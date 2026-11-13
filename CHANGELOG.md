# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.2.0] - 2026-11-13

### Added

- The module charges the balance of an order paid in several parts: when a
  credit note or a gift card pays a part of the order, Stripe is asked for
  what is left to pay, not for the total of the order
  (`Thelia\Module\BalanceChargingPaymentModuleInterface`). This holds for
  Stripe Checkout and for Stripe Elements. The core offers Stripe for such an
  order from this version on.
- `Service\ChargedAmount`, the single source of the amount sent to Stripe for
  an order.
- `Service\OrderPaymentIntent` creates, reads and cancels the PaymentIntent of
  an order; `Service\PaymentIntentGateway` is the only place that calls Stripe
  for it.
- Cancelling an order that was not paid cancels its PaymentIntent.
- Stripe Elements offers the payment methods selected by the
  `payment_method_types_override` and `payment_method_configuration_id`
  settings, like Stripe Checkout.
- A dedicated message when what is left to pay is below the smallest amount
  Stripe charges in the currency.

### Changed

- Requires Thelia 3.3.0 or later.
- **Stripe Elements is now paid after the order is placed**, on a page of the
  shop's checkout (`/module/StripePayment/payment`), with Stripe's Payment
  Element. The PaymentIntent is created by `pay()` for what the order leaves to
  pay and written on the order; it used to be created from the cart, on every
  cart event, and confirmed in the browser before the order existed, through
  hooks and a form that Twig themes such as Flexy do not have.
- The order turns paid when the buyer comes back from a successful payment
  (`/module/StripePayment/payment/return`), without waiting for the webhook,
  which remains the fallback.
- The webhook names the module as the source of the paid status
  (`OrderEvent::setSourceModuleCode()`), which the core needs to settle the
  part Stripe holds on an order paid in several parts.
- Webhook: `payment_intent.payment_failed` no longer cancels an order paid with
  the embedded form, since the buyer can pay it again on the same page. The
  events are handled as they arrive: the five-second wait is gone.
- The paid status is dispatched once: a confirmation of an order that is
  already paid is ignored.
- A Checkout session or a PaymentIntent is refused when nothing is left to pay
  on the order.

### Removed

- The *one click payment* setting: the Payment Element shows Apple Pay and
  Google Pay by itself.
- The Smarty-era front-office hooks (`order-invoice.payment-extra`,
  `order-invoice.after-javascript-include`, `main.after-javascript-include`,
  `main.head-bottom`), `EventListeners\CartEventListener` and the
  `PAYMENT_INTENT_*_SESSION_KEY` constants.

## [5.1.0] - 2026-10-14

### Changed

- The module ships inactive (`enabled-by-default` set to 0): it is activated
  once it has been configured.
- The customer is sent back to the confirmation and failure pages of the Flexy
  checkout (`/checkout/confirm`, `/checkout/failed`); the Thelia 2 order pages
  no longer exist.

### Fixed

- The module stays silent until Stripe is configured: without a secret key it
  creates no PaymentIntent on cart events, and without a publishable key it
  adds neither Stripe.js nor its stylesheet to the pages of the shop.
- The webhook is refused when no signing secret is configured. An empty secret
  used to let a forged event through.

## [5.0.0] - 2026-08-19

First release of the Thelia 3 line. Thelia 2 shops stay on the 4.x versions.

### Changed

- **BREAKING**: requires Thelia 3.0.0 or later.
- Hooks, forms and services are discovered by Thelia 3: `Config/config.xml` no
  longer declares them.
- Routes are declared with `#[Route]` attributes on the controllers;
  `Config/routing.xml` is gone.
- The front-office templates are Twig templates for the Flexy theme
  (`templates/frontOffice/flexy`): the Stripe Checkout redirect page and the
  Stripe Elements card fields. Their JavaScript ships as static module assets,
  with no inline script.
- The back-office configuration screen is a Twig template for the `default-twig`
  back-office.

### Added

- `Hook\Theme\StripePaymentThemeHook` serves the stylesheet and Stripe.js on
  the theme hooks of a Twig front office (`layout.head.bottom`,
  `layout.body.bottom`).
- The `stripepayment_trans` Twig filter translates the front-office templates
  against the catalogue of the module.

### Removed

- The Smarty templates of the front office and of the back office, and their
  translation catalogues (`I18n/frontOffice/default`, `I18n/backOffice/default`).

### Fixed

- Cart events raised outside of an HTTP request (console command, fixtures) no
  longer fail for want of a session.
- The generated parent of the configuration form is left out of service
  discovery, which made the name of the form ambiguous.
- A webhook call with no `stripe-signature` header is answered 400 instead of
  failing with a server error.

## [4.0.0] - 2026-04-27

### Changed

- **BREAKING**: bumped `stripe/stripe-php` requirement from `^7.100` to `^20.0`.
- `createStripeSession()` no longer hard-codes `payment_method_types=['card']`.
  Payment-method selection is now resolved at call time with a 3-tier priority:
  CSV override > Payment Method Configuration id > Stripe Dashboard default.
- Replaced the long-removed `\Stripe\Error\SignatureVerification` alias with
  `\Stripe\Exception\SignatureVerificationException` in the webhook controller.

### Added

- Two back-office configuration fields:
  - `payment_method_types_override` (CSV) — explicit, highest priority.
  - `payment_method_configuration_id` (`pmc_xxx`) — Dashboard-driven config.
- `update()` hook that seeds `payment_method_types_override="card"` when
  upgrading from any pre-4.0 version, preserving the previous behavior.
- README section documenting the three payment-method selection modes and
  the migration path from 3.x.

### Migration

Upgrading existing installs is non-breaking at runtime: the update hook
keeps the legacy card-only behavior. To benefit from the modern flow,
clear the override field (or set a Payment Method Configuration id) in the
Stripe configuration page of the Thelia back-office.

## [3.1.0] - 2026-04

### Fixed

- Stripe payment logging path falls back to `var/log/`.
- Swapped fr_FR translations restored.
- Rich Stripe exception details (request_id, code, http_status) in logs.
- Log payload before sending to Stripe; log rotation by file size.
- `chmod 0666` on freshly created log file; webhook event dumped as JSON.
