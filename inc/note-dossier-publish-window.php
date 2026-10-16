<?php
/**
 * Signal & Noise Tools: the note dossier's publish window ("First hour").
 *
 * Where a note's numbers already show (the Signal & Noise app's dossier),
 * the burst after it went live, read on demand (inc/publish-window.php): the
 * human readers after the window lead, then the window itself, by lane, how
 * much of it was the owner or automation, and the top sources inside it.
 * While the window is open the readout says how many minutes are left; it
 * is as fresh as the dossier, read again on every open. A note published
 * before publish windows existed says so and reads nothing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The window's name: "First hour" at the default, else its minutes. PURE.
 *
 * @param int $minutes Window length.
 * @return string
 */
function sn_publish_window_label( $minutes ) {
	/* translators: %d: minutes. */
	return 60 === (int) $minutes ? __( 'First hour', 'signal-and-noise-tools' ) : sprintf( __( 'First %d minutes', 'signal-and-noise-tools' ), (int) $minutes );
}

/**
 * The lanes that are not presumed-human readers, as the burst's split. PURE.
 *
 * @param array<string,array{hits:int}> $lanes Lane => figures.
 * @return array{owner:int,automation:int,human:int}
 */
function sn_publish_window_burst( array $lanes ) {
	$hits = static fn( $lane ) => (int) ( $lanes[ $lane ]['hits'] ?? 0 );
	return array(
		'owner'      => $hits( 'owner' ) + $hits( 'owner_agent' ) + $hits( 'unverified_owner' ),
		'automation' => $hits( 'suspected_automation' ),
		'human'      => $hits( 'human' ),
	);
}

/**
 * @param int $post_id Note id.
 * @return array<int,array<string,mixed>> Empty for an unpublished note or a page.
 */
function sn_note_dossier_publish_window( $post_id ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type || ! sn_note_dossier_is_public( $post ) || ! function_exists( 'sn_publish_window_report' ) ) {
		return array();
	}
	$r       = sn_publish_window_report( $post->ID );
	$heading = sn_publish_window_label( (int) $r['minutes'] );
	$source  = __( 'Analytics Engine, read on open', 'signal-and-noise-tools' );
	if ( 'none' === $r['state'] ) {
		return array( sn_note_dossier_status( 'numbers', $heading, 'neutral', __( 'Published before publish windows existed.', 'signal-and-noise-tools' ), __( 'A window starts at a note\'s first publish from 2026-10-16 on; older notes are not backfilled.', 'signal-and-noise-tools' ) ) );
	}
	if ( 'unread' === $r['state'] ) {
		$why = array(
			'truncated'       => __( 'Analytics Engine returned a truncated answer, so the counts would be short; nothing is shown rather than an undercount.', 'signal-and-noise-tools' ),
			'overcap_unread'  => __( 'The over-cap visitor list could not be read, so the human count would include capped visitor-days; nothing is shown rather than an overcount.', 'signal-and-noise-tools' ),
			'path_unreadable' => __( 'The note\'s path is not a plain URL path, so it is never read.', 'signal-and-noise-tools' ),
		);
		return array( isset( $why[ $r['why'] ] ) ? sn_note_dossier_status( 'numbers', $heading, 'warning', __( 'Not read.', 'signal-and-noise-tools' ), $why[ $r['why'] ] ) : sn_note_dossier_unreadable( 'numbers', $heading, __( 'Analytics Engine', 'signal-and-noise-tools' ) ) );
	}
	$win   = (array) $r['window'];
	$after = (array) $r['after'];
	$burst = sn_publish_window_burst( $win );
	$open  = 'open' === $r['state'];
	/* translators: %d: minutes left. */
	$left  = $open ? sprintf( _n( 'window open, %d min left', 'window open, %d min left', (int) $r['minutes_left'], 'signal-and-noise-tools' ), (int) $r['minutes_left'] ) : '';
	$num   = static fn( $n ) => number_format_i18n( (int) $n );
	$tiles = array(
		array( 'label' => __( 'Human after the window', 'signal-and-noise-tools' ), 'value' => $num( $after['human']['hits'] ?? 0 ), 'window' => __( 'since the window closed', 'signal-and-noise-tools' ), 'note' => $open ? __( 'the window is still open', 'signal-and-noise-tools' ) : __( 'page views', 'signal-and-noise-tools' ) ),
		array( 'label' => __( 'Human in the window', 'signal-and-noise-tools' ), 'value' => $num( $burst['human'] ), 'window' => $heading, 'note' => $left ),
		/* translators: 1: owner page views, 2: automation page views. */
		array( 'label' => __( 'Owner or automation in the window', 'signal-and-noise-tools' ), 'value' => $num( $burst['owner'] + $burst['automation'] ), 'window' => $heading, 'note' => sprintf( __( 'owner %1$s · automation %2$s · own infrastructure is not counted per note', 'signal-and-noise-tools' ), $num( $burst['owner'] ), $num( $burst['automation'] ) ) ),
	);
	$blocks = array( sn_note_dossier_stats( 'numbers', $heading, $tiles, $source ) );
	$rows   = array();
	foreach ( array_unique( array_merge( array_keys( $win ), array_keys( $after ) ) ) as $lane ) {
		$rows[] = array( 'lane' => (string) $lane, 'window' => $num( $win[ $lane ]['hits'] ?? 0 ), 'after' => $num( $after[ $lane ]['hits'] ?? 0 ) );
	}
	if ( array() !== $rows ) {
		$blocks[] = sn_note_dossier_table( 'numbers', __( 'Page views by lane', 'signal-and-noise-tools' ), array( array( 'key' => 'lane', 'label' => __( 'Lane', 'signal-and-noise-tools' ) ), array( 'key' => 'window', 'label' => $heading ), array( 'key' => 'after', 'label' => __( 'After', 'signal-and-noise-tools' ) ) ), $rows, $source );
	}
	$src = array();
	foreach ( (array) $r['sources'] as $s ) {
		$src[] = array( 'source' => (string) $s['label'], 'readers' => $num( $s['readers'] ) );
	}
	if ( array() !== $src ) {
		/* translators: %s: the window's name, e.g. First hour. */
		$blocks[] = sn_note_dossier_table( 'numbers', sprintf( __( 'Arrived from, %s', 'signal-and-noise-tools' ), $heading ), array( array( 'key' => 'source', 'label' => __( 'Source', 'signal-and-noise-tools' ) ), array( 'key' => 'readers', 'label' => __( 'Human readers', 'signal-and-noise-tools' ) ) ), $src, $source );
	}
	return $blocks;
}
