<?php

namespace StripePayment\Hook;

use StripePayment\StripePayment;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Tools\URL;

/**
 * Class StripePaymentHook
 * @package StripePayment\Hook
 * @author Etienne Perriere - OpenStudio <eperriere@openstudio.fr>
 */
class StripePaymentHook extends BaseHook
{

    protected $taxEngine;

    // Migration Thelia 3 : BaseHook::__construct prend (?EventDispatcherInterface,
    // ?ParserResolver). La requete est resolue a l'appel, elle n'existe pas
    // forcement a la construction du service.
    //
    // Note : Config/config.xml declare ce service avec une liste d'<argument> figee
    // (request_stack, thelia.taxEngine), sans autowiring. Modifier cette liste est hors
    // perimetre (Config/** n'est pas dans les chemins autorises) : `$this->container` reste
    // aussi non initialise pour ce service (l'injection de la propriete #[Required] ne se
    // declenche que sur les services autowire). On n'ajoute donc aucune dependance
    // supplementaire au constructeur ; l'ecran de configuration s'appuie sur les acces
    // globaux deja utilises par ce module (URL::getInstance(), $this->translator) et sur la
    // fonction Twig `getForm()` (module TwigEngine, deja utilisee cote front) pour obtenir le
    // FormView, exactement comme le faisait le tag Smarty {form}.
    public function __construct(
        private readonly RequestStack $requestStack,
        TaxEngine $taxEngine,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        $this->taxEngine = $taxEngine;
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * Migration Thelia 3 : le hook `module.configuration` reste declare dans Config/config.xml
     * (hors perimetre de cette migration) avec l'attribut historique
     * `templates="render:stripepayment-configuration.html"`. Cette declaration est persistee
     * telle quelle dans la table `module_hook` (colonne `templates`) et toujours invoquee via
     * la methode heritee `insertTemplate()`. Or `BaseHook::render()` choisit le parser par
     * extension litterale du nom de fichier : avec un nom fige a ".html" dans cette table, seul
     * Smarty peut jamais matcher, meme si un fichier ".html.twig" existe a cote. On surcharge
     * donc `insertTemplate()` pour prendre la main sur ce seul hook (identifie par son code
     * d'evenement, qui contient toujours "module.configuration") et rendre nous-memes l'ecran
     * Twig, avec le FormView en parametre. Les autres hooks de cette classe utilisent deja un
     * attribut "method" explicite dans le XML et ne passent jamais par ici.
     */
    public function insertTemplate(HookRenderEvent $event, string $code): void
    {
        if (!str_contains($code, 'module.configuration')) {
            parent::insertTemplate($event, $code);

            return;
        }

        $event->add($this->renderConfigurationScreen());
    }

    private function renderConfigurationScreen(): string
    {
        $successMessage = null;
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && $request->hasSession()) {
            if ([] !== $request->getSession()->getFlashBag()->get('stripepayment_success')) {
                $successMessage = $this->trans('Configuration correctly saved', [], StripePayment::MESSAGE_DOMAIN);
            }
        }

        $secureUrl = (string) (StripePayment::getConfigValue(StripePayment::SECURE_URL) ?? '');

        return $this->render('stripe-payment/configuration.html.twig', [
            'success_message' => $successMessage,
            'save_url' => URL::getInstance()->absoluteUrl('/admin/module/StripePayment'),
            'close_url' => URL::getInstance()->absoluteUrl('/admin/modules'),
            'webhook_url' => '' !== $secureUrl
                ? URL::getInstance()->absoluteUrl('/module/StripePayment/stripe_webhook/'.$secureUrl.'/listen')
                : null,
        ]);
    }

    public function includeStripe(HookRenderEvent $event)
    {
		if(StripePayment::getConfigValue('stripe_element')){
			$publicKey = StripePayment::getConfigValue('publishable_key');
			$clientSecret = $this->requestStack->getCurrentRequest()->getSession()->get(StripePayment::PAYMENT_INTENT_SECRET_SESSION_KEY);
			$currency = strtolower($this->requestStack->getCurrentRequest()->getSession()->getCurrency()->getCode());
            $country = $this->taxEngine->getDeliveryCountry()->getIsoalpha2();
            $event->add($this->render(
				'assets/js/stripe-js.html',
				[
					'stripe_module_id' => $this->getModule()->getModuleId(),
					'public_key' => $publicKey,
                    'oneClickPayment' => StripePayment::getConfigValue(StripePayment::ONE_CLICK_PAYMENT, false),
                    'clientSecret' => $clientSecret,
                    'currency' => $currency,
                    'country' => $country
				]
			));
		}
    }

    public function declareStripeOnClickEvent(HookRenderEvent $event)
    {
		if(StripePayment::getConfigValue('stripe_element')){
			$publicKey = StripePayment::getConfigValue('publishable_key');
			$event->add($this->render(
				'assets/js/order-invoice-after-js-include.html',
				[
					'stripe_module_id' => $this->getModule()->getModuleId(),
					'public_key' => $publicKey
				]
			));
		}
    }

    public function includeStripeJsV3(HookRenderEvent $event)
    {
        $event->add('<script src="https://js.stripe.com/v3/"></script>');
    }

	public function onMainHeadBottom(HookRenderEvent $event)
    {
        $content = $this->addCSS('assets/css/styles.css');
        $event->add($content);
    }
}