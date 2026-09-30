<?php
/**
 * Signal & Noise Tools: `wp sn rights-evidence dry-run <YYYY-MM> [--erratum]`.
 *
 * Prints the canonical payloads a month would carry today, per family, from
 * live sensor and ledger reads. With --erratum, the corrected in-force reservation
 * for each of the month's posted v1 records, for the erratum document. Posts nothing; the
 * functions it calls (inc/rights-evidence-dry-run.php) cannot.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'sn rights-evidence dry-run',
		static function ( $args, $assoc ) {
			$ym  = (string) ( $args[0] ?? '' );
			$out = empty( $assoc['erratum'] ) ? sn_rights_evidence_dry_run( $ym ) : sn_rights_evidence_erratum( $ym );
			$key = empty( $assoc['erratum'] ) ? 'payloads' : 'erratum';
			foreach ( $out[ $key ] as $family => $canonical ) {
				WP_CLI::line( '# ' . $family );
				WP_CLI::line( (string) wp_json_encode( json_decode( $canonical ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
			if ( ! $out['ok'] ) {
				WP_CLI::error( $out['error'] );
			}
			WP_CLI::success( count( $out[ $key ] ) . ' families; nothing was posted.' );
		},
		array(
			'shortdesc' => 'Print the rights-evidence payloads a month would carry today. Posts nothing.',
			'synopsis'  => array(
				array( 'type' => 'positional', 'name' => 'month', 'description' => 'YYYY-MM' ),
				array( 'type' => 'flag', 'name' => 'erratum', 'optional' => true, 'description' => 'Print the corrected in-force reservation for each of the month\'s posted v1 records (erratum data; never posted).' ),
			),
		)
	);
}
