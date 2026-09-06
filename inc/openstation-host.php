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
 *   3. REPLAY. A handler ends in `header()`/`wp_safe_redirect()` + `exit`,
 *      which a window cannot do, so none of them can be called normally.
 *      Everything BEFORE that exit is reproduced exactly — capability, nonce,
 *      page, the handler table, the flash code, the redirect target — and the
 *      four pipelines that do it live in inc/openstation-host-pipelines.php,
 *      required below. The two pure resolvers
 *      (`sn_admin_post_redirect_target()`, `sn_admin_flash_to_notice()`) are
 *      CALLED, never copied.
 *   4. ASSETS. Every leaf behaviour is a script that self-gates on a DOM
 *      marker, enqueued today on the classic hook suffixes. The window-args
 *      seam registers the same handles, with the same data, from the same
 *      builders; it lives in inc/openstation-host-assets.php.
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

// The WRITE half: the four pipelines a submitted form can belong to, the
// FormData expansion they all start with, and the redirect/die interceptor two
// of them need. Required HERE rather than from the plugin's manifest so every
// entry point that has the paint also has the write — the app file, the suites
// and a standalone host all require only this file.
require_once __DIR__ . '/openstation-host-pipelines.php';
// The ASSET seam: which handles a host window carries, and their
// registration from the same builders their own pages use.
require_once __DIR__ . '/openstation-host-assets.php';
// v13.106.0: the kit vocabulary the native windows paint their bodies from.
require_once __DIR__ . '/openstation-kit.php';
require_once __DIR__ . '/openstation-kit-display.php';
require_once __DIR__ . '/openstation-kit-data.php';
require_once __DIR__ . '/openstation-kit-forms.php';
require_once __DIR__ . '/openstation-kit-triggers.php';

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
 * The admin-library bootstrap runs BEFORE the swap for the same reason and it
 * is not a formality — loading `wp-admin/includes/admin.php` fires the `locale`
 * and `override_load_textdomain` filters, where third-party code runs and can
 * throw, and there is no `finally` covering a throw from ABOVE the swap only
 * because there is nothing yet to put back.
 *
 * @param callable            $paint Echoes the leaf. Called with no arguments.
 * @param array<string,mixed> $get   The `$_GET` the leaf should see (include `page`).
 * @param array<string,mixed> $post  The `$_POST` the leaf should see.
 * @return string The HTML the callable echoed.
 * @throws \Throwable Whatever the callable threw, after the buffer is discarded.
 */
function snt_os_host_capture( callable $paint, array $get = array(), array $post = array() ) {
	snt_os_host_admin_bootstrap();

	// phpcs:disable WordPress.Security.NonceVerification -- Reading the CURRENT superglobals only to restore them byte-for-byte; nothing here is input.
	$prev_get     = $_GET;
	$prev_post    = $_POST;
	$prev_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification
	$_GET     = $get;
	$_POST    = $post;
	$_REQUEST = array_merge( $get, $post );

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
 * @param string[] $own  `page=` slugs this window paints itself; empty derives them.
 * @return string
 */
function snt_os_host_rewrite( $html, array $own = array() ) {
	$html = (string) $html;
	if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	if ( array() === $own ) {
		$own = snt_os_host_own_pages();
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
 * The `page=` slugs that render THIS tabbed admin page.
 *
 * DERIVED, never listed. The estate registers the same page under eight
 * top-tab slugs (`sn_admin_top_tabs()`) plus eleven legacy ones
 * (`sn_admin_pages()`), and leaves link to each other through them —
 * `sn_admin_tag_page_url()` returns `admin.php?page=sn-content&…`. A
 * one-element literal made every such link a `door`: a second admin window
 * opening on the classic page while the host window sat where it was.
 *
 * Filtered through `sn_admin_post_allowed_pages()`, the allowlist the POST
 * dispatcher already keeps, so a slug this window would refuse to save on can
 * never become a `go` that paints as if it could. That filter is also why
 * `sn-analytics` is NOT here: the allowlist carries it (it has its own POST
 * form), but it is a DIFFERENT window's surface and neither registry claims it,
 * so it stays a door — measured, because `sn_admin_page_tab_for_slug()`
 * answers 'dashboard' for it, and a `go` would land the reader on the
 * Dashboard while the link said Analytics.
 *
 * @return string[]
 */
function snt_os_host_own_pages() {
	$slugs = array();
	if ( function_exists( 'sn_admin_top_tabs' ) ) {
		$slugs = array_merge( $slugs, array_column( sn_admin_top_tabs(), 'slug' ) );
	}
	if ( function_exists( 'sn_admin_pages' ) ) {
		$slugs = array_merge( $slugs, array_column( sn_admin_pages(), 'slug' ) );
	}
	$slugs = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $slugs ),
				static function ( $slug ) {
					return '' !== $slug;
				}
			)
		)
	);
	if ( function_exists( 'sn_admin_post_allowed_pages' ) ) {
		$slugs = array_values( array_intersect( $slugs, sn_admin_post_allowed_pages() ) );
	}
	// A standalone host has neither registry; the canonical slug is the one
	// thing that is true without them.
	return array() !== $slugs ? $slugs : array( 'sn-theme-options' );
}

