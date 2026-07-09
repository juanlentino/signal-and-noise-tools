<?php
/**
 * Signal & Noise Tools — Analytics recommendations engine + panel render.
 *
 * Pure rules over already-cached signals: each rule reads a durable/transient-
 * cached source (never triggers an expensive live scan) and returns at most one
 * actionable card deep-linked to the tool that acts on it. Cookieless: rules read
 * aggregate counts + published-content metadata only, never per-person data.
 * Rendered as a panel at the top of the Analytics → Content view (annotations R3b).
 *
 * @package SignalNoiseTools
 * @since 9.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the ordered recommendation card list. Each rule returns one card or null;
 * nulls are filtered. Empty result is first-class ("nothing needs attention").
 *
 * @return array<int,array{id:string,title:string,detail:string,count:int,action_url:string,action_label:string}>
 */
function sn_analytics_recommendations() {
	$cards = array(
		sn_analytics_rec_refresh(),
		sn_analytics_rec_unlinked(),
		sn_analytics_rec_seo_meta(),
	);
	return array_values( array_filter( $cards ) );
}

/**
 * Refresh rule: cooling, non-evergreen posts worth a refresh, from the transient-
 * cached lifecycle bundle. Deep-links to the Posts view (which renders the refresh
 * queue). Null when there is no bundle or zero candidates.
 *
 * @return array|null
 */
function sn_analytics_rec_refresh() {
	$bundle = function_exists( 'sn_analytics_posts_lifecycle' ) ? sn_analytics_posts_lifecycle() : null;
	$n      = is_array( $bundle ) ? (int) ( $bundle['summary']['refresh_candidates'] ?? 0 ) : 0;
	if ( $n < 1 ) {
		return null;
	}
	return array(
		'id'           => 'refresh',
		// translators: %d is the number of cooling posts worth a refresh.
		'title'        => sprintf( _n( '%d cooling post worth a refresh', '%d cooling posts worth a refresh', $n, 'signal-and-noise-tools' ), $n ),
		'detail'       => 'These posts are past their peak and not marked evergreen — a refresh can revive them.',
		'count'        => $n,
		'action_url'   => admin_url( 'index.php?page=sn-analytics&sn_view=posts' ),
		'action_label' => 'Open the refresh queue',
	);
}

/**
 * Unlinked-mentions rule: notes that title-mention another note without linking
 * it, read from the DURABLE cached Health scan (sn_health_last_scan() — a
 * get_option, never a live re-scan; the check itself is an O(n^2) TF-IDF pass).
 * Deep-links to the Health tab where Suggest/Apply lives. Null when no scan or
 * zero mentions.
 *
 * @return array|null
 */
function sn_analytics_rec_unlinked() {
	$scan = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$n    = is_array( $scan ) ? (int) ( $scan['checks']['unlinked_mentions']['count'] ?? 0 ) : 0;
	if ( $n < 1 ) {
		return null;
	}
	return array(
		'id'           => 'unlinked',
		// translators: %d is the number of unlinked mentions between notes.
		'title'        => sprintf( _n( '%d unlinked mention between notes', '%d unlinked mentions between notes', $n, 'signal-and-noise-tools' ), $n ),
		'detail'       => 'One note names another without linking it. Health → Suggest proposes an anchor already in your prose.',
		'count'        => $n,
		'action_url'   => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
		'action_label' => 'Review link suggestions',
	);
}

/**
 * SEO rule: published Pages that resolve to an EMPTY meta description ship
 * descriptionless. Uses the shared resolver (override → excerpt → theme filter),
 * so the theme's supplied copy for /about,/contact,/colophon,/music,/services is
 * honored — only a Page with no excerpt AND no theme entry is flagged. Deep-links
 * to the first such Page's editor (adding an excerpt fixes it; the theme route
 * map is the alternative). Cross-repo signal, plugin-only code. Null when none.
 *
 * @return array|null
 */
function sn_analytics_rec_seo_meta() {
	// v9.6.0: sn_seo_resolve_singular_description() is not yet on main — the
	// per-Page description path is still inline in sn_seo_meta_for_current_view()
	// (only the TITLE path got a pure resolver, back in v9.3.0). This rule ships
	// DORMANT: the guard below self-disables it until a follow-up extracts the
	// resolver. Refresh + unlinked are the two live rules today.
	if ( ! function_exists( 'sn_seo_resolve_singular_description' ) || ! function_exists( 'get_posts' ) ) {
		return null;
	}
	$pages   = (array) get_posts( array(
		'post_type'        => 'page',
		'post_status'      => 'publish',
		'numberposts'      => 100,
		'suppress_filters' => false,
	) );
	$missing = array();
	foreach ( $pages as $p ) {
		if ( is_object( $p ) && '' === trim( (string) sn_seo_resolve_singular_description( $p ) ) ) {
			$missing[] = $p;
		}
	}
	$n = count( $missing );
	if ( $n < 1 ) {
		return null;
	}
	$first = (int) ( $missing[0]->ID ?? 0 );
	return array(
		'id'           => 'seo_meta',
		// translators: %d is the number of published pages that ship with no meta description.
		'title'        => sprintf( _n( '%d page ships without a meta description', '%d pages ship without a meta description', $n, 'signal-and-noise-tools' ), $n ),
		'detail'       => 'Search engines and AI crawlers get no summary for these routes. Add a Page excerpt (or theme route copy) to fix.',
		'count'        => $n,
		'action_url'   => admin_url( 'post.php?post=' . $first . '&action=edit' ),
		'action_label' => 'Add a description',
	);
}

/**
 * Render the recommendations panel: deterministic, deep-linked action cards from
 * sn_analytics_recommendations(). Empty is first-class ("nothing needs attention
 * right now"). All dynamic output escaped; wp-admin-native. Uses the shared panel
 * primitive (snt_an_panel_open/close) + the .sn-an-rec* rules in
 * assets/analytics/analytics-admin.css. Rendered at the top of the Content view.
 *
 * @since 9.6.0 Re-homed from the retired Intelligence tab into the Content view.
 * @return void
 */
function snt_analytics_render_recommendations_panel() {
	$cards = function_exists( 'sn_analytics_recommendations' ) ? sn_analytics_recommendations() : array();

	snt_an_panel_open( 'Recommendations' );
	if ( empty( $cards ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'Nothing needs attention right now.', 'signal-and-noise-tools' ) . '</p>';
		snt_an_panel_close();
		return;
	}
	echo '<ul class="sn-an-recs">';
	foreach ( $cards as $c ) {
		echo '<li class="sn-an-rec">';
		echo '<p class="sn-an-rec-title">' . esc_html( (string) ( $c['title'] ?? '' ) ) . '</p>';
		if ( ! empty( $c['detail'] ) ) {
			echo '<p class="sn-an-rec-detail">' . esc_html( (string) $c['detail'] ) . '</p>';
		}
		if ( ! empty( $c['action_url'] ) ) {
			echo '<a class="button button-small" href="' . esc_url( (string) $c['action_url'] ) . '">' . esc_html( (string) ( $c['action_label'] ?? 'Open' ) ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ul>';
	snt_an_panel_close();
}
