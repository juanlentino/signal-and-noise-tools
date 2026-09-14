<?php
/**
 * A FLAT block parser for standalone tests: real delimiters, real attrs JSON,
 * innerHTML = everything between the delimiters (nested blocks stay inline,
 * innerBlocks stays empty). Copied from tests/abilities-sn-apply-block-edit.php
 * (v13.2.0) so detector suites can drive real markup without WordPress.
 * Every definition is guarded so a harness with its own parser wins.
 */

if ( ! function_exists( 'tf_be_freeform' ) ) {
function tf_be_freeform( $s ) {
	return array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => (string) $s, 'innerContent' => array( (string) $s ) );
}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$content = (string) $content;
		if ( '' === $content ) { return array(); }
		$re = '#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(\s+\{.*?\})?\s+?(/)?-->#s';
		if ( ! preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array( tf_be_freeform( $content ) );
		}
		$out = array(); $pos = 0; $i = 0; $n = count( $m );
		while ( $i < $n ) {
			$mt  = $m[ $i ];
			$off = (int) $mt[0][1];
			$len = strlen( $mt[0][0] );
			if ( $off > $pos ) { $out[] = tf_be_freeform( substr( $content, $pos, $off - $pos ) ); }
			$is_closer = '' !== ( $mt[1][0] ?? '' );
			$is_void   = '' !== ( $mt[4][0] ?? '' );
			$name      = (string) $mt[2][0];
			$full      = false === strpos( $name, '/' ) ? 'core/' . $name : $name;
			$attrs_raw = trim( (string) ( $mt[3][0] ?? '' ) );
			$attrs     = '' !== $attrs_raw ? (array) json_decode( $attrs_raw, true ) : array();
			if ( $is_closer ) { // stray closer: freeform, like core's recovery
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			if ( $is_void ) {
				$out[] = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
				$pos = $off + $len; $i++;
				continue;
			}
			$depth = 1; $j = $i + 1; $close = null;
			while ( $j < $n ) {
				$jt = $m[ $j ];
				if ( '' !== ( $jt[1][0] ?? '' ) ) { $depth--; if ( 0 === $depth ) { $close = $jt; break; } }
				elseif ( '' === ( $jt[4][0] ?? '' ) ) { $depth++; }
				$j++;
			}
			if ( null === $close ) { // unbalanced opener: freeform
				$out[] = tf_be_freeform( substr( $content, $off, $len ) );
				$pos = $off + $len; $i++;
				continue;
			}
			$inner_start = $off + $len;
			$inner       = substr( $content, $inner_start, (int) $close[0][1] - $inner_start );
			$out[]       = array( 'blockName' => $full, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $inner, 'innerContent' => array( $inner ) );
			$pos = (int) $close[0][1] + strlen( $close[0][0] );
			$i   = $j + 1;
		}
		if ( $pos < strlen( $content ) ) { $out[] = tf_be_freeform( substr( $content, $pos ) ); }
		return $out;
	}
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		$outp = '';
		foreach ( (array) $blocks as $b ) {
			$b = (array) $b;
			if ( null === ( $b['blockName'] ?? null ) ) { $outp .= (string) ( $b['innerHTML'] ?? '' ); continue; }
			$name  = (string) $b['blockName'];
			$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
			$attrs = ! empty( $b['attrs'] ) ? ' ' . json_encode( $b['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			if ( empty( $b['innerContent'] ) && '' === (string) ( $b['innerHTML'] ?? '' ) ) {
				$outp .= '<!-- wp:' . $short . $attrs . ' /-->';
			} else {
				$outp .= '<!-- wp:' . $short . $attrs . ' -->' . (string) ( $b['innerHTML'] ?? '' ) . '<!-- /wp:' . $short . ' -->';
			}
		}
		return $outp;
	}
}
if ( ! function_exists( 'serialize_block' ) ) { function serialize_block( $b ) { return serialize_blocks( array( $b ) ); } }
