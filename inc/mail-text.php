<?php
/**
 * Signal & Noise Tools: text helpers the plugin's emails share. PURE.
 *
 * Two jobs. Join sentences so a part that already ends in a stop never gets a
 * second one ("click Request indexing.. Read it"). And keep a long URL out of
 * the middle of a sentence: in plain text it becomes a numbered marker with the
 * URL on its own short line; in HTML it becomes a link labeled by its host.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A URL, minus trailing punctuation that belongs to the sentence. */
const SN_MAIL_URL_RE = '~https?://[^\s<>"]+?(?=[.,;:!?)\]]*(?:\s|$))~i';

/**
 * One sentence with exactly one terminal mark. A doubled stop collapses; a
 * real ellipsis stays.
 *
 * @param string $s Sentence, with or without its stop.
 * @return string '' for a blank part.
 */
function sn_mail_sentence( $s ) {
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return '';
	}
	$s = (string) preg_replace( '/(?<!\.)\.\.$/', '.', $s );
	return preg_match( '/[.!?]["\'\x{201D})]*$/u', $s ) ? $s : $s . '.';
}

/**
 * Sentences joined by one space, blanks dropped.
 *
 * @param array<int,string> $parts Sentences.
 * @return string
 */
function sn_mail_sentences( array $parts ) {
	return implode( ' ', array_filter( array_map( 'sn_mail_sentence', $parts ), 'strlen' ) );
}

/**
 * Plain text with each URL replaced by [n] and listed on its own line below.
 *
 * @param string $text Text that may carry URLs.
 * @return string
 */
function sn_mail_text_links( $text ) {
	$urls = array();
	$out  = (string) preg_replace_callback(
		SN_MAIL_URL_RE,
		static function ( $m ) use ( &$urls ) {
			$urls[] = $m[0];
			return '[' . count( $urls ) . ']';
		},
		(string) $text
	);
	foreach ( $urls as $i => $url ) {
		$out .= "\n  [" . ( $i + 1 ) . '] ' . $url;
	}
	return $out;
}

/**
 * Escaped HTML with each URL as a link labeled by its host.
 *
 * @param string $text Text that may carry URLs.
 * @return string
 */
function sn_mail_html_links( $text ) {
	$text = (string) $text;
	$out  = '';
	$at   = 0;
	preg_match_all( SN_MAIL_URL_RE, $text, $found, PREG_OFFSET_CAPTURE );
	foreach ( $found[0] as $m ) {
		$host = (string) parse_url( $m[0], PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no core here.
		$out .= esc_html( substr( $text, $at, $m[1] - $at ) ) . '<a href="' . esc_url( $m[0] ) . '">' . esc_html( '' !== $host ? $host : $m[0] ) . '</a>';
		$at   = $m[1] + strlen( $m[0] );
	}
	return $out . esc_html( substr( $text, $at ) );
}
