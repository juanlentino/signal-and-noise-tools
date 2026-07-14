<?php
/**
 * Signal & Noise Tools — Analytics panel primitive (v8.5.0).
 *
 * The ONE place that renders panel chrome for the Dashboard → Analytics page.
 * Every panel is a REAL native .postbox (owner: "keep postbox feel as much as
 * you can") with the crisp-console token treatment applied via the
 * .sn-an-postbox marker in assets/analytics/analytics-admin.css. Renderers must
 * never echo postbox markup themselves — that inline duplication across ~11
 * sites is exactly how the pre-v8.5.0 page drifted into a patchwork.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// snt_analytics_smooth_path (trend). Guarded — a few CLI fixtures declare their
// own stand-in for this fn before requiring this file (pre-dating this require);
// an unconditional require_once here would redeclare it and fatal (see
// tests/analytics-header-region.php, tests/analytics-posts-admin.php).
if ( ! function_exists( 'snt_analytics_smooth_path' ) ) {
	require_once __DIR__ . '/analytics-render-helpers.php';
}

/**
 * Open a panel: postbox shell + header. Pair with snt_an_panel_close().
 *
 * @param string $title Panel title (plain text; escaped here).
 * @param array  $args  {
 *     @type string $inside_class Body class. Default 'inside'.
 *     @type string $panel_class  Extra classes on the .postbox.
 *     @type string $header_meta  Small muted note right of the title (kses'd).
 *     @type bool   $collapsible  Adds the toggle button + marker. Default false.
 *     @type bool   $collapsed    Start collapsed (only with collapsible). Default false.
 * }
 */
function snt_an_panel_open( $title, $args = array() ) {
	$title        = (string) $title;
	$panel_class  = trim( 'postbox sn-an-postbox ' . (string) ( $args['panel_class'] ?? '' ) );
	$inside_class = (string) ( $args['inside_class'] ?? 'inside' );
	$collapsible  = ! empty( $args['collapsible'] );
	$collapsed    = $collapsible && ! empty( $args['collapsed'] );
	if ( $collapsed ) {
		$panel_class .= ' sn-an-collapsed';
	}

	echo '<div class="' . esc_attr( $panel_class ) . '"'
		. ( $collapsible ? ' data-sn-an-collapsible="' . esc_attr( sanitize_title( $title ) ) . '"' : '' )
		. '>';
	echo '<div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2>';
	if ( ! empty( $args['header_meta'] ) ) {
		echo '<span class="sn-an-head-meta">' . wp_kses_post( (string) $args['header_meta'] ) . '</span>';
	}
	if ( $collapsible ) {
		echo '<button type="button" class="sn-an-toggle" aria-expanded="' . ( $collapsed ? 'false' : 'true' ) . '">'
			. '<span class="screen-reader-text">' . esc_html__( 'Toggle panel', 'signal-and-noise-tools' ) . '</span>'
			. '</button>';
	}
	echo '</div>';
	echo '<div class="' . esc_attr( $inside_class ) . '">';
}

/**
 * Close the panel opened by snt_an_panel_open().
 */
function snt_an_panel_close() {
	echo '</div></div>';
}

/**
 * Render an interpretation callout inside a panel body: a short "read" of the
 * data, drawn ONLY when there is something to say. The inverse of the empty-fold
 * collector: draw-on-content, skip on null/empty. The sentence is plain text (the
 * resolver never emits markup) so it escapes with esc_html per
 * WORDPRESS-REFERENCE.md section 7.
 *
 * @param string|null $text One-sentence read, or null / '' to render nothing.
 * @return void
 * @since 9.4.0
 */
function snt_an_annotation( $text ) {
	$text = is_string( $text ) ? trim( $text ) : '';
	if ( '' === $text ) {
		return;
	}
	echo '<div class="sn-an-note"><span class="sn-an-note-label">'
		. esc_html__( 'Read', 'signal-and-noise-tools' ) . '</span> '
		. '<span class="sn-an-note-body">' . esc_html( $text ) . '</span></div>';
}

