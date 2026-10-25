<?php
/**
 * Signal & Noise Tools: openstation-host.php, the capture, the admin
 * bootstrap, the page rewrite and the form keeper.
 *
 * Split from inc/openstation-host.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
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
 * posts `action=sn_analytics_export`, and `sn_handle_analytics_export()` sends
 * `Content-Disposition`, echoes a raw CSV/JSON body and `exit`s — it never
 * returns a flash code and never renders. Replayed inside a dispatch it would
 * write a spreadsheet into the middle of a JSON response and kill the request.
 * So the host names those actions and this marks their forms; the rewrite pass
 * then leaves each a REAL form with an explicit action and no target.
 * A download must be a navigation; a same-window one downloads in place,
 * where `target="_blank"` relaunched the installed PWA (2026-10-25).
 *
 * TWO PASSES, because the `action` that identifies a form is a hidden input
 * INSIDE it — the marker cannot be decided at the moment the opening tag is
 * read. The first pass counts forms and records which ordinals carry one of
 * the named actions; the second sets the attribute on those ordinals. Closing
 * tags are visited, so an input that sits between two forms belongs to
 * neither.
 *
 * @param string   $html    Captured leaf HTML.
 * @param string[] $actions Handler actions (table keys) whose forms stay real forms.
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
		if ( 'action' !== (string) $scan->get_attribute( 'name' ) ) {
			continue;
		}
		$value = $scan->get_attribute( 'value' );
		if ( is_string( $value ) && in_array( (string) preg_replace( '/^sn_/', '', $value ), $actions, true ) ) {
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

