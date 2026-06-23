<?php
/**
 * Signal & Noise Tools — AI prepopulation at publish (engine).
 *
 * On the first transition into published/scheduled, schedules a one-off cron
 * event that auto-fills the meta description, excerpt, and OG card title —
 * empty fields only (never overwrites manual content). Deferred via
 * wp_schedule_single_event so the publish request isn't blocked by AI latency.
 *
 * Calls the SN engine functions DIRECTLY, not the ability wrappers: the
 * abilities check current_user_can('edit_post'), but WP-Cron has no logged-in
 * user, so the ability layer would reject prepop. The meta-description and
 * excerpt impls gate only on AI availability. The OG card title is reached via
 * snt_ai_og_card_title_write() — its no-cap writer — because the matching
 * *_impl() entry now adds an edit_post cap check (v6.39.2) that cron would fail.
 *
 * The excerpt's wp_update_post() re-fires save_post, but sn_post_settings_save()
 * returns on a missing nonce before any meta write (cron has no $_POST), so it
 * can't wipe SN meta. The notice + dismiss surface lives in
 * inc/ai-prepopulate-notice.php; this file owns the trigger, engine, and the
 * sentinel-state helpers that surface consumes.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimum body words before prepop will spend AI calls. Filterable.
 */
if ( ! defined( 'SNT_PREPOP_MIN_WORDS' ) ) {
	define( 'SNT_PREPOP_MIN_WORDS', 50 );
}

/**
 * Max random jitter (seconds) added to the prepop schedule time. A bulk
 * publish (e.g. an import) transitions many posts at once; without jitter
 * every prepop event fires on the same cron tick and hits the provider in
 * lockstep. Spreading them over a window smooths the load. Filterable.
 *
 * @since 6.39.2
 */
if ( ! defined( 'SNT_PREPOP_SCHEDULE_JITTER_MAX' ) ) {
	define( 'SNT_PREPOP_SCHEDULE_JITTER_MAX', 300 );
}

/**
 * Daily ceiling on total AI calls before AUTO-prepop yields. Auto-prepop is
 * the unattended path — a bulk publish could otherwise fire up to 3 AI calls
 * per post unbounded. When the trailing-24h call count (across all features,
 * via snt_ai_usage_summary) is at/over this, prepop skips silently for the
 * post; manual/attended AI actions are user-initiated and never gated here.
 * Filterable.
 *
 * @since 6.39.2
 */
if ( ! defined( 'SNT_PREPOP_DAILY_CALL_CEILING' ) ) {
	define( 'SNT_PREPOP_DAILY_CALL_CEILING', 100 );
}

/**
 * Schedule prepop on the first transition into publish/scheduled.
 *
 * Fires only when ENTERING a public/scheduled state from a non-public one
 * (never on re-saves or the future->publish auto-publish, which the empty-only
 * guard covers anyway). Scoped to SN_POST_SETTINGS_POST_TYPES — the same set
 * for which the SN meta keys are registered and the meta box + notice render,
 * so prepop can never fire where its result has nowhere to surface.
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function snt_prepop_on_transition( $new_status, $old_status, $post ) {
	$public_now    = in_array( $new_status, array( 'publish', 'future' ), true );
	$public_before = in_array( $old_status, array( 'publish', 'future' ), true );
	if ( ! $public_now || $public_before ) {
		return;
	}
	if ( ! in_array( $post->post_type, SN_POST_SETTINGS_POST_TYPES, true ) ) {
		return;
	}
	// v6.39.2: jitter the schedule so a bulk publish doesn't fire every prepop
	// event on the same cron tick (provider lockstep).
	$jitter = (int) apply_filters( 'snt_prepop_schedule_jitter_max', SNT_PREPOP_SCHEDULE_JITTER_MAX );
	$delay  = ( $jitter > 0 && function_exists( 'wp_rand' ) ) ? (int) wp_rand( 0, $jitter ) : 0;
	wp_schedule_single_event( time() + $delay, 'snt_prepop_event', array( (int) $post->ID ) );
}
add_action( 'transition_post_status', 'snt_prepop_on_transition', 10, 3 );

/**
 * Cron callback: generate + persist the empty fields.
 *
 * @param int $post_id
 */
