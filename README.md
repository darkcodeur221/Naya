# Naya — Assistant IA pour WordPress 🤖

Chatbot IA propulsé par **DeepSeek** — par **Deejitcorp**. Naya conseille vos visiteurs, répond à leurs demandes et les oriente, avec une mémoire de conversation persistante.

![Version](https://img.shields.io/badge/version-1.2.0-blueviolet) ![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![Licence](https://img.shields.io/badge/licence-GPL--2.0-green)

## ✨ Fonctionnalités

- **Widget flottant élégant** en bas à droite : bulle animée avec effet de pulsation, fenêtre avec dégradé personnalisable, indicateur de frappe, suggestions rapides cliquables.
- **Page de chat dédiée** (style Alibaba) créée automatiquement à l'activation : plein écran, barre latérale avec l'historique des conversations, création/suppression de conversations.
- **Mémoire de contexte** : chaque conversation est stockée en base de données (`wp_naya_conversations` / `wp_naya_messages`). Les 30 derniers messages sont renvoyés à DeepSeek à chaque tour — Naya se souvient de ce qui a été dit.
- **Visiteurs anonymes ou connectés** : identification par cookie sécurisé (1 an) ou par compte WordPress.
- **Personnalisation complète** depuis l'admin : clé API, modèle (DeepSeek Chat / DeepSeek Reasoner), nom du bot, message d'accueil, prompt système, couleurs, suggestions.
- **Alertes e-mail intelligentes** : l'IA détecte les conversations à forte valeur (prospect, demande de devis ou de contact, réclamation) et vous envoie automatiquement la transcription par e-mail — un seul e-mail par conversation, plafond journalier anti-inondation.
- **Bouclier anti-bots** : champ honeypot invisible, filtrage des user-agents automatisés (curl, python, headless…), contrôle d'origine (Origin/Referer), intervalle minimum entre messages, plafond horaire par IP avec bannissement temporaire d'une heure.
- **Sécurité** : nonces REST, requêtes préparées, vérification de propriété des conversations, limite de débit (20 messages / 5 min / visiteur), garde-fou anti-injection de prompt (l'IA refuse de changer de rôle ou de révéler ses instructions), clé API jamais exposée côté client.

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
| `POST` | `/wp-json/naya/v1/chat` | Envoie un message, renvoie la réponse de l'IA |
| `GET` | `/wp-json/naya/v1/conversations` | Liste les conversations du visiteur |
| `GET` | `/wp-json/naya/v1/conversations/{id}` | Historique d'une conversation |
| `DELETE` | `/wp-json/naya/v1/conversations/{id}` | Supprime une conversation |

## 📄 Licence

GPL-2.0-or-later.

---

Propulsé par **Deejitcorp** · 🤖 Généré avec [Claude Code](https://claude.com/claude-code)
