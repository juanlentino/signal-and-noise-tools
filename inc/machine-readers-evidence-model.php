<?php
/**
 * Signal & Noise Tools, Monitoring › Reader evidence: the page's readings,
 * for both twins. Its question: how is each count known? PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows the exceptions tables show before their "+N more" line. */
const SN_MR_EVIDENCE_ROWS = 10;

/**
 * Claim against proof: every read credited to a vendor (third parties), split.
 *
 * @param array $rows Aggregate rows.
 * @return array A verification split.
 */
function snt_mr_claim_proof( array $rows ) {
	$t = snt_mr_verification_zero();
	foreach ( snt_mr_reader_rows( $rows ) as $r ) {
		$t = snt_mr_verification_add( $t, $r );
	}
	return $t;
}

/**
 * The by-family lead: the exceptions, families with reads Cloudflare never
 * verified, largest first.
 *
 * @param array $who snt_mr_who_reads().
 * @return string
 */
function snt_mr_family_exceptions( array $who ) {
	// Unverified, not merely unverifiable: a read before the sensor recorded
	// the category was never checked, so it is no evidence of "never verified".
	$none = array_values( array_filter( $who['rows'], static fn( $f ) => 0 === $f['split']['verified'] && $f['split']['unverified'] > 0 ) );
	if ( array() === $none ) {
		return __( 'Every family with reads Cloudflare could check had some of them verified.', 'signal-and-noise-tools' );
	}
	$names = array_map( static fn( $f ) => $f['family'] . ' (' . number_format_i18n( $f['split']['unverified'] ) . ')', array_slice( $none, 0, 4 ) );
	return sprintf(
		/* translators: 1: families never verified, 2: the largest of them. */
		_n( '%1$d family was never verified by Cloudflare: %2$s.', '%1$d families were never verified by Cloudflare, largest first: %2$s.', count( $none ), 'signal-and-noise-tools' ),
		count( $none ),
		implode( ', ', $names )
	);
}

/**
 * The by-purpose lead: the training line.
 *
 * @param array|null $purposes snt_mr_basis_display()['purposes'].
 * @return string
 */
function snt_mr_train_line( $purposes ) {
	if ( null === $purposes ) {
		return __( 'Purposes are not measured: the deployed sensor predates the taxonomy.', 'signal-and-noise-tools' );
	}
	$t = array_column( $purposes, null, 'label' )['train'] ?? snt_mr_verification_zero();
	return sprintf(
		/* translators: 1: reads claiming training, 2: verified, 3: verified as a training crawler. */
		__( '%1$s reads claimed training; %2$s verified, %3$s as a training crawler.', 'signal-and-noise-tools' ),
		number_format_i18n( (int) $t['claimed'] ),
		number_format_i18n( (int) $t['verified'] ),
		number_format_i18n( (int) $t['verified_matched'] )
	);
}

/**
 * Is this crawler real: named third-party agents with reads Cloudflare did not
 * verify, largest unverified first, each with its whole split and top network.
 * Driven by each agent's split, so a read before the since date (unverifiable)
 * neither makes an agent an exception nor counts as verified.
 *
 * @param array $rows Aggregate rows.
 * @return array{lead:string,rows:array,more:int}
 */
function snt_mr_agent_exceptions( array $rows ) {
	$named    = array_values( array_filter( snt_mr_reader_rows( $rows ), static fn( $r ) => '' !== (string) ( $r['agent'] ?? '' ) ) );
	$nets     = array_column( snt_mr_agent_networks( $named ), 'networks', 'agent' );
	$out      = array();
	$verified = 0;
	$none     = 0;
	foreach ( (array) snt_mr_verification_by( $named, 'agent' ) as $agent => $t ) {
		if ( 0 === $t['unverified'] ) {
			$verified += 0 === $t['unverifiable'] && $t['verified'] > 0 ? 1 : 0;
			continue;
		}
		$none += 0 === $t['verified'] ? 1 : 0;
		$top   = $nets[ $agent ][0] ?? null;
		$out[] = array(
			'agent'    => (string) $agent,
			'hits'     => $t['claimed'],
			'verified' => $t['verified'],
			'network'  => null === $top ? __( 'not recorded', 'signal-and-noise-tools' ) : $top['network'] . ' (' . (int) round( 100 * $top['hits'] / max( 1, $t['claimed'] ) ) . '%)',
			'split'    => $t,
		);
	}
	usort( $out, static fn( $x, $y ) => $y['split']['unverified'] <=> $x['split']['unverified'] ?: strcmp( $x['agent'], $y['agent'] ) );
	$lead = sprintf(
		/* translators: 1: agents with unverified reads, 2: of them never verified, 3: agents verified on every read. */
		_n( '%1$d named agent read with a user agent Cloudflare did not verify; %2$d never verified. Verified on every read: %3$d.', '%1$d named agents read with a user agent Cloudflare did not verify, %2$d of them never verified. Verified on every read: %3$d.', count( $out ), 'signal-and-noise-tools' ),
		count( $out ),
		$none,
		$verified
	);
	return array( 'lead' => $lead, 'rows' => array_slice( $out, 0, SN_MR_EVIDENCE_ROWS ), 'more' => max( 0, count( $out ) - SN_MR_EVIDENCE_ROWS ) );
}

/**
 * Third-party reads per verifiable UTC day, verified and unverified: the two
 * series the anomalies histogram stacks.
 *
 * @param array  $rows Aggregate rows.
 * @param string $from First day, YYYY-MM-DD (clamped to the first verifiable day).
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array{from:string,to:string,columns:array<int,array<int,int>>}
 */
function snt_mr_verification_daily( array $rows, $from, $to ) {
	$from = function_exists( 'snt_ml_reader_verifiable_from' ) ? snt_ml_reader_verifiable_from( $from ) : $from;
	$days = array_fill_keys( snt_mr_day_range( $from, $to ), array( 0, 0 ) );
	foreach ( snt_mr_reader_rows( $rows ) as $r ) {
		$d     = (string) ( $r['day'] ?? '' );
		$class = snt_mr_row_verification( $r );
		if ( isset( $days[ $d ] ) && 'unverifiable' !== $class ) {
			$days[ $d ][ 'verified' === $class ? 0 : 1 ] += max( 0, (int) ( $r['hits'] ?? 0 ) );
		}
	}
	return array( 'from' => $from, 'to' => (string) $to, 'columns' => array_values( $days ) );
}
