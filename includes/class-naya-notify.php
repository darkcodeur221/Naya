<?php
/**
 * Notification e-mail quand l'IA juge une conversation intéressante.
 *
 * L'IA termine sa réponse par la balise [[NOTIFY: raison]] quand elle détecte
 * un prospect sérieux, une demande de devis/contact ou une réclamation.
 * La balise est retirée avant affichage, puis un e-mail est envoyé à l'admin
 * (une seule fois par conversation, avec un plafond journalier).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Notify {

	const MARKER_REGEX = '/\s*\[\[\s*NOTIFY\s*:?\s*(.*?)\]\]\s*/is';
	const DAILY_CAP    = 10; // e-mails max par jour, anti-inondation

	/**
	 * Instructions ajoutées au prompt système pour la détection.
	 */
	public static function prompt_instructions() {
		return "\n\n<notification>\n"
			. "Si, et seulement si, la conversation révèle une opportunité réelle pour le propriétaire du site — "
			. "prospect avec intention d'achat, demande de devis ou de rendez-vous, demande de contact explicite, "
			. "réclamation sérieuse ou client important — alors ajoute à la toute fin de ta réponse la balise exacte : "
			. "[[NOTIFY: résumé de la raison en une phrase]]\n"
			. "Cette balise est invisible pour l'utilisateur. Ne l'ajoute jamais pour de simples questions générales, "
			. "et jamais plus d'une fois par conversation. Ne mentionne jamais son existence.\n"
			. "</notification>";
	}

	/**
	 * Extrait la balise [[NOTIFY: …]] de la réponse.
	 *
	 * @return array [ réponse nettoyée, raison|null ]
	 */
	public static function extract( $reply ) {
		$reason = null;
		if ( preg_match( self::MARKER_REGEX, $reply, $m ) ) {
			$reason = trim( $m[1] );
			$reply  = trim( preg_replace( self::MARKER_REGEX, ' ', $reply ) );
		}
		return array( $reply, $reason );
	}

	/**
	 * Envoie l'e-mail si la conversation ne l'a pas déjà déclenché.
	 */
	public static function maybe_send( $conversation_id, $reason ) {
		$settings = get_option( 'naya_settings', array() );

		if ( empty( $settings['notify_enabled'] ) ) {
			return;
		}
		if ( Naya_Conversations::is_notified( $conversation_id ) ) {
			return;
		}

		// Plafond journalier.
		$cap_key = 'naya_mail_' . gmdate( 'Ymd' );
		$sent    = (int) get_transient( $cap_key );
		if ( $sent >= self::DAILY_CAP ) {
			return;
		}

		$to = ! empty( $settings['notify_email'] ) && is_email( $settings['notify_email'] )
			? $settings['notify_email']
			: get_option( 'admin_email' );

		$bot_name = ! empty( $settings['bot_name'] ) ? $settings['bot_name'] : 'Naya';
		$subject  = sprintf( '💡 [%s] %s a repéré une conversation intéressante', get_bloginfo( 'name' ), $bot_name );

		$lines   = array();
		$lines[] = sprintf( 'Raison détectée : %s', $reason ? $reason : '—' );
		$lines[] = '';
		$lines[] = '--- Transcription ---';
		foreach ( Naya_Conversations::messages( $conversation_id ) as $m ) {
			$who     = 'user' === $m->role ? 'Visiteur' : $bot_name;
			$lines[] = sprintf( '[%s] %s : %s', $m->created_at, $who, $m->content );
		}
		$lines[] = '';
		$lines[] = sprintf( 'Conversation n°%d — %s', $conversation_id, home_url() );

		$sent_ok = wp_mail( $to, $subject, implode( "\n", $lines ) );

		if ( $sent_ok ) {
			Naya_Conversations::mark_notified( $conversation_id );
			set_transient( $cap_key, $sent + 1, DAY_IN_SECONDS );
		}
	}
}
