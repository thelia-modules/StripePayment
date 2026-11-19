<?php

namespace StripePayment\Controller;

use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\Service\OrderConfirmation;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

#[Route('/module/StripePayment/stripe_webhook', name: 'stripe_webhook')]
class StripeWebHooksController extends BaseFrontController
{
    #[Route('/{secure_url}/listen', name: '_listen', requirements: ['secure_url' => '.*'])]
    public function listenAction($secure_url, EventDispatcherInterface $dispatcher, Request $request)
    {
        if (hash_equals((string) StripePayment::getConfigValue('secure_url'), (string) $secure_url)) {
            try {
                Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));

                // You can find your endpoint's secret in your webhook settings
                $endpointSecret = (string) (StripePayment::getConfigValue('webhooks_key') ?? '');

                // An empty signing secret is a secret anyone knows: the HMAC would be checked
                // against an empty key and a forged "checkout.session.completed" accepted.
                if ('' === $endpointSecret) {
                    return new Response('Webhook signing secret not configured', 400);
                }

                $payload = $request->getContent();
                // Lecture par l'objet Request plutot que par $_SERVER : un appel sans
                // en-tete de signature renvoie desormais 400 au lieu de provoquer une
                // ErrorException (« Undefined array key ») transformee en 500.
                $sigHeader = $request->headers->get('stripe-signature');

                if (null === $sigHeader) {
                    return new Response('Missing Stripe signature header', 400);
                }

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
                    // No waiting here: the order exists before its PaymentIntent, in both modes.
                    case 'payment_intent.succeeded':
                        $this->handlePaymentIntentSuccess($event->data->object->id, $dispatcher);
                        break;
                    case 'payment_intent.payment_failed':
                        // The payment form keeps the buyer on its page after a refusal, to pay the same order
                        // again with the same PaymentIntent: the order is only cancelled when they give up.
                        if ($this->isOfThePaymentForm($event->data->object)) {
                            (new StripePaymentLog())->logText(
                                sprintf('Payment attempt refused on PaymentIntent %s: the order is left to be paid again.', $event->data->object->id),
                                StripePaymentLog::INFO
                            );
                            break;
                        }

                        $this->handlePaymentIntentFail($event->data->object->id, $dispatcher);
                        break;
                    default:
                        (new StripePaymentLog())->logText(
                            sprintf('Unexpected webhook event type: %s', $event->type),
                            StripePaymentLog::WARNING
                        );

                        return new Response('Unexpected event type', 400);
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
                return new Response($e->getMessage(), 404);
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

        $this->setOrderToPaid($order, $dispatcher);
    }

    protected function handlePaymentIntentSuccess($paymentId, EventDispatcherInterface $dispatcher)
    {
        $order = OrderQuery::create()
            ->findOneByTransactionRef($paymentId);

        if (null === $order) {
            throw new \Exception("Order with transaction ref $paymentId not found");
        }

        $this->setOrderToPaid($order, $dispatcher);
    }

    protected function handlePaymentIntentFail($paymentId, EventDispatcherInterface $dispatcher)
    {
        $order = OrderQuery::create()
            ->findOneByTransactionRef($paymentId);

        if (null === $order) {
            throw new \Exception("Order with transaction ref $paymentId not found");
        }

        $this->setOrderToCanceled($order, $dispatcher);
    }

    /**
     * Only the PaymentIntent the module creates for the payment form names its order; the one of a Checkout session
     * is created by Stripe.
     */
    private function isOfThePaymentForm(object $paymentIntent): bool
    {
        return '' !== (string) ($paymentIntent->metadata['order_id'] ?? '');
    }

    protected function setOrderToPaid($order, EventDispatcherInterface $dispatcher)
    {
        (new OrderConfirmation($dispatcher))->paid($order);
    }

    protected function setOrderToCanceled($order, EventDispatcherInterface $dispatcher)
    {
        $canceledStatusId = OrderStatusQuery::create()
            ->filterByCode('canceled')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($canceledStatusId);
        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }
}
