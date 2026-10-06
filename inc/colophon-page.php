<?php
/**
 * Signal & Noise — the colophon shortcode ([sn_colophon]).
 *
 * Moves the colophon's content out of the theme template and into the CMS
 * (owner decision 2026-07-30): the page body carries [sn_colophon], the
 * theme stays frozen, and future edits are plugin releases or page edits.
 * Content mirrors the previously published colophon verbatim — including
 * the hosting and AI-assistance lines, which are ALREADY the owner's public
 * copy (they are deliberate here; the maturity-family leak contract does
 * not apply to the colophon's own published facts) — plus one new line
 * closing the loop to /maturity/ (resolved from the page per the
 * never-hardcode-paths rule).
 *
 * The version footer reads live values (wp_get_theme + SNT_VERSION), both
 * already public on the old colophon. Returns, never echoes; everything
 * escaped at build; no stylesheet — semantic markup inherits the theme's
 * typography exactly as the template-rendered version did.
 *
 * Since the Automattic dev-diary link (2026-08-15) this page is also an
 * arrival point for developers expecting the plugin, so the spec sheet now
 * resolves its own references: the Tooling bullet links the plugin repo, an
 * Interop bullet links OpenStation, and the version numbers link each
 * package's changelog (the GitHub releases pages are deliberately draft-only,
 * so the CHANGELOG blobs are the public record). External URLs route through
 * the `sn_colophon_urls` filter seam. A closing line linking /notes shipped
 * in 11.10.0 and was dropped in 11.10.1: /notes is the provenance research,
 * not build rationale — the maturity index (Trust bullet) already carries
 * the "why".
 *
 * 2026-10-06: rewritten for a reader who is not a developer. Ten rows in
 * three groups (Made with, On the page, Kept honest), each the fact plus one
 * clause on what it means; H2 group headings under the page's H1; labels
 * end in a colon. The rows are also the single source for the theme's
 * humans.txt (sn_colophon_plain_facts()). The version line is unchanged.
 *
 * @package SignalNoiseTools @since 10.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The colophon rows: slug => [label, plain text]. One source for every place
 * that states these facts: the page renders them with links on the phrases
 * in sn_colophon_links(), and humans.txt (theme) reads them as plain text
 * through sn_colophon_plain_facts(). Filterable (`sn_colophon_items`).
 *
 * 2026-10-06 rewrite for a reader who is not a developer: every row is the
 * fact, then one clause on what it means. Records states only what the
 * provenance code does (inc/provenance-core.php: SHA-256 over the normalized
 * text, a new version with its parent on each edit; inc/provenance-webhook.php:
 * the Worker signs with the site's Ed25519 key and anchors in Bitcoin through
 * OpenTimestamps; inc/provenance-verify.php: the /verify route).
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_colophon_items() {
	$items = array(
		'platform'   => array( __( 'Platform', 'signal-and-noise-tools' ), __( 'WordPress with Full Site Editing, so every page, header and footer is assembled from WordPress\'s own blocks, with no page builder on top.', 'signal-and-noise-tools' ) ),
		'code'       => array( __( 'Code', 'signal-and-noise-tools' ), __( 'hand-written PHP for the server, plain JavaScript for the browser, and a theme.json file of design settings, with no build step, so the code in the public repositories is exactly the code that runs.', 'signal-and-noise-tools' ) ),
		'hosting'    => array( __( 'Hosting', 'signal-and-noise-tools' ), __( 'Cloudways runs the server the site lives on, and Cloudflare keeps copies of each page close to readers and directs the domain name to the server, so pages load quickly wherever you are.', 'signal-and-noise-tools' ) ),
		'plugin'     => array( __( 'Companion plugin', 'signal-and-noise-tools' ), __( 'Signal & Noise Tools, which I also wrote, adds what the theme leaves out, such as search and social previews, cookie-free visitor counts, the signed records described below, and checks for broken links and missing image descriptions.', 'signal-and-noise-tools' ) ),
		'type'       => array( __( 'Type', 'signal-and-noise-tools' ), __( 'Bebas Neue for headings, buttons and navigation, and DM Mono for body text and captions, both served from this site rather than a font service, so no font company sees your visit.', 'signal-and-noise-tools' ) ),
		'appearance' => array( __( 'Appearance', 'signal-and-noise-tools' ), __( 'light by default, with a dark version that follows your device\'s setting; the toggle overrides that and remembers your choice on this device.', 'signal-and-noise-tools' ) ),
		'records'    => array( __( 'Records', 'signal-and-noise-tools' ), __( 'each note I publish gets a fingerprint of its text (SHA-256), a digital signature from the site\'s key (Ed25519) and a timestamp written into Bitcoin (OpenTimestamps), and an edit to the text adds a new signed version while keeping the earlier ones, so anyone can check a note\'s text, source and date at Verify a Note.', 'signal-and-noise-tools' ) ),
		'systems'    => array( __( 'Systems', 'signal-and-noise-tools' ), __( 'every system documented at the maturity index, where each one has a page stating what it does and what it will never do.', 'signal-and-noise-tools' ) ),
		'ai'         => array( __( 'AI', 'signal-and-noise-tools' ), __( 'engineered with Claude (Anthropic) as a pair programmer, an AI that writes code with me; the notes themselves stay mine.', 'signal-and-noise-tools' ) ),
		'interop'    => array( __( 'Interop', 'signal-and-noise-tools' ), __( 'the site\'s admin dashboard runs inside OpenStation, a free WordPress plugin that turns it into a desktop with windows and a dock; readers of the public site never see it.', 'signal-and-noise-tools' ) ),
	);
	return apply_filters( 'sn_colophon_items', $items );
}

/**
 * The three groups, in order: slug => [heading, row slugs]. A row in no
 * group (added through the items seam) renders after the last group.
 *
 * @return array<string,array{0:string,1:string[]}>
 */
