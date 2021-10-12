<?php

namespace StripePayment\Controller;

use Stripe\Checkout\Session;
use Stripe\Error\SignatureVerification;
use Stripe\Stripe;
use Stripe\Webhook;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
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

                (new StripePaymentLog())->logText(serialize($event));

                // Handle the event
                switch ($event->type) {
                    case 'checkout.session.completed':
                        /** @var Session $sessionCompleted */
                        $sessionCompleted = $event->data->object;
                        $this->handleSessionCompleted($sessionCompleted, $dispatcher);
                        break;
                    case 'payment_intent.succeeded':
                        // Needed to wait for order to be created (Stripe is faster than Thelia)
                        sleep(5);
                        /** @var Session $sessionCompleted */
                        $paymentId = $event->data->object->id;
                        $this->handlePaymentIntentSuccess($paymentId, $dispatcher);
                        break;
                    case 'payment_intent.payment_failed':
                        // Needed to wait for order to be created (Stripe is faster than Thelia)
                        sleep(5);
                        /** @var Session $sessionCompleted */
                        $paymentId = $event->data->object->id;
                        $this->handlePaymentIntentFail($paymentId, $dispatcher);
                        break;
                    default:
                        // Unexpected event type
                        (new StripePaymentLog())->logText('Unexpected event type');

                        return new Response('Unexpected event type', 400);
                }

                return new Response('Success', 200);
            } catch (\UnexpectedValueException $e) {
                // Invalid payload
                (new StripePaymentLog())->logText($e->getMessage());
                return new Response('Invalid payload', 400);
            } catch (SignatureVerification $e) {
                return new Response($e->getMessage(), 400);
            } catch (\Exception $e) {
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

    protected function setOrderToPaid($order, EventDispatcherInterface $dispatcher)
    {
        $paidStatusId = OrderStatusQuery::create()
            ->filterByCode('paid')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($paidStatusId);
        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
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
