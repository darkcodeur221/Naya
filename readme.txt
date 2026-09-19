=== Naya — Assistant IA ===
Contributors: darkcodeur221
Tags: chatbot, ia, ai, deepseek, assistant, support
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Chatbot IA propulsé par DeepSeek — par Deejitcorp : widget flottant élégant, page de chat dédiée et mémoire de conversation persistante.

== Description ==

Naya conseille vos visiteurs et répond à leurs demandes directement sur votre site :

* Widget flottant animé en bas à droite, aux couleurs de votre marque
* Page de chat dédiée en plein écran avec historique des conversations
* Mémoire de contexte : les conversations sont stockées en base et rejouées à l'IA
* Réglages complets : modèle DeepSeek, prompt système, message d'accueil, suggestions, couleurs

== Installation ==

1. Copiez le dossier du plugin dans `wp-content/plugins/`
2. Activez le plugin
3. Renseignez votre clé API DeepSeek dans Réglages → Naya

== Changelog ==

= 2.3.0 =
* Catalogue produits complet : Naya connaît tous les produits WooCommerce du site, plus seulement les 30 derniers. À chaque question, elle cherche les produits concernés et lit en direct leur prix, leur promotion, leur stock et les tailles ou couleurs encore disponibles
* Recherche adaptée aux questions des visiteurs : budget (« moins de 20 000 », « entre 60 000 et 100 000 »), promotions, « le moins cher », meilleures ventes, synonymes courants (smartphone, tel, portable → téléphones), référence produit, et relances (« et en XL ? », « le moins cher ? »)
* Naya n'invente plus de produit : si rien ne correspond, elle le dit et propose le lien de recherche de la boutique. Les produits masqués du catalogue ne sont jamais proposés
* Vue d'ensemble de la boutique (rayons et meilleures ventes) pour les questions générales ; nombre de produits connus affiché dans les réglages
* Compatible places de marché : le nom de la boutique du vendeur (Dokan, WCFM) est indiqué

= 2.2.0 =
* Nouvelle fenêtre de conversation : modale centrée sur un voile flouté, le site reste visible derrière le flou. Rien ne passe par-dessus (bandeau cookies, boutons flottants du thème) et la page ne défile plus en arrière-plan
* Mobile : feuille plein écran qui glisse du bas, avec un liseré du site flouté en haut. Elle suit la hauteur réellement visible quand le clavier s'ouvre, se ferme en glissant l'en-tête vers le bas ou avec le bouton « retour » du téléphone
* Nouvel écran d'accueil : avatar animé, message de bienvenue centré et questions proposées en grandes cartes faciles à toucher, au lieu d'un grand vide
* Accessibilité : focus clavier maintenu dans la fenêtre, Échap pour fermer, focus rendu à la barre à la fermeture

= 2.1.5 =
* Correctif majeur : derrière Cloudflare, les visiteurs recevaient des fichiers naya.css / naya.js vieux de plusieurs mois (cache d'un an, paramètre ?ver= supprimé par l'optimiseur). Les fichiers sont désormais servis depuis un chemin qui contient la version (uploads/naya/<version>/), donc chaque mise à jour est vue immédiatement
* La configuration du widget est aussi portée par l'attribut data-naya-config du HTML : Naya démarre même quand LiteSpeed retarde ou réordonne les scripts (« Delay JS »)
* Barre du header : cliquer sur une suggestion ne referme plus le panneau, et le bouton rouge « Terminer » s'affiche bien pendant la conversation
* Les anciennes copies versionnées sont nettoyées automatiquement (3 versions conservées)

= 2.1.4 =
* Correctif majeur : Naya fonctionnait pour l'administrateur mais pas pour les visiteurs sur un site avec LiteSpeed Cache. Les visiteurs anonymes n'ont plus besoin de jeton de sécurité ; ils sont protégés par une vérification d'origine sans état, que le cache ne peut pas figer. Les comptes connectés gardent le jeton
* Sécurité : aucune réponse du chat ne peut plus être mise en cache. Avec l'option « Cache REST API » de LiteSpeed, la liste et l'historique des conversations d'un visiteur pouvaient être resservis à un autre
* Limites de débit revues pour les réseaux mobiles partagés (CGNAT) : le rythme est limité par visiteur et non plus par adresse IP, qui ne sert plus qu'à bloquer les abus massifs
* Un visiteur qui a perdu sa session n'est plus bloqué : une nouvelle conversation s'ouvre avec son message
* La vérification d'origine accepte indifféremment www.exemple.com et exemple.com

= 2.1.3 =
* Correctif majeur : sur un site avec cache de page, le jeton de sécurité inscrit dans le HTML expirait au bout de 24 h et tous les envois de message étaient rejetés. Naya obtient désormais un jeton frais et rejoue la requête automatiquement, sans que le visiteur ne voie d'erreur
* Le script démarre même si un optimiseur le charge avant ses données de configuration, au lieu d'abandonner définitivement
* Messages d'erreur plus clairs lorsque le serveur renvoie une réponse illisible

= 2.1.2 =
* Le CSS et le JavaScript du plugin sont désormais exclus de la combinaison, de la minification et de l'élagage du « CSS inutilisé » pratiqués par LiteSpeed, WP Rocket et Autoptimize — c'était la cause des éléments empilés et du chat sans mise en forme
* Mise en forme de secours complète : même si la feuille de styles n'est pas servie, la barre reste correcte en haut de page au lieu de se disloquer
* Le champ piège anti-robots ne peut plus devenir visible
* Un avertissement en console signale une feuille de styles absente ou périmée

