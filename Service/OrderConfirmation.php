<?php

declare(strict_types=1);

namespace StripePayment\Service;

use StripePayment\StripePayment;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

/**
 * Turns an order paid in the name of the module. Two voices can say that Stripe charged an order — its webhook, and
 * the buyer's browser coming back from the payment form — and whichever speaks second finds it already said.
 */
final readonly class OrderConfirmation
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    public function paid(Order $order): void
    {
        // Read again: the other voice may have spoken since this order was loaded.
        $current = OrderQuery::create()->findPk($order->getId()) ?? $order;

        if ($current->isPaid(false)) {
            return;
        }

        $event = new OrderEvent($current);
        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
        // Without its source, the core does not settle the part Stripe holds on an order paid in several parts.
        $event->setSourceModuleCode(StripePayment::getModuleCode());

        $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
    }
}
