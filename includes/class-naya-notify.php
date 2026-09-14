<?php
/**
 * Alertes e-mail à l'administrateur.
 *
 * L'IA signale elle-même les conversations à traiter en terminant sa réponse
 * par une balise invisible :
 *   [[NOTIFY: urgent | raison en une phrase | Prénom, +221 77 000 00 00]]
 * Le champ priorité et le champ contact sont facultatifs. La balise est
 * retirée de la réponse avant affichage, puis l'e-mail part vers les
 * destinataires configurés.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Notify {

	const MARKER_REGEX = '/\s*\[\[\s*NOTIFY\s*:?\s*(.*?)\]\]\s*/is';
	const DAILY_CAP    = 30; // plafond anti-inondation, par jour

	/**
	 * Instructions ajoutées au prompt système.
	 */
	public static function prompt_instructions() {
		return "\n\n<alerte_administrateur>\n"
			. "Tu peux prévenir l'équipe par e-mail en ajoutant, à la toute fin de ta réponse, la balise exacte :\n"
			. "[[NOTIFY: priorité | raison en une phrase | coordonnées connues]]\n"
			. "- priorité vaut « urgent » ou « normal ».\n"
			. "- coordonnées : recopie le prénom, le téléphone ou l'e-mail que le visiteur t'a donnés ; sinon écris « non communiquées ».\n\n"
			. "Utilise « urgent » quand l'entreprise doit rappeler au plus vite : demande de devis ferme, projet avec échéance proche, "
			. "budget annoncé, demande de rendez-vous, client mécontent, ou visiteur qui laisse ses coordonnées.\n"
			. "Utilise « normal » pour un intérêt réel mais sans urgence : question sur une offre, comparaison, prise de renseignements sérieuse.\n"
			. "N'ajoute aucune balise pour les questions générales, les curieux ou les demandes déjà résolues.\n\n"
			. "Tu peux alerter une seconde fois dans la même conversation UNIQUEMENT si la situation s'aggrave ou se précise : "
			. "passage en « urgent », ou obtention des coordonnées après une première alerte.\n"
			. "Cette balise est invisible pour le visiteur : ne la commente jamais, ne dis jamais que tu préviens quelqu'un par e-mail. "
			. "Tu peux en revanche dire naturellement qu'un conseiller va le recontacter.\n"
			. "</alerte_administrateur>";
	}

	/**
	 * Extrait et analyse la balise.
	 *
	 * @return array [ réponse nettoyée, array|null {priority, reason, contact} ]
	 */
	public static function extract( $reply ) {
		if ( ! preg_match( self::MARKER_REGEX, $reply, $m ) ) {
			return array( $reply, null );
		}

		$reply = trim( preg_replace( self::MARKER_REGEX, ' ', $reply ) );
		$parts = array_map( 'trim', explode( '|', $m[1] ) );

		$priority = 'normal';
		$reason   = '';
		$contact  = '';

		// Le premier champ n'est une priorité que s'il en a la forme.
		if ( isset( $parts[0] ) && preg_match( '/^(urgent|normal|haute|basse|high|low)$/i', $parts[0] ) ) {
			$priority = preg_match( '/^(urgent|haute|high)$/i', $parts[0] ) ? 'urgent' : 'normal';
			array_shift( $parts );
		}

		$reason  = isset( $parts[0] ) ? $parts[0] : '';
		$contact = isset( $parts[1] ) ? $parts[1] : '';

		if ( preg_match( '/non communiqu|inconnu|aucune|n\/a/i', $contact ) ) {
			$contact = '';
		}

		return array( $reply, array(
			'priority' => $priority,
			'reason'   => mb_substr( $reason, 0, 255 ),
			'contact'  => mb_substr( $contact, 0, 190 ),
		) );
	}

	/**
	 * Destinataires configurés (un ou plusieurs, séparés par des virgules).
	 */
	private static function recipients( $settings ) {
		$raw   = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
		$list  = array_filter( array_map( 'trim', preg_split( '/[,;]+/', $raw ) ), 'is_email' );
		return $list ? array_values( $list ) : array( get_option( 'admin_email' ) );
	}

	/**
	 * Envoie l'alerte, sauf si la conversation a déjà déclenché la même.
	 */
	public static function maybe_send( $conversation_id, $alert ) {
		$settings = get_option( 'naya_settings', array() );

		if ( empty( $settings['notify_enabled'] ) || empty( $alert ) ) {
			return;
		}

		$previous = Naya_Conversations::notification_state( $conversation_id );

		// Déjà alerté : on ne renvoie que si la situation progresse réellement.
		if ( $previous['notified'] ) {
			$monte_en_urgence = ( 'urgent' === $alert['priority'] && 'urgent' !== $previous['priority'] );
			$contact_nouveau  = ( '' !== $alert['contact'] && '' === $previous['contact'] );
			if ( ! $monte_en_urgence && ! $contact_nouveau ) {
				return;
			}
		}

		$cap_key = 'naya_mail_' . gmdate( 'Ymd' );
		$sent    = (int) get_transient( $cap_key );
		if ( $sent >= self::DAILY_CAP ) {
			return;
		}

		$bot_name = ! empty( $settings['bot_name'] ) ? $settings['bot_name'] : 'Naya';
		$urgent   = ( 'urgent' === $alert['priority'] );

		$subject = sprintf(
			'%s [%s] %s — %s',
			$urgent ? '🔥 À RAPPELER' : '💡 Piste',
			get_bloginfo( 'name' ),
			$alert['contact'] ? $alert['contact'] : __( 'nouveau prospect', 'naya' ),
			wp_html_excerpt( $alert['reason'], 70, '…' )
		);

		$body    = self::build_email( $conversation_id, $alert, $bot_name, $urgent );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$ok = wp_mail( self::recipients( $settings ), $subject, $body, $headers );

		if ( $ok ) {
			Naya_Conversations::mark_notified( $conversation_id, $alert['reason'], $alert['priority'], $alert['contact'] );
			set_transient( $cap_key, $sent + 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Alerte immédiate sur une note faible (1-2 étoiles) : le signal qualité
	 * le plus précieux à traiter à chaud.
	 */
	public static function low_rating_alert( $conversation_id, $rating, $comment ) {
		$settings = get_option( 'naya_settings', array() );
		if ( empty( $settings['notify_enabled'] ) ) {
			return;
		}

		$bot_name = ! empty( $settings['bot_name'] ) ? $settings['bot_name'] : 'Naya';
		$subject  = sprintf( '⚠️ [%s] Note faible : %d/5', get_bloginfo( 'name' ), $rating );

		$alert = array(
			'priority' => 'urgent',
			'reason'   => sprintf(
				/* translators: 1: note sur 5, 2: commentaire du visiteur */
				__( 'Visiteur insatisfait (%1$d/5). Commentaire : %2$s', 'naya' ),
				$rating,
				$comment ? $comment : __( 'aucun', 'naya' )
			),
			'contact'  => '',
		);

		wp_mail(
			self::recipients( $settings ),
			$subject,
			self::build_email( $conversation_id, $alert, $bot_name, true ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Corps HTML de l'alerte : l'essentiel en haut, la transcription en dessous.
	 */
	private static function build_email( $conversation_id, $alert, $bot_name, $urgent ) {
		$accent = $urgent ? '#dc2626' : '#6d28d9';
		$titre  = $urgent
			? __( 'Contact à rappeler en priorité', 'naya' )
			: __( 'Nouvelle piste détectée', 'naya' );

		$transcript = '';
		foreach ( Naya_Conversations::messages( $conversation_id ) as $m ) {
			$is_user = ( 'user' === $m->role );
			$transcript .= sprintf(
				'<tr><td style="padding:8px 0;border-bottom:1px solid #eee;vertical-align:top;width:90px;color:%1$s;font-weight:600;font-size:13px;">%2$s</td>
				 <td style="padding:8px 0 8px 12px;border-bottom:1px solid #eee;font-size:14px;color:#222;line-height:1.5;">%3$s</td></tr>',
				$is_user ? '#111' : $accent,
				$is_user ? esc_html__( 'Visiteur', 'naya' ) : esc_html( $bot_name ),
				nl2br( esc_html( $m->content ) )
			);
		}

		$stats_url = admin_url( 'admin.php?page=naya-stats' );
		$contact   = $alert['contact']
			? esc_html( $alert['contact'] )
			: '<span style="color:#888;">' . esc_html__( 'non communiquées — à relancer via la conversation', 'naya' ) . '</span>';

		return sprintf(
			'<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:24px;background:#fff;">
				<div style="border-left:4px solid %1$s;padding:4px 0 4px 16px;margin-bottom:22px;">
					<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:%1$s;font-weight:700;">%2$s</div>
					<h1 style="margin:6px 0 0;font-size:21px;color:#111;">%3$s</h1>
				</div>

				<table style="width:100%%;border-collapse:collapse;margin-bottom:24px;">
					<tr><td style="padding:6px 0;color:#666;font-size:13px;width:110px;">%4$s</td>
						<td style="padding:6px 0;font-size:15px;color:#111;font-weight:600;">%5$s</td></tr>
					<tr><td style="padding:6px 0;color:#666;font-size:13px;">%6$s</td>
						<td style="padding:6px 0;font-size:15px;color:#111;">%7$s</td></tr>
				</table>

				<h2 style="font-size:14px;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px;">%8$s</h2>
				<table style="width:100%%;border-collapse:collapse;">%9$s</table>

				<p style="margin-top:26px;">
					<a href="%10$s" style="background:%1$s;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;display:inline-block;">%11$s</a>
				</p>
				<p style="color:#999;font-size:12px;margin-top:20px;">%12$s</p>
			</div>',
			esc_attr( $accent ),
			$urgent ? esc_html__( 'Priorité haute', 'naya' ) : esc_html__( 'Opportunité', 'naya' ),
			esc_html( $titre ),
			esc_html__( 'Coordonnées', 'naya' ),
			$contact,
			esc_html__( 'Situation', 'naya' ),
			esc_html( $alert['reason'] ),
			esc_html__( 'Transcription', 'naya' ),
			$transcript,
			esc_url( $stats_url ),
			esc_html__( 'Ouvrir le tableau de bord', 'naya' ),
			sprintf(
				/* translators: 1: numéro de conversation, 2: nom de l'assistant, 3: nom du site */
				esc_html__( 'Conversation n°%1$d — détectée par %2$s sur %3$s', 'naya' ),
				$conversation_id,
				esc_html( $bot_name ),
				esc_html( get_bloginfo( 'name' ) )
			)
		);
	}
}
