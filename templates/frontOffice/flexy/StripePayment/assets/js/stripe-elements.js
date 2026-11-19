/**
 * Embedded payment form (Stripe Elements): mounts the Payment Element for the PaymentIntent of an
 * order that has just been placed, and confirms it when the form is submitted.
 *
 * Loaded by StripePayment/payment.html.twig. Every parameter comes from the data attributes of the
 * [data-stripe-payment] container, so this file is a static asset with no server-side
 * interpolation.
 *
 * Once the payment is confirmed, Stripe sends the browser to the return address of the module,
 * which reads the outcome from Stripe and shows the confirmation page. A refused payment keeps the
 * buyer here, with the reason, to try again on the same order.
 */
(function () {
    'use strict';

    var STRIPE_WAIT_STEP = 50;
    var STRIPE_WAIT_LIMIT = 10000;

    var container = document.querySelector('[data-stripe-payment]');

    // Turbo runs the scripts of a page again when it is shown from its cache: one mount per container.
    if (!container || container.hasAttribute('data-stripe-payment-mounted')) {
        return;
    }

    container.setAttribute('data-stripe-payment-mounted', '');

    var form = container.querySelector('[data-stripe-payment-form]');
    var submit = container.querySelector('[data-stripe-payment-submit]');
    var errorHolder = container.querySelector('[data-stripe-payment-error]');
    var errorMessage = container.querySelector('[data-stripe-payment-error-message]');

    function showError(message) {
        errorMessage.textContent = message;
        errorHolder.hidden = false;
    }

    function hideError() {
        errorMessage.textContent = '';
        errorHolder.hidden = true;
    }

    function setBusy(busy) {
        submit.disabled = busy;
        submit.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    // Stripe.js is served at the bottom of the page: on a full page load this script runs before it.
    function whenStripeIsLoaded(ready, waited) {
        if (typeof window.Stripe !== 'undefined') {
            ready();

            return;
        }

        if (waited >= STRIPE_WAIT_LIMIT) {
            showError(container.getAttribute('data-stripe-unavailable-message'));

            return;
        }

        window.setTimeout(function () {
            whenStripeIsLoaded(ready, waited + STRIPE_WAIT_STEP);
        }, STRIPE_WAIT_STEP);
    }

    whenStripeIsLoaded(function () {
        var stripe = window.Stripe(container.getAttribute('data-stripe-public-key'));
        var elements = stripe.elements({
            clientSecret: container.getAttribute('data-stripe-client-secret'),
            locale: container.getAttribute('data-stripe-locale') || 'auto'
        });
        var paymentElement = elements.create('payment');

        paymentElement.on('ready', function () {
            setBusy(false);
        });

        paymentElement.on('loaderror', function (event) {
            showError(event.error && event.error.message
                ? event.error.message
                : container.getAttribute('data-stripe-unavailable-message'));
        });

        paymentElement.mount(container.querySelector('[data-stripe-payment-element]'));

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (submit.disabled) {
                return;
            }

            hideError();
            setBusy(true);

            stripe.confirmPayment({
                elements: elements,
                confirmParams: {
                    return_url: container.getAttribute('data-stripe-return-url')
                }
            }).then(function (result) {
                // Reached only when the payment did not go through: on success, and for a bank
                // challenge, Stripe has already sent the browser away.
                if (result.error) {
                    showError(result.error.message);
                }

                setBusy(false);
            });
        });
    }, 0);
})();
