<?php
/**
 * Signal & Noise — Posts (lifecycle) analytics view RENDER layer.
 *
 * Strict 1:1 reuse of the dashboard's native vocabulary — the hero clones the
 * .sn-kpi cards (like sn_login_defense_render_kpi_cards, NOT snt_analytics_render_cards
 * whose contract is pageview-shaped), the trajectory reuses snt_analytics_smooth_path,
 * the leaderboard reuses the .wp-list-table chrome, and velocity/decay reuse
 * snt_analytics_render_distribution. NO new CSS vocabulary. Read-only.
 *
 * @package signal-and-noise-tools
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * The whole Posts view: hero + lifecycle trajectory + catalog leaderboard +
 * launch-velocity + evergreen/spike bars. Null bundle (no published posts) → a
 * single empty-state note.
 *
 * @param array|null $bundle From sn_analytics_posts_bundle().
 */
function snt_analytics_render_posts_view( $bundle ) {
	if ( ! is_array( $bundle ) || empty( $bundle['subject'] ) ) {
		snt_an_gate(
			__( 'Posts', 'signal-and-noise-tools' ),
			__( 'No published posts yet: this view tracks each Note over its lifetime once you publish and traffic arrives.', 'signal-and-noise-tools' )
		);
		return;
	}

	snt_analytics_render_post_hero( $bundle['subject'] );
	snt_analytics_render_post_trajectory( $bundle['subject'], $bundle['leaderboard'] );

	echo '<div class="sn-an-grid">';
	snt_analytics_render_posts_leaderboard( $bundle['leaderboard'] );

	// Launch velocity — each recent post's first-48h views, as shared distribution bars.
	$vel = array();
	foreach ( (array) $bundle['leaderboard'] as $r ) {
		$vel[] = array( 'label' => (string) $r['title'], 'views' => (int) $r['velocity'] );
	}
	snt_analytics_render_distribution( __( 'Launch velocity (first 48h)', 'signal-and-noise-tools' ), $vel, __( 'No launch data yet.', 'signal-and-noise-tools' ), true );

	// Evergreen vs spike — how the catalog breaks down by decay shape.
	$shape = array( 'evergreen' => 0, 'cooling' => 0, 'spike' => 0 );
	foreach ( (array) $bundle['leaderboard'] as $r ) {
		$d = (string) ( $r['decay'] ?? '' );
		if ( isset( $shape[ $d ] ) ) {
			++$shape[ $d ];
		}
	}
	$decay_rows = array();
	foreach ( $shape as $label => $count ) {
		$decay_rows[] = array( 'label' => ucfirst( $label ), 'views' => $count );
	}
	snt_analytics_render_distribution( __( 'Evergreen vs spike', 'signal-and-noise-tools' ), $decay_rows, __( 'No shape data yet.', 'signal-and-noise-tools' ) );
	echo '</div>';
	snt_an_flush_empty_fold();
}

/**
 * "Did it land?" hero — the newest post's views-since-publish, its age-aligned
 * verdict vs the cohort median, and its rank, in cloned .sn-kpi cards.
 *
 * @param array $subject From sn_analytics_posts_subject().
 */
