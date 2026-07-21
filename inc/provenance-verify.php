<?php
/**
 * Signal & Noise — verifiable provenance: the human-facing /verify page.
 *
 * A standalone, noindex, client-side verifier. The page's job is limited to
 * emitting a static shell plus the config an in-browser script needs (every
 * endpoint as a data attribute — the JS hardcodes no URL); ALL cryptographic
 * and network verification happens in the reader's own browser
 * (assets/js/prov-verify.js). This file holds no keys and does no signing.
 * Flush-free virtual route (template_redirect priority 0, status_header(200)
 * required — a postless path 404s without it), same mechanism as
 * inc/provenance-did.php. Gated by SN_PROV_VERIFY_TEST for the standalone
 * test fixture, mirroring the SN_PROV_DID_TEST pattern.
 *
 * @package SignalNoiseTools
 * @since 9.73.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this request for /verify? Pure (takes the path). A trailing slash and a
 * query string are both accepted; a path merely prefixed with "verify" (or
 * the unrelated /provenance/verify Page) is not.
 *
 * @param string $uri
 * @return bool
 */
function sn_prov_verify_is_request( $uri ) {
	$path = strtok( (string) $uri, '?' );
	$path = '/' . trim( (string) $path, '/' );
	return ( '/verify' === $path );
}

/**
 * Sanitize the ?note= param to a lowercase UUID shape, or '' if it isn't one.
 * A blank return means "no prefill" — it is never echoed as the raw input.
 *
 * @param string $raw
 * @return string
 */
function sn_prov_verify_sanitize_uid( $raw ) {
	$raw = strtolower( trim( (string) $raw ) );
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $raw ) ) {
		return '';
	}
	return $raw;
}

/**
 * Sanitize the ?v= param to a non-negative int. 0 means "unset/invalid" (the
 * page then verifies whichever version the credential endpoint resolves as
 * latest); a decimal string truncates, a negative or non-numeric value blanks.
 *
 * @param string $raw
 * @return int
 */
function sn_prov_verify_sanitize_version( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return 0;
	}
	$v = (int) $raw;
	return $v > 0 ? $v : 0;
}

/**
 * Build the enqueue-free asset URL for a file under this plugin, with the
 * plugin version as a cache-buster query param — the same plugins_url() +
 * SNT_VERSION mechanism used for the enqueued provenance/maturity front-end
 * styles, just written inline because this standalone route never runs
 * through the normal wp_enqueue_scripts lifecycle.
 *
 * @param string $rel_path Path relative to the plugin root, e.g. 'assets/js/prov-verify.js'.
 * @return string
 */
function sn_prov_verify_asset_url( $rel_path ) {
	$url = plugins_url( $rel_path, SNT_PATH . 'signal-and-noise-tools.php' );
	return $url . '?ver=' . rawurlencode( (string) SNT_VERSION );
}

/**
 * Emit the standalone verifier page (always 200 — even a malformed/absent
 * ?note=/?v= just renders a blank-prefilled page; there is nothing to 404).
 */
function sn_prov_verify_send() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill of a public page, never a state change.
	$raw_note    = isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '';
	$raw_version = isset( $_GET['v'] ) ? sanitize_text_field( wp_unslash( $_GET['v'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$uid         = sn_prov_verify_sanitize_uid( $raw_note );
	$version     = sn_prov_verify_sanitize_version( $raw_version );

	$ns              = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	$credential_base = home_url( '/wp-json/' . $ns . '/credential/' );
	$did_url         = home_url( '/.well-known/did.json' );
	$keys_url        = home_url( '/.well-known/provenance-keys.json' );
	// Same filters sn_prov_ledger_note_url() uses, so the ledger base the JS
	// fetches from always matches the panel's own "Git ledger" link.
	$owner        = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo         = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	$ledger_base  = "https://raw.githubusercontent.com/{$owner}/{$repo}/main/";
	$mempool_base = 'https://mempool.space/api/';

	$css_url = sn_prov_verify_asset_url( 'assets/css/prov-verify.css' );
	$js_url  = sn_prov_verify_asset_url( 'assets/js/prov-verify.js' );

	if ( function_exists( 'status_header' ) ) {
		status_header( 200 ); // required: a postless path 404s by default via template_redirect.
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Verify a Note &mdash; Signal &amp; Noise</title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<div class="sn-verify"
	data-credential-base="<?php echo esc_attr( $credential_base ); ?>"
	data-did-url="<?php echo esc_attr( $did_url ); ?>"
	data-keys-url="<?php echo esc_attr( $keys_url ); ?>"
	data-ledger-base="<?php echo esc_attr( $ledger_base ); ?>"
	data-mempool-base="<?php echo esc_attr( $mempool_base ); ?>"
	data-note="<?php echo esc_attr( $uid ); ?>"
	data-version="<?php echo esc_attr( (string) $version ); ?>"
>
	<header class="sn-verify-head">
		<p class="sn-verify-kicker">Signal &amp; Noise</p>
		<h1>Verify a Note</h1>
		<p class="sn-verify-lede">Four checks run right here, in your browser. Nothing is taken on trust from this site &mdash; the signature, the content hash, and the Bitcoin anchor are all independently checkable against the public ledger and the Bitcoin chain themselves.</p>
	</header>

	<form class="sn-verify-form" data-role="paste-form">
		<label for="sn-verify-input">Paste a Note URL, or a note id</label>
		<input id="sn-verify-input" name="note" type="text" autocomplete="off" spellcheck="false" placeholder="https://&hellip;/a-note-slug or a note id">
		<button type="submit">Verify</button>
	</form>

	<p class="sn-verify-status-line" data-role="status-line" aria-hidden="true"></p>

	<ol class="sn-verify-checks" data-role="checks">
		<li class="sn-verify-check" data-check="signature">
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<span class="sn-verify-check-name">Signature</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="content-hash">
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<span class="sn-verify-check-name">Content hash</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="live-match">
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<span class="sn-verify-check-name">Live match</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="anchor">
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<span class="sn-verify-check-name">Bitcoin anchor</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
	</ol>

	<p class="sn-verify-live" data-role="announce" aria-live="polite"></p>

	<section class="sn-verify-facts" data-role="facts" hidden></section>

	<p class="sn-verify-noscript"><noscript>Verification runs in JavaScript, in your own browser. Enable it to run the checks &mdash; nothing is sent anywhere by doing so.</noscript></p>
</div>
<script src="<?php echo esc_url( $js_url ); ?>" defer></script>
</body>
</html>
	<?php
}

/**
 * template_redirect handler (priority 0 — before WP resolves a 404 for this
 * postless path). Disabled under the test constant so the standalone fixture
 * can call sn_prov_verify_send() directly without wiring the WP hook system.
 */
function sn_prov_verify_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_prov_verify_is_request( $req ) ) {
		sn_prov_verify_send();
		exit;
	}
}

if ( ! defined( 'SN_PROV_VERIFY_TEST' ) || ! SN_PROV_VERIFY_TEST ) {
	add_action( 'template_redirect', 'sn_prov_verify_maybe_serve', 0 );
}
