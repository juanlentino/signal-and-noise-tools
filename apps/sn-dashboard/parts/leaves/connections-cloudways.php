<?php
/**
 * S&N Dashboard — Connections → Cloudways, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/cloudways.php, `sn_admin_cloudways_render()`)
 * is DISPLAY-ONLY by a security decision its own docblock spells out: the four
 * Cloudways credentials are wp-config constants with no option fallback, so no
 * field is ever offered for them — there, and therefore not here. It paints
 * three glance cards (the connection, the last purge, its result) through
 * sn_admin_glance_grid() and one helper line. Same state gatherer, same pure
 * builders, same three cards, same line; the kit's parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The classic builders' pre-built meta line, its inline `<code>` as the kit's
 * inline `<os-code>`. The builder (sn_admin_cloudways_cards / _outcome) already
 * escaped every value inside — stage, HTTP, error, constant names — and the
 * line passes through wp_kses_post as the classic grid passes it (the swap
 * runs after, since kses keeps `<code>` but would strip an `<os-code>`).
 *
 * @param string $meta The card's meta_html.
 * @return string
 */
function cloudways_meta_html( $meta ) {
	return str_replace( array( '<code>', '</code>' ), array( '<os-code>', '</os-code>' ), \wp_kses_post( (string) $meta ) );
}

/**
 * One glance card as a systems-wall cell: the label, the value, the pill as a
 * badge (only the kinds the classic grid paints, and only with text — its
 * allowlist), and the meta line. A card that wants attention carries the tone
 * stripe, as the Dashboard's wall paints the same card shape.
 *
 * @param array<string,mixed> $card A card from sn_admin_cloudways_cards().
 * @return string
 */
function cloudways_card_html( array $card ) {
	$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
	$text  = isset( $card['pill']['text'] ) ? (string) $card['pill']['text'] : '';
	$pill  = in_array( $kind, array( 'ok', 'warn', 'err' ), true ) && '' !== $text;
	$state = ( $pill && 'ok' !== $kind && \sn_admin_card_wants_attention( $card ) ) ? $kind : '';
	$meta  = (string) ( $card['meta_html'] ?? '' );
	return '<div class="snt-sys' . ( '' !== $state ? ' snt-sys--' . \snt_kit_esc( $state ) : '' ) . '"' . ( '' !== $state ? ' data-tone="' . \snt_kit_tone( $state ) . '"' : '' ) . '>'
		. '<span class="snt-sys__k">' . \snt_kit_esc( (string) ( $card['label'] ?? '' ) ) . '</span>'
		. '<span class="snt-sys__v">' . \snt_kit_esc( (string) ( $card['value'] ?? '' ) ) . '</span>'
		. ( $pill ? \snt_kit_badge( $kind, $text ) : '' )
		. ( '' !== $meta ? '<span class="snt-sys__meta">' . cloudways_meta_html( $meta ) . '</span>' : '' )
		. '</div>';
}

/**
 * The leaf: the three cards, then the helper line — nothing else, and no form.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_cloudways( array $ctx ) {
	unset( $ctx );
	if ( ! function_exists( 'sn_admin_cloudways_state' ) || ! function_exists( 'sn_admin_cloudways_cards' ) ) {
		return \snt_kit_empty( __( 'The Cloudways status is not available.', 'signal-and-noise-tools' ) );
	}
	$cells = '';
	foreach ( \sn_admin_cloudways_cards( \sn_admin_cloudways_state() ) as $card ) {
		if ( is_array( $card ) ) {
			$cells .= cloudways_card_html( $card );
		}
	}
	// The classic grid emits nothing for empty input; so does this.
	$out  = '' !== $cells ? '<div class="snt-systems">' . $cells . '</div>' : '';
	$out .= '<p class="snt-hint">'
		. 'Cloudways holds the origin cache (Breeze / Varnish). This leaf reports it; it never edits it. '
		. 'Credentials live in <os-code>wp-config.php</os-code> only — an account-wide API key is deliberately kept out of the database.'
		. '</p>';
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cloudways'] = __NAMESPACE__ . '\\paint_connections_cloudways';
		return $painters;
	}
);