/**
 * Open a row-clamp region around a long table. Full rows stay in the DOM
 * (already fetched — clamping is display-only, zero extra queries); CSS hides
 * rows past $visible; assets/admin.js toggles .sn-an-clamp--open.
 *
 * @param int $total   Total rows the table will render.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_open( $total, $visible = 5 ) {
	echo '<div class="sn-an-clamp sn-an-clamp--' . (int) $visible . '" data-sn-an-total="' . (int) $total . '">';
}

/**
 * Close the clamp region; emits the "View all N" toggle only when needed.
 *
 * @param int $total   Total rows rendered.
 * @param int $visible Rows visible while clamped. Default 5.
 */
function snt_an_clamp_close( $total, $visible = 5 ) {
	if ( (int) $total > (int) $visible ) {
		echo '<button type="button" class="sn-an-viewall">'
			/* translators: %d is the total number of items */
			. esc_html( sprintf( __( 'View all %d', 'signal-and-noise-tools' ), (int) $total ) )
			. '</button>';
	}
	echo '</div>';
}

/**
 * Record a panel that had no data this range instead of drawing an empty card.
 * Collected per request; emitted by snt_an_flush_empty_fold() as ONE muted line
 * (no $why given for any panel) or ONE native <details> (at least one $why given)
 * so the crafted diagnostic strings are reachable on demand instead of dropped.
 *
 * THE CONVENTION (D4 §4): a dataless VIEW-BODY panel never renders open — it
 * folds into its view's collector, with its diagnostic available behind the
 * fold. NAMED EXCEPTIONS that stay outside this convention: the Movers tile
 * empty state (inc/analytics-movers.php), Uptime's structural header-region
 * rail tiles, all of login-defense (D5), the dashboard tail hint
 * (inc/analytics-admin.php — view-level, not a panel), the headline band's
 * own copy, panels emptied by an ACTIVE user filter (the drill panel,
 * filtered event properties) — they stay open so the Clear affordance
 * survives — and snt_an_gate() itself (a persistent needs-setup notice, not a
 * range-empty data panel; folding it would bury a setup CTA in a muted line).
 *
 * @param string $title Panel title.
 * @param string $why   Optional diagnostic ("needs X", "no Y in range", …).
 *                       Default '' — panel stays summary-only in the fold.
 * @return void
 */
function snt_an_note_empty( $title, $why = '' ) {
	if ( ! isset( $GLOBALS['sn_an_empty_panels'] ) || ! is_array( $GLOBALS['sn_an_empty_panels'] ) ) {
		$GLOBALS['sn_an_empty_panels'] = array();
	}
	$GLOBALS['sn_an_empty_panels'][] = array(
		'title' => (string) $title,
		'why'   => (string) $why,
	);
}

/**
 * Emit the collected empty panels, then clear the collector. Emits nothing
 * when nothing was collected.
 *
 * No panel carries a $why: today's plain line, byte-identical —
 * <p class="sn-an-empty sn-an-empty-fold">No data in this range yet: A · B</p>
 *
 * At least one panel carries a $why: a native <details> so every crafted
 * diagnostic is reachable behind one click, without cluttering the fold for
 * panels that have nothing more to say than their title —
 * <details class="sn-an-empty-fold"><summary>No data in this range yet: A · B</summary>
 * <ul><li><strong>A</strong> — why</li></ul></details>
 * (panels without a $why still appear in the summary line, just not as an <li>).
 *
 * @return void
 */
