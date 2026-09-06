<?php
/**
 * Signal & Noise Tools — the HOST: an admin page painted inside an
 * OpenStation app window, unchanged.
 *
 * WHY THIS EXISTS. The classic Signal & Noise admin page is 8 top tabs over
 * ~35 leaves, 57 forms, one flash table and one handler table. Rewriting any
 * of that for the App Framework would be a redesign, and a redesign is not a
 * port. So nothing is rewritten: the SAME render callables paint the SAME
 * HTML, and this file is the four seams that let that HTML live in a window
 * instead of a document.
 *
 *   1. CAPTURE. A leaf reads `$_GET` and echoes. A dispatch is a REST request
 *      whose superglobals belong to the dispatch, not to the leaf. So the
 *      capture lends the leaf the query it would have had, runs it under
 *      `ob_start()`, and puts the superglobals back — in a `finally`, because
 *      a leaf that throws must not leave the request wearing someone else's
 *      `$_GET`.
 *   2. REWRITE. Two seams in that HTML would leave the window: a `<form>`
 *      submit and an `<a href>` click (the runtime preventDefaults `submit`
 *      but NOT `click`, so a rewritten link must LOSE its href — measured in
 *      assets/js/app-runtime.min.js). A third would never run: an inline
 *      `<script>` painted by innerHTML. One pass over WP_HTML_Tag_Processor
 *      touches exactly those and nothing else.
 *   3. REPLAY. `sn_handle_admin_post()` ends in `header()` + `exit`, which a
 *      window cannot do, so it can never be called here. Everything BEFORE
 *      that exit is reproduced exactly: capability, nonce, page, the handler
 *      table, the flash code, the redirect target. The two pure resolvers
 *      (`sn_admin_post_redirect_target()`, `sn_admin_flash_to_notice()`) are
 *      CALLED, never copied.
 *   4. ASSETS. Every leaf behaviour is a script that self-gates on a DOM
 *      marker, enqueued today on the classic hook suffixes. The window-args
 *      seam registers the same handles, with the same data, from the same
 *      builders.
 *
 * Shared by both hosts (S&N Dashboard, S&N Analytics); nothing here knows
 * which page it is painting. Spec: docs/proposals/2026-09-06-openstation-hosts.md.
 *
 * @package SignalNoiseTools
 * @since 13.104.0
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** The nonce action every SN admin form carries. Same literal as the classic dispatcher. */
if ( ! defined( 'SNT_OS_HOST_NONCE' ) ) {
	define( 'SNT_OS_HOST_NONCE', 'sn_theme_options_nonce' );
}

/** Ceiling on the `sn_*` query params a window carries in its state (they ride every dispatch). */
if ( ! defined( 'SNT_OS_HOST_PARAM_CAP' ) ) {
	define( 'SNT_OS_HOST_PARAM_CAP', 20 );
}

/**
 * Run a render callable with a borrowed request, and give the request back.
 *
 * The leaves read `$_GET` (tab, sub, `sn_*`, `new_id`) and a couple read
 * `$_REQUEST`; one form (`audit_prune_now`) is handled INLINE from `$_POST`
 * inside its own render function, which is why `$post` exists here at all.
 *
 * `$_REQUEST` is rebuilt as `$get` merged under `$post` — POST wins, the way
 * PHP's default `request_order` (GP) builds it — so a form's hidden `tab`
 * beats the window's, exactly as it beats the URL's today.
 *
 * The restore is in `finally`: a leaf that throws would otherwise leave the
 * rest of the dispatch reading a query that belongs to a page it abandoned.
 *
 * @param callable            $paint Echoes the leaf. Called with no arguments.
 * @param array<string,mixed> $get   The `$_GET` the leaf should see (include `page`).
 * @param array<string,mixed> $post  The `$_POST` the leaf should see.
 * @return string The HTML the callable echoed.
 * @throws \Throwable Whatever the callable threw, after the buffer is discarded.
 */
