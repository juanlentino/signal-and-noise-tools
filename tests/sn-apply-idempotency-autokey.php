<?php
/**
 * Auto-derived idempotency keys (v11.5.0): the replay protection the
 * 2026-08-14 reinsertion audit found to be opt-in becomes automatic.
 *
 * THE INVARIANT UNDER TEST: every mutating (dry_run:false) sn_apply call is
 * replay-protected — by the caller's own idempotency_key when supplied, and
 * by a server-derived auto-key otherwise. The ONLY exceptions are the two
 * side-effect types (og_card, anchor_sweep) where an identical repeat is a
 * LEGITIMATE new call (force-regenerating a card; re-dispatching a sweep) —
 * and neither writes a post body, so double-fire is not a corruption risk.
 * A NEW change type is auto-protected by default: the classification fails
 * closed INTO protection, and the exclusion list is value-pinned here so
 * extending it must be a deliberate, reviewed edit.
 *
 * Pre-11.5.0, keyless retries were safe only via gate-1 fingerprint SIDE
 * EFFECTS (content_hash changes after a successful write) — a property of
 * the current fingerprint schemes, not a designed invariant. Field data
 * (103 live applies with keyed rate-limit retries, zero double-applies)
 * confirmed the keyed path; this suite pins the keyless one.
 *
 * Run: php tests/sn-apply-idempotency-autokey.php
 * @since plugin v11.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

// ── WP stubs: the gates file needs options + time + json only ────────────
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

require __DIR__ . '/../inc/sn-apply-executors.php'; // SNT_SN_APPLY_CHANGE_TYPES
require __DIR__ . '/../inc/sn-apply-gates.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Auto-derived idempotency keys (v11.5.0)\n";

$change = array(
	'type'        => 'sentence_replace',
	'fingerprint' => 'abc123',
	'payload'     => array( 'phrase' => 'the old sentence here', 'replacement' => 'the new sentence here' ),
);

echo "\nGroup: derivation\n";
$k1 = snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $change, 'post:42' );
ok( is_string( $k1 ) && 0 === strpos( $k1, 'auto:' ), 'keyless mutating call derives an auto: key' );
$k2 = snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $change, 'post:42' );
ok( $k1 === $k2, 'DETERMINISTIC: the identical retry derives the identical key — that is the whole point' );

ok( 'caller-key-9' === snt_sn_apply_effective_idempotency_key( 'caller-key-9', 'sentence_replace', 'publish', false, $change, 'post:42' ), 'a caller-supplied key ALWAYS wins — the contract is unchanged for keyed callers' );
ok( 'caller-key-9' === snt_sn_apply_effective_idempotency_key( 'caller-key-9', 'og_card', 'publish', false, $change, 'post:42' ), 'even for an excluded type: a caller who opts in gets protection' );

ok( '' === snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', true, $change, 'post:42' ), 'dry_run derives NO key — previews are harmless to repeat and never consult the store anyway' );

echo "\nGroup: distinct logical calls derive distinct keys\n";
$other_payload = $change; $other_payload['payload']['replacement'] = 'a different replacement';
ok( $k1 !== snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $other_payload, 'post:42' ), 'different payload → different key (a genuinely new edit is never deduped)' );
$other_fp = $change; $other_fp['fingerprint'] = 'def456';
ok( $k1 !== snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $other_fp, 'post:42' ), 'different fingerprint → different key (a re-scan against changed content is a new call)' );
ok( $k1 !== snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $change, 'post:43' ), 'different target → different key' );
ok( $k1 !== snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'revision', false, $change, 'post:42' ), 'different mode → different key (staging then publishing the same edit is two logical calls)' );

echo "\nGroup: THE EXCLUSION PIN — side-effect types, exactly, with the reason\n";
$excluded = snt_sn_apply_autokey_excluded_types();
sort( $excluded );
ok( array( 'anchor_sweep', 'og_card' ) === $excluded, 'exclusions are EXACTLY {anchor_sweep, og_card} — identical repeats are legitimate there (re-sweep, force-regenerate), and neither writes a post body. Extending this list is a deliberate edit that must argue its case here' );
foreach ( $excluded as $t ) {
	ok( in_array( $t, SNT_SN_APPLY_CHANGE_TYPES, true ), "exclusion '$t' names a REAL registered type — a stale exclusion would silently protect nothing" );
	ok( '' === snt_sn_apply_effective_idempotency_key( '', $t, 'publish', false, array( 'type' => $t ), 'post:42' ), "excluded type '$t' derives no auto-key" );
}
// The fail-closed default, walked over the WHOLE enum: every non-excluded
// type derives a key. A future type added to the enum is protected the
// moment it exists — no opt-in step to forget.
$unprotected = array();
foreach ( SNT_SN_APPLY_CHANGE_TYPES as $t ) {
	if ( in_array( $t, $excluded, true ) ) { continue; }
	$k = snt_sn_apply_effective_idempotency_key( '', $t, 'publish', false, array( 'type' => $t, 'payload' => array( 'x' => 1 ) ), 'post:7' );
	if ( ! is_string( $k ) || 0 !== strpos( $k, 'auto:' ) ) { $unprotected[] = $t; }
}
ok( array() === $unprotected, 'INVARIANT: every registered non-excluded change type auto-protects (fail-closed classification): ' . implode( ',', $unprotected ) );

echo "\nGroup: end to end through the real gate + store\n";
$auto = snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $change, 'post:42' );
$g1   = snt_sn_apply_gate_idempotency( $auto, 'post:42' );
ok( true === $g1['passed'] && null === $g1['replay'], 'first execution: fresh call, no replay' );
snt_sn_apply_idempotency_record( $auto, 'post:42', array( 'applied' => true, 'marker' => 'first-response' ) );
$g2 = snt_sn_apply_gate_idempotency( $auto, 'post:42' );
ok( is_array( $g2['replay'] ) && 'first-response' === $g2['replay']['marker'], 'THE RETRY REPLAYS: identical keyless retry gets the first response, no second write — the audit finding, closed' );
$g3 = snt_sn_apply_gate_idempotency( snt_sn_apply_effective_idempotency_key( '', 'sentence_replace', 'publish', false, $other_payload, 'post:42' ), 'post:42' );
ok( null === $g3['replay'], 'and a DIFFERENT edit to the same post is untouched by the dedupe' );

echo "\nGroup: no PHP notices\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
