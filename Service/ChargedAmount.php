<?php

declare(strict_types=1);

namespace StripePayment\Service;

use StripePayment\StripePayment;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPartQuery;

/**
 * What Stripe is asked to charge for an order: the balance left once the instant means of the shop (a credit note, a
 * gift card) have paid their part, the total of the order when it has no part. Every amount sent to Stripe for an
 * order comes from here, never from Order::getTotalAmount(): the core refuses the combination of parts with a module
 * that does not charge this figure (Thelia\Module\BalanceChargingPaymentModuleInterface).
 */
final readonly class ChargedAmount
{
    public function __construct(private EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Decimal string of six decimals, the answer of AbstractPaymentModule::getAmountToCharge().
     */
    public function of(Order $order): string
    {
        $module = new StripePayment();
        $module->setDispatcher($this->dispatcher);

        return $module->getAmountToCharge($order);
    }

    /**
     * The amount as the Stripe API takes it: an integer, in the smallest unit of the currency.
     */
    public function forStripe(Order $order): int
    {
        return (int) round((float) $this->of($order) * 100);
    }

    /**
     * Whether an instant means of the shop pays a part of the order.
     */
    public function isPaidInParts(Order $order): bool
    {
        return OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->exists();
    }
}
