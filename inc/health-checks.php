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
 *   6. unlinked_mentions    — a note mentions another note's title without
 *                             linking it (v7.4.0). Zero-AI at scan time;
 *                             AI Suggest+Apply via inc/ai-link-suggest.php.
 *
 * @package SignalNoiseTools
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v6.47.2: the scan result is stored in a DURABLE option (autoload=no), not a
// transient. On a site with a persistent object cache (e.g. Breeze/Redis on
// Cloudways), transients live in the object cache, so the cache flush a caching
// plugin fires on a plugin update wiped the last scan — the owner had to re-run
// it after every update. An option is a real wp_options row that survives object-
// cache flushes, so the scan now persists until the next manual run. The KEY name
// is unchanged (it does not collide: a transient was stored under the
// `_transient_`-prefixed option, never this bare key).
define( 'SN_HEALTH_CACHE_KEY',     'sn_health_last_scan' );
// No longer a hard expiry (the option never auto-expires). Retained as the
// "scan is stale" DISPLAY threshold: the Dashboard attention strip flags a scan
// older than this so the user knows to re-run (inc/admin-tab-dashboard.php).
define( 'SN_HEALTH_CACHE_TTL',     DAY_IN_SECONDS );
define( 'SN_HEALTH_STALE_MONTHS',  12 );
define( 'SN_HEALTH_LINK_CACHE_TTL', DAY_IN_SECONDS );
// v4.9.0 (T1): Cloudflare security-header drift probe caches for 6h. The
// transient holds the array of MISSING header names; an empty array means the
// edge delivered all 5 delegated headers on the last probe.
define( 'SN_HEALTH_CF_HEADERS_TTL', 6 * HOUR_IN_SECONDS );
// v4.1.1 (B-10): cap candidates per post in drift-detection. AI max_tokens=600
// budgets for ~25 verdicts; truncation mid-JSON would drop the post silently.
define( 'SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST', 25 );
define( 'SN_HEALTH_LINK_TIMEOUT',  5 );
// v7.4.0: cap pairs per source in the unlinked-mentions check. One prolific
// source could otherwise flood the findings table; the remainder surfaces on
// the next scan after the first batch is fixed.
define( 'SN_HEALTH_MENTIONS_MAX_PER_SOURCE', 5 );

// v4.2.0 PROMPT DESIGN (D-09): paired with inc/ai-drift-phrase-suggest.php's
// SNT_AI_DRIFT_SUGGEST_SYSTEM. Detection and suggestion are intentionally
// split — this prompt returns flagged positions; the suggest prompt proposes
// replacement phrases.
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
			'external_links'      => sn_health_check_external_links(),
			'stale_posts'         => sn_health_check_stale_posts(),
			'drift_time_phrases'  => sn_health_check_drift_time_phrases(),
			'color_drift'         => sn_health_check_color_drift(),
			'unlinked_mentions'   => sn_health_check_unlinked_mentions(),
			'link_opportunities'  => sn_health_check_link_opportunities(),
			'cf_security_headers' => sn_health_check_cf_security_headers(),
			'edge_workers'        => sn_health_check_edge_workers(),
		),
	);
	$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

	sn_health_store_scan( $result );
	return $result;
}

/**
 * Persist a scan result durably.
 *
 * v6.47.2: an autoload=no option (not a transient) so the scan survives the
 * object-cache flush a caching plugin fires on a plugin update. autoload=no keeps
 * it out of the per-request alloptions load — it is only read on the Health tab
 * and the Dashboard, both manage_options admin screens.
 *
 * @param array $result The sn_health_run_scan() result.
 */
function sn_health_store_scan( $result ) {
	update_option( SN_HEALTH_CACHE_KEY, $result, false );
}

