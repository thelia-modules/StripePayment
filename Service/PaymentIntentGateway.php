<?php

declare(strict_types=1);

namespace StripePayment\Service;

use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * The three things the module asks Stripe about a PaymentIntent. An interface so that the tests answer in the place
 * of Stripe: nothing in them goes over the network.
 */
interface PaymentIntentGateway
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws ApiErrorException
     */
    public function create(array $payload): PaymentIntent;

    /**
     * @throws ApiErrorException
     */
    public function retrieve(string $paymentIntentId): PaymentIntent;

    /**
     * @throws ApiErrorException
     */
    public function cancel(string $paymentIntentId): PaymentIntent;
}
