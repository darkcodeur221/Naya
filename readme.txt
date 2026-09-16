=== Naya — Assistant IA ===
Contributors: darkcodeur221
Tags: chatbot, ia, ai, deepseek, assistant, support
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.2
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
