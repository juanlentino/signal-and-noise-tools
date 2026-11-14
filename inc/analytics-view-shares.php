<?php
/**
 * The Shares panel on the Links view (owner only): each link-preview fetch of
 * the last 14 days (inc/share-echoes.php), newest first, with the human visits
 * that followed it. A share with no visit after it is listed too: the person
 * has not opened it yet.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Days of shares the panel lists. */
const SN_SHARES_PANEL_DAYS = 14;

/**
 * The panel's rows. PURE.
 *
 * @param array $list sn_share_echoes_list() rows.
 * @return array<int,array<int,string>>
 */
function snt_analytics_shares_rows( array $list ) {
	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	return array_map( static function ( $e ) use ( $tz ) {
		$after = array_map( static fn( $v ) => sn_share_echo_follow_text( $v['gap_s'], $v['read'] ), $e['visits'] );
		return array(
			( new DateTimeImmutable( '@' . (int) $e['at'] ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ),
			$e['path'],
			$e['app'],
			array() === $after ? __( 'no visit yet', 'signal-and-noise-tools' ) : implode( '; ', $after ),
		);
	}, $list );
}

/** Render the panel. */
function snt_analytics_render_shares() {
	$title = __( 'Shares, last 14 days', 'signal-and-noise-tools' );
	$hi    = gmdate( 'Y-m-d H:i:s', sn_analytics_clock() + 60 );
	$lo    = gmdate( 'Y-m-d H:i:s', sn_analytics_clock() - SN_SHARES_PANEL_DAYS * 86400 );
	$echo  = sn_share_echoes_read( SN_SHARES_PANEL_DAYS );
	$seen  = null === $echo ? null : sn_analytics_sessions_read( $lo, $hi, 'human' );
	if ( null === $echo || null === $seen ) {
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
		return;
	}
	snt_an_kv_table(
		$title,
		snt_analytics_shares_rows( sn_share_echoes_list( $echo, $seen['rows'] ) ),
		array(
			array( 'label' => __( 'When', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Page', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Shared via', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'What followed (gap, read)', 'signal-and-noise-tools' ) ),
		),
		array(
			'data_colname' => true,
			'clamp'        => 10,
			'empty'        => __( 'No link-preview fetch in the last 14 days. When a link to the site is pasted into WhatsApp, Messenger, LinkedIn, Slack and the like, the app fetches the page for its preview, and the share shows here.', 'signal-and-noise-tools' ),
			'footer'       => '<p class="sn-an-note">' . esc_html__( 'A preview fires when anyone shares the link, not only you, and a public post draws previews from many people: a visit here came after a share, not necessarily yours. Joined by page and time only (the visit\'s entry page, or the same /r/ code, within 72 hours); no visitor is identified. Repeat fetches by one app within a minute are one share.', 'signal-and-noise-tools' ) . '</p>',
		)
	);
}