function snt_os_host_capture( callable $paint, array $get = array(), array $post = array() ) {
	// phpcs:disable WordPress.Security.NonceVerification -- Reading the CURRENT superglobals only to restore them byte-for-byte; nothing here is input.
	$prev_get     = $_GET;
	$prev_post    = $_POST;
	$prev_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification
	$_GET     = $get;
	$_POST    = $post;
	$_REQUEST = array_merge( $get, $post );

	snt_os_host_admin_bootstrap();
	ob_start();
	try {
		call_user_func( $paint );
		return (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		throw $e;
	} finally {
		$_GET     = $prev_get;
		$_POST    = $prev_post;
		$_REQUEST = $prev_request;
	}
}

/**
 * Load wp-admin's function library when a request arrived without it.
 *
 * The classic page runs inside wp-admin, where `wp-admin/includes/admin.php`
 * has loaded submit_button(), get_plugins(), the screen API and the rest. A
 * window's dispatch is a REST request, which loads none of it: measured
 * 2026-09-06, Integrity -> MCP Clients answered 500 "Call to undefined
 * function submit_button()" while the same leaf captured cleanly under
 * WP-CLI. admin-ajax.php sets the precedent -- it requires this file for
 * exactly this reason -- so the host does the same, once, and only when the
 * library is absent. get_current_screen() still answers null here: a REST
 * request has no screen, and the one leaf that reads it already guards.
 *
 * @return void
 */
function snt_os_host_admin_bootstrap() {
	if ( function_exists( 'submit_button' ) || ! defined( 'ABSPATH' ) ) {
		return;
	}
	$library = ABSPATH . 'wp-admin/includes/admin.php';
	if ( is_readable( $library ) ) {
		require_once $library;
	}
}

/**
 * The site's `wp-admin/` base, as `[ host, path ]`, or null when WordPress is
 * not loaded (a standalone host, a suite).
 *
 * @return array{host:string,path:string}|null
 */
function snt_os_host_admin_base() {
	if ( ! function_exists( 'admin_url' ) ) {
		return null;
	}
	$base = (string) admin_url( '/' );
	$host = (string) wp_parse_url( $base, PHP_URL_HOST );
	$path = (string) wp_parse_url( $base, PHP_URL_PATH );
	if ( '' === $host || '' === $path ) {
		return null;
	}
	return array(
		'host' => strtolower( $host ),
		'path' => $path,
	);
}

/**
 * Whether a URL is an admin URL of THIS site.
 *
 * Host AND path prefix, never a bare `strpos` on the whole URL: `http` vs
 * `https` and a port would each make an identical screen look foreign.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
function snt_os_host_is_admin_url( $url ) {
	$base = snt_os_host_admin_base();
	if ( null === $base ) {
		return false;
	}
	$url    = (string) $url;
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return false;
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return $host === $base['host'] && 0 === strpos( $path, $base['path'] );
}

/**
 * Resolve an href the way a browser sitting on an admin page would.
 *
 * A bare `admin.php?page=…` on an admin screen is relative to `wp-admin/`,
 * not to the site root — the one resolution that decides whether such a link
 * becomes a window action or is left to navigate the whole desktop away.
 *
 * @param string $href Raw href.
 * @return string Absolute URL, or '' when it cannot be resolved.
 */
function snt_os_host_absolute_url( $href ) {
	$href = trim( (string) $href );
	if ( '' === $href ) {
		return '';
	}
	if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
		return $href;
	}
	$base = snt_os_host_admin_base();
	if ( null === $base ) {
		return '';
	}
	$origin = (string) preg_replace( '#' . preg_quote( $base['path'], '#' ) . '$#', '', (string) admin_url( '/' ) );
	if ( 0 === strpos( $href, '//' ) ) {
		return $href;
	}
	if ( 0 === strpos( $href, '/' ) ) {
		return $origin . $href;
	}
	return rtrim( (string) admin_url( '/' ), '/' ) . '/' . $href;
}

/**
 * The one rewrite pass. See the spec's table; the branches are in that order.
 *
 * What is NOT touched, and why each: a `#fragment`-only link (the composite
 * Identity & SEO leaf's section tabs are admin.js's job, in-page); `mailto:`,
 * `tel:`, `javascript:`; and anything already carrying `os-action` (a second
 * pass must be a no-op).
 *
 * `data-snt-submit` on a named submit button is the ONE addition beyond the
 * spec's list, and it is load-bearing: the runtime ships a form as
 * `new FormData( form )`, which EXCLUDES the clicked submit button, while 45
 * of this estate's submit buttons carry `sn_action` on the button itself
 * (17 files; the rest use a hidden input). Without a client seam that writes
 * the submitter back into the form, those saves arrive with no action at all.
 * The marker is inert on its own; assets/os-host.js is what reads it.
 *
 * @param string   $html The captured leaf HTML.
 * @param string[] $own  `page=` slugs this window paints itself.
 * @return string
 */
