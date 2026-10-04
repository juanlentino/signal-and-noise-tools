<?php
/**
 * Standalone test: which MCP protocol version the local doors' clients
 * announce (inc/mcp/mcp-protocol-seen.php).
 *
 * Run: php tests/mcp-protocol-seen.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; $GLOBALS['autoload'] = $a; return true; }
function sn_mcp_legacy_protocol_versions() { return array( '2025-11-25', '2025-06-18' ); }
const SN_MCP_MODERN_VERSIONS = array( '2026-07-28' );
const SN_MCP_META_PREFIX     = 'io.modelcontextprotocol/';
require __DIR__ . '/../inc/mcp/mcp-protocol-seen.php';

echo "\nWhere the version is announced\n";
$meta = array( 'params' => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ) ), 'method' => 'tools/list' );
ok( '2025-06-18' === sn_mcp_announced_protocol( array( 'mcp-protocol-version' => '2025-06-18' ), $meta ), 'the header wins when it is sent' );
ok( '2026-07-28' === sn_mcp_announced_protocol( array(), $meta ), 'no header: the body\'s _meta, so a modern client is not filed under none' );
ok( '2025-11-25' === sn_mcp_announced_protocol( array(), array( 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) ), 'a handshake announces it in initialize\'s params' );
ok( 'none' === sn_mcp_announced_protocol( array(), array( 'method' => 'tools/list', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) ) && 'none' === sn_mcp_announced_protocol( array(), null ) && 'none' === sn_mcp_announced_protocol( array(), array( array( 'method' => 'ping' ) ) ), 'protocolVersion on anything but initialize, no message, or a batch: none' );

echo "\nThe stored reading\n";
$known = array( '2025-11-25', '2025-06-18', '2026-07-28' );
$t     = gmmktime( 15, 0, 0, 10, 4, 2026 );
$s     = sn_mcp_protocol_seen_fold( array(), 'read', '2026-07-28', $known, $t );
$s     = sn_mcp_protocol_seen_fold( $s, 'read', '2026-07-28', $known, $t + 60 );
$s     = sn_mcp_protocol_seen_fold( $s, 'rw', 'none', $known, $t + 120 );
ok( array( 'read' => array( '2026-07-28' => 2 ), 'rw' => array( 'none' => 1 ) ) === $s['today'] && '2026-10-04' === $s['day'] && $t + 60 === $s['last_seen']['read']['2026-07-28'], 'a count per door and version for the day, and when each was last seen' );
$next = sn_mcp_protocol_seen_fold( $s, 'read', '2025-06-18', $known, $t + 86400 );
ok( array( 'read' => array( '2025-06-18' => 1 ) ) === $next['today'] && $t + 60 === $next['last_seen']['read']['2026-07-28'], 'a new UTC day starts the counts over and keeps the stamps' );
ok( array( 'read' => array( 'scriptxscript' => 1 ) ) === sn_mcp_protocol_seen_fold( array(), 'read', '<script>x</script>', $known, $t )['today'], 'a label is reduced to digits, letters and hyphens' );
$full = array();
foreach ( range( 1, 12 ) as $i ) { $full = sn_mcp_protocol_seen_fold( $full, 'read', "v-$i", $known, $t ); }
$full = sn_mcp_protocol_seen_fold( $full, 'read', 'one-too-many', $known, $t );
$full = sn_mcp_protocol_seen_fold( $full, 'read', '2025-06-18', $known, $t );
$full = sn_mcp_protocol_seen_fold( $full, 'read', 'v-3', $known, $t );
$full = sn_mcp_protocol_seen_fold( $full, 'rw', 'fresh-on-the-other-door', $known, $t );
ok( 1 === $full['today']['read']['other'] && ! isset( $full['last_seen']['read']['one-too-many'] ) && 1 === $full['today']['read']['2025-06-18'] && 2 === $full['today']['read']['v-3'] && 1 === $full['today']['rw']['fresh-on-the-other-door'], 'past twelve unrecognized names a new one is counted as other; a served version and a name already seen keep theirs; the cap is per door' );
ok( array( 'day' => '2026-10-04', 'today' => array( 'read' => array( 'none' => 1 ) ), 'last_seen' => array( 'read' => array( 'none' => $t ) ) ) === sn_mcp_protocol_seen_fold( 'garbage', 'read', '', $known, $t ), 'a stored value that is not a reading is replaced, and an empty label is none' );

$bad = sn_mcp_protocol_seen_fold( array( 'day' => '2026-10-04', 'today' => array( 'read' => 'oops', 'rw' => array( 'none' => 'x' ) ), 'last_seen' => array( 'read' => 7 ) ), 'read', 'none', $known, $t );
$bad = sn_mcp_protocol_seen_fold( $bad, 'rw', 'none', $known, $t );
ok( array( 'none' => 1 ) === $bad['today']['read'] && array( 'none' => 1 ) === $bad['today']['rw'] && $t === $bad['last_seen']['read']['none'], 'a bucket or a count of the wrong type is replaced, not indexed: the count runs inside the dispatch' );

echo "\nThe record and the readout\n";
sn_mcp_protocol_seen_record( 'read', array(), $meta );
ok( 1 === $GLOBALS['opt'][ SN_MCP_PROTOCOL_SEEN_OPT ]['today']['read']['2026-07-28'] && false === $GLOBALS['autoload'], 'one request is one count, stored without autoload' );
$GLOBALS['opt'][ SN_MCP_PROTOCOL_SEEN_OPT ] = $s;
$r = sn_mcp_protocol_seen( $t + 300 );
ok( $s['today'] === $r['today'] && '2026-10-04T15:01:00Z' === $r['last_seen']['read']['2026-07-28'], 'the readout carries today\'s counts and ISO stamps' );
$r2 = sn_mcp_protocol_seen( $t + 2 * 86400 );
ok( array() === $r2['today'] && '2026-10-06' === $r2['day'] && isset( $r2['last_seen']['rw']['none'] ), 'read on a later day: no counts for today, the stamps still there' );
$src = (string) file_get_contents( __DIR__ . '/../inc/mcp/mcp-endpoint.php' );
ok( false !== strpos( $src, 'sn_mcp_protocol_seen_record( $door, $headers, $decoded );' ) && strpos( $src, 'sn_mcp_protocol_seen_record(' ) < strpos( $src, 'sn_mcp_modern_handle( $headers, $decoded, $door )' ), 'the dispatch counts every request before either generation handles it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
