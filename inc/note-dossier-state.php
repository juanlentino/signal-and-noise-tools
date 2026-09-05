<?php
/**
 * Signal & Noise Tools — the note dossier: the operating-state block.
 *
 * A glance and a door: the last edge verdict for this URL, whether Google
 * indexes it, whether the sitemap carries it, the scheduled fragments that
 * target it. Each fact opens the S&N Dashboard leaf that owns it.
 *
 * The probe log is a twenty-row SITE-WIDE buffer, newest first by insertion;
 * a note's verdict is evicted by twenty later saves of other notes, so "no
 * probe in the last 20" is the honest absence, never "fresh". Rows are
 * matched on post_id, not url: url is the permalink at probe time.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The newest current-detector probe row for a post, or null.
 *
 * @param int $post_id
 * @return array{result:string,time:int,url:string,escalated:bool}|null
 */
function sn_note_dossier_last_probe( $post_id ) {
	if ( ! defined( 'SN_CF_PROBE_LOG_OPT' ) || ! defined( 'SN_CF_PROBE_ALGO' ) ) {
		return null;
	}
	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		return null;
	}
	foreach ( $log as $row ) {
		if ( ! is_array( $row ) || (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO || (int) ( $row['post_id'] ?? 0 ) !== (int) $post_id ) {
			continue;
		}
		$result = (string) ( $row['result'] ?? '' );
		return array(
			'result'    => in_array( $result, array( 'fresh', 'stale' ), true ) ? $result : 'unknown',
			'time'      => (int) ( $row['time'] ?? 0 ),
			'url'       => (string) ( $row['url'] ?? '' ),
			'escalated' => ! empty( $row['escalated'] ),
		);
	}
	return null;
}

/**
 * @param int $post_id
 * @return array<int,array<string,mixed>> Empty for an unpublished note.
 */
