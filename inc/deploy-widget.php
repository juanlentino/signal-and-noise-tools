<?php
/**
 * Signal & Noise Tools — Deploy status surfaces.
 *
 * Two coordinated wp-admin surfaces that close the loop on the WP-update
 * + deploy plumbing built across this codebase:
 *
 *   1. Dashboard widget (Signal & Noise · Deploy status) — read on
 *      login. Shows theme + plugin versions, recent GHA deploys, and
 *      quick actions.
 *
 *   2. Admin bar pills (top-secondary, right side) — at-a-glance state
 *      visible on every wp-admin page (and front-end when admin bar
 *      is shown).
 *
 * Both surfaces share the same data sources:
 *   - sn_gh_latest_theme_tag() / sn_gh_latest_plugin_tag() — version
 *     comparison cache from inc/wp-update-integration.php (theme +
 *     plugin). No new GitHub Tags API hits.
 *   - snt_gh_recent_runs_merged() — GHA workflow runs from
 *     inc/github-actions-api.php (60s transient cache).
 *
 * Force-check action (POST → admin-post.php → handler below) clears
 * BOTH our update transients AND WP's own update_themes/update_plugins
 * transients, then redirects to update-core.php?force-check=1 for the
 * belt-and-braces refresh.
 *
 * Capability gate: every surface checks current_user_can('manage_options').
 * Dashboard renders for any user with 'read' (per WP core), so the
 * render callback must self-gate. Admin bar nodes are registered only
 * for capable users. Handler does its own check + nonce.
 *
 * Verified against WP source:
 *   - wp_add_dashboard_widget() in wp-admin/includes/dashboard.php —
 *     7-arg signature; render callback receives ($post, $callback_args).
 *   - admin-post.php — reads $action from $_REQUEST, fires
 *     admin_post_{$action} hook, no automatic nonce verification.
 *   - WP_Admin_Bar::add_node() in wp-includes/class-wp-admin-bar.php —
 *     parent='top-secondary' for right-side placement.
 *
 * Added in v1.12.0 (2026-05-16).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DEPLOY_REPOS = array(
	'theme'  => 'juanlentino/signal-and-noise',
	'plugin' => 'juanlentino/signal-and-noise-tools',
);

/**
 * Compute a normalized status struct for one package.
 *
 * @return array {
 *   @type string $current  Current installed Version.
 *   @type string $latest   Latest GitHub tag (semver, no leading 'v').
 *   @type string $state    'ok' | 'available' | 'unknown'.
 *   @type string $repo     "owner/repo".
 * }
 */
function snt_deploy_status_for( $package ) {
	if ( 'theme' === $package ) {
		$current = (string) wp_get_theme( 'signal-and-noise' )->get( 'Version' );
		$latest  = function_exists( 'sn_gh_latest_theme_tag' ) ? sn_gh_latest_theme_tag() : null;
	} else {
		$current = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
		$latest  = function_exists( 'sn_gh_latest_plugin_tag' ) ? sn_gh_latest_plugin_tag() : null;
	}

	$latest_version = $latest ? ltrim( $latest, 'v' ) : '';
	$state          = 'unknown';
	if ( $current && $latest_version ) {
		$state = version_compare( $latest_version, $current, '>' ) ? 'available' : 'ok';
	}

	return array(
		'current' => $current,
		'latest'  => $latest_version,
		'state'   => $state,
		'repo'    => SNT_DEPLOY_REPOS[ $package ] ?? '',
	);
}

/**
 * Register the dashboard widget.
 *
 * Default priority — appears mid-stack. Users can drag/dismiss per WP
 * dashboard convention; we don't lock its position.
 */
add_action( 'wp_dashboard_setup', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget(
		'sn_deploy_status',
		'Signal &amp; Noise &middot; Deploy status',
		'snt_deploy_widget_render'
	);
} );

