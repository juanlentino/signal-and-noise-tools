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
	// Compare against home_url()'s own path so a subdirectory install
	// (REQUEST_URI "/blog/verify") matches the same /verify the chip links
	// point at via home_url( '/verify?…' ). Root installs reduce to '/verify'.
	$home_path = ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) )
		? (string) wp_parse_url( home_url( '/verify' ), PHP_URL_PATH )
		: '/verify';
	$home_path = '/' . trim( $home_path, '/' );
	return ( $path === $home_path );
}

/**
 * Sanitize the ?note= param to a lowercase UUID shape, or '' if it isn't one.
 * A blank return means "no prefill" — it is never echoed as the raw input.
 *
 * @param string $raw
 * @return string
 */
/**
 * THE ONE producer of verification endpoints (v11.7.0, R5, §9.5 P-53).
 *
 * Extracted from the shell so the /verify page and the in-page verification
 * manifest (inc/provenance-machine-pointers.php) consume the SAME derivation
 * — one definition, structural parity, no drift between the two surfaces an
 * anonymous agent might follow. Every host here is pinned in reviewed code:
 * the site's own origin (rest_url/home_url), the fixed ledger raw host via
 * the same owner/repo filters sn_prov_ledger_note_url() uses, and the fixed
 * mempool explorer. Nothing is assembled from options, meta, or content.
 *
 * rest_url(), never a hand-built /wp-json/ prefix: a site with a customized
 * rest prefix (rest_url_prefix filter) serves REST somewhere else entirely,
 * and the hardcoded path dies silently there.
 *
 * @return array{credential_base:string,did_url:string,keys_url:string,owner:string,repo:string,ledger_base:string,mempool_base:string}
 */