function snt_an_flush_empty_fold() {
	$panels                        = isset( $GLOBALS['sn_an_empty_panels'] ) ? (array) $GLOBALS['sn_an_empty_panels'] : array();
	$GLOBALS['sn_an_empty_panels'] = array();
	if ( empty( $panels ) ) {
		return;
	}
	// Defensively normalize any legacy plain-string entries (third-party callers
	// that never adopted the $why arg) to the { title, why } shape.
	$panels = array_map(
		function ( $panel ) {
			return is_array( $panel ) ? $panel : array(
				'title' => (string) $panel,
				'why'   => '',
			);
		},
		$panels
	);

	$titles   = array_column( $panels, 'title' );
	$escaped  = array_map( 'esc_html', $titles );
	$summary  = esc_html__( 'No data in this range yet:', 'signal-and-noise-tools' ) . ' '
		. implode( ' &middot; ', $escaped ); // Each element esc_html'd above; the separator is a static entity.

	$with_why = array_filter(
		$panels,
		function ( $panel ) {
			return '' !== ( $panel['why'] ?? '' );
		}
	);

	if ( empty( $with_why ) ) {
		echo '<p class="sn-an-empty sn-an-empty-fold">' . $summary . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $summary built from esc_html'd fragments above.
		return;
	}

	echo '<details class="sn-an-empty-fold"><summary>' . $summary . '</summary><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $summary built from esc_html'd fragments above.
	foreach ( $with_why as $panel ) {
		echo '<li><strong>' . esc_html( $panel['title'] ) . '</strong> — ' . esc_html( $panel['why'] ) . '</li>';
	}
	echo '</ul></details>';
}

/**
 * Shared maturity tier badge (maturity I6, spec §11): ONE component so every
 * view names its tier identically. Whitelisted; an unknown tier renders
 * nothing — never guess a tier. Returns HTML built from escaped fragments;
 * callers may echo it raw or pass it through the panel primitive's header_meta.
 *
 * @param string $tier 'descriptive' | 'diagnostic' | 'predictive' | 'prescriptive'.
 * @return string
 */
function snt_analytics_tier_badge( $tier ) {
	$tiers = array(
		'descriptive'  => __( 'Descriptive', 'signal-and-noise-tools' ),
		'diagnostic'   => __( 'Diagnostic', 'signal-and-noise-tools' ),
		'predictive'   => __( 'Predictive', 'signal-and-noise-tools' ),
		'prescriptive' => __( 'Prescriptive', 'signal-and-noise-tools' ),
	);
	$key = (string) $tier;
	if ( ! isset( $tiers[ $key ] ) ) {
		return '';
	}
	return '<span class="sn-an-tier sn-an-tier--' . esc_attr( $key ) . '">' . esc_html( $tiers[ $key ] ) . '</span>';
}

/**
 * THE delta badge (v9.40.0 D4): one renderer, two variants. The kpi variant's
 * basis label follows the resolved comparison frame — see analytics-header-region.php
 * (v9.38.0 D2 contract).
 * 'kpi' = the KPI-strip style (.sn-kpi-delta + prior-period tooltip);
 * 'inline' (default) = the legacy annotation style (.sn-an-delta--dir).
 * Colors come from --sn-an-up/--sn-an-down only. Silent no-op on bad input.
 *
 * @param array|null $delta {pct:?int, dir:string, previous?:numeric}
 * @param array      $opts  {variant?:'inline'|'kpi', basis_label?:string}
 */
function snt_an_delta_badge( $delta, $opts = array() ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	if ( 'kpi' === ( $opts['variant'] ?? 'inline' ) ) {
		$cls        = 'up' === $dir ? 'sn-delta-up' : ( 'down' === $dir ? 'sn-delta-down' : 'sn-delta-flat' );
		$prev_title = '';
		if ( isset( $delta['previous'] ) && is_numeric( $delta['previous'] ) ) {
			$prev        = (float) $delta['previous'];
			$basis_label = (string) ( $opts['basis_label'] ?? '' );
			$prev_title  = ( '' !== $basis_label ? $basis_label : __( 'previous period', 'signal-and-noise-tools' ) ) . ': ' . number_format_i18n( $prev, ( $prev == (int) $prev ) ? 0 : 1 );
		}
		echo '<span class="sn-kpi-delta ' . esc_attr( $cls ) . '"'
			. ( '' !== $prev_title ? ' title="' . esc_attr( $prev_title ) . '"' : '' )
			. '><span class="sn-delta-arrow">' . esc_html( $arrow ) . '</span> ' . esc_html( $text ) . '</span>';
		return;
	}
	echo ' <span class="sn-an-delta sn-an-delta--' . esc_attr( $dir ) . '">' . esc_html( $arrow . ' ' . $text ) . '</span>';
}

