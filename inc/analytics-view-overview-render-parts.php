<?php
/**
 * Signal & Noise Tools: Analytics Overview, the Overview view body, snt_analytics_render_view_overview(), one function that cannot split without editing it.
 *
 * Split from inc/analytics-view-overview.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Overview view body — dispatched from snt_analytics_render_dashboard()
 * under the SHARED header chrome. Layout (owner-approved): session quality
 * full-width → compact "Right now" → balanced bento (sources + campaigns left,
 * geography + devices right) → entry/exit pages paired.
 *
 * Every read below is a durable local table or a cron-warmed transient/option
 * — see the file header's load-cost contract. function_exists guards are
 * defence in depth for partial harnesses; production loads every module.
 *
 * v9.68.0 part 4: $range + $compare arrive from the dispatcher so the body can
 * (a) build doorway links that carry the window exactly as the tab strip does,
 * and (b) render change-vs-prior when the header's compare control is on. Both
 * default to the legacy landing ('7' / 'off') so every existing caller renders
 * byte-identically (test-pinned).
 *
 * @since 9.68.0
 * @param string     $from    Window start (Y-m-d).
 * @param string     $to      Window end (Y-m-d).
 * @param string     $class   Traffic class.
 * @param int|string $range   Active range token (doorway param carry).
 * @param string     $compare Compare mode: 'prev' | 'yoy' | 'off' (default).
 */