function snt_run_prepop( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
		return;
	}

	// v6.39.2 cost guard: auto-prepop yields once the day's total AI spend is
	// already high, so an unattended bulk publish can't run up an unbounded
	// number of calls (up to 3 per post). Reuses the usage log via
	// snt_ai_usage_summary; manual AI actions are not gated here.
	if ( function_exists( 'snt_ai_usage_summary' ) ) {
		$ceiling = (int) apply_filters( 'snt_prepop_daily_call_ceiling', SNT_PREPOP_DAILY_CALL_CEILING );
		if ( $ceiling > 0 ) {
			$today = snt_ai_usage_summary( 1 );
			if ( is_array( $today ) && (int) ( $today['calls'] ?? 0 ) >= $ceiling ) {
				return;
			}
		}
	}

	$min   = (int) apply_filters( 'snt_prepop_min_words', SNT_PREPOP_MIN_WORDS );
	$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
	if ( $words < $min ) {
		return;
	}

	// 1. Meta description (post meta — written here).
	if ( '' === (string) get_post_meta( $post_id, '_sn_meta_description', true )
		&& function_exists( 'snt_ai_meta_desc_impl' ) ) {
		$res = snt_ai_meta_desc_impl( $post_id, true );
		if ( is_array( $res ) && ! empty( $res['description'] ) ) {
			update_post_meta( $post_id, '_sn_meta_description', sanitize_textarea_field( $res['description'] ) );
			update_post_meta( $post_id, '_sn_autogen_meta_description', '1' );
		}
	}

	// 2. OG card title (writer self-persists the meta + rebuilds the PNG).
	//    v6.39.2: call the no-cap WRITER, not the *_impl entry — the impl now
	//    adds an edit_post cap check that WP-Cron (no logged-in user) would fail.
	if ( '' === (string) get_post_meta( $post_id, '_sn_og_card_title', true )
		&& function_exists( 'snt_ai_og_card_title_write' ) ) {
		$res = snt_ai_og_card_title_write( $post_id );
		if ( is_array( $res ) && ! empty( $res['title'] ) ) {
			update_post_meta( $post_id, '_sn_autogen_og_card_title', '1' );
		}
	}

	// 3. Excerpt (core post_excerpt field — wp_update_post last; it is the only
	//    re-entrancy point, and sn_post_settings_save no-ops without a nonce).
	//    Re-read the post in case the OG step changed it. Sentinel is set ONLY
	//    on a confirmed write so the notice can't claim a failed generation.
	$post = get_post( $post_id );
	if ( $post && '' === (string) $post->post_excerpt
		&& function_exists( 'snt_ai_excerpt_impl' ) ) {
		$res = snt_ai_excerpt_impl( $post_id, true );
		if ( is_array( $res ) && ! empty( $res['excerpt'] ) ) {
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => sanitize_textarea_field( $res['excerpt'] ),
				),
				true
			);
			if ( ! is_wp_error( $updated ) && $updated ) {
				update_post_meta( $post_id, '_sn_autogen_excerpt', '1' );
			}
		}
	}
}
add_action( 'snt_prepop_event', 'snt_run_prepop', 10, 1 );

/**
 * Map of sentinel meta key → human label for the notice. Single source of
 * truth for which fields prepop tracks; consumed by the notice surface.
 *
 * @return array<string,string>
 */
function sn_prepop_fields() {
	return array(
		'_sn_autogen_meta_description' => 'meta description',
		'_sn_autogen_excerpt'          => 'excerpt',
		'_sn_autogen_og_card_title'    => 'OG card title',
	);
}

/**
 * Clear all prepop sentinels for a post (the notice shows once, then clears on
 * the next editor save or an explicit dismiss). Called from
 * sn_post_settings_save() and the dismiss REST route.
 *
 * @param int $post_id
 */
function sn_prepop_clear_sentinels( $post_id ) {
	foreach ( array_keys( sn_prepop_fields() ) as $sentinel ) {
		delete_post_meta( (int) $post_id, $sentinel );
	}
}
