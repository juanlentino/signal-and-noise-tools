<?php
/**
 * S&N Dashboard — Tools → Links, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/links.php, `sn_admin_render_links_section()`)
 * paints nothing but a static grid of external shortcuts: three groups (Source
 * code, Releases, Infrastructure) of two links each, each link shown with its
 * group label, its title, its host (derived per-link via
 * `wp_parse_url( $href, PHP_URL_HOST )`, purely for display) and the link
 * itself. No DB/option reads, no `$_GET`, no forms, no nonces — the simplest
 * leaf in the tab. Same three groups, same six links, same hosts; the kit's
 * parts instead of wp-admin's `.sn-link-grid` / `.sn-link-card`.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The three link groups, the same array the classic renderer hardcodes
 * (inc/admin-forms/links.php:20-42), line for line.
 *
 * @return array<int,array{label:string,links:array<int,array{title:string,href:string}>}>
 */
function links_groups() {
	$groups = array(
		array(
			'label' => 'Source code',
			'links' => array(
				array( 'title' => 'Theme repo', 'href' => 'https://github.com/juanlentino/signal-and-noise' ),
				array( 'title' => 'Plugin repo', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools' ),
			),
		),
		array(
			'label' => 'Releases',
			'links' => array(
				array( 'title' => 'Theme releases', 'href' => 'https://github.com/juanlentino/signal-and-noise/releases' ),
				array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/releases' ),
			),
		),
		array(
			'label' => 'Infrastructure',
			'links' => array(
				array( 'title' => 'Cloudflare dashboard', 'href' => 'https://dash.cloudflare.com' ),
				array( 'title' => 'Cloudways platform', 'href' => 'https://platform.cloudways.com' ),
			),
		),
	);
	return $groups;
}

/**
 * One link card: title, host (derived exactly as the classic loop does), and
 * the external link itself, as an `<os-card>` (kit-help: Card — header /
 * default / footer slots).
 *
 * @param string              $group_label The group this link belongs to.
 * @param array<string,mixed> $link        title, href.
 * @return string
 */
function links_card_html( $group_label, array $link ) {
	$title = (string) ( $link['title'] ?? '' );
	$href  = (string) ( $link['href'] ?? '' );
	$host  = (string) \wp_parse_url( $href, PHP_URL_HOST );
	$header = \snt_kit_tag(
		'header',
		array(),
		\snt_kit_tag( 'span', array( 'class' => 'snt-hint' ), \snt_kit_esc( $group_label ) )
		. \snt_kit_tag( 'h3', array(), \snt_kit_esc( $title ) )
		. \snt_kit_tag( 'p', array( 'class' => 'snt-hint' ), \snt_kit_esc( $host ) . ' ↗' )
	);
	$footer = \snt_kit_tag( 'footer', array(), \snt_kit_link( $title, \esc_url( $href ) ) );
	return \snt_kit_tag( 'os-card', array(), $header . $footer );
}

/**
 * One group: heading + its cards, as a kit section.
 *
 * @param array{label:string,links:array<int,array<string,mixed>>} $group One group.
 * @return string
 */
function links_group_html( array $group ) {
	$label = (string) ( $group['label'] ?? '' );
	$cards = '';
	foreach ( (array) ( $group['links'] ?? array() ) as $link ) {
		if ( is_array( $link ) ) {
			$cards .= links_card_html( $label, $link );
		}
	}
	return \snt_kit_section( $label, \snt_kit_tag( 'os-grid', array( 'columns' => '2', 'gap' => '12' ), $cards ) );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_links( array $ctx ) {
	unset( $ctx );
	$out = '';
	foreach ( links_groups() as $group ) {
		if ( is_array( $group ) ) {
			$out .= links_group_html( $group );
		}
	}
	// NOT paired, and the reason is mechanical rather than semantic. These three
	// groups ARE siblings, and v13.109.12 wrapped them in `.snt-systems` on that
	// reading. But each group already lays out its own cards HORIZONTALLY, so a
	// horizontal wrapper divides an already-divided width: measured live
	// 2026-09-10 the groups fell to 265px each (and, with the leaf released,
	// 575px), which left every card at ~230px and truncated every URL --
	// `dash.cloudfl...`, `platform.cloudways...`. Stacked, each group takes the
	// full 820px, its two cards sit at ~390px, and the URLs read in full.
	//
	// The rule the rest of this pass follows -- siblings take a grid -- assumes
	// the siblings are single-column. A container that already uses the width is
	// not a candidate.
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/links'] = __NAMESPACE__ . '\\paint_tools_links';
		return $painters;
	}
);
