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
 * v9.38.0 (D2): request-level memo — the headline band (every shared-chrome
 * view) AND the Content panel both consume the cards in one request; the
 * seo-meta rule's get_posts() must not run twice. $refresh re-primes (CLI
 * suites use it at fixture boundaries; production never passes it).
 *
 * @param bool $refresh Recompute even when the request memo is primed.
 * @return array<int,array{id:string,title:string,detail:string,count:int,action_url:string,action_label:string}>
 */
function sn_analytics_recommendations( $refresh = false ) {
	static $memo = null;
	if ( $refresh || null === $memo ) {
		$cards = array(
			sn_analytics_rec_refresh(),
			sn_analytics_rec_unlinked(),
			sn_analytics_rec_seo_meta(),
		);
		$memo = array_values( array_filter( $cards ) );
	}
	return $memo;
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
		'detail'       => 'These posts are past their peak and not marked evergreen: a refresh can revive them.',
		'count'        => $n,
		'action_url'   => admin_url( 'index.php?page=sn-analytics&sn_view=posts' ),
		'action_label' => 'Open the refresh queue',
	);
}

/**
 * Unlinked-mentions rule: notes that title-mention another note without linking
 * it, read from the DURABLE cached Health scan (sn_health_last_scan() — a
 * get_option, never a live re-scan; the check itself is an exact-title stripos sweep).
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
 * descriptionless. Resolves each Page's description the SAME way the site emits
 * it (sn_seo_description_for_post): the front page, /notes and /provenance read
 * their SEO settings; every other Page reads its override → excerpt → theme
 * filter. So a Page is flagged only when its ACTUAL emitted description is empty
 * — not when some unrelated field is (the v9.22.3 fix: the front page is
 * described by a setting, so checking its excerpt false-positived a homepage
 * that was fine). Pages the owner hides from search (the per-page noindex
 * toggle) are skipped — a summary a crawler will never read isn't worth nagging
 * about. The card NAMES each remaining Page and deep-links it to its own editor.
 * Cross-repo signal, plugin-only code. Null when none. The finished card (or
 * its absence, as a 'none' sentinel) caches for an hour in the
 * sn_an_rec_seo_meta transient (v9.39.0) — parity with the other two rules'
 * pre-computed artifacts.
 *
 * @return array|null
 */
