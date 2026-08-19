<?php
/**
 * Signal & Noise — the Dashboard console.
 *
 * Direction B with C's band, from the 2026-08-19 mockups: a briefing line the
 * page opens with, a permanent systems rail down the left, and a stage that
 * belongs to whatever you came to look at.
 *
 * WHY THIS SHAPE. v11.28.0 built the collapse rule faithfully and produced a
 * page that was 53% empty when the site was healthy — because "state earns
 * space" describes what ALARMS do and says nothing about what the page IS when
 * nothing is wrong, which is nearly always. A console is dense at rest and lets
 * alarms assert themselves over that density; it is not empty when calm.
 *
 * The rail is always on, so the answer is readable without expanding anything.
 * Nothing here collapses.
 *
 * @package SignalNoiseTools
 * @since 11.29.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One rail row: a state dot, a label that links to the tab owning it, a value.
 *
 * @since 11.29.1
 * @param array<string,mixed> $card A glance card.
 * @return void
 */
function sn_dash_render_rail_row( array $card ) {
	$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';
	$href  = isset( $card['href'] ) ? (string) $card['href'] : '';
	$label = (string) ( $card['label'] ?? '' );
	$value = (string) ( $card['value'] ?? '' );

	// A card that opted out of promotion keeps its pill but must not read as an
	// alarm here either — the same predicate the sort and the zone state use.
	if ( ! sn_admin_card_wants_attention( $card ) && 'ok' !== $kind ) {
		$kind = 'muted';
	}

	echo '<li class="sn-rail__row">';
	echo '<span class="sn-rail__dot sn-rail__dot--' . esc_attr( $kind ) . '" aria-hidden="true"></span>';
	if ( '' !== $href ) {
		echo '<a class="sn-rail__label" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
	} else {
		echo '<span class="sn-rail__label">' . esc_html( $label ) . '</span>';
	}
	echo '<span class="sn-rail__value">' . esc_html( $value ) . '</span>';
	echo '</li>';
}

/**
 * The systems rail: every check and every component, always visible.
 *
 * Sections are labelled rather than collapsed. A reader learns where a row
 * lives and reads by position — which is the whole point of a rail, and what
 * the collapsing zones took away.
 *
 * @since 11.29.1
 * @param array<int,array<string,mixed>> $checks     Health/cron/caches/provenance cards.
 * @param array<int,array<string,mixed>> $components Fleet cards.
 * @return void
 */
function sn_dash_render_rail( array $checks, array $components ) {
	echo '<aside class="sn-rail" aria-label="' . esc_attr__( 'Systems', 'signal-and-noise-tools' ) . '">';

	if ( ! empty( $checks ) ) {
		echo '<h2 class="sn-rail__head">' . esc_html__( 'Systems', 'signal-and-noise-tools' ) . '</h2>';
		echo '<ul class="sn-rail__list">';
		foreach ( sn_admin_glance_sort_by_attention( $checks ) as $card ) {
			if ( is_array( $card ) ) {
				sn_dash_render_rail_row( $card );
			}
		}
		echo '</ul>';
	}

	if ( ! empty( $components ) ) {
		echo '<h2 class="sn-rail__head">' . esc_html__( 'Fleet', 'signal-and-noise-tools' ) . '</h2>';
		echo '<ul class="sn-rail__list">';
		foreach ( $components as $card ) {
			if ( is_array( $card ) ) {
				sn_dash_render_rail_row( $card );
			}
		}
		echo '</ul>';
	}

	echo '</aside>';
}

/**
 * The five figures as discrete cards, views widest.
 *
 * NOT the v11.28.0 inline strip. The approved mockup gives each figure its own
 * bordered card and a 22px number, with views on a 1.5fr column because it
 * carries the trend. A strip reads as a footnote; cards read as instruments.
 *
 * @since 11.29.1
 * @param array<int,array<string,mixed>> $figures From sn_dash_measurement_figures().
 * @return void
 */
