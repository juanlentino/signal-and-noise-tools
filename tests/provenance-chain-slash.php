<?php
/**
 * The provenance chain survives core's slash contract (2026-10-17).
 *
 * update_post_meta() runs wp_unslash() on its value. sn_prov_record() hashes the
 * payload in memory and then writes the chain, so an UNSLASHED write stripped
 * every backslash from payload.content: the stored payload no longer matched its
 * content_hash, and the integrity sweep and the webhook re-dispatch, which both
 * recompute from the stored payload, would have called the Note tampered.
 *
 * The stubs below behave like core (update_post_meta unslashes, get_post_meta
 * returns what was stored). Every writer is driven with a payload carrying \",
 * \/ and a lone \, and the stored payload must re-hash to the stored hash.
 * The guard at the end fails if any inc/ file writes the chain meta except
 * through sn_prov_put_chain().
 *
 * Run: php tests/provenance-chain-slash.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

function add_action() { return true; }
function add_filter() { return true; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }

$GLOBALS['__meta'] = array();
function get_post_meta( $id, $key, $single = false ) {
	$v = $GLOBALS['__meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
// Core: update_post_meta() -> update_metadata() -> wp_unslash( $meta_value ).
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['__meta'][ (int) $id ][ $key ] = wp_unslash( $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['__meta'][ (int) $id ][ $key ] );
	return true;
}

require SNT_PATH . 'inc/provenance-core.php';
require SNT_PATH . 'inc/provenance-webhook.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { ++$pass; echo "  ok: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; }
}

// Backslash before two quotes, before a slash, and alone.
const SLASH_CONTENT = 'She wrote \\"quoted\\", a path a\\/b, and a lone \\ here.';

function slash_commit( $version, $content = SLASH_CONTENT ) {
	$payload = array(
		'algo'    => 'sn-normalize-v1',
		'content' => $content,
		'title'   => 'T \\ ' . $version,
		'version' => $version,
	);
	return array(
		'version'      => $version,
		'payload'      => $payload,
		'content_hash' => sn_prov_content_hash( sn_prov_canonical_json( $payload ) ),
		'status'       => 'unanchored',
		'committed_at' => '2026-10-17T00:00:00Z',
	);
}

/** Every stored commit's payload re-hashes to its stored content_hash. */
function slash_chain_verifies( $post_id ) {
	$chain = get_post_meta( $post_id, SN_PROV_CHAIN_META, true );
	if ( ! is_array( $chain ) || ! $chain ) {
		return false;
	}
	foreach ( $chain as $c ) {
		if ( sn_prov_content_hash( sn_prov_canonical_json( $c['payload'] ) ) !== $c['content_hash'] ) {
			return false;
		}
	}
	return true;
}

echo "Provenance chain slash round-trip\n\n";

ok( 4 === substr_count( SLASH_CONTENT, '\\' ), 'precondition: the fixture really carries four backslashes' );

sn_prov_append_commit( 1, slash_commit( 1 ) );
ok( slash_chain_verifies( 1 ), 'append: the stored payload re-hashes to the stored content_hash' );
ok( SLASH_CONTENT === sn_prov_get_chain( 1 )[0]['payload']['content'], 'append: payload.content is stored byte-for-byte' );

sn_prov_append_commit( 1, slash_commit( 2 ) );
ok( slash_chain_verifies( 1 ), 'append over an existing chain: the earlier commit survives the rewrite too' );

// Reseed directly (as stored, no write path) so each writer below is judged on its own write.
$GLOBALS['__meta'][1][ SN_PROV_CHAIN_META ] = array( slash_commit( 1 ), slash_commit( 2 ) );
sn_prov_replace_head_commit( 1, slash_commit( 2, SLASH_CONTENT . ' \\ again' ) );
ok( slash_chain_verifies( 1 ) && 2 === count( sn_prov_get_chain( 1 ) ), 'replace head: every stored payload re-hashes' );

// Reseed directly (as stored, no write path) so each writer below is judged on its own write.
$GLOBALS['__meta'][1][ SN_PROV_CHAIN_META ] = array( slash_commit( 1 ), slash_commit( 2 ) );
ok( true === sn_prov_update_commit( 1, 1, array( 'status' => 'confirmed', 'bitcoin_block' => 1 ) ), 'update (webhook confirmation) found the commit' );
ok( slash_chain_verifies( 1 ), 'update (webhook confirmation): every stored payload re-hashes' );
ok( 'confirmed' === sn_prov_get_chain( 1 )[0]['status'], 'update: the merged field landed' );

// Reseed directly (as stored, no write path) so each writer below is judged on its own write.
$GLOBALS['__meta'][1][ SN_PROV_CHAIN_META ] = array( slash_commit( 1 ), slash_commit( 2 ) );
ok( true === sn_prov_set_change_class( 1, 2, 'correction' ), 'change class set' );
ok( slash_chain_verifies( 1 ), 'change class: every stored payload re-hashes' );

$canonical = sn_prov_canonical_json( sn_prov_get_chain( 1 )[0]['payload'] );
ok( false !== strpos( $canonical, '\\\\' ), 'the re-dispatch canonical JSON still carries the escaped backslash' );

echo "\nGuard: no inc/ file writes the chain meta except through sn_prov_put_chain()\n";
$writes  = array();
$iter    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SNT_PATH . 'inc', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	// Code only: a docblock naming update_post_meta() is not a write.
	$src = '';
	foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $t ) {
		$src .= ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) ? ' ' : ( is_array( $t ) ? $t[1] : $t );
	}
	if ( preg_match_all( '/\b(?:update|add)_(?:post_)?meta(?:data)?\s*\([^;]*?(?:SN_PROV_CHAIN_META|[\'"]_sn_prov_chain[\'"])[^;]*;/s', $src, $m ) ) {
		foreach ( $m[0] as $hit ) {
			$writes[] = str_replace( SNT_PATH, '', $file->getPathname() ) . ': ' . preg_replace( '/\s+/', ' ', $hit );
		}
	}
}
$helper_write = 'inc/provenance-core-chain-parts.php: update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, wp_slash( $chain ) );';
ok( array( $helper_write ) === $writes, 'the only chain-meta write is the helper, and it slashes' . ( array( $helper_write ) === $writes ? '' : ': ' . implode( ' | ', $writes ) ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
