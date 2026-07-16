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

// v9.51.0 (lane SEC-C, R8): PII minimization on the ABILITY surface only — the
// admin_post download handler below (a human, wp-admin-cookie-authenticated
// click) is untouched and still returns full plaintext via
// sn_audit_export_render()/sn_audit_export_build_view(). A per-call page-size
// cap, independent of the storage/retention window, plus default-redacted
// usernames unless the ability call explicitly passes include_pii:true.
const SNT_AUDIT_EXPORT_LOGINS_PAGE_CAP = 500; // Same magnitude as inc/audit-log.php's SN_AUDIT_LOGIN_SUCCESS_CAP; an independent constant per this file's own "duplicate the tiny constant" convention (see inc/mcp/mcp-rw-audit.php's docblock for the same rationale elsewhere in this arc).

/**
 * Mask a plaintext username for the PII-capped ability surface: keep the
 * first character, star the rest (at least one star even for a 1-char
 * username). Deterministic and reversible-proof (never logs/stores the
 * unmasked value anywhere this function touches).
 *
 * @param string $username
 * @return string
 */
function sn_audit_export_pii_mask_username( $username ) {
	$username = (string) $username;
	$len      = strlen( $username );
	if ( 0 === $len ) {
		return '';
	}
	// A 1-char username fully masks (revealing it would defeat the point of
	// masking at all for the shortest possible name) — first-char-preserved
	// masking only applies from length 2 up.
	if ( 1 === $len ) {
		return '*';
	}
	return substr( $username, 0, 1 ) . str_repeat( '*', $len - 1 );
}

/**
 * Redact the 'user' field of every row in a login-successes array, UNLESS
 * $include_pii is true. Default-drop-to-masked, not default-plaintext — the
 * caller must explicitly opt in per call.
 *
 * @param array<int,array> $rows
 * @param bool              $include_pii
 * @return array<int,array>
 */
function sn_audit_export_redact_login_rows( array $rows, $include_pii ) {
	if ( (bool) $include_pii ) {
		return $rows;
	}
	return array_map( function( $row ) {
		if ( isset( $row['user'] ) ) {
			$row['user'] = sn_audit_export_pii_mask_username( $row['user'] );
		}
		return $row;
	}, $rows );
}

/**
 * Cap a rows array to at most $cap entries, keeping the FIRST N — the
 * login-successes source impl (inc/audit-log.php's
 * snt_audit_get_login_successes_impl()) already returns newest-first, so
 * "first N" is "N most recent", independent of whatever retention/days window
 * produced the full array.
 *
 * @param array<int,array> $rows
 * @param int               $cap
 * @return array<int,array>
 */
function sn_audit_export_cap_rows( array $rows, $cap ) {
	$cap = (int) $cap;
	if ( count( $rows ) <= $cap ) {
		return $rows;
	}
	return array_slice( $rows, 0, $cap );
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
 * v9.51.0 (lane SEC-C, R8): builds its OWN view (rather than calling
 * sn_audit_export_render(), which the admin_post download handler still uses
 * for the full-plaintext, human-authenticated path) so the page-size cap and
 * PII redaction apply HERE — the MCP/ability surface — without touching the
 * download handler at all. Default include_pii is false: a caller must name
 * the argument explicitly to get real usernames back.
 *
 * @since 4.10.0
 * @param array|null $input { format?: 'csv'|'json', include_pii?: bool }.
 * @return array { format, content }
 */
function snt_ability_export_audit_log( $input = null ) {
	$format      = is_array( $input ) && isset( $input['format'] ) ? $input['format'] : 'json';
	$format      = sn_audit_export_normalize_format( $format );
	$include_pii = is_array( $input ) && ! empty( $input['include_pii'] );

	$view                     = sn_audit_export_build_view();
	$view['login_successes'] = sn_audit_export_cap_rows( (array) ( $view['login_successes'] ?? array() ), SNT_AUDIT_EXPORT_LOGINS_PAGE_CAP );
	$view['login_successes'] = sn_audit_export_redact_login_rows( $view['login_successes'], $include_pii );

	return array(
		'format'  => $format,
		'content' => 'csv' === $format ? sn_audit_export_build_csv( $view ) : sn_audit_export_build_json( $view ),
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
