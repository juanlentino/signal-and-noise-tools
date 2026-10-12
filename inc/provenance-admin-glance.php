<?php
/**
 * Signal & Noise Tools: the Provenance tiles, shared by the classic Tools >
 * Provenance page and the S&N Home leaf, and the one date format both use.
 * Split out of inc/provenance-admin.php (line cap) on 2026-10-12, when the
 * tiles learned to say what they count.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provenance time for admin display, in the site's timezone and its own
 * date and time formats (Settings > General), never the raw UTC ISO-8601 the
 * Worker and the ledger store. One format for the whole Provenance surface:
 * the Worker tile and the integrity line beside the Commits table both come
 * through here. A bare calendar date (the genesis "date", YYYY-MM-DD) is
 * printed date-only with NO timezone shift; moving its implicit midnight
 * across zones would roll it to the previous day. Empty in, empty out; an
 * unparseable value is returned verbatim rather than swallowed.
 *
 * @param string|int $when UTC ISO-8601 instant, a YYYY-MM-DD date, or a Unix time.
 * @return string
 */
function sn_prov_admin_format_ts( $when ) {
	$date = (string) get_option( 'date_format', 'F j, Y' );
	if ( is_int( $when ) ) {
		return (string) wp_date( $date . ' ' . (string) get_option( 'time_format', 'g:i a' ) . ' T', $when );
	}
	$iso = trim( (string) $when );
	if ( '' === $iso ) {
		return '';
	}
	try {
		$dt = new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		return $iso; // not a date we recognize: show it as-is, don't lie.
	}
	if ( false === strpos( $iso, 'T' ) && false === strpos( $iso, ':' ) ) {
		return $dt->format( $date );
	}
	return sn_prov_admin_format_ts( $dt->getTimestamp() );
}

/**
 * One glance card in the shape both surfaces read: `key` is what the leaf
 * matches on (never the translated label), `caption` says what the figure
 * counts. A status kind becomes the classic pill; a caption with no kind is
 * the classic meta line, so neither surface paints a swatch it cannot back.
 *
 * @param string $key     Stable card key.
 * @param string $label   Translated label.
 * @param string $value   The figure.
 * @param string $caption What it counts, or ''.
 * @param string $kind    ok|warn|err, or '' for none.
 * @return array<string,mixed>
 */
function sn_prov_admin_card( $key, $label, $value, $caption = '', $kind = '' ) {
	$card = array(
		'key'     => (string) $key,
		'label'   => (string) $label,
		'value'   => (string) $value,
		'caption' => (string) $caption,
	);
	if ( '' !== (string) $kind && '' !== (string) $caption ) {
		$card['pill'] = array( 'kind' => (string) $kind, 'text' => (string) $caption );
	} elseif ( '' !== (string) $caption ) {
		$card['meta_html'] = esc_html( (string) $caption );
	}
	return $card;
}

/**
 * First-glance hero cards for the Provenance panel, shared by both surfaces.
 * Pure: takes the sn_prov_admin_system_status() view-model.
 *
 * Each tile says what it counts, from the same population the Commits table
 * lists (every pending and every unanchored commit; the table's own "+N" line
 * counts what its newest-100 cut leaves out). Until 2026-10-12 the Pending
 * tile counted only `pending` while the table listed unanchored rows too, so
 * "Pending 0, clear" sat beside a table of proofs the Worker never took; the
 * unanchored count was computed and never painted. The Worker is still
 * INFERRED, not pinged (it has no GET route): the tile names the last time it
 * answered and turns amber while any proof is waiting to be sent, instead of
 * calling it "online" in exactly the state that shows it did not answer.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return array<int,array<string,mixed>>
 */
