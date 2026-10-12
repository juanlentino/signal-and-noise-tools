<?php
/**
 * S&N Dashboard — S&N Home landing surface, painted in Station Home's idiom.
 *
 * Implements the executive site-level home surface:
 * 1. Stable left rail cross-app launcher (Analytics, S&N, Posts, Pages, Media, Classic).
 * 2. Time-aware greeting and the line under it: how many things need
 *    attention (dashboard-attention.php).
 * 3. Site pulse: the audience tiles and detail (dashboard-pulse.php).
 * 4. Needs attention beside Continue working (dashboard-attention.php,
 *    dashboard-work.php).
 * 5. Operations: the systems and the overrides (dashboard-operations.php).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/dashboard-operations.php'; // Operations: the named systems and the overrides (2026-10-11).
require_once __DIR__ . '/dashboard-pulse.php'; // Site pulse: the audience tiles and detail (2026.10.4).
require_once __DIR__ . '/dashboard-attention.php'; // the headline and Needs attention (2026.10.4).
require_once __DIR__ . '/dashboard-work.php'; // Continue working (2026.10.4).

/**
 * A classic admin URL into our own pages, as a `go` target — tab, sub, anchor.
 *
 * @param string $href Admin URL.
 * @return array<string,string>|null Null when the URL is not one of ours.
 */
function go_target( $href ) {
	$query = array();
	$parts = wp_parse_url( (string) $href );
	if ( ! is_array( $parts ) || false === strpos( (string) ( $parts['query'] ?? '' ), 'page=sn-theme-options' ) ) {
		return null;
	}
	parse_str( (string) $parts['query'], $query );
	return array(
		'tab'    => (string) ( $query['tab'] ?? 'dashboard' ),
		'sub'    => (string) ( $query['sub'] ?? '' ),
		'anchor' => (string) ( $parts['fragment'] ?? '' ),
	);
}

/**
 * A personal, time-aware S&N Home greeting.
 *
 * @return string
 */
function home_greeting() {
	$hour = function_exists( 'current_time' ) ? (int) current_time( 'G' ) : (int) gmdate( 'G' );
	if ( $hour < 12 ) {
		$salutation = __( 'Good morning', 'signal-and-noise-tools' );
	} elseif ( $hour < 18 ) {
		$salutation = __( 'Good afternoon', 'signal-and-noise-tools' );
	} else {
		$salutation = __( 'Good evening', 'signal-and-noise-tools' );
	}

	$name = '';
	if ( function_exists( 'wp_get_current_user' ) ) {
		$user = wp_get_current_user();
		if ( is_object( $user ) ) {
			$name = trim( (string) ( $user->user_firstname ?? '' ) );
			if ( '' === $name ) {
				$display = trim( (string) ( $user->display_name ?? '' ) );
				$name    = '' !== $display ? (string) strtok( $display, ' ' ) : '';
			}
		}
	}

	return '' !== $name ? $salutation . ', ' . $name : __( 'Welcome back', 'signal-and-noise-tools' );
}

/**
 * A link's `go` wiring: an `os-action="go"` within this tab, the kit's
 * `.snt-go` across tabs, nothing for a URL that is not ours (the host routes
 * those). One helper for the pulse tiles and the attention rows.
 *
 * @param array<string,string> $attrs The link's attributes.
 * @param string               $href  Its URL.
 * @param string               $tab   The painting tab.
 * @return array<string,string>
 */
function go_attrs( array $attrs, $href, $tab ) {
	$go = go_target( (string) $href );
	if ( null === $go ) {
		return $attrs;
	}
	$same = '' === $go['tab'] || $go['tab'] === (string) $tab;
	if ( ! $same ) {
		$attrs['class']       .= ' snt-go';
		$attrs['data-snt-tab'] = $go['tab'];
	} else {
		$attrs['os-action'] = 'go';
	}
	foreach ( array( 'sub', 'anchor' ) as $key ) {
		if ( '' !== $go[ $key ] ) {
			$attrs[ $same ? 'os-arg-' . $key : 'data-snt-' . $key ] = $go[ $key ];
		}
	}
	return $attrs;
}

/**
 * Return quick launcher actions for S&N Home left rail.
 *
 * @return array<int,array<string,string>>
 */
