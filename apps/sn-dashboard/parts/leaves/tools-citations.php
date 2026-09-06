<?php
/**
 * S&N Dashboard — Integrity → Citations, painted from the kit.
 *
 * The classic leaf (inc/citations-admin.php, `sn_admin_render_citations_section()`)
 * paints a glance hero (one card per SN_CIT_TIERS tier, every tier printed even
 * at zero), a wide fieldset with the three-way summary sentence, an explanatory
 * paragraph, the public webmention inbox address, a folded legend of what each
 * tier means, and the up-to-100-row claims table (tier, source, cited page,
 * first seen, last checked, HTTP status). Read-only: the classic leaf offers no
 * form and no `sn_action` — this leaf offers none either.
 *
 * Same readers as the classic leaf: `sn_cit_counts()`, `sn_cit_glance_cards()`,
 * `sn_cit_tier_gloss()`, `sn_cit_summary_sentence()`, `sn_cit_endpoint_url()`,
 * `sn_cit_tier_sentence()`, `sn_cit_table()`, and the same raw
 * `SELECT * FROM {$table} ORDER BY first_seen_gmt DESC LIMIT 100` the classic
 * renderer issues. `sn_cit_render_row()` echoes directly (tier pill markup +
 * two anchors per row), so its per-row logic is mirrored line for line in
 * `citations_row_card()` (tools-citations-parts.php) rather than reused, per
 * the port brief.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/tools-citations-parts.php';

/**
 * The glance hero: one `<os-stat>` per tier, in the ladder's order — the SAME
 * cards the classic hero builds (`sn_cit_glance_cards()`), just painted as kit
 * stats instead of `.sn-glance-card` divs.
 *
 * @param array<string,int> $counts From sn_cit_counts().
 * @return string
 */
function citations_glance( array $counts ) {
	$cards = function_exists( 'sn_cit_glance_cards' ) ? sn_cit_glance_cards( $counts ) : array();
	$out   = '';
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$tier    = (string) ( $card['label'] ?? '' );
		$kind    = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		$note    = isset( $card['pill']['text'] ) ? (string) $card['pill']['text'] : '';
		$caption = function_exists( 'sn_cit_tier_gloss' ) ? sn_cit_tier_gloss( $tier ) : '';
		if ( '' !== $note ) {
			$caption = '' !== $caption ? $caption . ' · ' . $note : $note;
		}
		$out .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), $tier, $caption, $kind );
	}
	return '<div class="snt-stats">' . $out . '</div>';
}

/**
 * The folded legend: what each tier means, as a facts list inside a closed
 * disclosure — the kit's counterpart to the classic `<details><summary>`.
 *
 * @return string
 */
function citations_legend() {
	$rows = array();
	foreach ( ( defined( 'SN_CIT_TIERS' ) ? SN_CIT_TIERS : array() ) as $tier ) {
		$rows[] = array(
			'label' => $tier,
			'value' => function_exists( 'sn_cit_tier_sentence' ) ? sn_cit_tier_sentence( $tier ) : '',
		);
	}
	return \snt_kit_tag(
		'os-disclosure',
		array( 'heading' => __( 'What the four tiers mean', 'signal-and-noise-tools' ) ),
		\snt_kit_kv( $rows )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_citations( array $ctx ) {
	unset( $ctx );
	global $wpdb;
	$counts = function_exists( 'sn_cit_counts' ) ? sn_cit_counts() : array();
	$table  = function_exists( 'sn_cit_table' ) ? sn_cit_table() : '';
	$rows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY first_seen_gmt DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	$rows   = is_array( $rows ) ? $rows : array();

	$out  = '<section aria-label="' . \snt_kit_esc( __( 'Citations at a glance', 'signal-and-noise-tools' ) ) . '">' . citations_glance( $counts ) . '</section>';
	$body = '<p class="snt-prose"><b>' . \snt_kit_esc( function_exists( 'sn_cit_summary_sentence' ) ? sn_cit_summary_sentence( $counts ) : '' ) . '</b></p>'
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'A webmention is a claim that someone cited you. Each claim is re-fetched and sorted by what can be checked: verified and unattributed citations appear on the site; a claim whose link has gone, or that could not be reached, is kept here and shown to nobody else.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Inbox:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( function_exists( 'sn_cit_endpoint_url' ) ? sn_cit_endpoint_url() : '', false ) . '</p>'
		. citations_legend()
		. citations_table( $rows );
	$out .= \snt_kit_section( __( 'Citations', 'signal-and-noise-tools' ), $body );
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/citations'] = __NAMESPACE__ . '\\paint_tools_citations';
		return $painters;
	}
);
