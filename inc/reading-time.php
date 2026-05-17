<?php
/**
 * Signal & Noise Tools — Reading time calculation, caching, and legacy cleanup.
 *
 * Owns three concerns:
 *
 *   1. Calculation. `sn_calculate_reading_time()` strips Gutenberg block
 *      comments, shortcodes, and HTML, then divides word count by a
 *      filterable WPM (default 225). One-minute floor so a haiku doesn't
 *      render "0 min read".
 *
 *   2. Caching. Result stored in `_sn_reading_time_minutes` post meta
 *      (private, hidden from the Custom Fields UI). The `[sn_reading_time]`
 *      shortcode reads from this cache, populating lazily on first render.
 *      Rebuilt on `wp_after_insert_post` so edits update immediately.
 *
 *   3. Legacy cleanup admin tab. Older posts had reading-time text typed
 *      inline by hand ("8-minute read", "10 min read"). Admin page ships a
 *      Preview/Apply pair that scans post_content/excerpt/custom fields
 *      for these patterns and offers to remove them. The
 *      `sn_admin_reading_time_tab` action hook was a cross-package contract
 *      in v1.2.0 (theme listening for plugin-fired action) — now intra-plugin
 *      after Phase 3 (theme v8.4.0 / plugin v1.3.0).
 *
 * The theme's `inc/page-notes-render.php` reads the `_sn_reading_time_minutes`
 * post meta directly (stable contract — same meta key as before the move).
 *
 * Moved from theme inc/reading-time.php in Phase 3 (2026-05-16). Function
 * names unchanged.
 *
 * @package SignalNoiseTools
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_READING_TIME_META_KEY = '_sn_reading_time_minutes';
const SN_READING_TIME_DEFAULT_WPM = 225;

/**
 * Regex matching legacy hand-typed reading-time strings.
 *
 * Tolerates a leading approximation marker (~), digits, either a space
 * or hyphen separator, "min"/"mins"/"minute"/"minutes", and the trailing
 * word "read". Case-insensitive. Designed to NOT match the literal
 * shortcode token `[sn_reading_time]`.
 */
const SN_READING_TIME_LEGACY_REGEX = '/~?\s*\d+\s*[-\s]\s*(?:minutes?|mins?)\s+read\b/i';

/**
 * Compute reading time in whole minutes for a post body.
 *
 * Strips block comments (<!-- wp:* -->), shortcodes, and HTML before
 * counting words. The result is filterable via `sn_reading_time_minutes`
 * if a caller wants to override the calculation (e.g. add code-block
 * weighting).
 *
 * @param int|WP_Post $post Post ID or object.
 * @return int Minutes (>= 1).
 */
function sn_calculate_reading_time( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return 1;
	}

	$content = $post->post_content;
	$content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', $content ); // strip block delimiters
	$content = strip_shortcodes( $content );
	$content = wp_strip_all_tags( $content );

	$words   = str_word_count( $content );
	$wpm     = (int) apply_filters( 'sn_reading_time_wpm', SN_READING_TIME_DEFAULT_WPM, $post );
	$wpm     = max( 1, $wpm );
	$minutes = max( 1, (int) ceil( $words / $wpm ) );

	return (int) apply_filters( 'sn_reading_time_minutes', $minutes, $post, $words, $wpm );
}

/**
 * Get the cached reading time, populating the cache on miss.
 *
 * @param int|WP_Post|null $post Post ID, object, or null for current.
 * @return int Minutes (>= 1).
 */
function sn_get_reading_time( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return 1;
	}

	$cached = get_post_meta( $post->ID, SN_READING_TIME_META_KEY, true );
	if ( '' !== $cached && null !== $cached ) {
		return max( 1, (int) $cached );
	}

	$minutes = sn_calculate_reading_time( $post );
	update_post_meta( $post->ID, SN_READING_TIME_META_KEY, $minutes );
	return $minutes;
}

/**
 * Recompute and cache on every post save. Skips revisions and autosaves.
 */
