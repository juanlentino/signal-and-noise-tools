<?php
/**
 * wp_date() as the live site has it: Settings › General is America/New_York.
 *
 * 14.7.4: a suite that stubs wp_date() as gmdate() measures the UTC fallback
 * and never the path the owner reads. Require this BEFORE the leaf harness
 * (its own stub is function_exists-guarded) to run under the real zone. Pins
 * that print instants then expect EDT/EST, four or five hours behind UTC.
 */
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$tz = new DateTimeZone( $GLOBALS['__site_tz'] ?? 'America/New_York' );
		$d  = new DateTime( '@' . ( null === $timestamp ? time() : (int) $timestamp ) );
		$d->setTimezone( $tz );
		return $d->format( (string) $format );
	}
}
