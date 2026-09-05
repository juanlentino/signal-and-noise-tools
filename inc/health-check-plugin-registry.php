<?php
/**
 * Health check: the plugin registry agrees with what is actually active.
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * On 2026-09-04 the OpenStation Plugins window showed an empty installed list
 * on both a phone and a desk, cleared only by remounting the window. It looked
 * like a client bug and was chased as one for some time. It was not: the window
 * renders `Could not load plugins: <error>` whenever its fetch fails, and no
 * such message appeared. So `GET /wp/v2/plugins` had returned **zero plugins
 * with a 200** — a healthy-looking answer that was simply untrue.
 *
 * `WP_REST_Plugins_Controller` reads `get_plugins()`, which is memoised through
 * the object cache. A stale or evicted entry therefore reports "no plugins
 * installed" in exactly the same shape as a site with none, and every consumer
 * downstream — REST, the shell's window, our own admin — believes it.
 *
 * Nothing in the estate could tell those two states apart, so the only detector
 * was somebody looking at a phone. This check is that detector.
 *
 * ── The oracle ────────────────────────────────────────────────────────────
 *
 * `active_plugins` is a plain option, not the plugin cache, so the two cannot
 * fail together for the same reason. Every basename in it MUST appear in
 * `get_plugins()`. A site can legitimately have zero *inactive* plugins; it
 * cannot legitimately have an active plugin the registry has never heard of.
 *
 * The disk check is what makes the finding actionable rather than merely
 * alarming, and it is the whole point of splitting the two findings:
 *
 *   - file present on disk, absent from the registry  -> the CACHE is wrong.
 *     Fix: `wp cache flush`. Nothing is broken on disk.
 *   - file absent from disk, still in `active_plugins` -> the PLUGIN is gone.
 *     Fix: deactivate the orphan; a flush will not bring it back.
 *
 * Collapsing those into one "plugin missing" finding would hand the reader the
 * same sentence for two opposite repairs, which is the failure this check was
 * written to stop repeating.
 *
 * Scope: site-level `active_plugins` only. Network-activated plugins live in
 * the `active_sitewide_plugins` site option and are deliberately NOT read here
 * — this install is single-site, and a check that silently half-covers a
 * multisite is worse than one that states its boundary.
 *
 * @package Signal_And_Noise_Tools
 * @since 13.96.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare the plugin registry against the plugins WordPress believes are active.
 *
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_plugin_registry() {
	$label    = 'Plugin registry';
	$fix_hint = 'Every active plugin must appear in get_plugins(). A plugin whose file is still on disk but missing from the registry means the object cache is serving a stale plugin list — run `wp cache flush`. A plugin whose file is gone should be deactivated.';

	if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		// NOT a pass: nothing was compared, and a poisoned registry would
		// produce the same zero findings.
		return sn_health_pack_check( $label, array(), $fix_hint, 'get_plugins() is unavailable in this context' );
	}

	$active = get_option( 'active_plugins' );
	if ( ! is_array( $active ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'the active_plugins option is unreadable' );
	}
	if ( array() === $active ) {
		// A site really can have nothing active. With no oracle there is
		// nothing to compare against, and saying so beats reporting a pass.
		return sn_health_pack_check( $label, array(), $fix_hint, 'no active plugins to check the registry against' );
	}

	$registry = get_plugins();
	if ( ! is_array( $registry ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'get_plugins() returned a non-array' );
	}
	$known = array_keys( $registry );

	$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
	$findings   = array();

	foreach ( $active as $basename ) {
		$basename = (string) $basename;
		if ( '' === $basename || in_array( $basename, $known, true ) ) {
			continue;
		}

		// The two states this check exists to separate.
		$path      = '' !== $plugin_dir ? $plugin_dir . '/' . $basename : '';
		$on_disk   = '' !== $path && file_exists( $path );
		$note      = $on_disk
			? 'Active, and its file is on disk, but get_plugins() does not list it — the object cache is serving a stale plugin list. Run `wp cache flush`. Every consumer of the registry (the REST plugins route, the admin list, the shell window) is reading the same wrong answer.'
			: 'Active, but its file is not on disk — the plugin was removed while still activated. Deactivate the orphan; flushing the cache will not bring it back.';

		$findings[] = array(
			'subject_type'  => 'plugin',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => $basename,
			'edit_url'      => '',
			'note'          => $note,
		);
	}

	// The headline case, reported once and named for what it is: the registry
	// is empty while WordPress is actively running plugins out of it.
	if ( array() === $known ) {
		$findings[] = array(
			'subject_type'  => 'plugin_registry',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'registry is empty',
			'edit_url'      => '',
			'note'          => sprintf(
				'get_plugins() returned NOTHING while %d plugin(s) are active. Any REST or admin surface reading it now reports "no plugins installed" with a 200 — a healthy-looking answer that is false. Run `wp cache flush`.',
				count( $active )
			),
		);
	}

	// A transient poisoning can be served, be seen, and be gone before the next
	// scheduled scan. inc/plugin-registry-probe.php writes down what it served;
	// report that for its window even when the registry reads correctly NOW,
	// otherwise the scan reports a clean site for a fault someone watched.
	if ( function_exists( 'snt_plugin_registry_anomaly' ) ) {
		$seen = snt_plugin_registry_anomaly();
		if ( is_array( $seen ) ) {
			$findings[] = array(
				'subject_type'  => 'plugin_registry',
				'subject_id'    => 0,
				'subject_url'   => '',
				'subject_label' => 'empty response observed',
				'edit_url'      => '',
				'note'          => sprintf(
					'GET /wp/v2/plugins served an EMPTY list with a success status %s ago, while %d plugin(s) were active. The registry may read correctly now - this is the observation, not the current state. It clears itself after 7 days. Run `wp cache flush` and check the object cache.',
					human_time_diff( $seen['time'] ),
					$seen['active']
				),
			);
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
