<?php

namespace StripePayment\EventListeners;

use StripePayment\Classes\StripePaymentException;
use StripePayment\Classes\StripePaymentLog;
use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order as OrderModel;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderProductAttributeCombinationQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Tools\URL;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\OrderCouponQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Class StripePaymentEventListener
 * @package StripePayment\EventListeners
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentEventListener implements EventSubscriberInterface
{
    /** @var Request  */
    protected $request;

    /** @var ParserInterface */
    protected $parser;

    /** @var MailerFactory */
    protected $mailer;

    function __construct(Request $request, ParserInterface $parser, MailerFactory $mailer)
    {
        $this->request = $request;
        $this->parser = $parser;
        $this->mailer = $mailer;
    }

    public static function getSubscribedEvents()
    {
        $events[TheliaEvents::FORM_AFTER_BUILD . ".thelia_order_payment"] = ["addStripeInput", 128];
        $events[TheliaEvents::ORDER_SET_PAYMENT_MODULE] = ["getStripeTokenAndAmount", 128];
		
        $events[TheliaEvents::ORDER_PAY] = ["stripePayment", 128];

        $events[TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL] = ["sendConfirmationEmail", 129];
        $events[TheliaEvents::ORDER_SEND_NOTIFICATION_EMAIL] = ["sendConfirmationEmail", 129];


        return $events;
    }

    /**
     * @return \Thelia\Mailer\MailerFactory
     */
    public function getMailer()
    {
        return $this->mailer;
    }
	
  /**
     * Add stripe_token & stripe_amount inputs to invoice form
     * @param TheliaFormEvent $event
     */
    public function addStripeInput(TheliaFormEvent $event)
    {
        $event->getForm()->getFormBuilder()
            ->add(
                'stripeToken',
                'text',
                ["required" => true]
            );
    }

    /**
     * Get token created by Stripe and order amount from the form & save them in session
     */
    public function getStripeTokenAndAmount()
    {
		if(StripePayment::getConfigValue('stripe_element')){
			// Get Stripe token
			$this->request->getSession()->set(
				'stripeToken',
				$this->request->get('thelia_order_payment')['stripeToken']
			);
		}
    }
    /**
     * returns the StripePayment module code
     */
    public function getStripeCode()
    {
        return "StripePayment";
    }    

    public function sendConfirmationEmail(OrderEvent $event)
    {
		$order = $event->getOrder();
		if (! $order->isPaid() && $order->getPaymentModuleId() == StripePayment::getModuleId()) {
			$event->stopPropagation();
		}
    }
	
    /**
     * Send data to Stripe, save token, change order status & get response
     * @param OrderEvent $event
     * @throws \Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function stripePayment(OrderEvent $event)
    {
        $order = $event->getPlacedOrder();
        $stripeModule = ModuleQuery::create()->findOneByCode($this->getStripeCode());
        if ($order->getPaymentModuleId() !== $stripeModule->getId()) {
            return;
        }
        
        $logMessage = null;
        $userMessage = null;

        \Stripe\Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));

        try {

			if(StripePayment::getConfigValue('stripe_element')){
				// Create the charge on Stripe's servers - this will charge the user's card
				$this->stripeCharge($order);
				
				// Set 'paid' status to the order
				$this->changeOrderStatus($event);

				// Send payment confirmation mail
				$this->sendConfirmationMail($order);
				
			}else{
				// Create the session on Stripe's servers - this will charge the user's order and save session id into order transaction reference
				$this->stripeSession($order);
				
			}

        } catch(\Stripe\Error\Card $e) {
            // The card has been declined
            // FIXME Translate message here
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Card declined. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'Your card has been declined.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (\Stripe\Error\RateLimit $e) {
            // Too many requests made to the API too quickly
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Too many requests. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'Too many requests too quickly.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (\Stripe\Error\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Invalid parameters. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'Invalid parameters were supplied to Stripe.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (\Stripe\Error\Authentication $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Authentication failed: API key changed? Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'Authentication with Stripe failed. Please contact administrators.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (\Stripe\Error\ApiConnection $e) {
            // Network communication with Stripe failed
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Network communication failed. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'Network communication failed.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (\Stripe\Error\Base $e) {
            // Display a very generic error to the user
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'An error occurred with Stripe.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        } catch (StripePaymentException $e) {
            // Amount shown to the user by Stripe & order amount are not equal
            $logMessage = sprintf(
                'Error paying order %d with Stripe. Amounts are different. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = $e->getMessage();
        } catch (\Exception $e) {
            // Something else happened, completely unrelated to Stripe
            $logMessage = sprintf(
                'Error paying order %d with Stripe but maybe unrelated with it. Message: %s',
                $order->getId(),
                $e->getMessage()
            );

            $userMessage = Translator::getInstance()
                ->trans(
                    'An error occurred during payment.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                );
        }

        if ($logMessage !== NULL) {
            (new StripePaymentLog())->logText($logMessage);

            $this->redirectToFailurePage(
                $order->getId(),
                $userMessage
            );
        }
    }

    /**
     * Check if the amount displayed by Stripe and the order amount are the same
     * (in case once Stripe popup is displayed, the customer adds or removes a product from another tab)
     * @param OrderModel $order
     * @throws StripePaymentException
     */
    public function checkOrderAmount(OrderModel $order)
    {
        $stripeAmount = $this->request->getSession()->get('stripeAmount');
        $orderAmount = $order->getTotalAmount() * 100;

        if (strval($stripeAmount) != strval($orderAmount)) {
            throw new StripePaymentException(Translator::getInstance()
                ->trans(
                    'The payment mean does not have the same amount as your cart. Please reload and try again.',
                    [],
                    StripePayment::MESSAGE_DOMAIN
                )
            );
        }
    }

    /**
     * Send data to Stripe API & get response
	 * Save Stripe session as transaction reference
     * @param OrderModel $order
     * @return \Stripe\Session
     */
    public function stripeSession(OrderModel $order)
    {
		$infoItems=array();
        $this->request->getSession()->set('stripeAmount', 0); // Get order amount
		$stripeAmount=0;
		/* Impossible d'ajouter une ligne spécifique pour la remise, cette partie est mise de côté en attendant que stripe ajoute cette possibilité */
		/* DEBUT CODE PANIER DETAIL
		$baseSourceFilePath = ConfigQuery::read('images_library_path');
		if ($baseSourceFilePath === null) {
			$baseSourceFilePath = THELIA_LOCAL_DIR . 'media' . DS . 'images';
		} else {
			$baseSourceFilePath = THELIA_ROOT . $baseSourceFilePath;
		}
		if(null !== $orderProducts = OrderProductQuery::create()->filterByOrderId($order->getId())->joinOrderProductTax('opt', Criteria::LEFT_JOIN)->withColumn('SUM(`opt`.AMOUNT)', 'TOTAL_TAX')->withColumn('SUM(`opt`.PROMO_AMOUNT)', 'TOTAL_PROMO_TAX')->groupById()->find()){
			foreach ($orderProducts as $orderProduct) {
				$description='';
				if(null !== $orderProductAttributeCombinations = OrderProductAttributeCombinationQuery::create()->filterByOrderProductId($orderProduct->getId())->find()){
					foreach ($orderProductAttributeCombinations as $orderProductAttributeCombination) {
						if($description) $description .= ', ';
						$description .= $orderProductAttributeCombination->getAttributeTitle() . ' ' . $orderProductAttributeCombination->getAttributeAvTitle();
					}
				}
				$images=array();
				if(null !== $product = ProductQuery::create()->filterByRef($orderProduct->getProductRef())->findOne()){
					if(null !== $productImages = ProductImageQuery::create()->filterByProductId($product->getId())->filterByVisible(1)->orderBy('position')->find()){
						foreach ($productImages as $productImage) {
							// Put source image file path
							$sourceFilePath = sprintf(
								'%s/%s/%s',
								$baseSourceFilePath,
								'product',
								$productImage->getFile()
							);

							// Create image processing event
							$event = new ImageEvent();
							$event->setSourceFilepath($sourceFilePath);
							$event->setCacheSubdirectory('product');
							$width=100;
							try {
								// Dispatch image processing event
								$event->setWidth($width);
								$order->getDispatcher()->dispatch(TheliaEvents::IMAGE_PROCESS, $event);
								$images[]=$event->getFileUrl();
							} catch (\Exception $ex) {
								// Ignore the result and log an error
								Tlog::getInstance()->addError(sprintf("Failed to process image in image loop: %s", $ex->getMessage()));
							}
						}
					}
				}
				if($orderProduct->getWasInPromo()){
					$amount = $orderProduct->getPromoPrice() + $orderProduct->getVirtualColumn('TOTAL_PROMO_TAX');
				}else{
					$amount = $orderProduct->getPrice() + $orderProduct->getVirtualColumn('TOTAL_TAX');
				}
				
				$stripeAmount += $amount * $orderProduct->getQuantity() * 100;
				$line_items[] = [
					'name' => $orderProduct->getTitle(),
					'description' => $description,
					'images' => $images,
					'amount' => $amount*100,
					'currency' => 'eur',
					'quantity' => $orderProduct->getQuantity(),
				  ];
			}
		}
		if($order->getPostage()){
			if(null !== $module = ModuleQuery::create()->findPk($order->getDeliveryModuleId())){
				$locale = $this->request->getLocale();
				if($locale == 'en')$locale = 'en_US';
				$module->setLocale($locale);
				if(!$module->getTitle())$module->setLocale('fr_FR');
				$line_items[] = ['name'=> $module->getTitle(), 'description' => $module->getChapo(), 'quantity'=> 1, 'currency' => 'eur', 'amount' => ($order->getPostage()*100)];
				$stripeAmount += $order->getPostage() * 100;
			}
		}
		*/
		/*
		if($order->getDiscount() > 0){
			echo $order->getDiscount();
			$description=null;
			if(null !== $orderCoupons = OrderCouponQuery::create()->filterByOrderId($order->getId())->find()){
				foreach($orderCoupons as $orderCoupon){
					if($description)$description .= ', ';
					$description .= $orderCoupon->getTitle();
				}
			}
			$line_items[] = ['name'=> Translator::getInstance()->trans('Discount', [], StripePayment::MESSAGE_DOMAIN ), 'description' => $description, 'quantity'=> -1, 'currency' => 'eur', 'amount' => ($order->getDiscount()*100)];
				$stripeAmount -= $order->getDiscount() * 100;
		}
		*/
		// FIN CODE PANIER DETAIL
		/* à la place le montant sera envoyé en une seul ligne sans détail */
		$stripeAmount = $order->getTotalAmount() * 100;
		$line_items[] = ['name'=> Translator::getInstance()->trans('Total', [], StripePayment::MESSAGE_DOMAIN ), 'description' => null, 'quantity'=> 1, 'currency' => 'eur', 'amount' => $stripeAmount];
				
		$this->request->getSession()->set('stripeAmount', $stripeAmount); // Get order amount

		// Check order amount
		$this->checkOrderAmount($order);
		
		if(!empty($line_items)){
			try{
				// $message = $userMessage = Translator::getInstance()->trans('An error occurred during payment.', [], StripePayment::MESSAGE_DOMAIN );
				/* Le message d'erreur rend le cancel_url invalid */
				$message = 'error';
				$session = \Stripe\Checkout\Session::create([
				  'customer_email' => $order->getCustomer()->getEmail(),
				  'client_reference_id' => $order->getRef(),
				  'payment_method_types' => ['card'],
				  'line_items' => $line_items,
				  'success_url' => URL::getInstance()->absoluteUrl('/order/placed/' . $order->getId()),
				  'cancel_url' => URL::getInstance()->absoluteUrl('/order/failed/' . $order->getId() . '/' . $message),
				]);

				$this->request->getSession()->set('checkout_session_id', $session->id); // Get session id
				$order->setTransactionRef($session->id)->save();
				
				
				$this->parser->setTemplateDefinition(new TemplateDefinition('default',1));
				/* Je ne suis pas sur que ce soit la meilleur méthode. */
			 	echo $this->parser->render(
					'stripe-paiement.html',
					[
						'checkout_session_id' => $session->id,
						'public_key' => StripePayment::getConfigValue('publishable_key')
					]
				);
				exit;
			}
		  	catch (Exception $e) {
				$error = $e->getMessage();
				$_REQUEST['erreurpaiement']=1;
				$_REQUEST['erreurpaiementmsg']=$error;
			}
		}
    }

    /**
     * Send data to Stripe API & get response
     * @param OrderModel $order
     * @return \Stripe\Charge
     */
    public function stripeCharge(OrderModel $order)
    {
		// Token is created using Checkout or Elements!
		// Get the payment token ID submitted by the form:
		$token = $this->request->getSession()->get('stripeToken');
		$customerStripe=null;
		$stripeApiCustomerRetrieves = \Stripe\Customer::all(['limit' => 1, 'email' => $order->getCustomer()->getEmail()]);
		foreach($stripeApiCustomerRetrieves->data as $stripeApiCustomerRetrieve){
			$customerStripe = $stripeApiCustomerRetrieve;
		}
		
		if($customerStripe){
			$stripeApiCustomer = \Stripe\Customer::update(
			  $customerStripe->id,
			  [
				'source' =>  $token,
				'address' => [
					'line1'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getAddress1(),
					'city'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getCity(),
					'country'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()-> getCountry()->getTranslation()->getTitle(),
					'line2'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getAddress2(),
					'postal_code'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getZipcode(),
				]
			  ]
			);	
		}else{
			$stripeApiCustomer = \Stripe\Customer::create(
				[
					'email' => $order->getCustomer()->getEmail(),
					'source' =>  $token,
					'address' => [
						'line1'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getAddress1(),
						'city'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getCity(),
						'country'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()-> getCountry()->getTranslation()->getTitle(),
						'line2'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getAddress2(),
						'postal_code'=> $order->getOrderAddressRelatedByDeliveryOrderAddressId()->getZipcode(),
					]
				]
			);
		}
        $retour = \Stripe\Charge::create(
            [
                'customer' => $stripeApiCustomer,
                'amount' => $order->getTotalAmount() * 100,
                'currency' => $order->getCurrency()->getCode(),
				'description' => 'Order ' . $order->getRef(),
				'source' => $stripeApiCustomer->default_source,
				'metadata' => ['order_id' => $order->getId(), 'order_ref' => $order->getRef()],
            ]
        );
		$order->setTransactionRef($retour->id)->save();
    }

    /**
     * Save Stripe token as transaction reference
     * @param OrderModel $order
     */
    public function saveStripeToken(OrderModel $order)
    {
        $order
            ->setTransactionRef($this->request->getSession()->get('stripeToken'))
            ->save();
    }

    /**
     * Set paid status to the order
     * @param OrderEvent $orderEvent
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function changeOrderStatus(OrderEvent $orderEvent)
    {
        $paidStatusId = OrderStatusQuery::create()
            ->filterByCode('paid')
            ->select('ID')
            ->findOne();

        $event = new OrderEvent($orderEvent->getPlacedOrder());
        $event->setStatus($paidStatusId);
        $orderEvent->getDispatcher()->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
    }

    /**
     * Send payment confirmation mail
     * @param OrderModel $order
     * @throws \Exception
     */
    public function sendConfirmationMail(OrderModel $order)
    {
        $storeName = ConfigQuery::read('store_name', false);
        $storeSite = ConfigQuery::read('url_site', false);
        $contactEmail = ConfigQuery::read('store_email', false);

        Tlog::getInstance()->info("Sending Stripe payment confirmation email from store contact e-mail $contactEmail");

        if ($contactEmail) {

            $this->getMailer()->sendEmailToCustomer(
                StripePayment::CONFIRMATION_MESSAGE_NAME,
                $order->getCustomer(),
                [
                    "order_ref" => $order->getRef(),
                    "store_name" => $storeName,
                    "store_url" => $storeSite
                ]
            );
        }
    }

    /**
     * Redirect with failure message
     * @param $orderId
     * @param $message
     */
    public function redirectToFailurePage($orderId, $message)
    {
        throw new RedirectException(
            URL::getInstance()->absoluteUrl("/order/failed/$orderId/$message")
        );
    }
}