add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	$minutes = sn_calculate_reading_time( $post );
	update_post_meta( $post_id, SN_READING_TIME_META_KEY, $minutes );
}, 10, 4 );

/**
 * Shortcode: [sn_reading_time] → "X min read".
 *
 * No-args form operates on the current post (the legacy pre-6.5.5
 * behaviour preserved unchanged). With `slug="path/to/page"` the
 * shortcode resolves a different post via `get_page_by_path()` and
 * reports its cached reading time — used by the /provenance pillar
 * index to surface the live read-time of each child long-form rather
 * than hardcoded values that drift when the prose evolves.
 *
 * Returns empty string when the slug-targeted post doesn't exist
 * (e.g., during the brief window after a deploy but before seed
 * migrations have run). The empty fallback is visually graceful enough
 * — the migration window is short and self-heals.
 *
 * Format is filterable via `sn_reading_time_format`. The default uses
 * "{minutes} min read"; pass "{minutes}-minute read" for the long form.
 */
add_shortcode( 'sn_reading_time', function( $atts ) {
	$atts = shortcode_atts( array(
		'slug' => '',
	), $atts, 'sn_reading_time' );

	if ( '' !== $atts['slug'] ) {
		$post = get_page_by_path( $atts['slug'] );
		if ( ! $post ) {
			return '';
		}
	} else {
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
	}

	$minutes = sn_get_reading_time( $post );
	$format  = (string) apply_filters( 'sn_reading_time_format', '{minutes} min read', $post, $minutes );
	return esc_html( str_replace( '{minutes}', (string) $minutes, $format ) );
} );

/**
 * Process [sn_reading_time] inside block template parts (mirror of the
 * pattern used for [current_year] in inc/setup.php).
 *
 * Two specific strpos checks rather than a prefix-match: catches the
 * no-args form `[sn_reading_time]` AND the slug-attributed form
 * `[sn_reading_time slug="..."]`, but does NOT false-positive on
 * lookalikes (e.g. `[sn_reading_timex]`) the way a prefix-match would.
 *
 * Why both forms need to be caught here: post_content shortcodes
 * resolve via WordPress core's `the_content` filter chain (do_shortcode
 * at priority 11) regardless of this hook. But TEMPLATE files like
 * page-notes.html aren't post_content — they're rendered through the
 * block template engine, which doesn't apply `the_content`. So any
 * shortcode used in template markup needs this render_block bridge.
 *
 * History note: an earlier change (commit 949007e) used a loose
 * prefix-match `[sn_reading_time` and was suspected of causing the
 * /notes hang incident. Subsequent diagnosis (e006841 onward)
 * identified the actual root cause as the OG generator's UTF-8
 * truncation loop in inc/og-image.php — completely unrelated to this
 * filter. The targeted two-strpos form here is correct by design and
 * shouldn't be conflated with the loose prefix-match that was
 * reverted defensively at the time.
 */
add_filter( 'render_block', function( $block_content, $block ) {
	if ( false !== strpos( $block_content, '[sn_reading_time]' )
		|| false !== strpos( $block_content, '[sn_reading_time slug=' ) ) {
		$block_content = do_shortcode( $block_content );
	}
	return $block_content;
}, 10, 2 );

/**
 * Scan posts/pages for legacy hand-typed reading-time strings.
 *
 * Returns an array keyed by post ID, each entry containing the post
 * object, content matches, excerpt matches, and meta matches. A "match"
 * is an array of [match_string, context_snippet] pairs. Used by the
 * admin preview UI; intentionally read-only.
 *
 * @return array<int, array{post: WP_Post, content: array, excerpt: array, meta: array}>
 */
function sn_find_legacy_reading_time() {
	$posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	$report = array();
	foreach ( $posts as $post_id ) {
		$post  = get_post( $post_id );
		$entry = array(
			'post'    => $post,
			'content' => sn_extract_reading_time_matches( $post->post_content ),
			'excerpt' => sn_extract_reading_time_matches( $post->post_excerpt ),
			'meta'    => array(),
		);

		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( '_' === $key[0] ) {
				continue; // private meta — never auto-edit
			}
			foreach ( (array) $values as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$matches = sn_extract_reading_time_matches( $value );
				if ( $matches ) {
					$entry['meta'][ $key ] = array_merge( $entry['meta'][ $key ] ?? array(), $matches );
				}
			}
		}

		if ( $entry['content'] || $entry['excerpt'] || $entry['meta'] ) {
			$report[ $post_id ] = $entry;
		}
	}
	return $report;
}

