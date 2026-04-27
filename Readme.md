# Stripe

Thelia payment module for [Stripe](http://stripe.com).

You need a subscription to Stripe payment solution to use this module.

## Installation

Either you install StripePayment manually or via composer, the presence of Stripe API files is checked when you try to activate the module.
If the API files are absent, you can't use Stripe.
Be aware that API files are set into the core/vendor folder.

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is StripePayment.
* Install the Stripe PHP library :
    * add "stripe/stripe-php" to your composer.json file with command : `composer require stripe/stripe-php:"^20.0"`
    * or download the library from <https://github.com/stripe/stripe-php/releases> and install it in your `core/vendor` directory
* Activate it in your Thelia administration panel


### Composer

Add it in your main thelia composer.json file:

```
composer require thelia/stripe-payment-module ^4.0
```

### Configuration

Enter your Stripe keys (*secret* and *public*) available on your [Stripe dashboard](https://dashboard.stripe.com/).

Put your Stripe account in live mode.

Then activate the Stripe in the module configuration panel.

Activate the webhooks in stripe dashboard with the url specified in Thelia Back-office Stripe configuration, 
and add events listed in Thelia Back-office Stripe configuration.

### Payment methods

Since 4.0 the module no longer hard-codes the list of payment methods (`['card']`).
Three modes are available, evaluated in priority order:

1. **Override (CSV)** — field `Payment method types override (CSV)`.
   Comma-separated list of Stripe payment method type identifiers (e.g. `card,twint`).
   Highest priority, replaces the Dashboard configuration.
2. **Payment Method Configuration** — field `Payment method configuration ID`.
   Stripe `pmc_xxx` identifier created in the Dashboard
   (Settings → Payment methods → Configurations).
   Used when the override field is empty.
3. **Dashboard default** — both fields empty.
   Stripe selects payment methods according to the Dashboard configuration,
   currency, country and customer eligibility. Recommended for most setups.

#### Migrating from 3.x to 4.0

The pre-4.0 behavior was equivalent to mode 1 with `card`. To preserve that
behavior when upgrading, the module sets `payment_method_types_override = "card"`
during the update hook if both new fields are empty.

To switch to the modern Dashboard-driven flow, simply clear the override field
in the back-office configuration. To use a Payment Method Configuration created
in the Dashboard, paste its `pmc_xxx` id in the dedicated field (and leave
the override empty).

### Logs

Stripe error logs are stored in a specific file located in the log folder.
