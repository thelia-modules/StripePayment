<?php

declare(strict_types=1);

namespace StripePayment\Tests;

// The tests are found by their path, not by an autoload: the shared fixtures are loaded the same way.
require_once __DIR__.'/PaymentPartsFixtures.php';

use Stripe\Exception\InvalidRequestException;
use StripePayment\Classes\StripePaymentException;
use StripePayment\Service\ChargedAmount;
use StripePayment\StripePayment;
use Thelia\Module\BalanceChargingPaymentModuleInterface;
use Thelia\Test\IntegrationTestCase;

/**
 * An order of 100 paid with a part of 30 (a credit note) is charged 70 by Stripe, and an order with no part 100: what
 * Stripe is asked for is the answer of the core (ORDER_PAY_GET_TOTAL, the balance of the primary part), never the
 * total of the order. Nothing here calls Stripe.
 *
 * Run from the root of the Thelia project the module is installed in, against a disposable database (see bootstrap.php).
 */
final class BalanceChargingTest extends IntegrationTestCase
{
    use PaymentPartsFixtures;

    public function testTheModuleDeclaresThatItChargesTheBalance(): void
    {
        self::assertTrue(is_subclass_of(StripePayment::class, BalanceChargingPaymentModuleInterface::class));
    }

    public function testAnOrderOf100WithAPartOf30IsChargedTheBalance(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        self::assertSame(100.0, (float) $order->getTotalAmount());
        self::assertSame('70.000000', $this->chargedAmount()->of($order));
        self::assertSame(7000, $this->chargedAmount()->forStripe($order));
        self::assertTrue($this->chargedAmount()->isPaidInParts($order));
    }

    public function testAnOrderWithNoPartIsChargedInFull(): void
    {
        $order = $this->orderOf100();

        self::assertSame('100.000000', $this->chargedAmount()->of($order));
        self::assertSame(10000, $this->chargedAmount()->forStripe($order));
        self::assertFalse($this->chargedAmount()->isPaidInParts($order));
    }

    public function testTheCheckoutSessionOfAPartPaidOrderHoldsTheBalanceOnASingleLine(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        $payload = $this->module()->checkoutSessionPayload($order);

        self::assertCount(1, $payload['line_items']);
        self::assertSame(7000, $payload['line_items'][0]['price_data']['unit_amount']);
        self::assertSame(1, $payload['line_items'][0]['quantity']);
        self::assertSame($order->getRef(), $payload['client_reference_id']);
    }

    public function testTheCheckoutSessionOfAnOrderWithNoPartHoldsItsTotal(): void
    {
        $payload = $this->module()->checkoutSessionPayload($this->orderOf100());

        self::assertSame(10000, $payload['line_items'][0]['price_data']['unit_amount']);
    }

    public function testNoCheckoutSessionIsBuiltWhenThePartsLeaveNothingToPay(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '100.000000', '0.000000');

        $this->expectException(StripePaymentException::class);
        $this->expectExceptionMessage('Nothing left to pay');

        $this->module()->checkoutSessionPayload($order);
    }

    public function testABalanceStripeCannotChargeIsToldToTheBuyerAsSuch(): void
    {
        $message = new \ReflectionMethod(StripePayment::class, 'invalidRequestMessage');

        self::assertSame(
            'The amount left to pay is below what Stripe can charge.',
            $message->invoke($this->module(), InvalidRequestException::factory('Amount must be at least 0.50 eur', 400, null, null, null, 'amount_too_small')),
        );
        self::assertSame(
            'Invalid parameters were supplied to Stripe.',
            $message->invoke($this->module(), InvalidRequestException::factory('No such price', 400, null, null, null, 'resource_missing')),
        );
    }

    private function module(): StripePayment
    {
        $module = new StripePayment();
        $module->setContainer(static::getContainer());

        return $module;
    }

    private function chargedAmount(): ChargedAmount
    {
        return new ChargedAmount(static::getContainer()->get('event_dispatcher'));
    }
}
