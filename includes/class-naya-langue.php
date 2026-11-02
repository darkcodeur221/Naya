<?php
/**
 * Dans quelle langue le visiteur écrit-il ?
 *
 * Les clients de Dakar n'écrivent pas un wolof d'école : ils écrivent comme
 * ils parlent, sans accents, avec des mots français au milieu — « ñaata la
 * polo bi ? », « am nga ci 42 ? », « bonjour, bëgg naa un pull noir ».
 * Un détecteur de langue classique se trompe sur ces phrases de cinq mots.
 *
 * Trois indices, par ordre de fiabilité :
 *
 *  1. Les mots qui n'existent qu'en wolof (naka, ñaata, jërëjëf, bëgg) et
 *     les lettres ñ, ë, ó : un seul suffit.
 *  2. Les PAIRES de mots (« am nga », « bëgg naa », « ndax am ») : c'est ce
 *     qui sauve les phrases faites de petits mots ambigus, là où chaque mot
 *     pris isolément ne prouve rien.
 *  3. Les mots franchement français (je, vous, merci, bonjour) servent de
 *     contre-preuve : « merci beaucoup jërëjëf » est un message français
 *     avec un mot wolof, pas un message wolof.
 *
 * Trois réponses : 'wo' (wolof), 'mix' (franwolof), 'fr' (français).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Langue {

	/** Mots-témoins forts : leur seule présence signe le wolof. */
	const FORTS = 'naka nanga nangadef mangi maangi jamm jerejef jereujef jërëjëf diereudieuf
		salamalekum salaamaalekum asalamalekum asalaamaalekum malekumsalam maalekumsalaam
		waaw waw deedeet dedet deet baax bax baaxna
		naata niata nata ñaata jend jënd jenda jaay djaay njeg njëg xaalis khalis
		begg bëgg beug beugg beugue bugg amul amna amoul ameul
		dafa dafay dama damay danga dangay dina dinaa dinga dinanu dinañu duma doo dootul
		laa lanu lañu ngeen nioom ñoom ñuy yow yaw moom
		sama suma samay sunu sunuy seen
		yobbu yóbbu yobou wanni wàññi wagni wannil wàññil jegesi jegesil bayyi bayi
		nekk nekka ndax lool lekk defar defal
		fukk juroom juróom teemeer téeméer junni naari ñaari netti ñetti neenti beneen
		teey rekk sax waay kay niokobok nioko bokk
		baal baalma balma mbaa manke
		loolu looloo lii lile kañ kan fii fale
		buur sedd tang neex rafet yombna jafe jaafe
		ligeey jamono suba tey elek demb
		torop tuuti tuti lutax ndeysaan waaye naa';

	/**
	 * Mots-témoins faibles : wolof très courant, mais qui s'écrit comme un
	 * mot français. On ne conclut jamais sur eux seuls.
	 */
	const FAIBLES = 'ma mi mu na nga nu ni la le ci ak ko bu bi ba bii yi yu am an sa si da du ne
		lan lu ku kii gi mel ana ani di dey def';

	/**
	 * Paires de mots typiquement wolof. Chacune vaut un témoin fort :
	 * « am nga » n'est jamais du français, alors que « am » et « nga »
	 * séparés ne prouvent rien.
	 */
	const PAIRES = 'am nga|am ngeen|am na|am nañu|amul la|ndax am|ndax nga|ndax mën|nga bëgg|nga begg|
		bëgg naa|begg naa|bëgg nga|begg nga|naa ko|nga ko|la ko|ma ko|ko ci|ci lan|lan la|
		nga def|nga am|nga yor|nga wax|mën nga|men nga|mën naa|men naa|
		yobbu ko|def ko|wax ma|won ma|wone ma|jox ma|may ma|
		ci taille|ci pointure|ci kulër|ci kuler|la taille';

	/**
	 * Mots sans ambiguïté possible : s'ils sont là, du français est écrit.
	 * Aucun mot de cette liste ne doit exister en wolof.
	 */
	const FRANCAIS = 'je tu vous nous ils elles mon ton son mes tes ses notre votre leur
		un une des aux
		merci bonjour bonsoir salut coucou svp
		veux voudrais souhaite cherche avez avons avoir est sont été etre être fait faire
		pour avec dans sans chez sur sous pas plus trop très tres
		beaucoup combien comment pourquoi quand quel quelle quels quelles
		cette ces cet celui celle qui que quoi dont
		possible disponible besoin commande commander acheter livraison livrer prix
		bien bonne bonjour merci';

	/**
	 * @return string 'wo' | 'mix' | 'fr'
	 */
	public static function detecter( $texte ) {
		$mots = self::mots( $texte );
		if ( ! $mots ) {
			return 'fr';
		}

		$forts    = array_flip( self::liste( self::FORTS ) );
		$faibles  = array_flip( self::liste( self::FAIBLES ) );
		$francais = array_flip( self::liste( self::FRANCAIS ) );

		$n_forts = 0;
		$n_wo    = 0;
		$n_fr    = 0;

		foreach ( $mots as $mot ) {
			if ( isset( $forts[ $mot ] ) ) {
				$n_forts++;
				$n_wo++;
			} elseif ( isset( $faibles[ $mot ] ) ) {
				$n_wo++;
			} elseif ( isset( $francais[ $mot ] ) ) {
				$n_fr++;
			}
		}

		// Paires de mots : elles valent un témoin fort.
		$paires = array_flip( self::paires() );
		$suite  = count( $mots );
		for ( $i = 0; $i < $suite - 1; $i++ ) {
			if ( isset( $paires[ $mots[ $i ] . ' ' . $mots[ $i + 1 ] ] ) ) {
				$n_forts++;
				$n_wo++;
			}
		}

		// Lettres propres au wolof : un visiteur qui les tape sait ce qu'il écrit.
		if ( preg_match( '/[ñëó]/u', $texte ) ) {
			$n_forts++;
			$n_wo++;
		}

		if ( ! $n_wo ) {
			return 'fr';
		}

		// Que des petits mots ambigus, aucun témoin sûr : c'est du français.
		if ( ! $n_forts ) {
			return 'fr';
		}

		// Aucun mot français reconnu : le visiteur écrit en wolof.
		if ( ! $n_fr ) {
			return 'wo';
		}

		// Les deux langues sont présentes. Le wolof ne l'emporte que s'il
		// domine largement ; sinon c'est du franwolof.
		return ( $n_wo >= 4 * $n_fr ) ? 'wo' : 'mix';
	}

	/**
	 * Langue de la conversation, pas seulement du dernier message.
	 *
	 * « polo », « du 42 », « c'est combien ? » : beaucoup de messages sont
	 * trop courts pour trancher. On garde donc la dernière langue clairement
	 * identifiée — un client qui a ouvert en wolof continue d'être servi en
	 * wolof même quand il tape deux mots.
	 *
	 * @param array $messages [['role'=>…, 'content'=>…], …]
	 */
	public static function detecter_conversation( array $messages ) {
		$langue  = 'fr';
		$dernier = 'fr';
		$long    = false;

		foreach ( $messages as $m ) {
			if ( empty( $m['role'] ) || 'user' !== $m['role'] ) {
				continue;
			}
			$contenu = (string) $m['content'];
			$dernier = self::detecter( $contenu );
			$long    = count( self::mots( $contenu ) ) >= 5;
			if ( 'fr' !== $dernier ) {
				$langue = $dernier;
			}
		}

		// Un retour au français sur une vraie phrase l'emporte : le visiteur
		// a changé de langue, on le suit au lieu de s'accrocher au wolof.
		if ( 'fr' === $dernier && $long ) {
			return 'fr';
		}

		return $langue;
	}

	/**
	 * Le message est-il une simple recherche de produit (« polo »,
	 * « Air Jordan 4 », « Pull noir ») plutôt qu'une phrase ?
	 *
	 * C'est le cas le plus fréquent sur la boutique, et celui qui tue la
	 * conversation : Naya répond, le visiteur s'en va.
	 */
	public static function est_recherche( $texte ) {
		$mots = self::mots( $texte );
		if ( ! $mots || count( $mots ) > 4 ) {
			return false;
		}
		// Une question reste une question, même courte.
		if ( false !== strpos( $texte, '?' ) ) {
			return false;
		}
		$questions = array( 'combien', 'naata', 'niata', 'ñaata', 'comment', 'pourquoi', 'quand', 'ana', 'ndax', 'ou', 'où' );
		return ! array_intersect( $mots, $questions );
	}

	/* ------------------------------------------------------------------ */

	/** Mots du message, sans ponctuation ni accents parasites. */
	private static function mots( $texte ) {
		$texte = strtolower( wp_strip_all_tags( (string) $texte ) );
		$texte = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $texte );
		return preg_split( '/\s+/', trim( $texte ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/** Liste de mots déclarée sur plusieurs lignes -> tableau. */
	private static function liste( $blob ) {
		static $cache = array();
		$cle = md5( $blob );
		if ( ! isset( $cache[ $cle ] ) ) {
			$mots = preg_split( '/\s+/', trim( $blob ), -1, PREG_SPLIT_NO_EMPTY );
			// Les témoins accentués valent aussi sans accents : au clavier,
			// « bëgg » s'écrit le plus souvent « begg ».
			foreach ( $mots as $m ) {
				$sans = remove_accents( $m );
				if ( $sans !== $m ) {
					$mots[] = $sans;
				}
			}
			$cache[ $cle ] = array_values( array_unique( $mots ) );
		}
		return $cache[ $cle ];
	}

	private static function paires() {
		static $paires = null;
		if ( null === $paires ) {
			$paires = array();
			foreach ( explode( '|', preg_replace( '/\s*\|\s*/', '|', trim( self::PAIRES ) ) ) as $p ) {
				$p = trim( preg_replace( '/\s+/', ' ', $p ) );
				if ( '' === $p ) {
					continue;
				}
				$paires[] = $p;
				$sans     = remove_accents( $p );
				if ( $sans !== $p ) {
					$paires[] = $sans;
				}
			}
			$paires = array_values( array_unique( $paires ) );
		}
		return $paires;
	}
}
