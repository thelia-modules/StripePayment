<?php

declare(strict_types=1);

namespace StripePayment\Service;

use StripePayment\StripePayment;
use Thelia\Domain\Payment\DTO\PaymentComposition;
use Thelia\Domain\Payment\Exception\PaymentCompositionRefusedException;
use Thelia\Domain\Payment\OrderPaymentComposer;
use Thelia\Model\Cart;
use Thelia\Model\ModuleQuery;

/**
 * What Stripe would be asked to charge for a cart, before its order exists: the balance the instant means of the shop
 * (a credit note, a gift card) leave, the total of the cart when none of them pays a part of it.
 *
 * The card fields (Stripe Elements) confirm their PaymentIntent in the browser, before the order is placed: the intent
 * has to hold this figure by then, pay() comes too late to change what the card is charged.
 */
final readonly class CartChargedAmount
{
    public function __construct(private OrderPaymentComposer $composer)
    {
    }

    /**
     * In the smallest unit of the currency. Zero when there is nothing for Stripe to charge: the instant means cover
     * the cart, or the core refuses to combine them with Stripe.
     */
    public function forStripe(Cart $cart, int $cartTotal): int
    {
        $module = ModuleQuery::create()->findOneByCode(StripePayment::getModuleCode());

        if (null === $module) {
            return $cartTotal;
        }

        try {
            return $this->ofComposition($this->composer->composeForCart($cart, (int) $module->getId()), $cartTotal);
        } catch (PaymentCompositionRefusedException) {
            return 0;
        }
    }

    /**
     * The balance of the composition, or the total of the cart when no instant means pays a part of it (the balance
     * of an empty composition is not computed by the core).
     */
    public function ofComposition(PaymentComposition $composition, int $cartTotal): int
    {
        if ($composition->isEmpty()) {
            return $cartTotal;
        }

        return (int) round((float) $composition->balance * 100);
    }
}
