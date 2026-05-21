<?php
/**
 * Signal & Noise Tools — Content Health checks.
 *
 * Detection-only scans of the post / attachment graph. Four independent
 * checks, all dispatched from a single "Run scan" button on the Health
 * admin tab. Results cache for 24h in a transient — visiting the tab
 * shows the last scan; clicking "Run scan" re-computes and overwrites.
 *
 * The checks intentionally do NOT call any AI / LLM service in v1.
 * Findings are surfaced as plain lists with deep-links to the editor;
 * the user fixes them manually. AI-assisted fix proposals are a future
 * extension (see v3.5.x roadmap notes in the handoff).
 *
 * The 4 checks:
 *
 *   1. missing_alt       — image attachments and inline <img> tags
 *                          without an alt attribute
 *   2. orphaned_media    — attachments not used as a featured image
 *                          and not referenced in any post body
 *   3. broken_links      — internal links in post_content that 404 or
 *                          return network errors (cached HEAD requests)
 *   4. stale_posts       — published posts unedited in the last 12 months
 *
 * @package SignalNoiseTools
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_HEALTH_CACHE_KEY',     'sn_health_last_scan' );
define( 'SN_HEALTH_CACHE_TTL',     DAY_IN_SECONDS );
define( 'SN_HEALTH_STALE_MONTHS',  12 );
define( 'SN_HEALTH_LINK_CACHE_TTL', DAY_IN_SECONDS );
define( 'SN_HEALTH_LINK_TIMEOUT',  5 );

/**
 * Run all 4 checks and cache the combined result. Returns the array
 * regardless of cache state (callers wanting the cached version
 * should sn_health_last_scan() instead).
 */
function sn_health_run_scan() {
	$started = microtime( true );

	$result = array(
		'scanned_at'   => time(),
		'elapsed_ms'   => 0,
		'site_url'     => home_url( '/' ),
		'checks'       => array(
			'missing_alt'    => sn_health_check_missing_alt(),
			'orphaned_media' => sn_health_check_orphaned_media(),
			'broken_links'   => sn_health_check_broken_links(),
			'stale_posts'    => sn_health_check_stale_posts(),
		),
	);
	$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

	set_transient( SN_HEALTH_CACHE_KEY, $result, SN_HEALTH_CACHE_TTL );
	return $result;
}

function sn_health_last_scan() {
	$cached = get_transient( SN_HEALTH_CACHE_KEY );
	return is_array( $cached ) ? $cached : null;
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 1: missing alt text
 * Two passes:
 *   a. image attachments where _wp_attachment_image_alt is empty
 *   b. inline <img> tags in published post_content with no alt= attr
 * ───────────────────────────────────────────────────────────────────── */
function sn_health_check_missing_alt() {
	global $wpdb;
	$findings = array();

	// a) attachments without alt meta.
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.guid
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON ( pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt' )
		 WHERE p.post_type = 'attachment'
		   AND p.post_mime_type LIKE 'image/%'
		   AND ( pm.meta_value IS NULL OR pm.meta_value = '' )
		 ORDER BY p.post_date DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$findings[] = array(
				'subject_type' => 'attachment',
				'subject_id'   => (int) $r['ID'],
				'subject_url'  => (string) $r['guid'],
				'subject_label' => (string) $r['post_title'],
				'edit_url'     => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'note'         => 'Image attachment has no alt text — bad for SEO and screen readers.',
			);
		}
	}

	// b) inline <img> tags without alt in published posts/pages.
	$content_rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content LIKE '%<img%'
		 LIMIT 1000",
		ARRAY_A
	);
	if ( is_array( $content_rows ) ) {
		foreach ( $content_rows as $row ) {
			$inline = sn_health_extract_inline_imgs_without_alt( (string) $row['post_content'] );
			foreach ( $inline as $src ) {
				$findings[] = array(
					'subject_type'  => 'inline_img',
					'subject_id'    => (int) $row['ID'],
					'subject_url'   => $src,
					'subject_label' => (string) $row['post_title'],
					'edit_url'      => admin_url( 'post.php?post=' . (int) $row['ID'] . '&action=edit' ),
					'note'          => 'Inline <img> in post body has no alt attribute.',
				);
			}
		}
	}

	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => 'Missing alt text',
		'fix_hint' => 'Open the editor and add a descriptive alt attribute to each image. Empty alt="" is valid only for purely decorative images.',
	);
}

/**
 * Parse post_content for <img> tags that lack an alt attribute.
 * Pure regex — content has already been written to the DB so a
 * proper HTML parser is overkill for this check.
 *
 * @param string $content
 * @return array  src URLs of <img> tags without alt
 */
