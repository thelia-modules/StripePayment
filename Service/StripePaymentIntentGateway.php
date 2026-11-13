<?php

declare(strict_types=1);

namespace StripePayment\Service;

use Stripe\PaymentIntent;
use Stripe\StripeClient;
use StripePayment\StripePayment;

final readonly class StripePaymentIntentGateway implements PaymentIntentGateway
{
    public function create(array $payload): PaymentIntent
    {
        return $this->client()->paymentIntents->create($payload);
    }

    public function retrieve(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($paymentIntentId);
    }

    public function cancel(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->cancel($paymentIntentId);
    }

    /**
     * Built at each call: the secret key is a setting the merchant can change while the service lives on.
     */
    private function client(): StripeClient
    {
        return new StripeClient((string) StripePayment::getConfigValue(StripePayment::SECRET_KEY));
    }
}
