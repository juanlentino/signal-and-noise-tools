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
		'embed_token'      => function_exists( 'snt_ml_embed_token' ) ? snt_ml_embed_token() : '',
		'embed_has_check'  => function_exists( 'snt_ml_embed_configured' ),
		'embed_configured' => function_exists( 'snt_ml_embed_configured' ) && snt_ml_embed_configured(),
		'embed_account_id' => function_exists( 'snt_ml_embed_account_id' ) ? snt_ml_embed_account_id() : '',
		'cmp'              => get_transient( 'snt_ml_embed_compare' ),
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
		$out      .= '<p class="snt-prose">' . sprintf(
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
		if ( $spent >= $budget ) {
			$out .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The cap is reached. AI features are paused until the next calendar month, or until you raise this number.', 'signal-and-noise-tools' ) ) . '</b>' );
		}
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
		$out .= \snt_kit_section( __( 'This month, by feature', 'signal-and-noise-tools' ), \snt_kit_list( $rows ) );
	}
	return $out;
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
		return \snt_kit_notice( 'warn', \snt_kit_badge( 'warn', __( 'No Cloudflare account ID — set it under Measurement → Analytics first.', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_notice( 'info', \snt_kit_badge( '', __( 'Not configured.', 'signal-and-noise-tools' ) ) );
}

/**
 * The TF-IDF vs embeddings comparison (item 8's runner), gated to when
 * embeddings are configured — same gate the classic leaf uses.
 *
 * @param array $d From models_budget_data().
 * @return string
 */
function models_budget_compare_html( array $d ) {
	$cmp  = $d['cmp'];
	$body = '';
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
			foreach ( array_slice( $div, 0, 25 ) as $row ) {
				$names = array();
				foreach ( (array) $row['only_embedding'] as $o ) {
					$names[] = (string) $o['title'];
				}
				$div_rows[] = array( 'note' => (string) $row['title'], 'found' => implode( ' · ', $names ) );
			}
			$div_table = \snt_kit_table(
				array(
					array( 'key' => 'note', 'label' => __( 'Note', 'signal-and-noise-tools' ) ),
					array( 'key' => 'found', 'label' => __( 'Found only by embeddings', 'signal-and-noise-tools' ) ),
				),
				$div_rows
			);
			$body .= \snt_kit_tag(
				'os-disclosure',
				array(
					'heading' => sprintf(
						_n( '%d note has a pair TF-IDF does not find', '%d notes have pairs TF-IDF does not find', count( $div ), 'signal-and-noise-tools' ),
						count( $div )
					),
				),
				$div_table
			);
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
	$fields .= \snt_kit_field( 'text', 'sn_ml_embeddings_token', __( 'Workers AI token (semantic embeddings)', 'signal-and-noise-tools' ), sn_mask_secret( $d['embed_token'] ), array(
		'placeholder' => __( 'Paste a token; type clear to remove', 'signal-and-noise-tools' ),
		'hint'        => __( 'Cloudflare dashboard → My Profile → API Tokens → Create Custom Token. The permission is under the ACCOUNT scope (not User or Zone), named "Workers AI", set to Read. The account ID is shared with Analytics.', 'signal-and-noise-tools' ),
	) );

	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Which models this plugin calls, and the ceiling on what they may cost. Every AI feature here (drafts, insights, meta descriptions, alt text) draws on the same monthly budget.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= \snt_kit_section(
		__( 'Models & budget', 'signal-and-noise-tools' ),
		\snt_kit_form( 'ai_settings_save', $fields, array( 'submit' => __( 'Save AI settings', 'signal-and-noise-tools' ), 'columns' => 'auto' ) ),
		__( 'Model changes apply to the next AI call. The budget is evaluated per calendar month.', 'signal-and-noise-tools' )
	);
	$out .= models_budget_spend_html( $d );
	$out .= models_budget_embed_status_html( $d );
	if ( $d['embed_configured'] ) {
		$out .= models_budget_compare_html( $d );
	}
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/models-budget'] = __NAMESPACE__ . '\\paint_ai_models_budget';
		return $painters;
	}
);
