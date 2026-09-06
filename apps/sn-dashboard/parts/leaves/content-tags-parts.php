<?php
/**
 * S&N Dashboard — Content → Tags: the section painters.
 *
 * WHY THREE OF THE FORMS ARE A NATIVE `<form>`. `<os-form>` collects its
 * values by name, later-wins, and reads every checkbox as a boolean
 * (os-form.ts `getValues()` / `_readField()`, OpenStation 1.1.6). The classic
 * cluster form (`sn_tag_from[]`), the AI apply form (`assign[{post}][]`) and
 * the prune form (`sn_tag_unused[]`) are lists of checkboxes whose VALUE is a
 * term id, and every handler reads those values — through an `<os-form>` they
 * would arrive as one boolean under one name (the prune would delete term 1).
 * The runtime's native-form path ships FormData (repeated names as arrays, a
 * checked box's value; `submit` is preventDefault-ed), which is the wire shape
 * the handlers understand and the vehicle the captured classic leaf rides
 * today (`snt_os_host_rewrite_form()`). The single-valued forms — the picker,
 * AI suggest, the confirm — are kit forms.
 *
 * STYLING GAP, LEFT DELIBERATELY. `.snt-form--native`, its `.snt-submit`
 * button and the checkbox/radio rows above have NO rule in either
 * assets/os-app.css or apps/sn-dashboard/sn-dashboard.css (verified:
 * `grep -rn 'snt-form--native\|snt-submit'` returns zero hits in both, and
 * neither sheet carries an element-level input/button/form rule), so the
 * three primary buttons and every checkbox/radio in this leaf render as
 * unstyled browser defaults. Both stylesheets are OUTSIDE this port's
 * allowed file list (only content-tags.php, content-tags-parts.php and the
 * leaf's own test may be touched here) — fixing it needs a follow-up pass
 * on apps/sn-dashboard/sn-dashboard.css, not a change to this file.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The hidden fields every classic GET preview form carries (tag-consolidation-admin.php:122,146).
 *
 * @return array<string,string>
 */
function tags_page_hidden() {
	return array( 'page' => 'sn-content', 'tab' => 'content', 'sub' => 'tags', 'sn_tag_preview' => '1' );
}

/**
 * A native form: `post` for a write (carrying `sn_action` + the shared nonce),
 * `get` for the GET preview (dispatching `go`). Options: confirm, danger.
 *
 * @param string               $method post|get.
 * @param array<string,string> $hidden name => value.
 * @param string               $inner  Painted controls.
 * @param string               $submit Submit label.
 * @param array<string,mixed>  $opts   Options.
 * @return string
 */
function tags_form( $method, array $hidden, $inner, $submit, array $opts = array() ) {
	$is_post = 'post' === $method;
	$fields  = '';
	foreach ( $hidden as $name => $value ) {
		$fields .= \snt_kit_field( 'hidden', $name, '', $value );
	}
	return \snt_kit_tag(
		'form',
		array(
			'class'             => 'snt-form snt-form--native',
			'method'            => $is_post ? 'post' : 'get',
			'os-action'         => $is_post ? 'post' : 'go',
			'os-confirm'        => isset( $opts['confirm'] ) ? (string) $opts['confirm'] : null,
			'os-confirm-danger' => ! empty( $opts['danger'] ),
		),
		$fields . $inner . \snt_kit_tag( 'button', array( 'type' => 'submit', 'class' => 'snt-submit' ), \snt_kit_esc( $submit ) )
	);
}

/**
 * The two fields a classic POST form carries besides its own.
 *
 * @param string $sn_action Handler action.
 * @return array<string,string>
 */
function tags_post_hidden( $sn_action ) {
	return array( 'sn_action' => (string) $sn_action, '_wpnonce' => \snt_kit_nonce() );
}

/**
 * A native checkbox row on the shell's list tokens.
 *
 * @param string $name  Field name (with its `[]`).
 * @param int    $value Term id.
 * @param string $label Painted label HTML (escaped by the caller).
 * @return string
 */
function tags_check_row( $name, $value, $label ) {
	return '<li class="snt-list__row"><label class="snt-list__label">'
		. \snt_kit_tag( 'input', array( 'type' => 'checkbox', 'name' => (string) $name, 'value' => (string) (int) $value, 'checked' => true ) )
		. ' ' . $label . '</label></li>';
}

