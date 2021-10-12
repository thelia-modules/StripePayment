<?php

namespace StripePayment\EventListeners;

use Stripe\PaymentIntent;
use Stripe\Stripe;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\ActionEvent;
use Thelia\Core\Event\Cart\CartCreateEvent;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Cart\CartRestoreEvent;
use Thelia\Core\Event\Currency\CurrencyChangeEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Cart;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\TaxEngine\TaxEngine;

class CartEventListener implements EventSubscriberInterface
{
    /** @var Request  */
    protected $request;

    /** @var EventDispatcherInterface  */
    protected $dispatcher;

    /** @var TaxEngine */
    protected $taxEngine;

    function __construct(
        RequestStack $requestStack,
        EventDispatcherInterface $dispatcher,
        TaxEngine $taxEngine
    )
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->dispatcher = $dispatcher;
        $this->taxEngine = $taxEngine;
    }

    public static function getSubscribedEvents()
    {
        $events = [
            TheliaEvents::CART_RESTORE_CURRENT => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CART_CREATE_NEW => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CART_ADDITEM => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CART_DELETEITEM => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CART_UPDATEITEM => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CART_CLEAR => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::CHANGE_DEFAULT_CURRENCY => ["createOrUpdatePaymentIntent", 64],
            TheliaEvents::ORDER_SET_POSTAGE => [ "createOrUpdatePaymentIntent", 64 ]
        ];

        return $events;
    }

    public function createOrUpdatePaymentIntent(ActionEvent $event)
    {
        Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));

        /** @var Session $session */
        $session = $this->request->getSession();

        $paymentIntentValues = $this->getPaymentIntentValues($event);

        if (false === $paymentIntentValues) {
            return;
        }

        if (
            $session->has(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY)
            &&
            null !== $paymentId = $session->get(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY)
        )
        {

            $payment = PaymentIntent::update(
                $paymentId,
                $paymentIntentValues
            );
            $session->set(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY, $payment->client_secret);

            return;
        }

        /** @var PaymentIntent $payment */
        $payment = PaymentIntent::create($paymentIntentValues);

        $session->set(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY, $payment->id);
        $session->set(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY, $payment->client_secret);
        return;
    }


    protected function getPaymentIntentValues(ActionEvent $event)
    {
        /** @var Session $session */
        $session = $this->request->getSession();
        $currency = $session->getCurrency();

        $data  = $this->getCartAndOrderFromEvent($event);

        if (false === $data) {
            return false;
        }

        /** @var Cart $cart */
        $cart = $data['cart'];

        /** @var Order $order */
        $order = $data['order'];

        $postageAmount = floatval($order->getPostage());

        $country = $this->taxEngine->getDeliveryCountry();

        $cartAmount = floatval($cart->getTaxedAmount($country));

        $totalAmount = ($postageAmount + $cartAmount) * 100;

        if (!$totalAmount > 0) {
            return false;
        }

        $values = [
            'amount' => intval(round($totalAmount)),
            'currency' => strtolower($currency->getCode())
        ];

        if (null !== $stripeCustomerId = $this->getStripeCustomerId($session)) {
            $values['customer'] = $stripeCustomerId;
        }

        return $values;
    }

    protected function getStripeCustomerId(Session $session)
    {
        if (null ===  $session->getCustomerUser()) {
            return null;
        }

        if (!$session->has(StripePayment::PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY)) {
            /** @var Customer $customer */
            $customer = $session->getCustomerUser();
            $email = $customer->getEmail();

            $stripeCustomer = \Stripe\Customer::create([
                'email' => $email
            ]);

            $session->set(StripePayment::PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY, $stripeCustomer->id);
        }

        return $session->get(StripePayment::PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY);
    }

    protected function getCartAndOrderFromEvent(ActionEvent $event)
    {
        /** @var Session $session */
        $session = $this->request->getSession();

        if ($event instanceof CartRestoreEvent) {
            return [
                'cart' => $event->getCart(),
                'order' => $session->getOrder()
            ];
        }

        if ($event instanceof CartCreateEvent) {
            return [
                'cart' => $event->getCart(),
                'order' => $session->getOrder()
            ];
        }

        if ($event instanceof CartEvent) {
            return [
                'cart' => $event->getCart(),
                'order' => $session->getOrder()
            ];
        }

        if ($event instanceof CurrencyChangeEvent) {
            return [
                'cart' => $session->getSessionCart($this->dispatcher),
                'order' => $session->getOrder()
            ];
        }

        if ($event instanceof OrderEvent) {
            return [
                'cart' => $session->getSessionCart($this->dispatcher),
                'order' => $event->getOrder()
            ];
        }

        return false;
    }
}