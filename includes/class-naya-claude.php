<?php
/**
 * Client API Claude (Anthropic) via wp_remote_post.
 * Le SDK Composer n'est pas utilisé : les plugins WordPress distribués
 * s'appuient sur l'API HTTP de WordPress pour rester sans dépendance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Claude {

	const API_URL     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/**
	 * Envoie l'historique + le nouveau message à Claude et renvoie la réponse texte.
	 *
	 * @param array $messages Historique au format [['role' => ..., 'content' => ...], ...]
	 * @return string|WP_Error
	 */
	public static function chat( array $messages ) {
		$settings = get_option( 'naya_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error( 'naya_no_key', __( 'La clé API Anthropic n\'est pas configurée. Rendez-vous dans Réglages → Naya.', 'naya' ) );
		}

		$body = array(
			'model'      => ! empty( $settings['model'] ) ? $settings['model'] : 'claude-opus-4-8',
			'max_tokens' => ! empty( $settings['max_tokens'] ) ? (int) $settings['max_tokens'] : 1024,
			'system'     => self::system_prompt( $settings ),
			'messages'   => $messages,
		);

		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 90,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Erreur inconnue de l\'API.', 'naya' );
			return new WP_Error( 'naya_api_error', $message, array( 'status' => $code ) );
		}

		// Vérifier le stop_reason avant de lire le contenu.
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'naya_refusal', __( 'Je ne peux pas répondre à cette demande. Essayez de reformuler.', 'naya' ) );
		}

		$text = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'naya_empty', __( 'Réponse vide de l\'assistant.', 'naya' ) );
		}

		return $text;
	}

	/**
	 * Prompt système : instructions de l'admin + contexte du site.
	 */
	private static function system_prompt( $settings ) {
		$prompt = ! empty( $settings['system_prompt'] ) ? $settings['system_prompt'] : '';

		$site_context = sprintf(
			"\n\n<contexte_site>\nNom du site : %s\nDescription : %s\nURL : %s\n</contexte_site>",
			get_bloginfo( 'name' ),
			get_bloginfo( 'description' ),
			home_url()
		);

		return $prompt . $site_context;
	}
}