/**
 * The glance: the same three cards (`snt_tags_glance_cards()`) as stats; the
 * pill's text is the caption, its kind the swatch.
 *
 * @param array $clusters Duplicate clusters.
 * @param array $unused   Unused terms.
 * @param int   $total    Total tags.
 * @return string
 */
function tags_glance_html( $clusters, $unused, $total ) {
	if ( ! function_exists( 'snt_tags_glance_cards' ) ) {
		return '';
	}
	$out = '';
	foreach ( \snt_tags_glance_cards( $clusters, $unused, $total ) as $card ) {
		$pill = isset( $card['pill'] ) && is_array( $card['pill'] ) ? $card['pill'] : array();
		$out .= \snt_kit_stat( (string) ( $card['value'] ?? '' ), (string) ( $card['label'] ?? '' ), (string) ( $pill['text'] ?? '' ), (string) ( $pill['kind'] ?? '' ) );
	}
	return '<section class="snt-stats" aria-label="' . \snt_kit_esc( __( 'Tags at a glance', 'signal-and-noise-tools' ) ) . '">' . $out . '</section>';
}

/**
 * One cluster: canonical radio (defaulted to the suggested term), include
 * checkbox, name + slug, post count, and the GET "Preview merge" form.
 *
 * @param array $c Cluster: { key, terms:[{term_id,name,slug,count}], suggested }.
 * @return string
 */
