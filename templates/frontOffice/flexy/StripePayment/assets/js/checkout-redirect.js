/**
 * Hands the customer over to the Stripe-hosted Checkout page.
 *
 * Loaded by StripePayment/checkout-redirect.html.twig, which is the entire response returned by
 * StripePayment::createStripeSession(). The publishable key and the Checkout Session id are read
 * from data attributes rather than interpolated into this file, so it stays a static asset.
 */
(function () {
    'use strict';

    var container = document.querySelector('[data-stripe-checkout-redirect]');

    if (!container) {
        return;
    }

    var errorHolder = container.querySelector('[data-stripe-checkout-redirect-error]');

    function showError(message) {
        if (!errorHolder) {
            return;
        }

        errorHolder.textContent = message;
        errorHolder.hidden = false;
    }

    if (typeof Stripe === 'undefined') {
        showError('Stripe.js could not be loaded.');

        return;
    }

    var stripe = Stripe(container.getAttribute('data-stripe-public-key'));

    stripe
        .redirectToCheckout({sessionId: container.getAttribute('data-stripe-session-id')})
        .then(function (result) {
            // Reached only when the redirect itself failed (browser or network error): on success
            // the browser has already left this page.
            if (result && result.error) {
                showError(result.error.message);
            }
        });
})();
