<?php

declare(strict_types=1);

namespace StripePayment\Controller;

use FlexyBundle\Service\CheckoutTrail;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\Service\ChargedAmount;
use StripePayment\Service\OrderConfirmation;
use StripePayment\Service\OrderPaymentIntent;
use StripePayment\StripePayment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Translation\Translator;
use Thelia\Model\CheckoutStep;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Module\BasePaymentModuleController;
use Thelia\Tools\URL;

/**
 * The embedded payment form (Stripe Elements): the page the buyer pays an order on, once it is placed, and the page
 * Stripe sends them back to.
 */
class StripePaymentController extends BasePaymentModuleController
{
    /** The buyer is sent back to the form: Stripe took no money and waits for another attempt. */
    private const array STATUSES_TO_PAY_AGAIN = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
    ];

    /** Stripe holds the payment and has not said its last word: the webhook will. */
    private const array STATUSES_STILL_SETTLING = [
        PaymentIntent::STATUS_PROCESSING,
        PaymentIntent::STATUS_REQUIRES_CAPTURE,
    ];

    /**
     * The order comes from the session, where pay() wrote it, never from the address: there is no number to type
     * here, and a buyer with no account is served like any other.
     */
    #[Route(StripePayment::PAYMENT_PAGE_PATH, name: 'stripe_payment_page', methods: ['GET'])]
    public function paymentAction(
        Request $request,
        OrderPaymentIntent $paymentIntents,
        OrderConfirmation $confirmation,
        ChargedAmount $chargedAmount,
        ?CheckoutTrail $trail = null,
    ): Response {
        $order = $this->pendingOrderOf($request);

        // Nothing to pay here: the address was typed, or the order was cancelled since. Nothing failed either.
        if (null === $order) {
            return new RedirectResponse(URL::getInstance()->absoluteUrl('/checkout/cart'));
        }

        try {
            $paymentIntent = $paymentIntents->of($order);
        } catch (ApiErrorException $exception) {
            return $this->toTheFailurePage($order, $exception);
        }

        if (null === $paymentIntent) {
            return $this->toTheFailurePage($order);
        }

        // Reloaded, or reached again with the back button, after the payment went through: the session still names
        // the order, and the buyer is shown its confirmation again.
        if (!\in_array($paymentIntent->status, self::STATUSES_TO_PAY_AGAIN, true)) {
            return $this->afterThePayment($order, $paymentIntent, $confirmation);
        }

        return $this->render('StripePayment/payment', [
            'current' => CheckoutStep::CODE_PAYMENT,
            'steps' => $trail?->ofTheOrderJustPlaced() ?? [],
            'order_ref' => $order->getRef(),
            'amount' => $this->formatted($chargedAmount->of($order), $order, $request->getLocale()),
            'public_key' => (string) StripePayment::getConfigValue(StripePayment::PUBLISHABLE_KEY),
            'client_secret' => $paymentIntent->client_secret,
            'return_url' => URL::getInstance()->absoluteUrl(StripePayment::PAYMENT_RETURN_PATH),
            'stripe_locale' => substr($request->getLocale(), 0, 2),
            'declined' => $request->query->has('declined'),
        ]);
    }

    /**
     * Where Stripe sends the buyer once the form is confirmed, with the PaymentIntent and its client secret in the
     * address. The secret is what the browser that paid holds and nobody else: checked against Stripe, it stands for
     * the session, which a buyer does not always come back with from their bank.
     */
    #[Route(StripePayment::PAYMENT_RETURN_PATH, name: 'stripe_payment_return', methods: ['GET'])]
    public function returnAction(
        Request $request,
        OrderPaymentIntent $paymentIntents,
        OrderConfirmation $confirmation,
    ): Response {
        $paymentIntentId = (string) $request->query->get('payment_intent', '');
        $clientSecret = (string) $request->query->get('payment_intent_client_secret', '');

        $order = '' === $paymentIntentId ? null : OrderQuery::create()->findOneByTransactionRef($paymentIntentId);

        if (null === $order) {
            return $this->toTheFailurePage(null);
        }

        try {
            $paymentIntent = $paymentIntents->of($order);
        } catch (ApiErrorException $exception) {
            // Stripe could not be asked: nothing says the payment failed, so the order is left as it is.
            return $this->toTheFailurePage(null, $exception);
        }

        if (null === $paymentIntent || !hash_equals((string) $paymentIntent->client_secret, $clientSecret)) {
            return $this->toTheFailurePage(null);
        }

        return $this->afterThePayment($order, $paymentIntent, $confirmation);
    }

    protected function getModuleCode(): string
    {
        return 'StripePayment';
    }

    private function afterThePayment(Order $order, PaymentIntent $paymentIntent, OrderConfirmation $confirmation): Response
    {
        if (PaymentIntent::STATUS_SUCCEEDED === $paymentIntent->status) {
            // Said here without waiting for the webhook, so that the confirmation page shows a paid order.
            $confirmation->paid($order);
        }

        if (PaymentIntent::STATUS_SUCCEEDED === $paymentIntent->status || \in_array($paymentIntent->status, self::STATUSES_STILL_SETTLING, true)) {
            return new RedirectResponse(URL::getInstance()->absoluteUrl('/checkout/confirm'));
        }

        // Refused by the bank, or a 3-D Secure challenge that was not passed: the same order can be paid again.
        if (\in_array($paymentIntent->status, self::STATUSES_TO_PAY_AGAIN, true)) {
            return new RedirectResponse(URL::getInstance()->absoluteUrl(StripePayment::PAYMENT_PAGE_PATH, ['declined' => 1]));
        }

        return $this->toTheFailurePage($order);
    }

    /**
     * Flexy's failure page cancels the order it is given and shows the message. No order is given when nothing
     * proves the request speaks for one.
     */
    private function toTheFailurePage(?Order $order, ?ApiErrorException $exception = null): RedirectResponse
    {
        if (null !== $exception) {
            (new StripePaymentLog())->logText(
                sprintf('The PaymentIntent of order %s could not be read from Stripe. %s', (string) $order?->getId(), $exception->getMessage()),
                StripePaymentLog::ERROR
            );
        }

        $parameters = ['message' => Translator::getInstance()->trans('An error occurred during payment.', [], StripePayment::MESSAGE_DOMAIN)];

        if (null !== $order) {
            $parameters['order_id'] = $order->getId();
        }

        return new RedirectResponse(URL::getInstance()->absoluteUrl('/checkout/failed', $parameters));
    }

    /**
     * The order this session was last sent here to pay, unless it has been cancelled since.
     */
    private function pendingOrderOf(Request $request): ?Order
    {
        if (!$request->hasSession()) {
            return null;
        }

        $orderId = $request->getSession()->get(StripePayment::PENDING_ORDER_SESSION_KEY);

        if (!is_numeric($orderId)) {
            return null;
        }

        $order = OrderQuery::create()->findPk((int) $orderId);

        return null === $order || $order->isCancelled() ? null : $order;
    }

    private function formatted(string $amount, Order $order, string $locale): string
    {
        $formatted = (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))
            ->formatCurrency((float) $amount, (string) $order->getCurrency()?->getCode());

        return false === $formatted ? $amount : $formatted;
    }
}
