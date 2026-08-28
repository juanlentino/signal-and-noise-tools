<?php
/**
 * Tests: sn-scan scan_type tag_hygiene (v13.27.0) — the vocabulary source.
 *
 * Drives the REAL adapter over the REAL sn_health_check_tag_hygiene()
 * producer with a stubbed taxonomy. Properties: (1) both detectors emit
 * candidates with the right apply_hint; (2) a zero-post undescribed tag
 * yields ONE candidate (unused — the health check's report-once rule
 * survives the reshape); (3) the fingerprint is the STATE, so a tag moving
 * between types changes candidate identity; (4) two runs on unchanged terms
 * are byte-identical; (5) a skipped health check is a WP_Error, never an
 * empty-clean; (6) the scope resolver rejects non-"all" scopes for this
 * type; (7) the type/adapter/detector registries all carry it.
 *
 * Run: php tests/sn-scan-tag-hygiene.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $h, $v ) { return $v; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['__terms_result'] = array();
function get_terms( $args ) { return $GLOBALS['__terms_result']; }

// The real envelope helper's contract, restated (as in tests/health-check-tag-hygiene.php).
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

require_once __DIR__ . '/../inc/health-check-tag-hygiene.php';
require_once __DIR__ . '/../inc/sn-scan-detectors.php';
require_once __DIR__ . '/../inc/sn-scan-adapters.php';

echo "sn-scan: tag_hygiene source\n\n";

echo "Group: registries carry the type\n";
ok( array_key_exists( 'tag_hygiene', snt_sn_scan_adapters() ), 'adapter registry has tag_hygiene' );
$dets = snt_sn_scan_detectors_for( 'tag_hygiene' );
$det_ids = array_map( static function ( $d ) { return $d['id']; }, $dets );
ok( array( 'undescribed_tag', 'unused_tag' ) === $det_ids, 'detector registry declares undescribed_tag + unused_tag' );

echo "\nGroup: both detectors emit, with the right apply_hint\n";
$GLOBALS['__terms_result'] = array(
	(object) array( 'name' => 'Provenance', 'count' => 35, 'description' => 'Written.' ),
	(object) array( 'name' => 'New Cluster', 'count' => 4, 'description' => '' ),
	(object) array( 'name' => 'Typo Tagg', 'count' => 0, 'description' => '' ),
);
$r = snt_sn_scan_adapter_tag_hygiene( null );
ok( is_array( $r ) && 2 === count( $r['candidates'] ), 'the described tag is clean; the two dirty tags candidate (got ' . count( $r['candidates'] ?? array() ) . ')' );
$by_name = array();
foreach ( $r['candidates'] as $c ) { $by_name[ $c['target_identity'] ] = $c; }
ok( 'undescribed_tag' === ( $by_name['New Cluster']['evidence']['detector'] ?? '' ), 'in-use empty-description tag → undescribed_tag' );
ok( 'signal-noise/describe-tags' === ( $by_name['New Cluster']['apply_hint']['tool'] ?? '' ), 'undescribed apply_hint names describe-tags' );
ok( in_array( 'tags:[New Cluster]', $by_name['New Cluster']['apply_hint']['required_args'] ?? array(), true ), 'and its required_args carry the tag name' );
ok( 'unused_tag' === ( $by_name['Typo Tagg']['evidence']['detector'] ?? '' ), 'zero-post tag → unused_tag (report-once survives the reshape)' );
ok( 'signal-noise/prune-unused-tags' === ( $by_name['Typo Tagg']['apply_hint']['tool'] ?? '' ), 'unused apply_hint names prune-unused-tags' );
ok( 3 === (int) $r['posts_examined'], 'posts_examined counts TERMS (3 in the vocabulary)' );

echo "\nGroup: the fingerprint is the state\n";
$fp_unused = $by_name['Typo Tagg']['content_fingerprint'];
$GLOBALS['__terms_result'] = array(
	(object) array( 'name' => 'Typo Tagg', 'count' => 2, 'description' => '' ),
);
$r2 = snt_sn_scan_adapter_tag_hygiene( null );
ok( 'undescribed_tag' === ( $r2['candidates'][0]['evidence']['detector'] ?? '' ), 'the tag gaining posts moves it to undescribed_tag' );
ok( $r2['candidates'][0]['content_fingerprint'] !== $fp_unused, 'and its candidate identity CHANGES — the old unused candidate cannot resurrect' );

echo "\nGroup: determinism\n";
$GLOBALS['__terms_result'] = array(
	(object) array( 'name' => 'B Tag', 'count' => 2, 'description' => '' ),
	(object) array( 'name' => 'A Tag', 'count' => 0, 'description' => '' ),
);
$a = snt_sn_scan_adapter_tag_hygiene( null );
$b = snt_sn_scan_adapter_tag_hygiene( null );
ok( wp_json_encode_shim( $a ) === wp_json_encode_shim( $b ), 'two runs on unchanged terms are byte-identical' );
function wp_json_encode_shim( $v ) { return json_encode( $v ); }

echo "\nGroup: a skip is an error, never an empty-clean\n";
$GLOBALS['__terms_result'] = new WP_Error( 'invalid_taxonomy' );
$r = snt_sn_scan_adapter_tag_hygiene( null );
ok( is_wp_error( $r ), 'unmeasurable taxonomy → WP_Error, not zero candidates' );

echo "\nGroup: scope discipline (resolver source)\n";
$src = file_get_contents( __DIR__ . '/../inc/abilities-sn-scan.php' );
ok( false !== strpos( $src, "'tag_hygiene' === \$scan_type && 'all' !== \$kind" ), 'resolve_scope rejects non-all scopes for tag_hygiene (source pin)' );
ok( false !== strpos( $src, "'tag_hygiene'," ), 'SNT_SN_SCAN_TYPES lists tag_hygiene' );
ok( false !== strpos( $src, 'scan_type "tag_hygiene"' ), 'the doored description documents the new scan_type' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
