<?php
/**
 * Signal & Noise — analytics narrator (diagnostic + prescriptive language).
 * Consumes (summary, Signal[]) → a short narrative. AI path wraps the WP AI
 * Client; a deterministic template is the guaranteed floor. Spec §5.2.
 *
 * Render-path hardening: sn_aw_insight_header() (dashboard widget) and
 * snt_analytics_render_insights_band() (Analytics page) call sn_analytics_narrate()
 * / sn_analytics_digest() on EVERY admin page load — a passive render, not a
 * user-initiated action. Before this hardening, a cache miss meant an inline
 * 2.5-8.2s billed Anthropic call blocking that render. The *_ai() functions
 * below are now CACHE-READERS ONLY: cache hit → cached text; cache miss →
 * schedule a single out-of-band event and return (degrading to a same-key
 * last-good, else null → the caller's deterministic fallback). The paired
 * *_ai_run() functions, hooked to their own event, are the ONLY callers of
 * snt_ai_generate_with_constraints() for these two artifacts.
 *
 * @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// 15 min mirrors the analytics worker's */15 refresh cadence — a cached
// narrative/digest is never staler than the underlying data it describes.
const SN_ANALYTICS_AI_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

const SN_ANALYTICS_NARRATE_HOOK          = 'sn_analytics_narrate_generate';
const SN_ANALYTICS_NARRATE_LASTGOOD_OPT  = 'sn_analytics_narrate_lastgood';
const SN_ANALYTICS_DIGEST_HOOK           = 'sn_analytics_digest_generate';
const SN_ANALYTICS_DIGEST_LASTGOOD_OPT   = 'sn_analytics_digest_lastgood';

/**
 * Cache-key builder shared by both AI artifacts. Hashes the EXACT text that
 * would be sent to the model — prompt + system instruction + feature label +
 * the resolved model preference — so the key is provably complete: nothing
 * that could change the model's output can miss it, and nothing irrelevant
 * can force a spurious miss. $prompt/$system are themselves built from the
 * range-derived signals/summary/top_action (see the *_ai_prompt() builders
 * below), so the reporting window is implicitly covered — no separate range
 * component is needed in the key. The model-preference filter is applied
 * read-only here (mirroring, not duplicating, snt_ai_generate_with_constraints()'s
 * own resolution) so a live picker change (Front-End settings tab) still busts
 * the cache instead of serving text generated under a different model.
 *
 * @param string $namespace 'narrate' | 'digest' — keeps the two artifacts in
 *                           separate cache spaces even if a prompt collided.
 * @param string $prompt    The exact prompt string.
 * @param string $system    The exact system instruction string.
 * @param string $feature   The feature label passed to snt_ai_generate_with_constraints().
 * @return string Transient/option-safe cache key.
 */
function sn_analytics_ai_cache_key( $namespace, $prompt, $system, $feature ) {
	$model = defined( 'SN_AI_DEFAULT_MODEL' ) ? SN_AI_DEFAULT_MODEL : '';
	if ( function_exists( 'apply_filters' ) ) {
		$model = (string) apply_filters( 'snt_ai_model_preference', $model, $prompt, $system, $feature );
	}
	return 'sn_an_ai_' . substr( (string) $namespace, 0, 8 ) . '_' . md5( $prompt . "\x00" . $system . "\x00" . $feature . "\x00" . $model );
}

/**
 * Dedupe-and-fire a single background event for one of the two AI artifacts.
 * wp_next_scheduled()'s args match is exact, so a second render carrying the
 * SAME (summary, signals[, top_action]) — the common case, since concurrent
 * admin views of the same window produce identical args — is a true no-op; a
 * genuinely different window gets its own event. Never called from anywhere
 * but the two *_ai() cache-miss branches below.
 */
function sn_analytics_ai_schedule( $hook, $args ) {
	if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( time(), $hook, $args );
	}
}

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

/** Pure prompt/system builder for the narrate artifact — no I/O, no AI call. Shared by the cache-check and the out-of-band generator so they can never drift out of sync (same inputs → same key). */
function sn_analytics_narrate_ai_prompt( $signals ) {
	$facts = array();
	foreach ( $signals as $s ) {
		$facts[] = '- ' . (string) ( $s['plain_label'] ?? '' ) . ' [' . (string) ( $s['kind'] ?? '' ) . ', confidence ' . (string) ( $s['confidence'] ?? '' ) . ']';
	}
	$system = 'You are an analytics narrator. Narrate ONLY the signals given as bullet facts. NEVER invent or estimate a number that is not present. State uncertainty plainly. 2-3 sentences: what happened, why it may matter, one concrete next step. Plain text.';
	$prompt = "Signals:\n" . implode( "\n", $facts ) . "\n\nWrite the brief.";
	return array( $prompt, $system );
}

