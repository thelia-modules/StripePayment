/**
 * Stripe Elements checkout integration: mounts the card field and the Payment Request button
 * (Apple Pay / Google Pay) and confirms the payment intent when the order form is submitted.
 *
 * Loaded by StripePayment/stripe-elements-script.html.twig. Every parameter comes from the data
 * attributes of the .stripe-payment container rendered by stripe-elements.html.twig, so this file
 * is a static asset with no server-side interpolation.
 *
 * The form it wires itself to is the theme's order-invoice form (#form-cart-payment) and the radio
 * button the theme renders for this payment module (#payment_<moduleId>). Both are looked up
 * defensively: a theme that does not provide them still gets the card field mounted instead of a
 * JavaScript error that takes the whole page's scripts down.
 */
(function () {
    'use strict';

    var ORDER_FORM_ID = 'form-cart-payment';

    var container = document.querySelector('[data-stripe-elements]');

    if (!container || typeof Stripe === 'undefined') {
        return;
    }

    var moduleId = container.getAttribute('data-stripe-module-id');
    var intentSecret = container.getAttribute('data-stripe-client-secret');
    var totalAmount = parseInt(container.getAttribute('data-stripe-total-amount'), 10);

    var stripe = Stripe(container.getAttribute('data-stripe-public-key'));
    var elements = stripe.elements();
    var form = document.getElementById(ORDER_FORM_ID);

    var paymentRequest = stripe.paymentRequest({
        currency: container.getAttribute('data-stripe-currency'),
        country: container.getAttribute('data-stripe-country'),
        total: {
            label: container.getAttribute('data-stripe-store-name'),
            amount: isNaN(totalAmount) ? 0 : totalAmount
        },
        requestPayerName: true,
        requestPayerEmail: true
    });

    var prButton = elements.create('paymentRequestButton', {paymentRequest: paymentRequest});

    // Check the availability of the Payment Request API first.
    paymentRequest.canMakePayment().then(function (result) {
        if (result) {
            prButton.mount('#payment-request-button');
        }
    });

    function showError(elementId, message) {
        var holder = document.getElementById(elementId);

        if (!holder) {
            return;
        }

        holder.textContent = message;
        holder.classList.remove('hidden');
    }

    function triggerBrowserValidation() {
        // The only way to trigger the HTML5 form validation UI is to fake a user submit event.
        var submit = document.createElement('input');

        submit.type = 'submit';
        submit.style.display = 'none';
        form.appendChild(submit);
        submit.click();
        submit.remove();
    }

    function validPayment() {
        form.submit();
    }

    paymentRequest.on('paymentmethod', function (ev) {
        // Trigger the HTML5 validation UI on the form if any of the inputs fail validation.
        var plainInputsValid = true;

        if (form) {
            Array.prototype.forEach.call(form.querySelectorAll('input'), function (input) {
                if (input.checkValidity && !input.checkValidity()) {
                    plainInputsValid = false;
                }
            });
        }

        if (!plainInputsValid) {
            triggerBrowserValidation();
            ev.complete('fail');

            return;
        }

        stripe
            .confirmPaymentIntent(intentSecret, {payment_method: ev.paymentMethod.id})
            .then(function (confirmResult) {
                if (confirmResult.error) {
                    // Report to the browser that the payment failed, prompting it to re-show the
                    // payment interface, and spell the reason out next to the button.
                    ev.complete('fail');
                    showError('payment-request-errors', confirmResult.error.message);

                    return;
                }

                // Report to the browser that the confirmation succeeded, prompting it to close its
                // payment method collection interface.
                ev.complete('success');
                validPayment();
            });
    });

    // Custom styling can be passed as options when creating an Element.
    var card = elements.create('card', {
        style: {
            base: {
                color: '#32325D',
                fontWeight: 500,
                fontFamily: 'Inter UI, Open Sans, Segoe UI, sans-serif',
                fontSize: '16px',
                fontSmoothing: 'antialiased',
                '::placeholder': {
                    color: '#CFD7DF'
                }
            },
            invalid: {
                color: '#E25950'
            }
        }
    });

    card.mount('#card-element');

    // Real-time validation errors coming from the card Element.
    card.addEventListener('change', function (event) {
        var displayError = document.getElementById('card-errors');

        if (!displayError) {
            return;
        }

        displayError.textContent = event.error ? event.error.message : '';
    });

    if (!form) {
        // Nothing to submit against: the card field is mounted but this theme drives the payment
        // step its own way. Bail out rather than throw.
        return;
    }

    form.addEventListener('submit', function (event) {
        var paymentChoice = document.getElementById('payment_' + moduleId);

        if (!paymentChoice || !paymentChoice.checked) {
            return;
        }

        event.preventDefault();

        stripe.handleCardPayment(intentSecret, card).then(function (result) {
            if (result.error) {
                showError('card-errors', result.error.message);

                return;
            }

            validPayment();
        });
    });
})();