/**
 * THE KPI-card row (v9.40.0 D4): the one loop behind the Overview strip and its
 * former clones (visits, posts hero, lifecycle glance, edge). Card keys:
 * l (label), n (value), promoted?, live?, delta? (badge array), sub? (flat
 * descriptor), sub_class? (CSS class for the sub descriptor; default
 * 'sn-delta-flat' — lets a clone color its text descriptor, e.g. sn-delta-down,
 * without needing a real {pct,dir} delta). Slot precedence live > delta > sub >
 * default. Malformed cards are skipped silently.
 *
 * @param array $cards
 * @param array $opts {empty_slot?:'no-change'|'omit', row_class?:string, basis_label?:string}
 */
function snt_an_kpi_row( $cards, $opts = array() ) {
	echo '<div class="sn-kpi-row' . ( '' !== (string) ( $opts['row_class'] ?? '' ) ? ' ' . esc_attr( (string) $opts['row_class'] ) : '' ) . '">';
	foreach ( (array) $cards as $c ) {
		if ( ! is_array( $c ) || ! isset( $c['l'], $c['n'] ) ) {
			continue;
		}
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( (string) $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( (string) $c['n'] ) . '</p>';
		if ( ! empty( $c['live'] ) ) {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</span>';
		} elseif ( ! empty( $c['delta'] ) ) {
			snt_an_delta_badge( $c['delta'], array( 'variant' => 'kpi', 'basis_label' => (string) ( $opts['basis_label'] ?? '' ) ) );
		} elseif ( isset( $c['sub'] ) && '' !== (string) $c['sub'] ) {
			echo '<span class="sn-kpi-delta ' . esc_attr( (string) ( $c['sub_class'] ?? 'sn-delta-flat' ) ) . '">' . esc_html( (string) $c['sub'] ) . '</span>';
		} elseif ( 'omit' !== ( $opts['empty_slot'] ?? 'no-change' ) ) {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'no change', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</div>';
	}
	echo '</div>';
}

/**
 * THE config/dormant gate (v9.40.0 D4): one bare-postbox notice for "this view
 * needs setup / has no substrate yet". Replaces the per-view hand-rolled gate
 * idioms (dashboard unconfigured, edge dormant, visits AE, posts/lifecycle).
 *
 * @param string $title     Panel title.
 * @param string $message   Gate copy (plain text; already translated).
 * @param string $cta_label Optional CTA text.
 * @param string $cta_url   Optional CTA href (both required to render the CTA).
 * @param array  $opts      { @type bool $cta_primary First-run gates: CTA keeps button-primary weight. Default false. }
 */
function snt_an_gate( $title, $message, $cta_label = '', $cta_url = '', $opts = array() ) {
	echo '<div class="postbox sn-an-gate"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside">';
	echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $message );
	if ( '' !== $cta_label && '' !== $cta_url ) {
		// cta_primary: first-run gates (the page's ONLY action) keep button-primary
		// weight per the house convention; routine dormant gates stay button-small.
		$cta_class = ! empty( $opts['cta_primary'] ) ? 'button button-primary' : 'button button-small';
		echo ' <a class="' . esc_attr( $cta_class ) . '" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>';
	}
	echo '</p></div></div>';
}

/**
 * THE trend renderer (D5 §3): one smooth-line SVG sparkline, geometry copied
 * byte-for-byte from the Overview canonical (snt_analytics_render_trend()).
 * Intended callers (Task 3 adopts; a caller that can't decompose cleanly stays
 * bespoke — parity beats purity):
 *  - snt_analytics_render_trend()          inc/analytics-render-overview.php
 *  - sn_login_defense_render_trend_chart() inc/login-defense-analytics.php
 *  - snt_analytics_render_bot_trend()      inc/analytics-render-quality.php
 *  - snt_analytics_render_post_trajectory() inc/analytics-posts-admin.php
 *    (dual-scale, age-based x-axis, no area/gradient/baseline — may stay
 *    bespoke if it doesn't decompose onto this single-area-fill shape).
 *
 * $series is a PLAIN NUMERIC ARRAY (not the copies' {day,views}-shaped rows) —
 * geometry is domain-agnostic; the caller extracts whichever field it means
 * (views, bot_pct, cumulative count, …) before calling, and supplies any axis
 * labels itself via $opts['axis']. Fewer than 2 points renders nothing (the
 * empty-fold, if any, is the CALLER's concern, per snt_an_note_empty above).
 * Callers also own their <2-point/1-point pre-processing: bot-trend pads a
 * 1-point series to a flat [v, v] before calling; the Overview canonical's n=1
 * degenerate sliver deliberately becomes nothing at adoption.
 *
 * @param array $series Numeric values, ascending, >= 2 points to render anything.
 * @param array $opts   {
 *     @type array  $overlay_series Optional second numeric series, rendered as a
 *                                  dashed overlay sharing this call's y-max (its
 *                                  own x-spacing, from its own point count).
 *     @type string $stroke         Main line + gradient hex. Default '#2271b1'
 *                                  (the Overview canonical's hardcoded color).
 *     @type string $head           Trend-head title text. Omit to skip the head row.
 *     @type string $meta           Trend-head meta text (only shown alongside $head).
 *     @type array  $axis           [start_label, end_label]. Omit to skip the axis row.
 *     @type string $id_suffix      Appended to the gradient id ('snSparkFill' + suffix).
 *                                  Forward-looking seam, not a live-bug fix: today's
 *                                  two 'snSparkFill' copies (Overview, login-defense)
 *                                  are mutually exclusive views behind the
 *                                  analytics-admin.php view switch and never co-render.
 *     @type string $wrap_attrs     PRE-ESCAPED attribute string appended inside the
 *                                  .sn-spark-wrap open tag (the canonical's brush
 *                                  data-attrs live on that element — the caller
 *                                  assembles them from esc_attr'd fragments exactly
 *                                  as inc/analytics-render-overview.php does today).
 *                                  Default '' = the bare wrap, byte-identical.
 *     @type string $aria_label     svg aria-label. Default '' = fall back to $head;
 *                                  both absent = no aria-label attribute at all
 *                                  (the headless trajectory copy's precedent).
 *     @type string $wrap_class     Outer wrapper class. Default 'sn-overview-trend'
 *                                  (bot-trend needs its CSS-load-bearing 'sn-an-bot-trend').
 *     @type string $svg_class      svg class. Default 'sn-spark' (bot-trend:
 *                                  'sn-an-bot-spark'). The inner .sn-spark-wrap is
 *                                  shared by every copy and stays fixed.
 * }
 */
function snt_an_trend_svg( $series, $opts = array() ) {
	if ( ! is_array( $series ) || count( $series ) < 2 ) {
		return;
	}
	$series = array_values( $series );
	$n      = count( $series );
	$w      = 600.0;
	$top    = 8.0;
	$base   = 78.0;

	$overlay = ( isset( $opts['overlay_series'] ) && is_array( $opts['overlay_series'] ) ) ? array_values( $opts['overlay_series'] ) : array();

	// The overlay shares this $max so both lines are on the same scale — an
	// overlay on its own scale would lie about relative volume (parity with
	// the Overview canonical's compare-series handling).
	$max = 1.0;
	foreach ( $series as $v ) {
		$max = max( $max, (float) $v );
	}
	foreach ( $overlay as $v ) {
		$max = max( $max, (float) $v );
	}

	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( $series as $i => $v ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (float) $v / $max ) * ( $base - $top ), 2 );
	}

	// Smooth line via the shared helper (clamped Catmull-Rom → bézier).
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ $n - 1 ];
	// Area = the smooth line dropped to the baseline and closed.
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	$stroke      = (string) ( $opts['stroke'] ?? '#2271b1' );
	$gradient_id = 'snSparkFill' . (string) ( $opts['id_suffix'] ?? '' );
	$head        = (string) ( $opts['head'] ?? '' );
	$meta        = (string) ( $opts['meta'] ?? '' );
	$axis        = ( isset( $opts['axis'] ) && is_array( $opts['axis'] ) && 2 === count( $opts['axis'] ) ) ? array_values( $opts['axis'] ) : array();
	$wrap_attrs  = (string) ( $opts['wrap_attrs'] ?? '' );
	$wrap_class  = (string) ( $opts['wrap_class'] ?? 'sn-overview-trend' );
	$svg_class   = (string) ( $opts['svg_class'] ?? 'sn-spark' );
	// Explicit aria wins; else fall back to the head; both absent → omit the
	// attribute entirely (the headless trajectory copy's precedent).
	$aria = (string) ( $opts['aria_label'] ?? '' );
	if ( '' === $aria ) {
		$aria = $head;
	}

	echo '<div class="' . esc_attr( $wrap_class ) . '">';
	if ( '' !== $head ) {
		echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html( $head ) . '</span>';
		if ( '' !== $meta ) {
			echo '<span class="sn-trend-meta">' . esc_html( $meta ) . '</span>';
		}
		echo '</div>';
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wrap_attrs is a pre-escaped attribute string assembled from esc_attr'd fragments at the caller (the canonical's brush-attr pattern).
	echo '<div class="sn-spark-wrap"' . ( '' !== $wrap_attrs ? ' ' . $wrap_attrs : '' ) . '>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- class + aria esc_attr'd, static SVG chrome otherwise.
	echo '<svg class="' . esc_attr( $svg_class ) . '" viewBox="0 0 600 84" preserveAspectRatio="none" role="img"' . ( '' !== $aria ? ' aria-label="' . esc_attr( $aria ) . '"' : '' ) . '>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gradient id + color esc_attr'd, static SVG chrome otherwise.
	echo '<defs><linearGradient id="' . esc_attr( $gradient_id ) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0.16"/><stop offset="55%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0.04"/><stop offset="100%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#' . esc_attr( $gradient_id ) . ')" stroke="none"/>';
	if ( count( $overlay ) > 1 ) {
		$cn  = count( $overlay );
		$cst = $w / ( $cn - 1 );
		$cpx = array();
		$cpy = array();
		foreach ( $overlay as $i => $v ) {
			$cpx[] = round( $i * $cst, 2 );
			$cpy[] = round( $base - ( (float) $v / $max ) * ( $base - $top ), 2 );
		}
		$cmp_d = snt_analytics_smooth_path( $cpx, $cpy, $top, $base );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
		echo '<path d="' . esc_attr( $cmp_d ) . '" fill="none" stroke="#a7aaad" stroke-width="2" stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	}
	// non-scaling-stroke keeps the line a crisp 2px regardless of the horizontal stretch (preserveAspectRatio=none).
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	if ( ! empty( $axis ) ) {
		echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $axis[0] ) . '</span><span>' . esc_html( (string) $axis[1] ) . '</span></div>';
	}
	echo '</div>';
}

