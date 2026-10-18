<?php
/**
 * Signal & Noise Tools: Reader evidence's tile row, the movers, and the
 * control tokens, in S&N Analytics' own vocabulary (2026-10-11 redesign); the
 * reads that failed a check are inc/evidence-paint-impostors.php.
 * Tiles are snt_an_kpi_row() cards through snt_ev_tiles(), so the native
 * window paints os-stat tiles and the classic page its KPI row.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claimed against proof, as six tiles and the one line that tells the two
 * proofs apart. 2026-10-18: reads that failed a check are a tile of their own
 * (their reasons are rows in snt_ev_paint_impostors()), and verified says its
 * sources once vendor ranges are measured. The words are the shared
 * vocabulary (inc/machine-readers-impostor-copy.php).
 *
 * @param array       $t               snt_mr_claim_proof().
 * @param array|null  $sig             snt_mr_identity_breakdown() (null: never measured).
 * @param int|null    $tokens          Reads that presented a control token; null when not measured.
 * @param string|null $since           SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
 * @param string      $surface         kit|classic.
 * @param int|null    $impostors       Reads that failed a check (any reason); null when not measured.
 * @param bool        $vendor_measured Whether the window carried the vendor-ranges field.
 * @return string
 */
function snt_ev_paint_evidence_tiles( array $t, $sig, $tokens, $since, $surface = 'classic', $impostors = null, $vendor_measured = false ) {
	$n    = static fn( $v ) => number_format_i18n( (int) $v );
	$l    = snt_mr_reader_labels();
	$sign = null === $sig || (int) $sig['measured'] < 1
		? array( __( 'not measured', 'signal-and-noise-tools' ), __( 'no read carried a signature state', 'signal-and-noise-tools' ) )
		: array(
			$n( $sig['valid'] ),
			/* translators: 1: reads with a signature state, 2: invalid, 3: unknown key. */
			sprintf( __( 'of %1$s measured; %2$s invalid, %3$s unknown key', 'signal-and-noise-tools' ), $n( $sig['measured'] ), $n( $sig['invalid'] ), $n( $sig['unknown_key'] ) ),
		);
	$cards = array(
		snt_ev_stat( $surface, $n( $t['claimed'] ), __( 'Claimed', 'signal-and-noise-tools' ), __( 'reads credited to a vendor by their user agent', 'signal-and-noise-tools' ) ),
		/* translators: 1: verified by source, 2: verified reads that fit their claimed purpose. */
		snt_ev_stat( $surface, $n( $t['verified'] ), $l['verified']['label'], sprintf( __( '%1$s; %2$s for the purpose they claimed', 'signal-and-noise-tools' ), snt_mr_verified_by( (int) $t['verified'], (int) ( $t['vendor_verified'] ?? 0 ), (bool) $vendor_measured ), $n( $t['verified_matched'] ) ) ),
		// The tile's label is short (a KPI label truncates); the caption says the rest.
		snt_ev_stat( $surface, $n( $t['unverified'] ), __( 'Not verified', 'signal-and-noise-tools' ), __( 'named themselves; no check vouched for them', 'signal-and-noise-tools' ) ),
		snt_ev_stat( $surface, $sign[0], __( 'Signed (Web Bot Auth)', 'signal-and-noise-tools' ), $sign[1] ),
		// null: no row carried a taxonomy version, so no token could be seen.
		snt_ev_stat( $surface, null === $tokens ? __( 'not measured', 'signal-and-noise-tools' ) : $n( $tokens ), __( 'Control tokens', 'signal-and-noise-tools' ), __( 'credited to no vendor', 'signal-and-noise-tools' ) ),
	);
	if ( null !== $impostors ) {
		$cards[] = snt_ev_stat( $surface, $n( $impostors ), ucfirst( $l['failed_check']['label'] ), __( 'counted, credited to no vendor', 'signal-and-noise-tools' ) );
	}
	$when = null === $since ? __( 'an unknown time', 'signal-and-noise-tools' ) : gmdate( 'Y-m-d H:i', (int) strtotime( (string) $since ) ) . ' UTC';
	/* translators: 1: when the category began to be recorded, 2: reads before it. */
	$line = sprintf( __( 'Verified means Cloudflare’s verified-bot category, recorded since %1$s; %2$s reads before that are unverifiable. Signed is not verified: a signature proves who sent a request, the category says Cloudflare recognized the client.', 'signal-and-noise-tools' ), $when, $n( $t['unverifiable'] ) );
	$line .= $vendor_measured ? ' ' . __( 'A read from inside the IP ranges its vendor publishes is verified too, by the vendor’s published ranges.', 'signal-and-noise-tools' ) : '';
	$top = array_map( static fn( $a ) => (string) $a['agent'] . ' ' . $n( $a['hits'] ), array_slice( null === $sig ? array() : (array) ( $sig['by_agent'] ?? array() ), 0, 3 ) );
	/* translators: %s: up to three signers with their reads. */
	$line .= array() === $top ? '' : ' ' . sprintf( __( 'Top signers: %s.', 'signal-and-noise-tools' ), implode( ', ', $top ) );
	return snt_ev_tiles( $surface, $cards ) . snt_ev_lead( $surface, $line );
}

