# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.2.0] - 2026-11-13

### Added

- The module charges the balance of an order paid in several parts: when a
  credit note or a gift card pays a part of the order, the Stripe Checkout
  session is created for what is left to pay, not for the total of the order
  (`Thelia\Module\BalanceChargingPaymentModuleInterface`). The core offers
  Stripe for such an order from this version on.
- `Service\ChargedAmount`, the single source of the amount sent to Stripe for
  an order, and `Service\CartChargedAmount`, its counterpart for a cart.
- Stripe Elements (the embedded card fields): the PaymentIntent is created for
  what is left to pay once the instant payment means have taken their part,
  and brought to that amount again right before the card fields are shown.
- A dedicated message when what is left to pay is below the smallest amount
  Stripe charges in the currency.

### Changed

- Requires Thelia 3.3.0 or later.
- The webhook names the module as the source of the paid status
  (`OrderEvent::setSourceModuleCode()`), which the core needs to settle the
  part Stripe holds on an order paid in several parts.
- A Checkout session is refused when nothing is left to pay on the order.
- Stripe Elements: the card fields are not shown when nothing is left for
  Stripe to charge, or when the PaymentIntent cannot be brought to the amount
  left to pay.

### Known limits

- Stripe Elements confirms the payment in the browser, before the order is
  placed. If the order then leaves another amount to pay than the one the
  PaymentIntent charged (a credit note applied in another tab, a rounding
  difference between the cart and the order), the order is refused and the
  buyer is sent to the failure page; the PaymentIntent is written to the
  Stripe log of the module so that it can be refunded. Stripe Checkout is not
  affected: its session is created from the order.

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
