<?php
/**
 * S&N Dashboard — Integrity → Citations, per-row helpers.
 *
 * Split out of tools-citations.php to keep the leaf file under ~200 lines.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One claims row, as a compact `<os-card>` of labelled values — mirrors
 * `sn_cit_render_row()` line for line (that function echoes two `<a>` tags
 * directly, so it cannot be reused as a reader; the underlying values are
 * the same). Unlike a `snt_kit_table()` cell, an `<os-card>` kv row takes
 * HTML (`'html' => true`), so the source and cited-page anchors — dropped
 * in the first port because `os-table` only accepts plain string cells via
 * os-prop-data JSON — survive here, and the tier keeps its pill tone and
 * tooltip via `snt_kit_badge()` instead of losing them to plain text.
 *
 * @param object $r A row of the citations table.
 * @return string
 */
function citations_row_card( $r ) {
	$tiers = defined( 'SN_CIT_TIERS' ) ? SN_CIT_TIERS : array();
	$tier  = in_array( (string) $r->tier, $tiers, true ) ? (string) $r->tier : 'unverified';
	$kind  = function_exists( 'sn_cit_tier_pill_kind' ) ? sn_cit_tier_pill_kind( $tier ) : '';
	$host  = (string) wp_parse_url( (string) $r->source_url, PHP_URL_HOST );
	$path  = (string) wp_parse_url( (string) $r->target_url, PHP_URL_PATH );
	$name  = '' !== (string) $r->source_title ? (string) $r->source_title : ( '' !== $host ? $host : (string) $r->source_url );

	$cited = '';
	if ( (int) $r->target_post_id > 0 && function_exists( 'get_the_title' ) ) {
		$cited = (string) get_the_title( (int) $r->target_post_id );
	}

	$tier_html = \snt_kit_tag(
		'span',
		array( 'title' => function_exists( 'sn_cit_tier_sentence' ) ? sn_cit_tier_sentence( $tier ) : '' ),
		\snt_kit_badge( $kind, $tier )
	);

	$source_html = \snt_kit_link( $name, (string) $r->source_url );
	if ( '' !== $host && $name !== $host ) {
		$source_html .= '<p class="snt-hint">' . \snt_kit_esc( $host ) . '</p>';
	}

	$cites_html = \snt_kit_link( '' !== $cited ? $cited : $path, (string) $r->target_url );
	if ( '' !== $cited ) {
		$cites_html .= '<p class="snt-hint">' . \snt_kit_esc( $path ) . '</p>';
	}

	return \snt_kit_tag(
		'os-card',
		array( 'compact' => true ),
		\snt_kit_kv(
			array(
				array( 'label' => __( 'Tier', 'signal-and-noise-tools' ), 'value' => $tier_html, 'html' => true ),
				array( 'label' => __( 'Source', 'signal-and-noise-tools' ), 'value' => $source_html, 'html' => true ),
				array( 'label' => __( 'Cites', 'signal-and-noise-tools' ), 'value' => $cites_html, 'html' => true ),
				array(
					'label' => __( 'First seen', 'signal-and-noise-tools' ),
					'value' => function_exists( 'sn_cit_ago_label' ) ? sn_cit_ago_label( $r->first_seen_gmt ) : '',
				),
				array(
					'label' => __( 'Last checked', 'signal-and-noise-tools' ),
					'value' => function_exists( 'sn_cit_last_checked_label' ) ? sn_cit_last_checked_label( $r->last_checked_gmt ) : '',
				),
				array(
					'label' => __( 'HTTP', 'signal-and-noise-tools' ),
					// 0 means no response was received at all — distinct from a 200 or a 404.
					'value' => (int) $r->last_status ? (string) (int) $r->last_status : '—',
				),
			)
		)
	);
}

/**
 * The claims list, or the same-worded empty state, plus the same 100-row cap
 * notice. One `<os-card>` per row (see `citations_row_card()`), stacked —
 * the kit has no table-cell HTML slot, so the classic `<table>` becomes the
 * same card-list idiom the kit already ships (content-pattern-adoption.php),
 * which keeps every per-row link and the tier's pill tone.
 *
 * @param array<int,object> $rows Up to 100 rows, newest first.
 * @return string
 */
function citations_table( array $rows ) {
	if ( empty( $rows ) ) {
		return '<p class="snt-prose">' . \snt_kit_esc( __( 'Nothing to list yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$cards = '';
	foreach ( $rows as $r ) {
		$cards .= citations_row_card( $r );
	}
	$out = \snt_kit_tag( 'os-stack', array( 'gap' => '8' ), $cards );
	if ( 100 === count( $rows ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The newest 100 claims are listed.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	return $out;
}