/**
 * Extract [match, snippet] pairs from a string. Snippet is ~50 chars of
 * surrounding context with the match wrapped in `<<…>>` markers for the
 * preview UI. Returns an empty array when no matches.
 */
function sn_extract_reading_time_matches( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return array();
	}
	if ( ! preg_match_all( SN_READING_TIME_LEGACY_REGEX, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$out = array();
	foreach ( $matches[0] as $m ) {
		$match  = $m[0];
		$offset = $m[1];
		$start  = max( 0, $offset - 50 );
		$end    = min( strlen( $text ), $offset + strlen( $match ) + 50 );
		$before = substr( $text, $start, $offset - $start );
		$after  = substr( $text, $offset + strlen( $match ), $end - $offset - strlen( $match ) );
		$out[]  = array(
			'match'   => $match,
			'snippet' => trim( ( $start > 0 ? '…' : '' ) . $before . '<<' . $match . '>>' . $after . ( $end < strlen( $text ) ? '…' : '' ) ),
		);
	}
	return $out;
}

/**
 * Apply the legacy cleanup: strip the matched substrings from
 * post_content, post_excerpt, and public meta. Also collapses empty
 * inline wrappers (<p></p>, <span></span>) left behind by the removal.
 *
 * Returns a count of edited posts. Recomputes the reading-time cache for
 * each edited post afterward.
 *
 * @return int Number of posts modified.
 */
function sn_apply_legacy_reading_time_cleanup() {
	$report  = sn_find_legacy_reading_time();
	$updated = 0;

	foreach ( $report as $post_id => $entry ) {
		$post    = $entry['post'];
		$changed = false;

		if ( $entry['content'] ) {
			$new = preg_replace( SN_READING_TIME_LEGACY_REGEX, '', $post->post_content );
			$new = preg_replace( '#<(p|span|small|em|strong|i|b)[^>]*>\s*</\1>#i', '', $new );
			if ( $new !== $post->post_content ) {
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $new ) );
				$changed = true;
			}
		}
		if ( $entry['excerpt'] ) {
			$new = preg_replace( SN_READING_TIME_LEGACY_REGEX, '', $post->post_excerpt );
			if ( $new !== $post->post_excerpt ) {
				wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => $new ) );
				$changed = true;
			}
		}
		foreach ( $entry['meta'] as $key => $matches ) {
			$values = get_post_meta( $post_id, $key, false );
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$new = preg_replace( SN_READING_TIME_LEGACY_REGEX, '', $value );
				if ( $new !== $value ) {
					update_post_meta( $post_id, $key, $new, $value );
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			delete_post_meta( $post_id, SN_READING_TIME_META_KEY );
			sn_get_reading_time( $post_id ); // repopulate from fresh content
			$updated++;
		}
	}
	return $updated;
}

/**
 * Render the Reading Time admin UI on its dedicated tab. Hooked to
 * `sn_admin_reading_time_tab` emitted by inc/admin-page.php when the
 * user selects the Reading Time tab. Pre-v7.0.x this was a card on
 * the Dashboard tab via `sn_admin_dashboard_extras` — moved out as
 * part of the Dashboard-grew-too-big reorg.
 */