/**
 * Mark the forms a host must NOT turn into a dispatch, with where they post.
 *
 * THE ONE SHAPE A WINDOW CANNOT REPLAY IS A DOWNLOAD. Analytics' export form
 * posts `sn_action=analytics_export`, and `sn_handle_analytics_export()` sends
 * `Content-Disposition`, echoes a raw CSV/JSON body and `exit`s — it never
 * returns a flash code and never renders. Replayed inside a dispatch it would
 * write a spreadsheet into the middle of a JSON response and kill the request.
 * So the host names those actions and this marks their forms; the rewrite pass
 * then leaves each a REAL form with an explicit action and `target="_blank"`.
 * A download must be a navigation; a new tab is the least a window can do.
 *
 * TWO PASSES, because the `sn_action` that identifies a form is a hidden input
 * INSIDE it — the marker cannot be decided at the moment the opening tag is
 * read. The first pass counts forms and records which ordinals carry one of
 * the named actions; the second sets the attribute on those ordinals. Closing
 * tags are visited, so an input that sits between two forms belongs to
 * neither.
 *
 * @param string   $html    Captured leaf HTML.
 * @param string[] $actions `sn_action` values whose forms stay real forms.
 * @param string   $url     Where such a form posts (an absolute admin URL).
 * @return string
 */
function snt_os_host_keep_forms( $html, array $actions, $url ) {
	$html = (string) $html;
	$url  = (string) $url;
	if ( '' === $html || '' === $url || array() === $actions || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$keep   = array();
	$index  = -1;
	$inside = false;
	$scan   = new WP_HTML_Tag_Processor( $html );
	while ( $scan->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag = (string) $scan->get_tag();
		if ( 'FORM' === $tag ) {
			if ( $scan->is_tag_closer() ) {
				$inside = false;
				continue;
			}
			++$index;
			$inside = true;
			continue;
		}
		if ( ! $inside || 'INPUT' !== $tag || $scan->is_tag_closer() ) {
			continue;
		}
		if ( 'sn_action' !== (string) $scan->get_attribute( 'name' ) ) {
			continue;
		}
		$value = $scan->get_attribute( 'value' );
		if ( is_string( $value ) && in_array( $value, $actions, true ) ) {
			$keep[ $index ] = true;
		}
	}
	if ( array() === $keep ) {
		return $html;
	}

	$index = -1;
	$mark  = new WP_HTML_Tag_Processor( $html );
	while ( $mark->next_tag( 'FORM' ) ) {
		++$index;
		if ( isset( $keep[ $index ] ) ) {
			$mark->set_attribute( 'data-snt-keep-form', $url );
		}
	}
	return (string) $mark->get_updated_html();
}

/**
 * A `<form>`: POST saves through `post`, GET forms navigate through `go`.
 *
 * `method` is KEPT on a POST form (the classic markup's own declaration, and
 * what the runtime keys its `submit` listener on); `action` is dropped, since
 * the destination is now an action name, not a URL — but it is READ first.
 * Five forms in Integrity → Provenance post to `admin-post.php` with their own
 * `action` field and their own nonce, and dropping that attribute unread is
 * what routed them into a pipeline that could only refuse them. The reading
 * rides back to the server as `os-arg-pipeline`, which the runtime hands the
 * action alongside the form's values.
 *
 * A form the host marked `data-snt-keep-form` is the exception: it keeps its
 * method, GAINS the marked URL as its action and opens in a new tab. See
 * `snt_os_host_keep_forms()`.
 *
 * @param WP_HTML_Tag_Processor $tags Positioned on the tag.
 * @return void
 */
function snt_os_host_rewrite_form( $tags ) {
	if ( null !== $tags->get_attribute( 'os-action' ) ) {
		return;
	}
	$keep = $tags->get_attribute( 'data-snt-keep-form' );
	if ( is_string( $keep ) && '' !== $keep ) {
		// Set only when it differs, so a second pass over already-rewritten
		// markup is byte-identical.
		if ( $keep !== $tags->get_attribute( 'action' ) ) {
			$tags->set_attribute( 'action', $keep );
		}
		if ( null === $tags->get_attribute( 'target' ) ) {
			$tags->set_attribute( 'target', '_blank' );
		}
		return;
	}
	$method = strtolower( trim( (string) $tags->get_attribute( 'method' ) ) );
	if ( 'post' === $method ) {
		$action   = $tags->get_attribute( 'action' );
		$pipeline = snt_os_host_form_pipeline( is_string( $action ) ? $action : '' );
		$tags->set_attribute( 'os-action', 'post' );
		if ( '' !== $pipeline ) {
			$tags->set_attribute( 'os-arg-pipeline', $pipeline );
		}
		$tags->remove_attribute( 'action' );
		return;
	}
	$tags->set_attribute( 'os-action', 'go' );
}

/**
 * Which write pipeline a form's `action` attribute names, if any.
 *
 * Only one shape is nameable from the markup: a post to `admin-post.php`,
 * which is a different dispatcher with a different nonce. Everything else —
 * no action, the page's own URL — is the page's own pipeline, and the
 * submitted fields say which.
 *
 * @param string $action The form's `action` attribute.
 * @return string 'admin-post' or ''.
 */
function snt_os_host_form_pipeline( $action ) {
	$absolute = snt_os_host_absolute_url( $action );
	if ( '' === $absolute || ! snt_os_host_is_admin_url( $absolute ) ) {
		return '';
	}
	$path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
	return 'admin-post.php' === basename( $path ) ? 'admin-post' : '';
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
			$tags->set_attribute( 'rel', snt_os_host_rel( $tags->get_attribute( 'rel' ) ) );
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
	$sub = isset( $query['sub'] ) && is_string( $query['sub'] ) ? $query['sub'] : '';
	// The element id, verbatim: the client looks the value up as an id, and the
	// estate's fragments are not all `sn-sec-*` (the Dashboard's attention strip
	// links `#sn-dash-diagnostics`). Stripping a prefix here is how one of them
	// landed nowhere.
	$anchor = (string) wp_parse_url( $absolute, PHP_URL_FRAGMENT );
	// A legacy `page=`/`tab=` pair resolves through the SAME map the 301 uses,
	// so a link into our own page lands where a bookmark of it would.
	if ( function_exists( 'sn_admin_canonical_destination' ) ) {
		$destination = sn_admin_canonical_destination( $tab, $sub );
		if ( is_array( $destination ) ) {
			$tab = (string) ( $destination['tab'] ?? $tab );
			$sub = (string) ( $destination['sub'] ?? '' );
			if ( '' === $anchor && ! empty( $destination['anchor'] ) ) {
				$anchor = 'sn-sec-' . (string) $destination['anchor'];
			}
		}
	}

	$tags->remove_attribute( 'href' );
	$tags->set_attribute( 'os-action', 'go' );
	if ( '' !== $tab ) {
		$tags->set_attribute( 'os-arg-tab', $tab );
	}
	if ( '' !== $sub ) {
		$tags->set_attribute( 'os-arg-sub', $sub );
	}
	if ( '' !== $anchor ) {
		$tags->set_attribute( 'os-arg-anchor', $anchor );
	}
	foreach ( snt_os_host_params( $query ) as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$tags->set_attribute( 'os-arg-' . $key, (string) $value );
		}
	}
}