function home_quick_actions() {
	return array(
		array(
			'id'    => 'sn-analytics',
			'label' => __( 'S&N Analytics', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-chart-area',
			'url'   => admin_url( 'admin.php?page=sn-analytics' ),
			'app'   => 'sn-analytics',
		),
		array(
			'id'    => 'signal-noise',
			'label' => __( 'Signal & Noise', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-shield',
			'url'   => admin_url( 'admin.php?page=signal-noise' ),
			'app'   => 'signal-noise',
		),
		array(
			'id'    => 'posts',
			'label' => __( 'Posts', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-admin-post',
			'url'   => admin_url( 'edit.php' ),
		),
		array(
			'id'    => 'pages',
			'label' => __( 'Pages', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-admin-page',
			'url'   => admin_url( 'edit.php?post_type=page' ),
		),
		array(
			'id'    => 'media',
			'label' => __( 'Media', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-admin-media',
			'url'   => admin_url( 'upload.php' ),
		),
		array(
			'id'    => 'classic',
			'label' => __( 'Classic Dashboard', 'signal-and-noise-tools' ),
			'icon'  => 'dashicons-wordpress',
			'url'   => admin_url( 'admin.php?page=sn-theme-options' ),
		),
	);
}

/**
 * Render the stable left rail.
 *
 * @return string
 */
function home_rail_html() {
	$out = '<aside class="snt-home__rail" aria-label="' . esc_attr__( 'S&N Home', 'signal-and-noise-tools' ) . '">'
		. '<div class="snt-home__brand">'
		. '<span class="dashicons dashicons-shield-alt snt-home__brand-mark" aria-hidden="true"></span>'
		. '<span>' . esc_html__( 'Signal & Noise', 'signal-and-noise-tools' ) . '</span>'
		. '</div>'
		. '<div class="snt-home__location" aria-current="page">'
		. '<span aria-hidden="true"></span>'
		. esc_html__( 'S&N Home', 'signal-and-noise-tools' )
		. '</div>'
		. '<div class="snt-home__mesh" aria-hidden="true"></div>'
		. '<nav class="snt-home__actions" aria-label="' . esc_attr__( 'Quick actions', 'signal-and-noise-tools' ) . '">';

	foreach ( home_quick_actions() as $action ) {
		$target_attr = '';
		if ( isset( $action['app'] ) ) {
			$target_attr = ' data-os-app="' . esc_attr( $action['app'] ) . '"';
		}
		$out .= '<a class="snt-home__action" href="' . esc_url( $action['url'] ) . '"' . $target_attr . ' title="' . esc_attr( $action['label'] ) . '">'
			. '<span class="dashicons ' . esc_attr( $action['icon'] ) . '" aria-hidden="true"></span>'
			. '<span class="snt-home__action-label">' . esc_html( $action['label'] ) . '</span>'
			. '</a>';
	}

	$out .= '</nav></aside>';
	return $out;
}

/**
 * The Dashboard tab painter (S&N Home).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_dashboard( array $ctx ) {
	if ( ! function_exists( 'snt_dashboard_tab_data' ) ) {
		return \snt_kit_empty( __( 'The Dashboard is not available.', 'signal-and-noise-tools' ) );
	}
	$data = \snt_dashboard_tab_data();
	if ( null === $data ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$tab    = (string) ( $ctx['tab'] ?? 'dashboard' );
	$orient = home_orientation_info( $data );

	$intro_html = '<header class="snt-home__intro snt-home-heading" data-state="' . \snt_kit_esc( $orient['state'] ) . '">'
		. '<div>'
		. '<h1 id="snt-home-title">' . \snt_kit_esc( home_greeting() ) . '</h1>'
		. '<p class="snt-home-heading__state">' . \snt_kit_esc( $orient['text'] ) . '</p>'
		. '</div>'
		. '<os-button class="snt-home__refresh" variant="ghost" os-action="refresh" aria-label="' . esc_attr__( 'Refresh S&N Home', 'signal-and-noise-tools' ) . '" title="' . esc_attr__( 'Refresh', 'signal-and-noise-tools' ) . '">'
		// The host aria-label names the inner button: os-button forwards it
		// since OpenStation 1.1.11 (#857), so no hidden slotted text.
		. '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
		. '</os-button>'
		. '</header>';

	$main_content = $intro_html
		. north_star_html( $tab )
		. home_pulse_html( $data, $tab )
		. home_desk_html( $data, $tab )
		. home_operations_html( $data, $tab );

	return '<div class="snt-home__layout" data-snt-home="true">'
		. home_rail_html()
		. '<main class="snt-home__main">' . $main_content . '</main>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['dashboard/'] = __NAMESPACE__ . '\paint_dashboard';
		return $painters;
	}
);