function snt_os_host_rewrite( $html, array $own = array( 'sn-theme-options' ) ) {
	$html = (string) $html;
	if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag() ) {
		switch ( (string) $tags->get_tag() ) {
			case 'FORM':
				snt_os_host_rewrite_form( $tags );
				break;
			case 'A':
				snt_os_host_rewrite_link( $tags, $own );
				break;
			case 'BUTTON':
			case 'INPUT':
				snt_os_host_rewrite_submitter( $tags );
				break;
			case 'SCRIPT':
				$tags->set_attribute( 'data-snt-exec', '1' );
				break;
		}
	}
	return (string) $tags->get_updated_html();
}

/**
 * A `<form>`: POST saves through `post`, GET forms navigate through `go`.
 *
 * `method` is KEPT on a POST form (the classic markup's own declaration, and
 * what the runtime keys its `submit` listener on); `action` is dropped, since
 * the destination is now an action name, not a URL.
 *
 * @param WP_HTML_Tag_Processor $tags Positioned on the tag.
 * @return void
 */
function snt_os_host_rewrite_form( $tags ) {
	if ( null !== $tags->get_attribute( 'os-action' ) ) {
		return;
	}
	$method = strtolower( trim( (string) $tags->get_attribute( 'method' ) ) );
	if ( 'post' === $method ) {
		$tags->set_attribute( 'os-action', 'post' );
		$tags->remove_attribute( 'action' );
		return;
	}
	$tags->set_attribute( 'os-action', 'go' );
}

/**
 * A submit button that carries its own name/value. See `snt_os_host_rewrite()`.
 *
 * A `<button>` with no `type` inside a form IS a submit button (HTML), so an
 * absent type counts.
 *
 * @param WP_HTML_Tag_Processor $tags Positioned on the tag.
 * @return void
 */
function snt_os_host_rewrite_submitter( $tags ) {
	$name = $tags->get_attribute( 'name' );
	if ( ! is_string( $name ) || '' === $name ) {
		return;
	}
	$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
	$is_button_submit = 'BUTTON' === (string) $tags->get_tag() && ( '' === $type || 'submit' === $type );
	$is_input_submit  = 'INPUT' === (string) $tags->get_tag() && ( 'submit' === $type || 'image' === $type );
	if ( $is_button_submit || $is_input_submit ) {
		$tags->set_attribute( 'data-snt-submit', '1' );
	}
}

/**
 * An `<a href>`: into our page → `go`; into any other admin screen → `door`;
 * anywhere else on the web → keep the href and open a tab.
 *
 * THE RECORDED DEVIATION is the last one: today a targetless external link
 * replaces the admin tab; in a window it would replace the whole desktop, so
 * it gains `target="_blank" rel="noopener noreferrer"`. The destination is
 * unchanged, which is what faithful means.
 *
 * @param WP_HTML_Tag_Processor $tags Positioned on the tag.
 * @param string[]              $own  `page=` slugs this window paints itself.
 * @return void
 */
