<?php

declare(strict_types=1);

namespace StripePayment\Twig;

use StripePayment\StripePayment;
use Thelia\Core\Translation\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Translates the module's front-office templates against its own catalogue.
 *
 * The native `|trans` filter cannot be used here. It resolves through the Symfony translator,
 * which only carries the theme's `messages` catalogue — module catalogues are registered on
 * Thelia\Core\Translation\Translator instead, so `|trans({}, 'stripepayment.fo.flexy')` silently
 * falls back to the English source string.
 *
 * The Twig back-office bridges the two with a `translator` decorator, but only for `/admin`
 * requests, and it ships inside the composer-installed `default-twig` theme, so it can neither
 * be relied on nor extended from here. Hence a module-local filter: it works regardless of
 * which themes are installed.
 */
final class FrontTranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('stripepayment_trans', $this->trans(...)),
        ];
    }

    /**
     * Not marked `is_safe`: the return value is plain text and must keep being autoescaped by
     * Twig. Catalogue entries are editable from the back-office.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function trans(?string $message, array $parameters = []): string
    {
        return $this->translator->trans(
            $message,
            $parameters,
            StripePayment::FRONT_TRANSLATION_DOMAIN
        );
    }
}