/**
 * THE k/v table (D5 §4): one postbox table for "primary label + N numeric
 * columns" rows — the ranked/dimensional-breakdown shape shared by the edge
 * dim tables and login-defense's attacker top-tables. Domain-agnostic like
 * snt_an_trend_svg(): it never formats or translates a value itself — every
 * cell arrives as a PRE-FORMATTED string (number_format_i18n(), snt_edge_fmt_bytes(),
 * translated column labels, …), assembled by the caller. $cols is a flat,
 * ordered list of column headers (not assoc — neither adopter needs
 * per-column options beyond "numeric, right-aligned", which is implicit for
 * every column after the first): $cols[0] is the primary row-label column
 * (bold, class="column-primary"); every column after it is numeric
 * (class="num", the house right-align idiom). $rows is a list of rows, each
 * itself a plain list of cell strings aligned 1:1 with $cols.
 *
 * Adopters: snt_edge_render_dim() (inc/edge-admin.php) — forwards its long-dead
 * $empty diagnostic here for the first time — and
 * sn_login_defense_render_top_table() (inc/login-defense-analytics.php), which
 * picks up the standard postbox chrome (sn-an-postbox) it never had. NOT
 * adopted by the posts/lifecycle leaderboards (inc/analytics-posts-admin.php,
 * inc/analytics-render-tables.php) — those are bespoke ranked tables (extra
 * meta cells, sparkline cells, clamp regions) that don't decompose onto this
 * simple label+numeric-columns shape; recorded holdouts, not an oversight.
 *
 * @param string $title Panel title AND the empty-fold key (snt_an_note_empty).
 * @param array  $rows  List of rows; each row is a list of pre-formatted
 *                       string cell values, 1:1 with $cols.
 * @param array  $cols  Ordered column headers; $cols[0] = primary label
 *                       column, the rest are numeric (class="num").
 * @param array  $opts  {
 *     @type string $empty        Diagnostic why-text for the empty fold
 *                                 (forwarded verbatim to snt_an_note_empty()).
 *     @type string $header_meta  Forwarded to snt_an_panel_open() (small muted
 *                                 note right of the panel title). Unused by
 *                                 today's two adopters; kept as a passthrough
 *                                 seam since the primitive already supports it.
 *     @type bool   $data_colname Emit data-colname="<header>" on every <td>
 *                                 (the wp-list-table mobile-responsive
 *                                 convention). Default false. Only the edge
 *                                 dim tables carried this before adoption —
 *                                 login-defense's top tables never did, and
 *                                 stay byte-identical by leaving this off.
 * }
 * @since 9.41.0
 */
