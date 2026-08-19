<?php

namespace StripePayment\Hook;

use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

/**
 * Class StripePaymentHook
 * @package StripePayment\Hook
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentHook extends BaseHook
{
    // The request is resolved on each call rather than captured in the constructor:
    // the service is built once and would otherwise hold a stale request in worker mode.
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TaxEngine $taxEngine,
        private readonly CartFacade $cartFacade,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'order-invoice.payment-extra' => [
                ['type' => 'front', 'method' => 'includeStripe'],
            ],
            'order-invoice.after-javascript-include' => [
                ['type' => 'front', 'method' => 'declareStripeOnClickEvent'],
            ],
            'main.after-javascript-include' => [
                ['type' => 'front', 'method' => 'includeStripeJsV3'],
            ],
            'main.head-bottom' => [
                ['type' => 'front', 'method' => 'onMainHeadBottom'],
            ],
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
        ];
    }

    /**
     * Renders the back-office configuration screen with the template of the current parser:
     * stripepayment-configuration.html for the Smarty back-office, its .html.twig counterpart
     * for the Twig one. Both templates build the form themselves ({form} tag / getForm()).
     */
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $secureUrl = (string) (StripePayment::getConfigValue(StripePayment::SECURE_URL) ?? '');
        $request = $this->requestStack->getCurrentRequest();
        $successMessage = null;

        // The controller redirects back to this screen, so the success flag travels through
        // the session flash bag: the in-memory ParserContext does not survive the redirect.
        if (null !== $request && $request->hasSession()
            && [] !== $request->getSession()->getFlashBag()->get('stripepayment_success')) {
            $successMessage = $this->trans('Configuration correctly saved', [], StripePayment::MESSAGE_DOMAIN);
        }

        $event->add($this->render(
            'stripepayment-configuration.'.$this->getParser()->getFileExtension(),
            [
                'success_message' => $successMessage,
                'save_url' => URL::getInstance()->absoluteUrl('/admin/module/StripePayment'),
                'close_url' => URL::getInstance()->absoluteUrl('/admin/modules'),
                'webhook_url' => '' !== $secureUrl
                    ? URL::getInstance()->absoluteUrl('/module/StripePayment/stripe_webhook/'.$secureUrl.'/listen')
                    : null,
            ]
        ));
    }

    public function includeStripe(HookRenderEvent $event): void
    {
        if (!StripePayment::getConfigValue('stripe_element')) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return;
        }

        $cart = $this->cartFacade->getCartFromSession();

        if (null === $cart) {
            return;
        }

        $session = $request->getSession();
        $country = $this->taxEngine->getDeliveryCountry();

        // Amount the Payment Request button (Apple Pay / Google Pay) announces to the customer, in
        // cents. Computed here rather than in the template: the Smarty version read it from the
        // theme's {cart}/{order} tags, which are engine and theme specific, and it has to match, to
        // the cent, the payment intent CartEventListener sends to Stripe.
        $totalAmount = (int) round(
            ((float) $session->getOrder()->getPostage() + $cart->getTaxedAmount($country)) * 100
        );

        $event->add($this->render(
            'StripePayment/stripe-elements.html.twig',
            [
                'stripe_module_id' => $this->getModule()->getModuleId(),
                'public_key' => StripePayment::getConfigValue('publishable_key'),
                'one_click_payment' => StripePayment::getConfigValue(StripePayment::ONE_CLICK_PAYMENT, false),
                'client_secret' => $session->get(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY),
                'currency' => strtolower($session->getCurrency()->getCode()),
                'country' => $country->getIsoalpha2(),
                'store_name' => (string) (ConfigQuery::read('store_name') ?? ''),
                'total_amount' => $totalAmount,
            ]
        ));
    }

    public function declareStripeOnClickEvent(HookRenderEvent $event): void
    {
        if (!StripePayment::getConfigValue('stripe_element')) {
            return;
        }

        $event->add($this->render('StripePayment/stripe-elements-script.html.twig'));
    }

    public function includeStripeJsV3(HookRenderEvent $event): void
    {
        $event->add('<script src="https://js.stripe.com/v3/"></script>');
    }

    public function onMainHeadBottom(HookRenderEvent $event): void
    {
        $event->add($this->addCSS('StripePayment/assets/css/styles.css'));
    }
}
