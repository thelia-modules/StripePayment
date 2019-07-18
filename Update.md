Nouvelle version des fichier API

Deux manières différente de pouvoir payer. 
Avec Element (avoir le formulaire de paiement directement sur le site), avec Checkout (être redirigé sur le site de stripe)

Choix dans l'administration sur qu'elle solution le site fonctionne. 

Il faut vérifier les modifications et peut-être améliorer ou deplacer certaines parties. 
Je me demande si le \Stripe\Checkout\Session ne devrait pas être ailleurs que dans un listerner ?

Le webhook de stripe est utilisé pour passer la commande en payé quand on utilise le checkout.


Sur element quand on a le formulaire de paiement sur le site, il faudrait faire la partie qui permet d'afficher le bouton "apple pay" ou "google pay" (j'ai mis que le code html pour le moment)
	<div id="payment-request-button">
	  <!-- A Stripe Element will be inserted here. -->
	</div>
