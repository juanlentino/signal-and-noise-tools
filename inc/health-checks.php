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
 * the user fixes them manually for the read-only checks; AI-assisted
 * Suggest+Apply ships for missing_alt + drift_time_phrases (v4.0.0) and
 * orphaned_media (v4.1.0).
 *
 * The 5 checks (as of v4.1.0):
 *
 *   1. missing_alt          — image attachments and inline <img> tags
 *                             without an alt attribute. AI Suggest+Apply.
 *   2. orphaned_media       — image attachments not used as a featured
 *                             image and not referenced in any post body
 *                             (image MIME only since v4.1.1, B-02).
 *                             AI verdict + force-delete since v4.1.0.
 *   3. broken_links         — internal links in post_content that 404 or
 *                             return network errors (cached HEAD requests).
 *   4. stale_posts          — published posts unedited in the last 12 months.
 *                             Read-only; AI Suggest was scoped out of v4.1.0
 *                             per evergreen-site mismatch.
 *   5. drift_time_phrases   — time-relative phrases (recently, last year,
 *                             as of YYYY) whose meaning decays. AI verdict
 *                             since v3.7.0; Suggest+Apply since v4.0.0
 *                             (raw-position resolver fix v4.1.1, B-01).
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
// v4.1.1 (B-10): cap candidates per post in drift-detection. AI max_tokens=600
// budgets for ~25 verdicts; truncation mid-JSON would drop the post silently.
define( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST', 25 );
define( 'SN_HEALTH_LINK_TIMEOUT',  5 );

const SNT_AI_DRIFT_SYSTEM = "You are an editor evaluating whether time-relative phrases in a post are still accurate given the post's last_modified date vs. 'now'.\n\n" .
	"For each candidate in the input JSON, return ONLY a JSON array of objects:\n" .
	"[{\"phrase\": \"<phrase>\", \"verdict\": \"stale\" | \"ok\" | \"unsure\", \"reason\": \"<one sentence>\"}]\n\n" .
	"Rules:\n" .
	"- Be conservative. Only return \"stale\" if the phrase is materially misleading given the date gap.\n" .
	"- \"as of YYYY\" is ok if YYYY >= last_modified year; stale if the gap > 1 year and the surrounding context implies current state.\n" .
	"- \"recently\" / \"just released\" are stale when last_modified is > 12 months ago.\n" .
	"- \"this year\" / \"this month\" are stale when last_modified year/month doesn't match now.\n" .
	"- \"the latest\" is unsure (cannot verify without external knowledge).\n" .
	"- Output JSON only. No markdown, no preamble.";

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
			'missing_alt'         => sn_health_check_missing_alt(),
			'orphaned_media'      => sn_health_check_orphaned_media(),
			'broken_links'        => sn_health_check_broken_links(),
			'stale_posts'         => sn_health_check_stale_posts(),
			'drift_time_phrases'  => sn_health_check_drift_time_phrases(),
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

	// v4.1.1 (B-02): restrict to image MIME types. The AI orphan-suggest impl
	// rejects non-image attachments with a 422 (Suggest button always fails on
	// PDFs/videos/audio). Filtering at the SQL layer prevents the false-positive
	// Suggest UX entirely. Non-image orphans are an acceptable scope omission
	// today — the AI verdict heuristics are tuned for image filenames, not docs.
	$attachments = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, guid, post_date_gmt
		 FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		   AND post_mime_type LIKE 'image/%%'
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
	// Source-of-truth list. KEEP IN SYNC WITH the SQL REGEXP in
	// sn_health_check_drift_time_phrases() (which is a pre-filter that
	// must mirror these patterns).
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
 * Health check #5: time-relative drift detection.
 *
 * Hybrid algorithm: regex pre-filter eliminates posts with no candidate
 * phrases (free, fast); then a single AI call per remaining post evaluates
 * each candidate in context and returns a verdict (stale / ok / unsure).
 * Only "stale" verdicts become Health-tab findings.
 *
 * v3.7.0: detection only — findings deep-link to the editor.
 * v4.0.0: AI Suggest+Apply layer added (inc/ai-drift-phrase-suggest.php).
 * v4.1.1: raw-content position resolver — Apply now works for Gutenberg
 *         posts (B-01 fix; pre-v4.1.1 the apply step silently failed with
 *         409 on any post with block markup before the target phrase).
 *
 * Gracefully degrades when AI is unavailable (returns empty findings,
 * doesn't crash the scan).
 *
 * @since 3.7.0
 * @return array { count, findings, label, fix_hint }
 */
function sn_health_check_drift_time_phrases() {
	$label    = 'Time-relative drift';
	$fix_hint = 'Open each post and replace dated phrasing with absolute references (years, dates) or remove time-relative language entirely.';

	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return sn_health_pack_check( $label, array(), 'AI provider not configured — skipping drift detection. Configure Settings → Connectors + Settings → AI to enable.' );
	}

	global $wpdb;
	// KEEP IN SYNC WITH sn_health_drift_time_patterns().
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '(as of [0-9]{4}|this (year|month|week)|currently|recently|just (released|launched|announced|shipped|published)|the latest|now (available|free|paid|in beta|in alpha)|today|yesterday|tomorrow|last (year|month|week)|next (year|month|week))'
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$findings = array();
	foreach ( $rows as $r ) {
		$candidates = sn_health_extract_time_phrase_candidates( (string) $r['post_content'] );
		if ( count( $candidates ) > SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST ) {
			// v4.1.1 (B-10): cap is the named constant SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST
			// (defined at file scope) so a max_tokens budget tweak only needs the
			// constant value changed, not a grep-and-replace for the literal 25.
			$candidates = array_slice( $candidates, 0, SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST );
		}
		if ( empty( $candidates ) ) {
			continue;  // Regex pre-filter — no AI call needed.
		}

		// Build a compact AI prompt with candidates + post metadata.
		$payload = array(
			'post_id'       => (int) $r['ID'],
			'last_modified' => substr( (string) $r['post_modified_gmt'], 0, 10 ),
			'now'           => gmdate( 'Y-m-d' ),
			'candidates'    => array_map( function( $c ) {
				return array(
					'phrase'  => $c['phrase'],
					'context' => $c['context_snippet'],
				);
			}, $candidates ),
		);
		$prompt = wp_json_encode( $payload );
		if ( false === $prompt ) { continue; }

		// v4.0.1: cache AI verdicts per (post_id, post_modified, prompt_version).
		// Verdicts are deterministic from (post_content, post_modified_gmt, system_prompt),
		// so unchanged posts skip the AI call on subsequent Run scans.
		$cache_key      = 'sn_drift_verdicts_' . (int) $r['ID'];
		$post_modified  = (string) $r['post_modified_gmt'];
		$prompt_version = md5( SNT_AI_DRIFT_SYSTEM );
		$cached         = get_transient( $cache_key );

		if ( is_array( $cached )
			&& isset( $cached['post_modified'], $cached['prompt_version'], $cached['verdicts'] )
			&& $cached['post_modified']  === $post_modified
			&& $cached['prompt_version'] === $prompt_version ) {
			$verdicts = $cached['verdicts'];
		} else {
			$raw = snt_ai_generate_with_constraints( $prompt, SNT_AI_DRIFT_SYSTEM, 600 );
			if ( is_wp_error( $raw ) || ! is_string( $raw ) ) {
				continue;  // Soft fail — skip this post.
			}

			// Strip optional markdown fences (opener and/or closer, independently).
			$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw ) ) );
			$verdicts = json_decode( $text, true );
			if ( ! is_array( $verdicts ) ) {
				continue;  // Malformed — skip this post.
			}

			set_transient( $cache_key, array(
				'post_modified'  => $post_modified,
				'prompt_version' => $prompt_version,
				'verdicts'       => $verdicts,
			), 30 * DAY_IN_SECONDS );
		}

		foreach ( $verdicts as $v ) {
			if ( ! is_array( $v ) ) { continue; }
			if ( ( $v['verdict'] ?? '' ) !== 'stale' ) { continue; }
			$phrase = isset( $v['phrase'] ) ? (string) $v['phrase'] : '';
			$reason = isset( $v['reason'] ) ? (string) $v['reason'] : '';
			if ( '' === $phrase ) { continue; }

			// Look up the candidate's position + context_snippet for this phrase.
			// The $candidates array (built before the AI call) has them; find by phrase match.
			$position = 0;
			$context  = '';
			foreach ( $candidates as $cand ) {
				if ( $cand['phrase'] === $phrase ) {
					$position = (int) $cand['position'];
					$context  = (string) $cand['context_snippet'];
					break;
				}
			}

			$findings[] = array(
				'subject_type'    => 'post',
				'subject_id'      => (int) $r['ID'],
				'subject_url'     => get_permalink( (int) $r['ID'] ),
				'subject_label'   => (string) $r['post_title'],
				'edit_url'        => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'note'            => sprintf( '"%s" — %s', $phrase, $reason ),
				'phrase'          => $phrase,
				'position'        => $position,
				'context_snippet' => $context,
			);
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
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
