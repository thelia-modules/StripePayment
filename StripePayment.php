<?php

namespace StripePayment;

use Propel\Runtime\Connection\ConnectionInterface;
use Stripe\Stripe;
use Symfony\Component\Config\Definition\Exception\Exception;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;

/**
 * Class StripePayment
 * @package StripePayment
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePayment extends AbstractPaymentModule
{
    const MESSAGE_DOMAIN = "stripepayment";
    const ROUTER = "router.stripepayment";
    const CONFIRMATION_MESSAGE_NAME = "stripe_confirm_payment";
    const STRIPE_VERSION_MIN = "3.0.0";
    const STRIPE_VERSION_MAX = "4.0.0";

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
