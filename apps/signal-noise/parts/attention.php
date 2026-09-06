<?php
/**
 * Signal & Noise app — the Attention section: what needs the operator now.
 *
 * The phone's first screen (position 5, first at the root). Nine READERS over
 * signals something else already computed, composed into one queue of rows.
 *
 * THIS FILE IS THE COMPOSITION and touches no signal: the vocabulary (the
 * kinds, the stamps, the doors), the ordering, the cache, the item shape and
 * the descriptor. The nine readers live in parts/attention-readers.php, which
 * is the only half that calls the estate. The two fail differently -- a reader
 * breaks when the thing it reads changes shape, this breaks when the queue's
 * own contract changes -- and the suite pins the seam by name, so a tenth
 * signal read from this side is a red test rather than a habit.
 *
 * IT READS, IT NEVER COMPUTES. The rule is the desktop attention badge's, and
 * it is verbatim (inc/desktop-mode-attention.php:8-11): no reader here starts
 * a health scan, an integrity sweep, an edge probe or a live re-check. Every
 * one of them opens a stored measurement or runs one bounded, read-only query,
 * and every row says WHEN what it reports was measured — a queue whose rows
 * carry no stamp cannot be told from a queue reporting yesterday.
 *
 * ABSENT IS NOT ZERO, in three separate places:
 *
 *   - A reader whose subsystem is NOT INSTALLED yields no rows and makes no
 *     claim. It does not yield a warning: a row that is always present is the
 *     failure mode this queue exists to fix (inc/watches.php:4-23 — "a daily
 *     message that usually says 'nothing yet' trains its reader to stop
 *     opening it").
 *   - A reader that IS present and cannot answer — it threw, or handed back
 *     something that is not its contract — yields exactly ONE warning row
 *     saying so. Never a zero, never silence.
 *   - A reader that ran and measured nothing yields no rows, and the empty
 *     queue says when its readers last looked.
 *
 * READ-ONLY BY CONSTRUCTION, like Citations and Scheduled: `kind: entry`, no
 * `restPath`, no `edit_url`, no `hasDossier` — the client's three opt-ins, all
 * declined, which is what keeps the selection actions, the drag lift and the
 * dossier fetch off this section without a single special case in the client.
 *
 * THE COMPOSITION IS CACHED FOR SIXTY SECONDS, with its own `read_at`. The
 * payload calls `count()` for every section on every root paint
 * (parts/payload.php), and this section's count is the number of rows — so
 * without a cache the nine readers would run on every paint of every section.
 * The cache is not a freshness claim: `read_at` is projected into the empty
 * state and into every row's "as of", so what the reader sees is the age of
 * the reading, not the age of the paint.
 *
 * @package SignalNoiseTools
 * @since 13.103.0
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** The composed queue's transient key. */
const ATTENTION_CACHE_KEY = 'snt_os_attention';

/** How long a composition stands, in seconds. */
const ATTENTION_TTL = 60;

/**
 * Now, in unix seconds.
 *
 * `$GLOBALS['__now']` is honoured so a fixture can hold the clock still: the
 * cache's own 60-second window is the thing under test, and a suite that has
 * to sleep for a minute to reach the second branch is a suite nobody runs.
 * Nothing in the plugin ever writes it.
 *
 * @return int
 */
function attention_now() {
	if ( isset( $GLOBALS['__now'] ) ) {
		return (int) $GLOBALS['__now'];
	}
	return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
}

/**
 * Any stored instant as one stamp: UTC 'Y-m-d H:i:s', or '' when there is none.
 *
 * The estate stamps in three shapes — unix ints (the sweep, the scan, the
 * probe log, the snapshot), MySQL UTC strings (the citations table, the
 * schedule queue) and ISO 8601 (a commit's `committed_at`). A queue that
 * printed each in its own shape would make two rows look like two different
 * kinds of fact. A bare MySQL string is read as UTC, which is what every
 * producer here writes.
 *
 * @param mixed $when A unix int, a MySQL UTC string, or an ISO 8601 string.
 * @return string 'Y-m-d H:i:s' in UTC, or '' when unreadable or absent.
 */
