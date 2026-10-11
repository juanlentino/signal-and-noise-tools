<?php
/**
 * Signal & Noise Tools, Machine Readers: who reads, and for what. The parent
 * leaf's readings over a window's rows, for both twins. PURE.
 *
 * Readers are third-party reads credited to a vendor: this site's own tools
 * (the sensor's first_party flag) and robots.txt control tokens are not
 * readers, and each is said apart.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Third-party rows credited to a vendor.
 *
 * @param array $rows Aggregate rows.
 * @return array
 */
function snt_mr_reader_rows( array $rows ) {
	return array_values( array_filter( $rows, static fn( $r ) => is_array( $r ) && empty( $r['first_party'] ) && ! snt_mr_is_impersonation( $r ) ) );
}

/**
 * The AI-training finding in one sentence, built from the rows: what claimed
 * training, what a control token took out of it, and what Cloudflare verified.
 *
 * @param array $rows Aggregate rows.
 * @return string '' when the window carries no taxonomy.
 */
function snt_mr_ai_finding( array $rows ) {
	if ( snt_mr_taxonomy_absent( $rows ) ) {
		return '';
	}
	$train = array_filter( snt_mr_reader_rows( $rows ), static fn( $r ) => 'train' === (string) ( $r['purpose'] ?? '' ) );
	$t     = snt_mr_verification_zero();
	$nets  = array();
	foreach ( $train as $r ) {
		$t = snt_mr_verification_add( $t, $r );
		if ( 'verified' === snt_mr_row_verification( $r ) ) {
			$nets[ (string) ( $r['network'] ?? '' ) ] = true;
		}
	}
	$tokens = array_filter( snt_mr_impersonations( array_filter( $rows, static fn( $r ) => 'train' === (string) ( $r['purpose'] ?? '' ) ) ) );
	$taken  = array_sum( array_column( $tokens, 'hits' ) );
	$proof  = 0 === $t['verified']
		? __( 'Cloudflare verified none of them.', 'signal-and-noise-tools' )
		: ( 1 === count( $nets ) && '' !== (string) array_key_first( $nets )
			/* translators: 1: verified reads, 2: the one network they came from. */
			? sprintf( __( 'Cloudflare verified %1$s, all from %2$s.', 'signal-and-noise-tools' ), number_format_i18n( $t['verified'] ), (string) array_key_first( $nets ) )
			/* translators: 1: verified reads, 2: networks. */
			: sprintf( _n( 'Cloudflare verified %1$s, from %2$d network.', 'Cloudflare verified %1$s, from %2$d networks.', count( $nets ), 'signal-and-noise-tools' ), number_format_i18n( $t['verified'] ), count( $nets ) ) );
	if ( $taken > 0 ) {
		return sprintf(
			/* translators: 1: reads that claimed training, 2: control-token reads, 3: the tokens, 4: what is left, 5: the verification clause. */
			__( '%1$s reads claimed AI training; %2$s presented %3$s, a control token credited to no vendor, leaving %4$s; %5$s', 'signal-and-noise-tools' ),
			number_format_i18n( $t['claimed'] + $taken ),
			number_format_i18n( $taken ),
			implode( ', ', array_column( $tokens, 'token' ) ),
			number_format_i18n( $t['claimed'] ),
			$proof
		);
	}
	/* translators: 1: reads that claimed training, 2: the verification clause. */
	return sprintf( __( '%1$s reads claimed AI training; %2$s', 'signal-and-noise-tools' ), number_format_i18n( $t['claimed'] ), $proof );
}

/**
 * Who reads: per family, reads, the split and the last day seen; this site's
 * own tools as one closing figure, never a family row.
 *
 * @param array $rows Aggregate rows.
 * @return array{rows:array,first_party:int,lead:string}
 */
