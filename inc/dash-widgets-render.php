<?php
/**
 * Signal & Noise Tools — registration, shell render and assets for the four
 * fallback dashboard boxes. Definitions live in inc/dash-widgets.php.
 *
 * The render is a SHELL: signal cells with em-dash placeholders, list headings,
 * action buttons and the deep links, all server-side and free.
 * assets/dash-widgets.js fills values, list rows and action results from the
 * readonly abilities named in the markup. That ordering is what keeps the
 * index.php zero-cost invariant true while the boxes still carry live numbers,
 * and it means a box degrades to labelled em dashes with working links rather
 * than to a blank card if the hydrator never runs.
 *
 * @package SignalNoiseTools
 * @since 13.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register each box the current user may see.
 *
 * @since 13.30.0
 * @return void
 */
function snt_dwx_register() {
	foreach ( snt_dwx_boxes() as $box ) {
		// ANY-OF, never a single cap. `view_stats` is not a core WordPress
		// capability — a plain administrator does not hold it — which is why
		// every other consumer in this plugin gates `view_stats ||
		// manage_options`. v13.30.0 gated Audience on `view_stats` alone, so it
		// registered for nobody and was simply absent from the dashboard.
		$allowed = false;
		foreach ( (array) $box['caps'] as $cap ) {
			if ( current_user_can( (string) $cap ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			continue;
		}
		wp_add_dashboard_widget(
			(string) $box['id'],
			(string) $box['title'],
			static function () use ( $box ) {
				snt_dwx_render( $box );
			}
		);
	}
}
add_action( 'wp_dashboard_setup', 'snt_dwx_register' );

/**
 * Print one box: blurb, signal grid, lists, actions, deep links.
 *
 * @since 13.30.0
 * @param array<string,mixed> $box A row from snt_dwx_boxes().
 * @return void
 */
function snt_dwx_render( array $box ) {
	echo '<div class="sn-dwx">';

	if ( '' !== (string) ( $box['blurb'] ?? '' ) ) {
		echo '<p class="sn-dwx__blurb">' . esc_html( (string) $box['blurb'] ) . '</p>';
	}

	// The sibling box's grid vocabulary, deliberately: assets/dash-widget.css is
	// enqueued on this same screen, so reusing sn-dw__signals / __sig / __k / __n
	// / __c makes the five S&N boxes one visual family instead of five dialects.
	// A flat label-value list read as a settings table, not as a widget.
	echo '<div class="sn-dw__signals">';
	foreach ( (array) $box['sections'] as $sec ) {
		if ( ! empty( $sec['signals'] ) && is_callable( $sec['signals'] ) ) {
			foreach ( (array) call_user_func( $sec['signals'] ) as $sig ) {
				snt_dwx_cell(
					(string) ( $sig['label'] ?? '' ),
					(string) ( $sig['value'] ?? '' ),
					(string) ( $sig['compare'] ?? '' ),
					(string) ( $sig['dir'] ?? '' )
				);
			}
			continue;
		}
		foreach ( (array) $sec['fields'] as $field ) {
			snt_dwx_cell(
				(string) $field['label'],
				'',
				'',
				'',
				array(
					'ability'  => (string) $sec['ability'],
					'input'    => (array) ( $sec['input'] ?? array() ),
					'baseline' => (array) ( $sec['baseline'] ?? array() ),
					'path'     => (string) $field['path'],
					'compare'  => isset( $field['compare'] ) ? (array) $field['compare'] : array(),
					'delta'    => isset( $field['delta'] ) ? (array) $field['delta'] : array(),
				)
			);
		}
	}
	echo '</div>';

	foreach ( (array) ( $box['lists'] ?? array() ) as $list ) {
		$spec = array(
			'path'  => (string) $list['path'],
			'limit' => (int) ( $list['limit'] ?? 5 ),
			'item'  => (array) $list['item'],
		);
		if ( ! empty( $list['keys'] ) ) {
			$spec['keys'] = (array) $list['keys'];
		}
		if ( ! empty( $list['empty'] ) ) {
			$spec['empty'] = (string) $list['empty'];
		}
		echo '<div class="sn-dwx__list" data-sn-dwx-ability="' . esc_attr( (string) $list['ability'] ) . '"'
			. ' data-sn-dwx-input="' . esc_attr( (string) wp_json_encode( (array) ( $list['input'] ?? array() ) ) ) . '"'
			. ' data-sn-dwx-list="' . esc_attr( (string) wp_json_encode( $spec ) ) . '">';
		echo '<h4 class="sn-dwx__h">' . esc_html( (string) $list['label'] ) . '</h4>';
		// No skeleton rows: an invented row count would be a claim about data
		// nobody has read yet. The heading alone holds the space.
		echo '<div class="sn-dwx__rows"></div>';
		echo '</div>';
	}

	if ( ! empty( $box['actions'] ) ) {
		echo '<p class="sn-dwx__actions">';
		foreach ( (array) $box['actions'] as $action ) {
			// Always emitted, escaped inline. An absent input serialises to {} and
			// the hydrator already defaults to {}, so this needs no suppression:
			// a phpcs:ignore only covers the NEXT line, and a pre-built attribute
			// string lands on a continuation line where the ignore does not reach.
			echo '<button type="button" class="button button-small sn-dwx__btn"'
				. ' data-sn-dwx-action="' . esc_attr( (string) $action['ability'] ) . '"'
				. ' data-sn-dwx-action-input="' . esc_attr( (string) wp_json_encode( (array) ( $action['input'] ?? array() ) ) ) . '"'
				. ' data-sn-dwx-busy="' . esc_attr( (string) $action['busy'] ) . '">'
				. esc_html( (string) $action['label'] ) . '</button> ';
		}
		echo '<span class="sn-dwx__result" role="status"></span>';
		echo '</p>';
	}

	if ( ! empty( $box['links'] ) ) {
		echo '<p class="sn-dwx__links">';
		$first = true;
		foreach ( (array) $box['links'] as $link ) {
			if ( ! $first ) {
				echo ' <span class="sn-dwx__dot">&middot;</span> ';
			}
			$first = false;
			echo '<a href="' . esc_url( admin_url( (string) $link['url'] ) ) . '">'
				. esc_html( (string) $link['label'] ) . '</a>';
		}
		echo '</p>';
	}

	echo '</div>';
}

/**
 * One signal cell.
 *
 * Server-rendered cells arrive with their value; ability-backed cells arrive
 * empty and carry the hydration contract in data attributes. The placeholder is
 * an em dash, never a 0 — an unhydrated cell must not be readable as a measured
 * zero.
 *
 * @since 13.30.1
 * @param string              $label   Cell label.
 * @param string              $value   Value, or '' for an ability-backed cell.
 * @param string              $compare Sub-line, or ''.
 * @param string              $dir     'up'|'down'|'' for the comparison colour.
 * @param array<string,mixed> $hydrate Hydration contract, or array() when server-rendered.
 * @return void
 */
function snt_dwx_cell( $label, $value, $compare = '', $dir = '', array $hydrate = array() ) {
	$attrs = '';
	if ( $hydrate ) {
		$attrs = ' data-sn-dwx-ability="' . esc_attr( (string) $hydrate['ability'] ) . '"'
			. ' data-sn-dwx-path="' . esc_attr( (string) $hydrate['path'] ) . '"';
		if ( ! empty( $hydrate['input'] ) ) {
			$attrs .= ' data-sn-dwx-input="' . esc_attr( (string) wp_json_encode( $hydrate['input'] ) ) . '"';
		}
		// A delta needs the WIDER window as well: prior = baseline - current.
		if ( ! empty( $hydrate['delta'] ) && ! empty( $hydrate['baseline'] ) ) {
			$attrs .= ' data-sn-dwx-baseline="' . esc_attr( (string) wp_json_encode( $hydrate['baseline'] ) ) . '"'
				. ' data-sn-dwx-delta="' . esc_attr( (string) wp_json_encode( $hydrate['delta'] ) ) . '"';
		}
		if ( ! empty( $hydrate['compare'] ) ) {
			$attrs .= ' data-sn-dwx-compare="' . esc_attr( (string) wp_json_encode( $hydrate['compare'] ) ) . '"';
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every interpolated part of $attrs is esc_attr'd above.
	echo '<div class="sn-dw__sig"' . $attrs . '>';
	echo '<span class="sn-dw__k">' . esc_html( $label ) . '</span>';
	echo '<span class="sn-dw__n">' . ( '' === $value ? '&mdash;' : esc_html( $value ) ) . '</span>';
	$cls = 'sn-dw__c' . ( ( 'up' === $dir || 'down' === $dir ) ? ' sn-dw__c--' . $dir : '' );
	echo '<span class="' . esc_attr( $cls ) . '">' . esc_html( $compare ) . '</span>';
	echo '</div>';
}

/**
 * The "Last purge" compare line.
 *
 * TWO WRONG ANSWERS SHIPPED HERE FIRST, both the same mistake in different
 * words. v13.70.0 replaced "9 still stale" (a tally over the RETAINED LOG,
 * phrased as a live count) with "9 of 20 probes stale" beside a headline reading
 * "fresh" — one sentence contradicting itself. v13.70.1 added the word "earlier"
 * so the two halves stopped arguing. Owner ruling on that, 2026-09-02: "If it's
 * fresh, it is fresh. If it isn't, it shouldn't say."
 *
 * That is the right call and it is not a wording preference. This cell is
 * labelled "Last purge" — it answers ONE question, about ONE event: did the most
 * recent purge clear the edge, and how long ago. A running tally over up to 20
 * earlier probes answers a different question ("is the edge chronically
 * flaky?"), and pasting it beside a point verdict cannot be phrased into
 * coherence — a reader sees "fresh" and a stale count and distrusts both.
 *
 * The history is not discarded, it is MOVED: the Cloudflare tab renders the
 * individual rows (url, time, escalated), where "why were 9 stale?" is a
 * question a reader can actually pursue. A number nobody can drill into is
 * decoration.
 *
 * @since 13.71.1
 * @param string $last      Newest verdict: 'fresh' | 'stale' | 'unknown'.
 * @param int    $last_time Unix time of that verdict (0 when unknown).
 * @param int    $now       Clock, injected so the fixture does not race one.
 * @return string
 */
function snt_dash_freshness_compare( $last, $last_time, $now ) {
	$last_time = (int) $last_time;
	$now       = (int) $now;
	if ( $last_time <= 0 || $last_time > $now ) {
		// No usable timestamp: say that, rather than inventing an age. A future
		// stamp is a broken clock, not a fresh purge.
		return __( 'no timing recorded', 'signal-and-noise-tools' );
	}
	$ago = function_exists( 'human_time_diff' ) ? human_time_diff( $last_time, $now ) : ( $now - $last_time ) . 's';
	if ( 'stale' === (string) $last ) {
		/* translators: %s: human-readable age of the probe, e.g. "4 mins" */
		return sprintf( __( 'still stale after %s', 'signal-and-noise-tools' ), $ago );
	}
	if ( 'fresh' === (string) $last ) {
		/* translators: %s: human-readable age of the probe, e.g. "4 mins" */
		return sprintf( __( 'verified %s ago', 'signal-and-noise-tools' ), $ago );
	}
	// 'unknown' is not 'fresh'. The probe ran and could not read an answer.
	/* translators: %s: human-readable age of the probe, e.g. "4 mins" */
	return sprintf( __( 'unread %s ago', 'signal-and-noise-tools' ), $ago );
}

/**
 * Cron and cache-freshness cells, server-side and free.
 *
 * Both sources are LOCAL reads — _get_cron_array() is an option, and the
 * freshness summary reads the verification trail the purge path has written
 * since v11.10.0 — so these two cells carry real values on first paint instead
 * of arriving as em dashes after a round trip. They are also the two facts the
 * box's own title promises ("whether the edge took it") and 13.31.0 shipped
 * without: Purge caches fired into the dark, which is exactly the blindness the
 * desktop sn-cache widget was built to end.
 *
 * @since 13.33.0
 * @return array<int,array<string,mixed>>
 */
function snt_dwx_ops_signals() {
	$out = array();

	if ( function_exists( 'snt_cron_summary_for_localize' ) ) {
		$cron  = (array) snt_cron_summary_for_localize();
		$total = (int) ( $cron['total'] ?? 0 );
		$orph  = (int) ( $cron['orphans'] ?? 0 );
		$out[] = array(
			'label'   => __( 'Cron events', 'signal-and-noise-tools' ),
			'value'   => number_format_i18n( $total ),
			'compare' => $orph > 0
				/* translators: %d: number of orphaned cron events */
				? sprintf( _n( '%d orphaned', '%d orphaned', $orph, 'signal-and-noise-tools' ), $orph )
				/* translators: %d: number of events owned by this plugin */
				: sprintf( __( '%d ours', 'signal-and-noise-tools' ), (int) ( $cron['sn_count'] ?? 0 ) ),
			'dir'     => $orph > 0 ? 'down' : '',
		);
	}

	if ( function_exists( 'snt_cf_freshness_summary' ) ) {
		$fresh = snt_cf_freshness_summary();
		if ( is_array( $fresh ) ) {
			$last = (string) ( $fresh['last'] ?? 'unknown' );
			$out[] = array(
				'label'   => __( 'Last purge', 'signal-and-noise-tools' ),
				// The WORD, not a count: "did the edge actually clear" is the
				// question, and a number cannot answer it.
				'value'   => $last,
				'compare' => snt_dash_freshness_compare( $last, (int) ( $fresh['last_time'] ?? 0 ), time() ),
				'dir'     => 'stale' === $last ? 'down' : ( 'fresh' === $last ? 'up' : '' ),
			);
		}
	}

	return $out;
}

/**
 * Stylesheet + hydrator, on index.php only.
 *
 * Gated on the hook suffix rather than on whether any box registered: the hook
 * is the cheap reliable signal, and assets shipped to a screen that never
 * renders these boxes are dead weight on every request. v11.30.2 shipped the
 * sibling box's CSS to a stylesheet that only loaded on S&N pages, so the box
 * rendered unstyled on the one screen it lives on.
 *
 * The script DEPENDS on snt-ability-run: without it sntAbilityRun is undefined
 * and every box would sit at its em dashes forever, silently.
 *
 * @since 13.30.0
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function snt_dwx_enqueue( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'sn-dash-widgets', SNT_URL . 'assets/dash-widgets.css', array(), SNT_VERSION );
	wp_enqueue_script( 'sn-dash-widgets', SNT_URL . 'assets/dash-widgets.js', array( 'snt-ability-run' ), SNT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'snt_dwx_enqueue' );