function snt_an_kv_table( $title, $rows, $cols, $opts = array() ) {
	if ( empty( $rows ) ) {
		snt_an_note_empty( $title, (string) ( $opts['empty'] ?? '' ) );
		return;
	}

	$cols          = array_values( (array) $cols );
	$primary_label = (string) ( $cols[0] ?? '' );
	$num_labels    = array_slice( $cols, 1 );
	$with_colname  = ! empty( $opts['data_colname'] );

	$panel_args = array( 'inside_class' => 'inside sn-an-table-inside' );
	if ( ! empty( $opts['header_meta'] ) ) {
		$panel_args['header_meta'] = $opts['header_meta'];
	}
	snt_an_panel_open( $title, $panel_args );

	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $primary_label ) . '</th>';
	foreach ( $num_labels as $label ) {
		echo '<th scope="col" class="manage-column num">' . esc_html( (string) $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$row = array_values( (array) $row );
		echo '<tr><td class="column-primary"' . ( $with_colname ? ' data-colname="' . esc_attr( $primary_label ) . '"' : '' )
			. '><strong>' . esc_html( (string) ( $row[0] ?? '' ) ) . '</strong></td>';
		foreach ( $num_labels as $i => $label ) {
			echo '<td class="num"' . ( $with_colname ? ' data-colname="' . esc_attr( (string) $label ) . '"' : '' )
				. '>' . esc_html( (string) ( $row[ $i + 1 ] ?? '' ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	snt_an_panel_close();
}
