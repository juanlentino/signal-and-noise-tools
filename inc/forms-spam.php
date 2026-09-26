<?php
/**
 * Content screening for AllTerrain Forms entries, on our side of its
 * `alltfo_spam_verdict` filter: runs after the form's own honeypot, time
 * trap, rate limit and blocklist, on this server, with no outside service.
 *
 * A strong signal alone marks spam; weak ones need two. The reason is kept
 * as `snt:<signals>` on the verdict so the entry says why it was caught.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Disposable or throwaway mail domains seen in the contact form's spam. */
const SNT_FS_THROWAWAY_DOMAINS = array( 'emlpro.com', 'emltmp.com', 'mailinator.com', 'yopmail.com', 'guerrillamail.com', 'sharklasers.com', 'tempmail.com', '10minutemail.com', 'dispostable.com', 'trashmail.com' );

/**
 * Flatten posted values to strings, keyed by field id (sub-fields keep
 * their own ids).
 *
 * @param array $values Values keyed by field id.
 * @return array<string,string>
 */
function snt_fs_flatten( array $values ) {
	$out = array();
	foreach ( $values as $id => $v ) {
		if ( is_array( $v ) ) {
			foreach ( snt_fs_flatten( $v ) as $sub => $s ) {
				$out[ $id . '.' . $sub ] = $s;
			}
		} elseif ( is_scalar( $v ) ) {
			$out[ (string) $id ] = trim( (string) $v );
		}
	}
	return $out;
}

/**
 * Field ids whose type or label says they hold a person's name.
 *
 * @param array $schema Form schema.
 * @return string[]
 */
function snt_fs_name_fields( array $schema ) {
	$ids = array();
	foreach ( (array) ( $schema['fields'] ?? array() ) as $f ) {
		if ( 'name' === ( $f['type'] ?? '' ) || 1 === preg_match( '/\bname\b/i', (string) ( $f['label'] ?? '' ) ) ) {
			$ids[] = (string) ( $f['id'] ?? '' );
		}
	}
	return $ids;
}

/**
 * The signals an entry trips. PURE.
 *
 * @param array $values Values keyed by field id.
 * @param array $schema Form schema (for which fields are names).
 * @return array{strong:string[],weak:string[]}
 */
function snt_fs_signals( array $values, array $schema ) {
	$flat   = snt_fs_flatten( $values );
	$names  = snt_fs_name_fields( $schema );
	$strong = array();
	$weak   = array();

	$name_text = '';
	foreach ( $flat as $id => $v ) {
		if ( in_array( strtok( $id, '.' ), $names, true ) ) {
			$name_text .= ' ' . $v;
		}
	}
	$all = implode( ' ', $flat );

	if ( 1 === preg_match( '#https?://|www\.|\b[a-z0-9-]+\.(com|org|net|ru|xyz|top)/#i', $name_text ) ) {
		$strong[] = 'link_in_name';
	}
	if ( 1 === preg_match( '/\b(bitcoin|btc|crypto|usdt|cloud[- ]mining|new message|you have won|#H\d{3,})/i', $all ) ) {
		$strong[] = 'lure';
	}
	// "RobertBiB RonaldBiBGM", "NATREGTEGH475080NEHTYHYHTR": names no person has.
	if ( 1 === preg_match( '/\b[A-Z][a-z]+[A-Z][a-zA-Z]*[A-Z]{2}\b|[A-Z]{5,}\d{3,}[A-Z]{3,}/', $name_text ) ) {
		$strong[] = 'bot_name';
	}
	if ( 1 === preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $name_text ) ) {
		$weak[] = 'emoji_in_name';
	}
	// Short letter-and-digit tokens standing in for answers ("ctheh4r", "l77264").
	$tokens = 0;
	foreach ( $flat as $v ) {
		if ( 1 === preg_match( '/^(?=.*[a-z])(?=.*\d)[a-z0-9]{4,9}$/i', $v ) ) {
			++$tokens;
		}
	}
	if ( $tokens >= 3 ) {
		$weak[] = 'random_answers';
	}
	foreach ( $flat as $v ) {
		if ( 1 === preg_match( '/@([a-z0-9.-]+)$/i', $v, $m ) && in_array( strtolower( $m[1] ), (array) apply_filters( 'snt_forms_spam_throwaway_domains', SNT_FS_THROWAWAY_DOMAINS ), true ) ) {
			$weak[] = 'throwaway_email';
			break;
		}
	}
	return array( 'strong' => $strong, 'weak' => $weak );
}

/**
 * Is it spam, and why? PURE.
 *
 * @param array $signals snt_fs_signals() shape.
 * @return string '' when clean, else 'snt:signal,signal'.
 */
function snt_fs_reason( array $signals ) {
	$hit = $signals['strong'] || count( $signals['weak'] ) >= 2;
	return $hit ? 'snt:' . implode( ',', array_merge( $signals['strong'], $signals['weak'] ) ) : '';
}

add_filter(
	'alltfo_spam_verdict',
	static function ( $verdict, $schema, $values ) {
		if ( ! is_array( $verdict ) || ! empty( $verdict['spam'] ) ) {
			return $verdict;
		}
		$reason = snt_fs_reason( snt_fs_signals( (array) $values, (array) $schema ) );
		return '' === $reason ? $verdict : array( 'spam' => true, 'reason' => $reason );
	},
	10,
	3
);

require_once __DIR__ . '/forms-spam-sweep.php';
