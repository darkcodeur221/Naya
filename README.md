# Naya — Assistant IA pour WordPress 🤖

Chatbot IA propulsé par **Claude (Anthropic)** pour conseiller vos visiteurs, répondre à leurs demandes et les orienter — avec une mémoire de conversation persistante.

![Version](https://img.shields.io/badge/version-1.0.0-blueviolet) ![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![Licence](https://img.shields.io/badge/licence-GPL--2.0-green)

## ✨ Fonctionnalités

- **Widget flottant élégant** en bas à droite : bulle animée avec effet de pulsation, fenêtre avec dégradé personnalisable, indicateur de frappe, suggestions rapides cliquables.
- **Page de chat dédiée** (style Alibaba) créée automatiquement à l'activation : plein écran, barre latérale avec l'historique des conversations, création/suppression de conversations.
- **Mémoire de contexte** : chaque conversation est stockée en base de données (`wp_naya_conversations` / `wp_naya_messages`). Les 30 derniers messages sont renvoyés à Claude à chaque tour — Naya se souvient de ce qui a été dit.
- **Visiteurs anonymes ou connectés** : identification par cookie sécurisé (1 an) ou par compte WordPress.
- **Personnalisation complète** depuis l'admin : clé API, modèle (Opus 4.8 / Sonnet 5 / Haiku 4.5), nom du bot, message d'accueil, prompt système, couleurs, suggestions.
- **Sécurité** : nonces REST, requêtes préparées, vérification de propriété des conversations, limite de débit (20 messages / 5 min / visiteur), clé API jamais exposée côté client.

## 🚀 Installation

1. Téléchargez le dossier `Naya` (ou clonez ce dépôt) dans `wp-content/plugins/`.
2. Activez **Naya — Assistant IA** dans *Extensions*.
3. Allez dans **Réglages → Naya** et collez votre clé API Anthropic ([platform.claude.com](https://platform.claude.com/)).
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
  class-naya-claude.php               → client API Claude (wp_remote_post)
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
| `POST` | `/wp-json/naya/v1/chat` | Envoie un message, renvoie la réponse de Claude |
| `GET` | `/wp-json/naya/v1/conversations` | Liste les conversations du visiteur |
| `GET` | `/wp-json/naya/v1/conversations/{id}` | Historique d'une conversation |
| `DELETE` | `/wp-json/naya/v1/conversations/{id}` | Supprime une conversation |

## 📄 Licence

GPL-2.0-or-later.

---

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
