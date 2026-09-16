/**
 * Naya — Assistant IA
 * Gère le widget flottant et la page dédiée (même moteur, deux modes).
 */
(function () {
	'use strict';

	// Les optimiseurs de scripts réordonnent parfois les balises : ce fichier
	// peut alors s'exécuter avant ses propres données de configuration.
	// Plutôt que d'abandonner, on patiente jusqu'à ce qu'elles arrivent.
	if (typeof window.NAYA === 'undefined') {
		var essais = 0;
		var attente = setInterval(function () {
			if (typeof window.NAYA !== 'undefined') {
				clearInterval(attente);
				demarrer();
			} else if (++essais > 40) { // ~4 s, puis on renonce
				clearInterval(attente);
				if (window.console && console.warn) {
					console.warn('[Naya] Configuration absente : le script du plugin a été chargé sans ses données. Vérifiez les réglages d\'optimisation JavaScript de votre cache.');
				}
			}
		}, 100);
		return;
	}

	demarrer();

	function demarrer() {

	var API = {
		headers: function () {
			return {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NAYA.nonce
			};
		},

		/**
		 * Requête protégée par le jeton de sécurité.
		 *
		 * Avec un cache de page, le jeton inscrit dans le HTML peut être
		 * périmé depuis longtemps. On en redemande alors un frais et on
		 * rejoue la requête une fois — le visiteur ne voit rien.
		 */
		request: function (url, options, dejaRejoue) {
			var self = this;
			options = options || {};
			options.headers = this.headers();
			options.credentials = 'same-origin';

			return fetch(url, options).then(function (res) {
				if (res.status !== 403 || dejaRejoue) {
					return handleResponse(res);
				}

				// Jeton probablement expiré : on en obtient un neuf et on réessaie.
				return fetch(NAYA.restUrl + '/nonce', { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (!data || !data.nonce) return handleResponse(res);
						NAYA.nonce = data.nonce;
						return self.request(url, { method: options.method, body: options.body }, true);
					})
					.catch(function () { return handleResponse(res); });
			});
		},
		chat: function (message, conversationId, honeypot) {
			return this.request(NAYA.restUrl + '/chat', {
				method: 'POST',
				body: JSON.stringify({
					message: message,
					conversation_id: conversationId || 0,
					website: honeypot || ''
				})
			});
		},
		conversations: function () {
			return this.request(NAYA.restUrl + '/conversations', {});
		},
		history: function (id) {
			return this.request(NAYA.restUrl + '/conversations/' + id, {});
		},
		remove: function (id) {
			return this.request(NAYA.restUrl + '/conversations/' + id, { method: 'DELETE' });
		},
		rate: function (id, rating, comment) {
			return this.request(NAYA.restUrl + '/conversations/' + id + '/rate', {
				method: 'POST',
				body: JSON.stringify({ rating: rating, comment: comment || '' })
			});
		},
		track: function (event) {
			// Statistique d'usage — silencieux en cas d'échec.
			this.request(NAYA.restUrl + '/event', {
				method: 'POST',
				body: JSON.stringify({ event: event })
			}).catch(function () {});
		}
	};

	function handleResponse(res) {
		return res.json().then(function (data) {
			if (!res.ok) {
				var err = new Error(data && data.message ? data.message : NAYA.i18n.error);
				err.code = data && data.code ? data.code : '';
				err.status = res.status;
				throw err;
			}
			return data;
		}).catch(function (e) {
			// Réponse illisible (page d'erreur HTML du serveur, pare-feu…) :
			// mieux vaut un message clair qu'un « undefined ».
			if (e instanceof SyntaxError) {
				var err = new Error(NAYA.i18n.error);
				err.status = res.status;
				throw err;
			}
			throw e;
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
		this.endBtn = root.querySelector('.naya-end-btn');
		this.grabber = root.querySelector('.naya-grabber');
		this.sheet = root.querySelector('#naya-window') || root.querySelector('#naya-panel');
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

		// Notation manuelle via l'étoile de l'en-tête.
		var rateBtn = this.root.querySelector('.naya-rate-btn');
		if (rateBtn) {
			rateBtn.addEventListener('click', function () {
				self.showRating(false);
			});
		}

		// Clôture explicite de la conversation.
		if (this.endBtn) {
			this.endBtn.addEventListener('click', function () { self.endConversation(); });
		}

		this.bindSwipe();
	};

	/* ------------------- Clôture de la conversation -------------------- */

	/**
	 * « Terminer » : on remercie, on propose de noter, puis on repart à neuf.
	 * C'est le meilleur moment pour recueillir un avis — le visiteur vient
	 * de décider lui-même que l'échange était fini.
	 */
	Chat.prototype.endConversation = function () {
		var self = this;
		if (!this.conversationId) { this.requestClose(); return; }

		var dejaNote = !!sessionStorage.getItem('naya_rated_' + this.conversationId);

		if (dejaNote) {
			this.farewell();
			return;
		}

		// On affiche la notation, précédée d'un mot de contexte.
		this.suggEl.innerHTML = '';
		this.showRating(false, {
			title: NAYA.i18n.endTitle,
			onDone: function () { self.farewell(); },
			onSkip: function () { self.farewell(); }
		});
		this.toggleEndButton(false);
	};

	/** Message d'au revoir, puis fermeture et remise à zéro. */
	Chat.prototype.farewell = function () {
		var self = this;
		this.suggEl.innerHTML = '';
		this.append('assistant', NAYA.i18n.endDone);
		setTimeout(function () {
			self.resetConversation();
			self.requestClose();
		}, 1400);
	};

	Chat.prototype.resetConversation = function () {
		this.conversationId = 0;
		sessionStorage.removeItem('naya_conv');
		this.ratingInvited = false;
		clearTimeout(this.ratingTimer);
		this.showWelcome();
	};

	/** Demande la fermeture au conteneur (fenêtre, panneau…). */
	Chat.prototype.requestClose = function () {
		if (typeof this.onClose === 'function') this.onClose();
	};

	/* ------------------ Glissement vers le bas (mobile) ---------------- */

	/**
	 * Sur mobile, tirer la poignée vers le bas ferme la conversation — le
	 * geste attendu pour ce type de panneau.
	 */
	Chat.prototype.bindSwipe = function () {
		if (!this.grabber || !this.sheet) return;

		var self = this;
		var startY = 0;
		var delta = 0;
		var dragging = false;

		var onStart = function (e) {
			if (window.innerWidth > 600) return;
			dragging = true;
			delta = 0;
			startY = (e.touches ? e.touches[0].clientY : e.clientY);
			self.sheet.classList.add('naya-dragging');
		};

		var onMove = function (e) {
			if (!dragging) return;
			var y = (e.touches ? e.touches[0].clientY : e.clientY);
			delta = Math.max(0, y - startY);
			self.sheet.style.transform = 'translateY(' + delta + 'px)';
			if (e.cancelable) e.preventDefault();
		};

		var onEnd = function () {
			if (!dragging) return;
			dragging = false;
			self.sheet.classList.remove('naya-dragging');
			self.sheet.style.transform = '';

			// Au-delà d'un quart de la hauteur, le geste vaut fermeture.
			if (delta > Math.min(160, self.sheet.offsetHeight * 0.25)) {
				self.requestClose();
			}
		};

		this.grabber.addEventListener('touchstart', onStart, { passive: true });
		this.grabber.addEventListener('touchmove', onMove, { passive: false });
		this.grabber.addEventListener('touchend', onEnd);
		this.grabber.addEventListener('mousedown', onStart);
		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup', onEnd);

		// Un simple appui sur la poignée ferme aussi.
		this.grabber.addEventListener('click', function () {
			if (delta < 6) self.requestClose();
		});
	};

	/* ---------------------- Notation de l'agent ----------------------- */

	Chat.prototype.armRatingInvite = function () {
		var self = this;
		clearTimeout(this.ratingTimer);
		// Après 60 s sans nouvel échange, on invite discrètement à noter.
		this.ratingTimer = setTimeout(function () {
			self.showRating(true);
		}, 60000);
	};

	Chat.prototype.showRating = function (auto, options) {
		var self = this;
		options = options || {};
		if (!this.conversationId) return;
		if (sessionStorage.getItem('naya_rated_' + this.conversationId)) return;
		if (this.messagesEl.querySelector('.naya-rating')) return;
		if (auto && this.ratingInvited) return;
		this.ratingInvited = true;

		var card = document.createElement('div');
		card.className = 'naya-rating';

		var title = document.createElement('div');
		title.className = 'naya-rating-title';
		title.textContent = options.title || NAYA.i18n.rateTitle;
		card.appendChild(title);

		var starsRow = document.createElement('div');
		starsRow.className = 'naya-stars';
		var value = 0;
		var stars = [];
		for (var i = 1; i <= 5; i++) {
			(function (n) {
				var s = document.createElement('button');
				s.type = 'button';
				s.textContent = '★';
				s.setAttribute('aria-label', n + '/5');
				s.addEventListener('click', function () {
					value = n;
					stars.forEach(function (el, idx) {
						el.classList.toggle('naya-star-on', idx < n);
					});
					form.style.display = 'flex';
				});
				stars.push(s);
				starsRow.appendChild(s);
			})(i);
		}
		card.appendChild(starsRow);

		var form = document.createElement('div');
		form.className = 'naya-rating-form';
		form.style.display = 'none';

		var comment = document.createElement('textarea');
		comment.rows = 2;
		comment.placeholder = NAYA.i18n.ratePlaceholder;
		form.appendChild(comment);

		var send = document.createElement('button');
		send.type = 'button';
		send.textContent = NAYA.i18n.rateSend;
		send.addEventListener('click', function () {
			if (!value) return;
			send.disabled = true;
			API.rate(self.conversationId, value, comment.value.trim())
				.then(function () {
					sessionStorage.setItem('naya_rated_' + self.conversationId, '1');
					card.innerHTML = '';
					var thanks = document.createElement('div');
					thanks.className = 'naya-rating-title';
					thanks.textContent = NAYA.i18n.rateThanks;
					card.appendChild(thanks);
					if (options.onDone) {
						setTimeout(function () { card.remove(); options.onDone(); }, 900);
					} else {
						setTimeout(function () { card.remove(); }, 4000);
					}
				})
				.catch(function () {
					send.disabled = false;
				});
		});
		form.appendChild(send);
		card.appendChild(form);

		// À la clôture, on laisse toujours une porte de sortie sans noter.
		if (options.onSkip) {
			var skip = document.createElement('button');
			skip.type = 'button';
			skip.className = 'naya-rating-skip';
			skip.textContent = NAYA.i18n.endSkip;
			skip.addEventListener('click', function () {
				card.remove();
				options.onSkip();
			});
			card.appendChild(skip);
		}

		this.messagesEl.appendChild(card);
		this.scroll();
	};

	Chat.prototype.showWelcome = function () {
		this.messagesEl.innerHTML = '';
		if (NAYA.welcome) {
			this.append('assistant', NAYA.welcome);
		}
		// Une ligne d'invitation lève l'hésitation du « par où commencer ».
		if ((NAYA.sugg || []).length && NAYA.i18n.startHint) {
			var hint = document.createElement('div');
			hint.className = 'naya-start-hint';
			hint.textContent = NAYA.i18n.startHint;
			this.messagesEl.appendChild(hint);
		}
		this.renderSuggestions();
		this.toggleEndButton(false);
	};

	/** Le bouton « Terminer » n'a de sens qu'une fois l'échange engagé. */
	Chat.prototype.toggleEndButton = function (show) {
		if (!this.endBtn) return;
		this.endBtn.classList.toggle('naya-hidden', !show);
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
		var text = this.input.value.trim();
		if (!text) return;
		this.input.value = '';
		this.input.style.height = 'auto';
		this.sendText(text);
	};

	/**
	 * Envoi effectif. Séparé de send() pour que la barre du haut puisse
	 * expédier son propre champ de saisie.
	 *
	 * @param {string} text     Message du visiteur.
	 * @param {string} honeypot Valeur du champ piège, si l'appelant en a un.
	 */
	Chat.prototype.sendText = function (text, honeypot) {
		var self = this;
		text = (text || '').trim();
		if (!text || this.busy) return;

		this.busy = true;
		this.sendBtn.disabled = true;
		this.suggEl.innerHTML = '';

		this.append('user', text);
		this.typing(true);

		var hp = this.form.querySelector('.naya-hp');

		API.chat(text, this.conversationId, honeypot || (hp ? hp.value : ''))
			.then(function (data) {
				self.typing(false);
				self.conversationId = data.conversation_id;
				sessionStorage.setItem('naya_conv', String(data.conversation_id));
				self.append('assistant', data.reply);
				self.armRatingInvite();
				self.toggleEndButton(true);
				if (self.onReply) self.onReply();
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
				self.toggleEndButton(messages.length > 0);
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

	/* --------------------- Barre du haut (mode « bar ») ----------------- */

	/**
	 * Pilote la barre de conversation : champ de saisie toujours visible,
	 * panneau qui se déploie, et réduction en onglet.
	 */
	function Bar(widget, chat) {
		this.widget = widget;
		this.chat = chat;
		this.panel = document.getElementById('naya-panel');
		this.tab = document.getElementById('naya-tab');
		this.toggle = widget.querySelector('.naya-bar-toggle');
		this.form = widget.querySelector('.naya-bar-form');
		this.input = widget.querySelector('.naya-bar-input');
		this.open = false;
		this.unread = 0;

		this.bind();

		// Une barre réduite le reste le temps de la navigation.
		if (sessionStorage.getItem('naya_bar_minimized')) {
			this.minimize(true);
		}
	}

	Bar.prototype.bind = function () {
		var self = this;

		// Envoi depuis la barre : on ouvre le panneau et on transmet le message.
		this.form.addEventListener('submit', function (e) {
			e.preventDefault();
			var text = self.input.value.trim();
			if (!text) { self.openPanel(); return; }
			var hp = self.form.querySelector('.naya-hp');
			self.input.value = '';
			self.openPanel();
			self.chat.sendText(text, hp ? hp.value : '');
		});

		// Entrée envoie, sans dépendre de la soumission implicite du navigateur
		// (le formulaire contient aussi le champ piège anti-robots).
		this.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				self.form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
			}
		});

		// Cliquer dans le champ donne déjà envie d'écrire : on déploie.
		this.input.addEventListener('focus', function () { self.openPanel(); });

		this.toggle.addEventListener('click', function () {
			self.open ? self.closePanel() : self.openPanel();
		});

		var minimize = this.widget.querySelector('.naya-bar-minimize');
		if (minimize) {
			minimize.addEventListener('click', function () { self.minimize(); });
		}

		if (this.tab) {
			this.tab.addEventListener('click', function () { self.restore(); });
		}

		var close = this.panel.querySelector('.naya-panel-close');
		if (close) {
			close.addEventListener('click', function () { self.closePanel(); });
		}

		// Échap referme le panneau sans fermer la barre.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && self.open) self.closePanel();
		});

		// Un clic à l'extérieur referme le panneau, sauf si on écrit dedans.
		document.addEventListener('click', function (e) {
			if (!self.open) return;
			if (self.panel.contains(e.target) || self.widget.querySelector('#naya-bar').contains(e.target)) return;
			self.closePanel();
		});
	};

	Bar.prototype.openPanel = function () {
		if (this.open) return;
		this.open = true;
		this.unread = 0;
		this.renderCount();
		this.widget.classList.add('naya-panel-open');
		this.panel.classList.remove('naya-hidden', 'naya-panel-out');
		this.toggle.setAttribute('aria-expanded', 'true');
		this.chat.scroll();
	};

	Bar.prototype.closePanel = function () {
		if (!this.open) return;
		var self = this;
		this.open = false;
		this.widget.classList.remove('naya-panel-open');
		this.toggle.setAttribute('aria-expanded', 'false');
		this.panel.classList.add('naya-panel-out');
		setTimeout(function () {
			self.panel.classList.add('naya-hidden');
			self.panel.classList.remove('naya-panel-out');
		}, 220);
	};

	Bar.prototype.minimize = function (silent) {
		this.closePanel();
		this.widget.classList.add('naya-minimized');
		document.documentElement.classList.add('naya-bar-minimized');
		if (this.tab) this.tab.classList.remove('naya-hidden');
		if (!silent) sessionStorage.setItem('naya_bar_minimized', '1');
	};

	Bar.prototype.restore = function () {
		this.widget.classList.remove('naya-minimized');
		document.documentElement.classList.remove('naya-bar-minimized');
		if (this.tab) this.tab.classList.add('naya-hidden');
		sessionStorage.removeItem('naya_bar_minimized');
		this.openPanel();
	};

	/** Signale une réponse reçue alors que le panneau est fermé. */
	Bar.prototype.notifyReply = function () {
		if (this.open) return;
		this.unread++;
		this.renderCount();
	};

	Bar.prototype.renderCount = function () {
		var badge = this.toggle.querySelector('.naya-bar-count');
		if (!this.unread) {
			if (badge) badge.remove();
			return;
		}
		if (!badge) {
			badge = document.createElement('span');
			badge.className = 'naya-bar-count';
			this.toggle.appendChild(badge);
		}
		badge.textContent = this.unread > 9 ? '9+' : String(this.unread);
	};

	/* ------------------- Incitation à la conversation ------------------ */

	/**
	 * Incitation à la conversation : bulle d'accroche, badge et frétillement
	 * de la bulle. Se déclenche au premier signal d'intérêt (temps passé,
	 * défilement, intention de sortie) et ne s'impose jamais deux fois.
	 */
	function Nudge(widget, openChat) {
		this.widget = widget;
		this.openChat = openChat;
		this.launcher = document.getElementById('naya-launcher');
		this.teaser = document.getElementById('naya-teaser');
		this.done = !!sessionStorage.getItem('naya_nudge_done');
		this.shown = false;

		var cfg = NAYA.teaser || {};
		if (!cfg.enabled || this.done) return;

		this.arm(cfg.delay || 8000);
		this.startWiggle();
	}

	Nudge.prototype.arm = function (delay) {
		var self = this;
		var fire = function () { self.show(); };

		// 1. Temps passé sur la page.
		this.timer = setTimeout(fire, delay);

		// 2. Défilement au-delà de 45 % : le visiteur explore vraiment.
		this.onScroll = function () {
			var h = document.documentElement.scrollHeight - window.innerHeight;
			if (h > 0 && window.scrollY / h > 0.45) fire();
		};
		window.addEventListener('scroll', this.onScroll, { passive: true });

		// 3. Intention de sortie : la souris quitte la page par le haut.
		this.onLeave = function (e) {
			if (e.clientY <= 0) fire();
		};
		document.addEventListener('mouseout', this.onLeave);
	};

	Nudge.prototype.disarm = function () {
		clearTimeout(this.timer);
		window.removeEventListener('scroll', this.onScroll);
		document.removeEventListener('mouseout', this.onLeave);
	};

	Nudge.prototype.show = function () {
		if (this.shown || this.done) return;
		if (this.widget.classList.contains('naya-open')) return;
		this.shown = true;
		this.disarm();

		var self = this;
		this.widget.classList.add('naya-nudged');

		if (this.teaser) {
			this.teaser.classList.remove('naya-hidden');

			var open = function () {
				self.dismiss(true);
				self.openChat();
				API.track('teaser_click');
			};
			this.teaser.addEventListener('click', open);
			this.teaser.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
			});
			this.teaser.querySelector('.naya-teaser-close').addEventListener('click', function (e) {
				e.stopPropagation();
				self.dismiss(true);
			});

			// L'accroche se retire d'elle-même après 20 s, sans insister.
			this.autoHide = setTimeout(function () { self.dismiss(false); }, 20000);
		}

		API.track('teaser_shown');
	};

	/**
	 * @param {boolean} definitive Vrai si le visiteur a agi (clic ou fermeture) :
	 *                             on ne le relance plus de la session.
	 */
	Nudge.prototype.dismiss = function (definitive) {
		clearTimeout(this.autoHide);
		if (this.teaser && !this.teaser.classList.contains('naya-hidden')) {
			var t = this.teaser;
			t.classList.add('naya-teaser-out');
			setTimeout(function () {
				t.classList.add('naya-hidden');
				t.classList.remove('naya-teaser-out');
			}, 280);
		}
		if (definitive) {
			this.done = true;
			this.stopWiggle();
			this.widget.classList.remove('naya-nudged');
			sessionStorage.setItem('naya_nudge_done', '1');
		}
	};

	Nudge.prototype.startWiggle = function () {
		var self = this;
		this.wiggleTimer = setInterval(function () {
			if (self.done || self.widget.classList.contains('naya-open')) return;
			self.launcher.classList.add('naya-wiggle');
			setTimeout(function () { self.launcher.classList.remove('naya-wiggle'); }, 900);
		}, 14000);
	};

	Nudge.prototype.stopWiggle = function () {
		clearInterval(this.wiggleTimer);
	};

	/* ------------------------- Initialisation ------------------------- */

	/**
	 * Vérifie que la feuille de styles du plugin est bien celle de cette
	 * version. Un cache qui sert un fichier périmé casse la mise en page —
	 * autant le dire clairement plutôt que de laisser chercher.
	 */
	function checkStylesheet(el) {
		if (!el) return;
		var marque = getComputedStyle(el).getPropertyValue('--naya-css');
		if (marque && marque.trim()) return;

		if (window.console && console.warn) {
			console.warn(
				'[Naya] La feuille de styles du plugin n\'est pas chargée ou provient d\'une version antérieure. ' +
				'Purgez le cache de votre site, y compris les fichiers CSS/JS combinés ' +
				'(LiteSpeed : Boîte à outils → Purger tout).'
			);
		}
	}

	function initialiser() {
		var widget = document.getElementById('naya-widget');
		checkStylesheet(widget || document.getElementById('naya-page'));

		// Présentation en barre : pas de bulle flottante ni d'accroche.
		if (widget && widget.classList.contains('naya-mode-bar')) {
			var barChat = new Chat(widget, 'widget');
			var bar = new Bar(widget, barChat);
			barChat.onReply = function () { bar.notifyReply(); };
			barChat.onClose = function () { bar.closePanel(); };

			var pageEl = document.getElementById('naya-page');
			if (pageEl) new Chat(pageEl, 'page');
			return;
		}

		if (widget) {
			var chat = new Chat(widget, 'widget');
			var launcher = document.getElementById('naya-launcher');
			var win = document.getElementById('naya-window');

			var openChat = function () {
				widget.classList.add('naya-open');
				win.classList.remove('naya-hidden');
				chat.input.focus();
				// Une ouverture comptée par session de navigation.
				if (!sessionStorage.getItem('naya_opened')) {
					sessionStorage.setItem('naya_opened', '1');
					API.track('widget_open');
				}
			};

			var nudge = new Nudge(widget, openChat);

			launcher.addEventListener('click', function () {
				nudge.dismiss(true);
				openChat();
			});
			var closeWindow = function () {
				widget.classList.remove('naya-open');
				win.classList.add('naya-hidden');
			};

			win.querySelector('.naya-close').addEventListener('click', closeWindow);
			chat.onClose = closeWindow;
		}

		var page = document.getElementById('naya-page');
		if (page) {
			new Chat(page, 'page');
		}
	}

	/**
	 * Si le script arrive après coup — chargement différé, exécution retardée
	 * par un optimiseur — l'événement de fin de chargement est déjà passé :
	 * on démarre alors immédiatement.
	 */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialiser);
	} else {
		initialiser();
	}

	} // fin de demarrer()
})();