function sn_health_last_scan() {
	$stored = get_option( SN_HEALTH_CACHE_KEY );
	return is_array( $stored ) ? $stored : null;
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
/**
 * Whether an image attachment is referenced anywhere we can detect (so it is NOT
 * an orphan).
 *
 * v6.48.2: broadened well beyond the original v4.x "original basename in a
 * PUBLISHED post body" search, which over-flagged real images as orphans:
 *   - Gutenberg references an image by its ID class `wp-image-<id>` and by its
 *     SIZED URL (`photo-1024x576.jpg`), never the original basename — so every
 *     block-inserted, non-full-size image read as an orphan.
 *   - The site logo + site icon are stored in theme_mods/options, never a post
 *     body — so they read as orphans too.
 *   - References in drafts / scheduled / private posts (and edited FSE templates,
 *     which are wp_template/wp_template_part posts) were excluded by `publish`-only.
 *
 * Signals — ANY one means "referenced": featured image; site logo / site icon;
 * the `wp-image-<id>` class in any non-trash post body; the original basename OR
 * any generated size's exact filename in any non-trash post body OR in post meta.
 *
 * Conservative by design: when unsure, count the attachment as USED. A missed
 * orphan is harmless; a FALSE orphan erodes trust and risks a wrong deletion.
 *
 * @param int    $id       Attachment ID.
 * @param string $guid     Attachment guid (the full-size URL).
 * @param array  $featured Flipped set (id => true) of featured-image ids.
 * @param array  $chrome   Flipped set (id => true) of site logo / site icon ids.
 * @return bool True if referenced (not an orphan).
 *
 * @since 6.48.2
 */
function sn_health_attachment_is_referenced( $id, $guid, $featured, $chrome ) {
	global $wpdb;
	$id = (int) $id;

	if ( isset( $featured[ $id ] ) || isset( $chrome[ $id ] ) ) {
		return true;
	}

	// Block-inserted images carry class="wp-image-<id>" regardless of the rendered
	// size — the single most reliable signal on a modern block/FSE site.
	$block_ref = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s LIMIT 1",
		'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%'
	) );
	if ( $block_ref > 0 ) {
		return true;
	}

	// Filenames to search: the original basename + every generated size's exact
	// filename (photo-WxH.ext) from the attachment metadata. The classic editor and
	// direct-URL references use THESE, not the wp-image-<id> class.
	$needles  = array();
	$basename = wp_basename( (string) $guid );
	if ( '' !== $basename ) {
		$needles[] = $basename;
	}
	$meta = wp_get_attachment_metadata( $id );
	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$needles[] = (string) $size['file'];
			}
		}
	}
	$needles = array_values( array_unique( array_filter( $needles ) ) );

	foreach ( $needles as $needle ) {
		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		// ...in any non-trash post body (posts, pages, edited FSE templates)...
		$in_body = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s LIMIT 1",
			$like
		) );
		if ( $in_body > 0 ) {
			return true;
		}
		// ...or in post meta (OG-image, custom-field / ACF image references).
		$in_meta = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1",
			$like
		) );
		if ( $in_meta > 0 ) {
			return true;
		}
	}

	return false;
}

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

	// v6.48.2: site logo + site icon are referenced via theme_mods/options, never
	// a post body, so the body search alone false-flagged them as orphans.
	$site_chrome = array();
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $logo_id > 0 ) { $site_chrome[ $logo_id ] = true; }
	$icon_id = (int) get_option( 'site_icon' );
	if ( $icon_id > 0 ) { $site_chrome[ $icon_id ] = true; }

	foreach ( $attachments as $att ) {
		$id       = (int) $att['ID'];
		$basename = wp_basename( (string) $att['guid'] );
		if ( '' === $basename ) { continue; }

		if ( sn_health_attachment_is_referenced( $id, (string) $att['guid'], $used_as_featured, $site_chrome ) ) {
			continue;
		}

		$findings[] = array(
			'subject_type'  => 'attachment',
			'subject_id'    => $id,
			'subject_url'   => (string) $att['guid'],
			'subject_label' => (string) $att['post_title'] . ' (' . $basename . ')',
			'edit_url'      => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'note'          => 'Not referenced in any post body or meta, and not a featured image, logo, or site icon.',
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
		if ( ! empty( $status['skipped'] ) || $status['ok'] ) {
			continue; // healthy, or a live page behind a bot challenge (not broken)
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
 * 24h-cached HEAD probe. Returns { ok: bool, code: int, skipped?: bool, reason?: string }.
 * Network errors are encoded as code = 0 + ok = false. A bot-challenge
 * interstitial (a 403/503 carrying `cf-mitigated: challenge`, e.g. a same-host
 * path behind Cloudflare) is a LIVE page gating bots, not a broken link, so it is
 * marked skipped — the same treatment the external link-rot probe gives a
 * challenged citation (see sn_health_is_bot_challenge() in health-probe-classify.php).
 */
function sn_health_link_status( $url ) {
	$cache_key = 'sn_health_link_' . md5( $url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$resp = wp_remote_head( $url, array(
		'timeout'     => SN_HEALTH_LINK_TIMEOUT,
		// v4.14.2: do not follow redirects. The host filter validates only the
		// FIRST hop, so a same-host open redirect to 169.254.169.254 was followed
		// to the cloud-metadata service (LOW SSRF). 0 = the link's own status is
		// terminal — matches the v4.14.1 outbound-hardening peers.
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array( 'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' health-check' ),
	) );

	if ( is_wp_error( $resp ) ) {
		$result = array( 'ok' => false, 'code' => 0 );
	} else {
		$final  = $resp;
		$code   = (int) wp_remote_retrieve_response_code( $resp );
		// Some sites reject HEAD with 405; retry with GET in that case.
		if ( 405 === $code || 501 === $code ) {
			$resp2 = wp_remote_get( $url, array( 'timeout' => SN_HEALTH_LINK_TIMEOUT, 'redirection' => 0 ) );
			if ( is_wp_error( $resp2 ) ) {
				$final = null;
				$code  = 0;
			} else {
				$final = $resp2;
				$code  = (int) wp_remote_retrieve_response_code( $resp2 );
			}
		}
		$headers = $final ? wp_remote_retrieve_headers( $final ) : array();
		if ( sn_health_is_bot_challenge( $code, $headers ) ) {
			// A live page behind a Cloudflare bot challenge — gating automated
			// clients, not a dead link. Mark unverifiable (skipped) instead of
			// flagging it; mirrors the external link-rot probe.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'bot_challenge' );
		} elseif ( sn_health_is_edge_gated( $code, $headers ) ) {
			// A live internal page the Cloudflare edge is blocking/rate-limiting this
			// probe (403/429 + cf-ray, no cf-mitigated). juanlentino.com is fully
			// CF-fronted, so a bare-403 probe would false-flag live pages as broken.
			// Unverifiable, not broken; mirrors the external link-rot probe.
			$result = array( 'ok' => true, 'code' => $code, 'skipped' => true, 'reason' => 'edge_gated' );
		} else {
			$result = array( 'ok' => ( $code >= 200 && $code < 400 ), 'code' => $code );
		}
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
 * Normalize a CSS hex color: lowercase, 3-digit expanded to 6. '' for anything
 * that is not a #hex color (named colors, rgb(), malformed).
 *
 * @param string $color Raw color token.
 * @return string '#rrggbb' or ''.
 */
function sn_health_normalize_hex( $color ) {
	$c = strtolower( trim( (string) $color ) );
	if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
		return '';
	}
	$h = $m[1];
	if ( 3 === strlen( $h ) ) {
		$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
	}
	return '#' . $h;
}

/**
 * The allowed palette as a normalized hex set, read DEFENSIVELY from
 * wp_get_global_settings(): merged data presents either a FLAT entry list (the
 * shape the theme's design-tokens ability reads) or an ORIGIN-KEYED array.
 * When keyed, only theme+custom origins count — the theme sets
 * defaultPalette:false, so core-default colors are drift here.
 *
 * @return array<string,true> Set keyed by '#rrggbb'.
 */
function sn_health_allowed_palette_hexes() {
	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		return array();
	}
	$palette = wp_get_global_settings( array( 'color', 'palette' ) );
	if ( ! is_array( $palette ) ) {
		return array();
	}
	$entries = array();
	if ( isset( $palette['theme'] ) || isset( $palette['custom'] ) || isset( $palette['default'] ) ) {
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			if ( isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
				$entries = array_merge( $entries, $palette[ $origin ] );
			}
		}
	} else {
		$entries = $palette;
	}
	$allowed = array();
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) && isset( $entry['color'] ) ) {
			$hex = sn_health_normalize_hex( (string) $entry['color'] );
			if ( '' !== $hex ) {
				$allowed[ $hex ] = true;
			}
		}
	}
	return $allowed;
}

/**
 * Zero-AI color-drift check (v7.3.0, the v4.1.0-era "cheap zero-AI check"):
 * published posts/pages whose content carries inline hex colors outside the
 * theme palette. Read-only; the fix is editorial (use palette presets) or a
 * deliberate theme.json addition.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_color_drift() {
	$label    = 'Color drift';
	$fix_hint = 'Replace inline hex colors with theme palette presets, or add the color to theme.json if it is genuinely part of the design system.';

	$allowed = sn_health_allowed_palette_hexes();
	if ( empty( $allowed ) ) {
		return sn_health_pack_check( $label, array(), 'Theme palette unavailable — skipping (never flags everything on a missing palette). ' . $fix_hint );
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND post_content REGEXP '#[0-9a-fA-F]{3}'
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$findings = array();
	foreach ( $rows as $r ) {
		// v7.3.1: inline SVG figures are ARTWORK, not text styling — their
		// fills/strokes (grayscale tones, semantic diagram red/green) are
		// deliberate and flagged every diagram-carrying post as permanent
		// drift (alarm fatigue). Strip <svg>…</svg> spans before extracting
		// hexes so the check stays about prose/styling drift. Non-greedy per
		// block; nested <svg> inside <svg> would leave the outer tail, which
		// only risks a FLAGGED nested tail (never hides prose drift outside).
		$content = (string) preg_replace( '#<svg\b[^>]*>.*?</svg\s*>#is', '', (string) $r['post_content'] );
		if ( ! preg_match_all( '/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', $content, $m ) ) {
			continue;
		}
		$offending = array();
		foreach ( array_unique( $m[0] ) as $raw ) {
			$hex = sn_health_normalize_hex( $raw );
			if ( '' !== $hex && ! isset( $allowed[ $hex ] ) ) {
				$offending[ $hex ] = true;
			}
		}
		if ( empty( $offending ) ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => (int) $r['ID'],
			'subject_url'   => (string) get_permalink( (int) $r['ID'] ),
			'subject_label' => (string) $r['post_title'],
			'edit_url'      => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
			'note'          => 'Off-palette inline colors: ' . implode( ', ', array_slice( array_keys( $offending ), 0, 8 ) ) . '.',
		);
	}
	return sn_health_pack_check( $label, $findings, $fix_hint );
}

/**
 * Target eligibility for the unlinked-mentions check. Titles under 12
 * characters or under 2 words false-positive on substring matching
 * ("Now", "Craft" appear in ordinary prose constantly).
 *
 * @param string $title Target post title.
 * @return bool
 *
 * @since 7.4.0
 */
function sn_health_mention_target_eligible( $title ) {
	$title = trim( (string) $title );
	if ( strlen( $title ) < 12 ) {
		return false;
	}
	$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $words ) && count( $words ) >= 2;
}

