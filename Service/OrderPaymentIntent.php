<?php

declare(strict_types=1);

namespace StripePayment\Service;

use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use StripePayment\Classes\StripePaymentException;
use StripePayment\StripePayment;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Order;

/**
 * The PaymentIntent of an order paid with the embedded payment form (Stripe Elements).
 *
 * It is created once the order exists, for what the order leaves to pay, and written on the order as its transaction
 * reference: the payment page, the return from Stripe and the webhook all find one from the other through it.
 */
final readonly class OrderPaymentIntent
{
    /** Where Stripe stands before the buyer has paid: the only states a PaymentIntent can still be cancelled from. */
    private const array CANCELLABLE_STATUSES = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
        PaymentIntent::STATUS_REQUIRES_CAPTURE,
    ];

    public function __construct(
        private ChargedAmount $chargedAmount,
        private PaymentMethodSelection $paymentMethods,
        private PaymentIntentGateway $gateway,
    ) {
    }

    /**
     * What the PaymentIntent of the order is created with.
     *
     * @return array<string, mixed>
     *
     * @throws StripePaymentException when the order leaves nothing to pay
     */
    public function payload(Order $order): array
    {
        $amount = $this->chargedAmount->forStripe($order);

        if ($amount <= 0) {
            throw new StripePaymentException(Translator::getInstance()->trans('Nothing left to pay on this order.', [], StripePayment::MESSAGE_DOMAIN));
        }

        $payload = $this->paymentMethods->applyTo([
            'amount' => $amount,
            'currency' => strtolower((string) $order->getCurrency()?->getCode()),
            'description' => (string) $order->getRef(),
            // What tells this PaymentIntent from the one of a Checkout session, and names its order to whoever
            // reads it in the Dashboard.
            'metadata' => [
                'order_id' => (string) $order->getId(),
                'order_ref' => (string) $order->getRef(),
            ],
        ]);

        // An explicit list of payment method types and the automatic ones exclude each other.
        if (!isset($payload['payment_method_types'])) {
            $payload['automatic_payment_methods'] = ['enabled' => true];
        }

        return $payload;
    }

    /**
     * @throws ApiErrorException
     * @throws StripePaymentException
     */
    public function createFor(Order $order): PaymentIntent
    {
        $paymentIntent = $this->gateway->create($this->payload($order));

        $order->setTransactionRef($paymentIntent->id)->save();

        return $paymentIntent;
    }

    /**
     * The PaymentIntent the order was given, read again from Stripe. Null when the order has none, or when the one
     * it names was not created for it (the PaymentIntent of a Checkout session carries no order).
     *
     * @throws ApiErrorException
     */
    public function of(Order $order): ?PaymentIntent
    {
        $paymentIntentId = (string) $order->getTransactionRef();

        if (!str_starts_with($paymentIntentId, 'pi_')) {
            return null;
        }

        $paymentIntent = $this->gateway->retrieve($paymentIntentId);

        return $this->isOf($paymentIntent, $order) ? $paymentIntent : null;
    }

    /**
     * Takes back the PaymentIntent of an order that will not be paid, so that a payment confirmed late — a second
     * tab, a 3-D Secure window left open — is refused by Stripe instead of charging a cancelled order.
     *
     * @return bool whether a PaymentIntent was cancelled
     *
     * @throws ApiErrorException
     */
    public function cancelFor(Order $order): bool
    {
        $paymentIntent = $this->of($order);

        if (null === $paymentIntent || !\in_array($paymentIntent->status, self::CANCELLABLE_STATUSES, true)) {
            return false;
        }

        $this->gateway->cancel($paymentIntent->id);

        return true;
    }

    public function isOf(PaymentIntent $paymentIntent, Order $order): bool
    {
        return (string) $order->getId() === (string) ($paymentIntent->metadata['order_id'] ?? '');
    }
}
