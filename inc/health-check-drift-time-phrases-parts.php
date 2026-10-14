<?php
/**
 * Signal & Noise Tools: drift time phrases, why a post was not judged.
 *
 * A partial AI failure used to say only "1 of 12 AI calls failed; those posts
 * were not judged this scan." with no post and no cause. These helpers name
 * each failed post and say why in words, for the skip sentence and for the
 * structured `failed` rows on the check envelope. Loaded by require_once from
 * inc/health-check-drift-time-phrases.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many failed posts the sentence names before "+N more". */
const SN_HEALTH_DRIFT_FAILED_NAMED = 3;
/** How many failed rows the stored envelope keeps (the scan is an option row). */
const SN_HEALTH_DRIFT_FAILED_KEPT = 20;

/**
 * Plain text, with or without WordPress loaded (the check's own tests run bare).
 *
 * @param mixed $text Text that may carry tags.
 * @return string
 */
function sn_health_drift_plain( $text ) {
	return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text ) : strip_tags( (string) $text );
}

/**
 * A provider message made safe to show: no URLs, no key-shaped tokens, 160 chars.
 *
 * @param string $message Raw WP_Error message.
 * @return string
 */
function sn_health_drift_scrub_reason( $message ) {
	$text = trim( sn_health_drift_plain( $message ) );
	$text = (string) preg_replace( '#\bhttps?://\S+#i', '[link]', $text );
	// Anything that looks like a credential: sk-/key- prefixes or a long opaque run.
	$text = (string) preg_replace( '/\b(?:sk|pk|key|api)[-_][A-Za-z0-9_-]{8,}|\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $text );
	$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	return substr( $text, 0, 160 );
}

/**
 * Is a reply that failed json_decode a JSON array cut off mid-answer?
 *
 * The AI client returns a bare string (no stop reason), so read the text: it
 * opened an array or object and never closed it, counting brackets outside
 * string literals.
 *
 * @param string $text Reply with any markdown fence already stripped.
 * @return bool
 */
function sn_health_drift_reply_is_truncated( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text || ( '[' !== $text[0] && '{' !== $text[0] ) ) {
		return false;
	}
	$depth  = 0;
	$in_str = false;
	$len    = strlen( $text );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $text[ $i ];
		if ( $in_str ) {
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c ) {
				$in_str = false;
			}
			continue;
		}
		if ( '"' === $c ) {
			$in_str = true;
		} elseif ( '[' === $c || '{' === $c ) {
			++$depth;
		} elseif ( ']' === $c || '}' === $c ) {
			--$depth;
		}
	}
	return $in_str || $depth > 0;
}

/**
 * The reason, in words, a call for one post did not produce verdicts.
 *
 * @param mixed  $raw  What snt_ai_generate_with_constraints() returned.
 * @param string $text The fence-stripped reply, when $raw was a string.
 * @return string
 */
function sn_health_drift_failure_reason( $raw, $text = '' ) {
	if ( is_wp_error( $raw ) ) {
		$msg = sn_health_drift_scrub_reason( $raw->get_error_message() );
		return '' !== $msg ? 'the provider refused: ' . $msg : 'the provider refused without a message';
	}
	if ( ! is_string( $raw ) ) {
		return 'the provider returned no text';
	}
	if ( sn_health_drift_reply_is_truncated( $text ) ) {
		return 'the answer was cut off at the answer limit';
	}
	return 'the answer was not valid JSON (it may have been cut off)';
}

/**
 * One failed row: the post, its title (or "post #ID") and the reason.
 *
 * @param array  $row    The posts row (ID, post_title).
 * @param string $reason From sn_health_drift_failure_reason().
 * @return array{post_id:int,title:string,reason:string}
 */
function sn_health_drift_failed_row( array $row, $reason ) {
	$id    = (int) ( $row['ID'] ?? 0 );
	$title = trim( sn_health_drift_plain( $row['post_title'] ?? '' ) );
	return array(
		'post_id' => $id,
		'title'   => '' !== $title ? $title : 'post #' . $id,
		'reason'  => (string) $reason,
	);
}

/**
 * Names up to three failed posts with their reasons, then "+N more".
 *
 * @param array $failed Rows from sn_health_drift_failed_row().
 * @param int   $total  How many calls failed (rows may be capped).
 * @return string E.g. `"A" (reason); "B" (reason), +2 more`; '' for no rows.
 */
function sn_health_drift_failed_names( array $failed, $total ) {
	$named = array();
	foreach ( array_slice( $failed, 0, SN_HEALTH_DRIFT_FAILED_NAMED ) as $f ) {
		$named[] = sprintf( '"%s" (%s)', $f['title'], $f['reason'] );
	}
	if ( empty( $named ) ) {
		return '';
	}
	$more = (int) $total - count( $named );
	return implode( '; ', $named ) . ( $more > 0 ? sprintf( ', +%d more', $more ) : '' );
}