/**
 * Whether $content already links to the note at /notes/$post_name.
 *
 * Boundary-aware: a bare stripos would treat '/notes/craft-two' as a link
 * to 'craft' and silently suppress a real finding. After the slug we
 * require a path / quote / query / fragment terminator or end-of-string.
 * The suggest impl (inc/ai-link-suggest.php) and the theme's cited-by
 * query use the same boundary so the two surfaces never disagree.
 *
 * @param string $content   Raw post_content.
 * @param string $post_name Target slug.
 * @return bool
 *
 * @since 7.4.0
 */
function sn_health_contains_note_link( $content, $post_name ) {
	$post_name = (string) $post_name;
	if ( '' === $post_name ) {
		return false;
	}
	return (bool) preg_match(
		'#/notes/' . preg_quote( $post_name, '#' ) . '(?=[/"\'?\#]|$)#i',
		(string) $content
	);
}

/**
 * Zero-AI unlinked-mentions check (v7.4.0): published notes whose PROSE
 * mentions another note's title without linking to /notes/<post_name>.
 * One finding per (source, target) pair, capped at
 * SN_HEALTH_MENTIONS_MAX_PER_SOURCE pairs per source. AI enters only on
 * the Suggest click (inc/ai-link-suggest.php), never at scan time.
 *
 * The pairwise pass is quadratic over the LIMIT-500 corpus but each
 * source's content is stripped once; the real site is a few dozen notes.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_unlinked_mentions() {
	$label    = 'Unlinked mentions';
	$fix_hint = 'The note mentions another note\'s title without linking to it. Suggest asks AI whether the mention really refers to that note; Apply wraps the mention in a link.';

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_name, post_content, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type = 'post'
		 ORDER BY post_date DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$findings = array();
	foreach ( $rows as $source ) {
		// Strip once per source: mentions live in prose — a title inside an
		// href or attribute is markup, not a mention (same rationale as the
		// drift extractor's strip pass).
		$stripped = wp_strip_all_tags( strip_shortcodes( (string) $source['post_content'] ) );
		if ( '' === trim( $stripped ) ) {
			continue;
		}
		$pairs = 0;
		foreach ( $rows as $target ) {
			if ( $pairs >= SN_HEALTH_MENTIONS_MAX_PER_SOURCE ) {
				break;
			}
			if ( (int) $source['ID'] === (int) $target['ID'] ) {
				continue;
			}
			$title = trim( (string) $target['post_title'] );
			if ( ! sn_health_mention_target_eligible( $title ) ) {
				continue;
			}
			$pos = stripos( $stripped, $title );
			if ( false === $pos ) {
				continue;
			}
			if ( sn_health_contains_note_link( (string) $source['post_content'], (string) $target['post_name'] ) ) {
				continue;
			}
			// The mention AS IT APPEARS in the prose (case may differ from the
			// title). Suggest/Apply locate THIS exact string in raw content via
			// snt_ai_drift_locate_in_raw(), so casing must be the content's.
			$mention = substr( $stripped, $pos, strlen( $title ) );
			$start   = max( 0, $pos - 80 );
			$context = trim( substr( $stripped, $start, 200 ) );

			// v8.1.2 owner rule: non-actionable pairs are NOISE, not findings.
			// (a) STRUCTURAL: a mention that cannot be spliced — split by
			// inline markup, or sitting inside an existing <a> to a third
			// note — can only ever produce an advice-only panel. Suppress it
			// without spending an AI call.
			if ( function_exists( 'snt_ai_drift_locate_in_raw' ) && function_exists( 'snt_ai_link_position_inside_anchor' ) ) {
				$raw_pos = snt_ai_drift_locate_in_raw( (string) $source['post_content'], $mention, $context );
				if ( -1 === $raw_pos || snt_ai_link_position_inside_anchor( (string) $source['post_content'], $raw_pos ) ) {
					continue;
				}
			}
			// (b) JUDGED: the Suggest verdict store doubles as the scan's
			// memory — a stored skip/unsure means the AI already said no.
			// The key carries the source's modified stamp, so an edit
			// re-nominates naturally. v8.4.1: DURABLE store (autoload=no
			// option), not transients — the v10.22.0 auto-purges flush
			// transients on every update, which resurrected judged pairs.
			if ( function_exists( 'snt_ai_verdict_store_get' ) ) {
				$judged = snt_ai_verdict_store_get( 'sn_link_verdict_' . md5( (int) $source['ID'] . '|' . (int) $target['ID'] . '|' . (string) ( $source['post_modified_gmt'] ?? '' ) ) );
				if ( is_array( $judged ) && isset( $judged['verdict'] ) && 'link' !== (string) $judged['verdict'] ) {
					continue;
				}
			}

			$findings[] = array(
				'subject_type'    => 'post',
				'subject_id'      => (int) $source['ID'],
				'subject_url'     => (string) get_permalink( (int) $source['ID'] ),
				'subject_label'   => (string) $source['post_title'],
				'edit_url'        => admin_url( 'post.php?post=' . (int) $source['ID'] . '&action=edit' ),
				'note'            => sprintf( 'Mentions "%s" without linking to /notes/%s.', $title, (string) $target['post_name'] ),
				'target_id'       => (int) $target['ID'],
				'target_title'    => $title,
				'mention'         => $mention,
				'context_snippet' => $context,
			);
			$pairs++;
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 6: Cloudflare security-header drift (v4.9.0, T1)
 *
 * The 5 delegated headers (CSP / HSTS / X-Content-Type-Options /
 * X-Frame-Options / Referrer-Policy) are emitted at the Cloudflare edge
 * via a Transform Rule / Managed Headers — NOT by WordPress. If the rule
 * is dropped or misconfigured, the site silently loses its security
 * posture with no signal anywhere in wp-admin. This check fires ONE
 * HEAD request at home_url and asserts each header is present, surfacing
 * any absence as a finding.
 *
 * Probe result (the array of MISSING header names) caches for 6h in the
 * `sn_health_cf_headers_probe` transient. On a WP_Error probe we return a
 * probe-failed note WITHOUT caching, so the next scan re-attempts (the
 * edge being unreachable is a transient state, not a finding).
 *
 * Detection-only — NOT in $suggest_supported_checks (no AI-fix column;
 * the fix is a CF dashboard change, not a post mutation).
 *
 * @since 4.9.0
 * @return array { count, findings, label, fix_hint }
 */
