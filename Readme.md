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
    * add "stripe/stripe-php" to your composer.json file with command : `composer require stripe/stripe-php:"3.*"`
    * or download the library from <https://github.com/stripe/stripe-php/releases> and install it in your `core/vendor` directory
* Activate it in your Thelia administration panel


### Composer

Add it in your main thelia composer.json file:

```
composer require thelia/stripe-payment-module:"~1.0"
```

### Configuration

Enter your Stripe keys (*secret* and *public*) available on your [Stripe dashboard](https://dashboard.stripe.com/).

Put your Stripe account in live mode.

Then activate the Stripe in the module configuration panel.


### Logs

Stripe error logs are stored in a specific file located in the log folder.
