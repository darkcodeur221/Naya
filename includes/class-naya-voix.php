<?php
/**
 * Mode vocal : le visiteur parle, Naya répond à voix haute.
 *
 * Une conversation vocale ne pardonne pas l'attente. À l'écrit, trois
 * secondes de réflexion passent inaperçues ; à l'oral, c'est un blanc gênant
 * et le visiteur raccroche. Tout ici vise donc le temps de réponse.
 *
 * Le levier principal n'est pas technique mais rédactionnel : une réponse
 * vocale de deux phrases arrive deux à trois fois plus vite qu'un paragraphe,
 * parce que le modèle écrit moins de mots. On plafonne donc la longueur, et
 * on l'exige dans le prompt.
 *
 * Le reste se joue dans le navigateur : la reconnaissance vocale et la voix
 * de synthèse sont natives (aucun serveur, aucune latence réseau), et la
 * réponse est lue phrase par phrase dès qu'elle arrive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Voix {

	/**
	 * Plafond de longueur en mode vocal. Deux ou trois phrases tiennent
	 * largement dedans ; au-delà, personne n'écoute jusqu'au bout.
	 */
	const MAX_TOKENS = 220;

	public static function active( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'naya_settings', array() );
		}
		return ! empty( $settings['voice_enabled'] );
	}

	/**
	 * Consignes de rédaction parlée, ajoutées seulement quand le visiteur
	 * a posé sa question à la voix.
	 */
	public static function instructions( $settings ) {
		$p  = "\n\n<reponse_vocale>\n";
		$p .= "Cette question a été POSÉE À LA VOIX, et ta réponse va être LUE À VOIX HAUTE. Écris pour l'oreille, pas pour l'œil.\n";
		$p .= "- DEUX PHRASES, trois au maximum. C'est une conversation parlée : on ne récite pas un catalogue.\n";
		$p .= "- Aucune mise en forme : pas d'astérisques, pas de tirets de liste, pas de titres, pas d'émojis.\n";
		$p .= "- N'écris JAMAIS d'adresse web ni de lien markdown : ça se lit « crochet h t t p p deux points » et c'est insupportable. "
			. "Dis plutôt « je vous mets le lien juste en dessous » — le lien s'affichera à l'écrit de lui-même.\n";
		$p .= "- Les prix se disent simplement : « quinze mille francs », jamais « 15 000 F CFA ».\n";
		$p .= "- Une seule question à la fin, courte, pour que le visiteur puisse répondre d'un mot.\n";
		$p .= "- Si plusieurs produits conviennent, cite-en DEUX au maximum, avec leur prix.\n";
		$p .= "- Parle comme un vendeur au téléphone : chaleureux, direct, sans formule administrative.\n";
		$p .= "</reponse_vocale>";

		return $p;
	}

	/**
	 * Réglages transmis au navigateur.
	 */
	public static function config( $settings ) {
		return array(
			'enabled' => (int) self::active( $settings ),
			'lang'    => ! empty( $settings['voice_lang'] ) ? $settings['voice_lang'] : 'fr-FR',
			'rate'    => isset( $settings['voice_rate'] ) ? (float) $settings['voice_rate'] : 1.05,
			'hands'   => ! empty( $settings['voice_hands_free'] ) ? 1 : 0,
		);
	}
}