function snt_analytics_render_view_overview( $from, $to, $class, $range = '7', $compare = 'off' ) {
	// ── PART B frame: mode + prior window derive EXACTLY as the shared
	// Overview card's one comparison frame does (inc/analytics-header-region.php
	// v9.38.0 D2): the same sn_compare vocabulary, the same range=all gate, and
	// the SAME snt_analytics_compare_window() date math — never reimplemented.
	// The prior-window READS stay durable-table-only (the load-cost rule) and
	// ride the same guarded-read bracket as the current reads.
	$compare    = (string) $compare;
	$compare_on = ( 'off' !== $compare && 'all' !== (string) $range
		&& function_exists( 'snt_analytics_compare_window' )
		&& function_exists( 'sn_analytics_delta' ) );
	$cwin        = $compare_on ? snt_analytics_compare_window( $from, $to, $compare ) : array( '', '' );
	$basis_label = ( 'yoy' === $compare )
		? __( 'same period last year', 'signal-and-noise-tools' )
		: __( 'previous period', 'signal-and-noise-tools' );

	// ── PART C frame (v9.69.0): the attention window is ALWAYS the adjacent
	// prev window, whatever the compare control says — signals run on EVERY
	// render, compare Off included (the feature's point; the compare control
	// governs only the visible chips). range=all has no adjacent window (the
	// shared card's exact gate), so attention is off there — and so is "Right
	// now" always: an instantaneous reading has no prior period.
	$attn_on = ( 'all' !== (string) $range && function_exists( 'snt_analytics_compare_window' ) );
	$awin    = $attn_on ? snt_analytics_compare_window( $from, $to, 'prev' ) : array( '', '' );

	// ── Session quality reads: the header window (KPIs) + the 8-week trend
	// window anchored at $to + (attention) the prev window; in prev compare
	// mode the chips REUSE the attention read — one query serves both — while
	// yoy adds its own chip-basis read. The attention prior read runs whenever
	// the CURRENT read SUCCEEDED — [] included (review r1 F1: an empty window
	// is a real 0 and must be judged for total collapse); only a FAILED
	// current read (unknown — no real 0 to claim) skips it. Compare chips
	// still need visible rows ($sess_ok).
	$has_rollup = function_exists( 'sn_session_rollup_read' );
	$range_rows = $has_rollup ? sn_session_rollup_read( $from, $to, $class ) : false;
	$t8_from    = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( SN_OVERVIEW_TREND_WEEKS * 7 - 1 ) * DAY_IN_SECONDS );
	$trend_rows = $has_rollup ? sn_session_rollup_read( $t8_from, $to, $class ) : false;
	$sess_ok    = ( $has_rollup && is_array( $range_rows ) && array() !== $range_rows );
	$sig_sess   = ( $attn_on && $has_rollup && is_array( $range_rows ) ) ? sn_session_rollup_read( $awin[0], $awin[1], $class ) : false; // false = never attempted; null = the read FAILED.
	$prior_rows = false;
	if ( $compare_on && $sess_ok ) {
		$prior_rows = ( 'prev' === $compare && false !== $sig_sess )
			? $sig_sess
			: sn_session_rollup_read( $cwin[0], $cwin[1], $class );
	}
	$sq_signal = array( 'state' => 'none', 'fact' => '' );
	$sq_synth  = false;
	if ( false !== $sig_sess ) {
		$cur_kpis = snt_analytics_overview_session_kpis( $range_rows );
		// Track the synthesis (converged review, LOW): null KPIs can arrive from
		// rows-[] OR from zero-visit rows (the rollup writes a per-day row for
		// every class even at 0 sessions) — rows-non-empty ($sess_ok) alone
		// cannot tell the second shape from a healthy window, but the renderer
		// folds on BOTH (its own null-KPIs gate), so a collapse flag from either
		// must stay strip-only: no anchor to a folded panel.
		$sq_synth = ( null === $cur_kpis );
		if ( null === $cur_kpis ) {
			// An EMPTY current rollup window (the read succeeded — $sig_sess only
			// exists when $range_rows is an array) is an ANSWER: zero sessions,
			// not a missing surface. Synthesize the zero shape so the volume
			// signal can flag a TOTAL collapse (40 → 0 must out-flag 40 → 11,
			// review r1 F1). Every ratio stays un-judged: min(0, prior) can
			// never reach the session floor.
			$cur_kpis = array( 'sessions' => 0, 'bounce_pct' => 0.0, 'ppv' => 0.0, 'median_dur' => 0 );
		}
		$sq_signal = snt_analytics_attn_session_signal(
			$cur_kpis,
			is_array( $sig_sess ) ? snt_analytics_overview_session_kpis( $sig_sess ) : null,
			null === $sig_sess
		);
	}

	// ── Mini reads: current window (top-N), then the attention prev window
	// (depth SN_OVERVIEW_PRIOR_LIMIT, the same guarded bracket), then the
	// compare-chip basis (reused in prev mode; its own read in yoy). Since
	// v9.68.1 the accessors self-report a failed read as null ([] = empty
	// window) and snt_analytics_overview_read_guarded() resolves that verdict.
	// The attention prior read runs whenever the CURRENT read succeeded — []
	// included (review r1 F1: an empty window is a real 0, judged for total
	// collapse); only a FAILED current read (unknown) skips it.
	$sources     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $from, $to, $class, 5 ) : array();
	} );
	$sources_sig = ( $attn_on && ! $sources['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$sources_prior = null;
	if ( $compare_on && array() !== $sources['rows'] ) {
		$sources_prior = ( 'prev' === $compare && null !== $sources_sig ) ? $sources_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$campaigns     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $from, $to, $class, 5 ) : array();
	} );
	$campaigns_sig = ( $attn_on && ! $campaigns['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$campaigns_prior = null;
	if ( $compare_on && array() !== $campaigns['rows'] ) {
		$campaigns_prior = ( 'prev' === $compare && null !== $campaigns_sig ) ? $campaigns_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$countries     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $from, $to, $class, 5 ) : array();
	} );
	$countries_sig = ( $attn_on && ! $countries['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$countries_prior = null;
	if ( $compare_on && array() !== $countries['rows'] ) {
		$countries_prior = ( 'prev' === $compare && null !== $countries_sig ) ? $countries_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'country', $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$devices     = snt_analytics_overview_read_guarded( static function () use ( $from, $to, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $from, $to, $class, 5 ) : array();
	} );
	$devices_sig = ( $attn_on && ! $devices['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin, $class ) {
		return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $awin[0], $awin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$devices_prior = null;
	if ( $compare_on && array() !== $devices['rows'] ) {
		$devices_prior = ( 'prev' === $compare && null !== $devices_sig ) ? $devices_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin, $class ) {
			return function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( 'device', $cwin[0], $cwin[1], $class, SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}

	// ── Entry + exit pages (read before render since v9.69.0 — their signals
	// join the same roll-up): the durable pageroles rollup, human-only (their
	// rollup carries no class column; the header meta says so).
	$entries     = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $from, $to, 10 ) : array();
	} );
	$entries_sig = ( $attn_on && ! $entries['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin ) {
		return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $awin[0], $awin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$entries_prior = null;
	if ( $compare_on && array() !== $entries['rows'] ) {
		$entries_prior = ( 'prev' === $compare && null !== $entries_sig ) ? $entries_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin ) {
			return function_exists( 'sn_analytics_top_entry_pages' ) ? sn_analytics_top_entry_pages( $cwin[0], $cwin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}
	$exits     = snt_analytics_overview_read_guarded( static function () use ( $from, $to ) {
		return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $from, $to, 10 ) : array();
	} );
	$exits_sig = ( $attn_on && ! $exits['failed'] ) ? snt_analytics_overview_read_guarded( static function () use ( $awin ) {
		return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $awin[0], $awin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
	} ) : null;
	$exits_prior = null;
	if ( $compare_on && array() !== $exits['rows'] ) {
		$exits_prior = ( 'prev' === $compare && null !== $exits_sig ) ? $exits_sig : snt_analytics_overview_read_guarded( static function () use ( $cwin ) {
			return function_exists( 'sn_analytics_top_exit_pages' ) ? sn_analytics_top_exit_pages( $cwin[0], $cwin[1], SN_OVERVIEW_PRIOR_LIMIT ) : array();
		} );
	}

	$src_cmp = snt_analytics_overview_row_deltas( $sources['rows'], $sources_prior, 'value' );
	$utm_cmp = snt_analytics_overview_row_deltas( $campaigns['rows'], $campaigns_prior, 'value' );
	$geo_cmp = snt_analytics_overview_row_deltas( $countries['rows'], $countries_prior, 'value' );
	$dev_cmp = snt_analytics_overview_row_deltas( $devices['rows'], $devices_prior, 'value' );
	$ent_cmp = snt_analytics_overview_row_deltas( $entries['rows'], $entries_prior, 'path' );
	$ext_cmp = snt_analytics_overview_row_deltas( $exits['rows'], $exits_prior, 'path' );

	// ── The attention roll-up, canonical panel order. 'unknown' (a FAILED
	// prior read) contributes nothing: no chip, no strip mention — and no
	// false "all calm" either; unknown renders as silence (see file header).
	// Review r1 F1: a flag from a FOLDED (empty-current) panel is STRIP-ONLY —
	// no panel surface exists to anchor, promote, or chip — marked by an empty
	// anchor. The prior depth cap rides into every table signal so absence
	// from a truncated read is bounded, never read as a real 0 (review r1 F4).
	$flags = array();
	if ( 'notable' === $sq_signal['state'] ) {
		$flags['quality'] = array( 'label' => __( 'Session quality', 'signal-and-noise-tools' ), 'anchor' => ( $sess_ok && ! $sq_synth ) ? 'sn-ov-quality' : '', 'fact' => $sq_signal['fact'] );
	}
	foreach ( array(
		'sources'   => array( __( 'Top sources', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $sources['rows'], $sources_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $sources['rows'] ),
		'campaigns' => array( __( 'Campaigns (UTM)', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $campaigns['rows'], $campaigns_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $campaigns['rows'] ),
		'geography' => array( __( 'Geography', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $countries['rows'], $countries_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $countries['rows'] ),
		'devices'   => array( __( 'Devices', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $devices['rows'], $devices_sig, 'value', 5, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $devices['rows'] ),
		'entry'     => array( __( 'Entry pages', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $entries['rows'], $entries_sig, 'path', 10, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $entries['rows'] ),
		'exit'      => array( __( 'Exit pages', 'signal-and-noise-tools' ), snt_analytics_attn_resolve_table( $exits['rows'], $exits_sig, 'path', 10, SN_OVERVIEW_PRIOR_LIMIT ), array() !== $exits['rows'] ),
	) as $slug => $spec ) {
		if ( 'notable' === $spec[1]['state'] ) {
			$flags[ $slug ] = array( 'label' => $spec[0], 'anchor' => $spec[2] ? 'sn-ov-' . $slug : '', 'fact' => $spec[1]['fact'] );
		}
	}
	$quality_anchored = isset( $flags['quality'] ) && '' !== $flags['quality']['anchor'];
	$entry_anchored   = isset( $flags['entry'] ) && '' !== $flags['entry']['anchor'];
	$exit_anchored    = isset( $flags['exit'] ) && '' !== $flags['exit']['anchor'];

	// ── The strip: one triage line at the very top of the body, in-page
	// anchor links to the flagged panels (anchor-less flags — folded panels,
	// total collapse — render as plain flags). No flags → no strip at all.
	if ( array() !== $flags ) {
		snt_analytics_attn_render_strip( array_values( $flags ) );
	}

	// ── Session quality: ALWAYS first, flagged or not — flagging only gains
	// it the chip + anchor, never a new position (and a folded panel gains
	// neither: its collapse flag lives in the strip alone).
	if ( $quality_anchored ) {
		echo '<div class="sn-an-attn-anchor" id="sn-ov-quality">';
	}
	snt_analytics_render_overview_session_quality( $range_rows, $trend_rows, $t8_from, $to, $prior_rows, array(
		'basis_label' => $basis_label,
		'doorway'     => snt_analytics_overview_tab_doorway( 'visits', __( 'Sessions', 'signal-and-noise-tools' ), $range, $class, $from, $to ),
		'attn_chip'   => $quality_anchored,
	) );
	if ( $quality_anchored ) {
		echo '</div>';
	}

	// ── Mini renderers, defined ONCE and placed by the promotion geometry: a
	// closure per panel so the promoted and bento placements emit byte-identical
	// panel markup — promotion changes position, never content. PART A doorways:
	// sources/entry/exit all open the Content tab (one href, built once); the
	// other minis each open their own tab.
	$doorway_content = snt_analytics_overview_tab_doorway( 'content', __( 'Content', 'signal-and-noise-tools' ), $range, $class, $from, $to );
	$attn_meta       = static function ( $chip, $meta ) {
		return ( $chip ? snt_analytics_attn_chip() . ' · ' : '' ) . $meta;
	};
	$mini_render = array(
		'sources'   => function ( $chip ) use ( $sources, $src_cmp, $doorway_content, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Top sources', 'signal-and-noise-tools' ),
				$sources['rows'],
				$sources['failed']
					? snt_an_read_failed_copy( __( 'The durable referrer rollup', 'signal-and-noise-tools' ) )
					: __( 'No referrer rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, $doorway_content ),
					'deltas'      => $src_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $src_cmp['state'] ),
				)
			);
		},
		'campaigns' => function ( $chip ) use ( $campaigns, $utm_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Campaigns (UTM)', 'signal-and-noise-tools' ),
				$campaigns['rows'],
				$campaigns['failed']
					? snt_an_read_failed_copy( __( 'The durable UTM rollup', 'signal-and-noise-tools' ) )
					: __( 'No UTM-tagged traffic in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'campaigns', __( 'Campaigns', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $utm_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $utm_cmp['state'] ),
				)
			);
		},
		'geography' => function ( $chip ) use ( $countries, $geo_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Geography', 'signal-and-noise-tools' ),
				$countries['rows'],
				$countries['failed']
					? snt_an_read_failed_copy( __( 'The durable country rollup', 'signal-and-noise-tools' ) )
					: __( 'No country rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'geography', __( 'Geography', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $geo_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $geo_cmp['state'] ),
				)
			);
		},
		'devices'   => function ( $chip ) use ( $devices, $dev_cmp, $range, $class, $from, $to, $attn_meta ) {
			snt_analytics_render_dim_table(
				__( 'Devices', 'signal-and-noise-tools' ),
				$devices['rows'],
				$devices['failed']
					? snt_an_read_failed_copy( __( 'The durable device rollup', 'signal-and-noise-tools' ) )
					: __( 'No device rows in the durable rollup for this range yet.', 'signal-and-noise-tools' ),
				array(),
				'',
				5,
				array(
					'header_meta' => $attn_meta( $chip, snt_analytics_overview_tab_doorway( 'technology', __( 'Technology', 'signal-and-noise-tools' ), $range, $class, $from, $to ) ),
					'deltas'      => $dev_cmp['deltas'],
					'prior_note'  => snt_analytics_overview_prior_note_copy( $dev_cmp['state'] ),
				)
			);
		},
	);

	// ── Entry + exit as ONE placeable unit (they promote as a PAIR if either
	// flags — the owner-approved geometry). Same null-verdict resolution as
	// the bento: a failed read (accessor null) folds as "could not be read".
	$pair_render = function () use ( $entries, $exits, $ent_cmp, $ext_cmp, $doorway_content, $entry_anchored, $exit_anchored, $attn_meta ) {
		echo '<div class="sn-an-grid sn-an-overview-pair">';
		if ( $entries['failed'] ) {
			snt_an_note_empty( __( 'Entry pages', 'signal-and-noise-tools' ), snt_an_read_failed_copy( __( 'The durable entry-pages rollup', 'signal-and-noise-tools' ) ) );
		} else {
			if ( $entry_anchored ) {
				echo '<div class="sn-an-attn-anchor" id="sn-ov-entry">';
			}
			snt_analytics_render_pageroles_table(
				$entries['rows'],
				'entry',
				$attn_meta( $entry_anchored, __( 'human traffic · durable rollup', 'signal-and-noise-tools' ) . ' · ' . $doorway_content ),
				array(
					'deltas'     => $ent_cmp['deltas'],
					'prior_note' => snt_analytics_overview_prior_note_copy( $ent_cmp['state'] ),
				)
			);
			if ( $entry_anchored ) {
				echo '</div>';
			}
		}
		if ( $exits['failed'] ) {
			snt_an_note_empty( __( 'Exit pages', 'signal-and-noise-tools' ), snt_an_read_failed_copy( __( 'The durable exit-pages rollup', 'signal-and-noise-tools' ) ) );
		} else {
			if ( $exit_anchored ) {
				echo '<div class="sn-an-attn-anchor" id="sn-ov-exit">';
			}
			snt_analytics_render_pageroles_table(
				$exits['rows'],
				'exit',
				$attn_meta( $exit_anchored, __( 'human traffic · nightly session bridge', 'signal-and-noise-tools' ) . ' · ' . $doorway_content ),
				array(
					'deltas'     => $ext_cmp['deltas'],
					'prior_note' => snt_analytics_overview_prior_note_copy( $ext_cmp['state'] ),
				)
			);
			if ( $exit_anchored ) {
				echo '</div>';
			}
		}
		echo '</div>';
	};

	// ── Promotion (the approved AFTER geometry, generalized): flagged minis
	// leave the bento for FULL WIDTH directly beneath Session quality, in
	// canonical relative order; the entry/exit pair promotes as a unit.
	// Strip-only flags (empty anchor — folded panels) never promote: there is
	// no panel markup to move (review r1 F1).
	$mini_order = array( 'sources', 'campaigns', 'geography', 'devices' );
	foreach ( $mini_order as $slug ) {
		if ( isset( $flags[ $slug ] ) && '' !== $flags[ $slug ]['anchor'] ) {
			echo '<div class="sn-an-attn-anchor" id="sn-ov-' . esc_attr( $slug ) . '">';
			$mini_render[ $slug ]( true );
			echo '</div>';
		}
	}
	$pair_promoted = ( $entry_anchored || $exit_anchored );
	if ( $pair_promoted ) {
		$pair_render();
	}

	// ── Right now: the cron-warmed transient pair (realtime is class-aware;
	// views-today is human-only by construction and its card says so). Never
	// promotes: instantaneous — it cannot be notable vs a prior period.
	snt_analytics_render_overview_rightnow(
		function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( $class ) : null,
		function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null,
		$class
	);

	// ── The bento: the UNPROMOTED minis, re-packed into the standard two
	// columns (first half left, rest right). With all four unpromoted this is
	// byte-identical to the v9.68.1 layout — sources + campaigns left,
	// geography + devices right (the quiet-week shield). A strip-only flag
	// (folded panel) keeps its slot: it emits nothing here anyway, and
	// excluding it would silently shift the quiet panels' packing.
	$bento = array_values( array_filter( $mini_order, static function ( $slug ) use ( $flags ) {
		return ! isset( $flags[ $slug ] ) || '' === $flags[ $slug ]['anchor'];
	} ) );
	if ( array() !== $bento ) {
		$left  = array_slice( $bento, 0, (int) ceil( count( $bento ) / 2 ) );
		$right = array_slice( $bento, count( $left ) );
		echo '<div class="sn-an-overview-bento">';
		echo '<div class="sn-an-bento-col">';
		foreach ( $left as $slug ) {
			$mini_render[ $slug ]( false );
		}
		echo '</div>';
		if ( array() !== $right ) {
			echo '<div class="sn-an-bento-col">';
			foreach ( $right as $slug ) {
				$mini_render[ $slug ]( false );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// ── Entry + exit in their standard spot when neither flags.
	if ( ! $pair_promoted ) {
		$pair_render();
	}

	snt_an_flush_empty_fold();
}

