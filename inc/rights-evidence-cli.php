<?php
/**
 * Signal & Noise Tools: `wp sn rights-evidence dry-run <YYYY-MM> [--v2-draft]`.
 *
 * Prints the canonical payloads a month would carry today, per family, from
 * live sensor and ledger reads. With --v2-draft, the schema-2 candidates that
 * would supersede the month's posted v1 records instead. Posts nothing; the
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
			$out = empty( $assoc['v2-draft'] ) ? sn_rights_evidence_dry_run( $ym ) : sn_rights_evidence_v2_drafts( $ym );
			$key = empty( $assoc['v2-draft'] ) ? 'payloads' : 'drafts';
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
				array( 'type' => 'flag', 'name' => 'v2-draft', 'optional' => true, 'description' => 'Print the v2 candidates that supersede the month\'s v1 records.' ),
			),
		)
	);
}