function sn_prov_verify_endpoints() {
	$ns    = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	return array(
		'credential_base' => rest_url( $ns . '/credential/' ),
		'did_url'         => home_url( '/.well-known/did.json' ),
		'keys_url'        => home_url( '/.well-known/provenance-keys.json' ),
		'owner'           => $owner,
		'repo'            => $repo,
		'ledger_base'     => "https://raw.githubusercontent.com/{$owner}/{$repo}/main/",
		'mempool_base'    => 'https://mempool.space/api/',
	);
}

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
	// v10.84.0: which ledger directory holds this subject's record. An ALLOWLIST,
	// never the raw value — it reaches the client and becomes part of a fetched
	// URL. Absent means 'note', which is what every link minted before v10.84.0
	// meant, so old links keep verifying unchanged.
	$raw_kind    = isset( $_GET['kind'] ) ? sanitize_text_field( wp_unslash( $_GET['kind'] ) ) : '';
	$kind        = in_array( $raw_kind, array( 'note', 'page' ), true ) ? $raw_kind : 'note';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$uid         = sn_prov_verify_sanitize_uid( $raw_note );
	$version     = sn_prov_verify_sanitize_version( $raw_version );

	$sn_v_ep         = sn_prov_verify_endpoints();
	$credential_base = $sn_v_ep['credential_base'];
	$did_url         = $sn_v_ep['did_url'];
	$keys_url        = $sn_v_ep['keys_url'];
	$owner           = $sn_v_ep['owner'];
	$repo            = $sn_v_ep['repo'];
	$ledger_base     = $sn_v_ep['ledger_base'];
	$mempool_base    = $sn_v_ep['mempool_base'];

	$css_url  = sn_prov_verify_asset_url( 'assets/css/prov-verify.css' );
	$core_url = sn_prov_verify_asset_url( 'assets/js/prov-verify-core.js' );
	$js_url   = sn_prov_verify_asset_url( 'assets/js/prov-verify.js' );
	$diff_url = sn_prov_verify_asset_url( 'assets/js/prov-verify-diff.js' );
	$tabs_url = sn_prov_verify_asset_url( 'assets/js/prov-verify-tabs.js' );

	// The page speaks the site's own type: Bebas Neue + DM Mono, served from
	// the THEME's font files (same origin; the OG card generator already leans
	// on these exact woff2 files). If a different theme were ever active the
	// fallbacks (Impact / Courier New) carry the same intent.
	$fonts_base = function_exists( 'get_template_directory_uri' ) ? get_template_directory_uri() . '/assets/fonts' : '';

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
<?php // The &mdash; here is the site-wide DOCUMENT-TITLE separator, matching
// inc/seo.php's "Page Name — Site Name" format, not prose. Deliberately left
// by the em-dash sweep, the same exemption v10.48.2 applied across wp-admin.
// Changing it would make this one route's tab title disagree with every
// other page on the site. ?>
<title>Verify a Note &mdash; <?php echo esc_html( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'Signal & Noise' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
<?php if ( '' !== $fonts_base ) : ?>
<link rel="preload" href="<?php echo esc_url( $fonts_base . '/bebas-neue-latin.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( $fonts_base . '/dm-mono-300-latin.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:'Bebas Neue';font-weight:400;font-style:normal;font-display:swap;src:url('<?php echo esc_url( $fonts_base . '/bebas-neue-latin.woff2' ); ?>') format('woff2')}
@font-face{font-family:'DM Mono';font-weight:300;font-style:normal;font-display:swap;src:url('<?php echo esc_url( $fonts_base . '/dm-mono-300-latin.woff2' ); ?>') format('woff2')}
@font-face{font-family:'DM Mono';font-weight:400;font-style:normal;font-display:swap;src:url('<?php echo esc_url( $fonts_base . '/dm-mono-400-latin.woff2' ); ?>') format('woff2')}
@font-face{font-family:'DM Mono';font-weight:500;font-style:normal;font-display:swap;src:url('<?php echo esc_url( $fonts_base . '/dm-mono-500-latin.woff2' ); ?>') format('woff2')}
</style>
<?php endif; ?>
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
	data-kind="<?php echo esc_attr( $kind ); ?>"
>
	<header class="sn-verify-head">
		<p class="sn-verify-kicker">Signal &amp; Noise</p>
		<h1>Verify a Note</h1>
		<?php // v11.6.0 (R5, §9/P-51): the lede stops overclaiming. The old copy
		// said "nothing is taken on trust from this site" — while the CODE
		// running the checks came from this site, which is exactly the trust
		// the standalone verifier below exists to remove. The honest claim:
		// the checks run against public artifacts, and the page names its own
		// residual trust and the way out of it. ?>
		<p class="sn-verify-lede">Four checks run right here, in your browser, against the public ledger and the Bitcoin chain. One honest caveat: the code running them was served by this site. If that is the trust you came to question, the section at the end of this page shows how to run the same checks without it.</p>
	</header>

	<?php // The verdict band leads the page in the DOM, not only on screen: it is
	// hidden until a run starts, so an idle page opens on the form (the whole
	// job when there is no ?note=) and a running page opens on its answer.
	// Visual order and focus order stay identical in both modes — no CSS
	// `order` reshuffling to desynchronize them. ?>
	<section class="sn-verify-verdict" data-role="verdict" data-level="running" hidden aria-labelledby="sn-verify-verdict-word">
		<p class="sn-verify-verdict-kicker">Verdict</p>
		<p class="sn-verify-verdict-word" id="sn-verify-verdict-word" data-role="verdict-word">Checking</p>
		<p class="sn-verify-verdict-line" data-role="verdict-line"></p>
		<?php // A four-segment index of the docket below, mirroring each check's
		// state. aria-hidden: it is a redundant visual summary of four rows a
		// screen reader already reads in full, and the verdict line above it
		// already names every check that did not pass. ?>
		<ol class="sn-verify-tally" data-role="tally" aria-hidden="true">
			<li class="sn-verify-tally-seg" data-check="signature"></li>
			<li class="sn-verify-tally-seg" data-check="content-hash"></li>
			<li class="sn-verify-tally-seg" data-check="live-match"></li>
			<li class="sn-verify-tally-seg" data-check="anchor"></li>
		</ol>
		<p class="sn-verify-verdict-meta" data-role="verdict-meta"></p>
	</section>

	<form class="sn-verify-form" data-role="paste-form">
		<label for="sn-verify-input">Paste a Note URL, or a note id</label>
		<input id="sn-verify-input" name="note" type="text" autocomplete="off" spellcheck="false" placeholder="https://&hellip;/a-note-slug or a note id">
		<button type="submit">Verify</button>
	</form>

	<?php // Its own polite live region: every status message ("No public credential
	// exists…", "Could not reach…", "Done.") must reach screen readers — on the
	// error paths this line is the ONLY feedback a run produces. The verdict
	// announcements coalesce in a separate region, so no double-reads. ?>
	<p class="sn-verify-status-line" data-role="status-line" aria-live="polite"></p>

	<?php // Three panels, one nav, one at a time — not three stacked sections.
	// The verdict and the intake above are the page's constants; the checks,
	// the raw values and the diff tool are three DIFFERENT questions, and
	// stacking all three is what made this 2,400px of scroll in which the
	// answer occupied the first 15%.
	//
	// Authored as a real WAI-ARIA tablist (roving tabindex + arrow keys are
	// wired in assets/js/prov-verify-tabs.js). It degrades honestly: with no
	// tab script the nav is inert and all three panels stay visible, which is
	// the pre-v10.49.0 page — worse, never broken. ?>
	<nav class="sn-verify-nav" aria-label="Verification sections">
		<div class="sn-verify-nav-list" data-role="tablist" role="tablist">
			<button type="button" class="sn-verify-tab" role="tab" id="sn-tab-checks" aria-controls="sn-panel-checks" aria-selected="true" data-panel="checks">
				<span class="sn-verify-tab-label">The four checks</span>
				<span class="sn-verify-tab-badge" data-role="tab-badge" aria-hidden="true"></span>
			</button>
			<button type="button" class="sn-verify-tab" role="tab" id="sn-tab-walk" aria-controls="sn-panel-walk" aria-selected="false" data-panel="walk">
				<span class="sn-verify-tab-label">Proof walk</span>
			</button>
			<button type="button" class="sn-verify-tab" role="tab" id="sn-tab-compare" aria-controls="sn-panel-compare" aria-selected="false" data-panel="compare">
				<span class="sn-verify-tab-label">Compare versions</span>
			</button>
		</div>
	</nav>

	<section class="sn-verify-docket" id="sn-panel-checks" role="tabpanel" aria-labelledby="sn-tab-checks" data-panel="checks" tabindex="0">
		<p class="sn-verify-sec-lede">Each one runs against a different witness: this site, the independent git ledger, and the Bitcoin chain. Any of them can contradict the others.</p>

	<ol class="sn-verify-checks" data-role="checks">
		<li class="sn-verify-check" data-check="signature">
			<span class="sn-verify-check-no" aria-hidden="true">01</span>
			<span class="sn-verify-check-name">Signature</span>
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="content-hash">
			<span class="sn-verify-check-no" aria-hidden="true">02</span>
			<span class="sn-verify-check-name">Content hash</span>
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="live-match">
			<span class="sn-verify-check-no" aria-hidden="true">03</span>
			<span class="sn-verify-check-name">Live match</span>
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
		<li class="sn-verify-check" data-check="anchor">
			<span class="sn-verify-check-no" aria-hidden="true">04</span>
			<span class="sn-verify-check-name">Bitcoin anchor</span>
			<span class="sn-verify-check-state" data-role="state">pending</span>
			<p class="sn-verify-check-detail" data-role="detail"></p>
		</li>
	</ol>
	</section>

	<p class="sn-verify-live" data-role="announce" aria-live="polite"></p>

	<section class="sn-verify-facts" data-role="facts" hidden></section>

	<?php // Panel 2. NOTE: the walk's class attribute is exactly "sn-verify-walk"
	// — the page test pins /<section class="sn-verify-walk"[^>]*hidden/, and
	// its `hidden` means "the docket has not filled this yet", which is NOT
	// the same state as "this panel is not the selected tab". Keeping the two
	// meanings on two different elements is why the panel wraps the section
	// rather than being it: the tab script owns the wrapper's visibility, the
	// verifier owns the section's. ?>
	<div class="sn-verify-panel" id="sn-panel-walk" role="tabpanel" aria-labelledby="sn-tab-walk" data-panel="walk" tabindex="0" hidden>
		<p class="sn-verify-sec-lede">The chain of custody, value by value: the hash this Note&rsquo;s signature covers, the independent ledger&rsquo;s leaf, and the Bitcoin transaction and block that seal it. Each is labeled with where it was read from, so the independence of the three witnesses is visible, not asserted.</p>
		<section class="sn-verify-walk" data-role="walk" hidden>
			<ol class="sn-verify-walk-steps" data-role="walk-steps"></ol>
		</section>
		<?php // Shown until a run fills the walk — a selected tab must never be an
		// unexplained blank panel. The tab script hides it once steps land. ?>
		<p class="sn-verify-empty" data-role="walk-empty">Verify a Note above, and the four values it rests on appear here.</p>
	</div>

	<div class="sn-verify-panel sn-verify-compare" id="sn-panel-compare" data-role="compare" role="tabpanel" aria-labelledby="sn-tab-compare" data-panel="compare" tabindex="0" hidden>
		<p class="sn-verify-sec-lede">Every signed version stays on the chain. Pick two version numbers to see, word by word, what changed between them. Each side is labeled by its own anchor state.</p>
		<?php // Its OWN form class: .sn-verify-form is a one-label/one-input/
		// one-button bar, and pushing three labelled fields through that flex
		// row is what made this block wrap into orphaned labels. ?>
		<form class="sn-verify-cmp-form" data-role="compare-form">
			<p class="sn-verify-cmp-field sn-verify-cmp-field--wide">
				<label for="sn-compare-uid">Note id</label>
				<input id="sn-compare-uid" name="compare_uid" type="text" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $uid ); ?>">
			</p>
			<p class="sn-verify-cmp-field">
				<label for="sn-compare-a">From version</label>
				<input id="sn-compare-a" name="compare_a" type="number" min="1" step="1" value="1">
			</p>
			<p class="sn-verify-cmp-field">
				<label for="sn-compare-b">To version</label>
				<input id="sn-compare-b" name="compare_b" type="number" min="1" step="1" value="2">
			</p>
			<button type="submit">Compare</button>
		</form>
		<div class="sn-verify-compare-out" data-role="compare-out" aria-live="polite"></div>
	</div>

	<?php // v11.6.0 (R5): the standalone path — "don't trust the site's own
	// button" made literal, which is the board row's whole sentence. WORDING
	// IS GATED BY §9.5 P-54: this section says what the verifier IS (code in
	// the public ledger repo, readable before you run it) and what trusting
	// it means (you trust the code you cloned, not this site) — it does NOT
	// claim the verifier carries a verification of its own; that claim waits
	// on the software-provenance row. (The page suite pins the banned claim
	// phrases over this whole file, comments included — keep them out.) ?>
	<section class="sn-verify-standalone" aria-labelledby="sn-verify-standalone-h">
		<h2 id="sn-verify-standalone-h">Don&#8217;t trust this page either</h2>
		<p>Every check above was run by JavaScript this site served — so the page can vouch for the ledger, but not for itself. The same checks exist as a small standalone program inside the public ledger repository. It needs Node 22 and nothing else: no packages, no this-site, no trust in the page you are reading.</p>
		<pre class="sn-verify-standalone-cmd"><code>git clone https://github.com/<?php echo esc_html( $owner . '/' . $repo ); ?>.git