/**
 * Families that moved, as one table: the change and the two windows.
 *
 * @param array  $cards   snt_mr_family_delta_cards().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_movers( array $cards, $surface = 'classic' ) {
	if ( array() === $cards ) {
		return '';
	}
	$rows = array();
	foreach ( $cards as $c ) {
		$rows[] = array(
			'family' => substr( (string) ( $c['id'] ?? '' ), strlen( 'mr_delta_' ) ),
			/* translators: %d: percent change. */
			'change' => sprintf( 'up' === ( $c['direction'] ?? '' ) ? __( 'up %d%%', 'signal-and-noise-tools' ) : __( 'down %d%%', 'signal-and-noise-tools' ), (int) ( $c['percent'] ?? 0 ) ),
			'now'    => number_format_i18n( (int) ( $c['current'] ?? 0 ) ),
			'before' => number_format_i18n( (int) ( $c['prior'] ?? 0 ) ),
		);
	}
	$cols = array(
		'family' => __( 'Family', 'signal-and-noise-tools' ),
		'change' => __( 'Change', 'signal-and-noise-tools' ),
		'now'    => __( 'This half', 'signal-and-noise-tools' ),
		'before' => __( 'Half before', 'signal-and-noise-tools' ),
	);
	/* translators: %d: families that moved. */
	$lead = sprintf( _n( '%d family moved between the two halves of the window. Counts are what the edge observed; user agents are self-reported.', '%d families moved between the two halves of the window. Counts are what the edge observed; user agents are self-reported.', count( $rows ), 'signal-and-noise-tools' ), count( $rows ) );
	return snt_ev_box( $surface, __( 'Movers', 'signal-and-noise-tools' ), snt_ev_lead( $surface, $lead ) . snt_ev_table( $surface, $cols, $rows, 0, __( 'Movers', 'signal-and-noise-tools' ) ) );
}

/**
 * Control tokens credited to no one: the finding, then one row per token.
 *
 * @param array  $imp     snt_mr_basis_display()['impersonations'].
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_tokens( array $imp, $surface = 'classic' ) {
	$title = __( 'Control tokens credited to no one', 'signal-and-noise-tools' );
	if ( array() === $imp ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'No robots.txt control token was presented as a user agent in this window.', 'signal-and-noise-tools' ) ) );
	}
	$rows = array();
	foreach ( $imp as $i ) {
		$nets   = array_map( static fn( $x ) => $x['network'] . ' (' . (int) round( 100 * (int) $x['hits'] / max( 1, (int) $i['hits'] ) ) . '%)', array_slice( (array) $i['networks'], 0, 3 ) );
		$rows[] = array( 'token' => (string) $i['token'], 'reads' => number_format_i18n( (int) $i['hits'] ), 'networks' => array() === $nets ? __( 'not recorded', 'signal-and-noise-tools' ) : implode( ', ', $nets ) );
	}
	$lead = sprintf(
		/* translators: 1: reads, 2: the tokens. */
		__( '%1$s reads presented %2$s, a robots.txt token with no crawler of its own: credited to no vendor, family or purpose, and still counted in the total.', 'signal-and-noise-tools' ),
		number_format_i18n( array_sum( array_column( $imp, 'hits' ) ) ),
		implode( ', ', array_column( $imp, 'token' ) )
	);
	$cols = array( 'token' => __( 'Token', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ), 'networks' => __( 'From', 'signal-and-noise-tools' ) );
	return snt_ev_box( $surface, $title, snt_ev_verdict( $surface, esc_html( $lead ) ) . snt_ev_table( $surface, $cols, $rows, 0, $title ) );
}

require_once __DIR__ . '/evidence-paint-impostors.php'; // the reads that failed a check, as rows.
