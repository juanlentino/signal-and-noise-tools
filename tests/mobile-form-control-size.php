<?php
/**
 * Tests: no shipped form control falls below 16px on a phone (issue #1000).
 *
 * WHY 16px: below it, iOS Safari zooms the page into a focused control and does
 * NOT zoom back out. Core handles this in wp-admin/css/forms.css —
 *
 *     @media screen and (max-width: 782px) { textarea, input { font-size: 16px; } }
 *
 * — but that selector is (0,0,1). Five of our rules were (0,1,0) or higher, won,
 * and the bump never applied. The desktop sizes are deliberate house style; only
 * the phone is corrected, at CORE'S breakpoint so the two cannot disagree.
 *
 * RULE-BASED, NOT LINE-BASED. A selector and its declarations sit on different
 * lines, so a grep for both on one line structurally cannot see most rules — it
 * found 1 of the 5. Comments are stripped first for the same class of reason:
 * a comment above a rule bleeds into the captured selector, and the string
 * "textarea" in prose is not a control.
 *
 * Run: php tests/mobile-form-control-size.php
 * @since 13.96.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }


/**
 * Class names this codebase actually puts on a form control, DERIVED.
 *
 * Why this exists: `.sn-rsm-items` is a <textarea> on three admin pages, and a
 * selector-name check cannot see it — the selector never says "textarea". The
 * first version of this suite found 3 of the 4 rules in admin.css for exactly
 * that reason, and the scratch scan that originally found it only did so by
 * accident, because a COMMENT above the rule contained the word "textarea".
 *
 * So the vocabulary is derived from the markup rather than from the selector:
 * PHP tags with a class attribute, and JS elements created as a control and
 * then given a className.
 *
 * STATED PLAINLY: this covers a class written literally onto a control. It does
 * NOT cover a class composed at runtime from variables, nor one added by a
 * third party. The guard is a floor, not a proof.
 *
 * @param string $root Plugin root.
 * @return array<string,true> Class names, as a set.
 */
function mfc_control_classes( $root ) {
	$classes = array();
	$files   = array();
	foreach ( array( '/inc', '/assets' ) as $sub ) {
		if ( ! is_dir( $root . $sub ) ) { continue; }
		$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $sub, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $walk as $f ) {
			if ( ! $f->isFile() ) { continue; }
			$ext = strtolower( $f->getExtension() );
			if ( 'php' === $ext || 'js' === $ext ) { $files[] = (string) $f->getPathname(); }
		}
	}
	foreach ( $files as $file ) {
		$src = (string) file_get_contents( $file );

		// PHP/HTML: <textarea ... class="a b"> — the tag names the control.
		if ( preg_match_all( '/<(textarea|input|select)\b[^>]*?class=["\']([^"\']+)["\']/i', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				foreach ( preg_split( '/\s+/', $hit[2] ) as $c ) {
					$c = trim( $c );
					if ( '' !== $c && false === strpos( $c, '$' ) ) { $classes[ '.' . $c ] = true; }
				}
			}
		}

		// JS: createElement('textarea') into a variable, then <var>.className = '…'.
		if ( preg_match_all( '/(?:const|let|var)?\s*([A-Za-z_$][\w$]*)\s*=\s*document\.createElement\(\s*["\'](textarea|input|select)["\']/i', $src, $cm, PREG_SET_ORDER ) ) {
			foreach ( $cm as $made ) {
				$var = preg_quote( $made[1], '/' );
				if ( preg_match_all( '/' . $var . '\.className\s*=\s*["\']([^"\']+)["\']/', $src, $nm ) ) {
					foreach ( $nm[1] as $cn ) {
						foreach ( preg_split( '/\s+/', $cn ) as $c ) {
							$c = trim( $c );
							if ( '' !== $c ) { $classes[ '.' . $c ] = true; }
						}
					}
				}
			}
		}
	}
	return $classes;
}

/** CSS with /* … *\/ comments removed. */
function mfc_strip( $css ) { return preg_replace( '#/\*.*?\*/#s', '', (string) $css ); }

