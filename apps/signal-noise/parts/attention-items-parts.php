<?php
/**
 * Signal & Noise app, the Attention composition: the item shape, the acknowledgements and the section descriptor.
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
 * One queue row as the client sees it.
 *
 * @param array<string,mixed>                         $row     A composed row.
 * @param array<string,array<string,mixed>|null>|null $offered attention_post_sections() output, or null to resolve.
 * @return array<string,mixed>
 */
function attention_item( array $row, $offered = null ) {
	$kind    = (string) ( $row['kind'] ?? '' );
	$key     = (string) preg_replace( '/[^a-zA-Z0-9_-]/', '-', (string) ( $row['key'] ?? '' ) );
	$stamp   = (string) ( $row['stamp'] ?? '' );
	$asof    = attention_asof( $stamp );
	$label   = attention_kind_label( $kind );
	$actions = array();
	if ( '' !== (string) ( $row['door'] ?? '' ) ) {
		$actions[] = array(
			'label' => '' !== (string) ( $row['door_label'] ?? '' ) ? (string) $row['door_label'] : __( 'Open in S&N Dashboard', 'signal-and-noise-tools' ),
			'url'   => (string) $row['door'],
		);
	}
	// The jump: one dispatch that sets the section AND the item, so a row that
	// names a post opens that post's dossier instead of leaving the reader to
	// find it. Offered only when a section that LISTS it is offered to them --
	// and a section a reader pre-set goes through exactly the same gate as one
	// resolved from the post, or the rows that name their own section would be
	// the only ones never checked.
	$section = (string) ( $row['section'] ?? '' );
	$post_id = (int) ( $row['post_id'] ?? 0 );
	if ( '' === $section ) {
		$section = $post_id > 0 ? attention_section_for_post( $post_id, $offered ) : '';
	} elseif ( ! attention_section_lists( $section, $post_id, $offered ) ) {
		$section = '';
	}
	if ( '' !== $section ) {
		// The word comes from the SECTION the jump resolved to, never from the
		// presence of a post id: a signed page's row said "note" while landing
		// in Pages.
		$words     = array(
			'notes' => __( 'Open the note', 'signal-and-noise-tools' ),
			'pages' => __( 'Open the page', 'signal-and-noise-tools' ),
		);
		$actions[] = array(
			'label'    => ( $post_id > 0 && isset( $words[ $section ] ) ) ? $words[ $section ] : __( 'Open the section', 'signal-and-noise-tools' ),
			'dispatch' => 'jump',
			'args'     => array( 'section' => $section, 'item' => $post_id > 0 ? (string) $post_id : '' ),
		);
	}
	// v14.5.0 -- SOLVE ON THE ROW. The two kinds with a button offer it here,
	// dispatching to the SAME handlers the note dossier uses (purge_action,
	// anchor_action: same guards, same toasts); the type gate resolves the
	// post's own section server-side. Every row offers Acknowledge: hidden at
	// this stamp, back when the fact moves.
	// Offered under the jump's own gate: a section this reader is offered
	// lists the post. An unlisted post gets no button, exactly as it gets no jump.
	if ( '' !== $section && 'edge' === $kind ) {
		$actions[] = array( 'label' => __( 'Purge edge', 'signal-and-noise-tools' ), 'dispatch' => 'purge', 'args' => array( 'item' => (string) $post_id ), 'variant' => 'primary' );
	} elseif ( '' !== $section && 'anchors' === $kind ) {
		$actions[] = array( 'label' => __( 'Retry anchor', 'signal-and-noise-tools' ), 'dispatch' => 'anchor', 'args' => array( 'item' => (string) $post_id ), 'variant' => 'primary' );
	}
	$actions[] = array( 'label' => __( 'Acknowledge', 'signal-and-noise-tools' ), 'dispatch' => 'ack', 'args' => array( 'key' => $kind . '-' . $key, 'stamp' => $stamp ), 'variant' => 'ghost' );
	return array(
		'id'          => 'a-' . $kind . '-' . $key,
		'title'       => (string) ( $row['title'] ?? '' ),
		'subtitle'    => (string) ( $row['subtitle'] ?? '' ),
		'thumbnail'   => '',
		'icon'        => attention_kind_icon( $kind ),
		// The status IS the kind: the pills filter the queue by which signal
		// produced a row. 19.2.0: a row about a PAGE (the /provenance/ hub,
		// an essay) files under Pages instead, the owner's second axis; the
		// badge below still names the signal, so nothing is lost on the tile.
		'status'      => attention_is_page_row( $row ) ? 'pages' : $kind,
		'statusLabel' => attention_is_page_row( $row ) ? __( 'Pages', 'signal-and-noise-tools' ) : $label,
		'date'        => $stamp,
		'dateLabel'   => $asof,
		'badge'       => array(
			'text'  => $label,
			'tone'  => (string) ( $row['tone'] ?? 'neutral' ),
			'title' => $asof,
		),
		// The two the descriptor declares, and only those: the list view already
		// paints the kind and the date from statusLabel and dateLabel.
		'columns'     => array(
			'fact'  => (string) ( $row['subtitle'] ?? '' ),
			'stamp' => '' !== $stamp ? attention_local( $stamp ) : __( 'not stamped', 'signal-and-noise-tools' ),
		),
		'detail'      => array(
			'hero'    => '',
			'facts'   => array(
				array( __( 'Subject', 'signal-and-noise-tools' ), (string) ( $row['title'] ?? '' ) ),
				array( __( 'What', 'signal-and-noise-tools' ), (string) ( $row['subtitle'] ?? '' ) ),
				array( __( 'When', 'signal-and-noise-tools' ), $asof ),
				array( __( 'Source', 'signal-and-noise-tools' ), (string) ( $row['source'] ?? '' ) ),
			),
			'blocks'  => array(),
			'actions' => $actions,
		),
	);
}

