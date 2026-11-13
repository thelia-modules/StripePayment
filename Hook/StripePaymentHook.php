<?php

namespace StripePayment\Hook;

use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
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
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

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
}
