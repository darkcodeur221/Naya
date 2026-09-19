# Naya — Assistant IA pour WordPress 🤖

Chatbot IA propulsé par **Deejitcorp**. Naya conseille vos visiteurs, répond à leurs demandes et les oriente, avec une mémoire de conversation persistante.

![Version](https://img.shields.io/badge/version-2.2.0-blueviolet) ![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![Licence](https://img.shields.io/badge/licence-GPL--2.0-green)

## ✨ Fonctionnalités

- **Barre de conversation en haut de page** (présentation par défaut) : champ de saisie visible en permanence — ce qui déclenche le plus d'échanges — panneau qui se déploie sous la barre, réduction en onglet discret, compteur de réponses non lues. La page est décalée comme avec la barre d'administration WordPress, donc rien n'est recouvert. La bulle flottante en bas à droite reste disponible en option.
- **Conseillère commerciale, pas simple FAQ** : Naya applique une méthode de vente consultative — accueillir, comprendre le besoin, apporter de la valeur, qualifier en douceur, puis proposer l'étape suivante et demander les coordonnées. Elle traite les objections (prix, doute, mécontentement) et son degré d'initiative se règle en trois niveaux.
- **Page de chat dédiée** (style Alibaba) créée automatiquement à l'activation : plein écran, barre latérale avec l'historique des conversations, création/suppression de conversations.
- **Mémoire de contexte** : chaque conversation est stockée en base de données (`wp_naya_conversations` / `wp_naya_messages`). Les 30 derniers messages sont renvoyés à DeepSeek à chaque tour — Naya se souvient de ce qui a été dit.
- **Visiteurs anonymes ou connectés** : identification par cookie sécurisé (1 an) ou par compte WordPress.
- **Personnalisation complète** depuis l'admin : clé API, modèle (DeepSeek Chat / DeepSeek Reasoner), nom du bot, message d'accueil, prompt système, couleurs, suggestions.
- **Incitation à la conversation** : une bulle d'accroche animée surgit à côté du bouton, accompagnée d'un badge de notification et d'un frétillement périodique, pour pousser les visiteurs à écrire. Elle se déclenche au premier signal d'intérêt — temps passé, défilement au-delà de 45 %, ou intention de sortie — ne s'impose qu'une fois par visite, et se retire d'elle-même après 20 s. Message et délai configurables ; taux de clic mesuré dans le tableau de bord.
- **Pensé pour le mobile** : panneau plein écran qui suit la hauteur réellement visible (le clavier ne masque plus le champ), **poignée de glissement** pour fermer d'un geste vers le bas, zones tactiles d'au moins 44 px, champ de saisie à 16 px pour éviter le zoom automatique d'iOS, et en-tête épuré où seuls « Terminer » et « Réduire » subsistent.
- **Bouton rouge « Terminer »** : bien mis en évidence et animé, il apparaît dès le premier échange. Il clôture la conversation, demande l'avis au moment le plus opportun — celui où le visiteur décide lui-même d'avoir fini — puis repart sur une conversation neuve.
- **Notation de l'agent (CSAT)** : les visiteurs notent la conversation de 1 à 5 étoiles avec un commentaire facultatif — bouton ★ dans l'en-tête du chat et invitation automatique discrète après 60 s d'inactivité. Note moyenne, % de satisfaits et derniers avis dans le tableau de bord ; **alerte e-mail immédiate en cas de note faible** (1-2 étoiles) avec la transcription.
- **Tableau de bord statistiques** (menu « Naya » dans l'admin, sur 30 jours) : conversations, messages, visiteurs uniques, engagement (messages/conversation), **leads détectés et taux de conversion**, ouvertures du widget, clics WhatsApp — plus un graphique d'activité par jour, les heures de pointe, le top des questions posées, la liste des derniers leads avec la raison détectée par l'IA, et un **export CSV**.
- **Nourrie du contenu du site** : Naya lit automatiquement vos pages, articles et produits WooCommerce (titres, liens, résumés, prix) et ne répond qu'à partir de ces connaissances — réponses courtes, précises, avec de **vrais liens cliquables**, jamais d'URL inventée. Un champ « Connaissances complémentaires » permet d'ajouter tarifs, offres et FAQ.
- **Redirection WhatsApp** : quand un visiteur montre une intention sérieuse (achat, devis, projet), Naya lui propose de poursuivre sur WhatsApp (numéro configurable, lien wa.me).
- **Alertes e-mail à deux niveaux** : Naya distingue 🔥 « à rappeler » (devis ferme, échéance proche, budget annoncé, rendez-vous, client mécontent, coordonnées laissées) et 💡 « piste » (intérêt réel sans urgence). L'e-mail HTML contient les **coordonnées collectées**, la situation et la transcription complète, et part vers un ou plusieurs destinataires. Une alerte par conversation, renouvelée uniquement si la situation s'aggrave ou si des coordonnées arrivent.
- **Bouclier anti-bots** : champ honeypot invisible, filtrage des user-agents automatisés (curl, python, headless…), contrôle d'origine (Origin/Referer), intervalle minimum entre messages, plafond horaire par IP avec bannissement temporaire d'une heure.
- **Résistant aux thèmes** : le widget reprend la main sur les styles que les thèmes WordPress imposent aux boutons (positions, tailles minimales, majuscules, ombres), pour que la mise en page reste intacte quel que soit le thème installé.
- **Sécurité** : nonces REST, requêtes préparées, vérification de propriété des conversations, limite de débit (20 messages / 5 min / visiteur), garde-fou anti-injection de prompt (l'IA refuse de changer de rôle ou de révéler ses instructions), clé API jamais exposée côté client.

## ⚡ Compatibilité avec les extensions de cache

Le widget est injecté en pied de page et ses classes sont posées en JavaScript : les optimiseurs de CSS le croient inutilisé et suppriment ses règles, ce qui disloque l'interface. Naya demande donc explicitement que ses deux fichiers soient servis tels quels (`data-no-optimize`, liste blanche UCSS LiteSpeed), purge les caches à chaque changement de version, et embarque une **mise en forme de secours en ligne** qui garde la barre correcte même si la feuille de styles manque.

Côté conversations, aucune réponse du chat n'est jamais mise en cache (en-têtes `no-store`, `litespeed_control_set_nocache`, paramètre unique sur chaque lecture) : chacune est propre à un visiteur. Les visiteurs anonymes n'ont pas besoin de jeton de sécurité — un jeton inscrit dans une page en cache expirerait et bloquerait tout envoi — ; ils sont protégés par une vérification d'origine (`Sec-Fetch-Site`, `Origin`, `Referer`) qui n'a aucun état. Les comptes connectés conservent le jeton.

Si l'affichage paraît cassé après une mise à jour, purgez le cache — **y compris les fichiers CSS/JS combinés** (LiteSpeed : *Boîte à outils → Purger tout*). La console du navigateur affiche un avertissement explicite dans ce cas.

## 🔄 Mises à jour automatiques

Naya se met à jour **depuis ce dépôt**, comme un plugin du répertoire officiel : la mise à jour apparaît dans *Extensions → Mises à jour* sur chaque site, et s'installe en un clic. Rien à installer côté client, aucune bibliothèque tierce embarquée.

**Publier une nouvelle version** (depuis le dossier du plugin) :

```bash
git tag v2.0.2 && git push origin v2.0.2
```

Le numéro du tag doit correspondre à l'en-tête `Version:` de `naya.php`. Les sites voient la mise à jour dans les 6 heures (délai de cache) ; le lien **« Vérifier les mises à jour »**, sous la ligne du plugin dans *Extensions*, force la vérification immédiate.

Pour accompagner une version de notes de publication, créez une *release* GitHub sur le tag : son contenu s'affiche dans la fenêtre « Voir les détails » du plugin.

## 🚀 Installation

1. Téléchargez le dossier `Naya` (ou clonez ce dépôt) dans `wp-content/plugins/`.
2. Activez **Naya — Assistant IA** dans *Extensions*.
3. Allez dans **Réglages → Naya** et collez votre clé API DeepSeek ([platform.deepseek.com](https://platform.deepseek.com/)).
4. C'est tout : la bulle apparaît sur le site et la page *Assistant Naya* est prête.

## 🧩 Shortcode

Intégrez le chat plein écran sur n'importe quelle page :

```
[naya_chat]
```

## 🗂️ Structure

```
naya.php                              → point d'entrée du plugin
includes/
  class-naya-activator.php            → tables SQL + page dédiée + options par défaut
  class-naya-conversations.php        → mémoire (sessions, conversations, messages, contexte)
  class-naya-deepseek.php             → client API DeepSeek (wp_remote_post)
  class-naya-security.php             → bouclier anti-bots (honeypot, UA, IP, origine)
  class-naya-notify.php               → alertes e-mail sur conversations intéressantes
  class-naya-knowledge.php            → index du contenu du site (pages, articles, produits)
  class-naya-stats.php                → tableau de bord statistiques + export CSV
  class-naya-playbook.php             → méthode commerciale injectée dans le prompt
  class-naya-updater.php              → mises à jour automatiques depuis GitHub
  class-naya-cache.php                → purge des caches après mise à jour
  class-naya-rest.php                 → endpoints REST /naya/v1/*
  class-naya-admin.php                → page de réglages
  class-naya-frontend.php             → widget + page dédiée
assets/
  css/naya.css                        → styles (widget, page, responsive, animations)
  js/naya.js                          → logique front (fetch REST, historique, UI)
```

## 🔌 API REST

| Méthode | Route | Description |
|---|---|---|
| `GET` | `/wp-json/naya/v1/nonce` | Délivre un jeton de sécurité frais (indispensable avec un cache de page) |
| `POST` | `/wp-json/naya/v1/chat` | Envoie un message, renvoie la réponse de l'IA |
| `POST` | `/wp-json/naya/v1/event` | Trace un événement d'usage (widget ouvert, clic WhatsApp…) |
| `POST` | `/wp-json/naya/v1/conversations/{id}/rate` | Note la conversation (1-5 étoiles + commentaire) |
| `GET` | `/wp-json/naya/v1/conversations` | Liste les conversations du visiteur |
| `GET` | `/wp-json/naya/v1/conversations/{id}` | Historique d'une conversation |
| `DELETE` | `/wp-json/naya/v1/conversations/{id}` | Supprime une conversation |

## 📄 Licence

GPL-2.0-or-later.

---

Propulsé par **Deejitcorp** · 🤖 Généré avec [Claude Code](https://claude.com/claude-code)