function snt_os_host_rewrite_link( $tags, array $own ) {
	if ( null !== $tags->get_attribute( 'os-action' ) ) {
		return;
	}
	$href = $tags->get_attribute( 'href' );
	if ( ! is_string( $href ) ) {
		return;
	}
	$href = trim( $href );
	if ( '' === $href || '#' === $href[0] ) {
		return;
	}
	$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
	if ( '' !== $scheme && 'http' !== $scheme && 'https' !== $scheme ) {
		return; // mailto:, tel:, javascript: — never ours to rewrite.
	}

	$absolute = snt_os_host_absolute_url( $href );
	if ( '' === $absolute || ! snt_os_host_is_admin_url( $absolute ) ) {
		if ( null === $tags->get_attribute( 'target' ) ) {
			$tags->set_attribute( 'target', '_blank' );
			$tags->set_attribute( 'rel', 'noopener noreferrer' );
		}
		return;
	}

	$query = array();
	parse_str( (string) wp_parse_url( $absolute, PHP_URL_QUERY ), $query );
	$page = isset( $query['page'] ) ? (string) $query['page'] : '';
	if ( ! in_array( $page, $own, true ) ) {
		$tags->remove_attribute( 'href' );
		$tags->set_attribute( 'os-action', 'door' );
		$tags->set_attribute( 'os-arg-url', $absolute );
		return;
	}

	$tab = isset( $query['tab'] ) ? (string) $query['tab'] : '';
	if ( '' === $tab && function_exists( 'sn_admin_page_tab_for_slug' ) ) {
		// No ?tab= is how the Dashboard is normally linked; the classic page
		// derives the tab from the page slug, so the window does too.
		$tab = (string) sn_admin_page_tab_for_slug( $page );
	}
	$tags->remove_attribute( 'href' );
	$tags->set_attribute( 'os-action', 'go' );
	if ( '' !== $tab ) {
		$tags->set_attribute( 'os-arg-tab', $tab );
	}
	if ( isset( $query['sub'] ) && is_string( $query['sub'] ) && '' !== $query['sub'] ) {
		$tags->set_attribute( 'os-arg-sub', $query['sub'] );
	}
	$fragment = (string) wp_parse_url( $absolute, PHP_URL_FRAGMENT );
	if ( 0 === strpos( $fragment, 'sn-sec-' ) ) {
		$tags->set_attribute( 'os-arg-anchor', substr( $fragment, 7 ) );
	}
	foreach ( snt_os_host_params( $query ) as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$tags->set_attribute( 'os-arg-' . $key, (string) $value );
		}
	}
}

/**
 * The `sn_*` query params a window carries, filtered and bounded.
 *
 * These ARE state on the classic page — the Tags leaf's merge preview is
 * three of them — so they have to survive a paint. They also ride every
 * dispatch, hence the caps.
 *
 * @param array<string,mixed> $source A `$_GET`-shaped array.
 * @return array<string,string|string[]>
 */
function snt_os_host_params( array $source ) {
	$out = array();
	foreach ( $source as $key => $value ) {
		if ( ! is_string( $key ) || ! preg_match( '/^sn_[a-z0-9_]{1,40}$/', $key ) ) {
			continue;
		}
		if ( count( $out ) >= SNT_OS_HOST_PARAM_CAP ) {
			break;
		}
		if ( is_array( $value ) ) {
			$list = array();
			foreach ( array_slice( $value, 0, 100 ) as $item ) {
				if ( is_scalar( $item ) ) {
					$list[] = substr( (string) $item, 0, 200 );
				}
			}
			$out[ $key ] = $list;
			continue;
		}
		if ( is_scalar( $value ) ) {
			$out[ $key ] = substr( (string) $value, 0, 200 );
		}
	}
	return $out;
}

/**
 * Replay one form submission through the classic pipeline, minus the exit.
 *
 * Every gate `sn_handle_admin_post()` applies, in its order and with its
 * meaning: capability, nonce, page allowlist, handler table. The values are
 * `wp_slash()`ed into `$_POST` because every handler `wp_unslash()`es what it
 * reads — an unslashed replay silently eats a quote in a saved title.
 *
 * `reason` is here because the plan's three refusals — no capability, bad
 * nonce, unknown action — are three different facts, and a caller that can
 * only see `ok:false` would have to guess which one to say.
 *
 * @param array<string,mixed> $values    The submitted fields (`$args['values']`).
 * @param string              $page_slug The `page=` slug the window stands for.
 * @param array<string,mixed> $get       The query the window would have had (tab, sub, `sn_*`).
 * @return array{ok:bool,flash:string,target:?array,reason:string}
 */