function sn_health_extract_inline_imgs_without_alt( $content ) {
	if ( '' === trim( $content ) ) { return array(); }
	$out = array();
	if ( preg_match_all( '/<img\b([^>]*)>/i', $content, $matches ) ) {
		foreach ( $matches[1] as $attrs ) {
			// Match alt="..." OR alt=... (some legacy markup).
			if ( preg_match( '/\balt\s*=/i', $attrs ) ) {
				continue;
			}
			$src = '';
			if ( preg_match( '/\bsrc\s*=\s*"([^"]+)"/i', $attrs, $sm ) ) {
				$src = $sm[1];
			} elseif ( preg_match( "/\bsrc\s*=\s*'([^']+)'/i", $attrs, $sm ) ) {
				$src = $sm[1];
			}
			$out[] = $src;
		}
	}
	return $out;
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 2: orphaned media
 * An attachment is orphaned if:
 *   - It's not the _thumbnail_id of any post (featured image)
 *   - Its basename does NOT appear in any post's post_content
 *   - Older than 7 days (skip recently uploaded that may not yet be linked)
 * ───────────────────────────────────────────────────────────────────── */
function sn_health_check_orphaned_media() {
	global $wpdb;

	$findings = array();
	$one_week_ago = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

	$attachments = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, guid, post_date_gmt
		 FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		   AND post_date_gmt < %s
		 ORDER BY post_date_gmt DESC
		 LIMIT 500",
		$one_week_ago
	), ARRAY_A );
	if ( ! is_array( $attachments ) ) { return sn_health_pack_check( 'Orphaned media', $findings ); }

	// Build the featured-image id set once.
	$used_as_featured = $wpdb->get_col(
		"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'"
	);
	$used_as_featured = is_array( $used_as_featured ) ? array_flip( array_map( 'intval', $used_as_featured ) ) : array();

	foreach ( $attachments as $att ) {
		$id = (int) $att['ID'];
		if ( isset( $used_as_featured[ $id ] ) ) {
			continue;
		}
		// Search post_content for the file basename.
		$basename = wp_basename( (string) $att['guid'] );
		if ( '' === $basename ) { continue; }

		$ref_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $basename ) . '%'
		) );
		if ( $ref_count > 0 ) {
			continue;
		}

		$findings[] = array(
			'subject_type'  => 'attachment',
			'subject_id'    => $id,
			'subject_url'   => (string) $att['guid'],
			'subject_label' => (string) $att['post_title'] . ' (' . $basename . ')',
			'edit_url'      => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'note'          => 'Not used as a featured image and not referenced in any published post body.',
		);
	}

	return sn_health_pack_check( 'Orphaned media', $findings, 'Open each in Media → review whether it can be deleted.' );
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 3: broken internal links
 * Extract internal links (same-site origin OR root-relative) from
 * post_content of published posts. HEAD each (24h transient-cached).
 * Flag 4xx + 5xx + network failures.
 * ───────────────────────────────────────────────────────────────────── */
function sn_health_check_broken_links() {
	global $wpdb;

	$findings = array();
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $site_host ) { return sn_health_pack_check( 'Broken internal links', $findings ); }

	$posts = $wpdb->get_results(
		"SELECT ID, post_title, post_content FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '<a[[:space:]][^>]*href='
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $posts ) ) { return sn_health_pack_check( 'Broken internal links', $findings ); }

	// Build a deduplicated URL → posts-using-it map first.
	$url_to_posts = array();
	foreach ( $posts as $p ) {
		$urls = sn_health_extract_internal_links( (string) $p['post_content'], $site_host );
		foreach ( $urls as $u ) {
			$url_to_posts[ $u ][] = array(
				'post_id'    => (int) $p['ID'],
				'post_title' => (string) $p['post_title'],
			);
		}
	}

	// Probe each unique URL.
	foreach ( $url_to_posts as $url => $usages ) {
		$status = sn_health_link_status( $url );
		if ( $status['ok'] ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'internal_link',
			'subject_url'   => $url,
			'subject_label' => $url,
			'subject_id'    => 0,
			'edit_url'      => $usages[0]['edit_url'] ?? admin_url( 'post.php?post=' . $usages[0]['post_id'] . '&action=edit' ),
			'note'          => sprintf( 'HTTP %d on probe — used in %d post(s). First use: %s', $status['code'], count( $usages ), $usages[0]['post_title'] ),
		);
	}

	return sn_health_pack_check( 'Broken internal links', $findings, 'Update or remove each link in the editor. Probe results cache for 24h.' );
}

/**
 * Pull <a href="..."> URLs out of $content that point at $site_host
 * or are root-relative. Anchors, mailto:, tel:, javascript: are
 * stripped. Returns a deduped array.
 */
function sn_health_extract_internal_links( $content, $site_host ) {
	if ( '' === trim( $content ) ) { return array(); }
	$out = array();
	if ( preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $content, $m ) ) {
		foreach ( $m[1] as $href ) {
			$href = trim( $href );
			if ( '' === $href || '#' === $href[0] ) { continue; }
			if ( preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $href ) ) { continue; }

			if ( '/' === $href[0] && ( ! isset( $href[1] ) || '/' !== $href[1] ) ) {
				// Root-relative — internal by definition.
				$out[ home_url( $href ) ] = true;
				continue;
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( $h && strtolower( $h ) === strtolower( $site_host ) ) {
				$out[ $href ] = true;
			}
		}
	}
	return array_keys( $out );
}

