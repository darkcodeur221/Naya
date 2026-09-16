<?php
/**
 * Purge des caches après mise à jour.
 *
 * Les extensions de cache (LiteSpeed, WP Rocket…) servent des versions
 * combinées et minifiées du CSS et du JavaScript. Après une mise à jour de
 * Naya, le HTML généré par PHP est immédiatement à jour alors que ces
 * fichiers combinés restent figés : l'interface se retrouve alors sans style
 * ni pilotage, et le widget s'affiche en vrac au bas de la page.
 *
 * On purge donc automatiquement dès qu'un changement de version est détecté.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Cache {

	const VERSION_OPTION = 'naya_installed_version';

	/**
	 * Compare la version enregistrée à celle du code : toute différence
	 * signifie que les fichiers ont changé sous les pieds du cache.
	 */
	public static function maybe_purge_after_update() {
		$installed = get_option( self::VERSION_OPTION );

		if ( NAYA_VERSION === $installed ) {
			return;
		}

		update_option( self::VERSION_OPTION, NAYA_VERSION );

		// Première installation : rien à purger.
		if ( false !== $installed ) {
			self::purge_all();
		}
	}

	/**
	 * Vide les caches des extensions les plus répandues, quand elles sont
	 * présentes. Chaque appel est protégé : une extension absente ne doit
	 * jamais provoquer d'erreur fatale.
	 */
	public static function purge_all() {
		// LiteSpeed Cache — purge des pages et des fichiers CSS/JS combinés.
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Purge' ) ) {
			do_action( 'litespeed_purge_all' );
			do_action( 'litespeed_purge_all_cssjs' );
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// Autoptimize
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}

		// Cache Enabler
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			Cache_Enabler::clear_complete_cache();
		}

		// SiteGround Optimizer
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Hébergements gérés (Kinsta, WP Engine, Pagely…) et cache objet.
		do_action( 'naya_purge_cache' );

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}
}
