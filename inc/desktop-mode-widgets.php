<?php
/**
 * Signal & Noise Tools — the eight desktop widgets.
 *
 * Registration on `init` priority 6, in the same closure shape the commands
 * use. ORDER IS REGISTRATION ORDER — openstation_register_widget() has no
 * `sort` arg (seed.push, src/widgets/registry.ts), so the intended order is
 * expressed by registering in it: traffic, then site condition, then ops.
 *
 * The default_* geometry is MEASURED, not guessed (v10.68.0) — the derivation
 * is preserved in the block comment below. Do not adjust a height without
 * re-measuring against the shell's own CSS at the docked column width.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the desktop widgets (init:6).
 *
 * MUST be `init` — the shell reads the widget registry eagerly at
 * admin_enqueue_scripts:10 and always beats a same-priority callback of ours.
 * See the loader's hook note.
 */
add_action( 'init', function() {
	if ( ! snt_os_active() ) {
		return;
	}

	// Independent availability check — desktop-mode/OpenStation could
	// theoretically ship commands without widgets (defensive, mirrors the
	// pre-v4.1.6 split).
	if ( snt_os_register_widget_available() ) {
		// v9.52.0: every entry carries description + icon. desktop-mode's
		// server-sync copies both straight onto the widget def and its picker
		// lists them under the label; without them the picker showed an empty
		// blurb and the generic fallback dashicon.
		//
		// v9.52.1: the 'sort' key these entries used to pass was DEAD — it is
		// absent from desktop_mode_register_widget()'s $defaults and from the
		// stored $entry in BOTH v0.8.9 and v0.9.5, so wp_parse_args() kept it
		// and the registry then dropped it on the floor. Widget order is simply
		// REGISTRATION order (`seed.push( def )`, src/widgets/registry.ts), so
		// the intended order is expressed by registering in it: the Pulse
		// command-center read first, then Site Views, then the three older
		// utility cards, then Health.
		// v9.52.2: every card is movable + resizable — drag it out of the
		// right-side column and place it anywhere on the desktop. Both default
		// FALSE, so until v9.52.2 the cards were locked to the column. `movable`
		// makes desktop-mode render a thin chrome header (grip + label +
		// remove) and drag initiates ONLY from that chrome, so the buttons
		// inside SN Quick Actions stay clickable; `resizable` adds the 8 grip
		// handles. The column drives geometry while a card is docked — the
		// default_* sizes apply the first time a card floats, and the min_*
		// floor stops a drag collapsing one into an unreadable sliver.
		//
		// v9.53.0: ONE WIDGET PER DOMAIN. SN Pulse is retired — it carried
		// views + a delta (Site Views' job) and the health ratio (Health's job),
		// so on a desktop with all cards enabled the same numbers rendered
		// twice. The one row it alone carried, uptime, is now SN Uptime. Each
		// surviving card goes deep instead of three cards going shallow.
		// desktop_mode_register_widget() has NO sort arg (absent from $defaults
		// and the stored $entry in both v0.8.9 and v0.9.5) — order is
		// REGISTRATION order (seed.push, src/widgets/registry.ts). Hence:
		// traffic, then site condition, then ops.
		//
		// v10.68.0: THE SIZES ARE MEASURED NOW, NOT GUESSED.
		//
		// Every default_* below was hand-picked before any card had ever
		// floated, and OpenStation 1.0.0 is where that showed: the owner's
		// desktop had Site Views and Machine Readers hand-dragged to roughly
		// double their declared height, while Health and Anchors sat with
		// visible dead space under their last row.
		//
		// The geometry contract itself did NOT change in 1.0.0 — the diff
		// v0.9.8..v1.0.0 over `src/widgets/` and the `.os-widgets__*` CSS is
		// the rename and nothing else (`card-body` is `padding:16px;
		// min-height:48px; overflow-y:auto` in BOTH tags, `__chrome` is
		// `padding:6px 8px` + a 1px bottom border in both). So these numbers
		// were always wrong; 1.0.0 is only when they got looked at.
		//
		// Measured against OpenStation 1.0.0's OWN `assets/css/desktop.css`,
		// with the real DOM `frame.ts` builds (card > chrome > body), at the
		// docked column width, driving each widget's real mount callback with
		// live payloads read off the site:
		//
		//   card width  = 320 (.os-widgets) − 2×4 (.os-widgets__list padding)
		//               = 312
		//   chrome      = 6 + 20 (the 20×20 close/redock tiles are the tallest
		//                 children) + 6 + 1px border = 33
		//   body        = content + 2×16 padding
		//
		//   sn-site-views       463   sn-quick-actions     242
		//   sn-health           148   sn-rss-subscribers   207
		//   sn-uptime           210   sn-anchors           167
		//   sn-deploy-status    192   sn-machine-readers   508
		//
		// default_width is 312 for EVERY card — exactly the docked width — so
		// liberating a card off the column no longer reflows its contents mid
		// drag. The old 300/330 split is what made the floating cards sit at
		// three different widths in the screenshot.
		//
		// default_height is the measured natural height rounded up to the next
		// 10 with ~10px of slack, so a longer relative timestamp or one extra
		// source row doesn't immediately push the body into a scroll.
		//
		// Sized for the TYPICAL state, deliberately. Health measures 279 with
		// four flagged checks + a remainder + advisories, and Anchors 194 with
		// two pending notes — but both idle at the measured figure above (18/18
		// today, 30 of 30 anchored), the body scrolls rather than truncating,
		// and every card is resizable. Sizing for the worst day would mean dead
		// space on every ordinary one.
		//
		// min_* are FLOORS, not targets: 240 × 120 keeps a `label … value` row
		// legible and leaves room for chrome + padding + a headline. They are a
		// legibility judgement, not a measurement — unlike default_*, which is.
		$sn_drag = array(
			'movable'       => true,
			'resizable'     => true,
			'min_width'     => 240,
			'min_height'    => 120,
			'default_width' => 312,
		);

		snt_os_register_widget( 'sn-site-views', array_merge( $sn_drag, array(
			'label'          => 'SN Site Views',
			'description'    => 'First-party traffic: 14-day sparkline, bot share, top pages.',
			'icon'           => 'dashicons-chart-area',
			'script'         => 'sn-desktop-mode-widget-views',
			// BUDGETED 510, not browser-measured — 450 + 3 glance rows
			// (today + engaged + top_mover) × ~20px = +60. "Today so far"
			// rides the 15-min payload transient so the number lags ≤15 min.
			'default_height' => 510,
		) ) );

		snt_os_register_widget( 'sn-health', array_merge( $sn_drag, array(
			'label'          => 'SN Health',
			'description'    => 'Content-health checks passing — and which ones are not.',
			'icon'           => 'dashicons-shield-alt',
			'script'         => 'sn-desktop-mode-widget-health',
			// Measured 148 all-passing (the state it idles in), 279 with four
			// flagged checks + remainder + advisories. Sized for the former.
			'default_height' => 160,
		) ) );

		// v9.53.0: new. Was one row inside Pulse; uptime deserves its own card
		// once it can show 30d availability + response time.
		snt_os_register_widget( 'sn-uptime', array_merge( $sn_drag, array(
			'label'          => 'SN Uptime',
			'description'    => 'Monitor status, 30-day availability and response time.',
			'icon'           => 'dashicons-chart-bar',
			'script'         => 'sn-desktop-mode-widget-uptime',
			// Measured 210 with the two live monitors (juanlentino.com + the JL
			// heartbeat), each carrying a name line and a stats line.
			'default_height' => 220,
		) ) );

		snt_os_register_widget( 'sn-deploy-status', array_merge( $sn_drag, array(
			'label'          => 'SN Deploy Status',
			'description'    => 'Theme, plugin, and worker versions with last deploy time.',
			'icon'           => 'dashicons-update',
			'script'         => 'sn-desktop-mode-widget',
			// v11.11.2: BUDGETED 310, not browser-measured — the old measured
			// 192 covered the two-row grid; five worker rows add ~22px each.
			// If the owner reports clipping, rebuild the Trap-11 measurement
			// recipe rather than guessing again.
			'default_height' => 310,
		) ) );

		// v2.1.0: Quick Actions widget — replaces the 3-click path of
		// S&N → Dashboard → Maintenance with single-click access from desktop.
		// v11.29.0: SN Cron. The desktop could report traffic, health, uptime,
		// versions and anchors but not whether the site's scheduled work was
		// still running — the one "is it awake?" question with no surface.
		// Reads the already-localized cronSummary; no new data layer.
		snt_os_register_widget( 'sn-cron', array_merge( $sn_drag, array(
			'label'          => 'SN Cron',
			'description'    => 'Scheduled events, how many are ours, and any orphaned.',
			'icon'           => 'dashicons-clock',
			'script'         => 'sn-desktop-mode-widget-cron',
			// BUDGETED, not browser-measured: the health card measures 148 for a
			// dot row + a 2-row hairline list, and this is the same shape with one
			// extra 11px line when orphans exist. 170 with slack.
			'default_height' => 170,
		) ) );

		snt_os_register_widget( 'sn-quick-actions', array_merge( $sn_drag, array(
			'label'          => 'SN Quick Actions',
			'description'    => 'One-click purge, clear overrides, force update-check.',
			'icon'           => 'dashicons-controls-repeat',
			'script'         => 'sn-desktop-mode-widget-actions',
			// Was measured 242 for THREE full-width buttons + the footnote.
			// v11.29.0 adds the force update-check button the description has
			// always promised. 290 is DERIVED, not browser-measured: a button is
			// 8px padding x2 + 13px/1.2 text + 1px border x2 + 6px margin ~= 40px,
			// so 250 + 40 = 290. If it clips, measure rather than guess again.
			'default_height' => 290,
		) ) );

		// v2.1.0: RSS Subscribers widget — surfaces RSS feed activity that
		// was previously buried under S&N → RSS tab + a single line on the
		// SN Dashboard tab. At-a-glance subscriber growth on the desktop.
		snt_os_register_widget( 'sn-rss-subscribers', array_merge( $sn_drag, array(
			'label'          => 'SN RSS Subscribers',
			'description'    => 'Unique feed subscribers over 24h / 7d / 30d.',
			'icon'           => 'dashicons-rss',
			'script'         => 'sn-desktop-mode-widget-rss',
			// Measured 207: the last-request line, the 24h/7d/30d grid, the
			// link. Fixed three rows — this card's height never moves.
			'default_height' => 220,
		) ) );

		// v9.78.0: SN Anchors — the one glanceable that had no mirror.
		// Pending Notes with their live in-flight Bitcoin tx (N/6, captured
		// by the worker's pending callbacks) + a Sweep action; idles at an
		// honest "N notes anchored". Fetch-on-render via the anchor-status
		// ability — the aggregate walks every Note's chain meta, which must
		// never ride a page-load localize.
		snt_os_register_widget( 'sn-anchors', array_merge( $sn_drag, array(
			'label'          => 'SN Anchors',
			'description'    => 'Provenance anchor status: pending Bitcoin confirmations + on-demand sweep.',
			'icon'           => 'dashicons-admin-links',
			'script'         => 'sn-desktop-mode-widget-anchors',
			// Measured 167 idle ("30 of 30 notes anchored" + Sweep), 194 with
			// two pending rows. Sized for idle — the state it holds most days.
			'default_height' => 180,
		) ) );

		// v10.1.0: the machine half of the audience. Human readership is
		// sn-site-views' job (beacons); this reads the edge sensor, and the two
		// are never summed.
		snt_os_register_widget( 'sn-machine-readers', array_merge( $sn_drag, array(
			'label'          => 'SN Machine Readers',
			'description'    => 'AI crawler readership: top families, purposes, declared AI-training reads.',
			'icon'           => 'dashicons-visibility',
			'script'         => 'sn-desktop-mode-widget-machine-readers',
			// BUDGETED 560, not browser-measured — the old measured 508 covered
			// headline + families + AI-training + 3 sensor rows. Sensor rows
			// are gone (version lives on Deploy Status); Purposes adds a
			// heading + ≤4 rows (≤5). 508 − 3×22 + ≤5×22 = 552, rounded up
			// with slack. `ai_surfaces` is still variable-length.
			'default_height' => 560,
		) ) );
	}
}, 6 );
