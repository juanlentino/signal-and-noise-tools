<?php
/**
 * Signal & Noise Tools: Content > Tags, the sentences both surfaces print.
 *
 * 2026-10-12 audit: the classic leaf and the native window said the same
 * things in copies that drifted ("1 notes", "1 carry", "1 posts"), a
 * hard-coded "About seventy requests" that ages as the site grows, "3 notes,
 * read 2 hours ago" counting only the flagged notes, a theme version stamp, a
 * "301-redirect", and every cluster checkbox labelled only "Merge?". One
 * builder per sentence here, painted by inc/tag-consolidation-admin.php and
 * apps/sn-dashboard/parts/leaves/content-tags*.php. Pure: no writes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A duplicate cluster's heading: the suggested tag and how many fold into it.
 *
 * @param array $c Cluster: { terms:[{term_id,name}], suggested }.
 * @return string
 */
function sn_tag_cluster_heading( array $c ) {
	$terms = (array) ( $c['terms'] ?? array() );
	$name  = '';
	foreach ( $terms as $t ) {
		if ( (int) ( $t['term_id'] ?? 0 ) === (int) ( $c['suggested'] ?? 0 ) ) {
			$name = sn_tag_name( $t['name'] ?? '' );
		}
	}
	$others = max( 0, count( $terms ) - 1 );
	/* translators: 1: suggested tag name, 2: how many near-duplicates. */
	return sprintf( _n( '"%1$s" and %2$s near-duplicate', '"%1$s" and %2$s near-duplicates', $others, 'signal-and-noise-tools' ), $name, number_format_i18n( $others ) );
}

/**
 * The accessible names of a cluster row's two controls: the table's own
 * header is not announced from inside the row, so each names its tag.
 *
 * @param string $name Tag name.
 * @return array{0:string,1:string} Canonical radio, Merge checkbox.
 */
function sn_tag_cluster_control_labels( $name ) {
	$name = sn_tag_name( $name );
	return array(
		/* translators: %s: tag name. */
		sprintf( __( 'Keep %s as canonical', 'signal-and-noise-tools' ), $name ),
		/* translators: %s: tag name. */
		sprintf( __( 'Merge %s', 'signal-and-noise-tools' ), $name ),
	);
}

/**
 * A tag name as text. WordPress stores term names already HTML-escaped
 * ("R&amp;B"), and the kit escapes what it paints, so an undecoded name read
 * "R&amp;B" on screen (2026-10-12 review). Decoded once here; every surface
 * escapes it once when it paints it (esc_html() would not double-escape, the
 * kit's htmlspecialchars() does).
 *
 * @param mixed $name The stored name.
 * @return string
 */
function sn_tag_name( $name ) {
	return html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
}

/**
 * The confirm panel's dry-run sentence.
 *
 * @param array $pv Preview: { from:[{name}], into:{name}, posts_affected }.
 * @return string
 */
function sn_tag_merge_sentence( array $pv ) {
	$names = array();
	foreach ( (array) ( $pv['from'] ?? array() ) as $f ) {
		$names[] = sn_tag_name( $f['name'] ?? '' );
	}
	$posts = (int) ( $pv['posts_affected'] ?? 0 );
	return sprintf(
		/* translators: 1: source tag names, 2: canonical tag name, 3: post count */
		_n( 'This moves %3$s post from %1$s into "%2$s", then deletes the source tags. Links to the old tag archives redirect permanently to "%2$s".', 'This moves %3$s posts from %1$s into "%2$s", then deletes the source tags. Links to the old tag archives redirect permanently to "%2$s".', $posts, 'signal-and-noise-tools' ),
		implode( ', ', $names ),
		sn_tag_name( $pv['into']['name'] ?? '' ),
		number_format_i18n( $posts )
	);
}

/**
 * What a tag-fit pass does and what it costs, from today's notes.
 *
 * @return string
 */
function sn_tag_fit_about() {
	$what = __( 'Jev reads every published and scheduled note against the tags it carries, the tag descriptions as the state: a tag whose subject the note does not touch is listed for removal.', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_jev_note_ids' ) ) {
		return $what . ' ' . __( 'One request per note that carries a tag.', 'signal-and-noise-tools' );
	}
	$n = count( sn_jev_note_ids() );
	/* translators: %s: published and scheduled notes now. */
	return $what . ' ' . sprintf( __( 'One request per note that carries a tag: at most %s now.', 'signal-and-noise-tools' ), number_format_i18n( $n ) );
}

/**
 * The last pass in one sentence, with `%s` left for when it ran (each
 * surface paints its own time: an os-relative-time, or "2 hours ago").
 *
 * @param array $data    The stored pass (sn_jev_tags_data()).
 * @param int   $flagged Notes with a misfit (count of sn_jev_tags_rows()).
 * @return string A sprintf format with one %s.
 */
function sn_tag_fit_read_format( array $data, $flagged ) {
	$read = count( (array) ( $data['notes'] ?? array() ) );
	if ( (int) $flagged > 0 ) {
		/* translators: 1: notes flagged, 2: notes read; %%s stays for the time. */
		return sprintf( _n( 'Jev flagged %1$s of %2$s note (read %%s).', 'Jev flagged %1$s of %2$s notes (read %%s).', $read, 'signal-and-noise-tools' ), number_format_i18n( (int) $flagged ), number_format_i18n( $read ) );
	}
	/* translators: 1: notes read; %%s stays for the time. */
	return sprintf( _n( 'Jev read %1$s note %%s: every tag on it touches its subject.', 'Jev read %1$s notes %%s: every tag on every note touches its subject.', $read, 'signal-and-noise-tools' ), number_format_i18n( $read ) );
}

/**
 * What a listed misfit is, under the flagged notes.
 *
 * @return string
 */
function sn_tag_fit_listed() {
	return __( 'Listed: a tag Jev is confident the note does not touch (it scored under 0.5 of 2, with confidence of 0.7 or more). Nothing else is listed, and Jev proposes no tags. Jev judged each tag by its description, so a right tag read wrong is a description to fix. Removing a tag leaves the note\'s text alone, so a published note can take the change.', 'signal-and-noise-tools' );
}

/**
 * The by-tag summary: every tag in the pass, the wide ones named.
 *
 * @param array $rows Every tag in the pass (sn_jev_tags_by_tag()).
 * @param array $wide The tags with notes that only touch them.
 * @return string
 */
function sn_tag_by_tag_summary( array $rows, array $wide ) {
	$n = count( $rows );
	$w = count( $wide );
	return sprintf(
		'%1$s; %2$s: %3$s.',
		/* translators: %s: tags in the last pass. */
		sprintf( _n( '%s tag in the last pass', '%s tags in the last pass', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) ),
		/* translators: %s: tags carrying notes that only touch them. */
		sprintf( _n( '%s carries notes that only touch it', '%s carry notes that only touch them', $w, 'signal-and-noise-tools' ), number_format_i18n( $w ) ),
		$wide ? implode( ', ', array_map( 'sn_tag_name', array_column( $wide, 'name' ) ) ) : __( 'none', 'signal-and-noise-tools' )
	);
}

/**
 * The Groups box when the theme does not provide its tag groups.
 *
 * @return string
 */
function sn_tag_groups_missing() {
	return __( 'The tag groups are not available: the active theme does not provide them.', 'signal-and-noise-tools' );
}
