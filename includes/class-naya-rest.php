<?php
/**
 * Endpoints REST : /naya/v1/chat, /naya/v1/conversations, /naya/v1/history.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'naya/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'chat' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'args'                => array(
				'message'         => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'sanitize_message' ),
				),
				'conversation_id' => array(
					'required' => false,
					'type'     => 'integer',
				),
			),
		) );

		register_rest_route( 'naya/v1', '/event', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'event' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'args'                => array(
				'event' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => Naya_Stats::EVENTS,
				),
			),
		) );

		register_rest_route( 'naya/v1', '/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'conversations' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
		) );

		register_rest_route( 'naya/v1', '/conversations/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'history' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_conversation' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
			),
		) );
	}

	public static function check_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'naya_forbidden', __( 'Session expirée, rechargez la page.', 'naya' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function sanitize_message( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		return trim( mb_substr( $value, 0, 4000 ) );
	}

	/**
	 * Anti-abus simple : 20 requêtes par 5 minutes et par visiteur.
	 */
	private static function rate_limited() {
		$key   = 'naya_rl_' . md5( Naya_Conversations::session_key() );
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return true;
		}
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	public static function chat( WP_REST_Request $request ) {
		// Bouclier anti-bots : honeypot, user-agent, origine, limites par IP.
		$shield = Naya_Security::check( $request );
		if ( is_wp_error( $shield ) ) {
			return $shield;
		}

		$message = $request->get_param( 'message' );
		if ( '' === $message ) {
			return new WP_Error( 'naya_empty_message', __( 'Message vide.', 'naya' ), array( 'status' => 400 ) );
		}

		if ( self::rate_limited() ) {
			return new WP_Error( 'naya_rate_limited', __( 'Trop de messages, patientez quelques minutes.', 'naya' ), array( 'status' => 429 ) );
		}

		$conversation_id = (int) $request->get_param( 'conversation_id' );

		if ( $conversation_id ) {
			if ( ! Naya_Conversations::owns( $conversation_id ) ) {
				return new WP_Error( 'naya_forbidden', __( 'Conversation introuvable.', 'naya' ), array( 'status' => 403 ) );
			}
		} else {
			$conversation_id = Naya_Conversations::create();
		}

		// Mémoriser le message utilisateur, puis rejouer le contexte à l'IA.
		Naya_Conversations::add_message( $conversation_id, 'user', $message );
		$context = Naya_Conversations::context( $conversation_id );

		$reply = Naya_DeepSeek::chat( $context );

		if ( is_wp_error( $reply ) ) {
			$status = $reply->get_error_data();
			return new WP_Error(
				$reply->get_error_code(),
				$reply->get_error_message(),
				array( 'status' => is_array( $status ) && isset( $status['status'] ) ? $status['status'] : 500 )
			);
		}

		// L'IA a-t-elle signalé une conversation intéressante ? (balise retirée avant affichage)
		list( $reply, $notify_reason ) = Naya_Notify::extract( $reply );

		Naya_Conversations::add_message( $conversation_id, 'assistant', $reply );

		if ( null !== $notify_reason ) {
			Naya_Notify::maybe_send( $conversation_id, $notify_reason );
		}

		return rest_ensure_response( array(
			'conversation_id' => $conversation_id,
			'reply'           => $reply,
		) );
	}

	/**
	 * Trace un événement d'usage (ouverture du widget, clic WhatsApp…).
	 * Plafonné à 60/heure par visiteur pour éviter le bruit.
	 */
	public static function event( WP_REST_Request $request ) {
		$key   = 'naya_evt_' . md5( Naya_Conversations::session_key() );
		$count = (int) get_transient( $key );
		if ( $count < 60 ) {
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			Naya_Stats::record( $request->get_param( 'event' ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function conversations() {
		$list = Naya_Conversations::list_for_visitor();
		return rest_ensure_response( array_map( function ( $c ) {
			return array(
				'id'         => (int) $c->id,
				'title'      => $c->title ? $c->title : __( 'Nouvelle conversation', 'naya' ),
				'updated_at' => $c->updated_at,
			);
		}, $list ) );
	}

	public static function history( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Naya_Conversations::owns( $id ) ) {
			return new WP_Error( 'naya_forbidden', __( 'Conversation introuvable.', 'naya' ), array( 'status' => 403 ) );
		}
		$messages = Naya_Conversations::messages( $id );
		return rest_ensure_response( array_map( function ( $m ) {
			return array(
				'role'       => $m->role,
				'content'    => $m->content,
				'created_at' => $m->created_at,
			);
		}, $messages ) );
	}

	public static function delete_conversation( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Naya_Conversations::owns( $id ) ) {
			return new WP_Error( 'naya_forbidden', __( 'Conversation introuvable.', 'naya' ), array( 'status' => 403 ) );
		}
		Naya_Conversations::delete( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