= 2.1.1 =
* Correctif : avec une extension de cache (LiteSpeed, WP Rocket…), le CSS et le JavaScript combinés restaient figés après une mise à jour et le widget s'affichait sans style, empilé en bas de page
* Les caches sont désormais purgés automatiquement dès qu'un changement de version est détecté
* Filet de sécurité : les règles de positionnement essentielles sont générées en ligne par PHP, donc toujours à jour même si un fichier en cache ne l'est pas

= 2.1.0 =
* Bouton rouge « Terminer » bien visible : clôture la conversation, propose l'avis au bon moment, puis repart à neuf
* Confort mobile : panneau plein écran qui suit la hauteur réellement visible (clavier compris), poignée de glissement pour fermer d'un geste, zones tactiles agrandies, champ à 16 px (plus de zoom automatique iOS), zone de saisie au-dessus de la barre système
* En-tête mobile épuré : l'étoile et le plein écran disparaissent au profit de « Terminer » et « Réduire »
* Suggestions animées en cascade et défilement horizontal sur mobile, pour démarrer d'un seul geste
* Ligne d'invitation sous le message d'accueil : « Choisissez une question ou écrivez la vôtre »

= 2.0.1 =
* Mises à jour automatiques depuis GitHub : le plugin apparaît dans « Extensions → Mises à jour » comme un plugin du répertoire officiel, plus besoin d'installer un ZIP à la main
* Lien « Vérifier les mises à jour » sur la ligne du plugin
* Aucune bibliothèque tierce embarquée ; fonctionne avec une release ou un simple tag Git

= 2.0.0 =
* Nouvelle présentation par défaut : barre de conversation en haut de page, avec champ de saisie visible en permanence, panneau qui se déploie, réduction en onglet et compteur de réponses non lues
* La bulle flottante reste disponible en option
* Playbook commercial : méthode de vente consultative (accueillir, comprendre, apporter de la valeur, qualifier, engager), traitement des objections, capture des coordonnées, degré d'initiative réglable
* Alertes e-mail repensées : deux niveaux de priorité (à rappeler / piste), coordonnées du prospect, e-mail HTML avec transcription, plusieurs destinataires possibles, relance si la situation s'aggrave
* Priorité et coordonnées affichées dans le tableau de bord et l'export CSV

= 1.6.1 =
* Correctif : la croix de fermeture de la bulle d'accroche se retrouvait dans le flux (gros carré) sur les thèmes qui stylent les boutons en `!important`
* Blindage du widget contre les styles de thème (positions, tailles, majuscules, ombres) sur le bouton flottant, la bulle, l'en-tête, les suggestions et le champ d'envoi
* Design de l'accroche revu : avatar rond avec pastille « en ligne », queue de bulle orientée vers le bouton, croix discrète au survol, ombres adoucies
* Les ombres du bouton flottant suivent désormais la couleur choisie au lieu d'un violet figé

= 1.6.0 =
* Incitation à la conversation : bulle d'accroche animée, badge de notification et frétillement périodique du bouton
* Déclencheurs intelligents : temps passé sur la page, défilement au-delà de 45 %, ou intention de sortie
* Message d'accroche et délai configurables ; l'accroche ne s'impose qu'une fois par visite
* Taux de clic des accroches suivi dans le tableau de bord
* Animations désactivées si le visiteur a activé « réduire les animations »

= 1.5.0 =
* Notation de l'agent (1 à 5 étoiles) avec commentaire facultatif : bouton ★ dans l'en-tête et invitation automatique après 60 s d'inactivité
* Score de satisfaction (CSAT) dans le tableau de bord : note moyenne, % de satisfaits, nombre d'avis, derniers commentaires
* Alerte e-mail immédiate en cas de note faible (1-2 étoiles) avec la transcription
* Notes et commentaires ajoutés à l'export CSV

= 1.4.0 =
* Tableau de bord statistiques (menu « Naya » dans l'admin) : conversations, messages, visiteurs uniques, engagement, leads détectés et taux de conversion, ouvertures du widget, clics WhatsApp
* Graphique des conversations par jour (30 jours) et heures de pointe
* Top des premières questions des visiteurs et liste des derniers leads avec la raison détectée par l'IA
* Export CSV des conversations
* Collecte d'événements front (ouverture du widget, clics sur les liens et WhatsApp)

= 1.3.0 =
* Naya est nourrie du contenu du site : pages, articles et produits WooCommerce (titres, liens, résumés, prix) injectés dans son prompt
* Champ « Connaissances complémentaires » dans l'admin (tarifs, offres, FAQ)
* Réponses courtes (2 à 4 phrases) avec liens réels cliquables — plus d'URL inventée
* Redirection des prospects sérieux vers WhatsApp (numéro configurable, lien wa.me)

= 1.2.0 =
* Notification e-mail automatique quand l'IA détecte une conversation intéressante (prospect, devis, réclamation)
* Bouclier anti-bots : honeypot, filtrage user-agent, contrôle d'origine, limites par IP avec bannissement temporaire
* Garde-fou anti-injection dans le prompt système

= 1.1.0 =
* Moteur IA remplacé par DeepSeek (deepseek-chat / deepseek-reasoner)
* Marque « Propulsé par Deejitcorp » sur le widget et la page dédiée

= 1.0.0 =
* Version initiale : widget flottant, page dédiée, mémoire de conversation, réglages admin.
