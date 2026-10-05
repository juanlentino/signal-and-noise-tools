<?php
/**
 * Signal & Noise Tools — the desktop widgets (six: twelve folded to six by
 * owner decision, 2026-10-04).
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

		// 2026-10-04: TWELVE CARDS FOLDED TO SIX, owner-approved. Three cards
		// absorbed their neighbors and kept their ids, so each keeps its slot
		// (and its saved size) on the owner's desktop: sn-site-views is SN
		// Traffic (+ SN Audience, SN RSS Subscribers), sn-health is SN Systems
		// (+ SN Uptime, SN Cron and Quick Actions' Clear DB overrides), sn-anchors
		// is SN Provenance (+ SN Machine Readers). Deploy Status took Quick
		// Actions' Check for updates. Retired ids: sn-audience,
		// sn-rss-subscribers, sn-uptime, sn-cron, sn-quick-actions,
		// sn-machine-readers. A SAVED LAYOUT KEEPS THE OLD HEIGHT: the three
		// merged cards need one resize by hand; default_height applies only to a
		// card placed fresh.
		snt_os_register_widget( 'sn-site-views', array_merge( $sn_drag, array(
			'label'          => 'SN Traffic',
			'description'    => 'Views over 14 days with the trend, then who came and from where: countries, devices, sources, Hacker News, search, feed subscribers, top pages.',
			'icon'           => 'dashicons-chart-area',
			'script'         => 'sn-desktop-mode-widget-views',
			// BUDGETED 760, not browser-measured: the old card less its dropped
			// north-star rows (~360: headline, today row, sparkline, delta, top
			// pages, link) plus four groups at a hairline and a heading (~36)
			// each and ~20px a row (3 countries, 4 sources, 1 Hacker News story
			// at up to two lines, 3 one-line rows for devices, search and feed).
			// The body scrolls past it; measure live before trimming.
			// + This week (2026-10-04, the owner's pick: engaged readers, DOI
			// downloads, inquiries): a hairline, a heading and three rows, ~96.
			// A Campaigns group adds ~100 only when a tagged link was followed.
			// + the reach row (a hairline and one row, ~30).
			// 21.9.1: +60 for the busiest state (Campaigns: 2 rows and "+N more").
			'default_height' => 950,
		) ) );

		snt_os_register_widget( 'sn-reading', array_merge( $sn_drag, array(
			'label'          => 'SN Reading',
			'description'    => 'What readers do here: scroll depth, time per view, visits, custom events, Core Web Vitals.',
			'icon'           => 'dashicons-book-alt',
			'script'         => 'sn-desktop-mode-widget-groups',
			// BUDGETED 470, not browser-measured: a window line, four groups
			// (heading + up to 5/6/4/3 rows at ~22px) and the link.
			'default_height' => 575,
		) ) );

		// 15.8.0: SN Queue — "is the queue fed, and what goes out next". The
		// next note as the headline, the depth line (N scheduled · runs to
		// Dec 27), three more, the last three published. Fetch-on-render via
		// the content-queue ability; labels are the site timezone's.
		snt_os_register_widget( 'sn-queue', array_merge( $sn_drag, array(
			'label'          => 'SN Queue',
			'description'    => 'The next scheduled note, how deep the queue runs, and the last three published.',
			'icon'           => 'dashicons-calendar-alt',
			'script'         => 'sn-desktop-mode-widget-queue',
			// Measured 365 (15.8.1, live at the docked width with the real
			// queue: a two-line headline, the depth line, two headings, six
			// rows), rounded up to the next 10 with slack. The 15.8.0 budget
			// of 300 undershot the two-line title.
			'default_height' => 380,
		) ) );

		snt_os_register_widget( 'sn-health', array_merge( $sn_drag, array(
			'label'          => 'SN Systems',
			'description'    => 'Uptime, content-health checks and scheduled cron in one line when all is well, and what is wrong when not. Clear DB overrides.',
			'icon'           => 'dashicons-shield-alt',
			'script'         => 'sn-desktop-mode-widget-health',
			// BUDGETED 360, not browser-measured, for the state it idles in (all
			// clear): the verdict line, three sections at a hairline, a heading
			// and one row (~52 each), the full-width button (~44), the link,
			// chrome and padding = ~350. A flagged check or a down monitor adds
			// ~20 a row and the body scrolls. + the uptime row's mean uptime and
			// response time, which wraps it to a second line (~20).
			// 21.9.1: +60 for the busiest state (2 checks, 2 could-not-run, 2 monitors down, each with "+N more").
			'default_height' => 580, // 2026-10-05: + Edge and Cache sections, the cron-day row, incidents and slowest (~140).
		) ) );

		snt_os_register_widget( 'sn-deploy-status', array_merge( $sn_drag, array(
			'label'          => 'SN Deploy Status',
			'description'    => 'Theme, plugin, and worker versions with last deploy time, and a forced update check.',
			'icon'           => 'dashicons-update',
			'script'         => 'sn-desktop-mode-widget',
			// v11.11.2: BUDGETED 310, not browser-measured — the old measured
			// 192 covered the two-row grid; five worker rows add ~22px each.
			// + the Check for updates button from Quick Actions (~40px, the
			// figure that card's own budget used per button). If the owner
			// reports clipping, rebuild the Trap-11 measurement recipe rather
			// than guessing again.
			'default_height' => 350,
		) ) );

		// v9.78.0: SN Anchors, now SN Provenance: pending Notes with their live
		// in-flight Bitcoin tx (N/6, captured by the worker's pending
		// callbacks) + a Sweep action; idles at an honest "N notes anchored".
		// Fetch-on-render via the anchor-status ability — the aggregate walks
		// every Note's chain meta, which must never ride a page-load localize.
		// The machine readers ride under it (the edge sensor, never summed with
		// SN Traffic's beacon readership).
		snt_os_register_widget( 'sn-anchors', array_merge( $sn_drag, array(
			'label'          => 'SN Provenance',
			'description'    => 'Anchor status with an on-demand sweep, the Internet Archive run, and who reads by machine: identity and declared AI-training reads.',
			'icon'           => 'dashicons-admin-links',
			'script'         => 'sn-desktop-mode-widget-anchors',
			// Measured 167 idle ("30 of 30 notes anchored" + Sweep), 194 with
			// two pending rows. Sized for idle — the state it holds most days.
			// 21.1.0: + one Internet Archive line (two when it wraps); the capture
			// counts joined it later and it now wraps to three. BUDGETED.
			// + the machine readers (a hairline, a heading, up to five rows:
			// ~136) and the second link wrapping the action row (~28). BUDGETED.
			// + the rights-files row under the AI-training reads (~20) and the
			// top crawler family row (~20).
			// 21.9.1: +60 for the busiest state (2 recording, 2 pending, each with "+N more").
			'default_height' => 620, // 2026-10-05: + signatures, rights evidence, last posted, DOIs (~100).
		) ) );
	}
}, 6 );
