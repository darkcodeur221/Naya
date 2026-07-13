=== Naya — Assistant IA ===
Contributors: darkcodeur221
Tags: chatbot, ia, ai, deepseek, assistant, support
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
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
