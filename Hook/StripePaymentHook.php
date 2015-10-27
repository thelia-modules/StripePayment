<?php

namespace StripePayment\Hook;

use StripePayment\StripePayment;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Class StripePaymentHook
 * @package StripePayment\Hook
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentHook extends BaseHook
{
    public function includeStripe(HookRenderEvent $event)
    {
        $publicKey = StripePayment::getConfigValue('publishable_key');

        $event->add($this->render(
            'assets/js/stripe-js.html',
            [
                'stripe_module_id' => $this->getModule()->getModuleId(),
                'public_key' => $publicKey
            ]
        ));
    }

    public function declareStripeOnClickEvent(HookRenderEvent $event)
    {
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