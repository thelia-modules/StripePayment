<?php

declare(strict_types=1);

namespace StripePayment\Service;

use StripePayment\StripePayment;

/**
 * Which payment methods Stripe offers, for a Checkout session as for a PaymentIntent: both take the same two keys.
 *
 * Priority: explicit CSV override > Payment Method Configuration id > Dashboard default. When both settings are
 * empty neither key is added, and Stripe falls back to the configuration of the Dashboard.
 */
final readonly class PaymentMethodSelection
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $payload): array
    {
        $override = trim((string) (StripePayment::getConfigValue(StripePayment::STRIPE_PMC_TYPES_OVERRIDE) ?? ''));

        if ('' !== $override) {
            $types = array_values(array_filter(
                array_map('trim', explode(',', $override)),
                static fn (string $type): bool => '' !== $type
            ));

            if ([] !== $types) {
                $payload['payment_method_types'] = $types;

                return $payload;
            }
        }

        $configurationId = trim((string) (StripePayment::getConfigValue(StripePayment::CONFIG_PMC_ID) ?? ''));

        if ('' !== $configurationId) {
            $payload['payment_method_configuration'] = $configurationId;
        }

        return $payload;
    }
}