function snt_mr_who_reads( array $rows ) {
	$by   = array();
	$last = array();
	foreach ( snt_mr_reader_rows( $rows ) as $r ) {
		$f          = (string) ( $r['family'] ?? '' );
		$by[ $f ]   = snt_mr_verification_add( $by[ $f ] ?? snt_mr_verification_zero(), $r );
		$last[ $f ] = max( (string) ( $last[ $f ] ?? '' ), (string) ( $r['day'] ?? '' ) );
	}
	uasort( $by, static fn( $a, $b ) => $b['claimed'] <=> $a['claimed'] );
	$list = array();
	foreach ( $by as $f => $t ) {
		$list[] = array( 'family' => (string) $f, 'split' => $t, 'last_seen' => (string) $last[ $f ] );
	}
	$fp = 0;
	foreach ( $rows as $r ) {
		$fp += is_array( $r ) && ! empty( $r['first_party'] ) ? max( 0, (int) ( $r['hits'] ?? 0 ) ) : 0;
	}
	$top  = $list[0] ?? null;
	$lead = null === $top
		? __( 'No third-party machine reads in this window.', 'signal-and-noise-tools' )
		/* translators: 1: families, 2: the top family, 3: its reads, 4: of them verified. */
		: sprintf( _n( '%1$d family; %2$s leads with %3$s reads, %4$s verified.', '%1$d families; %2$s leads with %3$s reads, %4$s verified.', count( $list ), 'signal-and-noise-tools' ), count( $list ), $top['family'], number_format_i18n( $top['split']['claimed'] ), number_format_i18n( $top['split']['verified'] ) );
	return array( 'rows' => $list, 'first_party' => $fp, 'lead' => $lead );
}

/**
 * What for: per purpose, the split ("unknown" reads unmapped). Null rows when
 * the window carries no taxonomy (not measured).
 *
 * @param array $rows Aggregate rows.
 * @return array{rows:array|null,lead:string}
 */
function snt_mr_what_for( array $rows ) {
	$d = snt_mr_basis_display( $rows );
	if ( null === $d['purposes'] ) {
		return array( 'rows' => null, 'lead' => __( 'Purposes are not measured: the deployed sensor predates the taxonomy.', 'signal-and-noise-tools' ) );
	}
	$top = $d['purposes'][0] ?? null;
	return array(
		'rows' => $d['purposes'],
		'lead' => null === $top
			? __( 'No third-party reads carry a purpose in this window.', 'signal-and-noise-tools' )
			/* translators: 1: purposes, 2: the top purpose, 3: its reads. */
			: sprintf( _n( '%1$d purpose, this site’s own tools and control tokens left out; %2$s leads with %3$s reads.', '%1$d purposes, this site’s own tools and control tokens left out; %2$s leads with %3$s reads.', count( $d['purposes'] ), 'signal-and-noise-tools' ), count( $d['purposes'] ), $top['label'], number_format_i18n( $top['claimed'] ) ),
	);
}

/**
 * The parent hero's figures over a window: reads, readers (reads less this
 * site's own tools), AI-training claimed with its split, signatures, and the
 * figures beside them.
 *
 * @param array    $rows       Aggregate rows.
 * @param int      $days       Window.
 * @param int|null $feed_total Feed fetches, null when unread.
 * @param int|null $tool_calls Agent tool calls, null when unread.
 * @return array
 */
function snt_mr_hero_figures( array $rows, $days, $feed_total = null, $tool_calls = null ) {
	$total = 0;
	foreach ( $rows as $r ) {
		$total += is_array( $r ) ? max( 0, (int) ( $r['hits'] ?? 0 ) ) : 0;
	}
	$who = snt_mr_who_reads( $rows );
	$ev  = snt_mr_evidence( $rows );
	return array(
		'days'        => (int) $days,
		'total'       => $total,
		'first_party' => $who['first_party'],
		'readers'     => $total - $who['first_party'],
		'ai'          => $ev['verification']['ai_training_by_purpose'],
		'identity'    => function_exists( 'snt_mr_identity_totals' ) ? snt_mr_identity_totals( $rows ) : null,
		'feed_total'  => $feed_total,
		'tool_calls'  => $tool_calls,
	);
}

