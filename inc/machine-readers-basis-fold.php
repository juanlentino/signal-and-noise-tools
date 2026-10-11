<?php
/**
 * Signal & Noise Tools, Machine Readers: the evidence fields over a window's
 * rows, as the summary carries them and as both Machine Readers leaves paint
 * them. PURE; the classifiers live in inc/machine-readers-basis.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Impersonations, the verification split of every attributed count, and the
 * basis of each, over a window's aggregate rows.
 *
 * Each split reads the rows its figure reads: `families` the credited rows
 * (control-token hits out, first-party in, as in `families`), `purposes` and
 * `ai_training_by_purpose` the credited third-party rows (as in `purposes`),
 * `ai_training` the credited rows of the AI-training family set.
 *
 * @param array $rows Aggregate rows.
 * @return array{impersonations:array|null,verification:array,basis:array}
 */
function snt_mr_evidence( array $rows ) {
	$has_tax  = function_exists( 'snt_mr_taxonomy_absent' ) && ! snt_mr_taxonomy_absent( $rows );
	$ai_set   = function_exists( 'snt_mr_ai_training_families' ) ? snt_mr_ai_training_families() : array();
	$credited = array_values( array_filter( $rows, static fn( $r ) => is_array( $r ) && ! snt_mr_is_impersonation( $r ) ) );
	$ai       = snt_mr_verification_zero();
	foreach ( $credited as $row ) {
		if ( in_array( (string) ( $row['family'] ?? '' ), $ai_set, true ) ) {
			$ai = snt_mr_verification_add( $ai, $row );
		}
	}
	$purposes = $has_tax ? snt_mr_verification_by( array_values( array_filter( $credited, static fn( $r ) => empty( $r['first_party'] ) ) ), 'purpose' ) : null;
	return array(
		// null, not [], when the taxonomy is absent: rows then carry no agent
		// id, so no impersonation could have been seen.
		'impersonations' => $has_tax ? snt_mr_impersonations( $rows ) : null,
		'verification'   => array(
			'source'                 => 'cloudflare verified bot category',
			'since'                  => defined( 'SN_RIGHTS_EVIDENCE_VERIFIED_SINCE' ) ? SN_RIGHTS_EVIDENCE_VERIFIED_SINCE : null,
			'basis'                  => array( 'claimed' => 'claimed', 'verified' => 'verified', 'verified_matched' => 'verified', 'unverified' => 'claimed', 'unverifiable' => 'unmeasured' ),
			'families'               => snt_mr_verification_by( $credited, 'family' ),
			'purposes'               => $purposes,
			'ai_training'            => $ai,
			'ai_training_by_purpose' => null === $purposes ? null : ( $purposes->train ?? snt_mr_verification_zero() ),
		),
		'basis'          => array(
			'families'               => 'claimed',
			'ai_training'            => 'claimed',
			'ai_rights'              => 'claimed',
			'ai_surfaces'            => 'claimed',
			'purposes'               => 'claimed',
			'ai_training_by_purpose' => 'claimed',
			'first_party'            => 'claimed',
			'identity'               => 'signed',
			'verification'           => 'verified',
			'impersonations'         => 'claimed',
		),
	);
}

/**
 * The rows both Machine Readers leaves paint in "How each count is known":
 * per purpose and per family, the two AI-training figures, the impersonations.
 * Rows run largest claimed first. `purposes` is null without the taxonomy, and
 * so is the purpose AI-training row's every figure: not measured, never zeros.
 *
 * @param array $rows Aggregate rows.
 * @return array{since:string|null,purposes:array|null,families:array,ai:array,impersonations:array}
 */
function snt_mr_basis_display( array $rows ) {
	$ev    = snt_mr_evidence( $rows );
	$v     = $ev['verification'];
	$fp    = array();
	foreach ( $rows as $r ) {
		if ( is_array( $r ) && ! empty( $r['first_party'] ) && ! snt_mr_is_impersonation( $r ) ) {
			$fp[ (string) ( $r['family'] ?? '' ) ] = ( $fp[ (string) ( $r['family'] ?? '' ) ] ?? 0 ) + max( 0, (int) ( $r['hits'] ?? 0 ) );
		}
	}
	$list = static function ( $by, $extra = null ) {
		$out = array();
		foreach ( (array) $by as $key => $t ) {
			$row = array( 'label' => snt_mr_purpose_label( (string) $key ) ) + $t;
			if ( null !== $extra ) {
				$row = array( 'label' => (string) $key, 'first_party' => (int) ( $extra[ (string) $key ] ?? 0 ) ) + $t;
			}
			$out[] = $row;
		}
		usort( $out, static fn( $a, $b ) => $b['claimed'] <=> $a['claimed'] ?: strcmp( $a['label'], $b['label'] ) );
		return $out;
	};
	$none = array_fill_keys( array_keys( snt_mr_verification_zero() ), null );
	return array(
		'since'          => $v['since'],
		'purposes'       => null === $v['purposes'] ? null : $list( $v['purposes'] ),
		'families'       => $list( $v['families'], $fp ),
		'ai'             => array(
			array( 'label' => 'AI-training, by purpose' ) + ( $v['ai_training_by_purpose'] ?? $none ),
			array( 'label' => 'AI-training, by family (legacy)' ) + $v['ai_training'],
		),
		'impersonations' => (array) ( $ev['impersonations'] ?? array() ),
	);
}

/**
 * A count for display: grouped, or "not measured" for null (never 0).
 *
 * @param int|null $n Count.
 * @return string
 */
function snt_mr_basis_count( $n ) {
	return null === $n ? __( 'not measured', 'signal-and-noise-tools' ) : number_format_i18n( (int) $n );
}

/**
 * Top networks as one line ("Google LLC 200, xAI 110"); "not measured" when
 * the read could not say.
 *
 * @param array $u A snt_mr_unnamed_fold() or snt_mr_impersonations() entry.
 * @return string
 */
function snt_mr_basis_networks( array $u ) {
	if ( 'unmeasured' === ( $u['basis'] ?? '' ) || ! array_key_exists( 'networks', $u ) ) {
		return __( 'not measured', 'signal-and-noise-tools' );
	}
	$parts = array();
	foreach ( (array) $u['networks'] as $n ) {
		$parts[] = (string) ( $n['network'] ?? '' ) . ' ' . number_format_i18n( (int) ( $n['hits'] ?? 0 ) );
	}
	return array() === $parts ? __( 'not recorded', 'signal-and-noise-tools' ) : implode( ', ', $parts );
}

