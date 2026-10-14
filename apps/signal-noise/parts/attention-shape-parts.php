<?php
/**
 * Signal & Noise app, the Attention composition: the signals list, the reader contract, the row shape and the doors.
 *
 * A pure move from parts/attention.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The readers, keyed by the kind each produces.
 *
 * @return array<string,callable-string>
 */
function attention_signals() {
	return array(
		'integrity' => __NAMESPACE__ . '\attention_integrity',
		'anchors'   => __NAMESPACE__ . '\attention_anchors',
		'edge'      => __NAMESPACE__ . '\attention_edge',
		'citations' => __NAMESPACE__ . '\attention_citations',
		'schedule'  => __NAMESPACE__ . '\attention_schedule',
		'pending'   => __NAMESPACE__ . '\attention_pending',
		'health'    => __NAMESPACE__ . '\attention_health',
		'watches'   => __NAMESPACE__ . '\attention_watches',
		'readers'   => __NAMESPACE__ . '\attention_readers',
		'search'    => __NAMESPACE__ . '\attention_search', // v14.7.0
		'live'      => __NAMESPACE__ . '\attention_live', // 23.2.0: the live-surge analytics signal
	);
}

/**
 * A reader's return: rows, the reading's own stamp, and whether it failed.
 *
 * @param array<int,array<string,mixed>> $rows  Rows.
 * @param string                         $stamp attention_stamp() output.
 * @return array{rows:array,stamp:string,unreadable:bool}
 */
function attention_read( array $rows = array(), $stamp = '' ) {
	return array(
		'rows'       => array_values( $rows ),
		'stamp'      => (string) $stamp,
		'unreadable' => false,
	);
}

/** @return array{rows:array,stamp:string,unreadable:bool} The failure answer. */
function attention_unreadable() {
	return array(
		'rows'       => array(),
		'stamp'      => '',
		'unreadable' => true,
	);
}

/**
 * One queue row, before it becomes an item.
 *
 * `requires` is a capability the READER of the queue must hold for the row to
 * be shown at all. It exists because the composition is CACHED SITE-WIDE: a
 * reader that decided visibility while composing would let whoever filled the
 * cache decide for whoever reads it. A row states the right instead, and
 * attention_visible_rows() applies it per user, on every read.
 *
 * @param array<string,mixed> $row Partial row.
 * @return array<string,mixed>
 */
function attention_row( array $row ) {
	return array_merge(
		array(
			'kind'     => '',
			'key'      => '',
			'title'    => '',
			'subtitle' => '',
			'tone'     => 'neutral',
			'stamp'    => '',
			'source'   => '',
			'door'     => '',
			'door_label' => '',
			'post_id'  => 0,
			'section'  => '',
			'requires' => '',
		),
		$row
	);
}

/**
 * The door to one S&N Dashboard leaf, or '' when the admin dock is absent.
 *
 * Resolved through snt_desktop_admin_url() rather than a literal slug: the
 * registry answers where a leaf lives, and a hardcoded query string is the
 * stale-slug class the dock's own resolver exists to guard.
 *
 * @param string $slug Tab slug.
 * @param string $sub  Leaf slug, or ''.
 * @return string
 */
function attention_door( $slug, $sub = '' ) {
	return function_exists( 'snt_desktop_admin_url' ) ? (string) \snt_desktop_admin_url( $slug, $sub ) : '';
}

/**
 * The Health leaf, which needs `sub=health` explicitly: sn-monitoring's first
 * leaf is Analytics, so the bare tab URL lands a health click one leaf short
 * (inc/desktop-mode-attention.php:87-90 says exactly this).
 *
 * @return string
 */
function attention_health_door() {
	$tab = attention_door( 'sn-monitoring' );
	if ( '' === $tab || ! function_exists( 'add_query_arg' ) ) {
		return $tab;
	}
	return (string) add_query_arg( 'sub', 'health', $tab );
}

/**
 * The two post sections a jump can land in, resolved ONCE per paint.
 *
 * Resolving a section costs a full registry pass -- every descriptor, a
 * current_user_can() per section, a uasort -- so asking per ROW made the cost
 * of the queue the number of rows that name a post. The map is built once per
 * attention_items() call and threaded down; it is never cached across calls,
 * because what is offered is a per-user answer that must be re-asked.
 *
 * @return array<string,array<string,mixed>|null> id => descriptor, or null when not offered.
 */
function attention_post_sections() {
	$out = array();
	foreach ( array( 'notes', 'pages' ) as $id ) {
		$out[ $id ] = \snt_os_app_section( $id );
	}
	return $out;
}

/**
 * Does this section, offered to THIS user, actually LIST this post?
 *
 * Two questions, because a jump needs both. The registry answers the first
 * (is the section offered at all). The section's own `contains` callable
 * answers the second: Notes lists only the note category and Pages only the
 * pages carrying the signing meta, both over their own statuses and their own
 * author scope -- so a post no section lists would otherwise be offered an
 * "Open the note" that lands on nothing. A section that declares no `contains`
 * cannot answer, and an unanswerable question is a refusal.
 *
 * @param string                                      $section Section id.
 * @param int                                         $post_id Post id, or 0 for a section-only jump.
 * @param array<string,array<string,mixed>|null>|null $offered attention_post_sections() output, or null to resolve.
 * @return bool
 */
function attention_section_lists( $section, $post_id, $offered = null ) {
	$section = (string) $section;
	if ( '' === $section ) {
		return false;
	}
	if ( is_array( $offered ) && array_key_exists( $section, $offered ) ) {
		$descriptor = $offered[ $section ];
	} else {
		$descriptor = \snt_os_app_section( $section );
	}
	if ( ! is_array( $descriptor ) ) {
		return false;
	}
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return true;
	}
	$contains = isset( $descriptor['contains'] ) ? $descriptor['contains'] : null;
	return is_callable( $contains ) && (bool) call_user_func( $contains, $post_id );
}

/**
 * The app section a post id belongs to, or '' when neither lists it.
 *
 * Notes lists posts, Pages lists the signed pages -- but the POST TYPE only
 * names the candidate. Whether that section is offered to this user, and
 * whether it holds this particular post, is asked of the section itself, so a
 * jump is never offered into a section the reader cannot open or a list the
 * post is not on.
 *
 * @param int                                         $post_id Post id.
 * @param array<string,array<string,mixed>|null>|null $offered attention_post_sections() output, or null to resolve.
 * @return string 'notes' | 'pages' | ''.
 */
function attention_section_for_post( $post_id, $offered = null ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( $post_id );
	if ( ! is_object( $post ) ) {
		return '';
	}
	$section = 'page' === (string) ( $post->post_type ?? '' ) ? 'pages' : 'notes';
	return attention_section_lists( $section, $post_id, $offered ) ? $section : '';
}

/**
 * A post's title, or a stated absence — never a blank cell.
 *
 * @param int    $post_id  Post id.
 * @param string $fallback What to say when there is no post or no title.
 * @return string
 */
function attention_post_title( $post_id, $fallback = '' ) {
	$title = ( (int) $post_id > 0 && function_exists( 'get_the_title' ) ) ? (string) get_the_title( (int) $post_id ) : '';
	if ( '' !== $title ) {
		return $title;
	}
	return '' !== (string) $fallback ? (string) $fallback : __( '(no title)', 'signal-and-noise-tools' );
}

