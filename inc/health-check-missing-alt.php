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
 * v10.77.0 widened the check from alt PRESENCE on <img> to accessible-name
 * coverage plus alt QUALITY. Two things worth knowing before editing:
 *
 *   - The content query's pre-filter is load-bearing. It used to read
 *     `post_content LIKE '%<img%'`, so a post whose only graphic was an inline
 *     <svg> was never SELECTED and no parser change could have reported it.
 *     Narrowing this filter again silently reintroduces that blind spot.
 *   - The attachment passes are two SEPARATE queries on purpose. Merging them
 *     into one relaxed query would spend the LIMIT budget for alt-less images
 *     on healthy rows, shrinking coverage on a large media library.
 *
 * Parsing and quality verdicts live in inc/health-alt-quality.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ─────────────────────────────────────────────────────────────────────
 * CHECK 1: missing alt text
 * Four passes:
 *   a. image attachments where _wp_attachment_image_alt is empty
 *   b. inline <img> tags in published post_content with no alt= attr
 *   c. inline <svg> with no accessible name and no decorative marker (v10.77.0)
 *   d. alt that EXISTS but says nothing -- heading duplicate, caption duplicate,
 *      filename echo, category name -- on both attachments and inline <img>
 *      (v10.77.0; v10.81.0 added the heading rule and replaced a word COUNT,
 *      which flagged every correct short alt and missed "an image")
 *
 * The (d) reasons are ordered by SPECIFICITY, not by cost: relationship rules
 * (heading, caption) run before string-shape rules (filename), because a rule
 * that has seen the surrounding page gives the reader the more useful sentence.
 * Attachments have no surrounding page, so they get no heading.
 *
 * Passes (c) and (d) are findings only. Like (a) and (b) they carry no fix:
 * every applied change goes through the staged human-acceptance path.
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
				'note'         => 'Image attachment has no alt text: bad for SEO and screen readers.',
			);
		}
	}

	// c) attachments that DO have alt, judged on quality rather than presence.
	//    A separate query from (a) so each pass keeps its own LIMIT budget.
	$alt_rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.guid, p.post_excerpt, pm.meta_value
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON ( pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt' )
		 WHERE p.post_type = 'attachment'
		   AND p.post_mime_type LIKE 'image/%'
		   AND pm.meta_value != ''
		 ORDER BY p.post_date DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( is_array( $alt_rows ) ) {
		foreach ( $alt_rows as $r ) {
			$problem = sn_health_alt_quality_problem(
				(string) $r['meta_value'],
				(string) $r['guid'],
				(string) $r['post_excerpt']
			);
			if ( '' === $problem ) {
				continue;
			}
			$findings[] = array(
				'subject_type'   => 'attachment_alt_quality',
				'subject_id'     => (int) $r['ID'],
				'subject_url'    => (string) $r['guid'],
				'subject_label'  => (string) $r['post_title'],
				'edit_url'       => admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' ),
				'quality_reason' => $problem,
				'note'           => sn_health_alt_quality_note( $problem, (string) $r['meta_value'] ),
			);
		}
	}

	// b + c + d) one scan of published bodies. The pre-filter MUST admit <svg>
	// or pass (c) below can never fire -- see the file header.
	$content_rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type IN ('post','page')
		   AND ( post_content LIKE '%<img%' OR post_content LIKE '%<svg%' )
		 LIMIT 1000",
		ARRAY_A
	);
	if ( is_array( $content_rows ) ) {
		foreach ( $content_rows as $row ) {
			$post_id   = (int) $row['ID'];
			$title     = (string) $row['post_title'];
			$edit_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
			$content   = (string) $row['post_content'];

			// b) inline <img> with no alt attribute at all.
			foreach ( sn_health_extract_inline_imgs_without_alt( $content ) as $src ) {
				$findings[] = array(
					'subject_type'  => 'inline_img',
					'subject_id'    => $post_id,
					'subject_url'   => $src,
					'subject_label' => $title,
					'edit_url'      => $edit_url,
					'note'          => 'Inline <img> in post body has no alt attribute.',
				);
			}

			// c) inline <svg> with no accessible name. NOT an alt= problem:
			//    <svg> has no alt attribute, so the fix is a child <title>,
			//    aria-label, or aria-hidden="true" if it is decorative.
			foreach ( sn_health_extract_inline_svgs_without_name( $content ) as $hint ) {
				$findings[] = array(
					'subject_type'  => 'inline_svg',
					'subject_id'    => $post_id,
					'subject_url'   => '',
					'subject_label' => $title . ' — ' . $hint,
					'edit_url'      => $edit_url,
					'note'          => 'Inline <svg> has no accessible name. Add a direct-child <title>, or aria-label, or aria-hidden="true" if it is decorative. <svg> has no alt attribute.',
				);
			}

			// d) inline <img> whose alt exists but says nothing.
			foreach ( sn_health_extract_inline_imgs_with_alt( $content ) as $img ) {
				$problem = sn_health_alt_quality_problem( $img['alt'], $img['src'], $img['caption'], $img['heading'] );
				if ( '' === $problem ) {
					continue;
				}
				$findings[] = array(
					'subject_type'   => 'inline_img_alt_quality',
					'subject_id'     => $post_id,
					'subject_url'    => $img['src'],
					'subject_label'  => $title,
					'edit_url'       => $edit_url,
					'quality_reason' => $problem,
					'note'           => sn_health_alt_quality_note( $problem, $img['alt'] ),
				);
			}
		}
	}

	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => 'Missing alt text',
		'fix_hint' => 'Open the editor and add a descriptive alt attribute to each image. Empty alt="" is valid only for purely decorative images. Inline <svg> takes a direct-child <title> or aria-label instead — it has no alt attribute. Alt that repeats the filename or the caption, or names a category ("image", "chart") rather than the picture, reads as noise to a screen reader.',
	);
}

/**
 * Human-readable note for a quality finding.
 *
 * States the problem only. The rewrite is a human decision routed through the
 * staged-revision path, exactly like the coverage sweep's fixes.
 *
 * @param string $problem Reason code from sn_health_alt_quality_problem().
 * @param string $alt     The offending alt text.
 * @return string
 */
function sn_health_alt_quality_note( $problem, $alt ) {
	$quoted = '"' . ( strlen( $alt ) > 80 ? substr( $alt, 0, 77 ) . '…' : $alt ) . '"';
	switch ( $problem ) {
		case 'filename_echo':
			return 'Alt text ' . $quoted . ' repeats the image filename, which describes nothing to a screen reader.';
		case 'caption_duplicate':
			return 'Alt text ' . $quoted . ' duplicates the visible caption, so the description is announced twice.';
		case 'heading_duplicate':
			return 'Alt text ' . $quoted . ' duplicates the heading beside the image, so a screen reader announces it twice. Either describe the picture, or mark it decorative with alt="" and let the heading carry the label.';
		case 'generic_alt':
			return 'Alt text ' . $quoted . ' names a category rather than the image, so a screen reader learns nothing from it.';
	}
	return 'Alt text ' . $quoted . ' needs review.';
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