/**
 * AI narration — CACHE-READER ONLY (see file docblock). Returns null on
 * no-signals/wrapper-absent, on a genuine cache miss with no matching
 * last-good, or when the cached/last-good text is empty. Never calls
 * snt_ai_generate_with_constraints() itself.
 */
function sn_analytics_narrate_ai( $summary, $signals ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return null; }
	list( $prompt, $system ) = sn_analytics_narrate_ai_prompt( $signals );
	$feature = 'analytics_digest';
	$key     = sn_analytics_ai_cache_key( 'narrate', $prompt, $system, $feature );

	$cached = get_transient( $key );
	if ( is_array( $cached ) && '' !== trim( (string) ( $cached['narrative'] ?? '' ) ) ) {
		return $cached;
	}

	// Cache miss: schedule the out-of-band generator (deduped) and degrade to
	// a same-key last-good if the transient merely evicted rather than the
	// underlying signals changing — never call the AI client inline.
	sn_analytics_ai_schedule( SN_ANALYTICS_NARRATE_HOOK, array( $summary, $signals ) );

	$last_good = get_option( SN_ANALYTICS_NARRATE_LASTGOOD_OPT, array() );
	if ( is_array( $last_good ) && $key === (string) ( $last_good['key'] ?? '' ) && '' !== trim( (string) ( $last_good['narrative'] ?? '' ) ) ) {
		return array( 'narrative' => $last_good['narrative'], 'source' => 'ai', 'model' => $last_good['model'] ?? 'wp-ai-client' );
	}
	return null;
}

/**
 * Out-of-band generator for the narrate artifact — hooked to
 * SN_ANALYTICS_NARRATE_HOOK. The ONLY function that may call
 * snt_ai_generate_with_constraints() for this artifact; runs in cron
 * context, never on a render path. A WP_Error (e.g. the monthly budget cap)
 * leaves the cache untouched — the next render keeps degrading exactly as
 * before this hardening.
 */