function snt_deploy_widget_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<p>You do not have permission to view deploy status.</p>';
		return;
	}

	$theme  = snt_deploy_status_for( 'theme' );
	$plugin = snt_deploy_status_for( 'plugin' );
	$runs   = function_exists( 'snt_gh_recent_runs_merged' )
		? snt_gh_recent_runs_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();

	?>
	<div class="sn-deploy-widget">
		<table class="sn-status-table sn-deploy-versions">
			<tbody>
				<?php foreach ( array( 'Theme' => $theme, 'Plugin' => $plugin ) as $label => $pkg ) : ?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td><code><?php echo esc_html( $pkg['current'] ?: '—' ); ?></code></td>
					<td><?php snt_deploy_render_state_pill( $pkg ); ?></td>
					<td style="text-align:right;">
						<?php if ( $pkg['repo'] ) : ?>
						<a href="<?php echo esc_url( 'https://github.com/' . $pkg['repo'] ); ?>"
						   target="_blank" rel="noopener noreferrer">repo &#x2197;</a>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h4 style="margin:1em 0 .35em;">Recent deploys</h4>
		<?php if ( empty( $runs ) ) : ?>
			<p style="color:#787c82;margin:0 0 .75em;"><em>No recent runs (or GitHub API unreachable).</em></p>
		<?php else : ?>
			<ul class="sn-deploy-runs" style="margin:0 0 .75em;list-style:none;padding:0;font-size:12px;">
				<?php foreach ( $runs as $run ) : ?>
				<li style="display:flex;gap:.5em;padding:.15em 0;align-items:baseline;">
					<span aria-hidden="true"><?php echo esc_html( snt_deploy_run_icon( $run ) ); ?></span>
					<code style="background:transparent;padding:0;"><?php echo esc_html( snt_deploy_short_repo( $run['repo'] ?? '' ) ); ?></code>
					<code style="background:transparent;padding:0;"><?php echo esc_html( $run['ref'] ?? '' ); ?></code>
					<span style="color:#787c82;"><?php echo esc_html( $run['trigger'] ?? '' ); ?></span>
					<span style="color:#787c82;margin-left:auto;">
						<?php echo esc_html( snt_deploy_duration_label( $run['duration_s'] ?? null ) ); ?> ·
						<?php echo esc_html( snt_deploy_relative_time( $run['created_at'] ?? '' ) ); ?>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="sn_force_update_check">
			<?php wp_nonce_field( 'sn_force_update_check', 'sn_force_update_check_nonce' ); ?>
			<button type="submit" class="button button-secondary button-small">Force-check updates</button>
		</form>
	</div>
	<?php
}

function snt_deploy_render_state_pill( $pkg ) {
	switch ( $pkg['state'] ) {
		case 'ok':
			printf( '<span class="sn-pill sn-pill--ok">up to date</span>' );
			break;
		case 'available':
			printf(
				'<span class="sn-pill sn-pill--warn">v%s available</span>',
				esc_html( $pkg['latest'] )
			);
			break;
		default:
			printf( '<span class="sn-pill sn-pill--err">unknown</span>' );
	}
}

function snt_deploy_run_icon( $run ) {
	$status     = (string) ( $run['status'] ?? '' );
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	if ( 'in_progress' === $status || 'queued' === $status ) {
		return '•';
	}
	if ( 'success' === $conclusion ) {
		return '✓';
	}
	if ( 'cancelled' === $conclusion || 'skipped' === $conclusion ) {
		return '⊘';
	}
	return '✗';
}

function snt_deploy_short_repo( $repo ) {
	// "juanlentino/signal-and-noise-tools" → "plugin"; "...signal-and-noise" → "theme".
	if ( str_ends_with( $repo, '-tools' ) ) {
		return 'plugin';
	}
	if ( $repo ) {
		return 'theme';
	}
	return '';
}

function snt_deploy_duration_label( $seconds ) {
	if ( ! is_int( $seconds ) ) {
		return '—';
	}
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	return sprintf( '%dm %ds', intdiv( $seconds, 60 ), $seconds % 60 );
}