function sn_colophon_groups() {
	return array(
		'made'   => array( __( 'Made with', 'signal-and-noise-tools' ), array( 'platform', 'code', 'hosting', 'plugin' ) ),
		'page'   => array( __( 'On the page', 'signal-and-noise-tools' ), array( 'type', 'appearance' ) ),
		'honest' => array( __( 'Kept honest', 'signal-and-noise-tools' ), array( 'records', 'systems', 'ai', 'interop' ) ),
	);
}

/**
 * The colophon's facts as plain text, label => text, for text/plain readers
 * (the theme's humans.txt). Same words as the page, no markup.
 *
 * @return array<string,string>
 */
function sn_colophon_plain_facts() {
	$out = array();
	foreach ( sn_colophon_items() as $slug => $item ) {
		$out[ (string) ( $item[0] ?? $slug ) ] = sn_colophon_strip( (string) ( $item[1] ?? '' ) );
	}
	return $out;
}

/**
 * strip_tags, through WordPress's own when it is loaded.
 *
 * @param string $s Text.
 * @return string
 */
function sn_colophon_strip( $s ) {
	return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
}

/**
 * The colophon's external URLs: slug → URL. Filterable (`sn_colophon_urls`),
 * mirroring the items seam, so no destination is inlined at the point of
 * render and any of them can be retargeted without a code edit. A URL
 * filtered to '' degrades that reference to plain text, never a dead link.
 *
 * @return array<string,string>
 */
function sn_colophon_urls() {
	$urls = array(
		'plugin_repo'      => 'https://github.com/juanlentino/signal-and-noise-tools',
		'plugin_changelog' => 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/CHANGELOG.md',
		'theme_changelog'  => 'https://github.com/juanlentino/signal-and-noise/blob/main/CHANGELOG.md',
		'openstation'      => 'https://openstation.me/',
		'theme_repo'       => 'https://github.com/juanlentino/signal-and-noise',
	);
	return apply_filters( 'sn_colophon_urls', $urls );
}

/**
 * A version number as an external changelog link — or plain text when the
 * URL has been filtered away, keeping the stamp's output identical minus
 * the anchor.
 *
 * @param string $text Escapable label, e.g. 'v11.9.0'.
 * @param string $url  Changelog URL ('' → unlinked text).
 * @return string
 */
function sn_colophon_version_link( $text, $url ) {
	if ( '' === $url ) {
		return esc_html( $text );
	}
	return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
}

/**
 * Resolve the maturity index URL from the page itself ('' when absent).
 *
 * @return string
 */
function sn_colophon_maturity_url() {
	if ( function_exists( 'sn_maturity_index_resolve_url' ) ) {
		return sn_maturity_index_resolve_url( 'maturity' );
	}
	return '';
}

/**
 * Which phrase in each row is a link: slug => [phrase, url, external, hidden
 * suffix]. A row whose url is '' renders as plain text, never a dead link.
 *
 * @return array<string,array{0:string,1:string,2:bool,3:string}>
 */
function sn_colophon_links() {
	$urls = sn_colophon_urls();
	// /workflow is how the AI credit works in practice; linked only while it is
	// published (the module withdraws it to draft when nothing public is left).
	// By full path: a child page elsewhere with the same slug is not /workflow.
	$wf_page      = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'workflow' ) : null;
	$workflow_url = $wf_page && 'publish' === ( $wf_page->post_status ?? '' ) ? (string) get_permalink( $wf_page ) : '';
	return array(
		'plugin'  => array( 'Signal & Noise Tools', $urls['plugin_repo'], true, '' ),
		'records' => array( 'Verify a Note', function_exists( 'home_url' ) ? home_url( '/verify' ) : '', false, '' ),
		'systems' => array( 'maturity index', sn_colophon_maturity_url(), false, '' ),
		// Screen-reader-only context for link lists. A suffix, never an aria-label:
		// the accessible name must start with the visible words (WCAG 2.5.3).
		'ai'      => array( 'pair programmer', $workflow_url, false, __( ': how I work with AI', 'signal-and-noise-tools' ) ),
		'interop' => array( 'OpenStation', $urls['openstation'], true, '' ),
	);
}