function sn_analytics_narrate_ai_run( $summary, $signals ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return; }
	list( $prompt, $system ) = sn_analytics_narrate_ai_prompt( $signals );
	$feature = 'analytics_digest';
	$key     = sn_analytics_ai_cache_key( 'narrate', $prompt, $system, $feature );
	$text    = snt_ai_generate_with_constraints( $prompt, $system, 220, $feature );
	if ( ! is_string( $text ) || '' === trim( $text ) ) { return; }
	$payload = array( 'narrative' => '<p>' . esc_html( trim( $text ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
	set_transient( $key, $payload, SN_ANALYTICS_AI_CACHE_TTL );
	update_option( SN_ANALYTICS_NARRATE_LASTGOOD_OPT, array_merge( $payload, array( 'key' => $key ) ), false );
}
add_action( SN_ANALYTICS_NARRATE_HOOK, 'sn_analytics_narrate_ai_run', 10, 2 );

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

/** Pure prompt/system builder for the digest artifact — no I/O, no AI call. Shared by the cache-check and the out-of-band generator so they can never drift out of sync (same inputs → same key). */
function sn_analytics_digest_ai_prompt( $summary, $signals, $top_action = '' ) {
	$facts = array();
	if ( is_array( $summary ) ) {
		foreach ( array( 'views', 'visits' ) as $k ) {
			if ( isset( $summary[ $k ] ) ) { $facts[] = '- ' . ucfirst( $k ) . ' this period: ' . (string) (int) $summary[ $k ]; }
		}
	}
	foreach ( $signals as $s ) {
		$facts[] = '- ' . (string) ( $s['plain_label'] ?? '' ) . ' [' . (string) ( $s['kind'] ?? '' ) . ', confidence ' . (string) ( $s['confidence'] ?? '' ) . ']';
	}
	if ( '' !== trim( (string) $top_action ) ) {
		$facts[] = '- Top recommended action: ' . trim( (string) $top_action );
	}
	$system = 'You are writing a weekly analytics executive digest. Use ONLY the bullet facts given. NEVER invent or estimate a number that is not present. State uncertainty plainly. Two short paragraphs: (1) what happened and why it matters; (2) what to do next, concretely. Plain text.';
	$prompt = "Facts:\n" . implode( "\n", $facts ) . "\n\nWrite the weekly digest.";
	return array( $prompt, $system );
}

/**
 * AI weekly digest — CACHE-READER ONLY (see file docblock). Longer-form than
 * narrate(): two short paragraphs over the descriptive summary + every
 * signal's plain_label + the top action. Returns null on no-signals /
 * wrapper-absent, a genuine cache miss with no matching last-good, or an
 * empty cached/last-good digest. Never calls snt_ai_generate_with_constraints()
 * itself.
 *
 * @param array  $summary    Descriptive summary (views/visits).
 * @param array  $signals    Signal[] for the period.
 * @param string $top_action The top deterministic recommendation card's title; '' = omit (v9.38.0, D2).
 */
function sn_analytics_digest_ai( $summary, $signals, $top_action = '' ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return null; }
	list( $prompt, $system ) = sn_analytics_digest_ai_prompt( $summary, $signals, $top_action );
	$feature = 'analytics_digest_weekly';
	$key     = sn_analytics_ai_cache_key( 'digest', $prompt, $system, $feature );

	$cached = get_transient( $key );
	if ( is_array( $cached ) && '' !== trim( (string) ( $cached['digest'] ?? '' ) ) ) {
		return $cached;
	}

	// Cache miss: schedule the out-of-band generator (deduped) and degrade to
	// a same-key last-good if the transient merely evicted rather than the
	// underlying summary/signals/top_action changing — never call the AI
	// client inline.
	sn_analytics_ai_schedule( SN_ANALYTICS_DIGEST_HOOK, array( $summary, $signals, $top_action ) );

	$last_good = get_option( SN_ANALYTICS_DIGEST_LASTGOOD_OPT, array() );
	if ( is_array( $last_good ) && $key === (string) ( $last_good['key'] ?? '' ) && '' !== trim( (string) ( $last_good['digest'] ?? '' ) ) ) {
		return array( 'digest' => $last_good['digest'], 'source' => 'ai', 'model' => $last_good['model'] ?? 'wp-ai-client' );
	}
	return null;
}

/**
 * Out-of-band generator for the digest artifact — hooked to
 * SN_ANALYTICS_DIGEST_HOOK. The ONLY function that may call
 * snt_ai_generate_with_constraints() for this artifact; runs in cron
 * context, never on a render path. A WP_Error (e.g. the monthly budget cap)
 * leaves the cache untouched — the next render keeps degrading exactly as
 * before this hardening.
 */
function sn_analytics_digest_ai_run( $summary, $signals, $top_action = '' ) {
	if ( empty( $signals ) || ! function_exists( 'snt_ai_generate_with_constraints' ) ) { return; }
	list( $prompt, $system ) = sn_analytics_digest_ai_prompt( $summary, $signals, $top_action );
	$feature = 'analytics_digest_weekly';
	$key     = sn_analytics_ai_cache_key( 'digest', $prompt, $system, $feature );
	$text    = snt_ai_generate_with_constraints( $prompt, $system, 500, $feature );
	if ( ! is_string( $text ) || '' === trim( $text ) ) { return; }
	$payload = array( 'digest' => '<p>' . nl2br( esc_html( trim( $text ) ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
	set_transient( $key, $payload, SN_ANALYTICS_AI_CACHE_TTL );
	update_option( SN_ANALYTICS_DIGEST_LASTGOOD_OPT, array_merge( $payload, array( 'key' => $key ) ), false );
}
add_action( SN_ANALYTICS_DIGEST_HOOK, 'sn_analytics_digest_ai_run', 10, 3 );

/**
 * Public weekly digest (the seam, mirroring sn_analytics_narrate): filter
 * override → AI longer-form → deterministic floor. The wrapper returns WP_Error
 * when the monthly budget cap is hit; is_string() routes that to the floor.
 *
 * @param array  $summary    Descriptive summary (views/visits).
 * @param array  $signals    Signal[] for the period.
 * @param string $top_action The top deterministic recommendation card's title; '' = omit (v9.38.0, D2 — the digest is the screen's ONE voice).
 * @return array{digest:string, source:string, model:?string}
 */
function sn_analytics_digest( $summary, $signals, $top_action = '' ) {
	$override = function_exists( 'apply_filters' ) ? apply_filters( 'sn_analytics_digest', null, $summary, $signals ) : null;
	if ( is_array( $override ) && '' !== trim( (string) ( $override['digest'] ?? '' ) ) ) { return $override; }
	$ai = sn_analytics_digest_ai( $summary, $signals, $top_action );
	if ( is_array( $ai ) && '' !== trim( (string) ( $ai['digest'] ?? '' ) ) ) { return $ai; }
	return array( 'digest' => sn_analytics_digest_fallback( $summary, $signals ), 'source' => 'fallback', 'model' => null );
}