function snt_deploy_relative_time( $iso ) {
	if ( ! $iso ) {
		return '';
	}
	$t = strtotime( $iso );
	if ( ! $t ) {
		return '';
	}
	return human_time_diff( $t, time() ) . ' ago';
}

/**
 * Force-check handler. POST target for the widget's button.
 *
 * Per WP convention: admin-post.php reads $action from $_REQUEST and
 * fires admin_post_{$action} for logged-in users. No automatic nonce
 * verification — we do it ourselves below.
 */
add_action( 'admin_post_sn_force_update_check', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_force_update_check', 'sn_force_update_check_nonce' );

	// Clear our caches.
	delete_site_transient( 'sn_gh_latest_theme' );
	delete_site_transient( 'sn_gh_latest_plugin' );
	foreach ( array_values( SNT_DEPLOY_REPOS ) as $repo ) {
		delete_site_transient( 'sn_gh_recent_runs_' . sanitize_key( str_replace( '/', '-', $repo ) ) );
	}
	// Clear WP's own update transients so the next poll re-fetches.
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );

/**
 * Admin bar pills — two compact indicators on the top-secondary (right)
 * side. Visible on every wp-admin page AND on the front-end when the
 * admin bar is shown.
 *
 * Priority 100 puts us before built-in WP nodes on the right but after
 * any high-priority third-party additions. Color comes from the same
 * sn-pill--{ok,warn,err} classes used in the widget — admin bar CSS
 * inherits theme colors but the badge background is preserved via the
 * meta.class attribute.
 */
add_action( 'admin_bar_menu', function( $admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! is_admin_bar_showing() ) {
		return;
	}

	foreach ( array( 'theme' => 'T', 'plugin' => 'P' ) as $pkg => $abbr ) {
		$status = snt_deploy_status_for( $pkg );
		$class  = 'sn-pill sn-pill--' . ( 'ok' === $status['state'] ? 'ok' : ( 'available' === $status['state'] ? 'warn' : 'err' ) );
		$title  = sprintf(
			'%s %s — %s',
			ucfirst( $pkg ),
			$status['current'] ?: '?',
			'available' === $status['state'] ? 'v' . $status['latest'] . ' available' : ( 'ok' === $status['state'] ? 'up to date' : 'status unknown' )
		);
		$label = sprintf(
			'%s&nbsp;%s',
			esc_html( $abbr ),
			esc_html( $status['current'] ?: '?' )
		);

		$admin_bar->add_node( array(
			'id'     => 'sn-deploy-' . $pkg,
			'parent' => 'top-secondary',
			'title'  => '<span class="' . esc_attr( $class ) . '">' . $label . '</span>',
			'href'   => admin_url( 'index.php#sn_deploy_status' ),
			'meta'   => array(
				'title' => $title,
				'class' => 'sn-deploy-bar-item',
			),
		) );
	}
}, 100 );

/**
 * Admin bar pill styling — minimal override so the existing sn-pill--*
 * classes also work inside the admin bar's #wpadminbar context (which
 * tends to flatten background colors).
 */
add_action( 'admin_bar_init', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_action( 'admin_print_styles', 'snt_deploy_bar_styles' );
	add_action( 'wp_print_styles', 'snt_deploy_bar_styles' );
} );

function snt_deploy_bar_styles() {
	if ( ! is_admin_bar_showing() ) {
		return;
	}
	?>
	<style id="sn-deploy-bar-css">
		#wpadminbar .sn-deploy-bar-item .ab-item { padding: 0 8px; }
		#wpadminbar .sn-deploy-bar-item .sn-pill {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: 600;
			line-height: 18px;
		}
		#wpadminbar .sn-deploy-bar-item .sn-pill--ok   { background: #0a5a1a; color: #fff; }
		#wpadminbar .sn-deploy-bar-item .sn-pill--warn { background: #b78103; color: #fff; }
		#wpadminbar .sn-deploy-bar-item .sn-pill--err  { background: #8b1a1a; color: #fff; }
	</style>
	<?php
}
