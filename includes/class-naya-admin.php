<?php
/**
 * Page de réglages : Réglages → Naya.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NAYA_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=naya' ) ) . '">' . __( 'Réglages', 'naya' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page(
			__( 'Naya — Statistiques', 'naya' ),
			'Naya',
			'manage_options',
			'naya-stats',
			array( 'Naya_Stats', 'render' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page(
			'naya-stats',
			__( 'Naya — Statistiques', 'naya' ),
			__( 'Statistiques', 'naya' ),
			'manage_options',
			'naya-stats',
			array( 'Naya_Stats', 'render' )
		);
		add_submenu_page(
			'naya-stats',
			__( 'Naya — Réglages', 'naya' ),
			__( 'Réglages', 'naya' ),
			'manage_options',
			'naya',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting( 'naya', 'naya_settings', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$current = get_option( 'naya_settings', array() );

		$out = array();
		$out['api_key'] = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		// Champ laissé vide alors qu'une clé existe : on conserve la clé actuelle.
		if ( '' === $out['api_key'] && ! empty( $current['api_key'] ) ) {
			$out['api_key'] = $current['api_key'];
		}

		$allowed_models = array( 'deepseek-chat', 'deepseek-reasoner' );
		$out['model'] = ( isset( $input['model'] ) && in_array( $input['model'], $allowed_models, true ) )
			? $input['model'] : 'deepseek-chat';

		$out['max_tokens']      = isset( $input['max_tokens'] ) ? max( 256, min( 8192, (int) $input['max_tokens'] ) ) : 1024;
		$out['bot_name']        = isset( $input['bot_name'] ) ? sanitize_text_field( $input['bot_name'] ) : 'Naya';
		$out['welcome_message'] = isset( $input['welcome_message'] ) ? sanitize_textarea_field( $input['welcome_message'] ) : '';
		$out['system_prompt']   = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : '';
		$out['primary_color']   = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '#6d28d9';
		$out['secondary_color'] = isset( $input['secondary_color'] ) ? sanitize_hex_color( $input['secondary_color'] ) : '#db2777';
		$out['widget_enabled']  = empty( $input['widget_enabled'] ) ? 0 : 1;
		$out['widget_position'] = ( isset( $input['widget_position'] ) && 'bubble' === $input['widget_position'] ) ? 'bubble' : 'bar';
		$out['bar_offset']      = empty( $input['bar_offset'] ) ? 0 : 1;
		$out['bar_tagline']     = isset( $input['bar_tagline'] ) ? sanitize_text_field( $input['bar_tagline'] ) : '';
		$out['bar_placeholder'] = isset( $input['bar_placeholder'] ) ? sanitize_text_field( $input['bar_placeholder'] ) : '';
		$out['teaser_enabled']  = empty( $input['teaser_enabled'] ) ? 0 : 1;
		$out['teaser_message']  = isset( $input['teaser_message'] ) ? sanitize_text_field( $input['teaser_message'] ) : '';
		$out['teaser_delay']    = isset( $input['teaser_delay'] ) ? max( 1, min( 120, (int) $input['teaser_delay'] ) ) : 8;
		$out['suggestions']     = isset( $input['suggestions'] ) ? sanitize_textarea_field( $input['suggestions'] ) : '';

		$out['notify_enabled'] = empty( $input['notify_enabled'] ) ? 0 : 1;

		// Une ou plusieurs adresses, séparées par des virgules.
		$emails = array();
		if ( ! empty( $input['notify_email'] ) ) {
			foreach ( preg_split( '/[,;]+/', $input['notify_email'] ) as $candidate ) {
				$candidate = sanitize_email( trim( $candidate ) );
				if ( is_email( $candidate ) ) {
					$emails[] = $candidate;
				}
			}
		}
		$out['notify_email'] = $emails ? implode( ', ', array_unique( $emails ) ) : get_option( 'admin_email' );

		$out['knowledge'] = isset( $input['knowledge'] ) ? sanitize_textarea_field( $input['knowledge'] ) : '';
		$out['whatsapp']  = isset( $input['whatsapp'] ) ? preg_replace( '/\D/', '', $input['whatsapp'] ) : '';

		$styles = array_keys( Naya_Playbook::styles() );
		$out['sales_style'] = ( isset( $input['sales_style'] ) && in_array( $input['sales_style'], $styles, true ) )
			? $input['sales_style'] : 'balanced';

		// Le contenu injecté dans le prompt a changé : on reconstruit l'index.
		Naya_Knowledge::flush();

		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = wp_parse_args( get_option( 'naya_settings', array() ), array(
			'api_key' => '', 'model' => 'deepseek-chat', 'max_tokens' => 1024,
			'bot_name' => 'Naya', 'welcome_message' => '', 'system_prompt' => '',
			'primary_color' => '#6d28d9', 'secondary_color' => '#db2777',
			'widget_enabled' => 1, 'suggestions' => '',
			'notify_enabled' => 1, 'notify_email' => get_option( 'admin_email' ),
			'knowledge' => '', 'whatsapp' => '221778002341',
			'teaser_enabled' => 1, 'teaser_delay' => 8,
			'teaser_message' => __( 'Une question ? Je vous réponds tout de suite 👋', 'naya' ),
			'widget_position' => 'bar', 'bar_offset' => 1,
			'bar_tagline' => __( 'Conseillère en ligne', 'naya' ),
			'bar_placeholder' => __( 'Posez votre question, je réponds en direct…', 'naya' ),
			'sales_style' => 'balanced',
		) );

		$page_id  = (int) get_option( 'naya_chat_page_id' );
		$page_url = $page_id ? get_permalink( $page_id ) : '';
		?>
		<div class="wrap">
			<h1>🤖 Naya — Assistant IA</h1>

			<?php if ( $page_url ) : ?>
				<p>
					<?php esc_html_e( 'Page de chat dédiée :', 'naya' ); ?>
					<a href="<?php echo esc_url( $page_url ); ?>" target="_blank"><?php echo esc_html( $page_url ); ?></a>
					— <?php esc_html_e( 'shortcode :', 'naya' ); ?> <code>[naya_chat]</code>
				</p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'naya' ); ?>

				<h2><?php esc_html_e( 'Connexion à DeepSeek', 'naya' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="naya_api_key"><?php esc_html_e( 'Clé API DeepSeek', 'naya' ); ?></label></th>
						<td>
							<input type="password" id="naya_api_key" name="naya_settings[api_key]" value="" class="regular-text"
								placeholder="<?php echo $s['api_key'] ? esc_attr__( '•••••••• (clé enregistrée — laisser vide pour conserver)', 'naya' ) : 'sk-…'; ?>" autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'Obtenez une clé sur', 'naya' ); ?>
								<a href="https://platform.deepseek.com/" target="_blank">platform.deepseek.com</a>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_model"><?php esc_html_e( 'Modèle', 'naya' ); ?></label></th>
						<td>
							<select id="naya_model" name="naya_settings[model]">
								<option value="deepseek-chat" <?php selected( $s['model'], 'deepseek-chat' ); ?>>DeepSeek Chat (recommandé)</option>
								<option value="deepseek-reasoner" <?php selected( $s['model'], 'deepseek-reasoner' ); ?>>DeepSeek Reasoner (raisonnement avancé)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_max_tokens"><?php esc_html_e( 'Longueur max. des réponses (tokens)', 'naya' ); ?></label></th>
						<td><input type="number" id="naya_max_tokens" name="naya_settings[max_tokens]" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="256" max="8192" step="128" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Personnalité', 'naya' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="naya_bot_name"><?php esc_html_e( 'Nom de l\'assistant', 'naya' ); ?></label></th>
						<td><input type="text" id="naya_bot_name" name="naya_settings[bot_name]" value="<?php echo esc_attr( $s['bot_name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_welcome"><?php esc_html_e( 'Message d\'accueil', 'naya' ); ?></label></th>
						<td><textarea id="naya_welcome" name="naya_settings[welcome_message]" rows="2" class="large-text"><?php echo esc_textarea( $s['welcome_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_system"><?php esc_html_e( 'Instructions (prompt système)', 'naya' ); ?></label></th>
						<td>
							<textarea id="naya_system" name="naya_settings[system_prompt]" rows="6" class="large-text"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Décrivez votre activité, vos services, votre ton. Plus c\'est précis, mieux Naya conseille vos visiteurs.', 'naya' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_suggestions"><?php esc_html_e( 'Suggestions rapides (une par ligne)', 'naya' ); ?></label></th>
						<td><textarea id="naya_suggestions" name="naya_settings[suggestions]" rows="3" class="large-text"><?php echo esc_textarea( $s['suggestions'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Connaissances', 'naya' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Naya lit automatiquement vos pages, articles et produits (titres, liens, résumés) pour répondre avec précision et proposer les bons liens. Complétez ci-dessous avec ce qui n\'est pas sur le site : tarifs, offres, FAQ, horaires…', 'naya' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="naya_knowledge"><?php esc_html_e( 'Connaissances complémentaires', 'naya' ); ?></label></th>
						<td>
							<textarea id="naya_knowledge" name="naya_settings[knowledge]" rows="8" class="large-text" placeholder="<?php esc_attr_e( "Exemple :\nSite vitrine 5 pages : à partir de 150 000 FCFA, livré en 2 semaines.\nSite e-commerce : à partir de 400 000 FCFA.\nMaintenance mensuelle : 25 000 FCFA/mois.", 'naya' ); ?>"><?php echo esc_textarea( $s['knowledge'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_whatsapp"><?php esc_html_e( 'WhatsApp (prospects sérieux)', 'naya' ); ?></label></th>
						<td>
							<input type="text" id="naya_whatsapp" name="naya_settings[whatsapp]" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" class="regular-text" placeholder="221778002341" />
							<p class="description"><?php esc_html_e( 'Format international sans + ni espaces. Naya redirige les prospects sérieux vers ce numéro (lien wa.me). Laisser vide pour désactiver.', 'naya' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Posture commerciale', 'naya' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Naya applique une méthode de vente consultative : elle accueille, comprend le besoin, apporte de la valeur, qualifie en douceur, puis propose l\'étape suivante et demande les coordonnées. Choisissez son degré d\'initiative.', 'naya' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Degré d\'initiative', 'naya' ); ?></th>
						<td>
							<?php foreach ( Naya_Playbook::styles() as $key => $label ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="naya_settings[sales_style]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['sales_style'], $key ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Alertes e-mail', 'naya' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Prévenir par e-mail', 'naya' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="naya_settings[notify_enabled]" value="1" <?php checked( $s['notify_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Recevoir une alerte quand Naya repère un contact à rappeler', 'naya' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Naya distingue deux niveaux : 🔥 « à rappeler » (devis ferme, échéance proche, budget annoncé, rendez-vous, client mécontent, coordonnées laissées) et 💡 « piste » (intérêt réel sans urgence). L\'e-mail contient les coordonnées collectées, la situation et la transcription. Une alerte par conversation — renouvelée seulement si la situation s\'aggrave ou si des coordonnées arrivent.', 'naya' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_notify_email"><?php esc_html_e( 'Destinataires', 'naya' ); ?></label></th>
						<td>
							<input type="text" id="naya_notify_email" name="naya_settings[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="large-text" />
							<p class="description"><?php esc_html_e( 'Plusieurs adresses possibles, séparées par des virgules.', 'naya' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Apparence', 'naya' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Widget flottant', 'naya' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="naya_settings[widget_enabled]" value="1" <?php checked( $s['widget_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Afficher la bulle de chat sur tout le site', 'naya' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Emplacement', 'naya' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio" name="naya_settings[widget_position]" value="bar" <?php checked( $s['widget_position'], 'bar' ); ?> />
								<strong><?php esc_html_e( 'Barre en haut de page', 'naya' ); ?></strong> —
								<?php esc_html_e( 'champ de saisie visible en permanence, panneau qui se déploie (recommandé : bien plus de conversations)', 'naya' ); ?>
							</label>
							<label style="display:block;">
								<input type="radio" name="naya_settings[widget_position]" value="bubble" <?php checked( $s['widget_position'], 'bubble' ); ?> />
								<?php esc_html_e( 'Bulle flottante en bas à droite', 'naya' ); ?>
							</label>
						</td>
					</tr>
					<tr class="naya-bar-option">
						<th scope="row"><?php esc_html_e( 'Décaler le contenu du site', 'naya' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="naya_settings[bar_offset]" value="1" <?php checked( $s['bar_offset'], 1 ); ?> />
								<?php esc_html_e( 'Pousser la page vers le bas pour que la barre ne recouvre rien', 'naya' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'À décocher seulement si votre thème gère mal le décalage (même principe que la barre d\'administration WordPress).', 'naya' ); ?></p>
						</td>
					</tr>
					<tr class="naya-bar-option">
						<th scope="row"><label for="naya_bar_tagline"><?php esc_html_e( 'Sous-titre de la barre', 'naya' ); ?></label></th>
						<td><input type="text" id="naya_bar_tagline" name="naya_settings[bar_tagline]" value="<?php echo esc_attr( $s['bar_tagline'] ); ?>" class="regular-text" maxlength="60" /></td>
					</tr>
					<tr class="naya-bar-option">
						<th scope="row"><label for="naya_bar_placeholder"><?php esc_html_e( 'Invitation dans le champ', 'naya' ); ?></label></th>
						<td>
							<input type="text" id="naya_bar_placeholder" name="naya_settings[bar_placeholder]" value="<?php echo esc_attr( $s['bar_placeholder'] ); ?>" class="large-text" maxlength="90" />
							<p class="description"><?php esc_html_e( 'Exemple : « Besoin d\'un devis ? Posez votre question ici… »', 'naya' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Incitation à la conversation', 'naya' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="naya_settings[teaser_enabled]" value="1" <?php checked( $s['teaser_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Afficher une bulle d\'accroche animée pour inviter les visiteurs à écrire', 'naya' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'La bulle apparaît au premier signal d\'intérêt (temps passé, défilement ou intention de sortie), accompagnée d\'un badge et d\'un frétillement du bouton. Une seule fois par visite.', 'naya' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_teaser_message"><?php esc_html_e( 'Message d\'accroche', 'naya' ); ?></label></th>
						<td>
							<input type="text" id="naya_teaser_message" name="naya_settings[teaser_message]" value="<?php echo esc_attr( $s['teaser_message'] ); ?>" class="large-text" maxlength="120" />
							<p class="description"><?php esc_html_e( 'Court et engageant. Exemple : « Besoin d\'un devis ? Je vous réponds en 2 minutes 👋 »', 'naya' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_teaser_delay"><?php esc_html_e( 'Délai d\'apparition (secondes)', 'naya' ); ?></label></th>
						<td><input type="number" id="naya_teaser_delay" name="naya_settings[teaser_delay]" value="<?php echo esc_attr( $s['teaser_delay'] ); ?>" min="1" max="120" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_color1"><?php esc_html_e( 'Couleur principale', 'naya' ); ?></label></th>
						<td><input type="color" id="naya_color1" name="naya_settings[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="naya_color2"><?php esc_html_e( 'Couleur secondaire (dégradé)', 'naya' ); ?></label></th>
						<td><input type="color" id="naya_color2" name="naya_settings[secondary_color]" value="<?php echo esc_attr( $s['secondary_color'] ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