function snt_os_host_replay( array $values, $page_slug, array $get = array() ) {
	$refused = static function ( $reason ) {
		return array(
			'ok'     => false,
			'flash'  => '',
			'target' => null,
			'reason' => $reason,
		);
	};

	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		return $refused( 'capability' );
	}
	$nonce = isset( $values['_wpnonce'] ) && is_scalar( $values['_wpnonce'] ) ? (string) $values['_wpnonce'] : '';
	if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, SNT_OS_HOST_NONCE ) ) {
		return $refused( 'nonce' );
	}
	$page_slug = (string) $page_slug;
	if ( function_exists( 'sn_admin_post_allowed_pages' ) && ! in_array( $page_slug, sn_admin_post_allowed_pages(), true ) ) {
		return $refused( 'page' );
	}
	$action   = isset( $values['sn_action'] ) && is_scalar( $values['sn_action'] ) ? sanitize_text_field( (string) $values['sn_action'] ) : '';
	$handlers = function_exists( 'sn_admin_post_handlers' ) ? sn_admin_post_handlers() : array();
	if ( '' === $action || ! isset( $handlers[ $action ] ) || ! is_callable( $handlers[ $action ] ) ) {
		return $refused( 'unknown' );
	}

	// phpcs:disable WordPress.Security.NonceVerification -- Snapshotting the superglobals to restore them; the nonce was verified above.
	$prev_get     = $_GET;
	$prev_post    = $_POST;
	$prev_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification
	try {
		$_GET     = $get;
		$_POST    = wp_slash( $values );
		$_REQUEST = array_merge( $get, $_POST, array( 'page' => $page_slug ) );

		$flash = (string) call_user_func( $handlers[ $action ], $_POST );

		// The classic dispatcher only resolves a target when the request
		// carried a tab; without one it redirects to the bare page. Mirrored,
		// not re-decided.
		$target = null;
		if ( isset( $_REQUEST['tab'] ) && function_exists( 'sn_admin_post_redirect_target' ) ) {
			$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
			$requested_sub = isset( $_REQUEST['sub'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) ) : '';
			$target        = sn_admin_post_redirect_target( $requested_tab, $requested_sub );
		}
		return array(
			'ok'     => true,
			'flash'  => $flash,
			'target' => $target,
			'reason' => '',
		);
	} finally {
		$_GET     = $prev_get;
		$_POST    = $prev_post;
		$_REQUEST = $prev_request;
	}
}

/**
 * A flash code as the classic page's notice: `[ severity, html ]`, or null.
 *
 * Calls the shared registry; an unknown code renders nothing, which is what
 * the classic page does with one.
 *
 * @param string $flash Flash code.
 * @return array{0:string,1:string}|null
 */
function snt_os_host_notice( $flash ) {
	$flash = (string) $flash;
	if ( '' === $flash || ! function_exists( 'sn_admin_flash_to_notice' ) ) {
		return null;
	}
	$notice = sn_admin_flash_to_notice( $flash );
	return is_array( $notice ) && isset( $notice[0], $notice[1] ) ? array( (string) $notice[0], (string) $notice[1] ) : null;
}

/**
 * The same notice as one line of plain text, for `$os->toast()`.
 *
 * A toast has no tone and no markup (Effects::toast's own docblock), so the
 * colour and the `<a>` stay in the in-window notice; the toast is the part a
 * reader sees without looking at the body.
 *
 * @param array{0:string,1:string}|null $notice From `snt_os_host_notice()`.
 * @return string
 */
function snt_os_host_toast_text( $notice ) {
	if ( ! is_array( $notice ) || ! isset( $notice[1] ) ) {
		return '';
	}
	$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $notice[1] ) : (string) $notice[1];
	return trim( (string) $text );
}

/**
 * The sub-tab a (tab, requested sub) pair resolves to.
 *
 * The same two rules as `sn_admin_resolve_active_sub()` — the requested leaf
 * when the tab has it, else the first — read off the same registry accessor.
 * A separate function only because the classic one reads `$_GET`, and the
 * window's sub lives in state.
 *
 * @param string $tab       Top-tab slug.
 * @param string $requested Requested sub-tab slug.
 * @return string '' for a landing tab with no sub-tabs.
 */
function snt_os_host_resolve_sub( $tab, $requested ) {
	if ( ! function_exists( 'sn_admin_get_sub_tabs' ) ) {
		return '';
	}
	$sub_tabs = sn_admin_get_sub_tabs( (string) $tab );
	if ( empty( $sub_tabs ) ) {
		return '';
	}
	$requested = (string) $requested;
	if ( '' !== $requested && isset( $sub_tabs[ $requested ] ) ) {
		return $requested;
	}
	return (string) array_key_first( $sub_tabs );
}