/**
 * The acknowledgement store, sanitised on read.
 *
 * @return array<string,string> key => stamp.
 */
function attention_acks() {
	$raw = function_exists( 'get_option' ) ? get_option( ATTENTION_ACK_OPT, array() ) : array();
	$out = array();
	foreach ( is_array( $raw ) ? $raw : array() as $key => $stamp ) {
		$key = (string) preg_replace( '/[^a-zA-Z0-9_-]/', '-', (string) $key );
		if ( '' !== $key ) {
			$out[ $key ] = (string) $stamp;
		}
	}
	return $out;
}

/**
 * Record an acknowledgement, pruning keys the queue no longer holds so the
 * store cannot grow past the queue's own size.
 *
 * @param string $key   Row key, sanitised.
 * @param string $stamp The row's stamp at acknowledgement ('' for an unstamped row).
 * @return void
 */
function attention_ack( $key, $stamp ) {
	$acks = attention_acks();
	$live = array();
	foreach ( (array) attention_rows()['rows'] as $row ) {
		if ( is_array( $row ) ) {
			$live[ attention_row_key( $row ) ] = true;
		}
	}
	$acks = array_intersect_key( $acks, $live );
	$acks[ (string) $key ] = (string) $stamp;
	if ( function_exists( 'update_option' ) ) {
		update_option( ATTENTION_ACK_OPT, $acks, false );
	}
}

/**
 * One row's key as the client will echo it back: kind + sanitised key.
 *
 * @param array<string,mixed> $row
 * @return string
 */
function attention_row_key( array $row ) {
	return (string) ( $row['kind'] ?? '' ) . '-' . (string) preg_replace( '/[^a-zA-Z0-9_-]/', '-', (string) ( $row['key'] ?? '' ) );
}

/**
 * Has this row been acknowledged AT ITS CURRENT STAMP?
 *
 * @param array<string,mixed>  $row
 * @param array<string,string> $acks attention_acks().
 * @return bool
 */
function attention_is_acked( array $row, array $acks ) {
	$key = attention_row_key( $row );
	return array_key_exists( $key, $acks ) && $acks[ $key ] === (string) ( $row['stamp'] ?? '' );
}

/**
 * The composed rows THIS user may see.
 *
 * The composition is one transient for every administrator, so a row whose
 * visibility depends on the reader states the capability it needs and the
 * gate runs HERE, at read time. Composing the gate instead would let whoever
 * filled the cache decide for whoever reads it -- for sixty seconds, and
 * invisibly, because the two users get identical rows out of one store.
 *
 * @return array<int,array<string,mixed>>
 */
