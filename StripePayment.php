<?php

namespace StripePayment;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use StripePayment\Classes\StripePaymentException;
use StripePayment\Classes\StripePaymentLog;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\Order as OrderModel;
use Thelia\Model\OrderCouponQuery;
use Thelia\Model\OrderProductAttributeCombinationQuery;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

/**
 * Class StripePayment
 * @package StripePayment
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePayment extends AbstractPaymentModule
{
    const MESSAGE_DOMAIN = "stripepayment";
    const CONFIRMATION_MESSAGE_NAME = "stripe_confirm_payment";
    const STRIPE_VERSION_MIN = "3.0.0";
    const STRIPE_VERSION_MAX = "7.0.0";

    const PAYMENT_INTENT_ID_SESSION_KEY = 'payment_intent_id';
    const PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY = 'payment_intent_customer_id';
    const PAYMENT_INTENT_SECRET_SESSION_KEY = 'payment_intent_secret';

    public function preActivation(ConnectionInterface $con = null)
    {
        // Check if Stripe API is present
        try {
            $this->checkApi();
        } catch (\Exception $ex) {
            throw $ex;
        }

        return true;
    }

    public function postActivation(ConnectionInterface $con = null)
    {
        // Module image
        $moduleModel = $this->getModuleModel();

        if (! $moduleModel->isModuleImageDeployed($con)) {
            $this->deployImageFolder($moduleModel, sprintf('%s'.DS.'Resource'.DS.'images'.DS.'module', __DIR__), $con);
        }

        $this->createMailMessage();
    }

    public function createMailMessage()
    {
        // Create payment confirmation message from templates, if not already defined
        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {

            $languages = LangQuery::create()->find();

            $message = new Message();
            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)
                ->setHtmlTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.html')
                ->setTextTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.txt')
            ;

            foreach ($languages as $language) {
                /** @var Lang $language */
                $locale = $language->getLocale();
                $message
                    ->setLocale($locale)
                    ->setTitle(
                        Translator::getInstance()->trans(
                            "Payment confirmation for Stripe Payment",
                            [],
                            self::MESSAGE_DOMAIN,
                            $locale
                        )
                    )
                    ->setSubject(
                        Translator::getInstance()->trans(
                            'Payment confirmation of your order {$order_ref} on {$store_name}',
                            [],
                            self::MESSAGE_DOMAIN,
                            $locale
                        )
                    )
                ;
            }

            $message->save();
        }
    }

    public function checkApi()
    {
        try {
            $ReflectedClass = new \ReflectionClass('Stripe\Stripe');
        } catch (\Exception $ex) {
            throw new \Exception(
                Translator::getInstance()->trans(
                    "Stripe library is missing.",
                    [],
                    self::MESSAGE_DOMAIN
                )
            );
        }

        $stripeVersion = \Stripe\Stripe::VERSION;

        if (version_compare(self::STRIPE_VERSION_MIN, $stripeVersion) == 1) {
            throw new \Exception(
                Translator::getInstance()->trans(
                    "Stripe version is lower than min version (%version). Current version: %curVersion.",
                    [
                        '%version' => self::STRIPE_VERSION_MIN,
                        '%curVersion' => $stripeVersion
                    ],
                    self::MESSAGE_DOMAIN
                )
            );
        }

        if (version_compare(self::STRIPE_VERSION_MAX, $stripeVersion) < 1) {
            throw new \Exception(
                Translator::getInstance()->trans(
                    "Stripe version is greater than max version (< %version). Current version: %curVersion.",
                    [
                        '%version' => self::STRIPE_VERSION_MAX,
                        '%curVersion' => $stripeVersion
                    ],
                    self::MESSAGE_DOMAIN
                )
            );
        }
    }

    /**
     *
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is send to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway. On your response you can return this form already
     *  completed, ready to be sent
     *
     * @param  \Thelia\Model\Order $order processed order
     * @return null|\Thelia\Core\HttpFoundation\Response
     */
    public function pay(Order $order)
    {
        if (!$this->isValidPayment()) {
            throw new Exception("Your connection is not secured. Check that 'https' is present at the beginning of the site's address.");
        }

        return $this->doPay($order);
    }

    protected function doPay(Order $order)
    {
        Stripe::setApiKey(StripePayment::getConfigValue('secret_key'));
        $session = $this->getRequest()->getSession();

        try {

            if(StripePayment::getConfigValue('stripe_element')){
                $order->setTransactionRef($session->get(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY))
                    ->save();
                $session->set(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY, null);
                $session->set(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY, null);
                $session->set(StripePayment::PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY, null);

                return;
            }else{
                $session->set(StripePayment::PAYMENT_INTENT_ID_SESSION_KEY, null);
                $session->set(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY, null);
                $session->set(StripePayment::PAYMENT_INTENT_CUSTOMER_ID_SESSION_KEY, null);

                // Create the session on Stripe's servers - this will charge the user's order and save session id into order transaction reference
                return $this->createStripeSession($order);
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

            return new RedirectResponse(
                URL::getInstance()->absoluteUrl("/order/failed/".$order->getId()."/".$userMessage)
                );
        }

        return new Response();
    }

    public function createStripeSession(OrderModel $order)
    {
        /* Impossible d'ajouter une ligne spécifique pour la remise, cette partie est mise de côté en attendant que stripe ajoute cette possibilité

        $lineItems = $this->prepareLineItems($order);

        */

        $currency = $order->getCurrency();

        if (null === $currency) {
            $currency = $this->getRequest()->getSession()->getCurrency();
        }

        $lineItems[] = [
            'name'=> Translator::getInstance()->trans('Total', [], StripePayment::MESSAGE_DOMAIN ),
            'description' => null,
            'quantity'=> 1,
            'currency' => strtolower($currency->getCode()),
            'amount' => round($order->getTotalAmount(), 2) * 100
        ];

        if(empty($lineItems)){
            throw new \Exception("Sorry, your cart is empty. There's nothing to pay.");
        }

        $session = Session::create([
            'customer_email' => $order->getCustomer()->getEmail(),
            'client_reference_id' => $order->getRef(),
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'success_url' => URL::getInstance()->absoluteUrl('/order/placed/' . $order->getId()),
            'cancel_url' => URL::getInstance()->absoluteUrl('/order/failed/' . $order->getId() . '/error'),
        ]);

        $order->setTransactionRef($session->payment_intent)->save();

        /** @var ParserInterface $parser */
        $parser = $this->getContainer()->get("thelia.parser");

        $parser->setTemplateDefinition(
            $parser->getTemplateHelper()->getActiveFrontTemplate()
        );

        $renderedTemplate = $parser->render(
            "stripe-paiement.html",
            [
                'checkout_session_id' => $session->id,
                'public_key' => StripePayment::getConfigValue('publishable_key')
            ]
        );

        return Response::create($renderedTemplate);
    }

    /**
     *
     * This method is call on Payment loop.
     *
     * If you return true, the payment method will be display
     * If you return false, the payment method will not be display
     *
     * @return boolean
     */
    public function isValidPayment()
    {
        return ( ($this->isDevEnvironment() || $this->isSslEnabled()) && $this->getConfigValue('enabled') );
    }

    /**
     * Return true if the current environment is in Dev mode
     *
     * @return bool
     */
    protected function isDevEnvironment()
    {
        return 'dev' == $this->getContainer()->getParameter('kernel.environment');
    }

    /**
     * return true if SSL is enabled
     *
     * @return bool
     */
    protected function isSslEnabled()
    {
        return $this->getRequest()->isSecure();
    }

    public function checkOrderAmount(OrderModel $order, $stripeAmount)
    {
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

    protected function prepareLineItems(Order $order, $currency)
    {
        $stripeAmount = 0;
        $lineItems = [];

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
                $lineItems[] = [
                    'name' => $orderProduct->getTitle(),
                    'description' => $description,
                    'images' => $images,
                    'amount' => $amount*100,
                    'currency' => $currency,
                    'quantity' => $orderProduct->getQuantity(),
                ];
            }
        }
        if ($order->getPostage()){
            if (null !== $module = ModuleQuery::create()->findPk($order->getDeliveryModuleId())){
                $locale = $this->getRequest()->getLocale();
                if ($locale == 'en') {
                    $locale = 'en_US';
                }
                $module->setLocale($locale);

                if (!$module->getTitle()) {
                    $module->setLocale('fr_FR');
                }
                $lineItems[] = ['name'=> $module->getTitle(), 'description' => $module->getChapo(), 'quantity'=> 1, 'currency' => $currency, 'amount' => ($order->getPostage()*100)];
                $stripeAmount += $order->getPostage() * 100;
            }
        }

        if($order->getDiscount() > 0){
            $description=null;
            if(null !== $orderCoupons = OrderCouponQuery::create()->filterByOrderId($order->getId())->find()){
                foreach($orderCoupons as $orderCoupon){
                    if($description)$description .= ', ';
                    $description .= $orderCoupon->getTitle();
                }
            }
            $lineItems[] = ['name'=> Translator::getInstance()->trans('Discount', [], StripePayment::MESSAGE_DOMAIN ), 'description' => $description, 'quantity'=> 1, 'currency' => $currency, 'amount' => -($order->getDiscount()*100)];
            $stripeAmount -= $order->getDiscount() * 100;
        }

        $this->checkOrderAmount($order, $stripeAmount);

        return $lineItems;
    }


    /**
     * if you want, you can manage stock in your module instead of order process.
     * Return false to decrease the stock when order status switch to pay
     *
     * @return bool
     */
    public function manageStockOnCreation()
    {
        return false;
    }
}
