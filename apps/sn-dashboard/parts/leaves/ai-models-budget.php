<?php
/**
 * S&N Dashboard — AI → Models & Budget, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/ai-settings.php,
 * `sn_admin_render_ai_settings_form()`) paints one form (`sn_action=ai_settings_save`:
 * the prose model, the vision model, the monthly budget, and the masked
 * Workers AI embeddings token), the spend readout (total + by-feature,
 * both guarded on the AI-spend lane existing), the embeddings-token status
 * pill, and — only when embeddings are configured — the TF-IDF-vs-embeddings
 * comparison (a second action, `sn_action=ml_embed_compare`, posted via a
 * button that shares the classic form's own `sn_action` field name; per the
 * port map this is given its own distinct os-action here rather than reusing
 * the name, since FormData has no equivalent to "last value on the wire wins").
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/ai-models-budget-parts.php';

/**
 * State, read the way the classic leaf reads it.
 *
 * @return array<string,mixed>
 */
function models_budget_data() {
	return array(
		'model'            => (string) sn_setting( 'theme.ai_model', 'claude-sonnet-5' ),
		'alt_model'        => (string) sn_setting( 'theme.ai_alt_model', 'gemini-2.5-flash-lite' ),
		'budget'           => (float) sn_setting( 'theme.ai_monthly_budget', 0 ),
		'spent'            => function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0,
		'by_feature'       => function_exists( 'snt_ai_spend_this_month_by_feature' ) ? snt_ai_spend_this_month_by_feature() : array(),
		// #1597: Copilot turns, priced from the station's completion hook into a bucket of their own (inc/ai-copilot-spend.php),
		// and the turns that ran on an id the pricing table lacks, counted rather than dropped.
		'copilot'          => function_exists( 'snt_ai_copilot_spend_this_month' ) ? (float) snt_ai_copilot_spend_this_month() : 0.0,
		'copilot_unpriced' => function_exists( 'snt_ai_copilot_unpriced_this_month' ) ? (int) snt_ai_copilot_unpriced_this_month() : 0,
		'embed_token'      => function_exists( 'snt_ml_embed_token' ) ? snt_ml_embed_token() : '',
		'embed_has_check'  => function_exists( 'snt_ml_embed_configured' ),
		'embed_configured' => function_exists( 'snt_ml_embed_configured' ) && snt_ml_embed_configured(),
		'embed_account_id' => function_exists( 'snt_ml_embed_account_id' ) ? snt_ml_embed_account_id() : '',
		'cmp'              => get_transient( 'snt_ml_embed_compare' ),
		'jev'              => function_exists( 'sn_jev_meter_reading' ) ? sn_jev_meter_reading() : null,
		'jev_ready'        => function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready(),
		// The platform-reported spend (inc/spend-watch.php): null when the keyring row is absent.
		'gh'               => function_exists( 'sn_spend_gh_usage' ) ? sn_spend_gh_usage() : null,
		'ai'               => function_exists( 'sn_spend_ai_cost' ) ? sn_spend_ai_cost() : null,
	);
}

