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

/**
 * Read one numeric summary field, or null when absent / null / non-numeric.
 * Absent ≡ null ≡ "not known" is DELIBERATE here (this layer only selects
 * wording): both route to the honest degraded phrasing — a number is never
 * fabricated from an unknown, and an unknown never suppresses a known one.
 *
 * @param mixed  $summary Range-totals summary (any caller shape).
 * @param string $key     Field name.
 * @return int|null
 */
function sn_analytics_summary_num( $summary, $key ) {
	return ( is_array( $summary ) && isset( $summary[ $key ] ) && is_numeric( $summary[ $key ] ) ) ? (int) $summary[ $key ] : null;
}

/**
 * The four honest-vocabulary counts (spec §4) from a summary, with the legacy
 * ungated `visits` honoured for what it always WAS: unique visitor-DAYS.
 *
 * @param mixed $summary Range-totals summary.
 * @return array{views:?int, gated:?int, days:?int, viewless:?int, violation:bool}
 */
function sn_analytics_summary_vocabulary( $summary ) {
	$days = sn_analytics_summary_num( $summary, 'unique_visitor_days' );
	if ( null === $days ) {
		$days = sn_analytics_summary_num( $summary, 'visits' ); // legacy ungated count ≡ visitor-days.
	}
	return array(
		'views'     => sn_analytics_summary_num( $summary, 'views' ),
		'gated'     => sn_analytics_summary_num( $summary, 'pageview_visits' ),
		'days'      => $days,
		'viewless'  => sn_analytics_summary_num( $summary, 'viewless_visits' ),
		'violation' => is_array( $summary ) && ! empty( $summary['integrity_violation'] ),
	);
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
	$system = 'You are an analytics narrator. Narrate ONLY the signals given as bullet facts. NEVER invent or estimate a number that is not present.'
		. ' In these signals "visits" counts unique visitor-days (any beacon day, including feed readers with zero pageviews), not pageview-gated visits; visitor-days exceeding views is structural, never an anomaly.'
		. ' 2-3 short plain-English sentences: what happened, why it may matter, one concrete next step. State numbers plainly.'
		. ' NO statistical jargon in prose: never write sigma, σ, backtest, interval, robust, confidence, or point estimate.'
		. ' Plain prose only: NO markdown — no asterisks, no underscores, no headings, no bullet lists, no emojis.';
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
	// v9.64.2: the render path outputs plain text, so markdown marks must be
	// REMOVED (never escaped) before the text is stored — defense-in-depth
	// behind the instruction's markdown ban.
	$payload = array( 'narrative' => '<p>' . esc_html( trim( snt_ai_strip_markdown( trim( $text ) ) ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
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
	// v9.64.1 honest vocabulary (spec §4): "visits" is ONLY the gated
	// pageview_visits (the number the Overview KPI shows); the ungated count is
	// unique visitor-DAYS and is named exactly that. A legacy summary carrying
	// only the deprecated pair degrades to "views across visitor-days" — it
	// never re-labels the ungated count "visits".
	$v    = sn_analytics_summary_vocabulary( $summary );
	$line = '';
	if ( null !== $v['views'] && null !== $v['gated'] && null !== $v['days'] && null !== $v['viewless'] ) {
		$line = sprintf(
			'This period: %s views, %s visits (%s visitor-days, %s of them viewless).',
			number_format( (float) $v['views'] ),
			number_format( (float) $v['gated'] ),
			number_format( (float) $v['days'] ),
			number_format( (float) $v['viewless'] )
		);
	} elseif ( null !== $v['views'] && null !== $v['days'] ) {
		$line = sprintf( 'This period: %s views across %s visitor-days.', number_format( (float) $v['views'] ), number_format( (float) $v['days'] ) );
	} elseif ( null !== $v['views'] ) {
		$line = sprintf( 'This period: %s views.', number_format( (float) $v['views'] ) );
	} elseif ( null !== $v['days'] ) {
		$line = sprintf( 'This period: %s visitor-days.', number_format( (float) $v['days'] ) );
	}
	if ( '' !== $line && $v['violation'] ) {
		// The impossible case (views < pageview_visits) — the ONLY branch where
		// anomaly/alert language is honest (spec §5: the alarm is the feature).
		$line .= ' Integrity alert: views fell below pageview visits — arithmetically impossible; investigate the rollup.';
	}
	$head = '' !== $line ? '<p class="sn-an-digest-head">' . esc_html( $line ) . '</p>' : '';
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
	// v9.64.1 honest vocabulary: every count reaches the model WITH its
	// definition, and the views-vs-visitor-days gap arrives pre-explained (the
	// structural note) — so generated prose can never honestly claim "no
	// explanation is given in the data". Anomaly language is reserved for the
	// arithmetically impossible integrity_violation case alone.
	$facts = array();
	$v     = sn_analytics_summary_vocabulary( $summary );
	if ( null !== $v['views'] ) {
		$facts[] = '- Views this period: ' . $v['views'];
	}
	if ( null !== $v['gated'] ) {
		$facts[] = '- Visits this period (visitor-days with at least one pageview — the gated headline metric): ' . $v['gated'];
	}
	if ( null !== $v['days'] ) {
		$facts[] = ( null !== $v['gated'] )
			? '- Unique visitor-days this period (any beacon activity, including feed/RSS reads with zero pageviews): ' . $v['days']
			: '- Visitor-days this period (unique visitor-days — may include viewless feed/RSS days; NOT pageview-gated visits): ' . $v['days'];
	}
	if ( null !== $v['viewless'] ) {
		$facts[] = '- Viewless visitor-days (feed readers and beacon-only visits — no pageview): ' . $v['viewless'];
	}
	if ( $v['violation'] ) {
		$facts[] = '- DATA INTEGRITY ANOMALY: views' . ( null !== $v['views'] ? ' (' . $v['views'] . ')' : '' )
			. ' fell below pageview visits' . ( null !== $v['gated'] ? ' (' . $v['gated'] . ')' : '' )
			. ' — arithmetically impossible for this pipeline; a genuine anomaly worth flagging.';
	} elseif ( null !== $v['views'] && null !== $v['days'] && null !== $v['viewless'] && $v['days'] > $v['views'] && $v['viewless'] > 0 ) {
		$facts[] = sprintf(
			'- Structural note: %d visitor-days exceed %d views because %d visitor-days were viewless (feed readers and beacon-only visits) — a structural property of the measurement, fully explained by the data, not an anomaly.',
			$v['days'],
			$v['views'],
			$v['viewless']
		);
	}
	foreach ( $signals as $s ) {
		$facts[] = '- ' . (string) ( $s['plain_label'] ?? '' ) . ' [' . (string) ( $s['kind'] ?? '' ) . ', confidence ' . (string) ( $s['confidence'] ?? '' ) . ']';
	}
	if ( '' !== trim( (string) $top_action ) ) {
		$facts[] = '- Top recommended action: ' . trim( (string) $top_action );
	}
	// v9.64.2 voice contract: the facts were right (v9.64.1) but the prose read
	// like a stats appendix and rendered raw markdown. The audience is the site
	// owner glancing at a phone; the deterministic signal chips + transparency
	// footer directly below the digest already carry every σ/backtest/interval —
	// prose that repeats them is duplication, not information.
	$system = 'You are writing a weekly analytics digest for the site owner, who reads it at a glance on a phone. Use ONLY the bullet facts given. NEVER invent or estimate a number that is not present.'
		. ' Vocabulary: "visits" means visitor-days with at least one pageview; "visitor-days" is the ungated unique visitor-day count and is never to be called "visits".'
		. ' When a Structural note fact is present, visitor-days exceeding views is fully explained by the viewless count — give that explanation in one short clause; NEVER describe the gap as unusual, unexplained, or an anomaly.'
		. ' The ONLY genuine anomaly is a DATA INTEGRITY ANOMALY fact; when present, flag it in at most one plain sentence.'
		. ' Voice: at most 4-5 short plain-English sentences, plus optionally one final line starting "Worth a look:". State numbers plainly (47 views, 40 visits).'
		. ' NO statistical jargon in prose: never write sigma, σ, backtest, interval, robust, confidence, or point estimate — the signal chips and transparency footer under this digest already carry that machinery.'
		. ' Mention a forecast only when it is actionable, in plain words ("expect a quiet week") — never as numbers with intervals.'
		. ' Plain prose only: NO markdown — no asterisks, no underscores, no headings, no bullet lists, no emojis.';
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
	// v9.64.2: strip markdown before storing (see sn_analytics_narrate_ai_run).
	$payload = array( 'digest' => '<p>' . nl2br( esc_html( trim( snt_ai_strip_markdown( trim( $text ) ) ) ) ) . '</p>', 'source' => 'ai', 'model' => 'wp-ai-client' );
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
