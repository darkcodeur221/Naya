<?php
/**
 * Stockage des conversations : c'est la mémoire de Naya.
 * Chaque visiteur est identifié par un cookie (ou son compte WordPress),
 * et l'historique est rejoué à l'IA à chaque tour pour garder le contexte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Conversations {

	const COOKIE = 'naya_session';

	/** Nombre de messages d'historique renvoyés à l'IA comme contexte. */
	const CONTEXT_WINDOW = 30;

	/**
	 * Identifie le visiteur : cookie de session, créé si absent.
	 */
	public static function session_key() {
		if ( isset( $_COOKIE[ self::COOKIE ] ) && preg_match( '/^[a-f0-9]{40}$/', $_COOKIE[ self::COOKIE ] ) ) {
			return $_COOKIE[ self::COOKIE ];
		}

		$key = wp_generate_password( 40, false );
		$key = substr( hash( 'sha1', $key . wp_salt() ), 0, 40 );

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $key, array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
		}
		$_COOKIE[ self::COOKIE ] = $key;

		return $key;
	}

	/**
	 * Vérifie que la conversation appartient bien au visiteur courant.
	 */
	public static function owns( $conversation_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT session_key, user_id FROM {$wpdb->prefix}naya_conversations WHERE id = %d",
			$conversation_id
		) );
		if ( ! $row ) {
			return false;
		}
		if ( is_user_logged_in() && (int) $row->user_id === get_current_user_id() ) {
			return true;
		}
		return hash_equals( $row->session_key, self::session_key() );
	}

	public static function create( $title = '' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'naya_conversations', array(
			'session_key' => self::session_key(),
			'user_id'     => is_user_logged_in() ? get_current_user_id() : null,
			'title'       => $title,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function add_message( $conversation_id, $role, $content ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'naya_messages', array(
			'conversation_id' => $conversation_id,
			'role'            => $role,
			'content'         => $content,
			'created_at'      => $now,
		) );
		$wpdb->update(
			$wpdb->prefix . 'naya_conversations',
			array( 'updated_at' => $now ),
			array( 'id' => $conversation_id )
		);

		// Le premier message utilisateur devient le titre de la conversation.
		if ( 'user' === $role ) {
			$conv = $wpdb->get_row( $wpdb->prepare(
				"SELECT title FROM {$wpdb->prefix}naya_conversations WHERE id = %d",
				$conversation_id
			) );
			if ( $conv && '' === $conv->title ) {
				$wpdb->update(
					$wpdb->prefix . 'naya_conversations',
					array( 'title' => wp_html_excerpt( $content, 60, '…' ) ),
					array( 'id' => $conversation_id )
				);
			}
		}
	}

	/**
	 * Messages d'une conversation, du plus ancien au plus récent.
	 */
	public static function messages( $conversation_id, $limit = 0 ) {
		global $wpdb;
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT role, content, created_at FROM {$wpdb->prefix}naya_messages
				 WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
				$conversation_id, $limit
			) );
			return array_reverse( $rows );
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content, created_at FROM {$wpdb->prefix}naya_messages
			 WHERE conversation_id = %d ORDER BY id ASC",
			$conversation_id
		) );
	}

	/**
	 * Historique formaté pour l'API DeepSeek (contexte).
	 */
	public static function context( $conversation_id ) {
		$messages = self::messages( $conversation_id, self::CONTEXT_WINDOW );
		$context  = array();
		foreach ( $messages as $m ) {
			if ( in_array( $m->role, array( 'user', 'assistant' ), true ) ) {
				$context[] = array(
					'role'    => $m->role,
					'content' => $m->content,
				);
			}
		}
		// La conversation envoyée à l'API doit commencer par un message user.
		while ( ! empty( $context ) && 'user' !== $context[0]['role'] ) {
			array_shift( $context );
		}
		return $context;
	}

	/**
	 * Conversations du visiteur courant (pour la page dédiée).
	 */
	public static function list_for_visitor() {
		global $wpdb;
		if ( is_user_logged_in() ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, updated_at FROM {$wpdb->prefix}naya_conversations
				 WHERE user_id = %d OR session_key = %s
				 ORDER BY updated_at DESC LIMIT 50",
				get_current_user_id(), self::session_key()
			) );
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, updated_at FROM {$wpdb->prefix}naya_conversations
			 WHERE session_key = %s ORDER BY updated_at DESC LIMIT 50",
			self::session_key()
		) );
	}

	public static function is_notified( $conversation_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT notified_at FROM {$wpdb->prefix}naya_conversations WHERE id = %d",
			$conversation_id
		) );
	}

	public static function mark_notified( $conversation_id, $reason = '' ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'naya_conversations',
			array(
				'notified_at'   => current_time( 'mysql' ),
				'notify_reason' => mb_substr( (string) $reason, 0, 255 ),
			),
			array( 'id' => $conversation_id )
		);
	}

	/** Note déjà attribuée à cette conversation (0 si aucune). */
	public static function rating( $conversation_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT rating FROM {$wpdb->prefix}naya_conversations WHERE id = %d",
			$conversation_id
		) );
	}

	public static function rate( $conversation_id, $rating, $feedback = '' ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'naya_conversations',
			array(
				'rating'   => max( 1, min( 5, (int) $rating ) ),
				'feedback' => mb_substr( (string) $feedback, 0, 1000 ),
				'rated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);
	}

	public static function delete( $conversation_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'naya_messages', array( 'conversation_id' => $conversation_id ) );
		$wpdb->delete( $wpdb->prefix . 'naya_conversations', array( 'id' => $conversation_id ) );
	}
}
