<?php
/**
 * Signal & Noise Tools: the one credit ladder. Every reclassification of a
 * machine read is decided here, first match wins, and carries its reason.
 *
 *   control token                 control_token (counted, credited to no one)
 *   first party                   credited (this site's own tools)
 *   unverifiable                  credited: before the since date, NO rule applies
 *   Cloudflare-verified           credited, basis verified
 *   vendor_verified '1'           credited, basis vendor_verified (IP in the
 *                                 vendor's published ranges)
 *   cf_verified agent, unverified impostor, cf_directory
 *   vendor_verified '0' AND off its home network
 *                                 impostor, vendor_ranges (an out-of-range IP
 *                                 alone is not enough: vendor lists are incomplete)
 *   off its home network          impostor, off_home_network
 *   a rotator network, vendor-claimed or an unnamed crawler
 *                                 impostor, rotator_network
 *   otherwise                     credited, basis claimed
 *
 * "Off its home network" needs a measured network and a measured
 * home_network '0' (rights-signals stamps it from the taxonomy's
 * home_networks); '' never reclassifies. Fields a sensor does not send read
 * as not measured, so an older worker reclassifies nothing new. Every
 * impostor's basis is `inferred`: a rule decided it. Loaded by
 * inc/machine-readers-basis.php. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The impostor reasons, in ladder order. */
const SN_MR_IMPOSTOR_REASONS = array( 'cf_directory', 'vendor_ranges', 'off_home_network', 'rotator_network' );

/**
 * The verdict on one row.
 *
 * @param array $row Aggregate row.
 * @param array $ctx snt_mr_credit_context() of the fold the row is in.
 * @return array{class:string,reason:string,basis:string,verification:string}
 *   class credited|control_token|impostor; reason '' unless impostor; basis
 *   verified|vendor_verified|claimed|inferred; verification the row's class.
 */
function snt_mr_credit_verdict( $row, array $ctx ) {
	$row   = is_array( $row ) ? $row : array();
	$class = snt_mr_row_verification( $row );
	$v     = static fn( $c, $r, $b ) => array( 'class' => $c, 'reason' => $r, 'basis' => $b, 'verification' => $class );
	if ( snt_mr_is_impersonation( $row ) ) {
		return $v( 'control_token', '', 'claimed' );
	}
	if ( ! empty( $row['first_party'] ) || 'unverifiable' === $class ) {
		return $v( 'credited', '', 'claimed' );
	}
	if ( 'verified' === $class ) {
		return $v( 'credited', '', snt_mr_row_vendor_verified( $row ) ? 'vendor_verified' : 'verified' );
	}
	$off_home = '' !== (string) ( $row['network'] ?? '' ) && '0' === (string) ( $row['home_network'] ?? '' );
	if ( ! empty( $row['cf_verified'] ) ) {
		return $v( 'impostor', 'cf_directory', 'inferred' );
	}
	if ( $off_home ) {
		return $v( 'impostor', '0' === (string) ( $row['vendor_verified'] ?? '' ) ? 'vendor_ranges' : 'off_home_network', 'inferred' );
	}
	if ( ( '' !== (string) ( $row['vendor'] ?? '' ) || snt_mr_is_unnamed_crawler( $row ) ) && snt_mr_on_rotator( $row, $ctx ) ) {
		return $v( 'impostor', 'rotator_network', 'inferred' );
	}
	return $v( 'credited', '', 'claimed' );
}

/**
 * Whether a row is a crawler name no taxonomy entry knows: agent '' filed
 * under other-bot. The aggregate row carries no user agent, but the frozen
 * family classifier files a read there only when its user agent carries a
 * crawler token (bot/, spider, crawler, scraper), and every dev-client token
 * it also matches (curl/, wget/, java/ ...) has a taxonomy entry. Such a row is
 * caught by a rotator network, and never counts toward making one
 * (snt_mr_credit_context counts vendor agents only).
 *
 * @param array $row Aggregate row.
 * @return bool
 */
function snt_mr_is_unnamed_crawler( array $row ) {
	return '' === (string) ( $row['agent'] ?? '' ) && 'other-bot' === (string) ( $row['family'] ?? '' );
}