/**
 * The spend readout: total this month against the cap, then by feature.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_spend_html( array $d ) {
	$budget = $d['budget'];
	$spent  = $d['spent'];
	$out    = '';
	if ( $budget > 0 ) {
		$pct_true  = (int) round( ( $spent / $budget ) * 100 );
		$pct_width = max( 0, min( 100, $pct_true ) );
		// 17.4.1: a problem is a notice on top of its box.
		if ( $spent >= $budget ) {
			$out .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The cap is reached. AI features are paused until the next calendar month, or until you raise this number.', 'signal-and-noise-tools' ) ) . '</b>' );
		}
		$out .= '<p class="snt-prose">' . sprintf(
			esc_html__( 'Spent this month: $%1$s of $%2$s (%3$s%%).', 'signal-and-noise-tools' ),
			\snt_kit_esc( number_format_i18n( $spent, 2 ) ),
			\snt_kit_esc( number_format_i18n( $budget, 2 ) ),
			\snt_kit_esc( number_format_i18n( $pct_true ) )
		) . '</p>';
		$out .= \snt_kit_tag( 'os-progress-bar', array(
			'value' => (string) $pct_width,
			'max'   => '100',
			'tone'  => $spent >= $budget ? 'danger' : 'default',
		) );
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Set 0 to remove the cap.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$out .= '<p class="snt-prose">' . sprintf(
			esc_html__( 'No cap set: AI features never pause on cost. Spent this month: $%s.', 'signal-and-noise-tools' ),
			\snt_kit_esc( number_format_i18n( $spent, 2 ) )
		) . '</p>';
	}

	if ( array() !== $d['by_feature'] ) {
		$rows = array();
		foreach ( $d['by_feature'] as $feature_slug => $feature_cost ) {
			$decimals = $feature_cost < 0.01 ? 4 : 2;
			$rows[]   = array( 'label' => (string) $feature_slug, 'value' => '$' . number_format_i18n( $feature_cost, $decimals ) );
		}
		$out .= \snt_kit_list( $rows );
	}
	// #1597: Copilot (Ask AI) runs on the same connector but the station calls
	// it directly, so the cap never reads it and never pauses it. A row of its
	// own, never folded into the total or the feature rows above. $0.00 is a
	// recorded zero (the subscriber is always bound) and the unpriced hint
	// below is what separates "no turn this month" from "turns the pricing
	// table could not price": the Insights leaf's wording for the same state.
	$copilot  = (float) $d['copilot'];
	$unpriced = (int) ( $d['copilot_unpriced'] ?? 0 );
	$out     .= \snt_kit_list( array( array(
		'label' => __( 'Copilot (Ask AI), not counted against the cap', 'signal-and-noise-tools' ),
		'value' => '$' . number_format_i18n( $copilot, $copilot > 0 && $copilot < 0.01 ? 4 : 2 ),
	) ) );
	$out     .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Copilot is priced at list rates from the tokens the station reports, with no prompt-cache discount, so it reads high on a warm cache.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( $unpriced > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '%s Copilot turn(s) this month reported no usage, or a model with no list price on file, and are not in the dollar figure.', 'signal-and-noise-tools' ), number_format_i18n( $unpriced ) ) ) . '</p>';
	}
	// 17.4.1: one box, the total as its lead, so it can share a row with the form.
	return \snt_kit_section( __( 'This month, by feature', 'signal-and-noise-tools' ), $out );
}

/**
 * The embeddings-token status pill (item 8 — Workers AI token for SHADOW
 * semantic embeddings). Absent entirely when the lane's check is unavailable,
 * matching the classic `function_exists()` guard.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_embed_status_html( array $d ) {
	if ( ! $d['embed_has_check'] ) {
		return '';
	}
	if ( $d['embed_configured'] ) {
		return \snt_kit_notice( 'ok', \snt_kit_badge( 'ok', __( 'Configured', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( __( 'Embeddings run in SHADOW mode: they are computed and compared against the existing ranking, and nothing the site serves uses them yet.', 'signal-and-noise-tools' ) ) );
	}
	if ( '' === $d['embed_account_id'] ) {
		return \snt_kit_notice( 'warn', \snt_kit_badge( 'warn', __( 'No Cloudflare account ID — set it under Connections › Credentials first.', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_notice( 'info', \snt_kit_badge( '', __( 'Not configured.', 'signal-and-noise-tools' ) ) );
}

/** Divergent-pair rows painted before the "+N more" line. */
const MODELS_BUDGET_DIVERGENT_MAX = 25;

/**
 * The TF-IDF vs embeddings comparison (item 8's runner), gated to when
 * embeddings are configured — same gate the classic leaf uses.
 *
 * @param array  $d    From models_budget_data().
 * @param string $lead The embeddings status notice, on top of its box.
 * @return string
 */
