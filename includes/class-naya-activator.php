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
			notify_priority VARCHAR(10) NULL,
			lead_contact VARCHAR(190) NULL,
			rating TINYINT UNSIGNED NULL,
			feedback TEXT NULL,
			rated_at DATETIME NULL,
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
			'system_prompt'   => "Tu es Naya, la conseillère de l'entreprise : chaleureuse, professionnelle et efficace. Tu accueilles les visiteurs du site, tu comprends leur besoin, tu les conseilles honnêtement et tu les accompagnes jusqu'à la bonne solution. Réponds toujours dans la langue du visiteur. Tu représentes l'entreprise : ton objectif est qu'un visiteur reparte soit avec sa réponse, soit avec un rendez-vous.",
			'primary_color'   => '#6d28d9',
			'secondary_color' => '#db2777',
			'widget_enabled'  => 1,
			'suggestions'     => "Quels sont vos services ?\nComment vous contacter ?\nParlez-moi de votre entreprise",
			'notify_enabled'  => 1,
			'notify_email'    => get_option( 'admin_email' ),
			'knowledge'       => '',
			'whatsapp'        => '221778002341',
			'teaser_enabled'  => 1,
			'teaser_delay'    => 8,
			'teaser_message'  => __( 'Une question ? Je vous réponds tout de suite 👋', 'naya' ),
			'widget_position' => 'bar',
			'bar_offset'      => 1,
			'bar_tagline'     => __( 'Conseillère en ligne', 'naya' ),
			'bar_placeholder' => __( 'Posez votre question, je réponds en direct…', 'naya' ),
			'sales_style'     => 'balanced',
		) );
	}
}
