<?php
/**
 * Tableau de bord statistiques — le pilotage de Naya, façon Intercom/Drift :
 * KPI 30 jours, courbe d'activité, heures de pointe, top questions,
 * leads détectés par l'IA et export CSV.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Naya_Stats {

	const PERIOD_DAYS = 30;

	public static function init() {
		add_action( 'admin_post_naya_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Collecte des événements front (widget ouvert, clic WhatsApp…)       */
	/* ------------------------------------------------------------------ */

	const EVENTS = array( 'widget_open', 'whatsapp_click', 'link_click' );

	public static function record( $event ) {
		global $wpdb;
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}
		$wpdb->insert( $wpdb->prefix . 'naya_events', array(
			'event'       => $event,
			'session_key' => Naya_Conversations::session_key(),
			'created_at'  => current_time( 'mysql' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Requêtes                                                            */
	/* ------------------------------------------------------------------ */

	private static function since() {
		return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::PERIOD_DAYS * DAY_IN_SECONDS );
	}

	public static function kpis() {
		global $wpdb;
		$since = self::since();
		$conv  = $wpdb->prefix . 'naya_conversations';
		$msg   = $wpdb->prefix . 'naya_messages';
		$evt   = $wpdb->prefix . 'naya_events';

		$conversations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE created_at >= %s", $since ) );
		$messages      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msg} WHERE created_at >= %s AND role = 'user'", $since ) );
		$visitors      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session_key) FROM {$conv} WHERE created_at >= %s", $since ) );
		$leads         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$conv} WHERE notified_at IS NOT NULL AND created_at >= %s", $since ) );
		$opens         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$evt} WHERE event = 'widget_open' AND created_at >= %s", $since ) );
		$whatsapp      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$evt} WHERE event = 'whatsapp_click' AND created_at >= %s", $since ) );

		return array(
			'conversations' => $conversations,
			'messages'      => $messages,
			'visitors'      => $visitors,
			'leads'         => $leads,
			'lead_rate'     => $conversations ? round( 100 * $leads / $conversations, 1 ) : 0,
			'engagement'    => $conversations ? round( $messages / $conversations, 1 ) : 0,
			'opens'         => $opens,
			'open_rate'     => $opens ? round( 100 * $conversations / $opens, 1 ) : 0,
			'whatsapp'      => $whatsapp,
		);
	}

	/** Conversations par jour sur la période. */
	public static function per_day() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, COUNT(*) AS n
			 FROM {$wpdb->prefix}naya_conversations
			 WHERE created_at >= %s GROUP BY DATE(created_at)",
			self::since()
		), OBJECT_K );

		$out = array();
		for ( $i = self::PERIOD_DAYS - 1; $i >= 0; $i-- ) {
			$day         = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - $i * DAY_IN_SECONDS );
			$out[ $day ] = isset( $rows[ $day ] ) ? (int) $rows[ $day ]->n : 0;
		}
		return $out;
	}

	/** Messages visiteurs par heure de la journée (heures de pointe). */
	public static function per_hour() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT HOUR(created_at) AS h, COUNT(*) AS n
			 FROM {$wpdb->prefix}naya_messages
			 WHERE created_at >= %s AND role = 'user' GROUP BY HOUR(created_at)",
			self::since()
		), OBJECT_K );

		$out = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$out[ $h ] = isset( $rows[ $h ] ) ? (int) $rows[ $h ]->n : 0;
		}
		return $out;
	}

	/** Les 10 dernières « premières questions » — ce que cherchent les visiteurs. */
	public static function top_questions() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT m.content, m.created_at
			 FROM {$wpdb->prefix}naya_messages m
			 INNER JOIN (
				SELECT conversation_id, MIN(id) AS first_id
				FROM {$wpdb->prefix}naya_messages WHERE role = 'user' GROUP BY conversation_id
			 ) f ON f.first_id = m.id
			 WHERE m.created_at >= %s
			 ORDER BY m.id DESC LIMIT 10",
			self::since()
		) );
	}

	/** Derniers leads détectés par l'IA. */
	public static function latest_leads() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT id, title, notify_reason, notified_at
			 FROM {$wpdb->prefix}naya_conversations
			 WHERE notified_at IS NOT NULL
			 ORDER BY notified_at DESC LIMIT 10"
		);
	}

	/* ------------------------------------------------------------------ */
	/* Export CSV                                                          */
	/* ------------------------------------------------------------------ */

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'naya_export_csv' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'naya' ) );
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT c.id, c.title, c.created_at, c.updated_at, c.notified_at, c.notify_reason,
				(SELECT COUNT(*) FROM {$wpdb->prefix}naya_messages m WHERE m.conversation_id = c.id AND m.role = 'user') AS visitor_messages
			 FROM {$wpdb->prefix}naya_conversations c ORDER BY c.created_at DESC",
			ARRAY_A
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=naya-conversations-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 pour Excel.
		fputcsv( $out, array( 'ID', 'Sujet', 'Créée le', 'Dernier échange', 'Messages visiteur', 'Lead', 'Raison du lead' ), ';' );
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r['id'],
				$r['title'],
				$r['created_at'],
				$r['updated_at'],
				$r['visitor_messages'],
				$r['notified_at'] ? 'Oui' : 'Non',
				$r['notify_reason'],
			), ';' );
		}
		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendu du tableau de bord                                            */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$k         = self::kpis();
		$days      = self::per_day();
		$hours     = self::per_hour();
		$questions = self::top_questions();
		$leads     = self::latest_leads();

		$max_day  = max( 1, max( $days ) );
		$max_hour = max( 1, max( $hours ) );

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=naya_export_csv' ), 'naya_export_csv' );
		?>
		<div class="wrap naya-stats">
			<h1>📊 Naya — Statistiques <span class="naya-period">(30 derniers jours)</span></h1>

			<style>
				.naya-stats .naya-period { font-size: 14px; color: #777; font-weight: 400; }
				.naya-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin: 18px 0 24px; }
				.naya-kpi { background: #fff; border: 1px solid #e2e0ea; border-radius: 12px; padding: 16px 18px; }
				.naya-kpi .n { font-size: 28px; font-weight: 700; color: #1c1a27; line-height: 1.1; }
				.naya-kpi .l { font-size: 12.5px; color: #6b6784; margin-top: 4px; }
				.naya-kpi.hot { background: linear-gradient(135deg, #6d28d9, #db2777); border: none; }
				.naya-kpi.hot .n, .naya-kpi.hot .l { color: #fff; }
				.naya-panels { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 24px; }
				.naya-panel { background: #fff; border: 1px solid #e2e0ea; border-radius: 12px; padding: 18px; }
				.naya-panel h2 { margin: 0 0 14px; font-size: 15px; }
				.naya-chart { display: flex; align-items: flex-end; gap: 3px; height: 160px; }
				.naya-chart .bar { flex: 1; background: linear-gradient(180deg, #8b5cf6, #6d28d9); border-radius: 3px 3px 0 0; min-height: 2px; position: relative; }
				.naya-chart .bar:hover { opacity: 0.8; }
				.naya-chart .bar span {
					position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
					background: #1c1a27; color: #fff; font-size: 11px; padding: 2px 7px; border-radius: 5px;
					white-space: nowrap; display: none; margin-bottom: 4px; z-index: 2;
				}
				.naya-chart .bar:hover span { display: block; }
				.naya-hours { display: flex; align-items: flex-end; gap: 2px; height: 90px; }
				.naya-hours .bar { background: #d8b4fe; }
				.naya-hours-labels, .naya-chart-labels { display: flex; justify-content: space-between; font-size: 11px; color: #999; margin-top: 6px; }
				.naya-list { margin: 0; }
				.naya-list li { border-bottom: 1px solid #f0eef6; padding: 9px 0; font-size: 13px; }
				.naya-list li:last-child { border-bottom: none; }
				.naya-list .meta { color: #999; font-size: 11.5px; display: block; margin-top: 2px; }
				.naya-lead-reason { color: #6d28d9; font-weight: 600; }
				.naya-empty { color: #999; font-style: italic; font-size: 13px; }
				@media (max-width: 1100px) { .naya-panels { grid-template-columns: 1fr; } }
			</style>

			<div class="naya-kpis">
				<div class="naya-kpi"><div class="n"><?php echo esc_html( number_format_i18n( $k['conversations'] ) ); ?></div><div class="l"><?php esc_html_e( 'Conversations', 'naya' ); ?></div></div>
				<div class="naya-kpi"><div class="n"><?php echo esc_html( number_format_i18n( $k['messages'] ) ); ?></div><div class="l"><?php esc_html_e( 'Messages visiteurs', 'naya' ); ?></div></div>
				<div class="naya-kpi"><div class="n"><?php echo esc_html( number_format_i18n( $k['visitors'] ) ); ?></div><div class="l"><?php esc_html_e( 'Visiteurs uniques', 'naya' ); ?></div></div>
				<div class="naya-kpi"><div class="n"><?php echo esc_html( $k['engagement'] ); ?></div><div class="l"><?php esc_html_e( 'Messages / conversation', 'naya' ); ?></div></div>
				<div class="naya-kpi hot"><div class="n"><?php echo esc_html( number_format_i18n( $k['leads'] ) ); ?></div><div class="l"><?php esc_html_e( 'Leads détectés', 'naya' ); ?> · <?php echo esc_html( $k['lead_rate'] ); ?>%</div></div>
				<div class="naya-kpi"><div class="n"><?php echo esc_html( number_format_i18n( $k['whatsapp'] ) ); ?></div><div class="l"><?php esc_html_e( 'Clics WhatsApp', 'naya' ); ?></div></div>
				<div class="naya-kpi"><div class="n"><?php echo esc_html( number_format_i18n( $k['opens'] ) ); ?></div><div class="l"><?php esc_html_e( 'Ouvertures du widget', 'naya' ); ?> · <?php echo esc_html( $k['open_rate'] ); ?>% <?php esc_html_e( 'engagés', 'naya' ); ?></div></div>
			</div>

			<div class="naya-panels">
				<div class="naya-panel">
					<h2><?php esc_html_e( 'Conversations par jour', 'naya' ); ?></h2>
					<div class="naya-chart">
						<?php foreach ( $days as $date => $n ) : ?>
							<div class="bar" style="height: <?php echo esc_attr( max( 2, round( 100 * $n / $max_day ) ) ); ?>%;">
								<span><?php echo esc_html( date_i18n( 'j M', strtotime( $date ) ) . ' — ' . $n ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="naya-chart-labels">
						<span><?php echo esc_html( date_i18n( 'j M', strtotime( array_key_first( $days ) ) ) ); ?></span>
						<span><?php echo esc_html( date_i18n( 'j M', strtotime( array_key_last( $days ) ) ) ); ?></span>
					</div>
				</div>

				<div class="naya-panel">
					<h2><?php esc_html_e( 'Heures de pointe', 'naya' ); ?></h2>
					<div class="naya-hours naya-chart">
						<?php foreach ( $hours as $h => $n ) : ?>
							<div class="bar" style="height: <?php echo esc_attr( max( 2, round( 100 * $n / $max_hour ) ) ); ?>%;">
								<span><?php echo esc_html( sprintf( '%02dh — %d', $h, $n ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="naya-hours-labels"><span>0h</span><span>6h</span><span>12h</span><span>18h</span><span>23h</span></div>
				</div>
			</div>

			<div class="naya-panels">
				<div class="naya-panel">
					<h2>🔥 <?php esc_html_e( 'Derniers leads détectés par l\'IA', 'naya' ); ?></h2>
					<?php if ( $leads ) : ?>
						<ul class="naya-list">
							<?php foreach ( $leads as $lead ) : ?>
								<li>
									<strong><?php echo esc_html( $lead->title ? $lead->title : sprintf( __( 'Conversation n°%d', 'naya' ), $lead->id ) ); ?></strong>
									<?php if ( $lead->notify_reason ) : ?>
										— <span class="naya-lead-reason"><?php echo esc_html( $lead->notify_reason ); ?></span>
									<?php endif; ?>
									<span class="meta"><?php echo esc_html( date_i18n( 'j F Y à H:i', strtotime( $lead->notified_at ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="naya-empty"><?php esc_html_e( 'Aucun lead détecté pour le moment.', 'naya' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="naya-panel">
					<h2>💬 <?php esc_html_e( 'Ce que demandent vos visiteurs', 'naya' ); ?></h2>
					<?php if ( $questions ) : ?>
						<ul class="naya-list">
							<?php foreach ( $questions as $q ) : ?>
								<li>
									« <?php echo esc_html( wp_html_excerpt( $q->content, 90, '…' ) ); ?> »
									<span class="meta"><?php echo esc_html( date_i18n( 'j M H:i', strtotime( $q->created_at ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="naya-empty"><?php esc_html_e( 'Pas encore de questions.', 'naya' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<p>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">⬇ <?php esc_html_e( 'Exporter les conversations (CSV)', 'naya' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=naya' ) ); ?>" class="button"><?php esc_html_e( 'Réglages', 'naya' ); ?></a>
			</p>
		</div>
		<?php
	}
}
