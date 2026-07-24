<?php
/**
 * Signal & Noise Tools -- Content Health check: missing alt.
 *
 * Check 1: missing alt text -- attachments without alt meta plus inline img tags without an alt attribute. AI Suggest+Apply via inc/ai-alt-text-suggest.php.
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
