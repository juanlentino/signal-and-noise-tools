<?php
/**
 * Signal & Noise Tools: the /workflow page generator and Page upsert.
 *
 * Reads ONLY sn_workflow_public_data() (inc/workflow-page.php), so a map row
 * whose "Show on page" is unchecked never reaches any markup here. Output is
 * one wp:html block around <div class="sn-workflow-page">, the same shape as
 * /now and /uses; the theme renders it through templates/page-workflow.html.
 *
 * Every field is escaped with esc_html AND has `[` `]` encoded as &#91; &#93;:
 * WordPress runs shortcodes over post_content, and esc_html leaves brackets
 * alone. esc_html also encodes `<` and `>`, so no field can close the wp:html
 * block comment (`-->`) or open a new block (`<!-- wp:`).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escape one field for the generated body: esc_html plus bracket encoding.
 *
 * @param string $text Raw field.
 * @return string
 */
function sn_workflow_esc( $text ) {
	return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( (string) $text ) );
}

/**
 * The sample section, or '' when it holds nothing.
 *
 * @param array<string,string> $s label, title, intro, body, outcome.
 * @return string
 */
function sn_workflow_sample_html( array $s ) {
	if ( '' === trim( implode( '', $s ) ) ) {
		return '';
	}
	$out = '<section class="sn-workflow-sample">';
	if ( '' !== $s['label'] ) {
		$out .= '<p class="sn-workflow-sample__label">' . sn_workflow_esc( $s['label'] ) . '</p>';
	}
	if ( '' !== $s['title'] ) {
		$out .= '<h2 class="sn-workflow-sample__title">' . sn_workflow_esc( $s['title'] ) . '</h2>';
	}
	if ( '' !== $s['intro'] ) {
		$out .= '<p class="sn-workflow-sample__intro">' . sn_workflow_esc( $s['intro'] ) . '</p>';
	}
	if ( '' !== $s['body'] ) {
		// No whitespace between <pre> and <code>: HTML drops a newline right
		// after <pre>, and the body's own first character must survive.
		$label = '' !== $s['title'] ? $s['title'] : 'Sample';
		// The body is esc_html'd with double encoding ON: core's esc_html
		// passes existing entities through, so a typed `&amp;` would render
		// as `&` and the sample would not be verbatim.
		$body  = htmlspecialchars( $s['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
		$out  .= '<pre class="sn-workflow-sample__body" tabindex="0" role="region" aria-label="' . str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_attr( $label ) ) . '"><code>'
			. str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $body ) . '</code></pre>';
	}
	if ( '' !== $s['outcome'] ) {
		$out .= '<p class="sn-workflow-sample__outcome">' . sn_workflow_esc( $s['outcome'] ) . '</p>';
	}
	return $out . '</section>';
}

/**
 * Build the /workflow Page body from PUBLIC data. '' when there is nothing
 * to show: the caller never writes an empty body.
 *
 * @param array|null $pub sn_workflow_public_data() output.
 * @return string
 */
function sn_workflow_page_html( $pub ) {
	if ( ! is_array( $pub ) ) {
		return '';
	}
	$out = '';
	if ( '' !== $pub['title'] || '' !== $pub['dek'] ) {
		$out .= '<header class="sn-workflow-hero">';
		$out .= '' !== $pub['title'] ? '<h1 class="sn-workflow-title">' . sn_workflow_esc( $pub['title'] ) . '</h1>' : '';
		$out .= '' !== $pub['dek'] ? '<p class="sn-workflow-dek">' . sn_workflow_esc( $pub['dek'] ) . '</p>' : '';
		$out .= '</header>';
	}
	$out .= sn_workflow_sample_html( $pub['sample'] );
	if ( ! empty( $pub['map'] ) ) {
		$out .= '<section class="sn-workflow-map"><ul class="sn-workflow-map__list">';
		foreach ( $pub['map'] as $row ) {
			$out .= '<li class="sn-workflow-map__item">'
				. ( '' !== $row['title'] ? '<span class="sn-workflow-map__title">' . sn_workflow_esc( $row['title'] ) . '</span>' : '' )
				. ( '' !== $row['line'] ? ' <span class="sn-workflow-map__line">' . sn_workflow_esc( $row['line'] ) . '</span>' : '' ) . '</li>';
		}
		$out .= '</ul></section>';
	}
	if ( ! empty( $pub['rules'] ) ) {
		$out .= '<section class="sn-workflow-rules"><ol class="sn-workflow-rules__list">';
		foreach ( $pub['rules'] as $row ) {
			$out .= '<li class="sn-workflow-rules__item">'
				. ( '' !== $row['rule'] ? '<strong class="sn-workflow-rules__rule">' . sn_workflow_esc( $row['rule'] ) . '</strong>' : '' )
				. ( '' !== $row['explanation'] ? ' <span class="sn-workflow-rules__explanation">' . sn_workflow_esc( $row['explanation'] ) . '</span>' : '' ) . '</li>';
		}
		$out .= '</ol></section>';
	}
	if ( '' === $out ) {
		return '';
	}
	return "<!-- wp:html -->\n<div class=\"sn-workflow-page\">" . $out . "</div>\n<!-- /wp:html -->";
}

/**
 * Create-or-update the top-level /workflow Page. Title and excerpt are the
 * owner's own fields, so both are written on every sync (the excerpt is the
 * meta description). Returns the Page ID, or 0 on an empty body or a refused
 * write.
 *
 * @param string $body  Generated body.
 * @param array  $pub   Public data (title, dek).
 * @return int
 */
function sn_workflow_upsert_page( $body, array $pub ) {
	if ( '' === trim( (string) $body ) ) {
		return 0;
	}
	if ( function_exists( 'snt_generated_page_guard' ) && ! snt_generated_page_guard( 'workflow', $body ) ) {
		return 0;
	}
	$fields = array(
		'post_title'   => '' !== $pub['title'] ? $pub['title'] : 'Workflow',
		'post_excerpt' => $pub['dek'],
		'post_content' => $body,
	);
	$page = get_page_by_path( SN_WORKFLOW_SLUG );
	if ( $page ) {
		wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_status' => 'publish' ) + $fields ) );
		return (int) $page->ID;
	}
	$new_id = wp_insert_post(
		wp_slash( $fields + array(
			'post_name'     => SN_WORKFLOW_SLUG,
			'post_parent'   => 0,
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'page_template' => 'page-workflow',
		) ),
		false
	);
	return is_int( $new_id ) && $new_id > 0 ? $new_id : 0;
}

/**
 * Regenerate the /workflow Page from the stored document. When the public
 * view is empty (everything cleared, or only hidden rows remain), a Page that
 * already exists is moved to draft rather than left showing rows that are no
 * longer public: fail closed. Returns 'published', 'withdrawn', 'empty', or
 * 'failed' (the write guard or wp_insert_post refused).
 *
 * @return string
 */
function sn_workflow_sync_page() {
	$pub  = sn_workflow_public_data();
	$body = sn_workflow_page_html( $pub );
	if ( '' !== $body ) {
		return sn_workflow_upsert_page( $body, $pub ) > 0 ? 'published' : 'failed';
	}
	$page = get_page_by_path( SN_WORKFLOW_SLUG );
	if ( $page && 'publish' === ( $page->post_status ?? '' ) ) {
		wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_status' => 'draft' ) ) );
		return 'withdrawn';
	}
	return 'empty';
}
