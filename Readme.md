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

### Paying the balance of an order

Since 5.2 (Thelia 3.3.0 or later), Stripe can pay what is left of an order once
an instant payment means of the shop, such as a credit note or a gift card, has
paid a part of it. The Stripe Checkout session is created for that balance.

This holds for both modes: the Stripe Checkout session and the PaymentIntent of
the embedded payment form are both created from the order, for what it leaves
to pay.

### Stripe Checkout or the embedded payment form

The module pays an order in one of two ways, chosen with the *Activate Element*
setting.

**Stripe Checkout** (the default). Once the order is placed, the buyer is sent
to the payment page hosted by Stripe, and comes back to the shop when it is
done.

**The embedded payment form** (Stripe Elements). Once the order is placed, the
buyer pays on a page of the shop's own checkout, which holds Stripe's Payment
Element: cards, Apple Pay, Google Pay and the other payment methods enabled for
the account, including the ones that need a bank challenge (3-D Secure) or a
redirection.

- The PaymentIntent is created for the order, and written on it as its
  transaction reference.
- A refused payment keeps the buyer on the page, to try again on the same
  order.
- The order turns paid as soon as the buyer comes back from a successful
  payment; the webhook says the same when they never come back (a closed tab).
- Cancelling the order before it is paid cancels its PaymentIntent, so that a
  payment confirmed late cannot charge it.

Both modes need the webhook, with the events `checkout.session.completed`,
`payment_intent.succeeded` and `payment_intent.payment_failed`. The payment
methods offered by the embedded form follow the same three settings as Stripe
Checkout (see *Payment methods*).

The page of the embedded form ships for the Flexy front-office theme
(`templates/frontOffice/flexy/StripePayment/payment.html.twig`). It relies on
the theme's `checkout_confirm` and `checkout_failed` pages, as every payment
module does.

### Logs

Stripe error logs are stored in a specific file located in the log folder.