function sn_analytics_rec_seo_meta() {
	// The guard is defensive: sn_seo_resolve_singular_description() shipped in
	// v9.7.0 (inc/seo.php), so this rule is LIVE in production. It stays guarded
	// so the recs panel degrades gracefully if the SEO module is ever absent.
	// Kept OUTSIDE the transient envelope below: an install missing the SEO
	// module must keep re-checking function_exists() every request, never lock
	// itself into caching 'none' forever.
	if ( ! function_exists( 'sn_seo_description_for_post' ) || ! function_exists( 'get_posts' ) ) {
		return null;
	}

	// v9.39.0 (D3 fast-follow): the only rule that computed inline — its Page
	// scan now caches for an hour like its peers' pre-computed artifacts
	// (lifecycle transient / health-scan option). 'none' sentinel
	// distinguishes a cached null from a cache miss. No invalidation hooks —
	// owner-glance card.
	$cached = get_transient( 'sn_an_rec_seo_meta' );
	if ( false !== $cached ) {
		return 'none' === $cached ? null : $cached;
	}

	$pages   = (array) get_posts( array(
		'post_type'        => 'page',
		'post_status'      => 'publish',
		'numberposts'      => 100,
		'suppress_filters' => false,
	) );
	$missing = array();
	foreach ( $pages as $p ) {
		if ( ! is_object( $p ) ) {
			continue;
		}
		// A page the owner hides from search (noindex) doesn't need a meta
		// description — a crawler will never use it — so don't flag it. Mirrors
		// the noindex read in inc/seo.php.
		$noindex = function_exists( 'sn_post_settings_get_noindex' )
			? sn_post_settings_get_noindex( (int) ( $p->ID ?? 0 ) )
			: ( '1' === (string) get_post_meta( (int) ( $p->ID ?? 0 ), '_sn_noindex', true ) );
		if ( $noindex ) {
			continue;
		}
		if ( '' === trim( (string) sn_seo_description_for_post( $p ) ) ) {
			$missing[] = $p;
		}
	}
	$n = count( $missing );
	if ( $n < 1 ) {
		$card = null;
	} else {
		$items = array();
		foreach ( $missing as $p ) {
			$id    = (int) ( $p->ID ?? 0 );
			$label = trim( (string) ( $p->post_title ?? '' ) );
			if ( '' === $label ) {
				$label = (string) ( $p->post_name ?? ( '#' . $id ) );
			}
			$items[] = array(
				'label' => $label,
				'url'   => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			);
		}
		$card = array(
			'id'     => 'seo_meta',
			// translators: %d is the number of published pages that ship with no meta description.
			'title'  => sprintf( _n( '%d page ships without a meta description', '%d pages ship without a meta description', $n, 'signal-and-noise-tools' ), $n ),
			'detail' => 'Search engines and AI crawlers get no summary for these pages. Add a Page Excerpt to each:',
			'count'  => $n,
			'items'  => $items,
		);
	}

	set_transient( 'sn_an_rec_seo_meta', ( null === $card ? 'none' : $card ), HOUR_IN_SECONDS );
	return $card;
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
	$rec   = function_exists( 'sn_analytics_recommend' )
		? sn_analytics_recommend()
		: array( 'cards' => function_exists( 'sn_analytics_recommendations' ) ? sn_analytics_recommendations() : array(), 'brief' => '', 'source' => 'fallback' );
	$cards = is_array( $rec['cards'] ?? null ) ? $rec['cards'] : array();

	// v9.35.0 (maturity I6): the shared tier badge in the panel header. The
	// wp_kses_post guard covers CLI harnesses that load the real panel primitive
	// without WP (tests/analytics-view-content.php) — the panel header renders
	// header_meta through wp_kses_post, so only pass it when the full stack can
	// consume it; both functions always exist on a live install.
	snt_an_panel_open( __( 'Recommendations', 'signal-and-noise-tools' ), array(
		'header_meta' => function_exists( 'snt_analytics_tier_badge' ) && function_exists( 'wp_kses_post' )
			? snt_analytics_tier_badge( 'prescriptive' )
			: '',
	) );
	if ( empty( $cards ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'No action cards right now.', 'signal-and-noise-tools' ) . '</p>';
		snt_an_panel_close();
		return;
	}
	// v9.38.0 (D2): the AI priority brief retired — this div only renders when the
	// documented 'sn_analytics_recommender' filter deliberately fills it. Empty
	// brief (the default) renders exactly the deterministic cards, no div.
	$brief = trim( (string) ( $rec['brief'] ?? '' ) );
	if ( '' !== $brief ) {
		echo '<div class="sn-an-rec-brief" data-source="' . esc_attr( (string) ( $rec['source'] ?? 'filter' ) ) . '">' . wp_kses_post( $brief ) . '</div>';
	}
	echo '<ul class="sn-an-recs">';
	foreach ( $cards as $c ) {
		echo '<li class="sn-an-rec">';
		echo '<p class="sn-an-rec-title">' . esc_html( (string) ( $c['title'] ?? '' ) ) . '</p>';
		if ( ! empty( $c['detail'] ) ) {
			echo '<p class="sn-an-rec-detail">' . esc_html( (string) $c['detail'] ) . '</p>';
		}
		if ( ! empty( $c['items'] ) && is_array( $c['items'] ) ) {
			echo '<ul class="sn-an-rec-items">';
			foreach ( $c['items'] as $it ) {
				$label = (string) ( $it['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$url = (string) ( $it['url'] ?? '' );
				echo '<li>' . ( '' !== $url
					? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
					: esc_html( $label ) ) . '</li>';
			}
			echo '</ul>';
		} elseif ( ! empty( $c['action_url'] ) ) {
			echo '<a class="button button-small" href="' . esc_url( (string) $c['action_url'] ) . '">' . esc_html( (string) ( $c['action_label'] ?? __( 'Open', 'signal-and-noise-tools' ) ) ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ul>';
	snt_an_panel_close();
}

/**
 * v9.38.0 (D2, single voice): the deterministic cards ARE the result — the AI
 * priority brief retired into the digest (the screen's one voice; the band
 * feeds the top card's title into the digest prompt instead). The documented
 * 'sn_analytics_recommender' filter seam survives unchanged and remains the
 * only way a brief reaches the panel.
 *
 * @param array|null $signals Signal[] handed to the filter; never self-fetched (v9.38.0).
 * @return array{cards:array, brief:string, source:string, model:?string}
 */
function sn_analytics_recommend( $signals = null ) {
	$cards    = sn_analytics_recommendations();
	$signals  = is_array( $signals ) ? $signals : array();
	$override = function_exists( 'apply_filters' ) ? apply_filters( 'sn_analytics_recommender', null, $cards, $signals ) : null;
	if ( is_array( $override ) && isset( $override['cards'] ) ) {
		return $override;
	}
	return array( 'cards' => $cards, 'brief' => '', 'source' => 'fallback', 'model' => null );
}