cd <?php echo esc_html( $repo ); ?>
node verify.mjs<?php echo '' !== $uid ? ' ' . esc_html( $uid ) : ' &lt;note_uid&gt;'; ?></code></pre>
		<p>What you are trusting then: the code in your clone, which you can read first &#8212; it is a few small files &#8212; and one public block-explorer lookup for the Bitcoin header. <a href="<?php echo esc_url( 'https://github.com/' . $owner . '/' . $repo . '/blob/main/VERIFY.md' ); ?>" rel="noopener">VERIFY.md</a> in the repository walks through every check it runs and every byte it recomputes.</p>
		<?php // v11.6.1 (R5): the software-provenance half. Wording still
		// bound by the P-54 absence pins: states what the attestation proves
		// and names its anchor — never a claim-shaped phrase. ?>
		<p>Releases of the verifier are built in the repository&#8217;s public CI and published with a build attestation, so a downloaded copy can prove which commit built it. The attestation&#8217;s anchor is the code host itself &#8212; reading the code in your clone remains the trust floor. VERIFY.md&#8217;s &#8220;Verify the verifier&#8221; section shows the check and states plainly what it does and does not prove.</p>
	</section>

	<footer class="sn-verify-foot">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">juanlentino.com</a>
		<span aria-hidden="true">&middot;</span>
		<a href="<?php echo esc_url( 'https://github.com/' . $owner . '/' . $repo ); ?>" rel="noopener">Git ledger</a>
	</footer>

	<p class="sn-verify-noscript"><noscript>Verification runs in JavaScript, in your own browser. Enable it to run the checks. Nothing is sent anywhere by doing so.</noscript></p>
</div>
<?php // The pure decision core is a hard dependency of the page script — it
// MUST load first. This standalone route never runs the wp_enqueue_scripts
// lifecycle (the page exits at template_redirect), so the dependency is
// expressed the way this shell already expresses assets: emitted in order,
// both `defer` (deferred scripts execute in document order per spec — the
// same load-order guarantee WP's dependency graph would provide), both
// cache-busted via sn_prov_verify_asset_url()'s ?ver=SNT_VERSION param. ?>
<script src="<?php echo esc_url( $core_url ); ?>" defer></script>
<script src="<?php echo esc_url( $js_url ); ?>" defer></script>
<script src="<?php echo esc_url( $diff_url ); ?>" defer></script>
<?php // The tab nav is deliberately LAST and depends on nothing: it only hides
// panels the shell shipped visible, so if it never loads the page falls back
// to the old all-at-once scroll instead of to three unreachable panels. ?>
<script src="<?php echo esc_url( $tabs_url ); ?>" defer></script>
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
