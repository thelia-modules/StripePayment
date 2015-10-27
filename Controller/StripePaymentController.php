<?php

namespace StripePayment\Controller;

use Thelia\Module\BasePaymentModuleController;

/**
 * Class StripePaymentController
 * @package StripePayment\Controller
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentController extends BasePaymentModuleController
{
    /**
     * Return a module identifier used to calculate the name of the log file,
     * and in the log messages.
     *
     * @return string the module code
     */
    protected function getModuleCode()
    {
        return 'StripePayment';
    }
}