<?php
/**
 * Plugin Name:       Naya — Assistant IA
 * Plugin URI:        https://github.com/darkcodeur221/Naya
 * Update URI:        https://github.com/darkcodeur221/Naya
 * Description:       Chatbot IA propulsé par DeepSeek — par Deejitcorp. Widget flottant élégant, page de chat dédiée et mémoire de conversation persistante.
 * Version:           2.1.3
 * Author:            Deejitcorp
 * Author URI:        https://github.com/darkcodeur221
 * License:           GPL-2.0-or-later
 * Text Domain:       naya
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NAYA_VERSION', '2.1.3' );
define( 'NAYA_DB_VERSION', '2.0' );
define( 'NAYA_PLUGIN_FILE', __FILE__ );
define( 'NAYA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NAYA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NAYA_PLUGIN_DIR . 'includes/class-naya-activator.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-conversations.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-deepseek.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-security.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-notify.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-knowledge.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-playbook.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-stats.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-updater.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-cache.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-rest.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-admin.php';
require_once NAYA_PLUGIN_DIR . 'includes/class-naya-frontend.php';

register_activation_hook( __FILE__, array( 'Naya_Activator', 'activate' ) );

add_action( 'plugins_loaded', function () {
	// Mise à niveau du schéma pour les installations existantes.
	if ( get_option( 'naya_db_version' ) !== NAYA_DB_VERSION ) {
		Naya_Activator::create_tables();
		update_option( 'naya_db_version', NAYA_DB_VERSION );
	}

	// Les fichiers ont changé : les caches doivent suivre, sinon le widget
	// s'affiche sans style avec un CSS combiné périmé.
	Naya_Cache::maybe_purge_after_update();

	Naya_Rest::init();
	Naya_Admin::init();
	Naya_Frontend::init();
	Naya_Knowledge::init();
	Naya_Stats::init();

	// Mises à jour automatiques depuis les releases GitHub (admin uniquement).
	if ( is_admin() ) {
		Naya_Updater::init();
	}
} );