function attention_stamp( $when ) {
	if ( is_int( $when ) || is_float( $when ) || ( is_string( $when ) && ctype_digit( $when ) ) ) {
		$ts = (int) $when;
		return $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
	$when = trim( (string) $when );
	if ( '' === $when ) {
		return '';
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $when ) ) {
		$when .= ' UTC';
	}
	$ts = strtotime( $when );
	return ( false === $ts || $ts <= 0 ) ? '' : gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * A stamp as a date label. An unstamped row SAYS it is unstamped.
 *
 * @param string $stamp attention_stamp() output.
 * @return string
 */
function attention_asof( $stamp ) {
	$stamp = (string) $stamp;
	if ( '' === $stamp ) {
		return __( 'not stamped', 'signal-and-noise-tools' );
	}
	/* translators: %s: a UTC timestamp, 'Y-m-d H:i:s'. */
	return sprintf( __( 'as of %s UTC', 'signal-and-noise-tools' ), $stamp );
}

/**
 * The kinds, in the order the pills offer them. A kind is a row's `status`,
 * so the section's pills and a row's statusLabel read from ONE list.
 *
 * @return array<string,string> kind => label.
 */
function attention_kinds() {
	return array(
		'integrity' => __( 'Integrity', 'signal-and-noise-tools' ),
		'anchors'   => __( 'Anchors', 'signal-and-noise-tools' ),
		'edge'      => __( 'Edge', 'signal-and-noise-tools' ),
		'citations' => __( 'Citations', 'signal-and-noise-tools' ),
		'schedule'  => __( 'Scheduled', 'signal-and-noise-tools' ),
		'pending'   => __( 'Pending review', 'signal-and-noise-tools' ),
		'health'    => __( 'Health', 'signal-and-noise-tools' ),
		'watches'   => __( 'Watches', 'signal-and-noise-tools' ),
		'readers'   => __( 'Machine readers', 'signal-and-noise-tools' ),
	);
}

/**
 * One kind's label; an unknown kind reads as itself rather than as blank.
 *
 * @param string $kind A key of attention_kinds().
 * @return string
 */
function attention_kind_label( $kind ) {
	$kinds = attention_kinds();
	return isset( $kinds[ (string) $kind ] ) ? $kinds[ (string) $kind ] : (string) $kind;
}

/**
 * One kind's tile icon.
 *
 * @param string $kind A key of attention_kinds().
 * @return string Dashicons class.
 */
function attention_kind_icon( $kind ) {
	$icons = array(
		'integrity' => 'dashicons-shield',
		'anchors'   => 'dashicons-shield-alt',
		'edge'      => 'dashicons-cloud',
		'citations' => 'dashicons-admin-links',
		'schedule'  => 'dashicons-clock',
		'pending'   => 'dashicons-edit-page',
		'health'    => 'dashicons-heart',
		'watches'   => 'dashicons-visibility',
		'readers'   => 'dashicons-rest-api',
	);
	return isset( $icons[ (string) $kind ] ) ? $icons[ (string) $kind ] : 'dashicons-flag';
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
 * The app section a post id belongs to, or '' when neither lists it.
 *
 * Notes lists posts, Pages lists the signed pages; the registry is asked
 * whether the section is offered to THIS user at all, so a jump is never
 * offered into a section the reader cannot open.
 *
 * @param int $post_id Post id.
 * @return string 'notes' | 'pages' | ''.
 */
function attention_section_for_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
		return '';
	}
	$post = get_post( $post_id );
	if ( ! is_object( $post ) ) {
		return '';
	}
	$section = 'page' === (string) ( $post->post_type ?? '' ) ? 'pages' : 'notes';
	return \snt_os_app_section( $section ) ? $section : '';
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

// ─────────────────────────────────────────────────────────────────────────
// The composition.
// ─────────────────────────────────────────────────────────────────────────

/**
 * The row a reader that could not answer produces. One per signal, never a
 * zero: a queue that silently drops a failed reader reports "nothing needs
 * you" over a signal nobody read.
 *
 * @param string $kind The signal's kind.
 * @return array<string,mixed>
 */
function attention_unreadable_row( $kind ) {
	$label = attention_kind_label( $kind );
	return attention_row( array(
		'kind'     => (string) $kind,
		'key'      => 'unreadable',
		'title'    => $label,
		/* translators: %s: the name of an attention signal, e.g. "Citations". */
		'subtitle' => sprintf( __( '%s could not be read.', 'signal-and-noise-tools' ), $label ),
		'tone'     => 'warning',
		'stamp'    => '',
		'source'   => __( 'The reader failed; nothing was measured', 'signal-and-noise-tools' ),
	) );
}

/**
 * Danger, then warning, then neutral; inside a tone, newest stamp first.
 *
 * An unstamped row sorts LAST within its tone. An empty string sorts first in
 * every string comparison, which is exactly backwards: a row that carries no
 * reading time is not the most recent thing in the queue.
 *
 * @param array<string,mixed> $a Row.
 * @param array<string,mixed> $b Row.
 * @return int
 */
function attention_compare( array $a, array $b ) {
	$rank = array( 'danger' => 0, 'warning' => 1, 'neutral' => 2 );
	$ra   = $rank[ (string) ( $a['tone'] ?? 'neutral' ) ] ?? 2;
	$rb   = $rank[ (string) ( $b['tone'] ?? 'neutral' ) ] ?? 2;
	if ( $ra !== $rb ) {
		return $ra <=> $rb;
	}
	$sa = (string) ( $a['stamp'] ?? '' );
	$sb = (string) ( $b['stamp'] ?? '' );
	if ( ( '' === $sa ) !== ( '' === $sb ) ) {
		return '' === $sa ? 1 : -1;
	}
	return strcmp( $sb, $sa )
		?: ( strcmp( (string) ( $a['kind'] ?? '' ), (string) ( $b['kind'] ?? '' ) )
			?: strcmp( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) ) );
}

