<?php
/**
 * Signal & Noise Tools -- Content Health check: orphaned media.
 *
 * Check 2: orphaned media -- image attachments not used as a featured image and not referenced in any post body or site chrome. AI verdict + force-delete.
 *
 * Split VERBATIM out of inc/health-checks.php in v9.81.0 (mirroring the
 * analytics-render-*.php split); every function name is unchanged. Loaded
 * by the inc/health-checks.php orchestrator, which owns the shared
 * constants and sn_health_pack_check().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

/**
 * Build the featured-image + site-chrome reference sets the orphan signals need.
 *
 * v10.28.1: extracted from sn_health_check_orphaned_media() so the apply-time
 * TOCTOU re-check (sn_health_attachment_is_referenced_now) consults the SAME
 * sets the scan does — parity by construction, not by copy.
 *
 * @return array{0:array<int,true>,1:array<int,true>} [featured, chrome] flipped sets.
 *
 * @since 10.28.1
 */
function sn_health_reference_sets() {
	global $wpdb;

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

	return array( $used_as_featured, $site_chrome );
}

/**
 * Live re-check: is this attachment referenced RIGHT NOW?
 *
 * Rebuilds the reference sets and runs the full scan-time signal battery for a
 * single attachment. Used by snt_ai_orphan_apply_impl() to close the TOCTOU gap
 * between the orphan scan that flagged the attachment and the destructive apply.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $guid          Attachment guid (the full-size URL).
 * @return bool True if referenced (NOT safe to delete).
 *
 * @since 10.28.1
 */
function sn_health_attachment_is_referenced_now( $attachment_id, $guid ) {
	list( $featured, $chrome ) = sn_health_reference_sets();
	return sn_health_attachment_is_referenced( (int) $attachment_id, (string) $guid, $featured, $chrome );
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

	// Build the featured-image + site-chrome sets once (shared with the
	// apply-time re-check — see sn_health_reference_sets).
	list( $used_as_featured, $site_chrome ) = sn_health_reference_sets();

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