/** Inner text of every @media block whose condition covers a phone. */
function mfc_mobile_blocks( $css ) {
	$out = array();
	$len = strlen( $css );
	$off = 0;
	while ( false !== ( $at = strpos( $css, '@media', $off ) ) ) {
		$brace = strpos( $css, '{', $at );
		if ( false === $brace ) { break; }
		$cond = substr( $css, $at, $brace - $at );
		$depth = 0;
		for ( $i = $brace; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) { $depth++; }
			elseif ( '}' === $css[ $i ] ) { $depth--; if ( 0 === $depth ) { break; } }
		}
		$inner = substr( $css, $brace + 1, $i - $brace - 1 );
		// A block covers the phone when it caps width at 782px or below.
		if ( preg_match( '/max-width\s*:\s*([0-9.]+)px/i', $cond, $m ) && (float) $m[1] <= 782 ) {
			$out[] = $inner;
		}
		$off = $i + 1;
	}
	return $out;
}

/** [selector => smallest font-size] for rules naming a form control. */
function mfc_control_rules( $css, array $classes = array() ) {
	$found = array();
	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) { return $found; }
	foreach ( $m as $rule ) {
		$sel = trim( preg_replace( '/\s+/', ' ', $rule[1] ) );
		if ( '' === $sel || '@' === $sel[0] ) { continue; }
		$is_control = (bool) preg_match( '/\b(input|select|textarea)\b/i', $sel );
		if ( ! $is_control ) {
			foreach ( $classes as $cls => $_ ) {
				if ( false !== strpos( $sel, $cls ) ) { $is_control = true; break; }
			}
		}
		if ( ! $is_control ) { continue; }
		if ( ! preg_match( '/font-size\s*:\s*([0-9.]+)px/i', $rule[2], $fs ) ) { continue; }
		$found[ $sel ] = (float) $fs[1];
	}
	return $found;
}

$root  = dirname( __DIR__ );
$files = array();
$walk  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/assets', FilesystemIterator::SKIP_DOTS ) );
foreach ( $walk as $f ) {
	if ( $f->isFile() && 'css' === strtolower( $f->getExtension() ) ) { $files[] = (string) $f->getPathname(); }
}
sort( $files );

$classes = mfc_control_classes( $root );

echo "mobile-form-control-size — plugin v13.96.2\n\nGroup 1: the scan is not vacuous\n";
ok( count( $files ) > 5, 'stylesheets found (' . count( $files ) . ') — assets/ is walked at any depth' );
ok( count( $classes ) > 5, 'control classes DERIVED from markup (' . count( $classes ) . ') — an empty vocabulary would make the class half vacuous' );
ok( isset( $classes['.sn-rsm-items'] ),
	'.sn-rsm-items is known to be a control — it is a <textarea> whose SELECTOR never says so, and the selector-name check finds 3 of 4 without this' );

$probe = mfc_control_rules( mfc_strip( ".x textarea {\n  color: red;\n  font-size: 12px;\n}" ) );
ok( 12.0 === ( $probe['.x textarea'] ?? null ), 'a rule split across lines IS detected — a line-scoped search finds 1 of 5' );
$probe2 = mfc_control_rules( mfc_strip( "/* a note about a textarea at 12px */\n.y { font-size: 12px; }" ) );
ok( array() === $probe2, 'a control NAMED ONLY IN A COMMENT is not a rule' );
ok( count( mfc_mobile_blocks( '@media screen and (max-width: 782px){ a{font-size:16px} }' ) ) === 1, 'a 782px media block is recognised' );
ok( count( mfc_mobile_blocks( '@media screen and (min-width: 1200px){ a{font-size:12px} }' ) ) === 0, 'a desktop-only media block is NOT treated as the phone' );

echo "\nGroup 2: every sub-16px control is bumped on a phone\n";
$offenders = array();
foreach ( $files as $file ) {
	$css    = mfc_strip( (string) file_get_contents( $file ) );
	$mobile = implode( "\n", mfc_mobile_blocks( $css ) );
	$bumped = array();
	foreach ( mfc_control_rules( $mobile, $classes ) as $sel => $size ) {
		if ( $size >= 16 ) { foreach ( explode( ',', $sel ) as $one ) { $bumped[ trim( $one ) ] = true; } }
	}
	foreach ( mfc_control_rules( $css, $classes ) as $sel => $size ) {
		if ( $size >= 16 ) { continue; }
		foreach ( explode( ',', $sel ) as $one ) {
			$one = trim( $one );
			if ( ! isset( $bumped[ $one ] ) ) {
				$offenders[] = str_replace( $root . '/', '', $file ) . '  ' . $one . ' @ ' . $size . 'px';
			}
		}
	}
}
ok( array() === $offenders,
	'no control is left below 16px on a phone' . ( $offenders ? " —\n    " . implode( "\n    ", $offenders ) : ' (' . count( $files ) . ' stylesheets)' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
