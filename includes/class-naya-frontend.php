<?php
/**
 * Front-end : widget flottant + page dédiée (shortcode [naya_chat]).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_widget' ) );
		add_shortcode( 'naya_chat', array( __CLASS__, 'shortcode_page' ) );
	}

	private static function settings() {
		return wp_parse_args( get_option( 'naya_settings', array() ), array(
			'bot_name' => 'Naya', 'welcome_message' => '', 'primary_color' => '#6d28d9',
			'secondary_color' => '#db2777', 'widget_enabled' => 1, 'suggestions' => '',
			'teaser_enabled' => 1, 'teaser_delay' => 8,
			'teaser_message' => __( 'Une question ? Je vous réponds tout de suite 👋', 'naya' ),
			'widget_position' => 'bar', 'bar_offset' => 1,
			'bar_tagline' => __( 'Conseillère en ligne', 'naya' ),
			'bar_placeholder' => __( 'Posez votre question, je réponds en direct…', 'naya' ),
		) );
	}

	private static function is_chat_page() {
		return is_page( (int) get_option( 'naya_chat_page_id' ) ) ||
			( is_singular() && has_shortcode( (string) get_post_field( 'post_content' ), 'naya_chat' ) );
	}

	public static function assets() {
		$s = self::settings();

		if ( ! $s['widget_enabled'] && ! self::is_chat_page() ) {
			return;
		}

		wp_enqueue_style( 'naya', NAYA_PLUGIN_URL . 'assets/css/naya.css', array(), NAYA_VERSION );
		wp_enqueue_script( 'naya', NAYA_PLUGIN_URL . 'assets/js/naya.js', array(), NAYA_VERSION, true );

		$suggestions = array_values( array_filter( array_map( 'trim', explode( "\n", $s['suggestions'] ) ) ) );

		wp_localize_script( 'naya', 'NAYA', array(
			'restUrl'  => esc_url_raw( rest_url( 'naya/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'botName'  => $s['bot_name'],
			'welcome'  => $s['welcome_message'],
			'sugg'     => $suggestions,
			'pageUrl'  => get_permalink( (int) get_option( 'naya_chat_page_id' ) ),
			'teaser'   => array(
				'enabled' => (int) $s['teaser_enabled'],
				'delay'   => max( 1, (int) $s['teaser_delay'] ) * 1000,
			),
			'position' => $s['widget_position'],
			'i18n'     => array(
				'placeholder' => __( 'Écrivez votre message…', 'naya' ),
				'error'       => __( 'Oups, une erreur est survenue. Réessayez.', 'naya' ),
				'newChat'     => __( 'Nouvelle conversation', 'naya' ),
				'online'      => __( 'En ligne', 'naya' ),
				'thinking'    => __( 'Naya réfléchit…', 'naya' ),
				'deleteConf'  => __( 'Supprimer cette conversation ?', 'naya' ),
				'emptyList'   => __( 'Aucune conversation pour le moment.', 'naya' ),
				'rateTitle'   => __( 'Cette conversation vous a-t-elle aidé ?', 'naya' ),
				'ratePlaceholder' => __( 'Un commentaire ? (facultatif)', 'naya' ),
				'rateSend'    => __( 'Envoyer mon avis', 'naya' ),
				'rateThanks'  => __( 'Merci pour votre avis ! 💜', 'naya' ),
				'endConfirm'  => __( 'Terminer cette conversation ?', 'naya' ),
				'endTitle'    => __( 'Avant de partir…', 'naya' ),
				'endSkip'     => __( 'Fermer sans noter', 'naya' ),
				'endDone'     => __( 'À très vite ! 👋', 'naya' ),
				'startHint'   => __( 'Choisissez une question ou écrivez la vôtre', 'naya' ),
			),
		) );

		$css = sprintf(
			':root{--naya-c1:%1$s;--naya-c2:%2$s;}',
			esc_html( $s['primary_color'] ),
			esc_html( $s['secondary_color'] )
		);

		// Filet de sécurité : ce CSS est généré à chaque page par PHP, donc
		// toujours synchrone avec la version installée. Si une extension de
		// cache sert un fichier .css périmé, ces quelques règles suffisent à
		// garder le widget en place et les éléments masqués invisibles —
		// au lieu de le voir se déverser en bas de page.
		$css .= '#naya-bar{position:fixed;top:0;left:0;right:0;z-index:99997;}'
			. '#naya-panel{position:fixed;z-index:99996;}'
			. '#naya-window{position:fixed;right:24px;bottom:24px;z-index:99999;}'
			. '#naya-launcher{position:fixed;right:24px;bottom:24px;z-index:99998;}'
			. '#naya-teaser{position:fixed;z-index:99998;}'
			. '#naya-tab{position:fixed;top:0;right:24px;z-index:99997;}'
			. '#naya-window.naya-hidden,#naya-panel.naya-hidden,#naya-teaser.naya-hidden,'
			. '#naya-tab.naya-hidden,.naya-end-btn.naya-hidden{display:none !important;}';

		// La barre occupe le haut de l'écran : on décale le site comme le fait
		// la barre d'administration de WordPress, pour ne rien recouvrir.
		if ( 'bar' === $s['widget_position'] && ! empty( $s['bar_offset'] ) && ! self::is_chat_page() ) {
			$css .= 'html{margin-top:58px !important;}'
				. '@media screen and (max-width:782px){html{margin-top:52px !important;}}'
				. 'html.naya-bar-minimized{margin-top:0 !important;}';
		}

		wp_add_inline_style( 'naya', $css );
	}

	/**
	 * Widget global. Deux présentations possibles :
	 *  - « bar »    : barre d'appel à la conversation en haut de page (défaut),
	 *                 avec un champ de saisie visible et un panneau qui se déploie ;
	 *  - « bubble » : bulle flottante classique en bas à droite.
	 */
	public static function render_widget() {
		$s = self::settings();
		if ( ! $s['widget_enabled'] || self::is_chat_page() ) {
			return;
		}

		if ( 'bar' === $s['widget_position'] ) {
			self::render_bar( $s );
			return;
		}
		?>
		<div id="naya-widget" data-naya-mode="widget">
			<?php if ( ! empty( $s['teaser_enabled'] ) && ! empty( $s['teaser_message'] ) ) : ?>
				<div id="naya-teaser" class="naya-hidden" role="button" tabindex="0">
					<div class="naya-teaser-avatar" aria-hidden="true">✦</div>
					<div class="naya-teaser-body">
						<strong><?php echo esc_html( $s['bot_name'] ); ?></strong>
						<p><?php echo esc_html( $s['teaser_message'] ); ?></p>
					</div>
					<button type="button" class="naya-teaser-close" aria-label="<?php esc_attr_e( 'Masquer', 'naya' ); ?>">✕</button>
				</div>
			<?php endif; ?>

			<button id="naya-launcher" aria-label="<?php esc_attr_e( 'Ouvrir le chat', 'naya' ); ?>">
				<span class="naya-launcher-icon">
					<svg viewBox="0 0 24 24" fill="none" width="28" height="28"><path d="M12 3C7 3 3 6.6 3 11c0 2.2 1 4.2 2.7 5.6-.1 1-.5 2.1-1.4 3.1-.2.2 0 .6.3.5 1.7-.2 3.1-.8 4.1-1.5 1 .3 2.1.4 3.3.4 5 0 9-3.6 9-8S17 3 12 3z" fill="currentColor"/><circle cx="8.5" cy="11" r="1.2" fill="#fff"/><circle cx="12" cy="11" r="1.2" fill="#fff"/><circle cx="15.5" cy="11" r="1.2" fill="#fff"/></svg>
				</span>
				<span class="naya-launcher-pulse"></span>
				<span class="naya-badge" aria-hidden="true">1</span>
			</button>

			<div id="naya-window" class="naya-hidden" role="dialog" aria-label="<?php echo esc_attr( $s['bot_name'] ); ?>">
				<button type="button" class="naya-grabber" aria-label="<?php esc_attr_e( 'Fermer en glissant vers le bas', 'naya' ); ?>"></button>
				<div class="naya-header">
					<div class="naya-avatar">✦</div>
					<div class="naya-header-meta">
						<strong><?php echo esc_html( $s['bot_name'] ); ?></strong>
						<span class="naya-status"><span class="naya-dot"></span><?php esc_html_e( 'En ligne', 'naya' ); ?></span>
					</div>
					<?php self::end_button(); ?>
					<button class="naya-rate-btn" title="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>" aria-label="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>">★</button>
					<a class="naya-expand" href="<?php echo esc_url( get_permalink( (int) get_option( 'naya_chat_page_id' ) ) ); ?>" title="<?php esc_attr_e( 'Ouvrir en plein écran', 'naya' ); ?>">⛶</a>
					<button class="naya-close" aria-label="<?php esc_attr_e( 'Réduire', 'naya' ); ?>">✕</button>
				</div>
				<div class="naya-messages" aria-live="polite"></div>
				<div class="naya-suggestions"></div>
				<form class="naya-input-bar">
					<input type="text" name="website" class="naya-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
					<textarea rows="1" placeholder="<?php esc_attr_e( 'Écrivez votre message…', 'naya' ); ?>"></textarea>
					<button type="submit" aria-label="<?php esc_attr_e( 'Envoyer', 'naya' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M4 12l16-8-6 16-2.5-6.5L4 12z" fill="currentColor"/></svg>
					</button>
				</form>
				<div class="naya-footer-brand">Propulsé par <strong>Deejitcorp</strong></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Bouton de clôture de conversation. Volontairement rouge et distinct de
	 * la simple réduction : ici le visiteur dit « j'ai terminé », ce qui est
	 * le meilleur moment pour lui demander son avis.
	 */
	private static function end_button() {
		?>
		<button type="button" class="naya-end-btn naya-hidden"
			aria-label="<?php esc_attr_e( 'Terminer la conversation', 'naya' ); ?>">
			<svg viewBox="0 0 24 24" width="15" height="15" fill="none" aria-hidden="true">
				<path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
			</svg>
			<span class="naya-end-label"><?php esc_html_e( 'Terminer', 'naya' ); ?></span>
		</button>
		<?php
	}

	/**
	 * Barre de conversation en haut de page : le champ de saisie est visible
	 * en permanence — c'est ce qui déclenche le plus d'échanges — et le
	 * panneau de conversation se déploie juste en dessous.
	 */
	private static function render_bar( $s ) {
		$page_url = get_permalink( (int) get_option( 'naya_chat_page_id' ) );
		?>
		<div id="naya-widget" class="naya-mode-bar" data-naya-mode="widget">

			<div id="naya-bar" role="region" aria-label="<?php echo esc_attr( $s['bot_name'] ); ?>">
				<div class="naya-bar-inner">
					<div class="naya-bar-identity">
						<span class="naya-bar-avatar" aria-hidden="true">✦</span>
						<span class="naya-bar-labels">
							<strong><?php echo esc_html( $s['bot_name'] ); ?></strong>
							<span class="naya-bar-status"><span class="naya-dot"></span><?php echo esc_html( $s['bar_tagline'] ); ?></span>
						</span>
					</div>

					<form class="naya-bar-form">
						<input type="text" name="website" class="naya-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
						<input type="text" class="naya-bar-input" autocomplete="off"
							placeholder="<?php echo esc_attr( $s['bar_placeholder'] ); ?>"
							aria-label="<?php esc_attr_e( 'Votre message', 'naya' ); ?>" />
						<button type="submit" class="naya-bar-send" aria-label="<?php esc_attr_e( 'Envoyer', 'naya' ); ?>">
							<svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M4 12l16-8-6 16-2.5-6.5L4 12z" fill="currentColor"/></svg>
							<span class="naya-bar-send-label"><?php esc_html_e( 'Discuter', 'naya' ); ?></span>
						</button>
					</form>

					<div class="naya-bar-actions">
						<button type="button" class="naya-bar-toggle" aria-expanded="false" aria-controls="naya-panel"
							aria-label="<?php esc_attr_e( 'Ouvrir la conversation', 'naya' ); ?>">
							<span class="naya-chevron" aria-hidden="true"></span>
						</button>
						<button type="button" class="naya-bar-minimize" aria-label="<?php esc_attr_e( 'Réduire la barre', 'naya' ); ?>">✕</button>
					</div>
				</div>
			</div>

			<div id="naya-panel" class="naya-hidden" role="dialog" aria-label="<?php echo esc_attr( $s['bot_name'] ); ?>">
				<button type="button" class="naya-grabber" aria-label="<?php esc_attr_e( 'Fermer en glissant vers le bas', 'naya' ); ?>"></button>
				<div class="naya-panel-head">
					<span class="naya-panel-title"><?php echo esc_html( $s['bot_name'] ); ?></span>
					<div class="naya-panel-tools">
						<?php self::end_button(); ?>
						<button type="button" class="naya-rate-btn" title="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>" aria-label="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>">★</button>
						<?php if ( $page_url ) : ?>
							<a class="naya-expand" href="<?php echo esc_url( $page_url ); ?>" title="<?php esc_attr_e( 'Ouvrir en plein écran', 'naya' ); ?>">⛶</a>
						<?php endif; ?>
						<button type="button" class="naya-panel-close" aria-label="<?php esc_attr_e( 'Fermer la conversation', 'naya' ); ?>">✕</button>
					</div>
				</div>
				<div class="naya-messages" aria-live="polite"></div>
				<div class="naya-suggestions"></div>
				<form class="naya-input-bar">
					<input type="text" name="website" class="naya-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
					<textarea rows="1" placeholder="<?php esc_attr_e( 'Écrivez votre message…', 'naya' ); ?>"></textarea>
					<button type="submit" aria-label="<?php esc_attr_e( 'Envoyer', 'naya' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M4 12l16-8-6 16-2.5-6.5L4 12z" fill="currentColor"/></svg>
					</button>
				</form>
				<div class="naya-footer-brand">Propulsé par <strong>Deejitcorp</strong></div>
			</div>

			<button type="button" id="naya-tab" class="naya-hidden" aria-label="<?php esc_attr_e( 'Rouvrir la barre de conversation', 'naya' ); ?>">
				<span aria-hidden="true">💬</span> <?php echo esc_html( $s['bot_name'] ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Page dédiée façon "Alibaba" : historique à gauche, chat plein écran à droite.
	 */
	public static function shortcode_page() {
		$s = self::settings();
		ob_start();
		?>
		<div id="naya-page" data-naya-mode="page">
			<aside class="naya-sidebar">
				<button class="naya-new-chat">＋ <?php esc_html_e( 'Nouvelle conversation', 'naya' ); ?></button>
				<div class="naya-conv-list"></div>
			</aside>
			<main class="naya-main">
				<div class="naya-header naya-header-page">
					<button class="naya-toggle-sidebar" aria-label="<?php esc_attr_e( 'Historique', 'naya' ); ?>">☰</button>
					<div class="naya-avatar">✦</div>
					<div class="naya-header-meta">
						<strong><?php echo esc_html( $s['bot_name'] ); ?></strong>
						<span class="naya-status"><span class="naya-dot"></span><?php esc_html_e( 'En ligne', 'naya' ); ?></span>
					</div>
					<?php self::end_button(); ?>
					<button class="naya-rate-btn" title="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>" aria-label="<?php esc_attr_e( 'Noter la conversation', 'naya' ); ?>">★</button>
				</div>
				<div class="naya-messages" aria-live="polite"></div>
				<div class="naya-suggestions"></div>
				<form class="naya-input-bar">
					<input type="text" name="website" class="naya-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
					<textarea rows="1" placeholder="<?php esc_attr_e( 'Écrivez votre message…', 'naya' ); ?>"></textarea>
					<button type="submit" aria-label="<?php esc_attr_e( 'Envoyer', 'naya' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M4 12l16-8-6 16-2.5-6.5L4 12z" fill="currentColor"/></svg>
					</button>
				</form>
				<div class="naya-footer-brand">Propulsé par <strong>Deejitcorp</strong></div>
			</main>
		</div>
		<?php
		return ob_get_clean();
	}
}