function sn_dash_render_figures( array $figures ) {
	if ( empty( $figures ) ) {
		return;
	}
	echo '<div class="sn-figs">';
	foreach ( $figures as $fig ) {
		if ( ! is_array( $fig ) ) {
			continue;
		}
		$classes = array( 'sn-fig' );
		if ( ! empty( $fig['hero'] ) ) {
			$classes[] = 'sn-fig--hero';
		}
		// array_key_exists, not a falsy check: a measured 0 is a value.
		if ( array_key_exists( 'measured', $fig ) && false === $fig['measured'] ) {
			$classes[] = 'sn-fig--unmeasured';
		}
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<span class="sn-fig__k">' . esc_html( (string) ( $fig['label'] ?? '' ) ) . '</span>';
		echo '<span class="sn-fig__num">' . esc_html( (string) ( $fig['value'] ?? '' ) ) . '</span>';
		$delta = $fig['delta'] ?? null;
		if ( null !== $delta && 0 !== (int) $delta ) {
			$up = (int) $delta > 0;
			echo '<span class="sn-fig__delta sn-fig__delta--' . ( $up ? 'up' : 'down' ) . '">'
				. esc_html( ( $up ? '+' : '−' ) . number_format_i18n( abs( (int) $delta ) ) )
				. '</span>';
		}
		echo '</div>';
	}
	echo '</div>';
}

/**
 * The 30-day trend as a real chart.
 *
 * NOT snt_analytics_sparkline() stretched. That helper is a 72x18 inline mark
 * for a table cell; blown up to full width it is a bare line with no baseline
 * to read against. The stage chart the mockup approved has two grid lines, an
 * area fill and an emphasised endpoint — the same treatment as the Analytics
 * Overview chart, at the size the stage gives it.
 *
 * @since 11.29.1
 * @param array<int,array<string,mixed>> $series [{day,views}]
 * @return void
 */
function sn_dash_render_trend( array $series ) {
	$series = array_values( $series );
	$n      = count( $series );
	if ( $n < 2 ) {
		// One point is not a trend. Rendering a flat line would assert a shape
		// the data cannot support.
		return;
	}

	$max = 1;
	foreach ( $series as $row ) {
		$max = max( $max, (int) ( $row['views'] ?? 0 ) );
	}

	$w    = 600.0;
	$top  = 8.0;
	$base = 88.0;
	$step = $w / ( $n - 1 );

	$pts = array();
	foreach ( $series as $i => $row ) {
		$x     = round( $i * $step, 2 );
		$y     = round( $base - ( (int) ( $row['views'] ?? 0 ) / $max ) * ( $base - $top ), 2 );
		$pts[] = $x . ',' . $y;
	}
	$line = 'M ' . implode( ' L ', $pts );
	$area = $line . ' L ' . round( ( $n - 1 ) * $step, 2 ) . ',' . $base . ' L 0,' . $base . ' Z';
	$last = explode( ',', $pts[ $n - 1 ] );

	echo '<section class="sn-stage__trend">';
	echo '<h2 class="sn-stage__head">' . esc_html__( 'Views · 30 days', 'signal-and-noise-tools' ) . '</h2>';
	echo '<svg class="sn-trend" viewBox="0 0 600 96" preserveAspectRatio="none" role="img" aria-label="'
		. esc_attr__( 'Views over the last 30 days', 'signal-and-noise-tools' ) . '">';
	echo '<line x1="0" y1="28" x2="600" y2="28" class="sn-trend__grid" />';
	echo '<line x1="0" y1="58" x2="600" y2="58" class="sn-trend__grid" />';
	echo '<path d="' . esc_attr( $area ) . '" class="sn-trend__area" />';
	echo '<path d="' . esc_attr( $line ) . '" class="sn-trend__line" />';
	echo '<circle cx="' . esc_attr( $last[0] ) . '" cy="' . esc_attr( $last[1] ) . '" r="3.5" class="sn-trend__end" />';
	echo '</svg>';
	echo '</section>';
}

