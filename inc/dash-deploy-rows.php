<?php
/**
 * Signal & Noise — Dashboard deploy-run rows.
 *
 * Presentation for one GitHub Actions / wp-admin install run: the glyph, the
 * repo short name, the duration and the relative time. Split out of
 * admin-tab-dashboard.php in v11.28.0 — the orchestrator composes, it does not
 * format.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** "14m ago" / "—" for the Deploys card. */
function snt_dashboard_last_deploy_label( $runs ) {
	foreach ( $runs as $run ) {
		if ( ! empty( $run['created_at'] ) ) {
			$t = strtotime( $run['created_at'] );
			if ( $t ) {
				return human_time_diff( $t, time() ) . ' ago';
			}
		}
	}
	return '—';
}

/** Count runs within $window seconds of now. */
function snt_dashboard_count_recent_runs( $runs, $window ) {
	$cutoff = time() - $window;
	$n      = 0;
	foreach ( $runs as $run ) {
		if ( empty( $run['created_at'] ) ) {
			continue;
		}
		$t = strtotime( $run['created_at'] );
		if ( $t && $t >= $cutoff ) {
			++$n;
		}
	}
	return $n;
}

function snt_dashboard_render_deploy_row( $run ) {
	$status_class = 'sn-deploy-row__status sn-deploy-row__status--';
	$status_icon  = snt_dashboard_run_glyph( $run, $status_class );
	$repo_short   = snt_dashboard_short_repo( $run['repo'] ?? '' );
	$ref          = $run['ref'] ?? '';
	$duration     = snt_dashboard_duration_label( $run['duration_s'] ?? null );
	$when         = snt_dashboard_relative_time( $run['created_at'] ?? '' );
	$href         = $run['html_url'] ?? '';

	echo '<li class="sn-deploy-row">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status_icon is built by snt_dashboard_run_glyph_html(): class/label are esc_attr/esc_html-escaped and the glyph is a hardcoded HTML entity.
	echo $status_icon; /* already escaped via helper */
	echo '<span class="sn-deploy-row__repo">' . esc_html( $repo_short ) . '</span>';
	echo '<span class="sn-deploy-row__ref"><code>' . esc_html( $ref ) . '</code></span>';
	echo '<span class="sn-deploy-row__duration">' . esc_html( $duration ) . '</span>';
	echo '<span class="sn-deploy-row__when">' . esc_html( $when ) . '</span>';
	if ( $href ) {
		echo '<a class="sn-deploy-row__link" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'View on GitHub', 'signal-and-noise-tools' ) . '">&#x2197;</a>';
	} else {
		echo '<span></span>';
	}
	echo '</li>';
}

function snt_dashboard_run_glyph( $run, $base_class ) {
	$status     = (string) ( $run['status'] ?? '' );
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	if ( 'in_progress' === $status || 'queued' === $status ) {
		return snt_dashboard_run_glyph_html( $base_class . 'warn', '&middot;', __( 'Running', 'signal-and-noise-tools' ) );
	}
	if ( 'success' === $conclusion ) {
		return snt_dashboard_run_glyph_html( $base_class . 'ok', '&#x2713;', __( 'Success', 'signal-and-noise-tools' ) );
	}
	if ( 'cancelled' === $conclusion || 'skipped' === $conclusion ) {
		return snt_dashboard_run_glyph_html( $base_class . 'warn', '&#x2298;', ucfirst( $conclusion ) );
	}
	return snt_dashboard_run_glyph_html( $base_class . 'err', '&#x2717;', $conclusion ? $conclusion : 'unknown' );
}

/**
 * Build a deploy-status glyph span. v6.47.0: the glyph is aria-hidden and the
 * status word is carried in a visually-hidden .screen-reader-text label (plus
 * the title for sighted hover), so AT announces the status instead of a bare,
 * inconsistently-read glyph. $glyph is a hardcoded entity (never user data).
 *
 * @param string $class HTML class.
 * @param string $glyph HTML entity for the status glyph.
 * @param string $label Status word.
 * @return string
 */
function snt_dashboard_run_glyph_html( $class, $glyph, $label ) {
	return '<span class="' . esc_attr( $class ) . '" title="' . esc_attr( $label ) . '">'
		. '<span aria-hidden="true">' . $glyph . '</span>'
		. '<span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
}

function snt_dashboard_short_repo( $repo ) {
	if ( str_ends_with( $repo, '-tools' ) ) {
		return 'plugin';
	}
	return $repo ? 'theme' : '';
}

function snt_dashboard_duration_label( $seconds ) {
	if ( ! is_int( $seconds ) ) {
		return '—';
	}
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	return sprintf( '%dm %ds', intdiv( $seconds, 60 ), $seconds % 60 );
}

function snt_dashboard_relative_time( $iso ) {
	if ( ! $iso ) {
		return '';
	}
	$t = strtotime( $iso );
	return $t ? human_time_diff( $t, time() ) . ' ago' : '';
}
