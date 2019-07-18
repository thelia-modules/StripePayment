<?php 
namespace StripePayment\Controller ;


use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Log\Tlog;
use Thelia\Core\Event\ActionEvent;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Model\ProductQuery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Tools\Password;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\HttpFoundation\Response;

class StripeWebhooksController extends BaseFrontController
{
    public function __construct(){}
	
	
	public function listenAction($secure_url){
		
		if(StripePayment::getConfigValue('secure_url') == $secure_url){
			// Set your secret key: remember to change this to your live secret key in production
			// See your keys here: https://dashboard.stripe.com/account/apikeys
			\Stripe\Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));

			// You can find your endpoint's secret in your webhook settings
			$endpoint_secret = StripePayment::getConfigValue('webhooks_key');

			$payload = @file_get_contents('php://input');
			$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
			$event = null;

			try {
			  $event = \Stripe\Webhook::constructEvent(
				$payload, $sig_header, $endpoint_secret
			  );
			} catch(\UnexpectedValueException $e) {
			  // Invalid payload
			  http_response_code(400); // PHP 5.4 or greater
			  exit();
			} catch(\Stripe\Error\SignatureVerification $e) {
			  // Invalid signature
			  http_response_code(400); // PHP 5.4 or greater
			  exit();
			}

			// Handle the checkout.session.completed event
			if ($event->type == 'checkout.session.completed') {
			  $session = $event->data->object;

			  // Fulfill the purchase...
			  // $this->handle_checkout_session($session);
			}

			http_response_code(200); // PHP 5.4 or greater
			exit;
			
			//if(!empty($_REQUEST["success_url"])) return $response = $this->generateRedirect($_REQUEST["success_url"]);
		}
		http_response_code(400); // PHP 5.4 or greater
		exit();
	}
}
