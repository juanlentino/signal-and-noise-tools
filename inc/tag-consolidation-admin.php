<?php
/**
 * Content > Tags admin sub-tab: duplicate-tag clusters + a manual merge picker, a
 * read-only GET preview that renders the confirm panel, and a Recent merges list.
 * The merge POSTs back to admin.php?page=sn-content&tab=content&sub=tags with
 * sn_action=tag_merge; the central admin_init dispatcher (inc/admin-post-handler.php)
 * verifies the nonce + manage_options, calls sn_handle_tag_merge, and PRG-redirects
 * with ?sn_flash. Native wp-admin styling only.
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

	if ( ! $clusters ) {
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Duplicate tags', 'signal-and-noise-tools' ) . '</span></h2></div>';
		echo '<div class="inside"><p>' . esc_html__( 'No duplicate tags detected.', 'signal-and-noise-tools' ) . '</p></div></div>';
	} else {
		foreach ( $clusters as $c ) {
			sn_admin_tag_render_cluster( $c );
		}
	}

	sn_admin_tag_render_manual_picker();
	sn_admin_tag_render_ai_section();
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
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Possible duplicates', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="sn-content"><input type="hidden" name="tab" value="content"><input type="hidden" name="sub" value="tags"><input type="hidden" name="sn_tag_preview" value="1">';
	echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Canonical', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Merge?', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Tag', 'signal-and-noise-tools' ) . '</th><th class="num">' . esc_html__( 'Posts', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $c['terms'] as $t ) {
		$is_sug = ( (int) $t['term_id'] === (int) $c['suggested'] );
		echo '<tr><td><input type="radio" name="sn_tag_into" value="' . esc_attr( $t['term_id'] ) . '"' . ( $is_sug ? ' checked' : '' ) . '></td>';
		echo '<td><input type="checkbox" name="sn_tag_from[]" value="' . esc_attr( $t['term_id'] ) . '"' . ( $is_sug ? '' : ' checked' ) . '></td>';
		echo '<td><strong>' . esc_html( $t['name'] ) . '</strong> <code>' . esc_html( $t['slug'] ) . '</code></td>';
		echo '<td class="num">' . esc_html( number_format_i18n( (int) $t['count'] ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Preview merge', 'signal-and-noise-tools' ) . '</button> ';
	echo '<span class="description">' . esc_html__( 'Pick the canonical tag (radio) and which dupes to fold in (checkbox).', 'signal-and-noise-tools' ) . '</span></p>';
	echo '</form></div></div>';
}

/**
 * Manual "merge any two" picker (for semantic dupes the detector cannot spell-match).
 *
 * @return void
 */
function sn_admin_tag_render_manual_picker() {
	$tags = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Merge any two tags', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="sn-content"><input type="hidden" name="tab" value="content"><input type="hidden" name="sub" value="tags"><input type="hidden" name="sn_tag_preview" value="1">';
	echo '<p>' . esc_html__( 'Fold', 'signal-and-noise-tools' ) . ' ';
	echo '<select name="sn_tag_from[]">' . sn_admin_tag_options( $tags ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options pre-escaped in sn_admin_tag_options.
	echo esc_html__( 'into', 'signal-and-noise-tools' ) . ' ';
	echo '<select name="sn_tag_into">' . sn_admin_tag_options( $tags ) . '</select> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options pre-escaped in sn_admin_tag_options.
	echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Preview merge', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form></div></div>';
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
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Confirm merge', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
	if ( ! is_array( $pv ) || empty( $pv['from'] ) ) {
		echo '<p>' . esc_html__( 'Nothing to merge (the selected tags are no longer valid).', 'signal-and-noise-tools' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( sn_admin_tag_page_url() ) . '">' . esc_html__( 'Back', 'signal-and-noise-tools' ) . '</a></p></div></div>';
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
	echo '</form></div></div>';
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
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Recent tag operations', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside"><ul class="ul-disc">';
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
	echo '</ul></div></div>';
}

/**
 * AI section: suggest existing tags for untagged Notes (Suggest -> review -> Apply).
 * Dormant when no AI provider is configured.
 *
 * @return void
 */
function sn_admin_tag_render_ai_section() {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'AI: suggest tags for untagged Notes', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		echo '<p>' . esc_html__( 'Connect an AI provider (Settings > Connectors) to suggest tags.', 'signal-and-noise-tools' ) . '</p></div></div>';
		return;
	}

	$suggestions = function_exists( 'get_transient' ) ? get_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() ) : false;
	if ( is_array( $suggestions ) && $suggestions ) {
		echo '<p>' . esc_html__( 'Review the AI suggestions, then apply the ones you want. Suggestions are limited to your existing tags.', 'signal-and-noise-tools' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="tag_ai_apply">';
		foreach ( $suggestions as $s ) {
			$pid = (int) ( $s['post_id'] ?? 0 );
			if ( ! $pid || empty( $s['suggested'] ) ) {
				continue;
			}
			echo '<p><strong>' . esc_html( (string) ( $s['title'] ?? ( '#' . $pid ) ) ) . '</strong><br>';
			foreach ( $s['suggested'] as $tag ) {
				echo '<label style="margin-right:12px"><input type="checkbox" name="assign[' . esc_attr( $pid ) . '][]" value="' . esc_attr( (int) $tag['term_id'] ) . '" checked> ' . esc_html( (string) $tag['name'] ) . '</label>';
			}
			echo '</p>';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Apply selected', 'signal-and-noise-tools' ) . '</button></p>';
		echo '</form></div></div>';
		return;
	}

	$untagged = function_exists( 'sn_tag_untagged_notes' ) ? sn_tag_untagged_notes( 20 ) : array();
	if ( ! $untagged ) {
		echo '<p>' . esc_html__( 'Every published Note has at least one tag. Nothing to suggest.', 'signal-and-noise-tools' ) . '</p></div></div>';
		return;
	}
	echo '<p>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d untagged Note.', '%d untagged Notes.', count( $untagged ), 'signal-and-noise-tools' ), count( $untagged ) ) ) . ' '
		. esc_html__( 'Runs on demand on your AI key; up to 20 per click.', 'signal-and-noise-tools' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_ai_suggest">';
	echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Suggest tags', 'signal-and-noise-tools' ) . '</button>';
	echo '</form></div></div>';
}

/**
 * Unused-tag cleanup: list count-0 post_tag terms; delete the selected.
 *
 * @return void
 */
function sn_admin_tag_render_unused_section() {
	$unused = function_exists( 'sn_tag_find_unused' ) ? sn_tag_find_unused() : array();
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Unused tags', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
	if ( ! $unused ) {
		echo '<p>' . esc_html__( 'No unused tags.', 'signal-and-noise-tools' ) . '</p></div></div>';
		return;
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '" onsubmit="return confirm(\'Delete the selected unused tags?\');">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="tag_prune_unused"><p>';
	foreach ( $unused as $t ) {
		echo '<label style="display:block"><input type="checkbox" name="sn_tag_unused[]" value="' . esc_attr( (int) $t['term_id'] ) . '" checked> <strong>' . esc_html( (string) $t['name'] ) . '</strong> <code>' . esc_html( (string) $t['slug'] ) . '</code></label>';
	}
	echo '</p><p><button type="submit" class="button button-secondary">' . esc_html__( 'Delete selected', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form></div></div>';
}