/**
 * The maintenance actions, compact, inside the stage.
 *
 * The approved mockup demotes these to a toolbar. On the v11.28.0 page they
 * were four large cards taking a third of the viewport — the least-used thing
 * on screen with the most weight. Same form, same nonce, same action values.
 *
 * @since 11.29.1
 * @param string $check_updates_url
 * @return void
 */
function sn_dash_render_toolbar( $check_updates_url = '' ) {
	echo '<form class="sn-toolbar" method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<span class="sn-toolbar__k">' . esc_html__( 'Maintenance', 'signal-and-noise-tools' ) . '</span>';
	echo '<button type="submit" name="sn_action" value="purge_caches" class="button">'
		. esc_html__( 'Purge all caches', 'signal-and-noise-tools' ) . '</button>';
	echo '<button type="submit" name="sn_action" value="clear_overrides" class="button">'
		. esc_html__( 'Clear overrides', 'signal-and-noise-tools' ) . '</button>';
	if ( '' !== $check_updates_url ) {
		echo '<a class="button" href="' . esc_url( $check_updates_url ) . '">'
			. esc_html__( 'Check for updates', 'signal-and-noise-tools' ) . '</a>';
	}
	echo '<button type="submit" name="sn_action" value="full_reset" class="button button-link-delete">'
		. esc_html__( 'Full reset', 'signal-and-noise-tools' ) . '</button>';
	echo '</form>';
}

/**
 * The stage: status, figures, trend, actions — in that order.
 *
 * This is the half of the page the old design left blank.
 *
 * @since 11.29.1
 * @param array<string,mixed> $measurement From snt_dashboard_measurement_data().
 * @param array<string,mixed> $opts        needy, last_deploy_ago, check_updates_url, series.
 * @return void
 */
function sn_dash_render_stage( array $measurement, array $opts = array() ) {
	$needy = isset( $opts['needy'] ) ? (int) $opts['needy'] : 0;
	$ago   = (string) ( $opts['last_deploy_ago'] ?? '' );

	echo '<div class="sn-stage">';

	echo '<p class="sn-attn sn-attn--' . esc_attr( $needy > 0 ? 'attention' : 'ok' ) . '">';
	echo '<span class="sn-rail__dot sn-rail__dot--' . esc_attr( $needy > 0 ? 'warn' : 'ok' ) . '" aria-hidden="true"></span>';
	echo esc_html(
		$needy > 0
			/* translators: %d checks needing attention */
			? sprintf( _n( '%d check needs attention', '%d checks need attention', $needy, 'signal-and-noise-tools' ), $needy )
			: __( 'Nothing needs attention', 'signal-and-noise-tools' )
	);
	if ( '' !== $ago ) {
		/* translators: %s human time since the last deploy */
		echo ' <span class="sn-attn__meta">' . esc_html( sprintf( __( '— last deploy %s', 'signal-and-noise-tools' ), $ago ) ) . '</span>';
	}
	echo '</p>';

	sn_dash_render_figures( sn_dash_measurement_figures( $measurement ) );

	if ( ! empty( $opts['series'] ) && is_array( $opts['series'] ) ) {
		sn_dash_render_trend( $opts['series'] );
	}

	sn_dash_render_toolbar( (string) ( $opts['check_updates_url'] ?? '' ) );

	echo '</div>';
}

/**
 * Compose the console: band, then rail beside stage.
 *
 * @since 11.29.1
 * @param array<int,array<string,mixed>> $checks
 * @param array<int,array<string,mixed>> $components
 * @param array<string,mixed>            $measurement
 * @param array<string,mixed>            $briefing
 * @param array<string,mixed>            $opts needy, last_deploy_ago, check_updates_url, series.
 * @return void
 */
function sn_dash_render_console( array $checks, array $components, array $measurement, array $briefing, array $opts = array() ) {
	sn_dash_render_briefing( $briefing );

	echo '<div class="sn-console">';
	sn_dash_render_rail( $checks, $components );
	sn_dash_render_stage( $measurement, $opts );
	echo '</div>';
}
