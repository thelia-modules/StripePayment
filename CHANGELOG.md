# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.1] - 2026-09-09

### Fixed

- Webhook: `payment_intent.succeeded` and `payment_intent.payment_failed` could never
  resolve their order and always answered HTTP 404. `createStripeSession()` stored
  `transaction_ref` right after creating the Checkout Session, when Stripe has not created
  the PaymentIntent yet, so the reference was empty. A declined payment therefore never
  moved the order to `canceled`.
  The order reference is now carried by `payment_intent_data.metadata`, which Stripe copies
  onto the PaymentIntent, and the handlers resolve the order from it. Two fallbacks remain:
  the transaction reference (`stripe_element` mode) and a Checkout Session lookup for sessions
  created before this version.
- Webhook: `transaction_ref` is never empty anymore. It holds the Checkout Session id at
  creation time and is promoted to the PaymentIntent id as soon as that one exists.
- Webhook: an unexpected processing error now answers HTTP 500 instead of 404, so a broken
  endpoint is visible in the Stripe dashboard instead of being reported as a missing URL.
- Webhook: an unhandled event type answers HTTP 200 instead of 400, which no longer counts
  as a delivery failure in the Stripe dashboard.
- Webhook: order status updates are idempotent. A paid order is not paid again by
  `payment_intent.succeeded`, and a failed payment intent never cancels an order that has
  already been paid, processed or shipped.

### Removed

- The two blocking `sleep(5)` calls in the `payment_intent.*` handlers. The order exists
  before the Checkout Session is created, so there is no race to wait for.

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
