<?php
/**
 * Content > Tags admin sub-tab: duplicate-tag clusters + a manual merge picker, a
 * read-only GET preview that renders the confirm panel, and a Recent merges list.
 * The merge POSTs back to admin.php?page=sn-content&tab=content&sub=tags with
 * sn_action=tag_merge; the central admin_init dispatcher (inc/admin-post-handler.php)
 * verifies the nonce + manage_options, calls sn_handle_tag_merge, and PRG-redirects
 * with ?sn_flash. System .sn-fieldset card vocabulary (cohesion pass v8.0.2).
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page URL the Tags sub-tab forms post/link to (carries page/tab/sub so the
 * central dispatcher accepts the POST and the flash lands on this sub-tab).
 *
 * @return string
 */
function sn_admin_tag_page_url() {
	return admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' );
}

/**
 * First-glance hero cards for the Tags sub-tab: total tags, duplicate clusters,
 * and unused tags. Pure — takes the already-fetched data, returns the card array
 * for sn_admin_glance_grid(). Mirrors snt_cron_glance_cards().
 *
 * @param array $clusters Duplicate-tag clusters.
 * @param array $unused   Count-0 post_tag terms.
 * @param int   $total    Total post_tag term count.
 * @return array<int,array<string,mixed>>
 */
function snt_tags_glance_cards( $clusters, $unused, $total ) {
	$cl = is_array( $clusters ) ? count( $clusters ) : 0;
	$un = is_array( $unused ) ? count( $unused ) : 0;
	return array(
		array(
			'label' => 'Tags total',
			'value' => number_format_i18n( (int) $total ),
		),
		array(
			'label' => 'Duplicate clusters',
			'value' => number_format_i18n( $cl ),
			'pill'  => $cl > 0 ? array( 'kind' => 'warn', 'text' => 'review' ) : array( 'kind' => 'ok', 'text' => 'clean' ),
		),
		array(
			'label' => 'Unused tags',
			'value' => number_format_i18n( $un ),
			'pill'  => $un > 0 ? array( 'kind' => 'warn', 'text' => 'prune' ) : array( 'kind' => 'ok', 'text' => 'clean' ),
		),
	);
}

/**
 * Render the Content > Tags sub-tab.
 *
 * @return void
 */
function sn_admin_render_tag_cleanup_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage tags.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	// Read-only GET preview -> confirm panel (no mutation).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview render, no state change.
	if ( ! empty( $_GET['sn_tag_preview'] ) ) {
		// sn_tag_from arrives as an array (the cluster checkboxes + manual picker use
		// name="sn_tag_from[]"). Parse the array (absint each); a comma-string would
		// collapse to empty under sanitize_text_field, which yielded "Nothing to merge".
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$from = isset( $_GET['sn_tag_from'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_GET['sn_tag_from'] ) ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$into = isset( $_GET['sn_tag_into'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['sn_tag_into'] ) ) : 0;
		$pv   = function_exists( 'sn_tag_merge_preview' ) ? sn_tag_merge_preview( $from, $into ) : null;
		sn_admin_tag_render_confirm( $pv, $from, $into );
		return;
	}

	$clusters = function_exists( 'sn_tag_find_duplicate_clusters' ) ? sn_tag_find_duplicate_clusters() : array();

	// Phase 4b: a first-glance hero leads the full-width list view (mirrors the
	// Cron glance-over-table pattern). Counts only — sourced from existing accessors.
	$unused_tags = function_exists( 'sn_tag_find_unused' ) ? sn_tag_find_unused() : array();
	$total_tags  = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'count' ) );
	$total_tags  = is_array( $total_tags ) ? count( $total_tags ) : ( is_numeric( $total_tags ) ? (int) $total_tags : 0 );
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="Tags at a glance">';
		sn_admin_glance_grid( snt_tags_glance_cards( $clusters, $unused_tags, $total_tags ) );
		echo '</section>';
	}

	if ( ! $clusters ) {
		echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Duplicate tags', 'signal-and-noise-tools' ) . '</h2>';
		echo '<p>' . esc_html__( 'No duplicate tags detected.', 'signal-and-noise-tools' ) . '</p></div>';
	} else {
		foreach ( $clusters as $c ) {
			sn_admin_tag_render_cluster( $c );
		}
	}

	sn_admin_tag_render_manual_picker();
	sn_admin_tag_render_fit_section();
	sn_admin_tag_render_by_tag_section();
	sn_admin_tag_render_groups_section();
	sn_admin_tag_render_unused_section();
	sn_admin_tag_render_recent_merges();
}

