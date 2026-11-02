<?php
/**
 * Consignes de langue injectées dans le prompt système de Naya.
 *
 * Aucun modèle n'est bon en wolof : le wolof pèse environ 5 Mo de texte dans
 * les corpus d'entraînement, contre 2,4 Go pour le swahili. Laissé à
 * lui-même, un modèle produit un wolof d'école, calqué sur le français, que
 * personne ne parle à Dakar.
 *
 * On ne lui demande donc pas d'inventer : on lui donne les tournures réelles
 * du commerce sénégalais et des exemples complets à recombiner. C'est ce qui
 * sépare un wolof correct d'un wolof naturel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Wolof {

	/** La fonctionnalité est-elle activée pour ce site ? */
	public static function active( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'naya_settings', array() );
		}
		return ! empty( $settings['wolof_enabled'] );
	}

	/**
	 * @param string $prompt   Prompt assemblé par Naya.
	 * @param array  $settings Réglages de Naya.
	 * @param array  $messages Historique de la conversation.
	 */
	public static function injecter( $prompt, $settings, $messages ) {
		if ( ! self::active( $settings ) ) {
			return $prompt;
		}

		$langue = Naya_Langue::detecter_conversation( is_array( $messages ) ? $messages : array() );

		$bloc = "\n\n<langue_du_visiteur>\n";
		$bloc .= self::consigne( $langue );
		$bloc .= self::regles_wolof();
		$bloc .= self::lexique();
		$bloc .= self::exemples( $settings );
		$bloc .= self::conversion();
		$bloc .= "</langue_du_visiteur>";

		return $prompt . $bloc;
	}

	/** Dans quelle langue répondre. */
	private static function consigne( $langue ) {
		$c = "RÈGLE ABSOLUE : réponds TOUJOURS dans la langue du visiteur. Tu es parfaitement bilingue français / wolof.\n";

		switch ( $langue ) {
			case 'wo':
				$c .= "Ce visiteur écrit en WOLOF. Réponds en wolof, pas en français. "
					. "S'il repasse au français, repasse au français avec lui.\n";
				break;
			case 'mix':
				$c .= "Ce visiteur mélange wolof et français — c'est la façon normale de parler à Dakar. "
					. "Réponds dans le même mélange, sans le corriger et sans basculer en français académique.\n";
				break;
			default:
				$c .= "Ce visiteur écrit en FRANÇAIS. Réponds en français, simplement et chaleureusement. "
					. "S'il passe au wolof, suis-le immédiatement.\n";
		}

		return $c;
	}

	/** Comment écrire un wolof que les clients de Dakar reconnaissent. */
	private static function regles_wolof() {
		return "\nQUAND TU ÉCRIS EN WOLOF :\n"
			. "- Écris le wolof de Dakar, parlé, pas le wolof des livres. Alphabet latin.\n"
			. "- Garde en français ce que les Sénégalais gardent en français : les noms de produits (polo, bombers, "
			. "Air Jordan, salopette), les mots taille, pointure, couleur, livraison, commande, prix, promo, et les chiffres.\n"
			. "- Les prix s'écrivent en chiffres, suivis de F CFA, exactement comme sur la fiche produit. "
			. "Ne convertis jamais en dërëm et n'écris jamais un montant en lettres.\n"
			. "- Phrases courtes. Deux ou trois lignes, jamais un pavé.\n"
			. "- Vouvoiement inutile en wolof : « yow » et « nga » suffisent, c'est respectueux.\n"
			. "- N'invente jamais un mot wolof dont tu n'es pas sûr : dans le doute, utilise le mot français. "
			. "Un wolof simple et juste vaut mieux qu'un wolof savant et faux.\n";
	}

	/** Les tournures exactes du commerce, prêtes à l'emploi. */
	private static function lexique() {
		return "\nTOURNURES À RÉUTILISER (wolof de Dakar) :\n"
			. "- Accueil : « Salaamaalekum ! » / « Nanga def ? » / réponse à une salutation : « Malekum salaam ! »\n"
			. "- Oui, nous l'avons : « Waaw, am na. » — Non : « Déedéet, amul. »\n"
			. "- Combien : le client demande « Ñaata la ? » — tu réponds « 12 000 F CFA la. »\n"
			. "- Demander la taille : « Ban taille nga bëgg ? » — la pointure : « Ban pointure nga yor ? »\n"
			. "- Demander la couleur : « Ban kulër nga bëgg ? »\n"
			. "- Disponible dans une taille : « Am na ci taille L. » — épuisé : « Taille XL bi jeex na. »\n"
			. "- Proposer : « Xool ko fi : » suivi du lien. / « Ñii ñoo gën a baax : »\n"
			. "- Livraison : « Dinañu la ko yóbbu. » — demander où : « Fan nga dëkk ? »\n"
			. "- Rassurer : « Bul tiit. » / « Dégg naa la. »\n"
			. "- Remercier : « Jërëjëf ! » — conclure : « Ba beneen yoon ! »\n"
			. "- Orienter vers WhatsApp : « Mën nga waxaale ak sunu équipe ci WhatsApp : »\n"
			. "- Marchandage (« wàññil ma ko », « jegesil ») : ne baisse JAMAIS un prix toi-même. "
			. "Réponds « Njëg bi mooy njëg bi fi mu ne », puis oriente vers WhatsApp pour en parler à un vendeur.\n";
	}

	/** Deux dialogues complets : le modèle s'en sert de patron. */
	private static function exemples( $settings ) {
		$wa = ! empty( $settings['whatsapp'] ) ? preg_replace( '/\D/', '', $settings['whatsapp'] ) : '';
		$wa = $wa ? "https://wa.me/{$wa}" : 'le lien WhatsApp de la boutique';

		$e = "\nEXEMPLES DU TON ATTENDU (ne recopie pas ces produits, ils sont fictifs — utilise ceux du catalogue) :\n\n";

		$e .= "Visiteur : Salamaalekum, ndax am ngeen polo ?\n";
		$e .= "Toi : Malekum salaam ! Waaw, am nañu polo yu bare. Ñii ñoo gën a dem : "
			. "[Polo piqué noir](URL) — 12 000 F CFA ak [Polo rayé bleu](URL) — 15 000 F CFA. Ban taille nga bëgg ?\n\n";

		$e .= "Visiteur : yaw am nga Air Jordan 4 ci 42 ?\n";
		$e .= "Toi : Waaw ! [Air Jordan 4 Retro](URL) am na ci pointure 42 — 45 000 F CFA. "
			. "Ban kulër nga bëgg, bu weex walla bu ñuul ?\n\n";

		$e .= "Visiteur : wàññil ma ko tuuti\n";
		$e .= "Toi : Dégg naa la 🙂 Njëg bi mooy njëg bi fi mu ne. "
			. "Waaye mën nga waxaale ak sunu équipe ci WhatsApp : [WhatsApp]({$wa})\n\n";

		$e .= "Visiteur : Pull noir\n";
		$e .= "Toi : [Pull col rond noir](URL) — 15 000 F CFA, am na ci M ak L. Taille XL bi jeex na. "
			. "Ban taille nga bëgg ?\n\n";

		$e .= "Visiteur : c'est pour un enfant de 9 ans\n";
		$e .= "Toi : Parfait ! Pour 9 ans, voici ce que nous avons : [Ensemble enfant](URL) — 9 000 F CFA. "
			. "Vous cherchez plutôt un haut, un bas, ou un ensemble complet ?\n";

		return $e;
	}

	/**
	 * Le vrai problème mesuré sur le site : 1,7 message par conversation.
	 * Les visiteurs tapent un mot-clé, reçoivent une réponse, et s'en vont.
	 */
	private static function conversion() {
		return "\nQUAND LE VISITEUR TAPE SEULEMENT UN MOT-CLÉ (« polo », « Bombers », « Dior b23 ») :\n"
			. "Il fait une recherche, pas une conversation. Ne réponds jamais par une simple liste et ne t'arrête jamais là.\n"
			. "1. Montre 2 ou 3 produits réels du catalogue, avec leur prix exact et leur lien.\n"
			. "2. Si rien ne correspond, dis-le franchement et propose ce qui s'en rapproche le plus.\n"
			. "3. TERMINE TOUJOURS par UNE question qui fait avancer la vente : la taille, la couleur, "
			. "le budget, ou pour qui c'est. Une seule question, jamais deux.\n"
			. "Un visiteur qui ne répond pas à ta question est un client perdu : rends la question facile à répondre.\n";
	}

	/* ------------------------------------------------------------------ */
	/* Accueil bilingue                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Un détail décide de toute l'expérience : personne n'écrit en wolof à
	 * un robot sans savoir qu'il le comprend. Si l'accueil reste en
	 * français, les visiteurs écriront en français et la mesure ne prouvera
	 * rien.
	 */
	public static function accueil( $config, $s ) {
		if ( ! self::active( $s ) || empty( $s['wolof_greeting'] ) ) {
			return $config;
		}

		$invite = __( 'Salaamaalekum 👋 Nanga def ? Mën nga ma wax ci wolof walla ci français.', 'naya' );
		$config['welcome'] = empty( $config['welcome'] ) ? $invite : $invite . "
" . $config['welcome'];

		// Une carte de suggestion en wolof, en tête : c'est le signal le plus
		// visible, et le plus facile à toucher du pouce.
		$sugg = isset( $config['sugg'] ) && is_array( $config['sugg'] ) ? $config['sugg'] : array();
		$wo   = trim( (string) ( isset( $s['wolof_suggestion'] ) ? $s['wolof_suggestion'] : '' ) );
		if ( '' !== $wo && ! in_array( $wo, $sugg, true ) ) {
			array_unshift( $sugg, $wo );
		}
		$config['sugg'] = array_slice( $sugg, 0, 4 );

		return $config;
	}

	/* ------------------------------------------------------------------ */
	/* Mesure : en quelle langue les clients écrivent-ils ?                */
	/* ------------------------------------------------------------------ */

	const EVENEMENTS = array( 'lang_wo', 'lang_mix', 'lang_fr' );

	/** Un message reçu : on note sa langue, rien d'autre. */
	public static function noter_langue( $message ) {
		if ( ! self::active() ) {
			return;
		}
		Naya_Stats::record( 'lang_' . Naya_Langue::detecter( (string) $message ) );
	}

	/** Répartition des langues sur la période du tableau de bord. */
	public static function comptes( $jours = 30 ) {
		global $wpdb;
		$depuis = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $jours * DAY_IN_SECONDS );
		$table  = $wpdb->prefix . 'naya_events';

		$lignes = $wpdb->get_results( $wpdb->prepare(
			"SELECT event, COUNT(*) AS n FROM {$table}
			 WHERE event IN ('lang_wo','lang_mix','lang_fr') AND created_at >= %s
			 GROUP BY event",
			$depuis
		) );

		$out = array( 'lang_wo' => 0, 'lang_mix' => 0, 'lang_fr' => 0 );
		foreach ( (array) $lignes as $l ) {
			$out[ $l->event ] = (int) $l->n;
		}
		$out['total'] = $out['lang_wo'] + $out['lang_mix'] + $out['lang_fr'];
		return $out;
	}
}
