<?php
/**
 * Signal & Noise Tools — the contrast report's rendered tier: what the live
 * pages show, beside what the stylesheets say.
 *
 * The contrast report's two counts (token pairs that would fail if rendered
 * together; placement-dependent pairings) are possibilities a stylesheet scan
 * cannot resolve: where a thing lands decides whether it passes. The theme's
 * contrast.yml (2026-10-06) measures where things land: every sitemap page,
 * light and dark, every text pair at AA and every color-only link at 3:1. This
 * reads its latest completed run from the public GitHub API (no auth, fixed
 * URLs) and says it in one line above those counts, so they read as the
 * possibilities they are.
 *
 * Three reads, cached six hours (thirty minutes after a failed read): the
 * latest run, its job, the job's annotations. The runner prints one
 * `::notice title=contrast-summary::{json}` with the page and pair counts and
 * one `::error::` per failure; the failure count is the annotations at level
 * failure. An unreachable API is a gap in evidence, said in gray, never a
 * verdict (the ledger-CI check's convention).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The cache key carries the verdict's semantics: v2 since a green run without
// a summary stopped reading as ok, so a v1 'ok' cached before that is never
// served (Codex on #1948).
const SN_CONTRAST_RENDERED_CACHE = 'snt_contrast_rendered_v2';
const SN_CONTRAST_RENDERED_RUNS_URL = 'https://api.github.com/repos/juanlentino/signal-and-noise/actions/workflows/contrast.yml/runs?status=completed&per_page=1';

/**
 * Pure evaluator: the decoded run, job and annotations in, one verdict out.
 *
 * @param mixed $runs        Decoded workflow-runs response.
 * @param mixed $annotations Decoded check-run annotations list (or null).
 * @return array{state:string,failures:int,pages:int,checked:int,links:int,at:string,url:string}
 *               state: ok | red | unknown | none.
 */
function snt_contrast_rendered_evaluate( $runs, $annotations ) {
	$v = array( 'state' => 'unknown', 'reason' => 'api', 'failures' => 0, 'pages' => 0, 'checked' => 0, 'links' => 0, 'at' => '', 'url' => '' );
	if ( ! is_array( $runs ) || ! isset( $runs['workflow_runs'] ) || ! is_array( $runs['workflow_runs'] ) ) {
		return $v;
	}
	if ( array() === $runs['workflow_runs'] ) {
		$v['state'] = 'none';
		return $v;
	}
	$run      = (array) $runs['workflow_runs'][0];
	$v['at']  = substr( (string) ( $run['updated_at'] ?? '' ), 0, 10 );
	$v['url'] = (string) ( $run['html_url'] ?? '' );
	// The total comes from the runner's summary: GitHub keeps at most ten
	// error annotations a step, so counting them understates a bad run (Codex
	// on #1942). The annotation count is only the fallback for a run whose
	// summary is missing.
	$total = null;
	$measured = false;
	foreach ( is_array( $annotations ) ? $annotations : array() as $a ) {
		$a = (array) $a;
		if ( 'failure' === ( $a['annotation_level'] ?? '' ) ) {
			$v['failures']++;
		}
		if ( 'contrast-summary' === ( $a['title'] ?? '' ) ) {
			$s            = json_decode( (string) ( $a['message'] ?? '' ), true );
			// A summary is evidence only in its full shape and with pages
			// measured: a malformed or empty one is not a pass (Codex on #1948).
			if ( ! is_array( $s ) || ! isset( $s['pages'], $s['checked'], $s['links'] ) || (int) $s['pages'] < 1 ) {
				continue;
			}
			$measured     = true;
			$v['pages']   = (int) ( $s['pages'] ?? 0 );
			$v['checked'] = (int) ( $s['checked'] ?? 0 );
			$v['links']   = (int) ( $s['links'] ?? 0 );
			$total        = isset( $s['failures'] ) ? (int) $s['failures'] : null;
		}
	}
	if ( null !== $total ) {
		$v['failures'] = $total;
	}
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	if ( 'success' === $conclusion ) {
		// Green is a pass only with the runner's summary: an inconclusive run
		// (exit 2: sitemap unreadable, home blocked, a page that did not
		// render) is turned into a green job with a warning and no summary.
		$v['state'] = $measured ? 'ok' : 'unknown';
		if ( ! $measured ) {
			$v['reason'] = 'inconclusive';
		}
	} elseif ( 'failure' === $conclusion ) {
		// A red run whose failures could not be read (annotations unfetched, or
		// none counted) is unknown, never "0 failures" (Codex on #1942).
		$v['state'] = $v['failures'] > 0 ? 'red' : 'unknown';
	}
	return $v;
}