/**
 * One cluster card: members + counts, a canonical radio (defaulted to suggested),
 * include checkboxes, and a "Preview merge" GET form.
 *
 * @param array $c Cluster: { key, terms:[{term_id,name,slug,count}], suggested }.
 * @return void
 */
function sn_admin_tag_render_cluster( $c ) {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Possible duplicates', 'signal-and-noise-tools' ) . '</h2>';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="sn-content"><input type="hidden" name="tab" value="content"><input type="hidden" name="sub" value="tags"><input type="hidden" name="sn_tag_preview" value="1">';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Canonical', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Merge?', 'signal-and-noise-tools' ) . '</th><th class="manage-column column-primary">' . esc_html__( 'Tag', 'signal-and-noise-tools' ) . '</th><th class="num">' . esc_html__( 'Posts', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $c['terms'] as $t ) {
		$is_sug = ( (int) $t['term_id'] === (int) $c['suggested'] );
		echo '<tr><td><input type="radio" name="sn_tag_into" value="' . esc_attr( $t['term_id'] ) . '"' . ( $is_sug ? ' checked' : '' ) . '></td>';
		echo '<td><input type="checkbox" name="sn_tag_from[]" value="' . esc_attr( $t['term_id'] ) . '"' . ( $is_sug ? '' : ' checked' ) . '></td>';
		echo '<td class="column-primary"><strong>' . esc_html( $t['name'] ) . '</strong> <code>' . esc_html( $t['slug'] ) . '</code></td>';
		echo '<td class="num" data-colname="Posts">' . esc_html( number_format_i18n( (int) $t['count'] ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Preview merge', 'signal-and-noise-tools' ) . '</button> ';
	echo '<span class="description">' . esc_html__( 'Pick the canonical tag (radio) and which dupes to fold in (checkbox).', 'signal-and-noise-tools' ) . '</span></p>';
	echo '</form></div>';
}

/**
 * Manual "merge any two" picker (for semantic dupes the detector cannot spell-match).
 *
 * @return void
 */
function sn_admin_tag_render_manual_picker() {
	$tags = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Merge any two tags', 'signal-and-noise-tools' ) . '</h2>';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="sn-content"><input type="hidden" name="tab" value="content"><input type="hidden" name="sub" value="tags"><input type="hidden" name="sn_tag_preview" value="1">';
	echo '<p>' . esc_html__( 'Fold', 'signal-and-noise-tools' ) . ' ';
	echo '<select name="sn_tag_from[]">' . sn_admin_tag_options( $tags ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options pre-escaped in sn_admin_tag_options.
	echo esc_html__( 'into', 'signal-and-noise-tools' ) . ' ';
	echo '<select name="sn_tag_into">' . sn_admin_tag_options( $tags ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options pre-escaped in sn_admin_tag_options.
	echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Preview merge', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form></div>';
}

/**
 * <option> list for the manual picker (each option pre-escaped).
 *
 * @param array $tags Term objects.
 * @return string
 */
function sn_admin_tag_options( $tags ) {
	$out = '';
	foreach ( (array) $tags as $t ) {
		$out .= '<option value="' . esc_attr( $t->term_id ) . '">' . esc_html( $t->name ) . ' (' . (int) $t->count . ')</option>';
	}
	return $out;
}

/**
 * The confirm panel: the dry-run preview + the POST form that commits via the
 * central dispatcher (sn_action=tag_merge, central nonce, posts back to sn-content).
 *
 * @param array|null $pv   Preview result.
 * @param array      $from Source term ids.
 * @param int        $into Canonical term id.
 * @return void
 */
function sn_admin_tag_render_confirm( $pv, $from, $into ) {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Confirm merge', 'signal-and-noise-tools' ) . '</h2>';
	if ( ! is_array( $pv ) || empty( $pv['from'] ) ) {
		echo '<p>' . esc_html__( 'Nothing to merge (the selected tags are no longer valid).', 'signal-and-noise-tools' ) . '</p>';
		// #1228: was '</p></div></div>' — a stray extra </div> against the one
		// <div class="sn-fieldset"> opened above (the happy path below closes
		// with a single </div>, matching).
		echo '<p><a class="button" href="' . esc_url( sn_admin_tag_page_url() ) . '">' . esc_html__( 'Back', 'signal-and-noise-tools' ) . '</a></p></div>';
		return;
	}
	$names = array();
	foreach ( $pv['from'] as $f ) {
		$names[] = $f['name'];
	}
	echo '<p>' . esc_html( sprintf(
		/* translators: 1: source tag names, 2: canonical tag name, 3: post count */
		__( 'This moves %3$d posts from %1$s into "%2$s", then deletes the source tags. The old tag archives will 301-redirect to "%2$s".', 'signal-and-noise-tools' ),
		implode( ', ', $names ),
		$pv['into']['name'],
		(int) $pv['posts_affected']
	) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_merge">';
	echo '<input type="hidden" name="sn_tag_from" value="' . esc_attr( implode( ',', array_map( 'intval', (array) $from ) ) ) . '">';
	echo '<input type="hidden" name="sn_tag_into" value="' . esc_attr( (int) $into ) . '">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Confirm merge', 'signal-and-noise-tools' ) . '</button> ';
	echo '<a class="button" href="' . esc_url( sn_admin_tag_page_url() ) . '">' . esc_html__( 'Cancel', 'signal-and-noise-tools' ) . '</a>';
	echo '</form></div>';
}

/**
 * Recent merges list (the domain-appropriate "audit").
 *
 * @return void
 */
function sn_admin_tag_render_recent_merges() {
	$hist = get_option( 'sn_tag_merge_history', array() );
	if ( ! is_array( $hist ) || ! $hist ) {
		return;
	}
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Recent tag operations', 'signal-and-noise-tools' ) . '</h2><ul class="ul-disc">';
	foreach ( array_slice( $hist, 0, 10 ) as $h ) {
		$slugs = implode( ', ', array_map( 'strval', (array) ( $h['from'] ?? array() ) ) );
		if ( 'prune' === ( $h['op'] ?? 'merge' ) ) {
			$line = sprintf( /* translators: %s: deleted tag slugs */ __( 'deleted unused: %s', 'signal-and-noise-tools' ), $slugs );
		} else {
			$line = sprintf(
				/* translators: 1: source slugs, 2: canonical slug, 3: post count */
				__( '%1$s into "%2$s" (%3$d posts)', 'signal-and-noise-tools' ),
				$slugs,
				(string) ( $h['into'] ?? '' ),
				(int) ( $h['posts'] ?? 0 )
			);
		}
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	echo '</ul></div>';
}

/**
 * 16.9.0: Jev tag fit (Read tags now -> review -> Apply). Replaces the Claude
 * suggest section, which saw only untagged notes and only proposed. Reads the
 * stored pass; the rows are the allow-list the apply handler enforces.
 *
 * @return void
 */
function sn_admin_tag_render_fit_section() {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Jev: tag fit', 'signal-and-noise-tools' ) . '</h2>';
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		echo '<p>' . esc_html__( 'Install Connector for TypeSafe Jev and add the key under Settings › Connectors.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$action = admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' );
	$data   = function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_data() : null;
	$rows   = null === $data ? array() : sn_jev_tags_rows( $data );
	if ( null === $data ) {
		echo '<p>' . esc_html__( 'Jev reads every published and scheduled note against the tags it carries, the tag descriptions as the state: a tag whose subject the note does not touch is listed for removal. About seventy requests; under a cent.', 'signal-and-noise-tools' ) . '</p>';
	} elseif ( array() === $rows ) {
		echo '<p>' . esc_html( sprintf( /* translators: %s: how long ago */ __( 'Last read %s ago: every tag on every note touches its subject.', 'signal-and-noise-tools' ), human_time_diff( (int) $data['synced_at'], time() ) ) ) . '</p>';
	} else {
		echo '<p>' . esc_html( sprintf( /* translators: 1: notes flagged, 2: how long ago */ __( '%1$d notes, read %2$s ago. A misfit is a tag whose subject Jev could not find in the note, scored under 0.5 of 2 with confidence 0.7 or better; nothing else is listed, and Jev proposes no tags. Jev read each tag\'s description: a wrong reading of a right tag is the description to fix. Tags are not prose; a published note can take the change.', 'signal-and-noise-tools' ), count( $rows ), human_time_diff( (int) $data['synced_at'], time() ) ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="tag_fit_apply">';
		foreach ( $rows as $pid => $row ) {
			echo '<p><strong><a href="' . esc_url( get_edit_post_link( (int) $pid ) ?: '' ) . '">' . esc_html( $row['title'] ) . '</a></strong><br>';
			foreach ( $row['remove'] as $t ) {
				echo '<label class="snt-label-inline"><input type="checkbox" name="remove[' . esc_attr( (int) $pid ) . '][]" value="' . esc_attr( (int) $t['id'] ) . '"> ' . esc_html( sprintf( /* translators: 1: tag, 2: score */ __( 'Remove "%1$s" (attached for reach, %2$s of 2)', 'signal-and-noise-tools' ), (string) $t['name'], number_format_i18n( (float) $t['score'], 2 ) ) ) . '</label>';
			}
			echo '</p>';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Apply selected', 'signal-and-noise-tools' ) . '</button></p>';
		echo '</form>';
	}
	echo '<form method="post" action="' . esc_url( $action ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_fit_run">';
	echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Read tags now', 'signal-and-noise-tools' ) . '</button>';
	echo '</form></div>';
}

/**
 * 17.1.0: the pass pivoted per tag (see tags_by_tag_html() on the native leaf).
 * 17.2.1: a summary line, then only the tags with notes that only touch them.
 *
 * @return void
 */
function sn_admin_tag_render_by_tag_section() {
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return;
	}
	$data = function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_data() : null;
	if ( null === $data ) {
		return;
	}
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Jev: by tag', 'signal-and-noise-tools' ) . '</h2>';
	$rows = sn_jev_tags_by_tag( $data );
	if ( array() === $rows ) {
		echo '<p>' . esc_html__( 'No tags in the last pass.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$wide = array_values( array_filter( $rows, static fn( $r ) => array() !== $r['touching'] ) );
	echo '<p>' . esc_html( sprintf( /* translators: 1: tags, 2: tags with touching notes, 3: their names */ __( '%1$d tags in the last pass; %2$d carry notes that only touch them: %3$s.', 'signal-and-noise-tools' ), count( $rows ), count( $wide ), $wide ? implode( ', ', array_column( $wide, 'name' ) ) : __( 'none', 'signal-and-noise-tools' ) ) ) . ' ' . esc_html__( 'A note under 1 of 2 touches the tag rather than being about it; which tags a note carries stays your call.', 'signal-and-noise-tools' ) . '</p>';
	foreach ( $wide as $r ) {
		echo '<p><strong>' . esc_html( sprintf( /* translators: 1: tag, 2: notes, 3: mean score, 4: touching count */ __( '%1$s: %2$d notes, mean %3$s of 2, %4$d only touching it', 'signal-and-noise-tools' ), $r['name'], (int) $r['notes'], number_format_i18n( (float) $r['mean'], 2 ), count( $r['touching'] ) ) ) . '</strong></p>';
		if ( $r['touching'] ) {
			echo '<ul>';
			foreach ( $r['touching'] as $t ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( (int) $t['post_id'] ) ?: '' ) . '">' . esc_html( $t['title'] ) . '</a> ' . esc_html( sprintf( /* translators: 1: score, 2: confidence */ __( '(%1$s of 2, confidence %2$s)', 'signal-and-noise-tools' ), number_format_i18n( (float) $t['score'], 2 ), number_format_i18n( (float) $t['confidence'], 2 ) ) ) . '</li>';
			}
			echo '</ul>';
		}
	}
	echo '</div>';
}

/**
 * 17.2.0: file tags under /notes/tags' headings (see tags_groups_html()).
 * 17.2.1: a ledger and one small form, never a select per tag.
 *
 * @return void
 */
function sn_admin_tag_render_groups_section() {
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Groups on /notes/tags', 'signal-and-noise-tools' ) . '</h2>';
	if ( ! function_exists( 'sn_notes_tag_groups' ) || ! function_exists( 'sn_notes_tag_group_effective' ) ) {
		echo '<p>' . esc_html__( 'The theme\'s tag groups are not available (Signal & Noise theme 13.4.0 or later).', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	$ledger = sn_admin_tag_groups_ledger();
	if ( array() === $ledger['tags'] ) {
		echo '<p>' . esc_html__( 'No tags.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	echo '<ul>';
	foreach ( $ledger['groups'] as $g ) {
		echo '<li><strong>' . esc_html( $g['title'] ) . '</strong>: ' . esc_html( $g['names'] ? implode( ', ', $g['names'] ) : __( 'nothing yet', 'signal-and-noise-tools' ) ) . '</li>';
	}
	if ( $ledger['unfiled'] ) {
		echo '<li><strong>' . esc_html__( 'Not yet filed', 'signal-and-noise-tools' ) . '</strong>: ' . esc_html( implode( ', ', $ledger['unfiled'] ) ) . '</li>';
	}
	echo '</ul>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_group_apply">';
	echo '<label>' . esc_html__( 'Tag', 'signal-and-noise-tools' ) . ' <select name="file_tag">';
	foreach ( $ledger['tags'] as $t ) {
		echo '<option value="' . esc_attr( (string) $t['id'] ) . '">' . esc_html( $t['name'] ) . '</option>';
	}
	echo '</select></label> <label>' . esc_html__( 'Heading', 'signal-and-noise-tools' ) . ' <select name="file_group">';
	foreach ( $ledger['options'] as $value => $label ) {
		echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></label> <button type="submit" class="button button-primary">' . esc_html__( 'File', 'signal-and-noise-tools' ) . '</button></form></div>';
}

/**
 * The ledger behind the Groups section (the classic twin of
 * tags_groups_ledger() on the native leaf; same rows, same words).
 *
 * @return array{groups:array,unfiled:array,tags:array,options:array}
 */
function sn_admin_tag_groups_ledger() {
	$options = array( '' => __( 'Not yet filed', 'signal-and-noise-tools' ) );
	$groups  = array();
	foreach ( sn_notes_tag_groups() as $g ) {
		$title = html_entity_decode( (string) $g['title'], ENT_QUOTES, 'UTF-8' );
		$options[ (string) $g['id'] ] = $title;
		$groups[ (string) $g['id'] ] = array( 'title' => $title, 'names' => array() );
	}
	$terms   = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	$tags    = array();
	$unfiled = array();
	foreach ( (array) $terms as $t ) {
		if ( ! is_object( $t ) || ! isset( $t->term_id ) ) {
			continue;
		}
		$gid    = (string) sn_notes_tag_group_effective( $t );
		$tags[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'group' => $gid );
		if ( isset( $groups[ $gid ] ) ) {
			$groups[ $gid ]['names'][] = (string) $t->name;
		} else {
			$unfiled[] = (string) $t->name;
		}
	}
	usort( $tags, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
	foreach ( $groups as &$g ) {
		sort( $g['names'], SORT_FLAG_CASE | SORT_STRING );
	}
	unset( $g );
	sort( $unfiled, SORT_FLAG_CASE | SORT_STRING );
	return array( 'groups' => array_values( $groups ), 'unfiled' => $unfiled, 'tags' => $tags, 'options' => $options );
}

/**
 * Unused-tag cleanup: list count-0 post_tag terms; delete the selected.
 *
 * @return void
 */
function sn_admin_tag_render_unused_section() {
	$unused = function_exists( 'sn_tag_find_unused' ) ? sn_tag_find_unused() : array();
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Unused tags', 'signal-and-noise-tools' ) . '</h2>';
	if ( ! $unused ) {
		echo '<p>' . esc_html__( 'No unused tags.', 'signal-and-noise-tools' ) . '</p></div>';
		return;
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '" onsubmit="return confirm(\'Delete the selected unused tags?\');">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_prune_unused"><p>';
	foreach ( $unused as $t ) {
		echo '<label class="snt-label-block"><input type="checkbox" name="sn_tag_unused[]" value="' . esc_attr( (int) $t['term_id'] ) . '" checked> <strong>' . esc_html( (string) $t['name'] ) . '</strong> <code>' . esc_html( (string) $t['slug'] ) . '</code></label>';
	}
	echo '</p><p><button type="submit" class="button button-secondary">' . esc_html__( 'Delete selected', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form></div>';
}