function models_budget_compare_html( array $d, $lead = '' ) {
	$cmp  = $d['cmp'];
	$body = $lead;
	if ( is_array( $cmp ) && empty( $cmp['ok'] ) ) {
		$body .= \snt_kit_notice( 'warn', \snt_kit_esc( (string) ( $cmp['error'] ?? '' ) ) );
	} elseif ( is_array( $cmp ) && ! empty( $cmp['ok'] ) ) {
		$res    = (array) ( $cmp['result'] ?? array() );
		$vars   = (array) ( $res['variants'] ?? array() );
		$scope  = (array) ( $res['scope'] ?? array() );
		if ( $scope ) {
			$body .= '<p class="snt-prose">' . sprintf(
				esc_html__( '%1$d notes embedded (%2$d published and scored; %3$d scheduled, counted in the centroid only — a scheduled note has no baseline artifact to diverge from).', 'signal-and-noise-tools' ),
				(int) ( $scope['embedded_total'] ?? 0 ),
				(int) ( $scope['scored_sources'] ?? 0 ),
				(int) ( $scope['scheduled_in_centroid'] ?? 0 )
			) . '</p>';
		}
		$labels = array(
			'raw'             => __( 'Raw cosine', 'signal-and-noise-tools' ),
			'centered'        => __( 'Centred', 'signal-and-noise-tools' ),
			'centered_mutual' => __( 'Centred + mutual', 'signal-and-noise-tools' ),
		);
		$rows = array();
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $vars[ $key ] ) ) {
				continue;
			}
			$v      = (array) $vars[ $key ];
			$hub    = (array) ( $v['hub'] ?? array() );
			$is_rec = ( ( $res['recommended'] ?? '' ) === $key );
			$rows[] = array(
				'variant'   => $label . ( $is_rec ? ' (' . __( 'recommended', 'signal-and-noise-tools' ) . ')' : '' ),
				'divergence' => number_format_i18n( 100 * (float) ( $v['divergence'] ?? 0 ), 1 ) . '%',
				'hub_share' => number_format_i18n( 100 * (float) ( $hub['hub_share'] ?? 0 ), 1 ) . '% (' . (string) ( $hub['top_count'] ?? 0 ) . ' of ' . (string) ( $hub['sources'] ?? 0 ) . ')',
				'targets'   => (string) ( $hub['distinct_targets'] ?? 0 ),
			);
		}
		$body .= \snt_kit_table(
			array(
				array( 'key' => 'variant', 'label' => __( 'Variant', 'signal-and-noise-tools' ) ),
				array( 'key' => 'divergence', 'label' => __( 'Divergence', 'signal-and-noise-tools' ) ),
				array( 'key' => 'hub_share', 'label' => __( 'Hub share', 'signal-and-noise-tools' ) ),
				array( 'key' => 'targets', 'label' => __( 'Distinct targets', 'signal-and-noise-tools' ) ),
			),
			$rows
		);
		$div = (array) ( $res['divergent'] ?? array() );
		if ( $div ) {
			$div_rows = array();
			foreach ( array_slice( $div, 0, MODELS_BUDGET_DIVERGENT_MAX ) as $row ) {
				$names = array();
				foreach ( (array) $row['only_embedding'] as $o ) {
					$names[] = (string) $o['title'];
				}
				$div_rows[] = array( 'note' => (string) $row['title'], 'found' => implode( ' · ', $names ) );
			}
			// 17.4.1: a ledger, not a fold: the count as the lead, the table capped, a "+N more" line.
			$body .= '<p class="snt-prose">' . \snt_kit_esc( sprintf(
				_n( '%d note has a pair TF-IDF does not find', '%d notes have pairs TF-IDF does not find', count( $div ), 'signal-and-noise-tools' ),
				count( $div )
			) ) . '</p>';
			$body .= \snt_kit_table(
				array(
					array( 'key' => 'note', 'label' => __( 'Note', 'signal-and-noise-tools' ) ),
					array( 'key' => 'found', 'label' => __( 'Found only by embeddings', 'signal-and-noise-tools' ) ),
				),
				$div_rows
			);
			if ( count( $div ) > MODELS_BUDGET_DIVERGENT_MAX ) {
				$body .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '+%d more, the list is capped.', 'signal-and-noise-tools' ), count( $div ) - MODELS_BUDGET_DIVERGENT_MAX ) ) . '</p>';
			}
		} else {
			$body .= '<p class="snt-prose">' . \snt_kit_esc( __( 'No divergence at all: TF-IDF already found every pair the embeddings did. That is a real answer, and it argues against adopting a hosted model.', 'signal-and-noise-tools' ) ) . '</p>';
		}
	} else {
		$body .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Not run yet. This embeds every published note once (cached by content hash) and compares both rankings.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$body .= '<p>' . \snt_kit_action_button( __( 'Run comparison', 'signal-and-noise-tools' ), 'ml_embed_compare' ) . '</p>';
	return \snt_kit_section( __( 'TF-IDF vs embeddings', 'signal-and-noise-tools' ), $body );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_ai_models_budget( array $ctx ) {
	unset( $ctx );
	$d = models_budget_data();

	$fields  = \snt_kit_field( 'select', 'theme_ai_model', __( 'Prose model', 'signal-and-noise-tools' ), $d['model'], array(
		'options' => sn_theme_ai_models(),
		'hint'    => __( 'Used for AI-assisted prose features (drafts, insights, meta descriptions).', 'signal-and-noise-tools' ),
	) );
	$fields .= \snt_kit_field( 'select', 'theme_ai_alt_model', __( 'Vision model (alt text)', 'signal-and-noise-tools' ), $d['alt_model'], array(
		'options' => sn_theme_ai_vision_models(),
	) );
	$fields .= '<p class="snt-hint">' . sprintf(
		/* translators: %s: the alt-text model filter name, wrapped in <code>. */
		\snt_kit_esc( __( 'Used to LOOK at images when suggesting alt text. The %s filter still overrides this for code-level pins.', 'signal-and-noise-tools' ) ),
		\snt_kit_code( 'snt_ai_alt_text_model', false )
	) . '</p>';
	$fields .= \snt_kit_field( 'number', 'theme_ai_monthly_budget', __( 'Monthly budget (USD)', 'signal-and-noise-tools' ), number_format( $d['budget'], 2, '.', '' ), array(
		'min'  => 0,
		'step' => 0.5,
	) );
	// 16.6.0: the Jev credit and its cycle day; the meter reads both.
	$fields .= \snt_kit_field( 'number', 'theme_jev_credit', __( 'Jev monthly credit (USD)', 'signal-and-noise-tools' ), number_format( (float) sn_setting( 'theme.jev_credit', 5 ), 2, '.', '' ), array( 'min' => 0, 'step' => 0.5, 'hint' => __( 'TypeSafe\'s credit, as the console states it. A readout, not a cap.', 'signal-and-noise-tools' ) ) );
	$fields .= \snt_kit_field( 'number', 'theme_jev_cycle_day', __( 'Jev credit renews on day', 'signal-and-noise-tools' ), (string) (int) sn_setting( 'theme.jev_cycle_day', 17 ), array( 'min' => 1, 'max' => 28, 'step' => 1 ) );
	// 15.3.1: the Workers AI token is a keyring row (Connections › Credentials);
	// this leaf reads it and says so.
	$fields .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Workers AI token (semantic embeddings):', 'signal-and-noise-tools' ) ) . ' ' . ( '' !== (string) $d['embed_token'] ? \snt_kit_badge( 'ok', __( 'set', 'signal-and-noise-tools' ) ) : \snt_kit_badge( 'warn', __( 'not set', 'signal-and-noise-tools' ) ) ) . ' ' . \snt_kit_esc( __( 'Set under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'monitoring' ) ) . '.</p>';

	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Which models this plugin calls, and the ceiling on what they may cost. Every AI feature here (drafts, insights, meta descriptions, alt text) draws on the same monthly budget.', 'signal-and-noise-tools' ) ) . '</p>';
	$form  = \snt_kit_section(
		__( 'Models & budget', 'signal-and-noise-tools' ),
		\snt_kit_form( 'ai_settings_save', $fields, array( 'submit' => __( 'Save AI settings', 'signal-and-noise-tools' ), 'columns' => 'auto' ) ),
		__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' )
	);
	// 17.4.1 (#1573): boxes on rows of comparable height. The form beside the
	// two readouts it is read against; the Jev meter beside the comparison box
	// while that box is short (configured, no result yet). A bare status
	// notice, or a comparison carrying its result (a ledger), stands alone at
	// full width under the Jev box rather than beside a hole.
	$embed  = models_budget_embed_status_html( $d );
	$right  = $d['embed_configured'] ? models_budget_compare_html( $d, $embed ) : $embed;
	$beside = $d['embed_configured'] && ! ( is_array( $d['cmp'] ) && ! empty( $d['cmp']['ok'] ) );
	return $intro
		. models_budget_pair( $form, models_budget_spend_html( $d ) . models_budget_platform_html( $d ) )
		. models_budget_pair( models_budget_jev_html( $d ), $beside ? $right : '' )
		. ( $beside ? '' : $right );
}

/**
 * Two sides on one row (the cron_settings_row_html shape, connections-cron-parts.php;
 * the tags_pair idiom for an empty side). Each side is one .snt-col cell because
 * a side may stack two boxes, and bare os-sections in .snt-cols would each take
 * a cell. A side that painted nothing leaves the other alone at full width,
 * not beside a hole.
 *
 * @param string $left  Painted HTML, or ''.
 * @param string $right Painted HTML, or ''.
 * @return string
 */
function models_budget_pair( $left, $right ) {
	if ( '' === $left || '' === $right ) {
		return $left . $right;
	}
	return '<div class="snt-cols"><section class="snt-col">' . $left . '</section><section class="snt-col">' . $right . '</section></div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/models-budget'] = __NAMESPACE__ . '\\paint_ai_models_budget';
		return $painters;
	}
);
