<?php
/**
 * The ONE rule for the referrer kind (analytics worker 1.23.0, `double10`).
 *
 * The worker folds a self-referral to blob3 = '', so on its own '' means both
 * "no referrer" and "an internal click". Since 1.23.0 every event also stores
 * the kind in double10: 0 no referrer, 1 self (an internal click), 2 external.
 * Rows written before the release read 0 for everything (Analytics Engine
 * reads a missing double as 0), so they cannot be told apart and keep the old
 * behaviour: `double10 = 1` never matches one. The cutover in the predicate is
 * the declared start of the era, so a reader of the SQL sees where it begins.
 *
 * Every builder that derives "entry" or "(direct)" from blob3 takes its clause
 * from here (the entry-pages rollup, the referrer dim), so two rollups of one
 * stream cannot drift. tests/analytics-human-rule.php pins who carries it.
 *
 * AE shapes only: a comparison and toDateTime() in WHERE (the session read),
 * if() in SELECT under an alias (the read-time class). No function reaches
 * GROUP BY: the referrer dim groups by the `value` alias.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worker 1.23.0's own /_sn/version reports deployed_at 2026-10-03T15:09:39Z.
 * Rounded DOWN to the minute: an early cutover is harmless (a row written
 * before the deploy reads double10 = 0 and never matches), a late one would
 * misfile that minute. A UTC literal for toDateTime().
 */
const SN_ANALYTICS_REFKIND_CUTOVER = '2026-10-03 15:09:00';

/** What the referrer dim stores for an internal click. Never '' (that reads "(direct)"). */
const SN_ANALYTICS_INTERNAL_REFERRER = '(internal)';

/**
 * The predicate: this row is an internal click. PURE.
 *
 * @return string
 */
function sn_analytics_internal_click_sql() {
	return "(double10 = 1 AND timestamp >= toDateTime('" . SN_ANALYTICS_REFKIND_CUTOVER . "'))";
}

/**
 * WHERE form: drop the internal clicks (an internal click is not an entry). PURE.
 *
 * @return string
 */
function sn_analytics_not_internal_click_sql() {
	return ' AND NOT ' . sn_analytics_internal_click_sql();
}

/**
 * SELECT form: the referrer column, with an internal click relabelled. The row
 * is kept, so the referrer dim still totals what country and device total. PURE.
 *
 * @param string $col The referrer column (a constant from SN_ANALYTICS_DIM_COLUMNS).
 * @return string
 */
function sn_analytics_referrer_value_sql( $col ) {
	return 'if(' . sn_analytics_internal_click_sql() . ", '" . SN_ANALYTICS_INTERNAL_REFERRER . "', {$col})";
}