function sn_note_dossier_state( $post_id ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post || ! sn_note_dossier_is_public( $post ) ) {
		return array();
	}
	$blocks = array();
	$door   = static function ( $label, $slug, $sub ) {
		return function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( $label, snt_desktop_admin_url( $slug, $sub ) ) : null;
	};

	// ── Edge verdict ─────────────────────────────────────────────────────
	$cf_door = $door( __( 'Open Cloudflare in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-connections', 'cloudflare' );
	if ( function_exists( 'sn_cf_is_configured' ) && ! sn_cf_is_configured() ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), 'neutral', __( 'Edge purge not configured.', 'signal-and-noise-tools' ), __( 'No probe is ever written without a Cloudflare token and zone.', 'signal-and-noise-tools' ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
	} else {
		$probe = sn_note_dossier_last_probe( $post->ID );
		if ( null === $probe ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), 'neutral', __( 'No probe in the last 20 site-wide.', 'signal-and-noise-tools' ), __( 'Each save probes the edge two minutes later; the log keeps the newest twenty across the site. Absence is a gap, never a pass.', 'signal-and-noise-tools' ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
		} else {
			$tone = 'fresh' === $probe['result'] ? 'success' : ( 'stale' === $probe['result'] ? 'warning' : 'neutral' );
			$text = function_exists( 'snt_cf_freshness_headline' ) ? snt_cf_freshness_headline( $probe['result'] ) : $probe['result'];
			$meta = function_exists( 'snt_cf_freshness_phrase' ) ? snt_cf_freshness_phrase( $probe['result'], $probe['time'], time() ) : '';
			if ( $probe['escalated'] ) {
				$meta .= ' ' . __( 'A zone purge was forced.', 'signal-and-noise-tools' );
			}
			$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), $tone, $text, trim( $meta ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
		}
	}

	// ── Coverage ─────────────────────────────────────────────────────────
	$sc_door = $door( __( 'Open Search Console in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-monitoring', 'search-console' );
	$cov     = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	if ( ! is_array( $cov ) ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', __( 'Coverage inspection has never run.', 'signal-and-noise-tools' ), '', __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
	} else {
		$e = function_exists( 'snt_gsc_coverage_for_path' ) ? snt_gsc_coverage_for_path( (string) get_permalink( $post ) ) : null;
		if ( ! is_array( $e ) ) {
			$text = empty( $cov['complete'] ) ? __( 'Not yet inspected: a run is in progress or was interrupted.', 'signal-and-noise-tools' ) : __( 'Not inspected.', 'signal-and-noise-tools' );
			$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', $text, '', __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
		} elseif ( isset( $e['error'] ) ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'warning', __( 'Inspection failed.', 'signal-and-noise-tools' ), (string) ( $e['message'] ?? $e['error'] ), __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
		} else {
			$state = (string) ( $e['coverage_state'] ?? '' );
			$crawl = (string) ( $e['last_crawl_time'] ?? '' );
			$meta  = $state;
			if ( '' !== $crawl ) {
				$meta .= ( '' !== $meta ? '. ' : '' ) . sprintf( /* translators: %s: RFC3339 time. */ __( 'Last crawl %s.', 'signal-and-noise-tools' ), $crawl );
			}
			if ( true === ( $e['indexed'] ?? null ) ) {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'success', __( 'Indexed', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			} elseif ( false === ( $e['indexed'] ?? null ) ) {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'warning', __( 'Not indexed', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			} else {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', __( 'No coverage state', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			}
		}
	}

	// ── Sitemap ──────────────────────────────────────────────────────────
	if ( function_exists( 'the_seo_framework' ) ) {
		// The LIVE configuration: TSF serves the sitemap and this app does not
		// read its per-post exclusions. A gap, stated as one.
		$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'neutral', __( 'Sitemap membership not checked.', 'signal-and-noise-tools' ), __( 'The SEO Framework serves the sitemap here, and this app does not read its per-post exclusions. A gap, not a verdict.', 'signal-and-noise-tools' ), __( 'the sitemap', 'signal-and-noise-tools' ) );
	} else {
		$noindex = function_exists( 'sn_post_settings_get_noindex' ) && sn_post_settings_get_noindex( $post->ID );
		$canon   = function_exists( 'sn_post_settings_get_canonical_url' ) ? (string) sn_post_settings_get_canonical_url( $post->ID ) : '';
		if ( $noindex ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'warning', __( 'Not in the sitemap', 'signal-and-noise-tools' ), __( 'The note is marked noindex.', 'signal-and-noise-tools' ) );
		} elseif ( '' !== $canon ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'warning', __( 'Not in the sitemap', 'signal-and-noise-tools' ), sprintf( /* translators: %s: URL. */ __( 'The note declares a canonical URL elsewhere: %s', 'signal-and-noise-tools' ), $canon ) );
		} else {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'success', __( 'In the sitemap', 'signal-and-noise-tools' ), __( 'Published, indexable, canonical here.', 'signal-and-noise-tools' ) );
		}
	}

	// ── Scheduled fragments ──────────────────────────────────────────────
	$mine = array();
	if ( function_exists( 'sn_schedule_all' ) ) {
		foreach ( (array) sn_schedule_all() as $row ) {
			if ( is_array( $row ) && 'fragment' === (string) ( $row['target_type'] ?? '' ) && (string) ( $row['target_ref'] ?? '' ) === (string) $post->ID ) {
				$mine[] = $row;
			}
		}
	}
	$sched_door = $door( __( 'Open Scheduled in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-connections', 'scheduled-content' );
	if ( array() === $mine ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Scheduled fragments', 'signal-and-noise-tools' ), 'neutral', __( 'No scheduled fragments target this note.', 'signal-and-noise-tools' ), '', __( 'schedule', 'signal-and-noise-tools' ), $sched_door );
	} else {
		$now  = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
		$rows = array();
		foreach ( $mine as $row ) {
			$from   = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? sn_admin_schedule_fmt_gmt( $row['starts_at'] ?? '' ) : (string) ( $row['starts_at'] ?? '' );
			$until  = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? sn_admin_schedule_fmt_gmt( $row['ends_at'] ?? '' ) : (string) ( $row['ends_at'] ?? '' );
			$status = (string) ( $row['status'] ?? 'queued' );
			$open   = function_exists( 'sn_schedule_is_open' ) ? (bool) sn_schedule_is_open( $row['starts_at'] ?? null, $row['ends_at'] ?? null, $now ) : false;
			$rows[] = array(
				'window' => ( '' !== $from ? $from : __( 'always', 'signal-and-noise-tools' ) ) . ' → ' . ( '' !== $until ? $until : __( 'never', 'signal-and-noise-tools' ) ),
				'status' => array( 'text' => $status, 'tone' => 'active' === $status ? 'success' : ( 'error' === $status ? 'warning' : 'neutral' ) ),
				'now'    => $open ? __( 'visible', 'signal-and-noise-tools' ) : __( 'hidden', 'signal-and-noise-tools' ),
			);
		}
		$blocks[] = sn_note_dossier_table(
			'state',
			__( 'Scheduled fragments', 'signal-and-noise-tools' ),
			array( array( 'key' => 'window', 'label' => __( 'Window', 'signal-and-noise-tools' ) ), array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ), array( 'key' => 'now', 'label' => __( 'Now', 'signal-and-noise-tools' ) ) ),
			$rows,
			__( 'schedule', 'signal-and-noise-tools' ),
			$sched_door
		);
	}
	return $blocks;
}