/**
 * Said to a screen reader, not shown: a link that opens a new tab says so.
 * Hidden text after the visible words, never an aria-label (WCAG 2.5.3).
 *
 * @return string
 */
function sn_colophon_new_tab_note() {
	return '<span class="screen-reader-text">' . esc_html__( ' (opens in a new tab)', 'signal-and-noise-tools' ) . '</span>';
}

/**
 * One row's text, escaped, with its phrase linked when it has a URL.
 *
 * @param string $text Plain text.
 * @param array  $link [phrase, url, external, hidden suffix], or empty.
 * @return string
 */
function sn_colophon_row_html( $text, $link ) {
	$safe = esc_html( $text );
	if ( empty( $link ) || '' === (string) $link[1] ) {
		return $safe;
	}
	$phrase = esc_html( (string) $link[0] );
	$at     = strpos( $safe, $phrase );
	if ( false === $at ) {
		return $safe; // A filtered row without the phrase stays plain text.
	}
	$a = '<a href="' . esc_url( (string) $link[1] ) . '"' . ( ! empty( $link[2] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>'
		. $phrase . ( '' !== (string) $link[3] ? '<span class="screen-reader-text">' . esc_html( (string) $link[3] ) . '</span>' : '' )
		. ( ! empty( $link[2] ) ? sn_colophon_new_tab_note() : '' ) . '</a>';
	return substr( $safe, 0, $at ) . $a . substr( $safe, $at + strlen( $phrase ) );
}

/**
 * [sn_colophon], the how-this-is-built page body. Returns, never echoes.
 *
 * @param array|string $atts Unused; present for the shortcode signature.
 * @return string
 */
function sn_colophon_shortcode( $atts = array() ) {
	$urls  = sn_colophon_urls();
	$items = sn_colophon_items();
	$links = sn_colophon_links();
	$repo  = '' !== $urls['theme_repo']
		? '<a href="' . esc_url( $urls['theme_repo'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'public on GitHub', 'signal-and-noise-tools' ) . sn_colophon_new_tab_note() . '</a>'
		: esc_html__( 'public on GitHub', 'signal-and-noise-tools' );
	$out = '<div class="sn-colophon">'
		. '<p>' . esc_html__( 'This site runs on a theme and a plugin I wrote, and I am the only person who maintains it. Both are', 'signal-and-noise-tools' ) . ' ' . $repo . esc_html__( ', so anyone can read how a page here is made.', 'signal-and-noise-tools' ) . '</p>';

	$row = static function ( $slug ) use ( $items, $links ) {
		$item = $items[ $slug ];
		return '<li class="sn-colophon-item--' . esc_attr( $slug ) . '"><strong>' . esc_html( (string) ( $item[0] ?? $slug ) ) . ':</strong> '
			. sn_colophon_row_html( (string) ( $item[1] ?? '' ), $links[ $slug ] ?? array() ) . '</li>';
	};
	$placed = array();
	foreach ( sn_colophon_groups() as $gslug => $group ) {
		$rows = '';
		foreach ( $group[1] as $slug ) {
			if ( isset( $items[ $slug ] ) ) {
				$rows    .= $row( $slug );
				$placed[] = $slug;
			}
		}
		if ( '' === $rows ) {
			continue;
		}
		// The theme's H2 is 6rem, larger than this page's title: the group
		// headings take the site's section-heading scale (as on /resume).
		$out .= '<h2 id="sn-colophon-' . esc_attr( $gslug ) . '" style="font-size:clamp(2rem, 5vw, 3.5rem);line-height:1.05">' . esc_html( $group[0] ) . '</h2>'
			. '<ul class="sn-colophon-items">' . $rows . '</ul>';
	}
	// A row added through the items seam that no group names: after the last group.
	$extra = '';
	foreach ( array_keys( $items ) as $slug ) {
		if ( ! in_array( $slug, $placed, true ) ) {
			$extra .= $row( $slug );
		}
	}
	if ( '' !== $extra ) {
		$out .= '<ul class="sn-colophon-items">' . $extra . '</ul>';
	}

	// Live versions, unchanged: each version number links its package's
	// changelog; the text is identical when a URL is filtered away.
	$theme_version  = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
	$plugin_version = defined( 'SNT_VERSION' ) ? (string) SNT_VERSION : '';
	$stamp          = array();
	if ( '' !== $theme_version ) {
		$stamp[] = esc_html( 'Theme' ) . ' ' . sn_colophon_version_link( 'v' . $theme_version, $urls['theme_changelog'] );
	}
	if ( '' !== $plugin_version ) {
		$stamp[] = esc_html( 'plugin' ) . ' ' . sn_colophon_version_link( 'v' . $plugin_version, $urls['plugin_changelog'] );
	}
	if ( array() !== $stamp ) {
		$out .= '<p class="sn-colophon-versions">' . implode( ' · ', $stamp ) . '</p>';
	}

	return $out . '</div>';
}
add_shortcode( 'sn_colophon', 'sn_colophon_shortcode' );