function snt_analytics_render_post_hero( $subject ) {
	if ( empty( $subject['has_data'] ) ) {
		// D4 §4: the whole hero — including the "which post?" title line — folds
		// when this Note has no recorded views. The view-level null-bundle gate
		// (snt_an_gate, above in snt_analytics_render_posts_view) still covers
		// the "no posts at all" case; this is the narrower "one post exists but
		// has zero views yet" case, which now yields no visible hero at all
		// rather than a panel with just the title.
		// D5 §6: the fold why now NAMES the Note (was a generic "this Note" —
		// the fold's <li> already carries the panel title separately, but the
		// why-sentence read stronger identifying the subject by its own title).
		$title = (string) ( $subject['title'] ?? '' );
		snt_an_note_empty(
			__( 'Latest Note: did it land?', 'signal-and-noise-tools' ),
			sprintf(
				/* translators: %s: the Note's title. */
				__( '"%s" has no recorded views yet, or your other Notes have none to compare it against.', 'signal-and-noise-tools' ),
				$title
			)
		);
		return;
	}

	$age = (int) $subject['age'];
	$pub = ( 0 === $age )
		? __( 'published today', 'signal-and-noise-tools' )
		/* translators: %d: days since publish. */
		: sprintf( _n( 'published %d day ago', 'published %d days ago', $age, 'signal-and-noise-tools' ), $age );

	snt_an_panel_open(
		__( 'Latest Note: did it land?', 'signal-and-noise-tools' ),
		array(
			'panel_class'  => 'sn-overview',
			'inside_class' => 'inside inside-flush sn-an-panel',
		)
	);
	echo '<p class="sn-posts-hero-h"><a href="' . esc_url( (string) $subject['permalink'] ) . '"><strong>'
		. esc_html( (string) $subject['title'] ) . '</strong></a> · ' . esc_html( $pub ) . '</p>';

	$d       = is_array( $subject['delta'] ) ? $subject['delta'] : array();
	$dir     = in_array( $d['dir'] ?? 'flat', array( 'up', 'down', 'flat' ), true ) ? $d['dir'] : 'flat';
	$pct     = $d['pct'] ?? null;
	$verdict = ( null === $pct )
		? ( 'up' === $dir ? __( 'new', 'signal-and-noise-tools' ) : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	$descr = 'up' === $dir
		? '▲ ' . __( 'above median', 'signal-and-noise-tools' )
		: ( 'down' === $dir ? '▼ ' . __( 'below median', 'signal-and-noise-tools' ) : '■ ' . __( 'on par', 'signal-and-noise-tools' ) );
	$rank = is_array( $subject['rank'] ) ? $subject['rank'] : array( 'rank' => 1, 'of' => 1 );

	$age_sub = ( 0 === $age )
		? __( 'so far today', 'signal-and-noise-tools' )
		/* translators: %d: days since publish. */
		: sprintf( _n( 'in %d day', 'in %d days', $age, 'signal-and-noise-tools' ), $age );

	// v9.40.0 D4: 'sub' cards are always colored TEXT descriptors here (no real
	// {pct,dir} pair) — 'sub_class' rides the dir-derived class; the three
	// always-flat cards omit it and fall through to the primitive's default.
	$cards = array(
		array( 'l' => __( 'Views', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $subject['views'] ), 'promoted' => true, 'sub' => $age_sub ),
		array( 'l' => __( 'vs your typical', 'signal-and-noise-tools' ), 'n' => $verdict, 'promoted' => true, 'sub' => $descr, 'sub_class' => 'up' === $dir ? 'sn-delta-up' : ( 'down' === $dir ? 'sn-delta-down' : 'sn-delta-flat' ) ),
		/* translators: %d: total recent Notes compared. */
		array( 'l' => __( 'Rank', 'signal-and-noise-tools' ), 'n' => '#' . (int) $rank['rank'], 'sub' => sprintf( __( 'of %d recent', 'signal-and-noise-tools' ), (int) $rank['of'] ) ),
		array( 'l' => __( 'Lifetime', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $subject['lifetime'] ), 'sub' => __( 'all-time', 'signal-and-noise-tools' ) ),
	);

	snt_an_kpi_row( $cards );
	snt_an_panel_close();
}

/**
 * Lifecycle trajectory: the subject post's cumulative views by day-of-life,
 * overlaid on the cohort's median trajectory at each age. Both curves built with
 * the shared snt_analytics_smooth_path so the treatment is pixel-identical to
 * every other trend on the page (subject #2271b1, baseline muted #646970).
 *
 * D5 §3 recorded holdout: does NOT route through snt_an_trend_svg() (the shared
 * trend-SVG primitive the other three trend copies adopted) — different viewBox
 * (600×86, not 600×84), no area fill/gradient/baseline, dual equal-x series (the
 * primitive's overlay uses its own point count, not a shared x-axis), and a
 * different overlay stroke. Pre-authorized to stay bespoke; parity beats purity.
 *
 * @param array $subject     Subject summary.
 * @param array $leaderboard All recent-post rows (carry by_dol + age).
 */
function snt_analytics_render_post_trajectory( $subject, $leaderboard ) {
	$age = max( 1, (int) $subject['age'] );

	$subj = array();
	$base = array();
	for ( $dol = 0; $dol <= $age; $dol++ ) {
		$subj[] = sn_analytics_posts_cumulative_at( $subject['by_dol'], $dol );
		$cohort = array();
		foreach ( (array) $leaderboard as $r ) {
			if ( (int) $r['id'] !== (int) $subject['id'] && (int) $r['age'] >= $dol ) {
				$cohort[] = sn_analytics_posts_cumulative_at( $r['by_dol'], $dol );
			}
		}
		$base[] = sn_analytics_median( $cohort );
	}

	$max = 1.0;
	foreach ( array_merge( $subj, $base ) as $v ) {
		$max = max( $max, (float) $v );
	}
	$n    = $age + 1;
	$w    = 600.0;
	$top  = 8.0;
	$bse  = 78.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$plot = function ( $cum ) use ( $step, $top, $bse, $max ) {
		$px = array();
		$py = array();
		foreach ( array_values( $cum ) as $i => $v ) {
			$px[] = round( $i * $step, 2 );
			$py[] = round( $bse - ( (float) $v / $max ) * ( $bse - $top ), 2 );
		}
		return snt_analytics_smooth_path( $px, $py, $top, $bse );
	};
	$base_d = $plot( $base );
	$subj_d = $plot( $subj );

	snt_an_panel_open(
		__( 'Lifecycle: this Note vs your typical at each age', 'signal-and-noise-tools' ),
		array( 'inside_class' => 'inside inside-flush sn-an-panel' )
	);
	echo '<svg viewBox="0 0 600 86" preserveAspectRatio="none" role="img" class="sn-an-spark" style="width:100%;height:120px">';
	echo '<path d="' . esc_attr( $base_d ) . '" fill="none" stroke="#646970" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $subj_d ) . '" fill="none" stroke="#2271b1" stroke-width="2" vector-effect="non-scaling-stroke"/>';
	echo '</svg>';
	echo '<p class="sn-an-foot">'
		. esc_html__( 'Blue: this Note. Grey dashed: the median of your recent Notes at the same day of life.', 'signal-and-noise-tools' )
		. '</p>';
	snt_an_panel_close();
}

/**
 * Catalog leaderboard — recent posts by lifetime views + views-per-day-of-life,
 * with each post's decay shape. Routes through the shared snt_an_kv_table()
 * column-spec mode (D5 §4, inc/analytics-panels.php, holdout retirement
 * v9.43.x): the Post column carries caller-built link+strong+age markup
 * (html=true) and Shape carries either the decay text or the muted em-dash
 * fallback (also html=true — the caller already ran esc_html() on the text
 * branch, mirroring how sn_lifecycle_status_pill() is consumed at
 * inc/analytics-posts-lifecycle-admin.php). Byte-identical to the pre-adoption
 * hand-rolled table (data_colname was always on here).
 *
 * @param array $rows Leaderboard rows.
 */
function snt_analytics_render_posts_leaderboard( $rows ) {
	$cols = array(
		array( 'label' => __( 'Post', 'signal-and-noise-tools' ), 'html' => true ),
		array( 'label' => __( 'Lifetime views', 'signal-and-noise-tools' ), 'class' => 'num' ),
		array( 'label' => __( 'Per day', 'signal-and-noise-tools' ), 'class' => 'num' ),
		array( 'label' => __( 'Shape', 'signal-and-noise-tools' ), 'html' => true ),
	);

	$kv_rows = array();
	foreach ( (array) $rows as $r ) {
		$decay     = (string) ( $r['decay'] ?? '' );
		$kv_rows[] = array(
			'<a href="' . esc_url( (string) $r['permalink'] ) . '"><strong>' . esc_html( (string) $r['title'] ) . '</strong></a> <span class="sn-an-muted">' . esc_html( (int) $r['age'] . 'd' ) . '</span>',
			number_format_i18n( (int) $r['lifetime'] ),
			number_format_i18n( (float) $r['per_day'] ),
			'' !== $decay ? esc_html( $decay ) : '<span class="sn-an-muted">—</span>',
		);
	}

	snt_an_kv_table(
		__( 'Your catalog', 'signal-and-noise-tools' ),
		$kv_rows,
		$cols,
		array(
			'empty'        => __( 'No posts yet.', 'signal-and-noise-tools' ),
			'data_colname' => true,
		)
	);
}