/**
 * 24h-cached HEAD probe. Returns { ok: bool, code: int }.
 * Network errors are encoded as code = 0 + ok = false.
 */
function sn_health_link_status( $url ) {
	$cache_key = 'sn_health_link_' . md5( $url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$resp = wp_remote_head( $url, array(
		'timeout'     => SN_HEALTH_LINK_TIMEOUT,
		'redirection' => 5,
		'sslverify'   => true,
		'headers'     => array( 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' health-check' ),
	) );

	if ( is_wp_error( $resp ) ) {
		$result = array( 'ok' => false, 'code' => 0 );
	} else {
		$code   = (int) wp_remote_retrieve_response_code( $resp );
		// Some sites reject HEAD with 405; retry with GET in that case.
		if ( 405 === $code || 501 === $code ) {
			$resp2 = wp_remote_get( $url, array( 'timeout' => SN_HEALTH_LINK_TIMEOUT, 'redirection' => 5 ) );
			$code  = is_wp_error( $resp2 ) ? 0 : (int) wp_remote_retrieve_response_code( $resp2 );
		}
		$result = array( 'ok' => ( $code >= 200 && $code < 400 ), 'code' => $code );
	}

	set_transient( $cache_key, $result, SN_HEALTH_LINK_CACHE_TTL );
	return $result;
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 4: stale posts (published > 12mo ago, never modified since)
 * ───────────────────────────────────────────────────────────────────── */
function sn_health_check_stale_posts() {
	global $wpdb;
	$findings = array();

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . SN_HEALTH_STALE_MONTHS . ' months' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_modified_gmt < %s
		 ORDER BY post_modified_gmt ASC
		 LIMIT 200",
		$cutoff
	), ARRAY_A );

	if ( is_array( $rows ) ) {
		foreach ( $rows as $r ) {
			$findings[] = array(
				'subject_type'  => 'post',
				'subject_id'    => (int) $r['ID'],
				'subject_url'   => get_permalink( (int) $r['ID'] ),
				'subject_label' => (string) $r['post_title'],
				'edit_url'      => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'note'          => sprintf( 'Last modified %s — review for currency.', $r['post_modified_gmt'] ),
			);
		}
	}

	return sn_health_pack_check( sprintf( 'Stale posts (>%d months)', SN_HEALTH_STALE_MONTHS ), $findings, 'Review and either update, archive, or accept as evergreen.' );
}

/**
 * Pattern set for time-relative phrases that decay deterministically.
 *
 * Each regex captures the offending phrase as $matches[0]. Adding a new
 * pattern: append to the array (order doesn't matter for matching, only
 * for FIFO order in results — they get sorted by position downstream).
 *
 * Patterns are intentionally permissive — false positives are fine
 * because the AI evaluator (Task B) is the second filter. Missing a
 * real candidate is the more expensive failure mode.
 *
 * @since 3.7.0
 * @return array<string> regex patterns (Perl-compatible, case-insensitive enabled at call site).
 */
function sn_health_drift_time_patterns() {
	return array(
		'/\bas of \d{4}\b/i',
		'/\bthis (year|month|week)\b/i',
		'/\b(last|next) (year|month|week)\b/i',
		'/\bcurrently\b/i',
		'/\brecently\b/i',
		'/\bjust (released|launched|announced|shipped|published)\b/i',
		'/\bthe latest\b/i',
		'/\bnow (available|free|paid|in beta|in alpha)\b/i',
		'/\b(today|yesterday|tomorrow)\b/i',
	);
}

/**
 * Extract time-relative phrase candidates from post content.
 *
 * Returns an array of dicts: { phrase, context_snippet, position }.
 * - phrase: the matched substring as-is from the source
 * - context_snippet: ~200 chars around the phrase (for AI evaluation)
 * - position: byte offset in the post_content (sort stable)
 *
 * Strips shortcodes and HTML before scanning to avoid matching inside
 * attributes (e.g., href="...recently...").
 *
 * @since 3.7.0
 * @param string $content Raw post_content.
 * @return array
 */
function sn_health_extract_time_phrase_candidates( $content ) {
	$text = (string) $content;
	if ( '' === trim( $text ) ) {
		return array();
	}

	if ( function_exists( 'strip_shortcodes' ) ) {
		$text = strip_shortcodes( $text );
	}
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	} else {
		$text = strip_tags( $text );
	}

	$out = array();
	foreach ( sn_health_drift_time_patterns() as $pattern ) {
		if ( preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$phrase  = $hit[0];
				$pos     = (int) $hit[1];
				$start   = max( 0, $pos - 80 );
				$len     = min( 200, strlen( $text ) - $start );
				$snippet = trim( substr( $text, $start, $len ) );
				$out[]   = array(
					'phrase'          => $phrase,
					'context_snippet' => $snippet,
					'position'        => $pos,
				);
			}
		}
	}

	usort( $out, function( $a, $b ) { return $a['position'] - $b['position']; } );

	return $out;
}

/**
 * Common per-check result envelope used by 2-4.
 */
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}
