<?php

declare(strict_types=1);

namespace StripePayment\EventListeners;

use StripePayment\Classes\StripePaymentLog;
use StripePayment\Service\OrderPaymentIntent;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderStatusQuery;

/**
 * Takes back the PaymentIntent of an order that is cancelled before it was paid: the buyer who gave up on the payment
 * form, the one who went back to the checkout and ordered again. Left open, it could still be confirmed — a second
 * tab, a bank window answered late — and charge an order the shop no longer expects money for.
 */
final readonly class OrderCancellationListener implements EventSubscriberInterface
{
    public function __construct(private OrderPaymentIntent $paymentIntents)
    {
    }

    public function cancelThePaymentIntent(OrderEvent $event): void
    {
        $order = $event->getOrder();

        if ((int) $event->getStatus() !== (int) OrderStatusQuery::getCancelledStatus()->getId()
            || !(new StripePayment())->isPaymentModuleFor($order)
        ) {
            return;
        }

        // Whatever Stripe answers, the cancellation of the order goes on.
        try {
            if ($this->paymentIntents->cancelFor($order)) {
                (new StripePaymentLog())->logText(
                    sprintf('PaymentIntent %s cancelled with order %d.', (string) $order->getTransactionRef(), $order->getId()),
                    StripePaymentLog::INFO
                );
            }
        } catch (\Throwable $exception) {
            (new StripePaymentLog())->logText(
                sprintf('PaymentIntent %s of the cancelled order %d could not be cancelled. %s', (string) $order->getTransactionRef(), $order->getId(), $exception->getMessage()),
                StripePaymentLog::ERROR
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['cancelThePaymentIntent', 64],
        ];
    }
}
