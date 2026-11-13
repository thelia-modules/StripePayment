<?php

declare(strict_types=1);

namespace StripePayment\Tests;

// The tests are found by their path, not by an autoload: the shared fixtures are loaded the same way.
require_once __DIR__.'/PaymentPartsFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Exception\InvalidRequestException;
use StripePayment\Classes\StripePaymentException;
use StripePayment\Service\CartChargedAmount;
use StripePayment\Service\ChargedAmount;
use StripePayment\StripePayment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Domain\Payment\DTO\PaymentComposition;
use Thelia\Domain\Payment\DTO\PlannedPaymentPart;
use Thelia\Domain\Payment\OrderPaymentComposer;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
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

    /**
     * The card fields confirm their PaymentIntent in the browser before the order is placed: an order paid in parts
     * is tied to that intent only when it was asked for the balance.
     */
    public function testTheCardFieldsPayAnOrderPaidInPartsWhenTheirIntentChargedTheBalance(): void
    {
        StripePayment::setConfigValue(StripePayment::STRIPE_ELEMENT, 1);
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        $response = $this->payWithTheCardFields($order, 7000);

        self::assertNull($response);
        self::assertSame('pi_built_from_the_cart', OrderQuery::create()->findPk($order->getId())?->getTransactionRef());
    }

    /** @return iterable<string, array{?int}> */
    public static function amountsThatAreNotTheBalance(): iterable
    {
        yield 'the total of the cart' => [10000];
        yield 'an amount nobody recorded' => [null];
    }

    #[DataProvider('amountsThatAreNotTheBalance')]
    public function testTheCardFieldsAreRefusedWhenTheirIntentDidNotChargeTheBalance(?int $charged): void
    {
        StripePayment::setConfigValue(StripePayment::STRIPE_ELEMENT, 1);
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        $response = $this->payWithTheCardFields($order, $charged);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/checkout/failed', $response->getTargetUrl());
        self::assertStringContainsString('order_id='.$order->getId(), $response->getTargetUrl());
        self::assertStringContainsString('does+not+match', $response->getTargetUrl());
        self::assertNull(OrderQuery::create()->findPk($order->getId())?->getTransactionRef());
    }

    public function testTheCardFieldsStillPayAnOrderWithNoPart(): void
    {
        StripePayment::setConfigValue(StripePayment::STRIPE_ELEMENT, 1);
        $order = $this->orderOf100();

        $response = $this->payWithTheCardFields($order, null);

        self::assertNull($response);
        self::assertSame('pi_built_from_the_cart', OrderQuery::create()->findPk($order->getId())?->getTransactionRef());
    }

    public function testTheIntentOfACartPaidInPartsHoldsTheBalanceOfItsComposition(): void
    {
        $composition = new PaymentComposition([
            new PlannedPaymentPart(1, '30.000000', false, 0, true),
            new PlannedPaymentPart(2, '70.000000', true, 1, false),
        ], '70.000000');

        self::assertSame(7000, $this->cartChargedAmount()->ofComposition($composition, 10000));
    }

    public function testTheIntentOfACartNoInstantMeansPaysHoldsTheTotalOfTheCart(): void
    {
        self::assertSame(10000, $this->cartChargedAmount()->ofComposition(new PaymentComposition([], '0.000000'), 10000));
    }

    public function testNothingIsLeftForTheIntentWhenTheInstantMeansCoverTheCart(): void
    {
        $composition = new PaymentComposition([new PlannedPaymentPart(1, '100.000000', true, 0, true)], '0.000000');

        self::assertSame(0, $this->cartChargedAmount()->ofComposition($composition, 10000));
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

    private function payWithTheCardFields(Order $order, ?int $chargedByTheIntent): mixed
    {
        $module = $this->module();
        $session = $module->getRequest()->getSession();
        $session->set(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY, 'pi_built_from_the_cart');
        $session->set(StripePayment::PAYMENT_INTENT_AMOUNT_SESSION_KEY, $chargedByTheIntent);

        return (new \ReflectionMethod(StripePayment::class, 'doPay'))->invoke($module, $order);
    }

    private function cartChargedAmount(): CartChargedAmount
    {
        return new CartChargedAmount(static::getContainer()->get(OrderPaymentComposer::class));
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
