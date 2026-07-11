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
	 * Prompt système : instructions de l'admin + connaissances du site
	 * + règles de style + garde-fous + notification.
	 */
	private static function system_prompt( $settings ) {
		$prompt = ! empty( $settings['system_prompt'] ) ? $settings['system_prompt'] : '';

		$site_context = sprintf(
			"\n\n<contexte_site>\nNom du site : %s\nDescription : %s\nURL : %s\n</contexte_site>",
			get_bloginfo( 'name' ),
			get_bloginfo( 'description' ),
			home_url()
		);

		// Connaissances rédigées par l'admin (offres, tarifs, FAQ…).
		$custom = '';
		if ( ! empty( $settings['knowledge'] ) ) {
			$custom = "\n\n<connaissances_admin>\n" . $settings['knowledge'] . "\n</connaissances_admin>";
		}

		return $prompt
			. $site_context
			. Naya_Knowledge::context()
			. $custom
			. self::style_rules( $settings )
			. Naya_Security::prompt_guard()
			. Naya_Notify::prompt_instructions();
	}

	/**
	 * Règles de réponse : concision, liens réels, redirection WhatsApp.
	 */
	private static function style_rules( $settings ) {
		$whatsapp = ! empty( $settings['whatsapp'] ) ? preg_replace( '/\D/', '', $settings['whatsapp'] ) : '';

		$rules = "\n\n<regles_de_reponse>\n"
			. "- Sois BREF : 2 à 4 phrases maximum. Pas de listes à rallonge ni de paragraphes multiples, sauf si l'utilisateur demande explicitement des détails.\n"
			. "- Appuie-toi UNIQUEMENT sur <connaissances_site> et <connaissances_admin>. Si l'information n'y figure pas (un prix, un délai…), ne l'invente jamais : dis-le en une phrase et propose le bon lien ou le contact direct.\n"
			. "- Quand tu mentionnes une page, une offre ou un produit, donne TOUJOURS son lien réel au format markdown [texte](url), en utilisant exclusivement les URLs listées dans tes connaissances. N'invente jamais d'URL.\n"
			. "- Ne pose qu'une seule question à la fois, jamais plusieurs.\n";

		if ( $whatsapp ) {
			$rules .= "- Si le visiteur montre une intention sérieuse (achat, devis, projet concret, urgence), propose-lui de poursuivre directement sur WhatsApp avec ce lien : [Discuter sur WhatsApp](https://wa.me/{$whatsapp}). C'est le canal à privilégier pour les prospects sérieux.\n";
		}

		$rules .= "</regles_de_reponse>";
		return $rules;
	}
}
