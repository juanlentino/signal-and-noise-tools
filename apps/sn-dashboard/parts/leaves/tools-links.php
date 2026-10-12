<?php
/**
 * S&N Dashboard — Tools → Links, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/links.php, `sn_admin_render_links_section()`)
 * paints nothing but a static grid of external shortcuts: three groups (Source
 * code, Releases, Infrastructure) of two links each, each link shown with its
 * group label, its title as the link, and its host (derived per-link via
 * `wp_parse_url( $href, PHP_URL_HOST )`, purely for display). No DB/option reads, no `$_GET`, no forms, no nonces: the simplest
 * leaf in the tab. Same three groups, same six links, same hosts, one grid;
 * the kit's parts instead of wp-admin's `.sn-link-grid` / `.sn-link-card`.
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
				array( 'title' => 'Theme releases', 'href' => 'https://github.com/juanlentino/signal-and-noise/tags' ),
				array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/tags' ),
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
 * One link card: the group as its eyebrow, the title AS the link, and the
 * host it goes to. Each said once (2026-10-12): the card printed its title
 * twice (a heading, then the same words as the link) under a group label that
 * repeated the section heading above it.
 *
 * @param string              $group_label The group this link belongs to.
 * @param array<string,mixed> $link        title, href.
 * @return string
 */
function links_card_html( $group_label, array $link ) {
	$title = (string) ( $link['title'] ?? '' );
	$href  = (string) ( $link['href'] ?? '' );
	$host  = (string) \wp_parse_url( $href, PHP_URL_HOST );
	// The ↗ never wraps onto a line of its own: it rides a nowrap span with
	// the host's last word.
	$cut  = strrpos( $host, ' ' );
	$tail = false === $cut ? $host : substr( $host, $cut + 1 );
	$host = ( false === $cut ? '' : \snt_kit_esc( substr( $host, 0, $cut + 1 ) ) )
		. '<span class="snt-links-nowrap">' . \snt_kit_esc( $tail ) . ' <span aria-hidden="true">↗</span></span>';
	// os-card lays its header out as a flex row (an icon, then a title
	// block), so the three lines go in ONE title block and stack: group,
	// title, host. As three row items the titles wrapped and the ↗ dropped
	// to its own line (live 2026-10-12, Infrastructure cards).
	return \snt_kit_tag(
		'os-card',
		array(),
		\snt_kit_tag(
			'header',
			array(),
			\snt_kit_tag(
				'div',
				array( 'class' => 'snt-links-card' ),
				\snt_kit_tag( 'p', array( 'class' => 'snt-hint' ), \snt_kit_esc( $group_label ) )
				. \snt_kit_tag( 'h3', array(), \snt_kit_link( $title, \esc_url( $href ) ) )
				. \snt_kit_tag( 'p', array( 'class' => 'snt-hint' ), $host )
			)
		)
	);
}

/**
 * The leaf: one section, all six cards in one grid. At a 1,300px window a
 * 360px minimum gives three columns of about 400px, which clears the 390px
 * measured (2026-09-10) as the width every URL reads in full; a narrower
 * window drops to two columns or one on its own. It was three stacked
 * sections of two cards each, every one the same height at full width.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_tools_links( array $ctx ) {
	unset( $ctx );
	$cards = array();
	foreach ( links_groups() as $group ) {
		foreach ( (array) ( $group['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) ) {
				$cards[] = links_card_html( (string) ( $group['label'] ?? '' ), $link );
			}
		}
	}
	return \snt_kit_section( __( 'External shortcuts', 'signal-and-noise-tools' ), \snt_kit_grid( $cards, 360, 12 ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/links'] = __NAMESPACE__ . '\\paint_tools_links';
		return $painters;
	}
);
