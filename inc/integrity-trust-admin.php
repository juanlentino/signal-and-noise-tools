<?php
/**
 * Signal & Noise Tools — Integrity → Trust checks leaf.
 *
 * WHY THIS EXISTS. Four of the eighteen Content-Health checks are not about
 * content at all — they are the site's trust surface:
 *
 *   provenance_integrity  the payload-hash / live-twin / ledger+key triangle
 *   ledger_ci             the public ledger's own daily verify workflow
 *   rights_signals        the live rights surface at the edge (tdmrep, RSL, TDM)
 *   rights_anchored       whether those rights bytes are still being re-anchored
 *
 * They were reachable only as four rows inside an eighteen-row Health tab,
 * interleaved with missing alt text and stale posts. Nothing said they belonged
 * together, and nothing connected them to the Provenance console sitting on
 * another tab entirely — even though that console is the thing they are checking.
 * The v10.46.0 IA audit named Tools a junk drawer; this is what gives the tab a
 * concept instead of padding.
 *
 * WHAT THIS DELIBERATELY IS NOT. It runs nothing. It fetches nothing. It
 * registers no route, no cron, no ability. Every number here is read out of the
 * health scan that already ran (`sn_health_last_scan()`, an autoload=no option),
 * so opening this leaf costs one option read. The four checks keep their single
 * home in the scan; this is a second VIEW of them, not a second COPY — which is
 * the same rule snt_analytics_render_mirrors() follows in the other direction.
 *
 * A stale reading is therefore possible and is stated on the page rather than
 * hidden: the scan age is shown, and re-running is a link to Measurement →
 * Health, not a button here (one scan surface, one owner).
 *
 * @package SignalNoiseTools
 * @since 10.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four health-check keys that make up the trust surface, in reading order:
 * what is claimed (provenance), whether the claim record is sound (ledger CI),
 * what machines are told (rights signals), and whether that telling is still
 * being anchored (rights anchored).
 *
 * Pure data so tests/integrity-trust-admin.php can assert every key still exists
 * in the health registry — a check that gets renamed or dropped must not leave a
 * silently empty card here.
 *
 * @since 10.47.0
 * @return array<string,array{label:string,blurb:string}>
 */
function snt_trust_check_keys() {
	return array(
		'provenance_integrity' => array(
			'label' => __( 'Provenance triangle', 'signal-and-noise-tools' ),
			'blurb' => __( 'Payload hash, the live .json twin, and the public ledger record plus its key file still agree.', 'signal-and-noise-tools' ),
		),
		'ledger_ci'            => array(
			'label' => __( 'Ledger CI', 'signal-and-noise-tools' ),
			'blurb' => __( 'The public ledger verifies itself daily. This is that workflow&rsquo;s latest verdict.', 'signal-and-noise-tools' ),
		),
		'rights_signals'       => array(
			'label' => __( 'Rights signals', 'signal-and-noise-tools' ),
			'blurb' => __( 'The TDM reservation, RSL license, and robots Content-Signal lines are live at the edge.', 'signal-and-noise-tools' ),
		),
		'rights_anchored'      => array(
			'label' => __( 'Rights anchoring', 'signal-and-noise-tools' ),
			'blurb' => __( 'The rights bytes being served right now still match the newest ledger record.', 'signal-and-noise-tools' ),
		),
	);
}

/**
 * How many finding notes the Trust table prints per check before deferring to
 * the Health tab. Declared ONCE: the slice and the "is anything hidden" test
 * are the same number, and they used to be two separate literals.
 */
const SNT_TRUST_FINDINGS_CAP = 3;

/**
 * Split a check's findings into the slice shown here and the count deferred to
 * Health.
 *
 * The cap used to be written twice: once as the slice length, once as the
 * threshold in a separate count guard on the "see more" link. Two literals that
 * must agree, with nothing enforcing it: change one and the link either appears
 * with nothing behind it or disappears while rows are still hidden. Deriving
 * both from one array makes shown + hidden == total true by construction rather
 * than by keeping two numbers matching.
 *
 * @param mixed $findings The check's findings list (tolerates null).
 * @return array{shown:array, hidden:int}
 */
