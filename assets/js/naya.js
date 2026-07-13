/**
 * Naya — Assistant IA
 * Gère le widget flottant et la page dédiée (même moteur, deux modes).
 */
(function () {
	'use strict';

	if (typeof NAYA === 'undefined') return;

	var API = {
		headers: function () {
			return {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NAYA.nonce
			};
		},
		chat: function (message, conversationId, honeypot) {
			return fetch(NAYA.restUrl + '/chat', {
				method: 'POST',
				headers: this.headers(),
				credentials: 'same-origin',
				body: JSON.stringify({
					message: message,
					conversation_id: conversationId || 0,
					website: honeypot || ''
				})
			}).then(handleJson);
		},
		conversations: function () {
			return fetch(NAYA.restUrl + '/conversations', {
				headers: this.headers(),
				credentials: 'same-origin'
			}).then(handleJson);
		},
		history: function (id) {
			return fetch(NAYA.restUrl + '/conversations/' + id, {
				headers: this.headers(),
				credentials: 'same-origin'
			}).then(handleJson);
		},
		remove: function (id) {
			return fetch(NAYA.restUrl + '/conversations/' + id, {
				method: 'DELETE',
				headers: this.headers(),
				credentials: 'same-origin'
			}).then(handleJson);
		},
		track: function (event) {
			// Statistique d'usage — silencieux en cas d'échec.
			fetch(NAYA.restUrl + '/event', {
				method: 'POST',
				headers: this.headers(),
				credentials: 'same-origin',
				body: JSON.stringify({ event: event })
			}).catch(function () {});
		}
	};

	function handleJson(res) {
		return res.json().then(function (data) {
			if (!res.ok) {
				throw new Error(data && data.message ? data.message : NAYA.i18n.error);
			}
			return data;
		});
	}

	/**
	 * Mini-rendu sécurisé des réponses de l'IA :
	 * tout est échappé, puis seuls les liens [texte](url), les URLs http(s)
	 * et le **gras** sont convertis en HTML.
	 */
	function renderRich(text) {
		var esc = String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');

		// Liens markdown [texte](https://…)
		esc = esc.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
			'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

		// URLs nues (précédées d'un espace, d'une parenthèse ou en début de texte)
		esc = esc.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g,
			'$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>');

		// **gras**
		esc = esc.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

		return esc;
	}

	/* ------------------------------------------------------------------ */

	function Chat(root, mode) {
		this.root = root;
		this.mode = mode; // 'widget' | 'page'
		this.messagesEl = root.querySelector('.naya-messages');
		this.suggEl = root.querySelector('.naya-suggestions');
		this.form = root.querySelector('.naya-input-bar');
		this.input = this.form.querySelector('textarea');
		this.sendBtn = this.form.querySelector('button[type="submit"]');
		this.conversationId = parseInt(sessionStorage.getItem('naya_conv') || '0', 10) || 0;
		this.busy = false;

		this.bind();

		if (this.conversationId) {
			this.loadHistory(this.conversationId);
		} else {
			this.showWelcome();
		}

		if (mode === 'page') {
			this.sidebar();
		}
	}

	Chat.prototype.bind = function () {
		var self = this;

		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.send();
		});

		this.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				self.send();
			}
		});

		// Auto-resize du textarea
		this.input.addEventListener('input', function () {
			self.input.style.height = 'auto';
			self.input.style.height = Math.min(self.input.scrollHeight, 120) + 'px';
		});

		// Statistiques : clics sur les liens proposés par l'IA (WhatsApp ou autre).
		this.messagesEl.addEventListener('click', function (e) {
			var a = e.target.closest ? e.target.closest('a') : null;
			if (!a) return;
			API.track(a.href.indexOf('wa.me') !== -1 ? 'whatsapp_click' : 'link_click');
		});
	};

	Chat.prototype.showWelcome = function () {
		this.messagesEl.innerHTML = '';
		if (NAYA.welcome) {
			this.append('assistant', NAYA.welcome);
		}
		this.renderSuggestions();
	};

	Chat.prototype.renderSuggestions = function () {
		var self = this;
		this.suggEl.innerHTML = '';
		(NAYA.sugg || []).forEach(function (text) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'naya-sugg';
			b.textContent = text;
			b.addEventListener('click', function () {
				self.input.value = text;
				self.send();
			});
			self.suggEl.appendChild(b);
		});
	};

	Chat.prototype.append = function (role, content) {
		var div = document.createElement('div');
		div.className = 'naya-msg naya-msg-' + role;
		if (role === 'assistant') {
			// Contenu échappé puis enrichi (liens cliquables, gras).
			div.innerHTML = renderRich(content);
		} else {
			div.textContent = content;
		}
		this.messagesEl.appendChild(div);
		this.scroll();
		return div;
	};

	Chat.prototype.typing = function (show) {
		var t = this.messagesEl.querySelector('.naya-typing');
		if (show && !t) {
			t = document.createElement('div');
			t.className = 'naya-typing';
			t.setAttribute('aria-label', NAYA.i18n.thinking);
			t.innerHTML = '<span></span><span></span><span></span>';
			this.messagesEl.appendChild(t);
			this.scroll();
		} else if (!show && t) {
			t.remove();
		}
	};

	Chat.prototype.scroll = function () {
		this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
	};

	Chat.prototype.send = function () {
		var self = this;
		var text = this.input.value.trim();
		if (!text || this.busy) return;

		this.busy = true;
		this.sendBtn.disabled = true;
		this.input.value = '';
		this.input.style.height = 'auto';
		this.suggEl.innerHTML = '';

		this.append('user', text);
		this.typing(true);

		var hp = this.form.querySelector('.naya-hp');

		API.chat(text, this.conversationId, hp ? hp.value : '')
			.then(function (data) {
				self.typing(false);
				self.conversationId = data.conversation_id;
				sessionStorage.setItem('naya_conv', String(data.conversation_id));
				self.append('assistant', data.reply);
				if (self.mode === 'page') self.refreshList();
			})
			.catch(function (err) {
				self.typing(false);
				self.append('error', err.message || NAYA.i18n.error);
			})
			.finally(function () {
				self.busy = false;
				self.sendBtn.disabled = false;
				self.input.focus();
			});
	};

	Chat.prototype.loadHistory = function (id) {
		var self = this;
		API.history(id)
			.then(function (messages) {
				self.conversationId = id;
				sessionStorage.setItem('naya_conv', String(id));
				self.messagesEl.innerHTML = '';
				if (!messages.length && NAYA.welcome) {
					self.append('assistant', NAYA.welcome);
				}
				messages.forEach(function (m) {
					if (m.role === 'user' || m.role === 'assistant') {
						self.append(m.role, m.content);
					}
				});
				if (self.mode === 'page') self.refreshList();
			})
			.catch(function () {
				// Conversation inaccessible (cookie changé…) : repartir de zéro.
				self.conversationId = 0;
				sessionStorage.removeItem('naya_conv');
				self.showWelcome();
			});
	};

	Chat.prototype.newConversation = function () {
		this.conversationId = 0;
		sessionStorage.removeItem('naya_conv');
		this.showWelcome();
		this.refreshList();
		this.input.focus();
	};

	/* ------------------ Page dédiée : barre latérale ------------------ */

	Chat.prototype.sidebar = function () {
		var self = this;
		this.listEl = this.root.querySelector('.naya-conv-list');

		this.root.querySelector('.naya-new-chat').addEventListener('click', function () {
			self.newConversation();
			self.root.classList.remove('naya-sidebar-open');
		});

		var toggle = this.root.querySelector('.naya-toggle-sidebar');
		if (toggle) {
			toggle.addEventListener('click', function () {
				self.root.classList.toggle('naya-sidebar-open');
			});
		}

		this.refreshList();
	};

	Chat.prototype.refreshList = function () {
		var self = this;
		if (!this.listEl) return;

		API.conversations().then(function (list) {
			self.listEl.innerHTML = '';
			if (!list.length) {
				var empty = document.createElement('div');
				empty.className = 'naya-conv-empty';
				empty.textContent = NAYA.i18n.emptyList;
				self.listEl.appendChild(empty);
				return;
			}
			list.forEach(function (conv) {
				var item = document.createElement('div');
				item.className = 'naya-conv-item' + (conv.id === self.conversationId ? ' naya-active' : '');

				var title = document.createElement('span');
				title.className = 'naya-conv-title';
				title.textContent = conv.title;

				var del = document.createElement('button');
				del.className = 'naya-conv-del';
				del.textContent = '🗑';
				del.addEventListener('click', function (e) {
					e.stopPropagation();
					if (!window.confirm(NAYA.i18n.deleteConf)) return;
					API.remove(conv.id).then(function () {
						if (conv.id === self.conversationId) {
							self.newConversation();
						} else {
							self.refreshList();
						}
					});
				});

				item.appendChild(title);
				item.appendChild(del);
				item.addEventListener('click', function () {
					self.loadHistory(conv.id);
					self.root.classList.remove('naya-sidebar-open');
				});
				self.listEl.appendChild(item);
			});
		}).catch(function () { /* silencieux */ });
	};

	/* ------------------------- Initialisation ------------------------- */

	document.addEventListener('DOMContentLoaded', function () {
		var widget = document.getElementById('naya-widget');
		if (widget) {
			var chat = new Chat(widget, 'widget');
			var launcher = document.getElementById('naya-launcher');
			var win = document.getElementById('naya-window');

			launcher.addEventListener('click', function () {
				widget.classList.add('naya-open');
				win.classList.remove('naya-hidden');
				chat.input.focus();
				// Une ouverture comptée par session de navigation.
				if (!sessionStorage.getItem('naya_opened')) {
					sessionStorage.setItem('naya_opened', '1');
					API.track('widget_open');
				}
			});
			win.querySelector('.naya-close').addEventListener('click', function () {
				widget.classList.remove('naya-open');
				win.classList.add('naya-hidden');
			});
		}

		var page = document.getElementById('naya-page');
		if (page) {
			new Chat(page, 'page');
		}
	});
})();
