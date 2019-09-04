<?php

namespace StripePayment\Hook;

use StripePayment\Model\Config\Base\StripePaymentConfigValue;
use StripePayment\StripePayment;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\HttpFoundation\Request;
use Thelia\TaxEngine\TaxEngine;

/**
 * Class StripePaymentHook
 * @package StripePayment\Hook
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentHook extends BaseHook
{
    protected $request;

    protected $taxEngine;

    public function __construct(Request $request, TaxEngine $taxEngine)
    {
        $this->request = $request;
        $this->taxEngine = $taxEngine;
    }

    public function includeStripe(HookRenderEvent $event)
    {
		if(StripePayment::getConfigValue('stripe_element')){
			$publicKey = StripePayment::getConfigValue('publishable_key');
			$clientSecret = $this->request->getSession()->get(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY);
			$currency = strtolower($this->request->getSession()->getCurrency()->getCode());
            $country = $this->taxEngine->getDeliveryCountry()->getIsoalpha2();
            $event->add($this->render(
				'assets/js/stripe-js.html',
				[
					'stripe_module_id' => $this->getModule()->getModuleId(),
					'public_key' => $publicKey,
                    'oneClickPayment' => StripePayment::getConfigValue(StripePaymentConfigValue::ONE_CLICK_PAYMENT, false),
                    'clientSecret' => $clientSecret,
                    'currency' => $currency,
                    'country' => $country
				]
			));
		}
    }

    public function declareStripeOnClickEvent(HookRenderEvent $event)
    {
		if(StripePayment::getConfigValue('stripe_element')){
			$publicKey = StripePayment::getConfigValue('publishable_key');
			$event->add($this->render(
				'assets/js/order-invoice-after-js-include.html',
				[
					'stripe_module_id' => $this->getModule()->getModuleId(),
					'public_key' => $publicKey
				]
			));
		}
    }

    public function includeStripeJsV3(HookRenderEvent $event)
    {
        $event->add('<script src="https://js.stripe.com/v3/"></script>');
    }

	public function onMainHeadBottom(HookRenderEvent $event)
    {
        $content = $this->addCSS('assets/css/styles.css');
        $event->add($content);
    }
}