/**
 * Where a (tab, sub) pair actually lands: canonical tab, resolved sub, anchor.
 *
 * `sn_admin_post_redirect_target()` is the estate's own resolver for a moved
 * leaf, a legacy slug and an unknown tab (which falls back to dashboard); the
 * sub is then resolved the way the renderer resolves it. Both CALLED.
 *
 * @param string $tab Requested top tab.
 * @param string $sub Requested sub-tab.
 * @return array{tab:string,sub:string,anchor:string}
 */
function snt_os_host_destination( $tab, $sub = '' ) {
	$tab = (string) $tab;
	$sub = (string) $sub;
	if ( ! function_exists( 'sn_admin_post_redirect_target' ) ) {
		return array(
			'tab'    => '' !== $tab ? $tab : 'dashboard',
			'sub'    => $sub,
			'anchor' => '',
		);
	}
	$target = sn_admin_post_redirect_target( '' !== $tab ? $tab : 'dashboard', $sub );
	$tab    = (string) ( $target['tab'] ?? 'dashboard' );
	return array(
		'tab'    => $tab,
		'sub'    => snt_os_host_resolve_sub( $tab, (string) ( $target['sub'] ?? '' ) ),
		'anchor' => (string) ( $target['anchor'] ?? '' ),
	);
}

/**
 * The handles a host window needs, registered the way their own pages
 * register them.
 *
 * WHY A LIST AND NOT A HOOK. Every one of these is enqueued today by a guard
 * on `sn_admin_page_hooks()` — the classic hook suffixes — and the desktop
 * page is not one of them, so on the desktop NONE of them load. The window
 * cannot borrow that guard either: two of the eight are gated a second time
 * on the active tab/sub, which a window state answers and a hook suffix does
 * not. So the handles are named, and each is registered from the SAME source
 * and the SAME data builder its own page uses — never a copied URL, never a
 * copied localize literal.
 *
 * Registration, not enqueue: the shell enqueues a window's `scripts` and
 * `styles` when the window first opens, so a desktop page nobody opens this
 * window on pays nothing.
 *
 * @return array{styles:string[],scripts:string[]}
 */
function snt_os_host_asset_handles() {
	return array(
		'styles'  => array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status', 'sn-provenance-admin', 'snt-audit-log' ),
		'scripts' => array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-freshness-dot', 'snt-health-suggest-actions', 'sn-uptime-status', 'sn-cron-dashboard', 'sn-provenance-admin', 'snt-os-host' ),
	);
}

/**
 * Register every handle in `snt_os_host_asset_handles()` that is not
 * registered yet.
 *
 * Idempotent by `wp_style_is` / `wp_script_is`: on a classic admin page these
 * are already registered by their own enqueues and this adds nothing.
 *
 * @return void
 */
