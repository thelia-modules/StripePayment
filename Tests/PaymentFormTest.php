<?php

declare(strict_types=1);

namespace StripePayment\Tests;

// The tests are found by their path, not by an autoload: what they share is loaded the same way.
require_once __DIR__.'/PaymentPartsFixtures.php';
require_once __DIR__.'/FakePaymentIntentGateway.php';

use Stripe\PaymentIntent;
use StripePayment\Classes\StripePaymentException;
use StripePayment\Controller\StripePaymentController;
use StripePayment\Controller\StripeWebHooksController;
use StripePayment\EventListeners\OrderCancellationListener;
use StripePayment\Service\ChargedAmount;
use StripePayment\Service\OrderConfirmation;
use StripePayment\Service\OrderPaymentIntent;
use StripePayment\Service\PaymentMethodSelection;
use StripePayment\StripePayment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The embedded payment form (Stripe Elements): the order is placed first, its PaymentIntent is created for what it
 * leaves to pay, and the order turns paid when Stripe says the PaymentIntent succeeded — to the browser coming back
 * from the form, or to the webhook. FakePaymentIntentGateway answers in the place of Stripe; nothing calls it.
 *
 * Run from the root of the Thelia project the module is installed in, against a disposable database (see bootstrap.php).
 */
final class PaymentFormTest extends IntegrationTestCase
{
    use PaymentPartsFixtures;

    private const string SECURE_URL = 'secure-url-of-the-test';
    private const string SIGNING_SECRET = 'whsec_signing_secret_of_the_test';

    private FakePaymentIntentGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakePaymentIntentGateway();