/**
 * Run every reader once and order what they returned.
 *
 * The try/catch here is the second of two: a reader owns its own, and this one
 * catches the reader that throws before it can. Either way the signal gets its
 * one warning row.
 *
 * @return array{rows:array,stamp:string}
 */
function attention_compose() {
	$rows   = array();
	$newest = '';
	foreach ( attention_signals() as $kind => $reader ) {
		$read = null;
		try {
			$read = is_callable( $reader ) ? call_user_func( $reader ) : null;
		} catch ( \Throwable $e ) {
			$read = null;
		}
		if ( ! is_array( $read ) || ! isset( $read['rows'] ) || ! is_array( $read['rows'] ) || ! empty( $read['unreadable'] ) ) {
			$rows[] = attention_unreadable_row( $kind );
			continue;
		}
		$stamp = attention_stamp( $read['stamp'] ?? '' );
		if ( $stamp > $newest ) {
			$newest = $stamp;
		}
		foreach ( $read['rows'] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = attention_row( $row );
			}
		}
	}
	usort( $rows, __NAMESPACE__ . '\attention_compare' );
	return array(
		'rows'  => array_slice( $rows, 0, (int) SN_OS_APP_ITEM_CAP ),
		'stamp' => $newest,
	);
}

/**
 * The composed queue, from the sixty-second cache when it stands.
 *
 * The age is checked here as well as by the transient's own expiry: the
 * transient answers "is it gone", this answers "how old is what I got", and
 * the second question is the one `read_at` is projected to answer for the
 * reader. A `read_at` in the future is a clock that moved, not a fresh read.
 *
 * @return array{rows:array,read_at:int,stamp:string}
 */
function attention_rows() {
	$now    = attention_now();
	$cached = function_exists( 'get_transient' ) ? get_transient( ATTENTION_CACHE_KEY ) : false;
	if ( is_array( $cached ) && isset( $cached['rows'], $cached['read_at'] ) && is_array( $cached['rows'] ) ) {
		$age = $now - (int) $cached['read_at'];
		if ( $age >= 0 && $age < ATTENTION_TTL ) {
			return $cached;
		}
	}
	$composed = attention_compose();
	$out      = array(
		'rows'    => $composed['rows'],
		'read_at' => $now,
		'stamp'   => $composed['stamp'],
	);
	if ( function_exists( 'set_transient' ) ) {
		set_transient( ATTENTION_CACHE_KEY, $out, ATTENTION_TTL );
	}
	return $out;
}

/**
 * One queue row as the client sees it.
 *
 * @param array<string,mixed> $row A composed row.
 * @return array<string,mixed>
 */
function attention_item( array $row ) {
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
	// find it. Offered only when a section that lists it is offered to them.
	$section = (string) ( $row['section'] ?? '' );
	$post_id = (int) ( $row['post_id'] ?? 0 );
	if ( '' === $section && $post_id > 0 ) {
		$section = attention_section_for_post( $post_id );
	}
	if ( '' !== $section ) {
		$actions[] = array(
			'label'    => $post_id > 0 ? __( 'Open the note', 'signal-and-noise-tools' ) : __( 'Open the section', 'signal-and-noise-tools' ),
			'dispatch' => 'jump',
			'args'     => array( 'section' => $section, 'item' => $post_id > 0 ? (string) $post_id : '' ),
		);
	}
	return array(
		'id'          => 'a-' . $kind . '-' . $key,
		'title'       => (string) ( $row['title'] ?? '' ),
		'subtitle'    => (string) ( $row['subtitle'] ?? '' ),
		'thumbnail'   => '',
		'icon'        => attention_kind_icon( $kind ),
		// The status IS the kind: the pills filter the queue by which signal
		// produced a row, which is the only axis a mixed queue has.
		'status'      => $kind,
		'statusLabel' => $label,
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
			'stamp' => '' !== $stamp ? $stamp . ' UTC' : __( 'not stamped', 'signal-and-noise-tools' ),
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
 * Every row the queue holds, as items.
 *
 * @return array<int,array<string,mixed>>
 */
function attention_items() {
	$items = array();
	foreach ( (array) attention_rows()['rows'] as $row ) {
		if ( is_array( $row ) ) {
			$items[] = attention_item( $row );
		}
	}
	return $items;
}

/**
 * How many rows the queue holds, for the root folder tile. Reads the SAME
 * cache items() reads, so the tile and the list can never disagree.
 *
 * @return int
 */
function attention_count() {
	return count( (array) attention_rows()['rows'] );
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
		/* translators: %s: a UTC timestamp. */
		return sprintf( __( 'No reader carried a stamp. Composed %s UTC.', 'signal-and-noise-tools' ), $read_at );
	}
	/* translators: 1: a UTC timestamp. 2: a UTC timestamp. */
	return sprintf( __( 'The newest reading is from %1$s UTC. Composed %2$s UTC.', 'signal-and-noise-tools' ), $stamp, $read_at );
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$statuses = array();
		foreach ( attention_kinds() as $kind => $label ) {
			$statuses[] = array( 'value' => $kind, 'label' => $label );
		}
		$sections[] = array(
			'id'             => 'attention',
			'label'          => __( 'Attention', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-flag',
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
