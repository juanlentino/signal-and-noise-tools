<?php
/**
 * S&N Dashboard — Content → Tags, painted from the kit.
 *
 * The classic leaf (inc/tag-consolidation-admin.php,
 * `sn_admin_render_tag_cleanup_section()`) is a GET preview → POST confirm
 * flow: a glance, one "Preview merge" form per duplicate cluster and a manual
 * any-two-tags picker (both `method="get"`, `sn_tag_preview=1`), the AI
 * suggest/apply pair, the unused-tag prune and the recent operations. The
 * three query params that ARE state on the classic page (`sn_tag_preview`,
 * `sn_tag_from[]`, `sn_tag_into`) reach this painter through the window's
 * `params` state — the `go` action's reading of a GET form — instead of
 * `$_GET`. Same readers, same forms, same field names, same handlers.
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-tags-parts.php';

/**
 * The `sn_*` params the window carries — the classic page's `$_GET`.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return array<string,mixed>
 */
function tags_params( array $ctx ) {
	$state = $ctx['state'] ?? null;
	if ( ! is_object( $state ) || ! method_exists( $state, 'get' ) ) {
		return array();
	}
	$params = $state->get( 'params' );
	return is_array( $params ) ? $params : array();
}

/**
 * The list view's readings, read the way the classic leaf reads them
 * (tag-consolidation-admin.php:84-90).
 *
 * @return array{clusters:array,unused:array,total:int}
 */
function tags_data() {
	$clusters = function_exists( 'sn_tag_find_duplicate_clusters' ) ? \sn_tag_find_duplicate_clusters() : array();
	$unused   = function_exists( 'sn_tag_find_unused' ) ? \sn_tag_find_unused() : array();
	$total    = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'fields' => 'count' ) );
	$total    = is_array( $total ) ? count( $total ) : ( is_numeric( $total ) ? (int) $total : 0 );
	return array(
		'clusters' => is_array( $clusters ) ? $clusters : array(),
		'unused'   => is_array( $unused ) ? $unused : array(),
		'total'    => $total,
	);
}

/**
 * The dry-run preview the params ask for (tag-consolidation-admin.php:76-79):
 * `sn_tag_from` is an array (absint each), `sn_tag_into` one id.
 *
 * @param array<string,mixed> $params The window's params.
 * @return array{0:mixed,1:int[],2:int} preview, from, into.
 */
function tags_preview( array $params ) {
	$from = isset( $params['sn_tag_from'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $params['sn_tag_from'] ) ) ) : array();
	$into = isset( $params['sn_tag_into'] ) ? (int) sanitize_text_field( wp_unslash( $params['sn_tag_into'] ) ) : 0;
	$pv   = function_exists( 'sn_tag_merge_preview' ) ? \sn_tag_merge_preview( $from, $into ) : null;
	return array( $pv, $from, $into );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_tags( array $ctx ) {
	$tab = (string) ( $ctx['tab'] ?? 'content' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'You do not have permission to manage tags.', 'signal-and-noise-tools' ) );
	}

	// Read-only preview -> confirm panel (no mutation).
	$params = tags_params( $ctx );
	if ( ! empty( $params['sn_tag_preview'] ) ) {
		list( $pv, $from, $into ) = tags_preview( $params );
		return tags_confirm_html( $pv, $from, $into, $tab );
	}

	$data = tags_data();
	$out  = tags_glance_html( $data['clusters'], $data['unused'], $data['total'] );
	if ( ! $data['clusters'] ) {
		$out .= \snt_kit_section(
			__( 'Duplicate tags', 'signal-and-noise-tools' ),
			\snt_kit_empty( __( 'No duplicate tags detected.', 'signal-and-noise-tools' ) )
		);
	} else {
		foreach ( $data['clusters'] as $c ) {
			$out .= tags_cluster_html( (array) $c );
		}
	}
	$out .= tags_picker_html();
	$out .= tags_ai_html();
	$out .= tags_unused_html( $data['unused'] );
	$out .= tags_recent_html();
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/tags'] = __NAMESPACE__ . '\\paint_content_tags';
		return $painters;
	}
);
