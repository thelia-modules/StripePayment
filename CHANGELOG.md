# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
