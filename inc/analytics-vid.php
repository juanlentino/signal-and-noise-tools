<?php
/**
 * Signal & Noise Tools: the visitor-day hash every Analytics Engine read uses.
 *
 * index1 is the visitor-day hash, 16 hex characters. The analytics worker may
 * add a suffix for events other than page views (`<hash>:sc50`, `<hash>:tm`,
 * ...), so each event type gets its own index and Analytics Engine stops
 * sampling a burst as one. Every read therefore takes the visitor-day from the
 * first 16 characters. On a row without a suffix this is index1 itself, so no
 * figure moves. Alias it (`SN_AE_VID . ' AS vid'`) wherever a GROUP BY or
 * HAVING needs it: those take only column names or aliases.
 *
 * count(DISTINCT SN_AE_VID) was probed live on 2026-11-17 and accepted
 * (count(DISTINCT <expression>) is otherwise rejected; see
 * tests/analytics-sql-dialect.php). tests/analytics-vid-prefix.php fails if
 * any SQL string in inc/ names index1 outside this file.
 *
 * Its own file, required by each reader, because no existing include is
 * loaded by every reader and several tests stub analytics-generation.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AE_VID = 'substring(index1, 1, 16)';