function attention_visible_rows() {
	$rows = array();
	$acks = attention_acks();
	foreach ( (array) attention_rows()['rows'] as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$requires = (string) ( $row['requires'] ?? '' );
		if ( '' !== $requires && ! ( function_exists( 'current_user_can' ) && current_user_can( $requires ) ) ) {
			continue;
		}
		// Acknowledged at this stamp: solved until the fact moves (v14.5.0).
		if ( attention_is_acked( $row, $acks ) ) {
			continue;
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * Every row the queue holds, as items.
 *
 * @return array<int,array<string,mixed>>
 */
function attention_items() {
	// Once per paint, not once per row: resolving a section is a full registry
	// pass with a capability check per section.
	$offered = attention_post_sections();
	$items   = array();
	foreach ( attention_visible_rows() as $row ) {
		$items[] = attention_item( $row, $offered );
	}
	return $items;
}

/**
 * How many rows the queue holds, for the root folder tile. Reads the SAME
 * cache items() reads THROUGH THE SAME GATE, so the tile and the list can
 * never disagree.
 *
 * @return int
 */
function attention_count() {
	return count( attention_visible_rows() );
}

/**
 * The line under "Nothing needs you": WHEN the readers last looked.
 *
 * An empty queue with no date is indistinguishable from a queue nobody read,
 * which is the one reading this section must never produce.
 *
 * @return string
 */
function attention_empty_note() {
	$read    = attention_rows();
	$read_at = attention_stamp( $read['read_at'] ?? 0 );
	$stamp   = attention_stamp( $read['stamp'] ?? '' );
	if ( '' === $stamp ) {
		/* translators: %s: a timestamp in the site timezone with its zone. */
		return sprintf( __( 'No reader carried a stamp. Composed %s.', 'signal-and-noise-tools' ), attention_local( $read_at ) );
	}
	/* translators: 1: a timestamp in the site timezone with its zone. 2: another. */
	return sprintf( __( 'The newest reading is from %1$s. Composed %2$s.', 'signal-and-noise-tools' ), attention_local( $stamp ), attention_local( $read_at ) );
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$statuses = array();
		foreach ( attention_kinds() as $kind => $label ) {
			$statuses[] = array( 'value' => $kind, 'label' => $label );
		}
		// 19.2.0: rows about a Page, whatever signal raised them.
		$statuses[] = array( 'value' => 'pages', 'label' => __( 'Pages', 'signal-and-noise-tools' ) );
		$sections[] = array(
			'id'             => 'attention',
			'label'          => __( 'Attention', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-flag',
			// Read-only by construction, like Citations and Scheduled: `entry`,
			// no `restPath`, no `edit_url`, no `hasDossier` -- the client's three
			// opt-ins, all declined, which keeps the selection actions, the drag
			// lift and the dossier fetch off this section with no special case.
			'kind'           => 'entry',
			// The rows are operational, and every signal behind them is a
			// manage_options reading in its own leaf.
			'capability'     => 'manage_options',
			// First at the root: this is what the phone opens on.
			'position'       => 5,
			'statuses'       => $statuses,
			'default_status' => '',
			'columns'        => array(
				array( 'key' => 'fact', 'label' => __( 'What', 'signal-and-noise-tools' ) ),
				array( 'key' => 'stamp', 'label' => __( 'Measured', 'signal-and-noise-tools' ) ),
			),
			// An empty queue is the GOOD state, and the client's generic
			// "Nothing here yet." would read as a section that never filled.
			'empty_heading'  => __( 'Nothing needs you', 'signal-and-noise-tools' ),
			// A callable, resolved by payload() for the OPEN section only: a
			// literal would have to be composed at registry time, which is
			// before the capability gate runs.
			'empty_note'     => __NAMESPACE__ . '\attention_empty_note',
			'count'          => __NAMESPACE__ . '\attention_count',
			'items'          => __NAMESPACE__ . '\attention_items',
		);
		return $sections;
	}
);
