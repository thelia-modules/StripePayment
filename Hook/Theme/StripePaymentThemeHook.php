<?php

declare(strict_types=1);

namespace StripePayment\Hook\Theme;

use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Twig\Environment;

/**
 * Serves the module's front-office assets on the Twig theme hook points.
 *
 * The Smarty front-office hooks this module was written against (main.head-bottom,
 * main.after-javascript-include) no longer exist: a Twig theme declares its extension points with
 * theme_hook() and Flexy calls no legacy hook() at all, so a BaseHook subscribing to those names
 * is never invoked. The equivalents Flexy does declare:
 *
 *   main.head-bottom              -> layout.head.bottom
 *   main.after-javascript-include -> layout.body.bottom
 *
 * order-invoice.payment-extra, which injects the Stripe Elements card fields into the payment
 * form, has NO equivalent: Flexy's payment step is a LiveComponent with no per-module insertion
 * point. It stays on the legacy hook, handled by StripePayment\Hook\StripePaymentHook.
 */
final readonly class StripePaymentThemeHook implements ThemeHookInterface
{
    private const HEAD_BOTTOM_TEMPLATE = '@StripePaymentModule/frontOffice/flexy/StripePayment/theme-hook/head-bottom.html.twig';

    private const BODY_BOTTOM_TEMPLATE = '@StripePaymentModule/frontOffice/flexy/StripePayment/theme-hook/body-bottom.html.twig';

    public function __construct(
        private Environment $twig,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return \in_array($hookName, ['layout.head.bottom', 'layout.body.bottom'], true);
    }

    public function render(string $hookName, array $parameters): string
    {
        return match ($hookName) {
            'layout.head.bottom' => $this->twig->render(self::HEAD_BOTTOM_TEMPLATE, $parameters),
            'layout.body.bottom' => $this->twig->render(self::BODY_BOTTOM_TEMPLATE, $parameters),
            default => '',
        };
    }
}