add_action( 'sn_admin_reading_time_tab', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// POST handling lives in sn_handle_admin_post() (admin_init, PRG).
	// This callback is render-only. Preview is a GET-driven scan (read-
	// only, no PRG needed).
	$preview     = isset( $_GET['sn_rt_preview'] );
	$base_url    = admin_url( 'admin.php?page=sn-reading-time' );
	$preview_url = esc_url( add_query_arg( 'sn_rt_preview', '1', $base_url ) );
	$report      = $preview ? sn_find_legacy_reading_time() : array();
	$report_n    = count( $report );

	echo '<p class="sn-prose">Word count ÷ ' . (int) SN_READING_TIME_DEFAULT_WPM . ' WPM, cached in <code>_sn_reading_time_minutes</code> post meta and rebuilt on save. The cleanup tool below scans for hand-typed strings like "8-minute read" left over from before the shortcode existed.</p>';

	// ── TOOL FIELDSET ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Legacy string cleanup</h2>';
	echo '<p class="sn-fieldset-intro">Scan posts + pages for hand-typed reading-time strings; review the matches; apply the cleanup. The shortcode produces fresh values automatically — this tool only removes the legacy ones.</p>';

	echo '<div class="sn-card-grid">';

	// Preview card — always shown
	echo '<div class="sn-card sn-card--narrow">';
	echo '<strong>1 · Preview</strong>';
	echo '<p class="sn-helper">Scan all posts and pages for legacy reading-time strings. Read-only.</p>';
	echo '<a href="' . $preview_url . '" class="button">' . ( $preview ? 'Re-run preview' : 'Run preview' ) . '</a>';
	echo '</div>';

	// Apply card — shown when preview has run
	if ( $preview ) {
		echo '<form method="post" class="sn-card sn-card--narrow">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<strong>2 · Apply</strong>';
		if ( 0 === $report_n ) {
			echo '<p class="sn-helper">No matches found — nothing to apply.</p>';
		} else {
			echo '<p class="sn-helper"><strong>Destructive.</strong> Removes the ' . (int) $report_n . ' match(es) below from post content / excerpts / meta. Cannot be undone — back up first.</p>';
		}
		echo '<button type="submit" name="sn_action" value="apply_reading_time_cleanup" class="button button-primary"' . ( 0 === $report_n ? ' disabled' : '' ) . '>Apply to ' . (int) $report_n . ' post(s)</button>';
		echo '</form>';
	}

	echo '</div>'; // .sn-card-grid
	echo '</div>'; // .sn-fieldset

	// ── PREVIEW RESULTS ──
	if ( $preview ) {
		if ( 0 === $report_n ) {
			echo '<div class="sn-status-box">';
			echo '<div>';
			echo '<p class="sn-status-box-title">Clean</p>';
			echo '<p class="sn-status-box-body">No legacy reading-time strings found in any post, page, excerpt, or post meta.</p>';
			echo '</div>';
			echo '<span class="sn-pill sn-pill--ok">All clean</span>';
			echo '</div>';
			return;
		}

		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">Matches (' . (int) $report_n . ')</h2>';
		echo '<p class="sn-fieldset-intro">Each row shows where a legacy string lives. The Apply action above removes all of them.</p>';
		echo '<table class="widefat striped"><thead><tr><th class="column-id">ID</th><th>Title</th><th>Where</th><th>Match</th></tr></thead><tbody>';
		foreach ( $report as $post_id => $entry ) {
			$rows = array();
			foreach ( $entry['content'] as $m ) $rows[] = array( 'content', $m );
			foreach ( $entry['excerpt'] as $m ) $rows[] = array( 'excerpt', $m );
			foreach ( $entry['meta'] as $key => $matches ) {
				foreach ( $matches as $m ) $rows[] = array( 'meta:' . $key, $m );
			}
			foreach ( $rows as $i => $row ) {
				echo '<tr>';
				echo '<td>' . ( 0 === $i ? '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . (int) $post_id . '</a>' : '' ) . '</td>';
				echo '<td>' . ( 0 === $i ? esc_html( get_the_title( $post_id ) ) : '' ) . '</td>';
				echo '<td><code>' . esc_html( $row[0] ) . '</code></td>';
				echo '<td><span class="sn-pill sn-pill--err sn-mono">' . esc_html( $row[1]['match'] ) . '</span><br><small class="sn-helper sn-rt-snippet">' . esc_html( $row[1]['snippet'] ) . '</small></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>'; // .sn-fieldset
	}
} );
