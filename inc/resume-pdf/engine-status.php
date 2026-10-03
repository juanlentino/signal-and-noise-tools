<?php
/**
 * Resume PDF: the engine's status (Dompdf). Installed version, whether our
 * Cpdf patch is in, and the latest Dompdf release, read from GitHub at most
 * once a day and kept in an option (a failed read keeps the last answer).
 *
 * The patch (Cpdf.php, 20.3.2) stops justified Unicode text writing NUL
 * bytes that pypdf rejects. Upstream confirmed it (dompdf/dompdf#3771,
 * milestone 3.1.7). A bump overwrites the vendored file, so the line says
 * which state the engine is in, and the watch fires when a newer release
 * exists: bump, then check whether upstream's fix lets the patch go.
 *
 * Updating stays a deploy, not a button: the vendored tree ships with the
 * plugin and a release is how it changes.
 *
 * @package SignalNoiseTools
 * @since   20.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PDF_ENGINE_LATEST_OPTION = 'snt_pdf_engine_latest';

/**
 * The vendored Dompdf version ('3.1.6'), or '' when unreadable.
 *
 * @param string|null $json Test seam: installed.json contents.
 * @return string
 */
function snt_pdf_engine_installed( $json = null ) {
	if ( null === $json ) {
		$file = SNT_PATH . 'lib/pdf/vendor/composer/installed.json';
		$json = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local vendored file.
	}
	$data = json_decode( (string) $json, true );
	foreach ( (array) ( $data['packages'] ?? $data ) as $pkg ) {
		if ( is_array( $pkg ) && 'dompdf/dompdf' === ( $pkg['name'] ?? '' ) ) {
			return ltrim( (string) ( $pkg['version'] ?? '' ), 'v' );
		}
	}
	return '';
}

/**
 * Whether the vendored Cpdf carries our patch.
 *
 * @param string|null $src Test seam: Cpdf.php contents.
 * @return bool
 */
function snt_pdf_engine_patched( $src = null ) {
	if ( null === $src ) {
		$file = SNT_PATH . 'lib/pdf/vendor/dompdf/dompdf/lib/Cpdf.php';
		$src  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local vendored file.
	}
	return false !== strpos( (string) $src, 'Signal & Noise patch' );
}

/**
 * The latest Dompdf release ('3.1.7'), refreshed when the stored answer is a
 * day old. '' when it has never been read.
 *
 * @return string
 */
function snt_pdf_engine_latest() {
	$stored = get_option( SNT_PDF_ENGINE_LATEST_OPTION );
	$stored = is_array( $stored ) ? $stored : array( 'version' => '', 'at' => 0 );
	if ( time() - (int) $stored['at'] < DAY_IN_SECONDS ) {
		return (string) $stored['version'];
	}
	$resp = wp_remote_get(
		'https://api.github.com/repos/dompdf/dompdf/releases/latest',
		array(
			'timeout' => 5,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'signal-and-noise-tools' ),
		)
	);
	$tag = '';
	if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$tag  = ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' );
	}
	// A failed read keeps the last answer and retries tomorrow.
	$stored = array( 'version' => '' !== $tag ? $tag : (string) $stored['version'], 'at' => time() );
	update_option( SNT_PDF_ENGINE_LATEST_OPTION, $stored, false );
	return $stored['version'];
}

/**
 * One line for the Resume PDF section. PURE given its inputs.
 *
 * @param string $installed Installed version.
 * @param bool   $patched   Whether the Cpdf patch is in.
 * @param string $latest    Latest release, '' when unknown.
 * @return string
 */
function snt_pdf_engine_line( $installed, $patched, $latest ) {
	if ( '' === $installed ) {
		return 'PDF engine: Dompdf version unreadable.';
	}
	$line = sprintf( 'PDF engine: Dompdf %s, %s.', $installed, $patched ? 'with our text fix (dompdf#3771)' : 'WITHOUT our text fix: justified text may write bytes some readers reject' );
	if ( '' === $latest ) {
		return $line . ' Latest release not read yet.';
	}
	if ( version_compare( $latest, $installed, '>' ) ) {
		return $line . sprintf( ' Dompdf %s is out: a plugin release can bump it; check whether it carries the fix before dropping ours.', $latest );
	}
	return $line . ' Up to date.';
}

/**
 * The live line.
 *
 * @return string
 */
function snt_pdf_engine_status_line() {
	return snt_pdf_engine_line( snt_pdf_engine_installed(), snt_pdf_engine_patched(), snt_pdf_engine_latest() );
}

/**
 * Watch: ripe when a Dompdf release newer than the vendored one exists.
 *
 * @param array       $watch  The watch row.
 * @param int         $now    Unix time (unused).
 * @param array|null  $state  Test seam: {installed, latest}.
 * @return array{ripe:bool,note:string}
 */
function snt_watch_ripe_dompdf( $watch, $now, $state = null ) {
	unset( $watch, $now );
	$state     = is_array( $state ) ? $state : array( 'installed' => snt_pdf_engine_installed(), 'latest' => snt_pdf_engine_latest() );
	$installed = (string) $state['installed'];
	$latest    = (string) $state['latest'];
	if ( '' === $installed || '' === $latest ) {
		return array( 'ripe' => false, 'note' => 'versions not read' );
	}
	if ( ! version_compare( $latest, $installed, '>' ) ) {
		return array( 'ripe' => false, 'note' => "Dompdf $installed is the latest" );
	}
	return array( 'ripe' => true, 'note' => "Dompdf $latest is out (vendored $installed): bump lib/pdf, keep or drop the Cpdf patch by whether #3771's fix shipped" );
}