function tags_cluster_html( array $c ) {
	$rows = '';
	foreach ( (array) ( $c['terms'] ?? array() ) as $t ) {
		$id     = (int) ( $t['term_id'] ?? 0 );
		$is_sug = $id === (int) ( $c['suggested'] ?? 0 );
		$rows  .= '<li class="snt-list__row">'
			. \snt_kit_tag( 'input', array( 'type' => 'radio', 'name' => 'sn_tag_into', 'value' => (string) $id, 'checked' => $is_sug, 'aria-label' => __( 'Canonical', 'signal-and-noise-tools' ) ) )
			. \snt_kit_tag( 'input', array( 'type' => 'checkbox', 'name' => 'sn_tag_from[]', 'value' => (string) $id, 'checked' => ! $is_sug, 'aria-label' => __( 'Merge?', 'signal-and-noise-tools' ) ) )
			. '<span class="snt-list__label"><strong>' . \snt_kit_esc( (string) ( $t['name'] ?? '' ) ) . '</strong> ' . \snt_kit_code( (string) ( $t['slug'] ?? '' ), false ) . '</span>'
			. '<span class="snt-list__value">' . \snt_kit_esc( number_format_i18n( (int) ( $t['count'] ?? 0 ) ) ) . '</span>'
			. '</li>';
	}
	// A real header row, in the same column order as the data rows (radio,
	// checkbox, name+slug, count), so the words sit over the controls they
	// name instead of in a run-on sentence above the list. Reuses the two
	// classes the data rows already carry (snt-list__value is a fixed,
	// content-width flex cell — assets/os-app.css:82 — snt-list__label is the
	// flexible one) instead of inventing `snt-list__col` /
	// `snt-list__row--head`, which have no rule in any stylesheet.
	$head  = '<li class="snt-list__row">'
		. '<span class="snt-list__value">' . \snt_kit_esc( __( 'Canonical', 'signal-and-noise-tools' ) ) . '</span>'
		. '<span class="snt-list__value">' . \snt_kit_esc( __( 'Merge?', 'signal-and-noise-tools' ) ) . '</span>'
		. '<span class="snt-list__label">' . \snt_kit_esc( __( 'Tag', 'signal-and-noise-tools' ) ) . '</span>'
		. '<span class="snt-list__value">' . \snt_kit_esc( __( 'Posts', 'signal-and-noise-tools' ) ) . '</span>'
		. '</li>';
	$inner = '<ul class="snt-list">' . $head . $rows . '</ul>';
	// The hint follows the submit button, as the classic markup prints it
	// (`<button>…</button> <span class="description">…</span>` in the same
	// <p>, tag-consolidation-admin.php) — not before it.
	$hint  = '<p class="snt-hint">' . \snt_kit_esc( __( 'Pick the canonical tag (radio) and which dupes to fold in (checkbox).', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section(
		__( 'Possible duplicates', 'signal-and-noise-tools' ),
		tags_form( 'get', tags_page_hidden(), $inner, __( 'Preview merge', 'signal-and-noise-tools' ) ) . $hint
	);
}

/**
 * Manual "merge any two" picker: two kit selects in an `<os-form os-action="go">`
 * (single-valued, so the kit form carries them; attributes per os-form's help).
 *
 * @return string
 */
function tags_picker_html() {
	$tags    = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	$options = array();
	foreach ( (array) $tags as $t ) {
		if ( is_object( $t ) && isset( $t->term_id ) ) {
			$options[ (string) $t->term_id ] = (string) $t->name . ' (' . (int) $t->count . ')';
		}
	}
	$first  = array() !== $options ? (string) array_key_first( $options ) : '';
	$inner  = \snt_kit_field( 'select', 'sn_tag_from[]', __( 'Fold', 'signal-and-noise-tools' ), $first, array( 'options' => $options ) )
		. \snt_kit_field( 'select', 'sn_tag_into', __( 'into', 'signal-and-noise-tools' ), $first, array( 'options' => $options ) );
	$hidden = '';
	foreach ( tags_page_hidden() as $name => $value ) {
		$hidden .= \snt_kit_field( 'hidden', $name, '', $value );
	}
	$form = \snt_kit_tag(
		'os-form',
		array( 'class' => 'snt-form', 'os-action' => 'go', 'submit-label' => __( 'Preview merge', 'signal-and-noise-tools' ), 'show-reset' => 'false', 'columns' => '2' ),
		$inner . $hidden
	);
	return \snt_kit_section( __( 'Merge any two tags', 'signal-and-noise-tools' ), $form );
}

/**
 * The confirm panel: the dry-run sentence + the `tag_merge` form (hidden
 * comma-joined `sn_tag_from`, `sn_tag_into`), Cancel/Back as in-window links.
 *
 * @param mixed  $pv   Preview result (array, WP_Error or null).
 * @param int[]  $from Source term ids.
 * @param int    $into Canonical term id.
 * @param string $tab  The painting tab.
 * @return string
 */
function tags_confirm_html( $pv, array $from, $into, $tab ) {
	$heading = __( 'Confirm merge', 'signal-and-noise-tools' );
	$back    = array( 'tab' => 'content', 'sub' => 'tags', 'current' => (string) $tab );
	if ( ! is_array( $pv ) || empty( $pv['from'] ) ) {
		return \snt_kit_section(
			$heading,
			\snt_kit_empty( __( 'Nothing to merge (the selected tags are no longer valid).', 'signal-and-noise-tools' ) )
			. \snt_kit_go( __( 'Back', 'signal-and-noise-tools' ), $back, array( 'variant' => 'secondary' ) )
		);
	}
	$names = array();
	foreach ( (array) $pv['from'] as $f ) {
		$names[] = (string) ( $f['name'] ?? '' );
	}
	$prose = sprintf(
		/* translators: 1: source tag names, 2: canonical tag name, 3: post count */
		__( 'This moves %3$d posts from %1$s into "%2$s", then deletes the source tags. The old tag archives will 301-redirect to "%2$s".', 'signal-and-noise-tools' ),
		implode( ', ', $names ),
		(string) ( $pv['into']['name'] ?? '' ),
		(int) ( $pv['posts_affected'] ?? 0 )
	);
	$form = \snt_kit_form(
		'tag_merge',
		'',
		array(
			'submit' => $heading,
			'hidden' => array( 'sn_tag_from' => implode( ',', array_map( 'intval', $from ) ), 'sn_tag_into' => (string) (int) $into ),
		)
	);
	return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( $prose ) . '</p>' . $form . \snt_kit_go( __( 'Cancel', 'signal-and-noise-tools' ), $back, array( 'variant' => 'secondary' ) ) );
}

/**
 * AI section: dormant note, the review/apply form, the nothing-to-suggest
 * state, or the suggest form — the classic's four branches in order.
 *
 * @return string
 */
function tags_ai_html() {
	$heading = __( 'AI: suggest tags for untagged Notes', 'signal-and-noise-tools' );
	if ( ! function_exists( 'snt_ai_is_available' ) || ! \snt_ai_is_available() ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'Connect an AI provider (Settings > Connectors) to suggest tags.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$suggestions = function_exists( 'get_transient' ) ? get_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() ) : false;
	if ( is_array( $suggestions ) && $suggestions ) {
		$rows = '';
		foreach ( $suggestions as $s ) {
			$pid = (int) ( $s['post_id'] ?? 0 );
			if ( ! $pid || empty( $s['suggested'] ) ) {
				continue;
			}
			$boxes = '';
			foreach ( (array) $s['suggested'] as $tag ) {
				$boxes .= tags_check_row( 'assign[' . $pid . '][]', (int) ( $tag['term_id'] ?? 0 ), \snt_kit_esc( (string) ( $tag['name'] ?? '' ) ) );
			}
			$rows .= '<p class="snt-prose"><strong>' . \snt_kit_esc( (string) ( $s['title'] ?? ( '#' . $pid ) ) ) . '</strong></p><ul class="snt-list">' . $boxes . '</ul>';
		}
		return \snt_kit_section(
			$heading,
			'<p class="snt-prose">' . \snt_kit_esc( __( 'Review the AI suggestions, then apply the ones you want. Suggestions are limited to your existing tags.', 'signal-and-noise-tools' ) ) . '</p>'
			. tags_form( 'post', tags_post_hidden( 'tag_ai_apply' ), $rows, __( 'Apply selected', 'signal-and-noise-tools' ) )
		);
	}
	$untagged = function_exists( 'sn_tag_untagged_notes' ) ? \sn_tag_untagged_notes( 20 ) : array();
	if ( ! $untagged ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'Every published Note has at least one tag. Nothing to suggest.', 'signal-and-noise-tools' ) ) );
	}
	$count = sprintf( /* translators: %d: count */ _n( '%d untagged Note.', '%d untagged Notes.', count( $untagged ), 'signal-and-noise-tools' ), count( $untagged ) )
		. ' ' . __( 'Runs on demand on your AI key; up to 20 per click.', 'signal-and-noise-tools' );
	return \snt_kit_section(
		$heading,
		'<p class="snt-prose">' . \snt_kit_esc( $count ) . '</p>'
		. \snt_kit_form( 'tag_ai_suggest', '', array( 'submit' => __( 'Suggest tags', 'signal-and-noise-tools' ) ) )
	);
}

