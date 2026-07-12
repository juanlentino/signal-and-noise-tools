<?php
/**
 * Signal & Noise — analytics narrator (diagnostic + prescriptive language).
 * Consumes (summary, Signal[]) → a short narrative. AI path wraps the WP AI
 * Client; a deterministic template is the guaranteed floor. Spec §5.2.
 * @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic narrative from signals' plain_labels. Always available (the floor). */
function sn_analytics_narrate_fallback( $summary, $signals ) {
	if ( empty( $signals ) ) {
		return '<p class="sn-an-note">No standout signals in this window — nothing needs attention right now.</p>';
	}
	$items = array();
	foreach ( array_slice( $signals, 0, 4 ) as $s ) {
		$label = trim( (string) ( $s['plain_label'] ?? '' ) );
		if ( '' !== $label ) { $items[] = '<li>' . esc_html( $label ) . '</li>'; }
	}
	return '<ul class="sn-an-digest-list">' . implode( '', $items ) . '</ul>';
}

/**
 * Public narrator (the swap seam). Tries the AI path; falls back to the
 * deterministic floor on empty/error/over-budget. A future direct-to-provider
 * impl can replace the AI path via the 'sn_analytics_narrator' filter.
 * @return array{narrative:string, source:string, model:?string}
 */
function sn_analytics_narrate( $summary, $signals ) {
	$override = function_exists( 'apply_filters' ) ? apply_filters( 'sn_analytics_narrator', null, $summary, $signals ) : null;
	if ( is_array( $override ) && '' !== trim( (string) ( $override['narrative'] ?? '' ) ) ) { return $override; }
	$ai = sn_analytics_narrate_ai( $summary, $signals );
	if ( is_array( $ai ) && '' !== trim( (string) ( $ai['narrative'] ?? '' ) ) ) { return $ai; }
	return array( 'narrative' => sn_analytics_narrate_fallback( $summary, $signals ), 'source' => 'fallback', 'model' => null );
}

/** AI narration via the WP AI Client wrapper. Returns null on no-signals/absent/empty. */
function sn_analytics_narrate_ai( $summary, $signals ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return null; }
	$facts = array();
	foreach ( $signals as $s ) {
		$facts[] = '- ' . (string) ( $s['plain_label'] ?? '' ) . ' [' . (string) ( $s['kind'] ?? '' ) . ', confidence ' . (string) ( $s['confidence'] ?? '' ) . ']';
	}
	$system = 'You are an analytics narrator. Narrate ONLY the signals given as bullet facts. NEVER invent or estimate a number that is not present. State uncertainty plainly. 2-3 sentences: what happened, why it may matter, one concrete next step. Plain text.';
	$prompt = "Signals:\n" . implode( "\n", $facts ) . "\n\nWrite the brief.";
	$text   = snt_ai_generate_with_constraints( $prompt, $system, 220, 'analytics_digest' );
	if ( ! is_string( $text ) || '' === trim( $text ) ) { return null; }
	return array( 'narrative' => '<p>' . esc_html( trim( $text ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
}

/**
 * Deterministic weekly-digest floor (spec §9): descriptive summary line + the
 * period's signal list (≤8) + a concrete start-here line from the top signal.
 * Always available; the AI path composes richer prose over the same facts.
 */
function sn_analytics_digest_fallback( $summary, $signals ) {
	$head = '';
	if ( is_array( $summary ) && ( isset( $summary['views'] ) || isset( $summary['visits'] ) ) ) {
		$head = '<p class="sn-an-digest-head">' . esc_html( sprintf(
			'This period: %s views, %s visits.',
			number_format( (float) ( $summary['views'] ?? 0 ) ),
			number_format( (float) ( $summary['visits'] ?? 0 ) )
		) ) . '</p>';
	}
	if ( empty( $signals ) ) {
		return $head . '<p class="sn-an-note">No standout signals in this window — nothing needs attention right now.</p>';
	}
	$items = array();
	foreach ( array_slice( $signals, 0, 8 ) as $s ) {
		$label = trim( (string) ( $s['plain_label'] ?? '' ) );
		if ( '' !== $label ) { $items[] = '<li>' . esc_html( $label ) . '</li>'; }
	}
	$next = trim( (string) ( $signals[0]['plain_label'] ?? '' ) );
	$do   = '' !== $next ? '<p class="sn-an-digest-next">' . esc_html( 'Start here: ' . $next ) . '</p>' : '';
	return $head . '<ul class="sn-an-digest-list">' . implode( '', $items ) . '</ul>' . $do;
}

/**
 * AI weekly digest via the WP AI Client wrapper. Longer-form than narrate():
 * two short paragraphs over the descriptive summary + every signal's plain_label.
 * Returns null on no-signals / wrapper-absent / empty / WP_Error (budget cap).
 */
function sn_analytics_digest_ai( $summary, $signals ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return null; }
	$facts = array();
	if ( is_array( $summary ) ) {
		foreach ( array( 'views', 'visits' ) as $k ) {
			if ( isset( $summary[ $k ] ) ) { $facts[] = '- ' . ucfirst( $k ) . ' this period: ' . (string) (int) $summary[ $k ]; }
		}
	}
	foreach ( $signals as $s ) {
		$facts[] = '- ' . (string) ( $s['plain_label'] ?? '' ) . ' [' . (string) ( $s['kind'] ?? '' ) . ', confidence ' . (string) ( $s['confidence'] ?? '' ) . ']';
	}
	$system = 'You are writing a weekly analytics executive digest. Use ONLY the bullet facts given. NEVER invent or estimate a number that is not present. State uncertainty plainly. Two short paragraphs: (1) what happened and why it matters; (2) what to do next, concretely. Plain text.';
	$prompt = "Facts:\n" . implode( "\n", $facts ) . "\n\nWrite the weekly digest.";
	$text   = snt_ai_generate_with_constraints( $prompt, $system, 500, 'analytics_digest_weekly' );
	if ( ! is_string( $text ) || '' === trim( $text ) ) { return null; }
	return array( 'digest' => '<p>' . nl2br( esc_html( trim( $text ) ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
}

/**
 * Public weekly digest (the seam, mirroring sn_analytics_narrate): filter
 * override → AI longer-form → deterministic floor. The wrapper returns WP_Error
 * when the monthly budget cap is hit; is_string() routes that to the floor.
 * @return array{digest:string, source:string, model:?string}
 */
function sn_analytics_digest( $summary, $signals ) {
	$override = function_exists( 'apply_filters' ) ? apply_filters( 'sn_analytics_digest', null, $summary, $signals ) : null;
	if ( is_array( $override ) && '' !== trim( (string) ( $override['digest'] ?? '' ) ) ) { return $override; }
	$ai = sn_analytics_digest_ai( $summary, $signals );
	if ( is_array( $ai ) && '' !== trim( (string) ( $ai['digest'] ?? '' ) ) ) { return $ai; }
	return array( 'digest' => sn_analytics_digest_fallback( $summary, $signals ), 'source' => 'fallback', 'model' => null );
}