function snt_trust_findings_split( $findings ) {
	$all = array_values( (array) $findings );
	return array(
		'shown'  => array_slice( $all, 0, SNT_TRUST_FINDINGS_CAP ),
		'hidden' => max( 0, count( $all ) - SNT_TRUST_FINDINGS_CAP ),
	);
}

/**
 * One trust check's reading, the ONE place both trust surfaces (this classic
 * leaf and S&N Home's Integrity > Trust checks) get it from, so they cannot
 * disagree. Four states: not run (absent from the scan), skipped (the check
 * ran and could not measure), clear, findings.
 *
 * 2026-10-11: unknown is never zero. The rights checks report a skip as
 * count 0 plus a `skipped` reason, and both surfaces read that 0 as "clear"
 * and "holding": a gap in evidence painted as a pass. The skip test is
 * Health's own predicate, sn_health_check_is_skipped() (inc/health-summary.php),
 * so its rule (a skip counts only when there are no findings) is not restated.
 *
 * @param mixed $check The packed check, or null when the scan lacks it.
 * @return array{state:string,count:int,reason:string,text:string}
 */
function snt_trust_reading( $check ) {
	if ( ! is_array( $check ) ) {
		return array( 'state' => 'not_run', 'count' => 0, 'reason' => '', 'text' => __( 'not run', 'signal-and-noise-tools' ) );
	}
	if ( sn_health_check_is_skipped( $check ) ) {
		return array( 'state' => 'skipped', 'count' => 0, 'reason' => (string) $check['skipped'], 'text' => __( 'not measured', 'signal-and-noise-tools' ) );
	}
	$count = (int) ( $check['count'] ?? 0 );
	return array(
		'state'  => $count > 0 ? 'findings' : 'clear',
		'count'  => $count,
		'reason' => '',
		'text'   => $count > 0
			/* translators: %d: number of findings for one trust check. */
			? sprintf( _n( '%d finding', '%d findings', $count, 'signal-and-noise-tools' ), $count )
			: __( 'clear', 'signal-and-noise-tools' ),
	);
}

/**
 * Build the glance cards for the trust checks out of a scan result. PURE: takes
 * the scan, returns card definitions for sn_admin_glance_grid(); no reads, no
 * output, so the card logic is unit-testable without a WP bootstrap.
 *
 * A check absent from the scan yields an explicit "not run" card rather than
 * being skipped: a missing trust check must be visible as missing, never as an
 * empty space that reads like a pass. A skipped check reads "not measured" with
 * a neutral pill and its reason (the classic grid paints ok/warn/err pills only,
 * so there the value stands alone and the table row carries the reason).
 *
 * @since 10.47.0
 * @param array|null $scan sn_health_last_scan() result.
 * @return array<int,array<string,mixed>>
 */
function snt_trust_cards( $scan ) {
	$checks = is_array( $scan ) && isset( $scan['checks'] ) && is_array( $scan['checks'] ) ? $scan['checks'] : array();
	$pills  = array(
		'not_run'  => array( 'kind' => 'warn', 'text' => __( 'no reading', 'signal-and-noise-tools' ) ),
		'skipped'  => array( 'kind' => 'neutral', 'text' => __( 'skipped this scan', 'signal-and-noise-tools' ) ),
		'clear'    => array( 'kind' => 'ok', 'text' => __( 'holding', 'signal-and-noise-tools' ) ),
		'findings' => array( 'kind' => 'warn', 'text' => __( 'needs a look', 'signal-and-noise-tools' ) ),
	);
	$cards  = array();
	foreach ( snt_trust_check_keys() as $key => $meta ) {
		$reading = snt_trust_reading( $checks[ $key ] ?? null );
		$cards[] = array(
			'label'  => $meta['label'],
			'value'  => $reading['text'],
			'pill'   => $pills[ $reading['state'] ],
			'reason' => $reading['reason'],
		);
	}
	return $cards;
}

// The classic leaf's renderer (moved 2026-10-11, line cap).
require_once __DIR__ . '/integrity-trust-render.php';
