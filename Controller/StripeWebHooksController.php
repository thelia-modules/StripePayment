<?php

namespace StripePayment\Controller;

use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/module/StripePayment/stripe_webhook", name="stripe_webhook")
 */
class StripeWebHooksController extends BaseFrontController
{
    /**
     * @Route("/{secure_url}/listen", name="_listen")
     */
    public function listenAction($secure_url, EventDispatcherInterface $dispatcher)
    {
        if (StripePayment::getConfigValue('secure_url') == $secure_url) {
            try {
                Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));

                // You can find your endpoint's secret in your webhook settings
                $endpointSecret = StripePayment::getConfigValue('webhooks_key');

                $payload = file_get_contents('php://input');
                $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];
                $event = null;

                $event = Webhook::constructEvent(
                    $payload, $sigHeader, $endpointSecret
                );

                (new StripePaymentLog())->logText(
                    sprintf(
                        'Received webhook event %s: %s',
                        $event->type,
                        json_encode($event->toArray(), \JSON_UNESCAPED_UNICODE)
                    ),
                    StripePaymentLog::INFO
                );

                // Handle the event
                switch ($event->type) {
                    case 'checkout.session.completed':
                        /** @var Session $sessionCompleted */
                        $sessionCompleted = $event->data->object;
                        $this->handleSessionCompleted($sessionCompleted, $dispatcher);
                        break;
                    case 'payment_intent.succeeded':
                        /** @var PaymentIntent $paymentIntent */
                        $paymentIntent = $event->data->object;
                        $this->handlePaymentIntentSuccess($paymentIntent, $dispatcher);
                        break;
                    case 'payment_intent.payment_failed':
                        /** @var PaymentIntent $paymentIntent */
                        $paymentIntent = $event->data->object;
                        $this->handlePaymentIntentFail($paymentIntent, $dispatcher);
                        break;
                    default:
                        (new StripePaymentLog())->logText(
                            sprintf('Unexpected webhook event type: %s', $event->type),
                            StripePaymentLog::WARNING
                        );

                        // An event type we do not handle is not a delivery failure: answering 400 would
                        // count it as an error in the Stripe dashboard and trigger an endpoint alert.
                        return new Response('Unhandled event type', 200);
                }

