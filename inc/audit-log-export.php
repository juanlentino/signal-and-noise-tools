<?php
/**
 * Signal & Noise Tools — login-audit log CSV/JSON export (v4.10.0).
 *
 * Two delivery surfaces over one set of pure builders:
 *   - admin_post_sn_audit_export — a nonce-protected GET download handler
 *     (separate from the PRG save handler, which a download would clobber).
 *   - signal-noise/export-audit-log ability (registered in
 *     inc/abilities-audit.php) — returns { format, content } so AI callers
 *     can pull the same payload programmatically.
 *
 * The export payload contains plaintext usernames from the login_success
 * rows; gated on manage_options on every surface.
 *
 * Source data comes straight from the audit module's pure impls
 * (snt_audit_get_counters_impl / snt_audit_get_login_successes_impl), each
 * already clamped to the configured retention window.
 *
 * @package SignalNoiseTools
 * @since 4.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assemble the export view: retention window + counters + login successes.
 *
 * @since 4.10.0
 * @return array { days:int, counters:array, login_successes:array }
 */
function sn_audit_export_build_view() {
	$days = (int) sn_setting( 'audit.retention_days', 90 );
	return array(
		'days'            => $days,
		'counters'        => function_exists( 'snt_audit_get_counters_impl' ) ? snt_audit_get_counters_impl( $days ) : array(),
		'login_successes' => function_exists( 'snt_audit_get_login_successes_impl' ) ? snt_audit_get_login_successes_impl( $days ) : array(),
	);
}

/**
 * Build the JSON export string from a view array.
 *
 * @since 4.10.0
 * @param array $view { days, counters, login_successes }.
 * @return string JSON.
 */
function sn_audit_export_build_json( array $view ) {
	$days = (int) ( $view['days'] ?? 0 );
	return wp_json_encode(
		array(
			'generated_at'   => time(),
			'site'           => home_url( '/' ),
			'retention_days' => $days,
			'counters'       => array_values( (array) ( $view['counters'] ?? array() ) ),
			'login_successes' => array_values( (array) ( $view['login_successes'] ?? array() ) ),
		)
	);
}

/**
 * fputcsv with an explicit empty escape character.
 *
 * PHP 8.4 deprecates calling fputcsv() without the 5th `$escape` argument
 * (the default changes in PHP 9.0). Passing '' also disables the legacy
 * backslash-escape mechanism, leaving pure RFC-4180 double-quote quoting —
 * which is exactly the round-trippable behaviour we want for usernames that
 * contain commas or quotes.
 *
 * @since 4.10.0
 * @param resource $stream Open writable stream.
 * @param array    $fields Row fields.
 * @return void
 */
function sn_audit_export_fputcsv( $stream, array $fields ) {
	fputcsv( $stream, array_map( 'sn_audit_export_csv_cell', $fields ), ',', '"', '' );
}

/**
 * Neutralize spreadsheet formula injection in a CSV cell.
 *
 * A cell whose first character is one Excel/Google Sheets treats as a formula
 * trigger ( = + - @, or a leading TAB / CR ) is prefixed with a single quote so
 * the spreadsheet renders it as literal text instead of evaluating it (e.g. a
 * username `=HYPERLINK(...)` or `=cmd|...`). Usernames are the user-controllable
 * field in this export and the export ability is REST-reachable, so this is
 * defense-in-depth even though numeric counters/dates never trigger it.
 *
 * @since 4.10.0
 * @param mixed $value Cell value.
 * @return string Safe cell value.
 */
function sn_audit_export_csv_cell( $value ) {
	$value = (string) $value;
	if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Build the CSV export string from a view array.
 *
 * Two sections, each prefixed with a `# <name>` comment line and a header
 * row, separated by a blank line. fputcsv handles RFC-4180 quoting (e.g. a
 * username containing a comma gets wrapped in double quotes).
 *
 * @since 4.10.0
 * @param array $view { days, counters, login_successes }.
 * @return string CSV.
 */
function sn_audit_export_build_csv( array $view ) {
	$counters = array_values( (array) ( $view['counters'] ?? array() ) );
	$logins   = array_values( (array) ( $view['login_successes'] ?? array() ) );

	$fh = fopen( 'php://temp', 'r+' );

	// Section 1: counters.
	fwrite( $fh, "# counters\n" );
	$counter_cols = array(
		'date',
		'login_failed',
		'wp_login_404',
		'wp_admin_unauth_404',
		'lockout_triggered',
		'password_reset',
		'unique_ips_count',
	);
	sn_audit_export_fputcsv( $fh, $counter_cols );
	foreach ( $counters as $row ) {
		$line = array();
		foreach ( $counter_cols as $col ) {
			$line[] = $row[ $col ] ?? '';
		}
		sn_audit_export_fputcsv( $fh, $line );
	}

	fwrite( $fh, "\n" );

	// Section 2: login successes.
	fwrite( $fh, "# login_successes\n" );
	$login_cols = array( 'ts', 'formatted', 'user' );
	sn_audit_export_fputcsv( $fh, $login_cols );
	foreach ( $logins as $row ) {
		$line = array();
		foreach ( $login_cols as $col ) {
			$line[] = $row[ $col ] ?? '';
		}
		sn_audit_export_fputcsv( $fh, $line );
	}

	rewind( $fh );
	$csv = stream_get_contents( $fh );
	fclose( $fh );

	return $csv;
}

/**
 * Normalize a requested format to a supported value (csv|json), default json.
 *
 * @since 4.10.0
 * @param mixed $format Raw format.
 * @return string 'csv' or 'json'.
 */
function sn_audit_export_normalize_format( $format ) {
	$format = is_string( $format ) ? strtolower( $format ) : '';
	return 'csv' === $format ? 'csv' : 'json';
}

/**
 * Render the export payload for a given format.
 *
 * @since 4.10.0
 * @param string $format 'csv' or 'json' (already normalized).
 * @return string
 */
function sn_audit_export_render( $format ) {
	$view = sn_audit_export_build_view();
	return 'csv' === $format
		? sn_audit_export_build_csv( $view )
		: sn_audit_export_build_json( $view );
}

/**
 * Ability execute callback: signal-noise/export-audit-log.
 *
 * Returns the export payload as a string alongside the resolved format.
 * Registered in inc/abilities-audit.php.
 *
 * @since 4.10.0
 * @param array|null $input { format?: 'csv'|'json' }.
 * @return array { format, content }
 */
function snt_ability_export_audit_log( $input = null ) {
	$format = is_array( $input ) && isset( $input['format'] ) ? $input['format'] : 'json';
	$format = sn_audit_export_normalize_format( $format );

	return array(
		'format'  => $format,
		'content' => sn_audit_export_render( $format ),
	);
}

/**
 * admin_post handler: stream the export as a file download.
 *
 * Separate from the tab's PRG save handler so a download GET never clobbers
 * a save POST. Nonce + manage_options gated.
 *
 * @since 4.10.0
 */
function sn_audit_export_download_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_audit_export', 'sn_audit_export_nonce' );

	$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';
	$format = sn_audit_export_normalize_format( $format );

	$content   = sn_audit_export_render( $format );
	$timestamp = wp_date( 'Y-m-d' );
	$filename  = 'sn-audit-log-' . $timestamp . '.' . $format;

	nocache_headers();
	header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8' ) );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw export download (CSV/JSON), not HTML.
	exit;
}
add_action( 'admin_post_sn_audit_export', 'sn_audit_export_download_handler' );
