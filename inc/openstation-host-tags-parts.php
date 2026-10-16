<?php
/**
 * Signal & Noise Tools: openstation-host.php, the form, submitter and link
 * tag rewriters.
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

/**
 * Drop an anchor's `href` AND restore what the browser gave it for free.
 *
 * The href has to go: the runtime does not preventDefault a click, so a
 * surviving href navigates the whole desktop rather than the window. But an
 * `<a>` with no href is not focusable and exposes no role, so every rewritten
 * link became mouse-only -- measured on the running product 2026-09-10, seven
 * S&N Analytics cross-view links ("Content ->", "Sessions ->", ...) reachable
 * by pointer and by nothing else.
 *
 * The two are done together, here, because they were separate before: the
 * removal is required and the compensation is easy to forget beside it.
 *
 * @param WP_HTML_Tag_Processor $tags Positioned on the anchor.
 * @return void
 */
function snt_os_host_unhref( $tags ) {
	$tags->remove_attribute( 'href' );
	$tags->set_attribute( 'tabindex', '0' );
	$tags->set_attribute( 'role', 'link' );
}

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
		// 14.7.5: a same-origin front-end link (a note, /verify, a policy
		// file) is a door too, even when the leaf authored `target="_blank"`
		// for the classic page: in the PWA that attribute relaunches the app.
		if ( '' !== $absolute && snt_os_host_is_same_origin_url( $absolute ) && ! snt_os_host_is_download_url( $absolute ) ) {
			snt_os_host_unhref( $tags );
			$tags->remove_attribute( 'target' );
			$tags->set_attribute( 'os-action', 'door' );
			$tags->set_attribute( 'os-arg-url', $absolute );
			return;
		}
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
		snt_os_host_unhref( $tags );
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

	snt_os_host_unhref( $tags );
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

