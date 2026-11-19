<?php

namespace StripePayment\Hook;

use StripePayment\StripePayment;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Tools\URL;

/**
 * Class StripePaymentHook
 * @package StripePayment\Hook
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentHook extends BaseHook
{
    /**
     * Only the back-office screen is served here. The front office is a Twig theme, which declares its extension
     * points with theme_hook(): the CSS and Stripe.js are served by StripePayment\Hook\Theme\StripePaymentThemeHook,
     * and the payment form (Stripe Elements) has a page of its own, StripePayment\Controller\StripePaymentController.
     */
    public static function getSubscribedHooks(): array
    {
        return [
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

        $event->add($this->render(
            'stripepayment-configuration.'.$this->getParser()->getFileExtension(),
            [
                'save_url' => URL::getInstance()->absoluteUrl('/admin/module/StripePayment'),
                'close_url' => URL::getInstance()->absoluteUrl('/admin/modules'),
                'webhook_url' => '' !== $secureUrl
                    ? URL::getInstance()->absoluteUrl('/module/StripePayment/stripe_webhook/'.$secureUrl.'/listen')
                    : null,
            ]
        ));
    }
}