                return new Response('Success', 200);
            } catch (\UnexpectedValueException $e) {
                (new StripePaymentLog())->logText(
                    sprintf('Invalid webhook payload: %s', $e->getMessage()),
                    StripePaymentLog::ERROR
                );
                return new Response('Invalid payload', 400);
            } catch (SignatureVerificationException $e) {
                (new StripePaymentLog())->logText(
                    sprintf('Webhook signature verification failed: %s', $e->getMessage()),
                    StripePaymentLog::ERROR
                );
                return new Response($e->getMessage(), 400);
            } catch (\Exception $e) {
                (new StripePaymentLog())->logText(
                    sprintf('Unexpected webhook error: %s', $e->getMessage()),
                    StripePaymentLog::ERROR
                );
                // A processing error is ours, not Stripe's: a 5xx makes it visible in the dashboard
                // and lets Stripe replay the event.
                return new Response($e->getMessage(), 500);
            }
        }

        return new Response('Bad request', 400);
    }

    protected function handleSessionCompleted(Session $sessionCompleted, EventDispatcherInterface $dispatcher)
    {
        $order = OrderQuery::create()
            ->findOneByRef($sessionCompleted->client_reference_id);

        if (null === $order) {
            throw new \Exception("Order with reference $sessionCompleted->client_reference_id not found");
        }

        // The PaymentIntent exists from this point on, store its id as the transaction reference.
        $this->updateTransactionRef($order, $sessionCompleted->payment_intent);

        $this->setOrderToPaid($order, $dispatcher);
    }

    protected function handlePaymentIntentSuccess(PaymentIntent $paymentIntent, EventDispatcherInterface $dispatcher)
    {
        $order = $this->findOrderForPaymentIntent($paymentIntent);

        if (null === $order) {
            throw new \Exception("Order for payment intent $paymentIntent->id not found");
        }

        $this->updateTransactionRef($order, $paymentIntent->id);

        $this->setOrderToPaid($order, $dispatcher);
    }

    protected function handlePaymentIntentFail(PaymentIntent $paymentIntent, EventDispatcherInterface $dispatcher)
    {
        $order = $this->findOrderForPaymentIntent($paymentIntent);

        if (null === $order) {
            throw new \Exception("Order for payment intent $paymentIntent->id not found");
        }

        $this->updateTransactionRef($order, $paymentIntent->id);

        $this->setOrderToCanceled($order, $dispatcher);
    }

    /**
     * Resolve the order a PaymentIntent belongs to.
     *
     * The transaction reference alone is not enough: a Checkout Session has no PaymentIntent when it is
     * created, so the order is stored with the session id until the customer engages the payment.
     * The order reference is therefore carried by the PaymentIntent metadata, which Stripe copies from
     * payment_intent_data.metadata (see StripePayment::createStripeSession).
     */
    protected function findOrderForPaymentIntent(PaymentIntent $paymentIntent): ?Order
    {
        $orderRef = $paymentIntent->metadata[StripePayment::ORDER_REF_METADATA_KEY] ?? null;

        if (!empty($orderRef) && null !== ($order = OrderQuery::create()->findOneByRef($orderRef))) {
            $this->logOrderResolution($paymentIntent, $order, 'payment intent metadata');

            return $order;
        }

        // Payment intents created outside of Checkout (stripe_element mode) and orders whose reference
        // has already been promoted to the payment intent id.
        if (null !== ($order = OrderQuery::create()->findOneByTransactionRef($paymentIntent->id))) {
            $this->logOrderResolution($paymentIntent, $order, 'transaction reference');

            return $order;
        }

        return $this->findOrderFromCheckoutSession($paymentIntent);
    }

    /**
     * Last resort for sessions created before the order metadata was introduced, typically a customer
     * still on the Checkout page when the fix was deployed: ask Stripe which session produced this
     * PaymentIntent, then resolve the order from that session.
     */
    protected function findOrderFromCheckoutSession(PaymentIntent $paymentIntent): ?Order
    {
        try {
            $stripe = new StripeClient(StripePayment::getConfigValue(StripePayment::SECRET_KEY));

            $sessions = $stripe->checkout->sessions->all([
                'payment_intent' => $paymentIntent->id,
                'limit' => 1,
            ]);
        } catch (\Exception $e) {
            // Never let the fallback hide the real diagnosis: the caller reports the missing order.
            (new StripePaymentLog())->logText(
                sprintf(
                    'Unable to look up the checkout session of payment intent %s: %s',
                    $paymentIntent->id,
                    $e->getMessage()
                ),
                StripePaymentLog::ERROR
            );

            return null;
        }

        /** @var Session|null $session */
        $session = $sessions->data[0] ?? null;

        if (null === $session) {
            return null;
        }

        $order = null;

        if (!empty($session->client_reference_id)) {
            $order = OrderQuery::create()->findOneByRef($session->client_reference_id);
        }

        if (null === $order) {
            $order = OrderQuery::create()->findOneByTransactionRef($session->id);
        }

        if (null !== $order) {
            $this->logOrderResolution($paymentIntent, $order, sprintf('checkout session %s', $session->id));
        }

        return $order;
    }

    protected function updateTransactionRef(Order $order, $transactionRef)
    {
        if (empty($transactionRef) || $order->getTransactionRef() === $transactionRef) {
            return;
        }

        $order->setTransactionRef($transactionRef)->save();
    }

    protected function logOrderResolution(PaymentIntent $paymentIntent, Order $order, $strategy)
    {
        (new StripePaymentLog())->logText(
            sprintf(
                'Payment intent %s resolved to order %s (id %d) by %s.',
                $paymentIntent->id,
                $order->getRef(),
                $order->getId(),
                $strategy
            ),
            StripePaymentLog::INFO
        );
    }

    protected function setOrderToPaid(Order $order, EventDispatcherInterface $dispatcher)
    {
        // checkout.session.completed and payment_intent.succeeded both lead here a few seconds apart:
        // dispatching the status update twice would replay every listener of the order status change.
        if ($order->isPaid(false)) {
            (new StripePaymentLog())->logText(
                sprintf('Order %s is already paid, skipping the status update.', $order->getRef()),
                StripePaymentLog::INFO
            );

            return;
        }

        $paidStatusId = OrderStatusQuery::create()
            ->filterByCode('paid')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($paidStatusId);
        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }

    protected function setOrderToCanceled(Order $order, EventDispatcherInterface $dispatcher)
    {
        // Checkout lets the customer retry after a declined card. If a later attempt already succeeded,
        // the failed one must not cancel a paid order.
        if ($order->isPaid(false)) {
            (new StripePaymentLog())->logText(
                sprintf('Order %s is paid, refusing to cancel it on a failed payment intent.', $order->getRef()),
                StripePaymentLog::WARNING
            );

            return;
        }

        if ($order->isCancelled()) {
            (new StripePaymentLog())->logText(
                sprintf('Order %s is already canceled, skipping the status update.', $order->getRef()),
                StripePaymentLog::INFO
            );

            return;
        }

        $canceledStatusId = OrderStatusQuery::create()
            ->filterByCode('canceled')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($canceledStatusId);
        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }
}