function sn_health_check_cf_security_headers() {
	$label    = 'Cloudflare security headers';
	$fix_hint = 'These 5 headers are delivered at the Cloudflare edge (Transform Rule / Managed Headers), not by WordPress. A missing header means the edge rule was dropped or misconfigured — verify it in the Cloudflare dashboard.';

	// Allow the whole check to be filtered off (e.g., non-Cloudflare hosting).
	if ( ! apply_filters( 'sn_health_cf_header_check_enabled', true ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$expected = array(
		'content-security-policy',
		'strict-transport-security',
		'x-content-type-options',
		'x-frame-options',
		'referrer-policy',
	);

	$cache_key = 'sn_health_cf_headers_probe';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		// Cached array IS the list of missing header names.
		$missing = $cached;
	} else {
		$home = home_url( '/' );
		$resp = wp_remote_head( $home, array(
			'timeout'     => 5,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array(
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' header-drift-check',
			),
		) );

		if ( is_wp_error( $resp ) ) {
			// Edge unreachable — do NOT cache; self-heal on the next scan.
			return sn_health_pack_check(
				$label,
				array(),
				'Header probe failed (' . $resp->get_error_message() . ') — the edge was unreachable. The check will retry on the next scan.'
			);
		}

		// wp_remote_retrieve_headers() returns a WpOrg\Requests
		// CaseInsensitiveDictionary on live WP (its $data is PROTECTED, so a
		// (array) cast mangles the key to "\0*\0data" and never unwraps) and a
		// plain array under test. Use the class's public getAll() — which
		// returns already-lower-cased keys — with a Traversable/array fallback.
		$raw     = wp_remote_retrieve_headers( $resp );
		$present = array();
		$server  = ''; // server: header value, used for edge detection below.
		$collect = static function ( $name, $value ) use ( &$present, &$server ) {
			$lower             = strtolower( (string) $name );
			$present[ $lower ] = true;
			if ( 'server' === $lower ) {
				$server = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
			}
		};
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			foreach ( (array) $raw->getAll() as $name => $value ) {
				$collect( $name, $value );
			}
		} elseif ( $raw instanceof \Traversable || is_array( $raw ) ) {
			foreach ( $raw as $name => $value ) {
				$collect( $name, $value );
			}
		}

		// Edge detection: a CF-served response carries a cf-ray header and a
		// server: cloudflare. If the probe hit the origin directly (split-horizon
		// DNS, hosts pin, grey-cloud on Cloudways), none of that is present.
		$is_edge = isset( $present['cf-ray'] ) || ( '' !== $server && false !== stripos( $server, 'cloudflare' ) );

		$missing = array();
		foreach ( $expected as $header ) {
			if ( ! isset( $present[ $header ] ) ) {
				$missing[] = $header;
			}
		}

		// Edge-bypass guard: if NONE of the 5 expected headers are present AND
		// we can't confirm we hit the Cloudflare edge (no cf-ray / no
		// server: cloudflare), the probe most likely reached the origin
		// directly — flagging all 5 would be a false positive. Emit a single
		// advisory note with ZERO findings and do NOT cache the degenerate
		// result, so a later scan re-attempts.
		if ( count( $missing ) === count( $expected ) && ! $is_edge ) {
			return sn_health_pack_check(
				$label,
				array(),
				'Could not confirm the Cloudflare edge headers from this host — the probe may have hit the origin directly; verify the edge config manually.'
			);
		}

		set_transient( $cache_key, $missing, SN_HEALTH_CF_HEADERS_TTL );
	}

	$findings = array();
	$home_url = home_url( '/' );
	foreach ( $missing as $header ) {
		$findings[] = array(
			'subject_type'  => 'security_header',
			'subject_id'    => 0,
			'subject_url'   => $home_url,
			'subject_label' => $header,
			'edit_url'      => '',
			'note'          => 'Expected at the Cloudflare edge but absent — verify the CF Transform Rule / Managed Headers.',
		);
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
