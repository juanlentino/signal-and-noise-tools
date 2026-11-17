<?php
/**
 * The contact form's blocked attempts, counted per guard per site day.
 *
 * AllTerrain Forms runs `alltfo_spam_verdict` once per submission; this reads
 * the final verdict at a late priority (after its own guards and ours in
 * inc/forms-spam.php) and, when it says spam, adds one to that day's count for
 * the guard that caught it. Counts only: no message, name, email or address is
 * read or kept. The verdict passes through untouched. Kept 90 days.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_FS_TALLY_OPT  = 'snt_forms_blocked_tally';
const SNT_FS_TALLY_DAYS = 90;
// Guard key => the words a reader sees. Every stored key is one of these.
const SNT_FS_TALLY_GUARDS = array(
	'honeypot'   => 'Honeypot',
	'time_trap'  => 'Time trap',
	'rate_limit' => 'Rate limit',
	'rule'       => 'Rule',
	'challenge'  => 'Challenge',
	'akismet'    => 'Akismet',
	'other'      => 'Other',
);

/**
 * Which guard a verdict reason names. PURE. Never the raw reason as a key.
 *
 * @param mixed $reason Verdict reason.
 * @return string A SNT_FS_TALLY_GUARDS key.
 */
function snt_fs_tally_guard( $reason ) {
	$reason = strtolower( trim( (string) $reason ) );
	$map    = array( 'honeypot' => 'honeypot', 'too_fast' => 'time_trap', 'rate_limit' => 'rate_limit', 'blocklist' => 'rule', 'challenge' => 'challenge', 'akismet' => 'akismet' );
	if ( 0 === strpos( $reason, 'snt:' ) ) {
		return 'rule';
	}
	return $map[ $reason ] ?? 'other';
}

/**
 * One more blocked attempt on $day, days older than the window dropped. PURE.
 *
 * @param mixed  $tally  Stored tally (day => guard => count).
 * @param mixed  $reason Verdict reason.
 * @param string $day    Site day, Y-m-d.
 * @return array New tally.
 */
function snt_fs_tally_add( $tally, $reason, $day ) {
	$tally = is_array( $tally ) ? $tally : array();
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
		return $tally;
	}
	$guard                   = snt_fs_tally_guard( $reason );
	$tally[ $day ][ $guard ] = (int) ( $tally[ $day ][ $guard ] ?? 0 ) + 1;
	$oldest                  = gmdate( 'Y-m-d', strtotime( $day . ' 12:00:00 UTC' ) - ( SNT_FS_TALLY_DAYS - 1 ) * 86400 );
	$tally                   = array_filter( $tally, static fn( $d ) => (string) $d >= $oldest, ARRAY_FILTER_USE_KEY );
	krsort( $tally );
	return $tally;
}

/**
 * The tally as rows, newest day first, one count per guard. PURE.
 *
 * @param mixed $tally Stored tally.
 * @return array<int,array<string,int|string>>
 */
function snt_fs_tally_rows( $tally ) {
	$rows = array();
	foreach ( (array) $tally as $day => $by ) {
		$row = array( 'day' => (string) $day );
		foreach ( array_keys( SNT_FS_TALLY_GUARDS ) as $g ) {
			$row[ $g ] = (int) ( ( (array) $by )[ $g ] ?? 0 );
		}
		$rows[] = $row;
	}
	usort( $rows, static fn( $a, $b ) => strcmp( $b['day'], $a['day'] ) );
	return $rows;
}

/**
 * The tally for the forms-spam-scan ability: day rows, newest first.
 *
 * @return array<int,array<string,int|string>>
 */
function snt_fs_blocked_daily() {
	return snt_fs_tally_rows( get_option( SNT_FS_TALLY_OPT, array() ) );
}

add_filter(
	'alltfo_spam_verdict',
	static function ( $verdict ) {
		if ( is_array( $verdict ) && ! empty( $verdict['spam'] ) ) {
			$day = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			update_option( SNT_FS_TALLY_OPT, snt_fs_tally_add( get_option( SNT_FS_TALLY_OPT, array() ), $verdict['reason'] ?? '', $day ), false );
		}
		return $verdict;
	},
	1000,
	1
);

/**
 * Integrity › Reports, classic view: the daily table, last 30 days with any
 * count. Shown with or without a Health scan.
 */
function snt_fs_tally_render() {
	$rows = array_slice( snt_fs_tally_rows( get_option( SNT_FS_TALLY_OPT, array() ) ), 0, 30 );
	echo '<div class="sn-fieldset"><h3>' . esc_html__( 'Contact form: blocked attempts', 'signal-and-noise-tools' ) . '</h3><p class="sn-fieldset-intro">'
		. esc_html__( 'Submissions the form refused, counted per day by the guard that caught them (Rule is the blocklist and the content rules). Counts only: no message, name, email or address is kept. Days with none have no row.', 'signal-and-noise-tools' ) . '</p>';
	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No blocked attempt recorded yet.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Day', 'signal-and-noise-tools' ) . '</th>';
	foreach ( SNT_FS_TALLY_GUARDS as $label ) {
		echo '<th scope="col" class="num">' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><th scope="row">' . esc_html( $r['day'] ) . '</th>';
		foreach ( array_keys( SNT_FS_TALLY_GUARDS ) as $g ) {
			echo '<td class="num">' . esc_html( number_format_i18n( $r[ $g ] ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}
