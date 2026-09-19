<?php
/**
 * Catalogue produits : Naya connaît tout ce qui est en vente sur le site.
 *
 * Mettre des milliers de produits dans chaque requête serait lent, coûteux et
 * noierait l'IA. On procède donc comme un vendeur qui connaît sa boutique :
 *
 *  1. un index léger de TOUS les produits publiés (titre, catégories,
 *     étiquettes, attributs, référence) est tenu en cache ;
 *  2. à chaque question, on y cherche les produits pertinents — en tenant
 *     compte d'un budget (« moins de 20 000 »), des promotions, du tri
 *     (« le moins cher ») et du message précédent (« et en noir ? ») ;
 *  3. seuls ces produits sont relus en direct depuis WooCommerce — prix,
 *     promo, stock, tailles et couleurs disponibles — et transmis à l'IA.
 *
 * Le prix et le stock viennent donc toujours de la fiche à l'instant T.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Catalog {

	const INDEX_KEY  = 'naya_catalog_index';
	const INDEX_TTL  = 6 * HOUR_IN_SECONDS;
	const MAX_INDEX  = 6000; // au-delà, les plus récents d'abord
	const MAX_SHOWN  = 8;    // fiches détaillées envoyées à l'IA

	/** Mots trop courants pour aider à trouver un produit. */
	const STOPWORDS = 'le la les l un une des du de d au aux a et ou en dans sur pour par avec sans ce ces cet cette ca mon ma mes ton ta tes son sa ses notre nos votre vos leur leurs je j tu il elle on nous vous ils elles me moi te toi se s qui que qu quoi quel quelle quels quelles est sont ai as avez avons ont suis etes etre avoir fait faire peut peux pouvez puis veux voudrais voulez souhaite souhaiterais cherche recherche besoin trouver acheter commander commande commandes passer prendrey t ce c n ne combien prix cout coute coutent tarif tarifs disponible disponibles dispo stock produit produits article articles chose truc bonjour bonsoir salut merci svp stp please oui non pas plus moins tres bien bon bonne cher chere chers fcfa cfa franc francs xof euro euros eur taille tailles couleur couleurs pointure modele modeles comment pourquoi quand livraison livrer livrez delai delais paiement payer contacter contact joindre adresse horaire horaires site boutique magasin vendez vendre vend proposez propose montrer montrez voir autre autres encore aussi the and for with of to is are do you have what how much any';

	public static function init() {
		// Un produit créé, modifié ou supprimé : l'index est à refaire.
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_if_product' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_if_product' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'flush' ) );
		add_action( 'edited_product_tag', array( __CLASS__, 'flush' ) );
		// Import CSV WooCommerce : une seule reconstruction à la fin.
		add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'flush' ) );
	}

	public static function available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public static function flush() {
		delete_transient( self::INDEX_KEY );
	}

	public static function flush_if_product( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			self::flush();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Index                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Index compact : une entrée par produit visible en boutique.
	 * Construit en quatre requêtes SQL, sans charger un seul objet produit.
	 *
	 * @return array[] id => [ t: titre normalisé, k: termes normalisés,
	 *                         x: extrait normalisé, s: réf., p: prix, v: ventes, o: en stock ]
	 */
	public static function index() {
		$index = get_transient( self::INDEX_KEY );
		if ( is_array( $index ) ) {
			return $index;
		}

		global $wpdb;
		$index = array();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_excerpt FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status = 'publish' AND post_password = ''
			 ORDER BY post_date DESC LIMIT %d",
			self::MAX_INDEX
		) );

		if ( ! $rows ) {
			set_transient( self::INDEX_KEY, $index, self::INDEX_TTL );
			return $index;
		}

		foreach ( $rows as $r ) {
			$index[ (int) $r->ID ] = array(
				't' => self::normalize( $r->post_title ),
				'k' => '',
				'x' => self::normalize( wp_html_excerpt( wp_strip_all_tags( $r->post_excerpt ), 200, '' ) ),
				's' => '',
				'p' => null,
				'v' => 0,
				'o' => 1,
			);
		}

		$ids_sql = implode( ',', array_map( 'intval', array_keys( $index ) ) );

		// Prix, référence, stock, ventes.
		$metas = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ($ids_sql) AND meta_key IN ('_price','_sku','_stock_status','total_sales')" // phpcs:ignore WordPress.DB.PreparedSQL -- entiers
		);
		foreach ( (array) $metas as $m ) {
			$id = (int) $m->post_id;
			switch ( $m->meta_key ) {
				case '_price':
					// Produit variable : une ligne _price par variation, on garde le minimum.
					if ( '' !== $m->meta_value && ( null === $index[ $id ]['p'] || (float) $m->meta_value < $index[ $id ]['p'] ) ) {
						$index[ $id ]['p'] = (float) $m->meta_value;
					}
					break;
				case '_sku':
					$index[ $id ]['s'] = self::normalize( $m->meta_value );
					break;
				case '_stock_status':
					$index[ $id ]['o'] = 'outofstock' === $m->meta_value ? 0 : 1;
					break;
				case 'total_sales':
					$index[ $id ]['v'] = (int) $m->meta_value;
					break;
			}
		}

		// Catégories, étiquettes, attributs (pa_couleur, pa_taille…) et
		// visibilité : un produit masqué du catalogue n'est jamais proposé.
		$terms = $wpdb->get_results(
			"SELECT tr.object_id, tt.taxonomy, t.name, t.slug
			 FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tr.object_id IN ($ids_sql)
			   AND ( tt.taxonomy IN ('product_cat','product_tag','product_brand','pwb-brand','product_visibility')
			         OR tt.taxonomy LIKE 'pa\\_%' )" // phpcs:ignore WordPress.DB.PreparedSQL -- entiers
		);
		$hidden = array();
		foreach ( (array) $terms as $t ) {
			$id = (int) $t->object_id;
			if ( 'product_visibility' === $t->taxonomy ) {
				if ( 'exclude-from-catalog' === $t->slug || 'exclude-from-search' === $t->slug ) {
					$hidden[ $id ] = ( isset( $hidden[ $id ] ) ? $hidden[ $id ] : 0 ) + 1;
				}
				continue;
			}
			$index[ $id ]['k'] .= ' ' . self::normalize( $t->name );
		}
		// Masqué des deux (« caché ») : hors catalogue.
		foreach ( $hidden as $id => $n ) {
			if ( $n >= 2 ) {
				unset( $index[ $id ] );
			}
		}

		set_transient( self::INDEX_KEY, $index, self::INDEX_TTL );
		return $index;
	}

	/** Nombre de produits connus de Naya (affiché dans les réglages). */
	public static function count() {
		return self::available() ? count( self::index() ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Recherche                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Bloc de prompt avec les produits correspondant à la question.
	 *
	 * @param array $messages Historique [['role'=>…, 'content'=>…], …], le dernier étant le message du visiteur.
	 */
	public static function context_for( array $messages ) {
		if ( ! self::available() ) {
			return '';
		}

		// Message actuel, et le précédent pour les relances (« et en noir ? »).
		$user = array();
		for ( $i = count( $messages ) - 1; $i >= 0 && count( $user ) < 2; $i-- ) {
			if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
				$user[] = (string) $messages[ $i ]['content'];
			}
		}
		if ( ! $user ) {
			return '';
		}

		$query = self::parse( $user[0] );
		$prev  = isset( $user[1] ) ? self::parse( $user[1] ) : null;

		$ids = self::search( $query, $prev );

		// Rien de pertinent et pas de demande chiffrée : on n'encombre pas le prompt.
		if ( ! $ids ) {
			if ( ! $query['words'] ) {
				return '';
			}
			return "\n\n<catalogue_recherche>\nRecherche automatique dans le catalogue pour « " . implode( ' ', $query['words'] ) . " » : aucun produit correspondant."
				. "\nSi le visiteur cherche un produit, n'en propose AUCUN d'inventé : dis que tu ne le trouves pas en boutique, demande une précision ou donne ce lien de recherche : "
				. self::search_url( $query['words'] )
				. "\nSi sa question ne porte pas sur un produit, ignore ce bloc.\n</catalogue_recherche>";
		}

		$lines = array();
		foreach ( $ids as $id ) {
			$card = self::card( $id );
			if ( $card ) {
				$lines[] = $card;
			}
		}
		if ( ! $lines ) {
			return '';
		}

		$intro = 'Fiches extraites EN DIRECT du catalogue d\'après la question du visiteur (prix et stock exacts à cet instant). Sers-t\'en seulement si elles répondent à sa demande :';
		$out   = "\n\n<catalogue_recherche>\n" . $intro . "\n" . implode( "\n", $lines );
		if ( $query['words'] ) {
			$out .= "\nTous les résultats sur le site : " . self::search_url( $query['words'] );
		}
		$out .= "\nConsignes : cite le prix exact et la disponibilité, avec le lien de la fiche. Si le visiteur hésite entre plusieurs produits, propose-en 2 ou 3 au maximum. Un produit « rupture » ne peut pas être promis : propose une alternative en stock. N'invente jamais un produit, une taille, une couleur ou un prix absent de ces fiches.";
		$out .= "\n</catalogue_recherche>";

		return $out;
	}

	/**
	 * Décompose un message : mots utiles, budget, promo, tri.
	 */
	public static function parse( $text ) {
		$norm = self::normalize( $text );
		$q    = array( 'words' => array(), 'min' => null, 'max' => null, 'sale' => false, 'sort' => '', 'norm' => $norm );

		$num = '(\d{1,3}(?:[ .\x{202F}\x{00A0}]?\d{3})+|\d+(?:[.,]\d+)?)\s*(k|mille)?';

		if ( preg_match( '/entre\s+' . $num . '\s*(?:fcfa|cfa|f)?\s+et\s+' . $num . '/u', $norm, $m ) ) {
			$q['min'] = self::amount( $m[1], isset( $m[2] ) ? $m[2] : '' );
			$q['max'] = self::amount( $m[3], isset( $m[4] ) ? $m[4] : '' );
		} else {
			if ( preg_match( '/(?:moins de|max(?:imum)?|pas plus de|jusqu a|en dessous de|budget(?: de)?|inferieur a|<)\s*' . $num . '/u', $norm, $m ) ) {
				$q['max'] = self::amount( $m[1], isset( $m[2] ) ? $m[2] : '' );
			}
			if ( preg_match( '/(?:plus de|min(?:imum)?|a partir de|au dessus de|superieur a|>)\s*' . $num . '/u', $norm, $m ) ) {
				$q['min'] = self::amount( $m[1], isset( $m[2] ) ? $m[2] : '' );
			}
		}

		$promo  = '/\b(promos?|promotions?|soldes?|reductions?|remises?|offres? speciales?|bons? plans?|en solde)\b/u';
		$asc    = '/\b(le |la |les )?(moins cher|moins chere|moins chers|pas cher|pas chere|pas chers|petits? prix|economiques?|abordables?|moins couteux)\b/u';
		$desc   = '/\b(le |la |les )?(plus cher|plus chere|plus chers|haut de gamme|premium|meilleure qualite)\b/u';
		$ventes = '/\b(populaires?|meilleures? ventes?|best sellers?|plus vendus?|tendances?)\b/u';

		if ( preg_match( $promo, $norm ) ) {
			$q['sale'] = true;
		}
		if ( preg_match( $asc, $norm ) ) {
			$q['sort'] = 'asc';
		} elseif ( preg_match( $desc, $norm ) ) {
			$q['sort'] = 'desc';
		} elseif ( preg_match( $ventes, $norm ) ) {
			$q['sort'] = 'sales';
		}

		// Ces expressions ont servi de filtre : ce ne sont pas des produits.
		// Les montants non plus.
		$reste = preg_replace( array( $promo, $asc, $desc, $ventes, '/\d[\d ]*/u' ), ' ', $norm );
		$stop  = array_flip( explode( ' ', self::STOPWORDS ) );
		foreach ( preg_split( '/\s+/', $reste, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( isset( $stop[ $w ] ) || strlen( $w ) < 2 ) {
				continue;
			}
			$q['words'][] = self::stem( $w );
		}
		$q['words'] = array_values( array_unique( $q['words'] ) );

		return $q;
	}

	/**
	 * Classe les produits de l'index pour une requête.
	 *
	 * @return int[] Identifiants, du plus pertinent au moins pertinent.
	 */
	public static function search( array $q, $prev = null ) {
		$index = self::index();
		if ( ! $index ) {
			return array();
		}

		// Relance courte (« et en rouge ? », « moins cher ? ») : le message
		// précédent donne le sujet et le budget, l'actuel la précision.
		$relance    = $prev && count( $q['words'] ) <= 2;
		$prev_words = $relance ? array_values( array_diff( $prev['words'], $q['words'] ) ) : array();
		if ( $relance ) {
			foreach ( array( 'min', 'max', 'sale' ) as $k ) {
				if ( ! $q[ $k ] && $prev[ $k ] ) {
					$q[ $k ] = $prev[ $k ];
				}
			}
		}

		$filtre = null !== $q['min'] || null !== $q['max'] || $q['sale'] || $q['sort'];
		if ( ! $q['words'] && ! $prev_words && ! $filtre ) {
			return array();
		}

		// 1. Produits qui répondent au message actuel.
		// 2. Sinon, ceux du sujet précédent (« et en violet ? » sans violet en
		//    boutique) : l'IA verra les couleurs réellement disponibles.
		// 3. Sinon, une mention dans la description suffit (« smartphone »
		//    absent des titres mais présent dans le texte).
		$scores = self::score_all( $index, $q, $prev_words, true, 3 );
		if ( ! $scores && $prev_words ) {
			$scores = self::score_all( $index, $q, $prev_words, false, 3 );
		}
		if ( ! $scores && $q['words'] ) {
			$scores = self::score_all( $index, $q, $prev_words, true, 1 );
		}
		if ( ! $scores ) {
			return array();
		}

		if ( $q['words'] || $prev_words ) {
			// Des produits contiennent tous les mots (« téléphone samsung ») :
			// les autres ne sont que des voisins, on les écarte.
			$max_hits = max( wp_list_pluck( $scores, 'h' ) );
			if ( count( $q['words'] ) > 1 && count( $q['words'] ) <= $max_hits ) {
				$scores = array_filter( $scores, function ( $r ) use ( $max_hits ) {
					return $r['h'] === $max_hits;
				} );
			}
			// Correspondance nettement plus faible que la meilleure (le mot
			// n'apparaît qu'en fin de titre : « Pochette pour téléphone »
			// face aux téléphones du rayon) : écartée.
			$max_w  = max( wp_list_pluck( $scores, 'w' ) );
			$scores = array_filter( $scores, function ( $r ) use ( $max_w ) {
				return $r['w'] >= 0.75 * $max_w;
			} );
		}

		$rang = wp_list_pluck( $scores, 'r' );
		arsort( $rang );
		$ids = array_keys( $rang );

		// Tri demandé explicitement : sur les candidats les plus pertinents.
		if ( 'asc' === $q['sort'] || 'desc' === $q['sort'] ) {
			usort( $ids, function ( $a, $b ) use ( $index, $q ) {
				// Les ruptures en dernier, quel que soit le prix.
				if ( $index[ $a ]['o'] !== $index[ $b ]['o'] ) {
					return $index[ $b ]['o'] - $index[ $a ]['o'];
				}
				$pa = null === $index[ $a ]['p'] ? PHP_INT_MAX : $index[ $a ]['p'];
				$pb = null === $index[ $b ]['p'] ? PHP_INT_MAX : $index[ $b ]['p'];
				return 'asc' === $q['sort'] ? $pa <=> $pb : $pb <=> $pa;
			} );
		} elseif ( 'sales' === $q['sort'] ) {
			usort( $ids, function ( $a, $b ) use ( $index ) {
				return $index[ $b ]['v'] - $index[ $a ]['v'];
			} );
		}

		return array_slice( $ids, 0, self::MAX_SHOWN );
	}

	/**
	 * @param bool  $exiger_actuel Un produit doit correspondre à au moins un
	 *                             mot du message actuel (quand il en a).
	 * @param float $seuil         Pertinence minimale.
	 * @return array[] id => [ w: pertinence, h: mots trouvés, r: rang final ]
	 */
	private static function score_all( array $index, array $q, array $prev_words, $exiger_actuel, $seuil ) {
		$on_sale = $q['sale'] && function_exists( 'wc_get_product_ids_on_sale' ) ? array_flip( wc_get_product_ids_on_sale() ) : array();

		$scores = array();
		foreach ( $index as $id => $e ) {
			if ( null !== $q['min'] && ( null === $e['p'] || $e['p'] < $q['min'] ) ) {
				continue;
			}
			if ( null !== $q['max'] && ( null === $e['p'] || $e['p'] > $q['max'] ) ) {
				continue;
			}
			if ( $q['sale'] && ! isset( $on_sale[ $id ] ) ) {
				continue;
			}

			$score = 0;
			$hits  = 0;

			// Référence citée telle quelle (« SAM-A15 ») : désignation exacte.
			if ( strlen( $e['s'] ) >= 3 && self::has( $q['norm'], $e['s'] ) ) {
				$score += 10;
				$hits++;
			}
			foreach ( $q['words'] as $w ) {
				$s = self::word_score( $w, $e );
				if ( $s ) {
					$score += $s;
					$hits++;
				}
			}
			// Le sujet précédent compte moins que les mots actuels… sauf s'il
			// n'y en a pas (« le moins cher ? ») : il est alors tout le sujet.
			$poids = $q['words'] ? 0.6 : 1;
			foreach ( $prev_words as $w ) {
				$score += $poids * self::word_score( $w, $e );
			}

			if ( $q['words'] || $prev_words ) {
				if ( $exiger_actuel && $q['words'] && ! $hits ) {
					continue;
				}
				// Par défaut, une mention dans un résumé ne suffit pas à
				// désigner un produit : il faut le titre, un rayon, un attribut.
				if ( $score < $seuil ) {
					continue;
				}
			}

			$rang  = $score;
			$rang += $e['o'] ? 1 : -2;                  // en stock d'abord
			$rang += min( 2, log( 1 + $e['v'] ) / 3 );  // puis les plus vendus
			$scores[ $id ] = array( 'w' => $score, 'h' => $hits, 'r' => $rang );
		}

		return $scores;
	}

	/**
	 * Poids d'un mot (ou de ses synonymes) dans une entrée : début du titre
	 * > rayon, marque, attribut > reste du titre > description.
	 */
	private static function word_score( $w, $e ) {
		$best = 0;
		foreach ( self::variants( $w ) as $v ) {
			$s = 0;
			if ( self::has( self::head( $e['t'] ), $v ) ) {
				$s += 5; // « Samsung Galaxy… » : le produit lui-même
			} elseif ( self::has( $e['t'], $v ) ) {
				$s += 2.5; // « Pochette étanche téléphone » : un accessoire
			}
			if ( self::has( $e['k'], $v ) ) {
				$s += 4;
			}
			if ( ! $s && self::has( $e['x'], $v ) ) {
				$s = 1;
			}
			$best = max( $best, $s );
		}
		return $best;
	}

	/** Les deux premiers mots du titre, qui nomment le produit. */
	private static function head( $title ) {
		return implode( ' ', array_slice( explode( ' ', $title ), 0, 2 ) );
	}

	/**
	 * Synonymes courants : le visiteur dit « smartphone » ou « tel », la
	 * boutique a rangé ses produits dans « Téléphones ».
	 */
	private static function variants( $w ) {
		static $groupes = array(
			array( 'telephone', 'smartphone', 'portable', 'mobile', 'tel', 'phone', 'cellulaire' ),
			array( 'ecouteur', 'oreillette', 'airpod', 'earbud' ),
			array( 'chaussure', 'basket', 'sneaker', 'soulier' ),
			array( 'ordinateur', 'pc', 'laptop', 'notebook', 'macbook' ),
			array( 'television', 'tele', 'tv', 'televiseur' ),
			array( 'montre', 'watch', 'smartwatch' ),
			array( 'maillot', 'jersey' ),
			array( 'sac', 'sacoche', 'besace' ),
			array( 'frigo', 'refrigerateur', 'congelateur' ),
			array( 'clim', 'climatiseur', 'climatisation' ),
		);
		foreach ( $groupes as $g ) {
			if ( in_array( $w, $g, true ) ) {
				return $g;
			}
		}
		return array( $w );
	}

	/** Le mot (ou un mot qui commence par lui) figure-t-il dans le texte ? */
	private static function has( $haystack, $w ) {
		if ( '' === $haystack ) {
			return false;
		}
		// Début de mot : « tel » trouve « telephone », « chaussure » trouve « chaussures ».
		return false !== strpos( ' ' . $haystack, ' ' . $w );
	}

	/* ------------------------------------------------------------------ */
	/* Fiche détaillée                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Fiche d'un produit, relue en direct : c'est elle que l'IA cite.
	 */
	public static function card( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return '';
		}

		$parts = array( '- ' . wp_strip_all_tags( $product->get_name() ) );

		$prix = self::price_text( $product );
		if ( $prix ) {
			$parts[] = 'Prix : ' . $prix;
		}

		$parts[] = 'Stock : ' . self::stock_text( $product );

		$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$parts[] = 'Catégorie : ' . implode( ', ', array_slice( $cats, 0, 3 ) );
		}

		$attrs = self::attributes_text( $product );
		if ( $attrs ) {
			$parts[] = $attrs;
		}

		if ( $product->get_sku() ) {
			$parts[] = 'Réf. ' . $product->get_sku();
		}

		$note = (float) $product->get_average_rating();
		if ( $note > 0 ) {
			$parts[] = sprintf( 'Note %s/5 (%d avis)', number_format_i18n( $note, 1 ), (int) $product->get_review_count() );
		}

		$vendeur = self::vendor( $id );
		if ( $vendeur ) {
			$parts[] = 'Vendeur : ' . $vendeur;
		}

		$desc = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		$desc = trim( preg_replace( '/\s+/', ' ', wp_html_excerpt( wp_strip_all_tags( strip_shortcodes( $desc ) ), 160, '…' ) ) );
		if ( $desc ) {
			$parts[] = $desc;
		}

		$parts[] = 'Lien : ' . get_permalink( $id );

		return implode( ' | ', $parts );
	}

	/** « 15 000 FCFA (au lieu de 20 000 FCFA, promo) » ou « de 5 000 à 9 000 FCFA ». */
	private static function price_text( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			if ( '' === $min ) {
				return '';
			}
			$txt = $min === $max ? self::money( $min ) : 'de ' . self::money( $min ) . ' à ' . self::money( $max );
			return $product->is_on_sale() ? $txt . ' (en promo)' : $txt;
		}

		$price = $product->get_price();
		if ( '' === $price ) {
			return '';
		}
		$txt = self::money( $price );
		if ( $product->is_on_sale() && $product->get_regular_price() ) {
			$txt .= ' (promo, au lieu de ' . self::money( $product->get_regular_price() ) . ')';
		}
		return $txt;
	}

	private static function stock_text( $product ) {
		if ( ! $product->is_in_stock() ) {
			return 'rupture';
		}
		if ( 'onbackorder' === $product->get_stock_status() ) {
			return 'sur commande';
		}
		$qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
		if ( null !== $qty && $qty > 0 && $qty <= 5 ) {
			return 'en stock, plus que ' . (int) $qty;
		}
		return 'en stock';
	}

	/**
	 * Attributs visibles. Pour un produit variable, seules les valeurs
	 * réellement disponibles (variation en stock) sont annoncées : Naya ne
	 * promet pas une taille épuisée.
	 */
	private static function attributes_text( $product ) {
		$dispo = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( array_slice( $product->get_children(), 0, 60 ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || ! $v->is_in_stock() || ! $v->variation_is_visible() ) {
					continue;
				}
				foreach ( $v->get_attributes() as $tax => $slug ) {
					if ( '' === $slug ) {
						continue; // « toute valeur »
					}
					$label = wc_attribute_label( $tax, $product );
					$term  = taxonomy_exists( $tax ) ? get_term_by( 'slug', $slug, $tax ) : false;
					$dispo[ $label ][ $term ? $term->name : $slug ] = true;
				}
			}
		}

		$out = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr->get_visible() && ! $attr->get_variation() ) {
				continue;
			}
			$label = wc_attribute_label( $attr->get_name(), $product );

			if ( $attr->get_variation() && $product->is_type( 'variable' ) ) {
				if ( empty( $dispo[ $label ] ) ) {
					continue;
				}
				$values = array_keys( $dispo[ $label ] );
				$out[]  = $label . ' dispo : ' . implode( ', ', array_slice( $values, 0, 15 ) );
				continue;
			}

			$values = $attr->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) )
				: $attr->get_options();
			if ( $values ) {
				$out[] = $label . ' : ' . implode( ', ', array_slice( array_map( 'strval', $values ), 0, 15 ) );
			}
		}

		return implode( ' | ', $out );
	}

	/** Boutique du vendeur sur une place de marché (Dokan, WCFM). */
	private static function vendor( $id ) {
		$author = (int) get_post_field( 'post_author', $id );
		if ( ! $author ) {
			return '';
		}
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = dokan_get_store_info( $author );
			return ! empty( $info['store_name'] ) ? wp_strip_all_tags( $info['store_name'] ) : '';
		}
		if ( function_exists( 'wcfm_get_vendor_store_name' ) ) {
			return wp_strip_all_tags( (string) wcfm_get_vendor_store_name( $author ) );
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Vue d'ensemble (prompt permanent)                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Ce que la boutique propose, en quelques lignes : rayons et meilleures
	 * ventes. Suffit pour orienter une question vague (« vous vendez quoi ? »).
	 */
	public static function overview() {
		if ( ! self::available() ) {
			return array();
		}

		$lines = array();
		$index = self::index();
		$lines[] = sprintf( 'CATALOGUE : %d produits en ligne. Les fiches précises (prix, stock, tailles) te sont fournies dans <catalogue_recherche> selon la question.', count( $index ) );

		$cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 30,
		) );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$lines[] = 'RAYONS :';
			foreach ( $cats as $c ) {
				if ( 'uncategorized' === $c->slug || 'non-classe' === $c->slug ) {
					continue;
				}
				$lines[] = sprintf( '- %s (%d) — %s', $c->name, $c->count, get_term_link( $c ) );
			}
		}

		// Meilleures ventes : de quoi répondre sans recherche aux questions générales.
		uasort( $index, function ( $a, $b ) {
			return $b['v'] - $a['v'];
		} );
		$top = array();
		foreach ( $index as $id => $e ) {
			if ( ! $e['o'] || $e['v'] <= 0 ) {
				continue;
			}
			$p = wc_get_product( $id );
			if ( $p ) {
				$prix  = self::price_text( $p );
				$top[] = sprintf( '- %s%s — %s', wp_strip_all_tags( $p->get_name() ), $prix ? ' : ' . $prix : '', get_permalink( $id ) );
			}
			if ( count( $top ) >= 10 ) {
				break;
			}
		}
		if ( $top ) {
			$lines[] = 'MEILLEURES VENTES :';
			$lines   = array_merge( $lines, $top );
		}

		return $lines;
	}

	/* ------------------------------------------------------------------ */
	/* Outils                                                               */
	/* ------------------------------------------------------------------ */

	/** Minuscules, sans accents ni ponctuation, espaces simples. */
	public static function normalize( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		// Sans accents, tout est ASCII : strtolower suffit (pas besoin de mbstring).
		$text = strtolower( remove_accents( $text ) );
		$text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
		return trim( $text );
	}

	/** Pluriel naïf : « chaussures » → « chaussure », « bijoux » → « bijou ». */
	private static function stem( $w ) {
		if ( strlen( $w ) > 3 && preg_match( '/[sx]$/', $w ) && ! preg_match( '/(ss|us|is)$/', $w ) ) {
			return substr( $w, 0, -1 );
		}
		return $w;
	}

	/** « 20 000 », « 20.000 », « 20k », « 20 mille » → 20000. */
	private static function amount( $n, $suffix = '' ) {
		$n = preg_replace( '/[ .\x{202F}\x{00A0}]/u', '', $n );
		$n = (float) str_replace( ',', '.', $n );
		if ( $suffix ) {
			$n *= 1000;
		}
		return $n;
	}

	private static function money( $amount ) {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function search_url( array $words ) {
		return add_query_arg(
			array( 's' => rawurlencode( implode( ' ', $words ) ), 'post_type' => 'product' ),
			home_url( '/' )
		);
	}
}
