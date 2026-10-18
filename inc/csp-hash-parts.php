<?php
/**
 * Signal & Noise Tools: the pure half of the CSP inline-hash check.
 *
 * Hashing, script extraction, header parsing and the replacement line, with
 * no WordPress state, so the test runs them directly. The method is the
 * theme's docs/CSP.md: each hash is sha256 over exactly the bytes between the
 * opening tag's ">" and "</script>", base64.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSP hash source of a script body, without quotes.
 *
 * @param string $body Raw script text.
 * @return string "sha256-<base64>".
 */
function sn_csp_hash( $body ) {
	return 'sha256-' . base64_encode( hash( 'sha256', (string) $body, true ) );
}

/**
 * Every executable inline script on a page, in page order. A script with src
 * is not inline; a type that is not JavaScript, a module, an import map or
 * speculation rules is a data block (JSON islands) and runs nothing.
 * Speculation rules are hashed too: live on 2026-10-18, Chromium 152 still
 * reported them under a policy carrying 'inline-speculation-rules' and named
 * their hash, so the strict policy pins it like the others.
 *
 * @param string $html Page HTML.
 * @return array<int,array{label:string,hash:string}>
 */
function sn_csp_inline_scripts( $html ) {
	$out = array();
	$p   = new WP_HTML_Tag_Processor( (string) $html );
	while ( $p->next_tag( 'SCRIPT' ) ) {
		if ( null !== $p->get_attribute( 'src' ) ) {
			continue;
		}
		$type = $p->get_attribute( 'type' );
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		if ( '' !== $type && 'module' !== $type && 'importmap' !== $type && 'speculationrules' !== $type && ! preg_match( '#^(text|application)/(x-)?(java|ecma)script$#', $type ) ) {
			continue;
		}
		$label = 'other';
		if ( 'sn-theme-init' === $p->get_attribute( 'id' ) ) {
			$label = 'theme-init';
		} elseif ( 'importmap' === $type || 'module' === $type ) {
			$label = $type;
		} elseif ( 'speculationrules' === $type ) {
			$label = 'speculation';
		}
		$out[] = array( 'label' => $label, 'hash' => sn_csp_hash( (string) $p->get_modifiable_text() ) );
	}
	return $out;
}

/**
 * The script-src sources of one policy, or null when it has none.
 *
 * @param string $policy One CSP header value.
 * @return string[]|null
 */
function sn_csp_script_src( $policy ) {
	foreach ( explode( ';', (string) $policy ) as $directive ) {
		$tokens = preg_split( '/\s+/', trim( $directive ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $tokens && 'script-src' === strtolower( $tokens[0] ) ) {
			return array_slice( $tokens, 1 );
		}
	}
	return null;
}

/**
 * The strict policy among a response's headers: the one whose script-src
 * lacks 'unsafe-inline'. Enforced wins over Report-Only.
 *
 * @param array $headers Lowercase name => value or values.
 * @return array{mode:string,sources:string[],csp_seen:bool}
 */
function sn_csp_strict( array $headers ) {
	$seen = false;
	foreach ( array( 'enforced' => 'content-security-policy', 'report-only' => 'content-security-policy-report-only' ) as $mode => $name ) {
		if ( ! isset( $headers[ $name ] ) ) {
			continue;
		}
		$seen = true;
		// Repeated headers arrive as an array; one header may carry several policies, comma-separated.
		foreach ( explode( ',', implode( ',', (array) $headers[ $name ] ) ) as $policy ) {
			$src = sn_csp_script_src( $policy );
			if ( null !== $src && ! in_array( "'unsafe-inline'", array_map( 'strtolower', $src ), true ) ) {
				return array( 'mode' => $mode, 'sources' => $src, 'csp_seen' => true );
			}
		}
	}
	return array( 'mode' => 'none', 'sources' => array(), 'csp_seen' => $seen );
}

/**
 * Whether a source token is a sha256 hash; returns it unquoted, or ''.
 *
 * @param string $token Source expression.
 * @return string
 */
function sn_csp_hash_token( $token ) {
	return preg_match( "/^'(sha256-[A-Za-z0-9+\/=_-]+)'$/", (string) $token, $m ) ? $m[1] : '';
}

/**
 * Hashes seen on the pages but absent from the strict sources.
 *
 * @param string[] $seen    Unquoted hashes.
 * @param string[] $sources Strict script-src sources.
 * @return string[]
 */
function sn_csp_missing( array $seen, array $sources ) {
	return array_values( array_diff( array_unique( $seen ), array_map( 'sn_csp_hash_token', $sources ) ) );
}

/**
 * The script-src line to paste: the strict sources in place, with the hash
 * block replaced by the hashes seen (kept ones first, then the fresh ones).
 * With no hash yet, the block goes after 'self'.
 *
 * @param string[] $sources Strict script-src sources.
 * @param string[] $seen    Unquoted hashes seen this run.
 * @return string
 */
function sn_csp_replacement_line( array $sources, array $seen ) {
	$seen   = array_values( array_unique( $seen ) );
	$kept   = array();
	$rest   = array();
	$insert = null;
	foreach ( $sources as $token ) {
		$h = sn_csp_hash_token( $token );
		if ( '' === $h ) {
			$rest[] = $token;
			continue;
		}
		if ( null === $insert ) {
			$insert = count( $rest );
		}
		if ( in_array( $h, $seen, true ) ) {
			$kept[] = $h;
		}
	}
	if ( null === $insert ) {
		$self   = array_search( "'self'", $rest, true );
		$insert = false === $self ? 0 : $self + 1;
	}
	$block = array_map(
		static function ( $h ) {
			return "'" . $h . "'";
		},
		array_merge( $kept, array_values( array_diff( $seen, $kept ) ) )
	);
	array_splice( $rest, $insert, 0, $block );
	return 'script-src ' . implode( ' ', $rest );
}
