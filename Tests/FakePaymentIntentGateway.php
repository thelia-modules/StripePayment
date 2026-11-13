<?php

declare(strict_types=1);

namespace StripePayment\Tests;

use Stripe\PaymentIntent;
use StripePayment\Service\PaymentIntentGateway;

/**
 * Answers in the place of Stripe: keeps the PaymentIntents it is asked to create, and lets a test say where one of
 * them stands.
 */
final class FakePaymentIntentGateway implements PaymentIntentGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $paymentIntents = [];

    /** @var list<string> */
    public array $cancelled = [];

    public function create(array $payload): PaymentIntent
    {
        $id = 'pi_test_'.(\count($this->paymentIntents) + 1);

        $this->paymentIntents[$id] = $payload + [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            'client_secret' => $id.'_secret_of_the_test',
        ];

        return $this->retrieve($id);
    }

    public function retrieve(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::constructFrom($this->paymentIntents[$paymentIntentId] ?? throw new \RuntimeException("No PaymentIntent $paymentIntentId."));
    }

    public function cancel(string $paymentIntentId): PaymentIntent
    {
        $this->cancelled[] = $paymentIntentId;
        $this->stands($paymentIntentId, PaymentIntent::STATUS_CANCELED);

        return $this->retrieve($paymentIntentId);
    }

    public function stands(string $paymentIntentId, string $status): void
    {
        $this->paymentIntents[$paymentIntentId]['status'] = $status;
    }

    /**
     * A PaymentIntent Stripe created by itself, for a Checkout session: it names no order.
     */
    public function holdsOneOfACheckoutSession(string $paymentIntentId): void
    {
        $this->paymentIntents[$paymentIntentId] = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            'client_secret' => $paymentIntentId.'_secret_of_the_test',
            'metadata' => [],
        ];
    }
}
