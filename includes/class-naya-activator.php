<?php
/**
 * Activation : création des tables et de la page dédiée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Activator {

	public static function activate() {
		self::create_tables();
		self::create_chat_page();
		self::default_options();
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$conversations = "CREATE TABLE {$wpdb->prefix}naya_conversations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			notified_at DATETIME NULL,
			notify_reason VARCHAR(255) NULL,
			PRIMARY KEY (id),
			KEY session_key (session_key),
			KEY user_id (user_id)
		) {$charset};";

		$messages = "CREATE TABLE {$wpdb->prefix}naya_messages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY conversation_id (conversation_id)
		) {$charset};";

		$events = "CREATE TABLE {$wpdb->prefix}naya_events (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(40) NOT NULL,
			session_key VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event (event),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $conversations );
		dbDelta( $messages );
		dbDelta( $events );
	}

	private static function create_chat_page() {
		$page_id = (int) get_option( 'naya_chat_page_id' );
		if ( $page_id && get_post_status( $page_id ) ) {
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => __( 'Assistant Naya', 'naya' ),
			'post_name'    => 'assistant-naya',
			'post_content' => '[naya_chat]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'naya_chat_page_id', $page_id );
		}
	}

	private static function default_options() {
		add_option( 'naya_settings', array(
			'api_key'         => '',
			'model'           => 'deepseek-chat',
			'max_tokens'      => 1024,
			'bot_name'        => 'Naya',
			'welcome_message' => __( 'Bonjour 👋 Je suis Naya, votre assistante. Comment puis-je vous aider aujourd\'hui ?', 'naya' ),
			'system_prompt'   => "Tu es Naya, une assistante virtuelle chaleureuse et professionnelle intégrée à un site WordPress. Tu conseilles les visiteurs, réponds à leurs questions sur le site, ses produits et ses services, et les orientes vers les bonnes pages. Réponds toujours dans la langue de l'utilisateur, de façon concise et utile. Si tu ne connais pas une information spécifique au site, dis-le honnêtement et propose de contacter l'équipe.",
			'primary_color'   => '#6d28d9',
			'secondary_color' => '#db2777',
			'widget_enabled'  => 1,
			'suggestions'     => "Quels sont vos services ?\nComment vous contacter ?\nParlez-moi de votre entreprise",
			'notify_enabled'  => 1,
			'notify_email'    => get_option( 'admin_email' ),
			'knowledge'       => '',
			'whatsapp'        => '221778002341',
		) );
	}
}