        StripePayment::setConfigValue(StripePayment::STRIPE_ELEMENT, 1);
        StripePayment::setConfigValue(StripePayment::STRIPE_PMC_TYPES_OVERRIDE, '');
        StripePayment::setConfigValue(StripePayment::CONFIG_PMC_ID, '');
    }

    public function testThePaymentIntentOfAnOrderHoldsItsTotalAndNamesIt(): void
    {
        $order = $this->orderOf100();

        $payload = $this->paymentIntents()->payload($order);

        self::assertSame(10000, $payload['amount']);
        self::assertSame(strtolower($order->getCurrency()->getCode()), $payload['currency']);
        self::assertSame(['order_id' => (string) $order->getId(), 'order_ref' => $order->getRef()], $payload['metadata']);
        self::assertSame(['enabled' => true], $payload['automatic_payment_methods']);
        self::assertArrayNotHasKey('payment_method_types', $payload);
    }

    public function testThePaymentIntentOfAnOrderPaidInPartsHoldsTheBalance(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        self::assertSame(7000, $this->paymentIntents()->payload($order)['amount']);
    }

    public function testNoPaymentIntentIsBuiltWhenThePartsLeaveNothingToPay(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '100.000000', '0.000000');

        $this->expectException(StripePaymentException::class);
        $this->expectExceptionMessage('Nothing left to pay');

        $this->paymentIntents()->payload($order);
    }

    public function testAnExplicitListOfPaymentMethodsReplacesTheAutomaticOnes(): void
    {
        StripePayment::setConfigValue(StripePayment::STRIPE_PMC_TYPES_OVERRIDE, 'card, twint');

        $payload = $this->paymentIntents()->payload($this->orderOf100());

        self::assertSame(['card', 'twint'], $payload['payment_method_types']);
        self::assertArrayNotHasKey('automatic_payment_methods', $payload);
    }

    public function testAPaymentMethodConfigurationIsPassedOnWithTheAutomaticOnes(): void
    {
        StripePayment::setConfigValue(StripePayment::CONFIG_PMC_ID, 'pmc_of_the_test');

        $payload = $this->paymentIntents()->payload($this->orderOf100());

        self::assertSame('pmc_of_the_test', $payload['payment_method_configuration']);
        self::assertSame(['enabled' => true], $payload['automatic_payment_methods']);
    }

    public function testPayingCreatesThePaymentIntentOfTheOrderAndSendsTheBuyerToThePaymentForm(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');
        static::getContainer()->set(OrderPaymentIntent::class, $this->paymentIntents());

        $module = new StripePayment();
        $module->setContainer(static::getContainer());

        $response = (new \ReflectionMethod(StripePayment::class, 'doPay'))->invoke($module, $order);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringEndsWith(StripePayment::PAYMENT_PAGE_PATH, $response->getTargetUrl());
        self::assertSame('pi_test_1', $this->reloaded($order)->getTransactionRef());
        self::assertSame(7000, $this->stripe->retrieve('pi_test_1')->amount);
        self::assertSame($order->getId(), $module->getRequest()->getSession()->get(StripePayment::PENDING_ORDER_SESSION_KEY));
    }

    public function testComingBackFromASucceededPaymentTurnsTheOrderPaidAndShowsTheConfirmation(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);
        $this->stripe->stands($paymentIntent->id, PaymentIntent::STATUS_SUCCEEDED);

        $response = $this->comeBack($paymentIntent->id, $paymentIntent->client_secret);

        self::assertStringEndsWith('/checkout/confirm', $response->getTargetUrl());
        self::assertTrue($this->reloaded($order)->isPaid(false));
    }

    public function testAPaymentStillProcessingShowsTheConfirmationAndLeavesTheOrderToTheWebhook(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);
        $this->stripe->stands($paymentIntent->id, PaymentIntent::STATUS_PROCESSING);

        $response = $this->comeBack($paymentIntent->id, $paymentIntent->client_secret);

        self::assertStringEndsWith('/checkout/confirm', $response->getTargetUrl());
        self::assertFalse($this->reloaded($order)->isPaid(false));
    }

    public function testComingBackFromARefusedPaymentSendsTheBuyerToTheFormAgain(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);

        $response = $this->comeBack($paymentIntent->id, $paymentIntent->client_secret);

        self::assertStringContainsString(StripePayment::PAYMENT_PAGE_PATH.'?declined=1', $response->getTargetUrl());
        self::assertFalse($this->reloaded($order)->isPaid(false));
        self::assertFalse($this->reloaded($order)->isCancelled());
    }

    /**
     * The back button after a payment, or a reload of the page: the buyer paid, and is not told that it failed.
     */
    public function testThePaymentPageOfAnOrderAlreadyPaidShowsItsConfirmation(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);
        $this->stripe->stands($paymentIntent->id, PaymentIntent::STATUS_SUCCEEDED);

        $response = $this->openThePaymentPage($order->getId());

        self::assertStringEndsWith('/checkout/confirm', $response->getTargetUrl());
        self::assertTrue($this->reloaded($order)->isPaid(false));
    }

    public function testThePaymentPageWithNoOrderToPaySendsBackToTheCart(): void
    {
        $response = $this->openThePaymentPage(null);

        self::assertStringEndsWith('/checkout/cart', $response->getTargetUrl());
    }

    public function testASucceededPaymentIsNotBelievedWithoutItsClientSecret(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);
        $this->stripe->stands($paymentIntent->id, PaymentIntent::STATUS_SUCCEEDED);

        $response = $this->comeBack($paymentIntent->id, 'a_secret_somebody_made_up');

        self::assertStringContainsString('/checkout/failed', $response->getTargetUrl());
        self::assertStringNotContainsString('order_id', $response->getTargetUrl());
        self::assertFalse($this->reloaded($order)->isPaid(false));
    }

    /**
     * The order names a PaymentIntent that was not created for it: the one of a Checkout session, or of another
     * order.
     */
    public function testAPaymentIntentThatDoesNotNameTheOrderPaysNothing(): void
    {
        $order = $this->orderOf100();
        $order->setTransactionRef('pi_of_a_checkout_session')->save();
        $this->stripe->holdsOneOfACheckoutSession('pi_of_a_checkout_session');
        $this->stripe->stands('pi_of_a_checkout_session', PaymentIntent::STATUS_SUCCEEDED);

        $response = $this->comeBack('pi_of_a_checkout_session', 'pi_of_a_checkout_session_secret_of_the_test');

        self::assertStringContainsString('/checkout/failed', $response->getTargetUrl());
        self::assertFalse($this->reloaded($order)->isPaid(false));
    }

    public function testTheWebhookOfASucceededPaymentIntentTurnsTheOrderPaid(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);

        $response = $this->receive('payment_intent.succeeded', $paymentIntent);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($this->reloaded($order)->isPaid(false));
    }

    public function testARefusedAttemptOnThePaymentFormLeavesTheOrderToBePaidAgain(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);

        $response = $this->receive('payment_intent.payment_failed', $paymentIntent);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($this->reloaded($order)->isCancelled());
    }

    public function testCancellingAnOrderTakesBackItsPaymentIntent(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);

        $this->cancel($order);

        self::assertSame([$paymentIntent->id], $this->stripe->cancelled);
    }

    public function testCancellingAnOrderLeavesAPaymentIntentThatWasPaidAlone(): void
    {
        $order = $this->orderOf100();
        $paymentIntent = $this->paymentIntents()->createFor($order);
        $this->stripe->stands($paymentIntent->id, PaymentIntent::STATUS_SUCCEEDED);

        $this->cancel($order);

        self::assertSame([], $this->stripe->cancelled);
    }

    public function testAnotherStatusThanCancelledTakesNothingBack(): void
    {
        $order = $this->orderOf100();
        $this->paymentIntents()->createFor($order);

        $event = new OrderEvent($order);
        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
        (new OrderCancellationListener($this->paymentIntents()))->cancelThePaymentIntent($event);

        self::assertSame([], $this->stripe->cancelled);
    }

    private function cancel(Order $order): void
    {
        $event = new OrderEvent($order);
        $event->setStatus(OrderStatusQuery::getCancelledStatus()->getId());

        (new OrderCancellationListener($this->paymentIntents()))->cancelThePaymentIntent($event);
    }

    private function openThePaymentPage(?int $pendingOrderId): RedirectResponse
    {
        $request = Request::create(StripePayment::PAYMENT_PAGE_PATH);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set(StripePayment::PENDING_ORDER_SESSION_KEY, $pendingOrderId);

        $response = (new StripePaymentController())->paymentAction(
            $request,
            $this->paymentIntents(),
            new OrderConfirmation(static::getContainer()->get('event_dispatcher')),
            new ChargedAmount(static::getContainer()->get('event_dispatcher')),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function comeBack(string $paymentIntentId, string $clientSecret): RedirectResponse
    {
        $request = Request::create(StripePayment::PAYMENT_RETURN_PATH, 'GET', [
            'payment_intent' => $paymentIntentId,
            'payment_intent_client_secret' => $clientSecret,
        ]);

        $response = (new StripePaymentController())->returnAction(
            $request,
            $this->paymentIntents(),
            new OrderConfirmation(static::getContainer()->get('event_dispatcher')),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function receive(string $type, PaymentIntent $paymentIntent): Response
    {
        StripePayment::setConfigValue('secure_url', self::SECURE_URL);
        StripePayment::setConfigValue('webhooks_key', self::SIGNING_SECRET);
        StripePayment::setConfigValue(StripePayment::SECRET_KEY, 'sk_test_never_sent_anywhere');

        $payload = json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $paymentIntent->toArray()],
        ], \JSON_THROW_ON_ERROR);
        $timestamp = time();

        $request = Request::create('/module/StripePayment/stripe_webhook/'.self::SECURE_URL.'/listen', 'POST', [], [], [], [], $payload);
        $request->headers->set('stripe-signature', 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SIGNING_SECRET));

        return (new StripeWebHooksController())->listenAction(self::SECURE_URL, static::getContainer()->get('event_dispatcher'), $request);
    }

    private function reloaded(Order $order): Order
    {
        OrderQuery::create()->clear();
        \Thelia\Model\Map\OrderTableMap::clearInstancePool();

        return OrderQuery::create()->findPk($order->getId()) ?? throw new \RuntimeException('The order is gone.');
    }

    private function paymentIntents(): OrderPaymentIntent
    {
        return new OrderPaymentIntent(
            new ChargedAmount(static::getContainer()->get('event_dispatcher')),
            new PaymentMethodSelection(),
            $this->stripe,
        );
    }
}
