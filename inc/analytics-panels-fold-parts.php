<?php
/**
 * Signal & Noise Tools: Analytics panel primitive, the empty fold and the small primitives (the read-failure copy, the empty-panel collector and its flush, the tier and delta badges, the KPI row, the gate).
 *
 * Split from inc/analytics-panels.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
/**
 * The ONE read-failure sentence (v9.68.1). Every panel that distinguishes a
 * FAILED durable-table read (accessor null) from an empty window ([]) speaks
 * it identically — extracted from the v9.68.0 Overview folds so the wording
 * can never fork per surface. A database failure must never impersonate a
 * quiet range (the v9.65.0 conflation class).
 *
 * @since 9.68.1
 * @param string $subject What could not be read (a panel title like
 *                        "Browsers", or a phrase like "Referrer categories").
 * @return string Translated copy.
 */
function snt_an_read_failed_copy( $subject ) {
	/* translators: %s: the table/panel that could not be read (e.g. "Browsers", "Referrer categories"). */
	return sprintf( __( '%s could not be read (read failure: not an empty window).', 'signal-and-noise-tools' ), (string) $subject );
}

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
		echo '<li><strong>' . esc_html( $panel['title'] ) . '</strong>. ' . esc_html( $panel['why'] ) . '</li>';
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
 * 'sentiment' (kpi variant only; v9.68.0 review 2, F1) decouples the COLOR
 * class from the arrow for lower-is-better metrics: 'down_good' maps
 * up→.sn-delta-bad / down→.sn-delta-good (a rising bounce is a RED chip with a
 * real ▲ — never a green "improvement"); the 'up_good' default keeps the
 * legacy direction classes byte-identically, so every existing caller is
 * unchanged. The inline variant ignores it — its one class IS the direction
 * marker existing CSS consumes.
 *
 * @param array|null $delta {pct:?int, dir:string, previous?:numeric}
 * @param array      $opts  {variant?:'inline'|'kpi', basis_label?:string,
 *                          sentiment?:'up_good'|'down_good'}
 */
function snt_an_delta_badge( $delta, $opts = array() ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : __( 'none before', 'signal-and-noise-tools' ) )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	if ( 'kpi' === ( $opts['variant'] ?? 'inline' ) ) {
		$cls = 'up' === $dir ? 'sn-delta-up' : ( 'down' === $dir ? 'sn-delta-down' : 'sn-delta-flat' );
		if ( 'down_good' === ( $opts['sentiment'] ?? 'up_good' ) ) {
			// Lower is better: color says whether the CHANGE is good; the
			// arrow above keeps the real direction untouched.
			$cls = 'up' === $dir ? 'sn-delta-bad' : ( 'down' === $dir ? 'sn-delta-good' : 'sn-delta-flat' );
		}
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
 * without needing a real {pct,dir} delta), sentiment? ('up_good' default |
 * 'down_good' — forwarded to the badge so a lower-is-better KPI's chip colors
 * by goodness, not direction; v9.68.0 review 2, F1). Slot precedence live >
 * delta > sub > default. Malformed cards are skipped silently.
 *
 * @param array $cards
 * @param array $opts {empty_slot?:'no-change'|'omit', row_class?:string, basis_label?:string}
 */
function snt_an_kpi_row( $cards, $opts = array() ) {
	if ( snt_an_surface( 'kpis', array( 'cards' => $cards, 'opts' => $opts ) ) ) {
		return;
	}
	echo '<div class="sn-kpi-row' . ( '' !== (string) ( $opts['row_class'] ?? '' ) ? ' ' . esc_attr( (string) $opts['row_class'] ) : '' ) . '">';
	foreach ( (array) $cards as $c ) {
		if ( ! is_array( $c ) || ! isset( $c['l'], $c['n'] ) ) {
			continue;
		}
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( (string) $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value"';
		foreach ( (array) ( $c['attrs'] ?? array() ) as $k => $v ) { // data-sn-live: assets/live-now.js updates it in place.
			echo ' ' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '>' . esc_html( (string) $c['n'] ) . '</p>';
		if ( ! empty( $c['live'] ) ) {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</span>';
		} elseif ( ! empty( $c['delta'] ) ) {
			snt_an_delta_badge( $c['delta'], array( 'variant' => 'kpi', 'basis_label' => (string) ( $opts['basis_label'] ?? '' ), 'sentiment' => (string) ( $c['sentiment'] ?? 'up_good' ) ) );
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
	if ( snt_an_surface( 'gate', compact( 'title', 'message', 'cta_label', 'cta_url', 'opts' ) ) ) {
		return;
	}
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