function sn_prov_admin_glance_cards( array $sys ) {
	$reachable  = ! empty( $sys['worker']['reachable'] );
	$last       = (string) ( $sys['worker']['last_contact'] ?? '' );
	$pending    = (int) ( $sys['counts']['pending'] ?? 0 );
	$unanchored = (int) ( $sys['counts']['unanchored'] ?? 0 );
	$confirmed  = (int) ( $sys['counts']['confirmed'] ?? 0 );
	/* translators: %s: number of proofs never sent to the Worker. */
	$not_sent   = sprintf( _n( '%s proof not sent', '%s proofs not sent', $unanchored, 'signal-and-noise-tools' ), number_format_i18n( $unanchored ) );

	if ( $reachable && '' !== $last ) {
		// A short figure and the date in the caption: a full datetime as the
		// figure wrapped to three lines beside four numeric tiles.
		/* translators: %s: when the Worker last answered, in the site's format. */
		$answered = sprintf( __( 'last answered %s', 'signal-and-noise-tools' ), sn_prov_admin_format_ts( $last ) );
		$worker   = sn_prov_admin_card( 'worker', __( 'Worker', 'signal-and-noise-tools' ), __( 'Answered', 'signal-and-noise-tools' ), $unanchored > 0 ? $answered . '; ' . $not_sent : $answered, $unanchored > 0 ? 'warn' : 'ok' );
	} else {
		$worker = sn_prov_admin_card( 'worker', __( 'Worker', 'signal-and-noise-tools' ), __( 'No answer yet', 'signal-and-noise-tools' ), $unanchored > 0 ? $not_sent : __( 'nothing has been sent', 'signal-and-noise-tools' ), $unanchored > 0 ? 'warn' : '' );
	}

	$genesis  = isset( $sys['genesis'] ) && is_array( $sys['genesis'] ) ? $sys['genesis'] : array();
	$g_status = (string) ( $genesis['status'] ?? '' );
	$g_pills  = array(
		'confirmed' => array( 'ok', __( 'anchored on Bitcoin', 'signal-and-noise-tools' ) ),
		'pending'   => array( 'warn', __( 'awaiting Bitcoin', 'signal-and-noise-tools' ) ),
		'unsent'    => array( 'err', __( 'not sent', 'signal-and-noise-tools' ) ),
	);
	$g_pill   = $g_pills[ $g_status ] ?? array( '', '' );

	return array(
		$worker,
		sn_prov_admin_card( 'genesis', __( 'Genesis', 'signal-and-noise-tools' ), '' !== $g_status ? sn_prov_admin_status_label( $g_status ) : __( 'Not anchored', 'signal-and-noise-tools' ), $g_pill[1], $g_pill[0] ),
		sn_prov_admin_card( 'pending', __( 'Pending', 'signal-and-noise-tools' ), number_format_i18n( $pending ), $pending > 0 ? __( 'sent, awaiting Bitcoin', 'signal-and-noise-tools' ) : __( 'none in flight', 'signal-and-noise-tools' ), $pending > 0 ? 'warn' : 'ok' ),
		sn_prov_admin_card( 'unanchored', __( 'Not sent', 'signal-and-noise-tools' ), number_format_i18n( $unanchored ), $unanchored > 0 ? __( 'the Worker never took these', 'signal-and-noise-tools' ) : __( 'every proof was sent', 'signal-and-noise-tools' ), $unanchored > 0 ? 'warn' : 'ok' ),
		sn_prov_admin_card( 'confirmed', __( 'Confirmed', 'signal-and-noise-tools' ), number_format_i18n( $confirmed ), $confirmed > 0 ? __( 'anchored on Bitcoin', 'signal-and-noise-tools' ) : '', $confirmed > 0 ? 'ok' : '' ),
	);
}

/**
 * Proofs waiting (pending or not sent) that the Commits table leaves out: it
 * reads the newest 100 subjects, the tiles read every one. Never a silent cut.
 *
 * @param array $sys    sn_prov_admin_system_status() view-model.
 * @param int   $listed Rows the table lists.
 * @return int
 */
function sn_prov_admin_unlisted( array $sys, $listed ) {
	$waiting = (int) ( $sys['counts']['pending'] ?? 0 ) + (int) ( $sys['counts']['unanchored'] ?? 0 );
	return max( 0, $waiting - (int) $listed );
}

/**
 * The sentence for that cut, or '' when nothing is left out.
 *
 * @param int $hidden From sn_prov_admin_unlisted().
 * @return string
 */
function sn_prov_admin_unlisted_line( $hidden ) {
	return (int) $hidden > 0
		/* translators: %s: proofs waiting that the table does not list. */
		? sprintf( __( '+%s older proofs waiting are not listed; the table reads the newest 100 subjects.', 'signal-and-noise-tools' ), number_format_i18n( (int) $hidden ) )
		: '';
}
