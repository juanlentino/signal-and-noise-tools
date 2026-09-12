<?php
/**
 * S&N Dashboard — S&N Home landing surface, painted in Station Home's idiom.
 *
 * Implements the executive site-level home surface:
 * 1. Stable left rail cross-app launcher (Analytics, S&N, Posts, Pages, Media, Classic).
 * 2. Time-aware greeting and live situation-dependent orientation.
 * 3. Compact Site Pulse (Audience, Publishing, Trust & Operations).
 * 4. Actionable Needs Attention queue with All Clear fallback.
 * 5. Continue Working recent work rows.
 * 6. Subordinate fleet systems & maintenance operations.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

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
 * Resolve live situation orientation.
 *
 * Urgent conditions override growth and operations-under-control.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @return array{state:string,text:string}
 */
function home_orientation_info( array $data ) {
	$runs       = (array) ( $data['runs'] ?? array() );
	$checks     = (array) ( $data['checks'] ?? array() );
	$components = (array) ( $data['components'] ?? array() );
	$attention  = (array) ( $data['attention'] ?? array() );

	$urgent_items = array();

	// 1. Failed recent deploy.
	foreach ( $runs as $run ) {
		$status     = (string) ( $run['status'] ?? '' );
		$conclusion = (string) ( $run['conclusion'] ?? '' );
		if ( 'completed' === $status && '' !== $conclusion
			&& ! in_array( $conclusion, array( 'success', 'cancelled', 'skipped' ), true ) ) {
			$urgent_items[] = __( 'A deployment needs attention', 'signal-and-noise-tools' );
			break;
		}
	}

	// 2. Urgent checks or components with err.
	foreach ( array_merge( $checks, $components ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$kind = (string) ( $item['pill']['kind'] ?? '' );
		if ( 'err' === $kind ) {
			$label = (string) ( $item['label'] ?? '' );
			if ( false !== stripos( $label, 'security' ) || false !== stripos( $label, 'login' ) ) {
				$urgent_items[] = __( 'A security alert needs attention', 'signal-and-noise-tools' );
			} elseif ( false !== stripos( $label, 'provenance' ) || false !== stripos( $label, 'anchor' ) ) {
				$urgent_items[] = __( 'Content provenance needs attention', 'signal-and-noise-tools' );
			} elseif ( false !== stripos( $label, 'health' ) ) {
				$urgent_items[] = __( 'A health check needs attention', 'signal-and-noise-tools' );
			} else {
				/* translators: %s: component label */
				$urgent_items[] = sprintf( __( '%s needs attention', 'signal-and-noise-tools' ), $label );
			}
		}
	}

	$urgent_count = count( $urgent_items );
	if ( 1 === $urgent_count ) {
		return array(
			'state' => 'err',
			'text'  => $urgent_items[0] . '.',
		);
	}
	if ( $urgent_count > 1 ) {
		return array(
			'state' => 'err',
			/* translators: %d: number of urgent items */
			'text'  => sprintf( __( '%d things need attention.', 'signal-and-noise-tools' ), $urgent_count ),
		);
	}

	// Non-urgent attention items (decision needed).
	if ( ! empty( $attention ) ) {
		$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		return array(
			'state' => 'warn',
			'text'  => '' !== $site_name
				/* translators: %s: site name */
				? sprintf( __( 'A decision needs attention on %s.', 'signal-and-noise-tools' ), $site_name )
				: __( 'A decision needs attention.', 'signal-and-noise-tools' ),
		);
	}

	// Healthy and growing.
	$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
	return array(
		'state' => 'ok',
		'text'  => '' !== $site_name
			/* translators: %s: site name */
			? sprintf( __( 'Pick up where you left off on %s. The site is healthy and growing.', 'signal-and-noise-tools' ), $site_name )
			: __( 'Pick up where you left off. The site is healthy and growing.', 'signal-and-noise-tools' ),
	);
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
 * Render a single Site Pulse metric cell.
 *
 * @param string $label Label.
 * @param string $icon  Dashicon class.
 * @param string $value Primary value.
 * @param string $delta Comparison delta.
 * @param string $href  Target URL.
 * @param string $tab   Current tab.
 * @return string
 */
function pulse_item_html( $label, $icon, $value, $delta, $href, $tab = 'dashboard' ) {
	$go         = go_target( $href );
	$delta_html = '' !== $delta ? '<span class="snt-home__metric-delta">' . esc_html( $delta ) . '</span>' : '';
	$body       = '<div class="snt-home__metric-label">'
		. '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>'
		. '<span>' . esc_html( $label ) . '</span>'
		. '</div>'
		. '<strong>' . esc_html( $value ) . '</strong>'
		. $delta_html;

	$attrs = array(
		'class' => 'snt-home__metric',
		'href'  => (string) $href,
	);

	if ( null !== $go ) {
		$target_tab = (string) ( $go['tab'] ?? '' );
		if ( '' === $target_tab || $target_tab === $tab ) {
			$attrs['os-action'] = 'go';
			if ( ! empty( $go['sub'] ) ) {
				$attrs['os-arg-sub'] = (string) $go['sub'];
			}
			if ( ! empty( $go['anchor'] ) ) {
				$attrs['os-arg-anchor'] = (string) $go['anchor'];
			}
		} else {
			$attrs['class']       .= ' snt-go';
			$attrs['data-snt-tab'] = $target_tab;
			if ( ! empty( $go['sub'] ) ) {
				$attrs['data-snt-sub'] = (string) $go['sub'];
			}
			if ( ! empty( $go['anchor'] ) ) {
				$attrs['data-snt-anchor'] = (string) $go['anchor'];
			}
		}
	}

	return \snt_kit_tag( 'a', $attrs, $body );
}

/**
 * Render the Site Pulse grouped metric strip.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_pulse_html( array $data, $tab ) {
	$measurement = function_exists( 'snt_dashboard_measurement_data' ) ? snt_dashboard_measurement_data() : array();

	// 1. Audience 7-day deltas.
	$from   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
	$to     = gmdate( 'Y-m-d', time() );
	$deltas = function_exists( 'sn_analytics_period_deltas' ) ? sn_analytics_period_deltas( $from, $to, 'human' ) : array();

	$views_curr = isset( $deltas['views']['current'] )
		? number_format_i18n( (int) $deltas['views']['current'] )
		: ( isset( $measurement['views_7d'] ) ? number_format_i18n( (int) $measurement['views_7d'] ) : '—' );
	$views_pct  = isset( $deltas['views']['pct'] ) && null !== $deltas['views']['pct']
		? ( ( $deltas['views']['pct'] >= 0 ? '+' : '' ) . $deltas['views']['pct'] . '%' )
		: '';

	$visits_curr = isset( $deltas['visits']['current'] )
		? number_format_i18n( (int) $deltas['visits']['current'] )
		: '—';
	$visits_pct  = isset( $deltas['visits']['pct'] ) && null !== $deltas['visits']['pct']
		? ( ( $deltas['visits']['pct'] >= 0 ? '+' : '' ) . $deltas['visits']['pct'] . '%' )
		: '';

	$engaged_val = '—';
	$engaged_pct = '';
	if ( isset( $deltas['views']['current'] ) && $deltas['views']['current'] > 0 && isset( $deltas['visits']['current'] ) && $deltas['visits']['current'] > 0 ) {
		$rate        = round( ( (int) $deltas['views']['current'] / max( 1, (int) $deltas['visits']['current'] ) ), 1 );
		$engaged_val = number_format_i18n( $rate, 1 ) . ' v/s';

		// The tile is a RATIO (views per visit); its delta must be the ratio's
		// own move, not the views delta — views and visits can move together
		// (ratio unchanged) or apart (ratio moves opposite either input).
		$prior_views  = (int) ( $deltas['views']['previous'] ?? 0 );
		$prior_visits = (int) ( $deltas['visits']['previous'] ?? 0 );
		if ( $prior_views > 0 && $prior_visits > 0 && function_exists( 'sn_analytics_delta' ) ) {
			$prior_rate = $prior_views / $prior_visits;
			$rate_delta = sn_analytics_delta( $rate, $prior_rate );
			if ( null !== $rate_delta['pct'] ) {
				$engaged_pct = ( $rate_delta['pct'] >= 0 ? '+' : '' ) . $rate_delta['pct'] . '%';
			}
		}
	}

	$search_clicks = isset( $measurement['search_clicks'] ) ? number_format_i18n( (int) $measurement['search_clicks'] ) : '—';
	$search_detail = isset( $measurement['search_clicks_days'] ) ? sprintf( __( '%dd window', 'signal-and-noise-tools' ), $measurement['search_clicks_days'] ) : '';

	// 2. Publishing.
	$post_counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'post' ) : null;
	$published   = is_object( $post_counts ) && isset( $post_counts->publish ) ? number_format_i18n( (int) $post_counts->publish ) : '—';
	$scheduled   = is_object( $post_counts ) && isset( $post_counts->future ) ? number_format_i18n( (int) $post_counts->future ) : '—';

	// 3. Trust & Operations.
	$plugin_status = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();
	$deploy_val    = (string) ( $plugin_status['version'] ?? 'Current' );

	$anchored_str = '—';
	if ( isset( $measurement['anchored'] ) ) {
		$anchored_str = isset( $measurement['anchored_total'] )
			? sprintf( '%d / %d', $measurement['anchored'], $measurement['anchored_total'] )
			: (string) $measurement['anchored'];
	}

	$health_val = __( 'Healthy', 'signal-and-noise-tools' );
	$verdict    = (array) ( $data['verdict'] ?? array() );
	if ( 'err' === ( $verdict['state'] ?? '' ) ) {
		$health_val = __( 'Attention', 'signal-and-noise-tools' );
	} elseif ( 'warn' === ( $verdict['state'] ?? '' ) ) {
		$health_val = __( 'Warning', 'signal-and-noise-tools' );
	}

	$timestamp = function_exists( 'current_time' ) ? current_time( 'g:i a' ) : gmdate( 'g:i a' );

	$out = '<section class="snt-home__section" aria-labelledby="snt-home-pulse-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-pulse-heading">' . esc_html__( 'Site pulse', 'signal-and-noise-tools' ) . '</h2>'
		. '<span class="snt-home__timestamp">' . sprintf( esc_html__( 'Updated %s', 'signal-and-noise-tools' ), esc_html( $timestamp ) ) . '</span>'
		. '</div>';

	// Group 1: Audience.
	$out .= '<div class="snt-home__pulse-group">'
		. '<div class="snt-home__pulse-group-label">' . esc_html__( 'Audience (7 days)', 'signal-and-noise-tools' ) . '</div>'
		. '<div class="snt-home__pulse">'
		. pulse_item_html( __( 'Views', 'signal-and-noise-tools' ), 'dashicons-visibility', $views_curr, $views_pct, admin_url( 'admin.php?page=sn-analytics&sn_range=7d' ) )
		. pulse_item_html( __( 'Visits', 'signal-and-noise-tools' ), 'dashicons-groups', $visits_curr, $visits_pct, admin_url( 'admin.php?page=sn-analytics&sn_view=sessions&sn_range=7d' ) )
		. pulse_item_html( __( 'Engagement', 'signal-and-noise-tools' ), 'dashicons-performance', $engaged_val, $engaged_pct, admin_url( 'admin.php?page=sn-analytics&sn_range=7d' ) )
		. pulse_item_html( __( 'Search clicks', 'signal-and-noise-tools' ), 'dashicons-search', $search_clicks, $search_detail, admin_url( 'admin.php?page=sn-analytics&sn_view=search' ) )
		. '</div></div>';

	// Group 2: Publishing.
	$out .= '<div class="snt-home__pulse-group">'
		. '<div class="snt-home__pulse-group-label">' . esc_html__( 'Publishing', 'signal-and-noise-tools' ) . '</div>'
		. '<div class="snt-home__pulse">'
		. pulse_item_html( __( 'Published posts', 'signal-and-noise-tools' ), 'dashicons-admin-post', $published, '', admin_url( 'edit.php' ) )
		. pulse_item_html( __( 'Scheduled posts', 'signal-and-noise-tools' ), 'dashicons-calendar-alt', $scheduled, '', admin_url( 'edit.php?post_status=future' ) )
		. '</div></div>';

	// Group 3: Trust & Operations.
	$out .= '<div class="snt-home__pulse-group">'
		. '<div class="snt-home__pulse-group-label">' . esc_html__( 'Trust & Operations', 'signal-and-noise-tools' ) . '</div>'
		. '<div class="snt-home__pulse">'
		. pulse_item_html( __( 'Plugin version', 'signal-and-noise-tools' ), 'dashicons-admin-plugins', $deploy_val, '', admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' ), $tab )
		. pulse_item_html( __( 'Provenance anchors', 'signal-and-noise-tools' ), 'dashicons-tag', $anchored_str, '', admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=provenance' ), $tab )
		. pulse_item_html( __( 'Site health', 'signal-and-noise-tools' ), 'dashicons-heart', $health_val, '', admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ), $tab )
		. '</div></div>';

	$out .= '</section>';
	return $out;
}

/**
 * Render the Needs Attention section.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_attention_html( array $data, $tab ) {
	$runs       = (array) ( $data['runs'] ?? array() );
	$checks     = (array) ( $data['checks'] ?? array() );
	$components = (array) ( $data['components'] ?? array() );
	$attention  = (array) ( $data['attention'] ?? array() );

	$rows = array();

	// 1. Deploys non-success.
	foreach ( $runs as $run ) {
		$status     = (string) ( $run['status'] ?? '' );
		$conclusion = (string) ( $run['conclusion'] ?? '' );
		if ( 'completed' === $status && '' !== $conclusion
			&& ! in_array( $conclusion, array( 'success', 'cancelled', 'skipped' ), true ) ) {
			$rows[] = array(
				'severity'     => 'err',
				'icon'         => 'dashicons-warning',
				'label'        => __( 'Deployment failed', 'signal-and-noise-tools' ),
				'description'  => sprintf( __( 'Workflow concluded with %s. Review logs.', 'signal-and-noise-tools' ), $conclusion ),
				'action_label' => __( 'Fix deployment', 'signal-and-noise-tools' ),
				'href'         => admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' ),
			);
			break;
		}
	}

	// 2. Checks/Components with err or warn.
	foreach ( array_merge( $checks, $components ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$kind = (string) ( $item['pill']['kind'] ?? '' );
		if ( 'err' === $kind || ( 'warn' === $kind && \sn_admin_card_wants_attention( $item ) ) ) {
			$is_err = 'err' === $kind;
			$label  = (string) ( $item['label'] ?? '' );
			$val    = (string) ( $item['value'] ?? '' );
			$rows[] = array(
				'severity'     => $is_err ? 'err' : 'warn',
				'icon'         => $is_err ? 'dashicons-warning' : 'dashicons-info',
				'label'        => $label,
				'description'  => '' !== $val ? $val : __( 'Requires review and verification.', 'signal-and-noise-tools' ),
				'action_label' => $is_err ? __( 'Review now', 'signal-and-noise-tools' ) : __( 'Inspect', 'signal-and-noise-tools' ),
				'href'         => (string) ( $item['href'] ?? admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ) ),
			);
		}
	}

	// 3. Existing attention items (e.g. DB overrides, orphans, health findings).
	foreach ( $attention as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$text = (string) ( $item['text'] ?? '' );
		$href = (string) ( $item['href'] ?? '' );
		$already = false;
		foreach ( $rows as $r ) {
			if ( $r['label'] === $text ) {
				$already = true;
				break;
			}
		}
		if ( ! $already ) {
			$rows[] = array(
				'severity'     => 'warn',
				'icon'         => 'dashicons-info',
				'label'        => $text,
				'description'  => __( 'Operational item pending review.', 'signal-and-noise-tools' ),
				'action_label' => __( 'Review', 'signal-and-noise-tools' ),
				'href'         => $href,
			);
		}
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['severity'] === $b['severity'] ) {
				return 0;
			}
			return 'err' === $a['severity'] ? -1 : 1;
		}
	);

	$total_count  = count( $rows );
	$visible_rows = array_slice( $rows, 0, 5 );

	$out = '<section class="snt-home__section" aria-labelledby="snt-home-attention-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-attention-heading">' . esc_html__( 'Needs attention', 'signal-and-noise-tools' ) . '</h2>';

	if ( $total_count > 5 ) {
		$view_all_url = admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' );
		$go           = go_target( $view_all_url );
		$view_all_btn = null !== $go
			? \snt_kit_go( sprintf( __( 'View all (%d)', 'signal-and-noise-tools' ), $total_count ), $go + array( 'current' => $tab ), array( 'class' => 'snt-home__view-all' ) )
			: '<a class="snt-home__view-all" href="' . esc_url( $view_all_url ) . '">' . sprintf( esc_html__( 'View all (%d)', 'signal-and-noise-tools' ), $total_count ) . '</a>';
		$out         .= $view_all_btn;
	}

	$out .= '</div><div class="snt-home__attention">';

	if ( empty( $rows ) ) {
		$out .= '<div class="snt-home__all-clear">'
			. '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>'
			. '<span>'
			. '<strong>' . esc_html__( 'All clear', 'signal-and-noise-tools' ) . '</strong>'
			. '<span>' . esc_html__( 'Nothing needs your attention right now.', 'signal-and-noise-tools' ) . '</span>'
			. '</span>'
			. '</div>';
	} else {
		foreach ( $visible_rows as $row ) {
			$go       = go_target( $row['href'] );
			$row_body = '<span class="snt-home__attention-badge snt-home__attention-badge--' . esc_attr( $row['severity'] ) . '">'
				. '<span class="dashicons ' . esc_attr( $row['icon'] ) . '" aria-hidden="true"></span>'
				. '</span>'
				. '<span class="snt-home__attention-copy">'
				. '<strong>' . esc_html( $row['label'] ) . '</strong>'
				. '<span>' . esc_html( $row['description'] ) . '</span>'
				. '</span>'
				. '<span class="snt-home__attention-action">'
				. esc_html( $row['action_label'] ) . ' '
				. '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
				. '</span>';

			$attrs = array(
				'class' => 'snt-home__attention-row snt-home__attention-row--' . esc_attr( $row['severity'] ),
				'href'  => (string) $row['href'],
			);

			if ( null !== $go ) {
				$target_tab = (string) ( $go['tab'] ?? '' );
				if ( '' === $target_tab || $target_tab === $tab ) {
					$attrs['os-action'] = 'go';
					if ( ! empty( $go['sub'] ) ) {
						$attrs['os-arg-sub'] = (string) $go['sub'];
					}
					if ( ! empty( $go['anchor'] ) ) {
						$attrs['os-arg-anchor'] = (string) $go['anchor'];
					}
				} else {
					$attrs['class']       .= ' snt-go';
					$attrs['data-snt-tab'] = $target_tab;
					if ( ! empty( $go['sub'] ) ) {
						$attrs['data-snt-sub'] = (string) $go['sub'];
					}
					if ( ! empty( $go['anchor'] ) ) {
						$attrs['data-snt-anchor'] = (string) $go['anchor'];
					}
				}
			}

			$out .= \snt_kit_tag( 'a', $attrs, $row_body );
		}
	}

	$out .= '</div></section>';
	return $out;
}

/**
 * Render the Continue Working recent items section.
 *
 * @param string $tab Current tab.
 * @return string
 */
function home_continue_working_html( $tab ) {
	unset( $tab );
	$items       = array();
	$found_posts = 0;

	if ( class_exists( 'WP_Query' ) ) {
		$query = new \WP_Query(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => array( 'draft', 'pending', 'future', 'private', 'publish' ),
				'posts_per_page'         => 6,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$found_posts = (int) $query->found_posts;

		if ( ! empty( $query->posts ) ) {
			foreach ( $query->posts as $p ) {
				$edit_url = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $p->ID, 'raw' ) : admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
				if ( ! is_string( $edit_url ) || '' === $edit_url ) {
					continue;
				}
				$type_obj   = function_exists( 'get_post_type_object' ) ? get_post_type_object( $p->post_type ) : null;
				$status_obj = function_exists( 'get_post_status_object' ) ? get_post_status_object( $p->post_status ) : null;
				$title      = function_exists( 'get_the_title' ) ? get_the_title( $p ) : $p->post_title;

				$modified_time = strtotime( (string) $p->post_modified_gmt . ' UTC' );
				$time_str      = false !== $modified_time && function_exists( 'human_time_diff' ) ? human_time_diff( $modified_time ) . ' ' . __( 'ago', 'signal-and-noise-tools' ) : '';

				$items[] = array(
					'id'         => $p->ID,
					'title'      => '' !== trim( (string) $title ) ? (string) $title : __( '(Untitled)', 'signal-and-noise-tools' ),
					'type'       => $type_obj ? $type_obj->labels->singular_name : __( 'Post', 'signal-and-noise-tools' ),
					'icon'       => 'page' === $p->post_type ? 'dashicons-admin-page' : 'dashicons-admin-post',
					'status'     => $p->post_status,
					'status_lbl' => $status_obj ? $status_obj->label : $p->post_status,
					'time_ago'   => $time_str,
					'edit_url'   => $edit_url,
				);
			}
		}
	}

	// found_posts is the query's real total (paid for via no_found_rows=false);
	// count( $items ) is only the 6-row display window and undercounts once
	// more than 6 posts exist. Fall back to the window for a stub WP_Query
	// (or no WP_Query at all) that never set found_posts.
	$total_count  = $found_posts > 0 ? $found_posts : count( $items );
	$visible_work = array_slice( $items, 0, 5 );

	$out = '<section class="snt-home__section" aria-labelledby="snt-home-work-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-work-heading">' . esc_html__( 'Continue working', 'signal-and-noise-tools' ) . '</h2>';

	if ( $total_count > 5 ) {
		$out .= '<a class="snt-home__view-all" href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . sprintf( esc_html__( 'View all (%d)', 'signal-and-noise-tools' ), $total_count ) . '</a>';
	}

	$out .= '</div><div class="snt-home__work">';

	if ( empty( $items ) ) {
		$out .= '<div class="snt-home__all-clear">'
			. '<span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span>'
			. '<span>'
			. '<strong>' . esc_html__( 'Your desk is clear', 'signal-and-noise-tools' ) . '</strong>'
			. '<span>' . esc_html__( 'Start something new and it will be waiting here when you return.', 'signal-and-noise-tools' ) . '</span>'
			. '</span>'
			. '</div>';
	} else {
		foreach ( $visible_work as $item ) {
			$status_tone = 'publish' === $item['status'] ? 'success' : ( in_array( $item['status'], array( 'future', 'pending' ), true ) ? 'warning' : 'neutral' );

			$out .= '<a class="snt-home__work-row" href="' . esc_url( $item['edit_url'] ) . '">'
				. '<span class="snt-home__row-icon"><span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span></span>'
				. '<span class="snt-home__row-copy">'
				. '<span class="snt-home__row-title">' . esc_html( $item['title'] ) . '</span>'
				. '<span class="snt-home__row-meta">' . esc_html( $item['type'] ) . '</span>'
				. '</span>'
				. '<os-badge tone="' . esc_attr( $status_tone ) . '">' . esc_html( $item['status_lbl'] ) . '</os-badge>'
				. ( '' !== $item['time_ago'] ? '<span class="snt-home__row-time">' . esc_html( $item['time_ago'] ) . '</span>' : '' )
				. '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
				. '</a>';
		}
	}

	$out .= '</div></section>';
	return $out;
}

/**
 * Render subordinate operations & maintenance.
 *
 * @param array<string,mixed> $data Dashboard data bundle.
 * @param string              $tab  Current tab.
 * @return string
 */
function home_operations_html( array $data, $tab ) {
	$out = '<section class="snt-home__section snt-home__section--subordinate" aria-labelledby="snt-home-ops-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-ops-heading">' . esc_html__( 'Operations & Maintenance', 'signal-and-noise-tools' ) . '</h2>'
		. '</div>';

	$out .= systems_html( (array) ( $data['checks'] ?? array() ), (array) ( $data['components'] ?? array() ), $tab );
	$out .= detail_html( (array) ( $data['panels'] ?? array() ) );
	$out .= toolbar_html( (string) ( $data['check_updates_url'] ?? '' ) );

	if ( ! empty( $data['overrides'] ) ) {
		$names = array_map( '\snt_kit_esc', (array) $data['overrides'] );
		$out  .= \snt_kit_tag(
			'os-disclosure',
			array(
				'heading' => sprintf( /* translators: %d database overrides */ _n( '%d database override', '%d database overrides', count( $names ), 'signal-and-noise-tools' ), count( $names ) ),
				'open'    => false,
				'id'      => 'sn-dash-diagnostics',
			),
			'<ul class="snt-plain">' . implode( '', array_map( static function ( $name ) { return '<li><os-code>' . $name . '</os-code></li>'; }, $names ) ) . '</ul>'
		);
	}

	$out .= '</section>';
	return $out;
}

/**
 * Systems wall: fleet checks & components.
 *
 * @param array<int,array<string,mixed>> $checks     Checks.
 * @param array<int,array<string,mixed>> $components Fleet cards.
 * @param string                         $tab        The painting tab.
 * @return string
 */
function systems_html( array $checks, array $components, $tab ) {
	$cells = '';
	foreach ( array_merge( array_values( $checks ), array_values( $components ) ) as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';
		$state = ( 'ok' !== $kind && \sn_admin_card_wants_attention( $card ) ) ? $kind : '';
		$value = (string) ( $card['value'] ?? '' );
		$go    = go_target( (string) ( $card['href'] ?? '' ) );
		$body  = null !== $go
			? \snt_kit_go( $value, $go + array( 'current' => $tab ), array( 'class' => 'snt-sys__v' ) )
			: '<span class="snt-sys__v">' . \snt_kit_esc( $value ) . '</span>';
		$pill  = (string) ( $card['pill']['text'] ?? '' );
		$cells .= '<div class="snt-sys' . ( '' !== $state ? ' snt-sys--' . \snt_kit_esc( $state ) : '' ) . '"' . ( '' !== $state ? ' data-tone="' . \snt_kit_tone( $state ) . '"' : '' ) . '>'
			. '<span class="snt-sys__k">' . \snt_kit_esc( (string) ( $card['label'] ?? '' ) ) . '</span>'
			. $body
			. ( '' !== $pill && 'ok' !== $kind ? \snt_kit_badge( $kind, $pill ) : '' )
			. ( '' !== (string) ( $card['meta_html'] ?? '' ) ? '<span class="snt-sys__meta">' . (string) $card['meta_html'] . '</span>' : '' )
			. '</div>';
	}
	$parts = array();
	if ( ! empty( $checks ) ) {
		/* translators: %d health checks on the wall */
		$parts[] = sprintf( _n( '%d check', '%d checks', count( $checks ), 'signal-and-noise-tools' ), count( $checks ) );
	}
	if ( ! empty( $components ) ) {
		/* translators: %d fleet components on the wall */
		$parts[] = sprintf( _n( '%d component', '%d components', count( $components ), 'signal-and-noise-tools' ), count( $components ) );
	}
	return \snt_kit_section( __( 'Systems', 'signal-and-noise-tools' ), '<div class="snt-systems">' . $cells . '</div>', implode( ' · ', $parts ) );
}

/**
 * Ops panels: deploys, pages, sources, queries, API rate limits.
 *
 * @param array<int,array<string,mixed>> $panels Panels.
 * @return string
 */
function detail_html( array $panels ) {
	$cols = '';
	foreach ( $panels as $panel ) {
		if ( ! is_array( $panel ) ) {
			continue;
		}
		$rows  = array_key_exists( 'rows', $panel ) ? $panel['rows'] : null;
		$inner = null === $rows
			? '<p class="snt-list__empty">' . \snt_kit_esc( (string) ( $panel['unmeasured'] ?? '' ) ) . '</p>'
			: \snt_kit_list( (array) $rows, array( 'empty' => (string) ( $panel['empty'] ?? '' ) ) );
		$cols .= '<section class="snt-col"><h3 class="snt-col__h">' . \snt_kit_esc( (string) ( $panel['title'] ?? '' ) ) . '</h3>' . $inner . '</section>';
	}
	return \snt_kit_section( __( 'Detail', 'signal-and-noise-tools' ), '<div class="snt-cols">' . $cols . '</div>' );
}

/**
 * Maintenance buttons.
 *
 * @param string $check_updates_url Admin post URL for update check.
 * @return string
 */
function toolbar_html( $check_updates_url ) {
	$buttons = \snt_kit_action_button( __( 'Purge all caches', 'signal-and-noise-tools' ), 'purge_caches' )
		. \snt_kit_action_button( __( 'Clear overrides', 'signal-and-noise-tools' ), 'clear_overrides' );
	if ( '' !== (string) $check_updates_url ) {
		$buttons .= \snt_kit_door( __( 'Check for updates', 'signal-and-noise-tools' ), (string) $check_updates_url, array( 'variant' => 'secondary' ) );
	}
	$buttons .= \snt_kit_action_button(
		__( 'Full reset', 'signal-and-noise-tools' ),
		'full_reset',
		array(
			'variant'       => 'danger',
			'confirm'       => __( 'Reset every Signal & Noise setting to its default? This cannot be undone.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Full reset', 'signal-and-noise-tools' ),
			'danger'        => true,
		)
	);
	return \snt_kit_section( __( 'Maintenance', 'signal-and-noise-tools' ), '<os-cluster gap="8">' . $buttons . '</os-cluster>' );
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
		. '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
		// Slotted text names the inner button; host aria-label is not forwarded.
		. '<span class="snt-home__refresh-label">' . \snt_kit_esc( __( 'Refresh S&N Home', 'signal-and-noise-tools' ) ) . '</span>'
		. '</os-button>'
		. '</header>';

	$main_content = $intro_html
		. home_pulse_html( $data, $tab )
		. home_attention_html( $data, $tab )
		. home_continue_working_html( $tab )
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