/**
 * Unused-tag cleanup: every count-0 term checked, deleted on confirm.
 *
 * @param array $unused From sn_tag_find_unused().
 * @return string
 */
function tags_unused_html( array $unused ) {
	$heading = __( 'Unused tags', 'signal-and-noise-tools' );
	if ( ! $unused ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'No unused tags.', 'signal-and-noise-tools' ) ) );
	}
	$rows = '';
	foreach ( $unused as $t ) {
		$rows .= tags_check_row( 'sn_tag_unused[]', (int) ( $t['term_id'] ?? 0 ), '<strong>' . \snt_kit_esc( (string) ( $t['name'] ?? '' ) ) . '</strong> ' . \snt_kit_code( (string) ( $t['slug'] ?? '' ), false ) );
	}
	return \snt_kit_section(
		$heading,
		tags_form(
			'post',
			tags_post_hidden( 'tag_prune_unused' ),
			'<ul class="snt-list">' . $rows . '</ul>',
			__( 'Delete selected', 'signal-and-noise-tools' ),
			array( 'confirm' => __( 'Delete the selected unused tags?', 'signal-and-noise-tools' ), 'danger' => true )
		)
	);
}

/**
 * Recent merges and prunes (the last ten), one line each.
 *
 * @return string
 */
function tags_recent_html() {
	$hist = get_option( 'sn_tag_merge_history', array() );
	if ( ! is_array( $hist ) || ! $hist ) {
		return '';
	}
	$items = '';
	foreach ( array_slice( $hist, 0, 10 ) as $h ) {
		if ( ! is_array( $h ) ) {
			continue;
		}
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
		$items .= '<li>' . \snt_kit_esc( $line ) . '</li>';
	}
	return \snt_kit_section( __( 'Recent tag operations', 'signal-and-noise-tools' ), '<ul class="snt-plain">' . $items . '</ul>' );
}
