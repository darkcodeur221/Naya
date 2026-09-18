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
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache' ), 10, 3 );
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

		// Jeton de sécurité frais. Indispensable avec un cache de page : le
		// jeton inscrit dans le HTML expire au bout de 24 h, alors que la
		// page en cache, elle, continue d'être servie des jours durant.
		register_rest_route( 'naya/v1', '/nonce', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'fresh_nonce' ),
			'permission_callback' => '__return_true',
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

		register_rest_route( 'naya/v1', '/conversations/(?P<id>\d+)/rate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rate' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'args'                => array(
				'rating'  => array(
					'required' => true,
					'type'     => 'integer',
					'minimum'  => 1,
					'maximum'  => 5,
				),
				'comment' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
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

	/**
	 * Renvoie un jeton valide. La réponse ne doit jamais être mise en cache,
	 * sans quoi le problème qu'elle résout se reproduirait à l'identique.
	 */
	public static function fresh_nonce() {
		nocache_headers();
		return rest_ensure_response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Contrôle d'accès des routes du chat.
	 *
	 * - Compte connecté : jeton de sécurité exigé. Une requête forgée pourrait
	 *   agir au nom de la personne, le jeton l'en empêche.
	 * - Visiteur anonyme : pas de jeton. Inscrit dans une page mise en cache,
	 *   il expire alors que la page continue d'être servie, et bloque tout
	 *   envoi — c'est exactement ce qui empêchait les visiteurs de discuter.
	 *   Pour eux, il ne protégeait d'ailleurs de rien : il est lisible dans le
	 *   HTML et il n'y a aucun compte à détourner. La protection contre les
	 *   requêtes forgées passe par la vérification d'origine, qui n'a aucun
	 *   état et ne peut donc pas être figée par un cache ; la protection
	 *   contre les abus, par le bouclier Naya_Security.
	 */
	public static function check_nonce( $request ) {
		if ( ! is_user_logged_in() ) {
			if ( Naya_Security::is_same_origin() ) {
				return true;
			}
			return new WP_Error( 'naya_forbidden', __( 'Requête refusée.', 'naya' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// Code distinct : le client comprend qu'il doit demander un jeton
		// frais et rejouer sa requête, sans afficher d'échec.
		return new WP_Error(
			'naya_stale_nonce',
			__( 'Jeton de sécurité expiré.', 'naya' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Aucune réponse du chat ne doit jamais être mise en cache : chacune est
	 * propre à un visiteur et à un instant.
	 */
	public static function no_cache( $response, $server, $request ) {
		if ( 0 !== strpos( $request->get_route(), '/naya/v1' ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}

		// API officielle de LiteSpeed Cache pour exclure la réponse courante.
		do_action( 'litespeed_control_set_nocache', 'Naya : réponse de chat' );

		return $response;
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

		// Un visiteur n'est reconnu que par son cookie de session. S'il l'a
		// perdu (navigation privée, cookie non conservé, cache mal réglé), la
		// conversation n'est plus reconnue comme la sienne. Plutôt que de le
		// bloquer, on ouvre une nouvelle conversation avec son message : il
		// perd le fil précédent, mais il n'est jamais empêché de parler.
		if ( ! $conversation_id || ! Naya_Conversations::owns( $conversation_id ) ) {
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

		// L'IA a-t-elle signalé une situation à traiter ? (balise retirée avant affichage)
		list( $reply, $alert ) = Naya_Notify::extract( $reply );

		Naya_Conversations::add_message( $conversation_id, 'assistant', $reply );

		if ( null !== $alert ) {
			Naya_Notify::maybe_send( $conversation_id, $alert );
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

	/**
	 * Notation de l'agent (1 à 5 étoiles) + commentaire facultatif.
	 */
	public static function rate( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Naya_Conversations::owns( $id ) ) {
			return new WP_Error( 'naya_forbidden', __( 'Conversation introuvable.', 'naya' ), array( 'status' => 403 ) );
		}

		$rating  = (int) $request->get_param( 'rating' );
		$comment = trim( mb_substr( (string) $request->get_param( 'comment' ), 0, 1000 ) );
		$first   = ( 0 === Naya_Conversations::rating( $id ) );

		Naya_Conversations::rate( $id, $rating, $comment );

		// Une note faible mérite votre attention immédiate.
		if ( $first && $rating <= 2 ) {
			Naya_Notify::low_rating_alert( $id, $rating, $comment );
		}

		return rest_ensure_response( array( 'ok' => true ) );
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
