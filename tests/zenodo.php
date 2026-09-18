<?php
/**
 * inc/zenodo-client.php + inc/zenodo-records.php + inc/abilities-zenodo.php
 * + inc/health-check-zenodo-doi.php (15.11.0): the record's metadata (pure),
 * the client's request shaping over a fake transport, the deposit flow
 * (create → upload ×3 → metadata → publish, and the resume path), the
 * flow-back accessor, the status shape, and the health check's tiers.
 *
 * Run: php tests/zenodo.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

// ── WP stubs ────────────────────────────────────────────────────────────────
$GLOBALS['__z'] = array( 'options' => array(), 'meta' => array(), 'posts' => array(), 'http' => array(), 'log' => array(), 'chain' => array(), 'cred' => array(), 'scheduled' => array() );
function add_action( $t, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__z']['actions'][ $t ][] = $cb; return true; }
function add_filter() { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__z']['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__z']['options'][ $k ] = $v; return true; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__z']['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__z']['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['__z']['meta'][ $id ][ $k ] ); return true; }
function get_post( $id = null ) { return $GLOBALS['__z']['posts'][ (int) $id ] ?? null; }
function get_posts( $args ) { $out = array(); foreach ( $GLOBALS['__z']['posts'] as $p ) { if ( $p->post_type === $args['post_type'] && 'publish' === $p->post_status ) { $out[] = $p->ID; } } return $out; }
function get_the_title( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? $p->post_title : ''; }
function get_permalink( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? 'https://juanlentino.com/notes/' . $p->post_name . '/' : ''; }
function get_post_time( $f, $gmt, $p ) { return '2026-07-25'; }
function wp_get_post_tags( $id, $a = array() ) { return array( 'C2PA', 'Music Rights' ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function home_url( $p = '/' ) { return 'https://juanlentino.com' . $p; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'boom'; } }
function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['code'] ?? 0 ); }
function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
function wp_remote_request( $url, $args ) { $GLOBALS['__z']['log'][] = array( 'url' => $url, 'method' => $args['method'], 'headers' => $args['headers'], 'body' => $args['body'] ?? null, 'redirection' => $args['redirection'] ); $k = $args['method'] . ' ' . preg_replace( '/\?.*$/', '', $url ); return $GLOBALS['__z']['http'][ $k ] ?? array( 'code' => 404, 'body' => '{"message":"not found"}' ); }
function wp_safe_remote_get( $url, $args ) { return $GLOBALS['__z']['http'][ 'GET ' . $url ] ?? array( 'code' => 404, 'body' => '' ); }
function wp_next_scheduled( $h, $a = array() ) { return $GLOBALS['__z']['scheduled'][ $h ] ?? false; }
function wp_schedule_single_event( $t, $h, $a = array() ) { $GLOBALS['__z']['scheduled'][ $h ] = $t; return true; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__z']['scheduled'][ $h ] = $t; return true; }
function sn_credential( $id ) { return $GLOBALS['__z']['cred'][ $id ] ?? ''; }
function sn_prov_get_chain( $id ) { return $GLOBALS['__z']['chain'][ $id ] ?? array(); }
function sn_prov_subject_kind( $p ) { return $p && 'post' === $p->post_type ? 'note' : ( $p && ! empty( $GLOBALS['__z']['meta'][ $p->ID ]['_sn_prov_sign'] ) ? 'page' : '' ); }
function sn_prov_note_uid( $id ) { return 'uid-' . $id; }
function sn_prov_ledger_dir( $k ) { return 'note' === $k ? 'notes' : 'pages'; }
function sn_prov_verify_endpoints() { return array( 'ledger_base' => 'https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/' ); }
function sn_seo_resolve_singular_description( $p ) { return 'Two questions, one word.'; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null ); }
function admin_url( $p ) { return 'https://juanlentino.com/wp-admin/' . $p; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__z']['abilities'][ $slug ] = $args; return true; }

require __DIR__ . '/../inc/zenodo-client.php';
require __DIR__ . '/../inc/zenodo-records.php';
require __DIR__ . '/../inc/abilities-zenodo.php';
require __DIR__ . '/../inc/health-check-zenodo-doi.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function mkpost( $id, $slug, $title, $type = 'post' ) { $GLOBALS['__z']['posts'][ $id ] = (object) array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $title, 'post_type' => $type, 'post_status' => 'publish', 'post_excerpt' => '' ); }

echo "zenodo — 15.11.0\n\nGroup A: the record's metadata (pure)\n";
$m = sn_zenodo_metadata_for( array( 'title' => 'Two kinds of provenance', 'description' => 'Two questions.', 'url' => 'https://juanlentino.com/notes/two-kinds-of-provenance/', 'date' => '2026-07-25', 'keywords' => array( 'C2PA', 'Music Rights' ), 'version' => 3, 'pillar_url' => 'https://juanlentino.com/provenance/', 'pubkey_id' => 'sn-ed25519-2026-07', 'bitcoin_block' => 957333, 'verify_url' => 'https://juanlentino.com/verify' ) );
ok( 'publication' === $m['upload_type'] && 'other' === $m['publication_type'], 'upload_type publication, publication_type other (notes and pillars are not preprints)' );
ok( 'cc-by-nd-4.0' === $m['license'] && 'open' === $m['access_right'], 'CC BY-ND 4.0, open (the owner\'s decision, 2026-09-17)' );
ok( array( array( 'name' => 'Lentino, Juan', 'orcid' => '0009-0006-8151-5920' ) ) === $m['creators'], 'one creator, surname first, with the ORCID' );
ok( 'v3' === $m['version'] && 'eng' === $m['language'] && '2026-07-25' === $m['publication_date'], 'version from the provenance version; language eng; the publish date' );
ok( array( 'C2PA', 'Music Rights' ) === $m['keywords'], 'keywords are the tag names' );
ok( 2 === count( $m['related_identifiers'] ) && 'isIdenticalTo' === $m['related_identifiers'][0]['relation'] && 'isPartOf' === $m['related_identifiers'][1]['relation'] && 'https://juanlentino.com/provenance/' === $m['related_identifiers'][1]['identifier'], 'related: the canonical URL isIdenticalTo, the pillar isPartOf' );
ok( false !== strpos( $m['notes'], 'sn-ed25519-2026-07' ) && false !== strpos( $m['notes'], 'block 957333' ) && false !== strpos( $m['notes'], 'juanlentino.com/verify' ), 'the notes line names the key, the block and the verifier' );
$m2 = sn_zenodo_metadata_for( array( 'title' => 'Bare', 'url' => '', 'keywords' => array(), 'pillar_url' => '' ) );
ok( ! isset( $m2['related_identifiers'] ) && ! isset( $m2['keywords'] ) && 'Bare' === $m2['description'] && 'v1' === $m2['version'], 'no URL and no tags: no related list, no keywords key, description falls back to the title, version v1' );
ok( false !== strpos( $m2['notes'], 'key unknown' ) && false === strpos( $m2['notes'], 'block' ), 'no key and no block: the notes line says so and names no block' );

echo "\nGroup A2: the ledger's concept DOI (16.1.1)\n";
ok( '10.5281/zenodo.1234567' === sn_zenodo_normalize_doi( ' https://doi.org/10.5281/zenodo.1234567 ' ) && '10.5281/zenodo.1234567' === sn_zenodo_normalize_doi( 'doi:10.5281/zenodo.1234567' ), 'a pasted doi.org URL or doi: prefix normalizes to the bare DOI' );
ok( '' === sn_zenodo_normalize_doi( 'zenodo.1234567' ) && '' === sn_zenodo_normalize_doi( '10.5281/' ) && '' === sn_zenodo_normalize_doi( '' ), 'not a DOI: empty, never stored' );
ok( '' === sn_zenodo_ledger_doi(), 'unset: no ledger DOI' );
update_option( SN_ZENODO_LEDGER_DOI_OPT, 'https://doi.org/10.5281/zenodo.7654321' );
ok( '10.5281/zenodo.7654321' === sn_zenodo_ledger_doi(), 'the accessor normalizes what was stored' );
$m3 = sn_zenodo_metadata_for( array( 'title' => 'With ledger', 'url' => 'https://juanlentino.com/notes/x/', 'pillar_url' => '', 'ledger_doi' => '10.5281/zenodo.7654321' ) );
ok( 2 === count( $m3['related_identifiers'] ) && array( 'identifier' => '10.5281/zenodo.7654321', 'relation' => 'isPartOf', 'resource_type' => 'dataset' ) === $m3['related_identifiers'][1], 'a ledger DOI rides related_identifiers as isPartOf a dataset' );
$m4 = sn_zenodo_metadata_for( array( 'title' => 'Without', 'url' => '', 'pillar_url' => '', 'ledger_doi' => '' ) );
ok( ! isset( $m4['related_identifiers'] ), 'no ledger DOI: no row' );
update_option( SN_ZENODO_LEDGER_DOI_OPT, '' );

echo "\nGroup B: environments, tokens, sandbox DOIs\n";
ok( 'https://sandbox.zenodo.org/api' === sn_zenodo_api_base( 'sandbox' ) && 'https://zenodo.org/api' === sn_zenodo_api_base( 'production' ), 'two bases' );
ok( 'sandbox' === sn_zenodo_env(), 'the environment defaults to sandbox' );
update_option( SN_ZENODO_ENV_OPT, 'production' );
ok( 'production' === sn_zenodo_env() && 'zenodo_token' === sn_zenodo_token_id( 'production' ) && 'zenodo_sandbox_token' === sn_zenodo_token_id( 'sandbox' ), 'production flips the base and the token row' );
update_option( SN_ZENODO_ENV_OPT, 'nonsense' );
ok( 'sandbox' === sn_zenodo_env(), 'an unknown value is sandbox (fail safe)' );
ok( true === sn_zenodo_doi_is_sandbox( '10.5072/zenodo.42' ) && false === sn_zenodo_doi_is_sandbox( '10.5281/zenodo.42' ), '10.5072 is the sandbox prefix' );
ok( false === sn_zenodo_is_enabled(), 'no token: not enabled' );

echo "\nGroup C: the client shapes a bearer request and never throws\n";
$GLOBALS['__z']['cred']['zenodo_sandbox_token'] = 'sbx-secret';
$r = sn_zenodo_create_deposition( 'sandbox' );
$last = end( $GLOBALS['__z']['log'] );
ok( 'POST' === $last['method'] && 'https://sandbox.zenodo.org/api/deposit/depositions' === $last['url'] && 'Bearer sbx-secret' === $last['headers']['Authorization'] && 0 === $last['redirection'] && '{}' === $last['body'], 'create: POST, bearer, no redirects, an empty JSON OBJECT (`[]` drew a bare 500 from Zenodo on 2026-09-18)' );
ok( false === $r['ok'] && 404 === $r['code'] && 'not found' === $r['error'], 'a 404 comes back as {ok:false, code, error}, no exception' );
ok( false === sn_zenodo_request( 'GET', 'https://zenodo.org/api/x', null, 'production' )['ok'] && 'no-token' === sn_zenodo_request( 'GET', 'https://zenodo.org/api/x', null, 'production' )['error'], 'no token for the environment: refused locally, no request made' );
sn_zenodo_upload_file( 'https://sandbox.zenodo.org/api/files/bucket-1', 'note.md', "# hi", 'sandbox', 'text/markdown' );
$last = end( $GLOBALS['__z']['log'] );
ok( 'PUT' === $last['method'] && 'https://sandbox.zenodo.org/api/files/bucket-1/note.md' === $last['url'] && "# hi" === $last['body'] && 'application/octet-stream' === $last['headers']['Content-Type'], 'upload: PUT raw bytes into the bucket under the filename as application/octet-stream, whatever type the caller names (the bucket refuses every other type, measured 2026-09-18)' );

echo "\nGroup D: readiness\n";
mkpost( 7, 'two-kinds-of-provenance', 'Two kinds of provenance' );
ok( 'no-commit' === sn_zenodo_readiness( 7 )['reason'], 'a subject without a chain is not ready (no-commit)' );
$GLOBALS['__z']['chain'][7] = array( array( 'version' => 1, 'status' => 'pending', 'pubkey_id' => 'k' ) );
ok( 'anchor-pending' === sn_zenodo_readiness( 7 )['reason'], 'a pending anchor is not ready' );
$GLOBALS['__z']['chain'][7] = array( array( 'version' => 1, 'status' => 'confirmed', 'pubkey_id' => 'k', 'bitcoin_block' => 957333 ) );
ok( true === sn_zenodo_readiness( 7 )['ready'] && 'note' === sn_zenodo_readiness( 7 )['kind'], 'a confirmed head commit is ready' );
mkpost( 8, 'about', 'About', 'page' );
ok( 'not-a-subject' === sn_zenodo_readiness( 8 )['reason'], 'an unsigned page is not a subject' );
ok( 'not-published' === sn_zenodo_readiness( 99 )['reason'], 'an unknown post is not published' );

echo "\nGroup E: the deposit flow over a fake transport\n";
update_option( SN_ZENODO_ENV_OPT, 'sandbox' );
$GLOBALS['__z']['http']['GET https://juanlentino.com/notes/two-kinds-of-provenance/'] = array( 'code' => 200, 'body' => "---\ntitle: x\n---\nBody" );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-7/v1.json'] = array( 'code' => 200, 'body' => '{"signature":"s"}' );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-7/v1.ots'] = array( 'code' => 200, 'body' => 'OTS' );
$GLOBALS['__z']['http']['POST https://sandbox.zenodo.org/api/deposit/depositions'] = array( 'code' => 201, 'body' => json_encode( array( 'id' => 542201, 'links' => array( 'bucket' => 'https://sandbox.zenodo.org/api/files/b-1' ) ) ) );
$GLOBALS['__z']['http']['PUT https://sandbox.zenodo.org/api/files/b-1/two-kinds-of-provenance.md'] = array( 'code' => 201, 'body' => '{}' );
$GLOBALS['__z']['http']['PUT https://sandbox.zenodo.org/api/files/b-1/two-kinds-of-provenance.provenance.json'] = array( 'code' => 201, 'body' => '{}' );
$GLOBALS['__z']['http']['PUT https://sandbox.zenodo.org/api/files/b-1/two-kinds-of-provenance.ots'] = array( 'code' => 201, 'body' => '{}' );
$GLOBALS['__z']['http']['PUT https://sandbox.zenodo.org/api/deposit/depositions/542201'] = array( 'code' => 200, 'body' => '{}' );
$GLOBALS['__z']['http']['POST https://sandbox.zenodo.org/api/deposit/depositions/542201/actions/publish'] = array( 'code' => 202, 'body' => json_encode( array( 'id' => 542201, 'doi' => '10.5072/zenodo.542201', 'conceptdoi' => '10.5072/zenodo.542200' ) ) );
$GLOBALS['__z']['log'] = array();
$r = sn_zenodo_deposit( 7 );
ok( true === $r['ok'] && 'published' === $r['state'] && '10.5072/zenodo.542201' === $r['doi'], 'the flow publishes and returns the DOI' );
$methods = array_map( static function ( $l ) { return $l['method'] . ' ' . preg_replace( '#^https://sandbox.zenodo.org/api/#', '', $l['url'] ); }, $GLOBALS['__z']['log'] );
ok( array( 'POST deposit/depositions', 'PUT files/b-1/two-kinds-of-provenance.md', 'PUT files/b-1/two-kinds-of-provenance.provenance.json', 'PUT files/b-1/two-kinds-of-provenance.ots', 'PUT deposit/depositions/542201', 'POST deposit/depositions/542201/actions/publish' ) === $methods, 'THE PIN: create → three uploads → metadata → publish, in that order' );
$meta_sent = json_decode( $GLOBALS['__z']['log'][4]['body'], true );
ok( 'Two kinds of provenance' === $meta_sent['metadata']['title'] && 'v1' === $meta_sent['metadata']['version'] && false !== strpos( $meta_sent['metadata']['notes'], 'block 957333' ), 'the metadata PUT carries the built record (title, version, the block)' );
ok( '10.5072/zenodo.542201' === get_post_meta( 7, SN_ZENODO_DOI_META ) && 'sandbox' === get_post_meta( 7, SN_ZENODO_ENV_META ) && '' === get_post_meta( 7, SN_ZENODO_DRAFT_META ) && '' === get_post_meta( 7, SN_ZENODO_ERROR_META ), 'the post carries the DOI and the environment; the draft and error metas are cleared' );
ok( 'already' === sn_zenodo_deposit( 7 )['state'], 'a second call in the same environment is a no-op (already)' );
ok( '' === sn_zenodo_doi_for( 7 ), 'a sandbox DOI never flows back to a public surface' );
// Interrupted flow: the publish fails; the draft id is kept; the next call resumes with GET, not POST.
mkpost( 9, 'the-pen', 'The pen is not the notary' );
$GLOBALS['__z']['chain'][9] = array( array( 'version' => 1, 'status' => 'confirmed', 'pubkey_id' => 'k', 'bitcoin_block' => 1 ) );
$GLOBALS['__z']['http']['GET https://juanlentino.com/notes/the-pen/'] = array( 'code' => 200, 'body' => 'md' );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-9/v1.json'] = array( 'code' => 200, 'body' => '{}' );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-9/v1.ots'] = array( 'code' => 200, 'body' => 'OTS' );
$GLOBALS['__z']['http']['POST https://sandbox.zenodo.org/api/deposit/depositions'] = array( 'code' => 201, 'body' => json_encode( array( 'id' => 777, 'links' => array( 'bucket' => 'https://sandbox.zenodo.org/api/files/b-9' ) ) ) );
foreach ( array( 'the-pen.md', 'the-pen.provenance.json', 'the-pen.ots' ) as $f ) { $GLOBALS['__z']['http'][ 'PUT https://sandbox.zenodo.org/api/files/b-9/' . $f ] = array( 'code' => 201, 'body' => '{}' ); }
$GLOBALS['__z']['http']['PUT https://sandbox.zenodo.org/api/deposit/depositions/777'] = array( 'code' => 200, 'body' => '{}' );
$GLOBALS['__z']['http']['POST https://sandbox.zenodo.org/api/deposit/depositions/777/actions/publish'] = array( 'code' => 500, 'body' => '{"message":"down"}' );
$r = sn_zenodo_deposit( 9 );
ok( false === $r['ok'] && 'publish' === $r['state'] && '777' === get_post_meta( 9, SN_ZENODO_DRAFT_META ) && false !== strpos( get_post_meta( 9, SN_ZENODO_ERROR_META ), 'publish: down' ), 'a failed publish keeps the draft id and records the error' );
$GLOBALS['__z']['http']['GET https://sandbox.zenodo.org/api/deposit/depositions/777'] = array( 'code' => 200, 'body' => json_encode( array( 'id' => 777, 'links' => array( 'bucket' => 'https://sandbox.zenodo.org/api/files/b-9' ) ) ) );
$GLOBALS['__z']['http']['POST https://sandbox.zenodo.org/api/deposit/depositions/777/actions/publish'] = array( 'code' => 202, 'body' => json_encode( array( 'id' => 777, 'doi' => '10.5072/zenodo.777', 'conceptdoi' => '' ) ) );
$GLOBALS['__z']['log'] = array();
$r = sn_zenodo_deposit( 9 );
ok( true === $r['ok'] && 'GET https://sandbox.zenodo.org/api/deposit/depositions/777' === $GLOBALS['__z']['log'][0]['method'] . ' ' . $GLOBALS['__z']['log'][0]['url'], 'the next pass RESUMES the draft with GET; it never mints a second deposition' );
// 16.1.3: a resume read that fails with a 5xx KEEPS the draft id (the next pass tries the same draft again); only a 404 forgets it.
mkpost( 11, 'resume-500', 'Resume 500' );
$GLOBALS['__z']['chain'][11] = array( array( 'version' => 1, 'status' => 'confirmed' ) );
$GLOBALS['__z']['http']['GET https://juanlentino.com/notes/resume-500/'] = array( 'code' => 200, 'body' => "---\ntitle: x\n---\nBody" );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-11/v1.json'] = array( 'code' => 200, 'body' => '{"signature":"s"}' );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-11/v1.ots'] = array( 'code' => 200, 'body' => 'OTS' );
update_post_meta( 11, SN_ZENODO_DRAFT_META, '888' );
$GLOBALS['__z']['http']['GET https://sandbox.zenodo.org/api/deposit/depositions/888'] = array( 'code' => 500, 'body' => '{"message":"internal"}' );
$r = sn_zenodo_deposit( 11 );
ok( false === $r['ok'] && 'resume' === $r['state'] && '888' === get_post_meta( 11, SN_ZENODO_DRAFT_META ), 'a 500 on the resume read keeps the draft id and names the step "resume" (ten orphan drafts on the first production day)' );
$GLOBALS['__z']['http']['GET https://sandbox.zenodo.org/api/deposit/depositions/888'] = array( 'code' => 404, 'body' => '{"message":"not found"}' );
$r = sn_zenodo_deposit( 11 );
ok( false === $r['ok'] && '' === get_post_meta( 11, SN_ZENODO_DRAFT_META ), 'a 404 on the resume read forgets the draft; the next pass starts clean' );
unset( $GLOBALS['__z']['posts'][11], $GLOBALS['__z']['meta'][11], $GLOBALS['__z']['chain'][11] ); // this post is not part of the later counts
// Bundle gate: no proof, no deposit.
mkpost( 10, 'no-proof', 'No proof' );
$GLOBALS['__z']['chain'][10] = array( array( 'version' => 1, 'status' => 'confirmed' ) );
$GLOBALS['__z']['http']['GET https://juanlentino.com/notes/no-proof/'] = array( 'code' => 200, 'body' => 'md' );
$GLOBALS['__z']['http']['GET https://raw.githubusercontent.com/juanlentino/signal-and-noise-provenance/main/notes/uid-10/v1.json'] = array( 'code' => 200, 'body' => '{}' );
$GLOBALS['__z']['log'] = array();
$r = sn_zenodo_deposit( 10 );
ok( 'bundle' === $r['state'] && 'ledger-unavailable' === $r['error'] && array() === $GLOBALS['__z']['log'], 'a bundle without its proof aborts BEFORE any Zenodo request' );

echo "\nGroup F: production flow-back\n";
update_post_meta( 7, SN_ZENODO_DOI_META, '10.5281/zenodo.42' ); update_post_meta( 7, SN_ZENODO_ENV_META, 'production' );
ok( '10.5281/zenodo.42' === sn_zenodo_doi_for( 7 ), 'a production DOI flows back' );
update_post_meta( 7, SN_ZENODO_ENV_META, 'sandbox' );
ok( '' === sn_zenodo_doi_for( 7 ), 'a DOI recorded under the sandbox environment does not, whatever its prefix' );
update_post_meta( 7, SN_ZENODO_ENV_META, 'production' );

echo "\nGroup G: the ledger, the status shape, the health check\n";
$ledger = sn_zenodo_ledger();
$by = array(); foreach ( $ledger as $row ) { $by[ $row['id'] ] = $row['state']; }
ok( 'minted' === $by[7] && 'sandbox' === $by[9] && 'ready' === $by[10] && ! isset( $by[8] ), 'the ledger: minted, sandbox-only, ready; the unsigned page is not a subject' );
$st = sn_zenodo_status_shape( 'production', true, $ledger );
ok( 3 === $st['total'] && 1 === $st['minted'] && 2 === count( $st['missing'] ) && 'production' === $st['environment'], 'the status counts and lists the rows not minted' );
$f = sn_health_zenodo_doi_judge( $ledger );
ok( 2 === count( $f ) && false !== strpos( $f[0]['note'], 'sandbox DOI only' ) && false !== strpos( $f[1]['note'], 'last deposit failed: ' ) && false !== strpos( $f[1]['note'], 'ledger-unavailable' ), 'the check flags the sandbox-only row and the ready row whose last deposit failed, each with its own sentence and the error named' );
delete_post_meta( 10, SN_ZENODO_ERROR_META );
ok( false !== strpos( sn_health_zenodo_doi_judge( sn_zenodo_ledger() )[1]['note'], 'next Zenodo pass' ), 'a ready row with no recorded error says the next pass deposits it' );
$GLOBALS['__z']['chain'][10] = array( array( 'version' => 1, 'status' => 'pending' ) );
ok( 1 === count( sn_health_zenodo_doi_judge( sn_zenodo_ledger() ) ), 'a pending anchor is NOT a finding (not ready by design)' );
$GLOBALS['__z']['cred'] = array();
$c = sn_health_check_zenodo_doi();
ok( 0 === $c['count'] && is_string( $c['skipped'] ), 'no token: the check is SKIPPED, never a pass' );
$GLOBALS['__z']['cred']['zenodo_sandbox_token'] = 's'; update_option( SN_ZENODO_ENV_OPT, 'sandbox' );
ok( is_string( sn_health_check_zenodo_doi()['skipped'] ), 'sandbox environment: skipped too (production DOIs cannot exist yet)' );

echo "\nGroup H: triggers\n";
$GLOBALS['__z']['scheduled'] = array();
sn_zenodo_on_confirmed( 7 );
ok( isset( $GLOBALS['__z']['scheduled'][ SN_ZENODO_HOOK ] ), 'a confirmation books the single deposit event' );
$GLOBALS['__z']['cred'] = array(); $GLOBALS['__z']['scheduled'] = array();
sn_zenodo_on_confirmed( 7 );
ok( ! isset( $GLOBALS['__z']['scheduled'][ SN_ZENODO_HOOK ] ), 'no token: nothing is booked' );
foreach ( $GLOBALS['__z']['actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$ab = $GLOBALS['__z']['abilities']['signal-noise/zenodo-status'] ?? null;
ok( is_array( $ab ) && array( 'object', 'null' ) === $ab['input_schema']['type'] && true === $ab['meta']['annotations']['readonly'], 'zenodo-status registers readonly with the [object,null] input union' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
