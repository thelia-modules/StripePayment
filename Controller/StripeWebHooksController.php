<?php

namespace StripePayment\Controller;

use Stripe\Checkout\Session;
use Stripe\Error\SignatureVerification;
use Stripe\Stripe;
use Stripe\Webhook;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\StripePayment;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

class StripeWebHooksController extends BaseFrontController
{
    public function listenAction($secure_url)
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
                        $this->handleSessionCompleted($sessionCompleted);
                        break;
                    case 'payment_intent.succeeded':
                        // Needed to wait for order to be created (Stripe is faster than Thelia)
                        sleep(5);
                        /** @var Session $sessionCompleted */
                        $paymentId = $event->data->object->id;
                        $this->handlePaymentIntentSuccess($paymentId);
                        break;
                    case 'payment_intent.payment_failed':
                        // Needed to wait for order to be created (Stripe is faster than Thelia)
                        sleep(5);
                        /** @var Session $sessionCompleted */
                        $paymentId = $event->data->object->id;
                        $this->handlePaymentIntentFail($paymentId);
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

    protected function handleSessionCompleted(Session $sessionCompleted)
    {
        $order = OrderQuery::create()
            ->findOneByRef($sessionCompleted->client_reference_id);

        if (null === $order) {
            throw new \Exception("Order with reference $sessionCompleted->client_reference_id not found");
        }

        $this->setOrderToPaid($order);
    }

    protected function handlePaymentIntentSuccess($paymentId)
    {
        $order = OrderQuery::create()
            ->findOneByTransactionRef($paymentId);

        if (null === $order) {
            throw new \Exception("Order with transaction ref $paymentId not found");
        }

        $this->setOrderToPaid($order);
    }

    protected function handlePaymentIntentFail($paymentId)
    {
        $order = OrderQuery::create()
            ->findOneByTransactionRef($paymentId);

        if (null === $order) {
            throw new \Exception("Order with transaction ref $paymentId not found");
        }

        $this->setOrderToCanceled($order);
    }

    protected function setOrderToPaid($order)
    {
        $paidStatusId = OrderStatusQuery::create()
            ->filterByCode('paid')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($paidStatusId);
        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
    }

    protected function setOrderToCanceled($order)
    {
        $canceledStatusId = OrderStatusQuery::create()
            ->filterByCode('canceled')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($order);
        $event->setStatus($canceledStatusId);
        $this->getDispatcher()->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
    }
}
