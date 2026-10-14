<?php
/**
 * Signal & Noise app, the Attention composition: the composition, its ordering and its cache.
 *
 * A pure move from parts/attention.php (house line cap, 2026-10-14), required at its end in source order.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
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
 * WHY A CACHE: the payload calls `count()` for every section on every root
 * paint (parts/payload.php), and this section's count is the number of rows,
 * so without one the readers would run on every paint of every section. It
 * is not a freshness claim: `read_at` is projected into the empty state and
 * into every row's "as of", so what the reader sees is the age of the
 * reading, not the age of the paint.
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
	if ( function_exists( 'update_option' ) ) {
		update_option( ATTENTION_LAST_OPT, array( 'count' => count( $out['rows'] ), 'read_at' => $now, 'stamp' => $out['stamp'] ), false );
	}
	return $out;
}

/**
 * Is this row about a Page (not a Note)? 19.2.0: such rows file under the
 * Pages pill.
 *
 * @param array<string,mixed> $row A composed row.
 * @return bool
 */
function attention_is_page_row( array $row ) {
	$post_id = (int) ( $row['post_id'] ?? 0 );
	return $post_id > 0 && function_exists( 'get_post_type' ) && 'page' === get_post_type( $post_id );
}

