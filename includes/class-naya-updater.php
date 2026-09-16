<?php
/**
 * Mises à jour automatiques depuis les releases GitHub.
 *
 * Le plugin apparaît dans « Extensions → Mises à jour » comme n'importe quel
 * plugin du répertoire officiel : il suffit de publier une release taguée
 * (v2.0.1, v2.1.0…) sur GitHub, et tous les sites la proposent.
 *
 * Aucune bibliothèque tierce : on interroge l'API publique de GitHub, avec un
 * cache de 6 heures pour rester loin des limites de débit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Updater {

	/** Dépôt au format « propriétaire/nom ». */
	const REPO = 'darkcodeur221/Naya';

	const CACHE_KEY = 'naya_latest_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ), 10, 0 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_check' ) );
	}

	private static function basename() {
		return plugin_basename( NAYA_PLUGIN_FILE );
	}

	/**
	 * Dernière version publiée, mise en cache.
	 *
	 * On regarde d'abord les releases (elles portent des notes de version) ;
	 * à défaut, un simple tag Git suffit à déclencher une mise à jour.
	 *
	 * @return array|false
	 */
	private static function latest_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return empty( $cached['version'] ) ? false : $cached;
			}
		}

		$release = self::fetch_release();
		if ( ! $release ) {
			$release = self::fetch_tag();
		}

		// Cache court en cas d'échec, pour ne pas réinterroger à chaque page d'admin.
		set_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : 30 * MINUTE_IN_SECONDS );

		return $release;
	}

	private static function api_get( $endpoint ) {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Naya-Updater/' . NAYA_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/** Release GitHub en bonne et due forme. */
	private static function fetch_release() {
		$data = self::api_get( '/releases/latest' );
		if ( empty( $data['tag_name'] ) ) {
			return false;
		}

		// Un ZIP joint à la release prime sur l'archive automatique du tag :
		// il contient l'arborescence exacte du plugin.
		$package = ! empty( $data['zipball_url'] ) ? $data['zipball_url'] : '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['browser_download_url'], -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		return array(
			'version'   => ltrim( $data['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => ! empty( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . self::REPO,
			'published' => ! empty( $data['published_at'] ) ? $data['published_at'] : '',
			'notes'     => ! empty( $data['body'] ) ? $data['body'] : '',
		);
	}

	/** Repli : le dépôt n'a que des tags, sans release publiée. */
	private static function fetch_tag() {
		$tags = self::api_get( '/tags' );
		if ( empty( $tags[0]['name'] ) ) {
			return false;
		}

		$tag = $tags[0];

		return array(
			'version'   => ltrim( $tag['name'], 'vV' ),
			'package'   => ! empty( $tag['zipball_url'] ) ? $tag['zipball_url'] : '',
			'url'       => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $tag['name'] ),
			'published' => '',
			'notes'     => '',
		);
	}

	/**
	 * Signale la mise à jour à WordPress.
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::latest_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		$basename = self::basename();

		if ( version_compare( $release['version'], NAYA_VERSION, '>' ) ) {
			$transient->response[ $basename ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => dirname( $basename ),
				'plugin'      => $basename,
				'new_version' => $release['version'],
				'url'         => $release['url'],
				'package'     => $release['package'],
				'tested'      => get_bloginfo( 'version' ),
				'icons'       => array(),
			);
		} else {
			// Sans cette entrée, WordPress affiche « aucune information de mise à jour ».
			$transient->no_update[ $basename ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => dirname( $basename ),
				'plugin'      => $basename,
				'new_version' => NAYA_VERSION,
				'url'         => $release['url'],
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Contenu de la fenêtre « Voir les détails ».
	 */
	public static function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		if ( dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		$notes = $release['notes'] ? wpautop( wp_kses_post( $release['notes'] ) ) : __( 'Aucune note de version.', 'naya' );

		return (object) array(
			'name'          => 'Naya — Assistant IA',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/darkcodeur221">Deejitcorp</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => __( 'Chatbot IA propulsé par DeepSeek : conseille vos visiteurs, détecte les prospects et vous alerte par e-mail.', 'naya' ),
				'changelog'   => $notes,
			),
		);
	}

	/**
	 * L'archive GitHub se décompresse dans un dossier horodaté
	 * (« darkcodeur221-Naya-a1b2c3 ») : on le renomme pour conserver le dossier
	 * d'origine du plugin, sinon WordPress le désactive après la mise à jour.
	 */
	public static function rename_source( $source, $remote_source, $upgrader, $extra = array() ) {
		if ( empty( $extra['plugin'] ) || self::basename() !== $extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( self::basename() );
		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return new WP_Error( 'naya_rename_failed', __( 'Impossible de préparer le dossier du plugin.', 'naya' ) );
	}

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );

		// Après une mise à jour, les fichiers CSS/JS combinés des extensions
		// de cache doivent être régénérés.
		if ( class_exists( 'Naya_Cache' ) ) {
			Naya_Cache::purge_all();
		}
	}

	/**
	 * Lien « Vérifier les mises à jour » sous la ligne du plugin.
	 */
	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'naya_check_update', '1', admin_url( 'plugins.php' ) ),
			'naya_check_update'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Vérifier les mises à jour', 'naya' ) . '</a>';
		return $links;
	}

	public static function handle_manual_check() {
		if ( empty( $_GET['naya_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'naya_check_update' );

		self::latest_release( true );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
