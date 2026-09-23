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
 * One claims row, as the row of an `<os-table>` (17.9.0, #1624) — mirrors
 * `sn_cit_render_row()` line for line (that function echoes two `<a>` tags
 * directly, so it cannot be reused as a reader; the underlying values are
 * the same). The source and cited-page anchors and the tier's pill (tone and
 * tooltip) are slot cells (OpenStation 1.1.11), so they survive as markup;
 * the two times are slot cells too, showing the relative label and sorting
 * on the GMT timestamp, so the columns sort by date rather than by the words.
 *
 * @param object $r A row of the citations table.
 * @return array<string,mixed>
 */
function citations_row( $r ) {
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

	$first   = function_exists( 'sn_cit_ago_label' ) ? sn_cit_ago_label( $r->first_seen_gmt ) : '';
	$checked = function_exists( 'sn_cit_last_checked_label' ) ? sn_cit_last_checked_label( $r->last_checked_gmt ) : '';
	return array(
		'_key'    => 'cit-' . (string) ( $r->id ?? ( $r->source_url . '|' . $r->target_url ) ),
		'tier'    => array( 'html' => $tier_html, 'text' => $tier ),
		'source'  => array( 'html' => $source_html, 'text' => $name ),
		'cites'   => array( 'html' => $cites_html, 'text' => '' !== $cited ? $cited : $path ),
		'first'   => array( 'html' => \snt_kit_esc( $first ), 'text' => (string) $r->first_seen_gmt ),
		'checked' => array( 'html' => \snt_kit_esc( $checked ), 'text' => (string) $r->last_checked_gmt ),
		// 0 means no response was received at all — distinct from a 200 or a 404.
		'http'    => (int) $r->last_status ? (string) (int) $r->last_status : '—',
	);
}

/**
 * The claims table, or the same-worded empty state, plus the same 100-row
 * cap notice. 17.9.0 (#1624): the classic `<table>` again, as an
 * `<os-table>`; it was one `<os-card>` per claim while the kit had no
 * table-cell HTML slot. A card per claim on a phone.
 *
 * @param array<int,object> $rows Up to 100 rows, newest first.
 * @return string
 */
function citations_table( array $rows ) {
	if ( empty( $rows ) ) {
		return '<p class="snt-prose">' . \snt_kit_esc( __( 'Nothing to list yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$data = array();
	foreach ( $rows as $r ) {
		$data[] = citations_row( $r );
	}
	$columns = array(
		array( 'key' => 'tier', 'label' => __( 'Tier', 'signal-and-noise-tools' ), 'sortable' => true, 'filter' => 'select' ),
		array( 'key' => 'source', 'label' => __( 'Source', 'signal-and-noise-tools' ), 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'cites', 'label' => __( 'Cites', 'signal-and-noise-tools' ), 'sortable' => true ),
		array( 'key' => 'first', 'label' => __( 'First seen', 'signal-and-noise-tools' ), 'sortable' => true ),
		array( 'key' => 'checked', 'label' => __( 'Last checked', 'signal-and-noise-tools' ), 'sortable' => true ),
		array( 'key' => 'http', 'label' => __( 'HTTP', 'signal-and-noise-tools' ), 'sortable' => true, 'align' => 'end' ),
	);
	$out = \snt_kit_table( $columns, $data, array( 'stack_on_phone' => true ) );
	if ( 100 === count( $rows ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'The newest 100 claims are listed.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	return $out;
}
