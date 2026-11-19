<?php

declare(strict_types=1);

namespace StripePayment\Tests;

require_once __DIR__.'/PaymentPartsFixtures.php';

use StripePayment\Controller\StripeWebHooksController;
use StripePayment\StripePayment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * Stripe confirming a payment names the module as the source of the paid status: the core settles the part a provider
 * holds on an order paid in several parts only when that provider says it charged it. The webhook is signed here the
 * way Stripe signs it; nothing calls Stripe.
 *
 * Run from the root of the Thelia project the module is installed in, against a disposable database (see bootstrap.php).
 */
final class WebhookConfirmationTest extends IntegrationTestCase
{
    use PaymentPartsFixtures;

    private const string SECURE_URL = 'secure-url-of-the-test';
    private const string SIGNING_SECRET = 'whsec_signing_secret_of_the_test';

    public function testACompletedCheckoutSessionTurnsTheOrderPaidInTheNameOfTheModule(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');
        $confirmation = null;
        $this->dispatcher()->addListener(TheliaEvents::ORDER_UPDATE_STATUS, static function (OrderEvent $event) use (&$confirmation): void {
            $confirmation = $event;
            $event->stopPropagation();
        }, 1024);

        $response = $this->receive($this->completedCheckoutSessionOf($order));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertInstanceOf(OrderEvent::class, $confirmation);
        self::assertSame($order->getId(), $confirmation->getOrder()->getId());
        self::assertSame((int) OrderStatusQuery::getPaidStatus()->getId(), (int) $confirmation->getStatus());
        self::assertSame('StripePayment', $confirmation->getSourceModuleCode());
    }

    /**
     * Nothing stops the event here: the core turns the order paid and settles the part Stripe holds.
     */
    public function testACompletedCheckoutSessionPaysTheOrderAndSettlesThePartOfStripe(): void
    {
        $order = $this->orderOf100();
        $this->payInParts($order, '30.000000', '70.000000');

        $response = $this->receive($this->completedCheckoutSessionOf($order));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue(OrderQuery::create()->findPk($order->getId())?->isPaid(false));
        self::assertSame('settled', $this->stateOfThePartOfStripe($order));
    }

    /**
     * A cancellation gave back the credit note: the core refuses to turn the order paid, but Stripe did charge the
     * balance. Its part is settled, which is what the back office reads to give the money back, only because the
     * confirmation names the module.
     */
    public function testAConfirmationArrivingAfterACancellationStillRecordsWhatStripeCharged(): void
    {
        $order = $this->orderOf100();
        $this->payInPartsThenRelease($order, '30.000000', '70.000000');

        $this->receive($this->completedCheckoutSessionOf($order));

        self::assertFalse(OrderQuery::create()->findPk($order->getId())?->isPaid(false));
        self::assertSame('settled', $this->stateOfThePartOfStripe($order));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    private function stateOfThePartOfStripe(Order $order): ?string
    {
        return OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->filterByIsPrimary(true)->findOne()?->getState();
    }

    /**
     * @return array<string, mixed>
     */
    private function completedCheckoutSessionOf(Order $order): array
    {
        return [
            'id' => 'evt_test',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test', 'object' => 'checkout.session', 'client_reference_id' => $order->getRef()]],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function receive(array $event): Response
    {
        StripePayment::setConfigValue('secure_url', self::SECURE_URL);
        StripePayment::setConfigValue('webhooks_key', self::SIGNING_SECRET);
        StripePayment::setConfigValue(StripePayment::SECRET_KEY, 'sk_test_never_sent_anywhere');

        return (new StripeWebHooksController())->listenAction(self::SECURE_URL, $this->dispatcher(), $this->signedWebhook($event));
    }

    /**
     * @param array<string, mixed> $event
     */
    private function signedWebhook(array $event): Request
    {
        $payload = json_encode($event, \JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SIGNING_SECRET);

        $request = Request::create('/module/StripePayment/stripe_webhook/'.self::SECURE_URL.'/listen', 'POST', [], [], [], [], $payload);
        $request->headers->set('stripe-signature', 't='.$timestamp.',v1='.$signature);

        return $request;
    }
}
