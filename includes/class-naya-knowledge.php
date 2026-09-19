<?php
/**
 * Connaissances du site : Naya est nourrie du contenu réel de WordPress
 * (pages, articles, produits WooCommerce) pour répondre avec précision
 * et proposer de vrais liens — au lieu d'inventer ou de rester vague.
 *
 * L'index est reconstruit toutes les 12 h ou dès qu'un contenu est publié.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Knowledge {

	const CACHE_KEY = 'naya_knowledge';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function init() {
		// Un contenu publié ou modifié invalide l'index.
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Bloc <connaissances_site> injecté dans le prompt système.
	 */
	public static function context() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$lines = array();

		// Pages publiées (menu d'abord).
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );
		if ( $pages ) {
			$lines[] = 'PAGES DU SITE :';
			foreach ( $pages as $p ) {
				$lines[] = self::entry( $p->post_title, get_permalink( $p ), $p->post_content );
			}
		}

		// Articles récents.
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
		) );
		if ( $posts ) {
			$lines[] = '';
			$lines[] = 'ARTICLES RÉCENTS :';
			foreach ( $posts as $p ) {
				$lines[] = self::entry( $p->post_title, get_permalink( $p ), $p->post_content );
			}
		}

		// Boutique WooCommerce : vue d'ensemble (rayons, meilleures ventes).
		// Les fiches précises sont cherchées à chaque question par Naya_Catalog.
		$catalog = Naya_Catalog::overview();
		if ( $catalog ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $catalog );
		}

		$context = '';
		if ( $lines ) {
			$context = "\n\n<connaissances_site>\n" . implode( "\n", $lines ) . "\n</connaissances_site>";
		}

		set_transient( self::CACHE_KEY, $context, self::CACHE_TTL );
		return $context;
	}

	/**
	 * Une ligne d'index : titre — URL : résumé court.
	 */
	private static function entry( $title, $url, $content ) {
		$excerpt = wp_html_excerpt( wp_strip_all_tags( strip_shortcodes( (string) $content ) ), 180, '…' );
		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) );
		return sprintf( '- %s — %s%s', $title, $url, $excerpt ? ' : ' . $excerpt : '' );
	}
}
