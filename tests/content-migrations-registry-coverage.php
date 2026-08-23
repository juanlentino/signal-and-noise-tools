<?php
/**
 * Every callback in sn_content_migrations_registry() resolves to a function that
 * exists, exactly once, somewhere under inc/.
 *
 * WHY THIS EXISTS. `inc/content-migrations.php` is 1,442 lines and slated to be
 * split into per-domain files. The registry binds a callback NAME to a sentinel
 * option and does not care which file the function lives in — which is what
 * makes the split safe, and what makes a DROPPED function invisible.
 *
 * And here the drop is worse than a fatal. sn_run_content_migrations() calls
 * each entry behind a guard:
 *
 *     if ( ! get_option( $flag ) && function_exists( $callback ) ) { $callback(); }
 *
 * so a migration that goes missing does NOT crash. It is silently skipped, its
 * flag is never stamped, `$complete` stays false, and the master sentinel
 * SN_CONTENT_MIGRATIONS_MASTER_OPT is withheld forever — the runner re-enters on
 * every admin_init, does nothing, and reports nothing. There is no error to
 * notice. This suite is the only thing that would.
 *
 * Contrast the admin-post split (v12.21.1/v12.21.2): a lost handler there fatals
 * at click time. Loud. This one is silent, which is why the guard matters more.
 *
 * Written BEFORE the refactor deliberately: a guard added afterwards proves the
 * end state, not the move. It walks inc/ recursively, so it keeps working once
 * the migrations live in inc/content-migrations/*.php.
 *
 * Run: php tests/content-migrations-registry-coverage.php
 *
 * @since 12.21.3
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$root = realpath( __DIR__ . '/..' );
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

// Read as SOURCE rather than loading it: the migrations pull in a large
// dependency tree, and this suite is about names, not behaviour.
$mig_src = (string) file_get_contents( "$root/inc/content-migrations.php" );

// Isolate the registry body first — a bare regex over the whole file would also
// match unrelated arrays.
if ( ! preg_match( '/function\s+sn_content_migrations_registry\s*\(\s*\)\s*\{(.*?)\n\}/s', $mig_src, $rm ) ) {
	echo "  FAIL: could not locate sn_content_migrations_registry() body\n";
	echo "\n0 passed, 1 failed\n";
	exit( 1 );
}
preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*(?:SN_[A-Z0-9_]+|'[a-z0-9_]+')/", $rm[1], $m );
$callbacks = (array) $m[1];
ok( count( $callbacks ) > 20, 'registry parsed (' . count( $callbacks ) . ' entries)' );

// Collect every function declaration across inc/, so the guard keeps working
// once the file is split into inc/content-migrations/*.php.
$declared = array();
$dir = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc" ) );
foreach ( $dir as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$rel = str_replace( "$root/", '', $file->getPathname() );
	preg_match_all( '/^function\s+([A-Za-z0-9_]+)\s*\(/m', (string) file_get_contents( $file->getPathname() ), $fm );
	foreach ( (array) $fm[1] as $fn ) {
		$declared[ $fn ][] = $rel;
	}
}
ok( count( $declared ) > 0, 'found function declarations under inc/ (' . count( $declared ) . ')' );

// 1. Every registered migration resolves. A miss here is the silent-skip bug.
foreach ( $callbacks as $fn ) {
	ok( isset( $declared[ $fn ] ), "registry callback $fn() is declared" );
}

// 2. None is declared TWICE. A split done by copy rather than cut leaves a
//    duplicate and PHP fatals with "Cannot redeclare" at load.
foreach ( $callbacks as $fn ) {
	if ( isset( $declared[ $fn ] ) ) {
		ok( 1 === count( $declared[ $fn ] ), "$fn() declared exactly once (in " . implode( ', ', $declared[ $fn ] ) . ')' );
	}
}

// 3. The master runner itself, and the spine that drives it, survive the split.
ok( isset( $declared['sn_run_content_migrations'] ) && 1 === count( $declared['sn_run_content_migrations'] ),
	'sn_run_content_migrations() declared exactly once' );
ok( isset( $declared['sn_content_migrations_registry'] ) && 1 === count( $declared['sn_content_migrations_registry'] ),
	'sn_content_migrations_registry() declared exactly once' );

// 4. The top-level const and the admin_init registration must each appear
//    exactly once across inc/. A duplicated const keeps the FIRST value and does
//    not warn under some configurations; a duplicated add_action would run the
//    master runner twice per request.
$const_hits = 0; $hook_hits = 0;
$dir2 = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc" ) );
foreach ( $dir2 as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	$const_hits += preg_match_all( '/^const\s+SN_CONTENT_MIGRATIONS_MASTER_OPT\s*=/m', $src );
	$hook_hits  += preg_match_all( "/add_action\(\s*'admin_init'\s*,\s*'sn_run_content_migrations'\s*\)/", $src );
}
ok( 1 === $const_hits, "SN_CONTENT_MIGRATIONS_MASTER_OPT declared exactly once (found $const_hits)" );
ok( 1 === $hook_hits, "sn_run_content_migrations hooked to admin_init exactly once (found $hook_hits)" );

// 5. Every seed-content reference in the layer RESOLVES to a file on disk.
//
//    This is the silent-empty class. The body loaders build their path and then:
//        return file_exists( $body_file ) ? file_get_contents( $body_file ) : '';
//    so a path that does not resolve raises nothing — the loader just returns an
//    empty string and the migration seeds a blank page body.
//
//    A bare __DIR__ is position-DEPENDENT: it changes the moment a loader moves
//    from inc/ into inc/content-migrations/. That is exactly what happened during
//    the v12.21.3 split, and a full 497-suite sweep stayed green through it,
//    because no suite asserted that a loader returns content. Resolving each
//    reference against its OWN file's directory is what makes the check honest.
$layer = array_merge(
	array( "$root/inc/content-migrations.php" ),
	glob( "$root/inc/content-migrations/*.php" ) ?: array()
);
$seed_refs = 0;
foreach ( $layer as $lf ) {
	$src = (string) file_get_contents( $lf );
	$rel = str_replace( "$root/", '', $lf );

	// Form A: a bare __DIR__ with a literal name — position-dependent, resolve
	// against THIS file's directory, which is what PHP would do at runtime.
	if ( preg_match_all( "/__DIR__ \. '\/seed-content\/([A-Za-z0-9._-]+)'/", $src, $am ) ) {
		foreach ( $am[1] as $name ) {
			$seed_refs++;
			ok( file_exists( dirname( $lf ) . "/seed-content/$name" ),
				"$rel: __DIR__ seed '$name' resolves from its own directory" );
		}
	}

	// Form B: the helper — always resolves from inc/, which is the point.
	if ( preg_match_all( "/sn_content_seed_file\(\s*'([A-Za-z0-9._-]+)'\s*\)/", $src, $bm ) ) {
		foreach ( $bm[1] as $name ) {
			$seed_refs++;
			ok( file_exists( "$root/inc/seed-content/$name" ),
				"$rel: seed '$name' exists in inc/seed-content/" );
		}
	}
}
ok( $seed_refs > 10, "seed references found and resolved ($seed_refs)" );

// 6. The helper is the ONLY place that builds a seed path from __DIR__. A loader
//    that reintroduces a bare __DIR__ is a position-dependent regression waiting
//    for its next move.
$helper_hits = 0; $bare_hits = 0;
foreach ( $layer as $lf ) {
	$src = (string) file_get_contents( $lf );
	$helper_hits += preg_match_all( "/function\s+sn_content_seed_file\s*\(/", $src );
	$bare_hits   += preg_match_all( "/__DIR__ \. '\/seed-content\/[A-Za-z0-9._-]+'/", $src );
}
ok( 1 === $helper_hits, "sn_content_seed_file() declared exactly once (found $helper_hits)" );
ok( 0 === $bare_hits, "no loader builds a seed path from a bare __DIR__ (found $bare_hits)" );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
