<?php

namespace StripePayment\EventListeners;

use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserInterface;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;

class SendConfirmationEmailListener implements EventSubscriberInterface
{
    /**
     * @var MailerFactory
     */
    protected $mailer;
    /**
     * @var ParserInterface
     */
    protected $parser;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(ParserInterface $parser, MailerFactory $mailer, EventDispatcherInterface $eventDispatcher)
    {
        $this->parser = $parser;
        $this->mailer = $mailer;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @return MailerFactory
     */
    public function getMailer()
    {
        return $this->mailer;
    }

    public function updateOrderStatus(OrderEvent $event)
    {
        $stripe = new StripePayment();

        if ($event->getOrder()->isPaid() && $stripe->isPaymentModuleFor($event->getOrder())) {
            $this->eventDispatcher->dispatch($event, TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL);
            $this->eventDispatcher->dispatch($event, TheliaEvents::ORDER_SEND_NOTIFICATION_EMAIL);
        }
    }


    public function cancelOrderConfirmationEmail(OrderEvent $event)
    {
        $stripe = new StripePayment();

        if ($stripe->isPaymentModuleFor($event->getOrder()) && !$event->getOrder()->isPaid()) {
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::ORDER_UPDATE_STATUS => array("updateOrderStatus", 128),
            TheliaEvents::ORDER_SEND_NOTIFICATION_EMAIL => array("cancelOrderConfirmationEmail", 150),
            TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL => array("cancelOrderConfirmationEmail", 150)
        );
    }

}