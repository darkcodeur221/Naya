<?php
/**
 * Client API DeepSeek via wp_remote_post.
 * DeepSeek expose une API compatible OpenAI (chat completions).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_DeepSeek {

	const API_URL = 'https://api.deepseek.com/chat/completions';

	/**
	 * Envoie l'historique + le nouveau message à DeepSeek et renvoie la réponse texte.
	 *
	 * @param array $messages Historique au format [['role' => ..., 'content' => ...], ...]
	 * @return string|WP_Error
	 */
	public static function chat( array $messages ) {
		$settings = get_option( 'naya_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error( 'naya_no_key', __( 'La clé API DeepSeek n\'est pas configurée. Rendez-vous dans Réglages → Naya.', 'naya' ) );
		}

		// Le prompt système est le premier message du tableau (format OpenAI).
		array_unshift( $messages, array(
			'role'    => 'system',
			'content' => self::system_prompt( $settings ),
		) );

		$body = array(
			'model'      => ! empty( $settings['model'] ) ? $settings['model'] : 'deepseek-chat',
			'max_tokens' => ! empty( $settings['max_tokens'] ) ? (int) $settings['max_tokens'] : 1024,
			'messages'   => $messages,
			'stream'     => false,
		);

		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 90,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
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

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

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
