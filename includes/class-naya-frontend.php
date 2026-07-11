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
			'i18n'     => array(
				'placeholder' => __( 'Écrivez votre message…', 'naya' ),
				'error'       => __( 'Oups, une erreur est survenue. Réessayez.', 'naya' ),
				'newChat'     => __( 'Nouvelle conversation', 'naya' ),
				'online'      => __( 'En ligne', 'naya' ),
				'thinking'    => __( 'Naya réfléchit…', 'naya' ),
				'deleteConf'  => __( 'Supprimer cette conversation ?', 'naya' ),
				'emptyList'   => __( 'Aucune conversation pour le moment.', 'naya' ),
			),
		) );

		$css = sprintf(
			':root{--naya-c1:%1$s;--naya-c2:%2$s;}',
			esc_html( $s['primary_color'] ),
			esc_html( $s['secondary_color'] )
		);
		wp_add_inline_style( 'naya', $css );
	}

	/**
	 * Bulle + fenêtre flottante, injectées dans le footer de toutes les pages.
	 */
	public static function render_widget() {
		$s = self::settings();
		if ( ! $s['widget_enabled'] || self::is_chat_page() ) {
			return;
		}
		?>
		<div id="naya-widget" data-naya-mode="widget">
			<button id="naya-launcher" aria-label="<?php esc_attr_e( 'Ouvrir le chat', 'naya' ); ?>">
				<span class="naya-launcher-icon">
					<svg viewBox="0 0 24 24" fill="none" width="28" height="28"><path d="M12 3C7 3 3 6.6 3 11c0 2.2 1 4.2 2.7 5.6-.1 1-.5 2.1-1.4 3.1-.2.2 0 .6.3.5 1.7-.2 3.1-.8 4.1-1.5 1 .3 2.1.4 3.3.4 5 0 9-3.6 9-8S17 3 12 3z" fill="currentColor"/><circle cx="8.5" cy="11" r="1.2" fill="#fff"/><circle cx="12" cy="11" r="1.2" fill="#fff"/><circle cx="15.5" cy="11" r="1.2" fill="#fff"/></svg>
				</span>
				<span class="naya-launcher-pulse"></span>
			</button>

			<div id="naya-window" class="naya-hidden" role="dialog" aria-label="<?php echo esc_attr( $s['bot_name'] ); ?>">
				<div class="naya-header">
					<div class="naya-avatar">✦</div>
					<div class="naya-header-meta">
						<strong><?php echo esc_html( $s['bot_name'] ); ?></strong>
						<span class="naya-status"><span class="naya-dot"></span><?php esc_html_e( 'En ligne', 'naya' ); ?></span>
					</div>
					<a class="naya-expand" href="<?php echo esc_url( get_permalink( (int) get_option( 'naya_chat_page_id' ) ) ); ?>" title="<?php esc_attr_e( 'Ouvrir en plein écran', 'naya' ); ?>">⛶</a>
					<button class="naya-close" aria-label="<?php esc_attr_e( 'Fermer', 'naya' ); ?>">✕</button>
				</div>
				<div class="naya-messages" aria-live="polite"></div>
				<div class="naya-suggestions"></div>
				<form class="naya-input-bar">
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
				</div>
				<div class="naya-messages" aria-live="polite"></div>
				<div class="naya-suggestions"></div>
				<form class="naya-input-bar">
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
