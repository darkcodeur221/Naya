<?php
/**
 * Adresses des fichiers CSS et JavaScript, versionnées dans le chemin.
 *
 * WordPress distingue les versions d'un fichier par un paramètre
 * (`naya.css?ver=2.1.5`). Mais les extensions d'optimisation retirent
 * souvent ce paramètre (« supprimer les chaînes de requête »). L'adresse
 * devient alors identique d'une version à l'autre, et un CDN comme
 * Cloudflare — qui conserve ces fichiers jusqu'à un an — continue de servir
 * l'ancien indéfiniment : le HTML est à jour, le style et le comportement
 * ne le sont pas.
 *
 * On sert donc une copie placée dans un dossier qui porte la version :
 *   wp-content/uploads/naya/2.1.5/assets/css/naya.css
 * Nouvelle version, nouvelle adresse : aucun cache ne peut resservir
 * l'ancienne. Si la copie est impossible (droits d'écriture), on retombe
 * sur l'adresse d'origine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Assets {

	/** Nombre de versions conservées : un HTML encore en cache peut pointer vers l'une d'elles. */
	const VERSIONS_KEPT = 3;

	/**
	 * @param string $relative Chemin relatif au plugin, ex. « assets/css/naya.css ».
	 * @return string Adresse publique du fichier.
	 */
	public static function url( $relative ) {
		$relative = ltrim( $relative, '/' );
		$upload   = wp_upload_dir( null, false );

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return self::origin_url( $relative );
		}

		$versioned = 'naya/' . NAYA_VERSION . '/' . $relative;
		$dest      = trailingslashit( $upload['basedir'] ) . $versioned;

		if ( ! file_exists( $dest ) && ! self::copy( NAYA_PLUGIN_DIR . $relative, $dest ) ) {
			return self::origin_url( $relative );
		}

		// Certains hébergements derrière un proxy annoncent « http » alors que
		// le site est servi en « https » : on aligne sur le protocole du site.
		return set_url_scheme( trailingslashit( $upload['baseurl'] ) . $versioned );
	}

	private static function origin_url( $relative ) {
		return NAYA_PLUGIN_URL . $relative;
	}

	private static function copy( $src, $dest ) {
		if ( ! is_readable( $src ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return false;
		}
		// Copie dans un fichier temporaire puis renommage : un visiteur ne peut
		// jamais recevoir un fichier à moitié écrit.
		$tmp = $dest . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return @rename( $tmp, $dest ) || file_exists( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Supprime les copies des versions trop anciennes. Les plus récentes sont
	 * gardées : une page encore en cache peut y faire référence, et la
	 * supprimer priverait ses visiteurs de tout style.
	 */
	public static function prune() {
		$upload = wp_upload_dir( null, false );
		if ( ! empty( $upload['error'] ) ) {
			return;
		}

		$root = trailingslashit( $upload['basedir'] ) . 'naya';
		if ( ! is_dir( $root ) ) {
			return;
		}

		$versions = array();
		foreach ( (array) scandir( $root ) as $entry ) {
			// Uniquement des dossiers nommés comme une version : rien d'autre n'est touché.
			if ( preg_match( '/^\d+\.\d+\.\d+$/', $entry ) && is_dir( $root . '/' . $entry ) ) {
				$versions[] = $entry;
			}
		}

		usort( $versions, 'version_compare' );
		$obsolete = array_slice( $versions, 0, max( 0, count( $versions ) - self::VERSIONS_KEPT ) );

		foreach ( $obsolete as $version ) {
			if ( NAYA_VERSION !== $version ) {
				self::remove_dir( $root . '/' . $version );
			}
		}
	}

	private static function remove_dir( $dir ) {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? self::remove_dir( $path ) : @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