/**
 * GET one GitHub API URL as decoded JSON, or null.
 *
 * @param string $url URL (fixed, or read from a GitHub response).
 * @return mixed
 */
function snt_contrast_rendered_get( $url ) {
	if ( 0 !== strpos( $url, 'https://api.github.com/' ) || ! function_exists( 'wp_remote_get' ) ) {
		return null;
	}
	$resp = wp_remote_get( $url, array(
		'timeout'     => 5,
		'redirection' => 0,
		'headers'     => array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' contrast-rendered',
		),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	return json_decode( (string) wp_remote_retrieve_body( $resp ), true );
}

/**
 * The verdict, cached.
 *
 * @return array snt_contrast_rendered_evaluate() shape.
 */
function snt_contrast_rendered() {
	// Outside WordPress (a test harness) there is no cache and no HTTP: unknown,
	// never a network call from a unit test.
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'wp_remote_get' ) ) {
		return snt_contrast_rendered_evaluate( null, null );
	}
	$cached = get_transient( SN_CONTRAST_RENDERED_CACHE );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$runs        = snt_contrast_rendered_get( SN_CONTRAST_RENDERED_RUNS_URL );
	$annotations = null;
	$run         = is_array( $runs ) && ! empty( $runs['workflow_runs'][0] ) ? (array) $runs['workflow_runs'][0] : array();
	if ( isset( $run['jobs_url'] ) ) {
		$jobs = snt_contrast_rendered_get( (string) $run['jobs_url'] );
		$cr   = is_array( $jobs ) && ! empty( $jobs['jobs'][0]['check_run_url'] ) ? (string) $jobs['jobs'][0]['check_run_url'] : '';
		if ( '' !== $cr ) {
			$annotations = snt_contrast_rendered_get( $cr . '/annotations?per_page=100' );
		}
	}
	$v = snt_contrast_rendered_evaluate( $runs, $annotations );
	set_transient( SN_CONTRAST_RENDERED_CACHE, $v, 'unknown' === $v['state'] ? 30 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	return $v;
}

/**
 * The line, as escaped HTML: the measured result first, the run linked. A red
 * result links to the run that names each failure (an amber line links to its
 * fix); an unknown one is said plainly, never as a pass.
 *
 * @param array  $v     Verdict.
 * @param string $class Paragraph class (the surface's hint class).
 * @return string
 */
function snt_contrast_rendered_html( array $v, $class ) {
	$link = static function ( $text ) use ( $v ) {
		return '' !== $v['url'] ? '<a href="' . esc_url( $v['url'] ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
	};
	switch ( $v['state'] ) {
		case 'ok':
			$scope = $v['pages'] > 0
				/* translators: 1: page count, 2: text pair count, 3: link count */
				? sprintf( __( 'every text pair at AA and every color-only link at 3:1 on %1$d pages (%2$d pairs, %3$d links), light and dark', 'signal-and-noise-tools' ), $v['pages'], $v['checked'], $v['links'] )
				: __( 'every text pair at AA and every color-only link at 3:1, light and dark', 'signal-and-noise-tools' );
			/* translators: 1: what passed, 2: date of the run, linked */
			$text = sprintf( esc_html__( 'Rendered on the live site: %1$s (%2$s). The counts below are what a stylesheet alone can say; this is what the pages show.', 'signal-and-noise-tools' ), esc_html( $scope ), $link( $v['at'] ) );
			break;
		case 'red':
			/* translators: 1: failure count, linked to the run that names each */
			$text = sprintf( esc_html__( 'Rendered on the live site: %1$s below AA or color-only under 3:1. The run names each page, element and ratio.', 'signal-and-noise-tools' ), $link( sprintf( _n( '%d failure', '%d failures', $v['failures'], 'signal-and-noise-tools' ), $v['failures'] ) ) );
			break;
		case 'none':
			$text = esc_html__( 'Rendered on the live site: not measured yet (the theme\'s contrast.yml has no completed run).', 'signal-and-noise-tools' );
			break;
		default:
			if ( 'inconclusive' === ( $v['reason'] ?? '' ) ) {
				/* translators: %s: date of the run, linked */
				$text = sprintf( esc_html__( 'Rendered on the live site: unknown. The last run (%s) could not measure (the sitemap, a page or the edge failed), so it carries no result; its warning says which. Rechecks within thirty minutes.', 'signal-and-noise-tools' ), $link( '' !== $v['at'] ? $v['at'] : __( 'the run', 'signal-and-noise-tools' ) ) );
				break;
			}
			$text = esc_html__( 'Rendered on the live site: unknown right now (the GitHub API did not answer; an outage is a gap in evidence, not a result). Retries within thirty minutes.', 'signal-and-noise-tools' );
	}
	return '<p class="' . esc_attr( $class ) . '">' . $text . '</p>';
}
