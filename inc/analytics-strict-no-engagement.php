<?php
/**
 * Strict rule v2's second removal, "no engagement recorded" (owner decision
 * 2026-11-13): a scraper that sends no automation signal still renders one
 * page and leaves. Behavior only: no country, city or network test, so it
 * catches the same scraper wherever it rents servers and never removes a
 * reader for where they are.
 *
 * A presumed-human visitor-day leaves the strict figure for this reason when,
 * over the whole visitor-day: exactly one page view; no time, scroll or custom
 * event ever recorded; no referrer (direct, so an internal click is kept);
 * desktop; and the page is not /resume, /cv or an /r/ link code. One page view
 * in the whole visitor-day means one session, so this is the brief's "every
 * session meets the rule", narrowed: two such sessions in one visitor-day are
 * kept. Protected on purpose: mobile and any referrer (an in-app tap with no
 * recorded time, a phone reader who finishes a short page without touching),
 * and the job-search pages (a locked-down desktop can lose the leave signal).
 *
 * Read with the nightly per-day bot-signal read (inc/analytics-bot-signals-by-day.php);
 * the automation-signal removal wins where both apply, so a visitor-day is
 * removed once and the two counts are never folded together.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The reason, in one sentence, for every surface that carries the count. */
const SNT_STRICT_NO_ENGAGEMENT_REASON = 'Presumed-human visitor-days removed when they had exactly one page view, direct, on desktop, with no time, scroll or custom event recorded, on a page other than /resume, /cv or an /r/ link; never by place or network.';

/**
 * The by-day read's extra columns, per UTC day and visitor code. Only shapes
 * already live on Analytics Engine: max(if(cond, 1, 0)), integer branches. PURE.
 *
 * @return string[]
 */
function sn_strict_no_engagement_cols() {
	$ref = sn_analytics_referrer_value_sql( 'blob3' );
	return array(
		'max(if(bitAnd(toUInt32(double8), ' . SNT_BOT_SIGNAL_PRESENT . ') > 0, 1, 0)) AS present',
		"sum(if(blob1 = 'pv', 1, 0)) AS pv_rows",
		"max(if(blob1 = 'sc' OR blob1 = 'ce' OR blob1 = 'cp' OR (blob1 = 'tm' AND double2 > 0), 1, 0)) AS engaged",
		"max(if(blob1 = 'pv' AND {$ref} != '', 1, 0)) AS referred",
		"max(if(blob1 = 'pv' AND blob5 != 'desktop', 1, 0)) AS not_desktop",
		"max(if(blob1 = 'pv' AND (blob2 LIKE '/resume%' OR blob2 LIKE '/cv%' OR blob2 LIKE '/r/%'), 1, 0)) AS guarded",
	);
}

/**
 * The visitor codes the rule removes, judged over every row of the read, not
 * per UTC day: the code rotates on the site's day, so a page view at 19:55 ET
 * and its time event at 20:05 ET land in two UTC rows, and the first alone
 * would look unengaged. A read without the columns removes nothing. PURE.
 *
 * @param array $rows sn_bot_signals_by_day_sql( ..., true ) rows.
 * @return array<string,true> Lowercase visitor code => true.
 */
function sn_strict_no_engagement_vids( array $rows ) {
	$agg = array();
	foreach ( $rows as $r ) {
		$r = (array) $r;
		if ( ! array_key_exists( 'pv_rows', $r ) ) {
			return array();
		}
		$vid         = strtolower( (string) ( $r['vid'] ?? '' ) );
		$a           = $agg[ $vid ] ?? array( 'pv' => 0, 'flag' => 0 );
		$a['pv']    += (int) $r['pv_rows'];
		$a['flag']   = max( $a['flag'], (int) ( $r['engaged'] ?? 1 ), (int) ( $r['referred'] ?? 1 ), (int) ( $r['not_desktop'] ?? 1 ), (int) ( $r['guarded'] ?? 1 ) );
		$agg[ $vid ] = $a;
	}
	$out = array();
	foreach ( $agg as $vid => $a ) {
		if ( '' !== $vid && 1 === $a['pv'] && 0 === $a['flag'] ) {
			$out[ $vid ] = true;
		}
	}
	return $out;
}