/**
 * `noopener noreferrer` MERGED into whatever rel the leaf already chose.
 *
 * Replacing it was a real loss, not a cosmetic one: Integrity → Citations
 * prints each inbound row as `rel="noopener nofollow ugc"` around a URL a
 * webmention SENDER supplied, and overwriting that attribute stripped the
 * untrusted-link profile the leaf deliberately set.
 *
 * @param string|null|bool $existing The anchor's current rel.
 * @return string
 */
function snt_os_host_rel( $existing ) {
	$tokens = is_string( $existing ) ? preg_split( '/\s+/', trim( $existing ), -1, PREG_SPLIT_NO_EMPTY ) : array();
	$tokens = is_array( $tokens ) ? $tokens : array();
	$have   = array_map( 'strtolower', $tokens );
	foreach ( array( 'noopener', 'noreferrer' ) as $needed ) {
		if ( ! in_array( $needed, $have, true ) ) {
			$tokens[] = $needed;
		}
	}
	return implode( ' ', $tokens );
}

/**
 * The `sn_*` query params a window carries, filtered and bounded.
 *
 * These ARE state on the classic page — the Tags leaf's merge preview is
 * three of them — so they have to survive a paint. They also ride every
 * dispatch, hence the caps.
 *
 * `_wpnonce` rides too, and only because one own-page GET link is a nonce-gated
 * ACTION rather than navigation: `sn_worker_version_recheck_url()` builds
 * `?sn_worker_recheck=1` and appends the nonce, and
 * `sn_worker_version_recheck_requested()` requires both. Dropping it made
 * "Re-check now" a silent no-op that repainted the same stale version. It is
 * safe to carry because it is only ever a value the window LENDS back as
 * `$_GET`; every write still verifies its own.
 *
 * @param array<string,mixed> $source A `$_GET`-shaped array.
 * @return array<string,string|string[]>
 */
function snt_os_host_params( array $source ) {
	$out = array();
	foreach ( $source as $key => $value ) {
		if ( ! is_string( $key ) || ( '_wpnonce' !== $key && ! preg_match( '/^sn_[a-z0-9_]{1,40}$/', $key ) ) ) {
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