function snt_os_host_register_assets() {
	if ( ! function_exists( 'wp_register_style' ) || ! defined( 'SNT_URL' ) || ! defined( 'SNT_VERSION' ) ) {
		return;
	}
	$plugin_file = defined( 'SNT_PATH' ) ? SNT_PATH . 'signal-and-noise-tools.php' : __FILE__;

	if ( ! wp_style_is( 'sn-admin', 'registered' ) ) {
		wp_register_style( 'sn-admin', SNT_URL . 'assets/admin.css', array(), SNT_VERSION );
	}
	if ( ! wp_style_is( 'snt-analytics-tokens', 'registered' ) ) {
		wp_register_style( 'snt-analytics-tokens', SNT_URL . 'assets/analytics/analytics-tokens.css', array(), SNT_VERSION );
	}
	if ( ! wp_style_is( 'sn-analytics-admin', 'registered' ) ) {
		wp_register_style( 'sn-analytics-admin', SNT_URL . 'assets/analytics/analytics-admin.css', array( 'sn-admin', 'snt-analytics-tokens' ), SNT_VERSION );
	}
	if ( ! wp_style_is( 'sn-uptime-status', 'registered' ) ) {
		wp_register_style( 'sn-uptime-status', SNT_URL . 'assets/uptime-status.css', array(), SNT_VERSION );
	}

	// The two shared utilities below are registered by their OWN registrars on
	// `admin_enqueue_scripts`; the window-args filter runs at `init`, earlier.
	// Calling the registrar (never re-declaring the handle) keeps one source.
	if ( function_exists( 'snt_register_status_script' ) && ! wp_script_is( 'snt-status', 'registered' ) ) {
		snt_register_status_script();
	}
	if ( function_exists( 'snt_ability_run_client_register' ) ) {
		snt_ability_run_client_register();
	}

	if ( ! wp_script_is( 'sn-admin', 'registered' ) ) {
		wp_register_script( 'sn-admin', SNT_URL . 'assets/admin.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'snt-confirm', 'registered' ) ) {
		wp_register_script( 'snt-confirm', SNT_URL . 'assets/snt-confirm.js', array( 'wp-i18n' ), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-analytics-brush', 'registered' ) ) {
		wp_register_script( 'sn-analytics-brush', SNT_URL . 'assets/analytics/analytics-brush.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-resume-admin', 'registered' ) ) {
		wp_register_script( 'sn-resume-admin', SNT_URL . 'assets/resume-admin.js', array(), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'sn-freshness-dot', 'registered' ) ) {
		wp_register_script( 'sn-freshness-dot', plugins_url( 'assets/freshness-dot.js', $plugin_file ), array(), SNT_VERSION, true );
		// The SAME payload snt_freshness_enqueue() attaches, from the SAME
		// builder: a copied route list would go stale the first time the
		// front-end routes moved.
		if ( function_exists( 'snt_freshness_routes' ) && defined( 'SNT_FRESHNESS_CARD_ID' ) ) {
			wp_localize_script(
				'sn-freshness-dot',
				'sntFreshness',
				array(
					'routes' => array_map( static function ( $path ) {
						return home_url( $path );
					}, snt_freshness_routes() ),
					'cardId' => SNT_FRESHNESS_CARD_ID,
				)
			);
		}
	}
	if ( ! wp_script_is( 'snt-health-suggest-actions', 'registered' ) ) {
		wp_register_script( 'snt-health-suggest-actions', plugins_url( 'assets/health-suggest-actions.js', $plugin_file ), array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ), SNT_VERSION, true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'snt-health-suggest-actions', 'signal-and-noise-tools' );
		}
	}
	if ( ! wp_script_is( 'sn-uptime-status', 'registered' ) ) {
		wp_register_script( 'sn-uptime-status', SNT_URL . 'assets/uptime-status.js', array( 'snt-ability-run' ), SNT_VERSION, true );
	}
	if ( ! wp_script_is( 'snt-os-host', 'registered' ) ) {
		wp_register_script( 'snt-os-host', SNT_URL . 'assets/os-host.js', array( 'sn-admin' ), SNT_VERSION, true );
	}
	// Three leaves register their own assets from their own enqueue callbacks
	// (Connections -> Cron, Integrity -> Provenance, Security -> Audit log),
	// gated on the classic hook suffixes the desktop page never carries. Each
	// exposes its registrar; calling it keeps one source of strings and paths.
	foreach ( array( 'snt_cron_dashboard_register_script', 'sn_prov_admin_register_assets', 'snt_audit_log_register_style' ) as $registrar ) {
		if ( function_exists( $registrar ) ) {
			$registrar();
		}
	}
}

/**
 * Ride the host windows with the admin assets their leaves expect.
 *
 * A sibling of `snt_os_app_window_args()` on the same filter rather than a
 * branch inside it: the Signal & Noise app's window has nothing to do with
 * this one, and one function answering for two windows is how a change to
 * either becomes a change to both.
 *
 * @param array<string,mixed> $window_args `openstation_register_window()` args.
 * @param string              $id          App id.
 * @return array<string,mixed>
 */
function snt_os_host_window_args( $window_args, $id ) {
	if ( ! is_array( $window_args ) || ! in_array( (string) $id, array( 'sn-dashboard' ), true ) ) {
		return $window_args;
	}
	snt_os_host_register_assets();
	$handles = snt_os_host_asset_handles();
	foreach ( array( 'styles', 'scripts' ) as $bucket ) {
		$existing = isset( $window_args[ $bucket ] ) ? (array) $window_args[ $bucket ] : array();
		foreach ( $handles[ $bucket ] as $handle ) {
			if ( ! in_array( $handle, $existing, true ) ) {
				$existing[] = $handle;
			}
		}
		$window_args[ $bucket ] = $existing;
	}
	return $window_args;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'openstation_app_window_args', 'snt_os_host_window_args', 10, 2 );
}
