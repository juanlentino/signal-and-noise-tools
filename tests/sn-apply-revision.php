<?php
/**
 * Standalone fixture tests for the sn_apply revision-mode write primitive
 * (MCP consolidation session 6a) — inc/sn-apply-revision.php.
 *
 * Core WP functions are stubbed to mirror their REAL documented contracts,
 * verified against the actual WP 7.0.2 source (wp-includes/revision.php,
 * wp-includes/post.php — cached and read directly, not recalled from
 * training knowledge; a first pass at these stubs got 3 things wrong this
 * way and was corrected after adversarial review):
 *   - _wp_put_post_revision() narrows to core's REAL field allowlist —
 *     post_title/post_content/post_excerpt ONLY (post_author is explicitly
 *     on `_wp_post_revision_fields()`'s own disallowed-field list) — and
 *     calls wp_insert_post( $revision_data, true ), i.e. WITH
 *     $wp_error = true, so a DB failure surfaces as a real WP_Error. That is
 *     the primary path (Test 10a). A separate `empty()`/int-0 arm in the
 *     primitive is a defensive/cross-version fallback, not the documented
 *     7.0.2 shape — exercised distinctly in Test 10b.
 *   - _wp_put_post_revision() also runs wp_slash() on the row immediately
 *     before wp_insert_post() ("Since data is from DB."); this stub models
 *     that slash-then-unslash-on-store round trip (wp_insert_post() unslashes
 *     before writing to the fixture "table") so a future double-slash
 *     regression in the primitive is observable — see the gnarly-content
 *     slash-safety test (Test 17).
 *   - wp_insert_post() fires `transition_post_status` UNCONDITIONALLY (only
 *     gated on attachment-vs-not) AND fires `save_post`/`save_post_{type}`/
 *     `wp_insert_post` (the action) UNCONDITIONALLY too — there is NO
 *     core-level post_type guard for revisions anywhere in the real source.
 *     An earlier pass at this file wrongly modeled save_post as
 *     revision-guarded by core; it is not. Test 8 pins the real shape: both
 *     hook families fire for a staged revision, and guard-representative
 *     callbacks (mirroring ml-artifacts.php's transition_post_status guard
 *     and schedule-sync.php's/post-settings.php's save_post
 *     wp_is_post_revision() guard) prove it is THOSE plugin-side guards,
 *     not an absent fire, that keep our own hooks from cascading.
 *
 * @since plugin v10.39.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// ─── State ──────────────────────────────────────────────────────────────
$GLOBALS['__test_posts']               = array(); // id => ARRAY_A row
$GLOBALS['__test_postmeta']            = array();
$GLOBALS['__test_options']             = array();
$GLOBALS['__test_next_id']             = 1000;
$GLOBALS['__test_revisions_supported'] = array( 'post' => true );
$GLOBALS['__test_revisions_to_keep']   = -1; // unlimited, WP default
$GLOBALS['__test_insert_fail']         = false;
$GLOBALS['__test_insert_fail_mode']    = 'wp_error'; // 'wp_error' (real 7.0.2 path) | 'int_zero' (defensive-arm path)
$GLOBALS['__test_hooks']               = array();
$GLOBALS['__hook_calls']               = array();

// ─── WP core stubs (faithful to documented contracts, see file header) ──
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'sprintf' ) ) {
	// Native PHP function — never actually stubbed, listed for clarity only.
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id, $output = 'OBJECT' ) {
		$row = $GLOBALS['__test_posts'][ (int) $id ] ?? null;
		if ( ! $row ) {
			return null;
		}
		return 'ARRAY_A' === $output ? $row : (object) $row;
	}
}

if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( $post_type, $feature ) {
		if ( 'revisions' !== $feature ) {
			return true;
		}
		return $GLOBALS['__test_revisions_supported'][ $post_type ] ?? true;
	}
}

if ( ! function_exists( 'wp_revisions_to_keep' ) ) {
	function wp_revisions_to_keep( $post ) {
		return $GLOBALS['__test_revisions_to_keep'];
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['__test_hooks'][ $hook ][] = $cb;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['__hook_calls'][ $hook ] = ( $GLOBALS['__hook_calls'][ $hook ] ?? 0 ) + 1;
		foreach ( ( $GLOBALS['__test_hooks'][ $hook ] ?? array() ) as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}
if ( ! function_exists( 'wp_transition_post_status' ) ) {
	function wp_transition_post_status( $new_status, $old_status, $post ) {
		do_action( 'transition_post_status', $new_status, $old_status, $post );
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	// Real core (wp-includes/formatting.php): recursively addslashes().
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	// Real core: recursively stripslashes(). DB storage holds the unslashed
	// value — wp_slash() exists only to survive an SQL query round trip.
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		if ( $GLOBALS['__test_insert_fail'] ) {
			if ( 'int_zero' === $GLOBALS['__test_insert_fail_mode'] ) {
				// Synthetic: exercises the primitive's defensive empty()
				// arm. Real 7.0.2 core would not return this under
				// $wp_error = true — this models a cross-version/future
				// return-contract drift, not today's documented behavior.
				return 0;
			}
			// Real 7.0.2 shape: $wp_error = true (which _wp_put_post_revision
			// always passes) means a DB failure comes back as WP_Error.
			return $wp_error ? new WP_Error( 'db_insert_error', 'mock insert fail' ) : 0;
		}
		$id         = $GLOBALS['__test_next_id']++;
		// Core unslashes before writing to the posts table — DB storage is
		// always the unslashed value. Modeling this makes a pre-slash
		// regression upstream (double-slashing) observable in assertions.
		$unslashed  = wp_unslash( $postarr );
		$post_type  = $unslashed['post_type'] ?? 'post';
		$row        = array_merge( array( 'ID' => $id ), $unslashed );
		$GLOBALS['__test_posts'][ $id ] = $row;
		$post_obj   = (object) $row;
		// Core calls this for every non-attachment insert, unconditionally.
		wp_transition_post_status( $row['post_status'] ?? 'publish', 'new', $post_obj );
		// Real 7.0.2 core: NO post_type guard on any of these three — they
		// fire for every insert, revisions included (post.php:5165-5211).
		do_action( "save_post_{$post_type}", $id, $post_obj, false );
		do_action( 'save_post', $id, $post_obj, false );
		do_action( 'wp_insert_post', $id, $post_obj, false );
		return $id;
	}
}

if ( ! function_exists( '_wp_put_post_revision' ) ) {
	function _wp_put_post_revision( $post = null, $autosave = false ) {
		if ( is_object( $post ) ) {
			$post = (array) $post;
		} elseif ( ! is_array( $post ) ) {
			$post = get_post( $post, 'ARRAY_A' );
		}
		if ( ! $post || empty( $post['ID'] ) ) {
			return new WP_Error( 'invalid_post', 'Invalid post ID.' );
		}
		if ( isset( $post['post_type'] ) && 'revision' === $post['post_type'] ) {
			return new WP_Error( 'post_type', 'Cannot create a revision of a revision' );
		}

		// Real 7.0.2 allowlist (_wp_post_revision_fields()): title/content/
		// excerpt ONLY — post_author is explicitly disallowed.
		$revision_data = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$revision_data[ $field ] = $post[ $field ] ?? '';
		}
		$revision_data['post_parent']   = $post['ID'];
		$revision_data['post_status']   = 'inherit';
		$revision_data['post_type']     = 'revision';
		$revision_data['post_name']     = $autosave ? "{$post['ID']}-autosave-v1" : "{$post['ID']}-revision-v1";
		$revision_data['post_date']     = $post['post_modified'] ?? '';
		$revision_data['post_date_gmt'] = $post['post_modified_gmt'] ?? '';

		// Real core: "Since data is from DB" — wp_slash() runs here, then
		// wp_insert_post() unslashes on write. Round trip must be identity.
		$revision_data = wp_slash( $revision_data );

		// Real 7.0.2 shape: $wp_error = true IS passed, so a DB failure
		// comes back as WP_Error, not silently as int 0.
		$revision_id = wp_insert_post( $revision_data, true );
		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}
		if ( $revision_id ) {
			do_action( '_wp_put_post_revision', $revision_id, $post['ID'] );
		}
		return $revision_id;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	function wp_restore_post_revision( $revision_id ) {
		$revision = $GLOBALS['__test_posts'][ (int) $revision_id ] ?? null;
		if ( ! $revision || 'revision' !== ( $revision['post_type'] ?? '' ) ) {
			return false;
		}
		$parent_id = (int) $revision['post_parent'];
		if ( ! isset( $GLOBALS['__test_posts'][ $parent_id ] ) ) {
			return false;
		}
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$GLOBALS['__test_posts'][ $parent_id ][ $field ] = $revision[ $field ] ?? '';
		}
		do_action( 'wp_restore_post_revision', $parent_id, $revision_id );
		return $parent_id;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	// Real core (revision.php:311-319): ID of the parent on success, false
	// otherwise. Used by the guard-representative callbacks in Test 8 to
	// mirror inc/schedule-sync.php's and inc/post-settings.php's real guard.
	function wp_is_post_revision( $post ) {
		$row = is_object( $post ) ? (array) $post : ( $GLOBALS['__test_posts'][ (int) $post ] ?? null );
		if ( ! $row || 'revision' !== ( $row['post_type'] ?? '' ) ) {
			return false;
		}
		return (int) ( $row['post_parent'] ?? 0 );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['__test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['__test_postmeta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}

require_once __DIR__ . '/../inc/sn-apply-revision.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function sar_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function sar_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

function _sar_reset() {
	$GLOBALS['__test_posts']               = array();
	$GLOBALS['__test_postmeta']            = array();
	$GLOBALS['__test_options']             = array();
	$GLOBALS['__test_revisions_supported'] = array( 'post' => true );
	$GLOBALS['__test_revisions_to_keep']   = -1;
	$GLOBALS['__test_insert_fail']         = false;
	$GLOBALS['__test_insert_fail_mode']    = 'wp_error';
	$GLOBALS['__test_hooks']               = array();
	$GLOBALS['__hook_calls']               = array();
}

function _sar_post( $id, $overrides = array() ) {
	$row = array_merge( array(
		'ID'                => $id,
		'post_type'         => 'post',
		'post_status'       => 'publish',
		'post_title'        => "Fixture $id",
		'post_content'      => 'Original content.',
		'post_excerpt'      => 'Original excerpt.',
		'post_author'       => '1',
		'post_parent'       => 0,
		'post_modified'     => '2026-08-04 10:00:00',
		'post_modified_gmt' => '2026-08-04 10:00:00',
		'post_name'         => "fixture-$id",
	), $overrides );
	$GLOBALS['__test_posts'][ $id ] = $row;
	return $row;
}

echo "sn_apply revision-mode primitive suite — plugin v10.39.0\n";

// ─── Test 1: post not found → 404 ────────────────────────────────────
echo "\nTest 1: post not found\n";
_sar_reset();
$result = snt_sn_apply_stage_revision( 999, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 1.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_post_not_found', $result->get_error_code(), 'Test 1.2: error code' );
sar_eq( 404, $result->data['status'], 'Test 1.3: status 404' );

// ─── Test 2: empty proposed → 422 ────────────────────────────────────
echo "\nTest 2: empty proposed\n";
_sar_reset();
_sar_post( 1 );
$result = snt_sn_apply_stage_revision( 1, array() );
sar_true( is_wp_error( $result ), 'Test 2.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_invalid_proposed', $result->get_error_code(), 'Test 2.2: error code' );
sar_eq( 422, $result->data['status'], 'Test 2.3: status 422' );

// ─── Test 3: unknown field in proposed → 422 ─────────────────────────
echo "\nTest 3: unsupported field (e.g. post_status)\n";
_sar_reset();
_sar_post( 2 );
$result = snt_sn_apply_stage_revision( 2, array( 'post_status' => 'draft' ) );
sar_true( is_wp_error( $result ), 'Test 3.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_invalid_proposed', $result->get_error_code(), 'Test 3.2: error code' );

// ─── Test 4: revisions unsupported for post type → 409, loud not silent ─
echo "\nTest 4: revisions unsupported (post type gate)\n";
_sar_reset();
_sar_post( 3, array( 'post_type' => 'attachment' ) );
$GLOBALS['__test_revisions_supported']['attachment'] = false;
$result = snt_sn_apply_stage_revision( 3, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 4.1: returns WP_Error (never silent)' );
sar_eq( 'snt_sn_apply_revisions_unsupported', $result->get_error_code(), 'Test 4.2: error code' );
sar_eq( 409, $result->data['status'], 'Test 4.3: status 409' );

// ─── Test 5: wp_revisions_to_keep() === 0 → 409, loud not silent ────
echo "\nTest 5: revisions explicitly disabled (wp_revisions_to_keep = 0)\n";
_sar_reset();
_sar_post( 4 );
$GLOBALS['__test_revisions_to_keep'] = 0;
$result = snt_sn_apply_stage_revision( 4, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 5.1: returns WP_Error (never silent, unlike wp_save_post_revision())' );
sar_eq( 'snt_sn_apply_revisions_disabled', $result->get_error_code(), 'Test 5.2: error code' );

// ─── Test 6: target is itself a revision → 422 ───────────────────────
echo "\nTest 6: target post is itself a revision\n";
_sar_reset();
_sar_post( 5, array( 'post_type' => 'revision', 'post_parent' => 1 ) );
$result = snt_sn_apply_stage_revision( 5, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 6.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_revision_of_revision', $result->get_error_code(), 'Test 6.2: error code' );

// ─── Test 7 / acceptance test 1+6: successful stage, live row untouched ─
echo "\nTest 7: successful stage — diff produced, live post BYTE-IDENTICAL after (acceptance test 6)\n";
_sar_reset();
_sar_post( 6 );
$before_snapshot = $GLOBALS['__test_posts'][6]; // full row, incl. post_modified*
$result = snt_sn_apply_stage_revision( 6, array( 'post_content' => 'Proposed new content.' ) );
sar_true( is_array( $result ) && ! is_wp_error( $result ), 'Test 7.1: returns array (success)' );
sar_true( $result['revision_id'] > 0, 'Test 7.2: revision_id assigned' );
sar_eq( 6, $result['post_id'], 'Test 7.3: post_id echoed' );
sar_eq( array( 'post_content' ), $result['fields_staged'], 'Test 7.4: fields_staged echoes caller keys' );

$after_snapshot = $GLOBALS['__test_posts'][6];
sar_eq( $before_snapshot, $after_snapshot, 'Test 7.5: live post row strictly unchanged (every field, incl. post_modified)' );

$revision_row = $GLOBALS['__test_posts'][ $result['revision_id'] ];
sar_eq( 'revision', $revision_row['post_type'], 'Test 7.6: staged row is post_type=revision' );
sar_eq( 'inherit', $revision_row['post_status'], 'Test 7.7: staged row is post_status=inherit' );
sar_eq( 6, $revision_row['post_parent'], 'Test 7.8: staged row post_parent points at the target' );
sar_eq( 'Proposed new content.', $revision_row['post_content'], 'Test 7.9: staged row carries the PROPOSED content' );
sar_eq( 'Original content.', $GLOBALS['__test_posts'][6]['post_content'], 'Test 7.10: live post_content still the ORIGINAL value' );

// ─── Test 8: hook cascade — BOTH families fire; plugin-side guards, not core, protect us ─
echo "\nTest 8: hook cascade (both save_post and transition_post_status fire — real 7.0.2 shape)\n";
_sar_reset();
_sar_post( 7 );

$transition_seen = array();
add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) use ( &$transition_seen ) {
	$transition_seen[] = array( $new_status, $old_status, $post->post_type ?? '' );
} );
// Guard representative of ml-artifacts.php's real snt_ml_on_transition():
// requires post_type === 'post'. Registered SEPARATELY to prove the guard
// — not an absent fire — is what suppresses the side effect.
$transition_guarded_effect_ran = false;
add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) use ( &$transition_guarded_effect_ran ) {
	if ( 'post' !== ( $post->post_type ?? '' ) ) {
		return; // mirrors ml-artifacts.php's real guard
	}
	$transition_guarded_effect_ran = true;
} );

$save_post_seen = array();
add_action( 'save_post', function ( $post_id, $post ) use ( &$save_post_seen ) {
	$save_post_seen[] = $post->post_type ?? '';
} );
// Guard representative of inc/schedule-sync.php's sn_schedule_sync_post()
// AND inc/post-settings.php's sn_post_settings_save(): both call
// wp_is_post_revision( $post_id ) first and return early. Registered
// SEPARATELY to prove THIS guard, not a core-level skip, is what protects
// them — corrected after adversarial review found no such core guard exists.
$save_post_guarded_effect_ran = false;
add_action( 'save_post', function ( $post_id ) use ( &$save_post_guarded_effect_ran ) {
	if ( wp_is_post_revision( $post_id ) ) {
		return; // mirrors schedule-sync.php / post-settings.php's real guard
	}
	$save_post_guarded_effect_ran = true;
} );

$result = snt_sn_apply_stage_revision( 7, array( 'post_content' => 'x' ) );
sar_true( is_array( $result ) && ! is_wp_error( $result ), 'Test 8.1: stage succeeds' );
sar_eq( 1, count( $transition_seen ), 'Test 8.2: transition_post_status fired exactly once — it is NOT skipped for revisions' );
sar_eq( array( 'inherit', 'new', 'revision' ), $transition_seen[0], 'Test 8.3: fired with (new=inherit, old=new, post_type=revision)' );
sar_true( ! $transition_guarded_effect_ran, 'Test 8.4: the post_type guard (ml-artifacts.php pattern) suppresses the transition_post_status side effect' );
sar_eq( array( 'revision' ), $save_post_seen, 'Test 8.5: save_post DOES fire for a revision insert — no core-level guard exists (corrected finding)' );
sar_true( ! $save_post_guarded_effect_ran, 'Test 8.6: the wp_is_post_revision() guard (schedule-sync.php / post-settings.php pattern) suppresses the save_post side effect' );

// ─── Test 9 (RED control): a wp_update_post-based staging WOULD fail Test 7.5 ─
echo "\nTest 9: RED control — proves the byte-identical assertion has teeth\n";
_sar_reset();
_sar_post( 8 );
$before_snapshot = $GLOBALS['__test_posts'][8];
// Deliberately-wrong mechanism: stage by writing straight to the live row,
// the exact anti-pattern every existing apply ability uses today per
// FINDINGS.md #5. This function is local to the test file — never shipped.
function _sar_wrong_mechanism_stage( $post_id, array $proposed ) {
	foreach ( $proposed as $field => $value ) {
		$GLOBALS['__test_posts'][ $post_id ][ $field ] = $value;
	}
	return array( 'post_id' => $post_id, 'fields_staged' => array_keys( $proposed ) );
}
_sar_wrong_mechanism_stage( 8, array( 'post_content' => 'Silently live-written.' ) );
$after_wrong = $GLOBALS['__test_posts'][8];
sar_true( $before_snapshot !== $after_wrong, 'Test 9.1: the wrong mechanism DOES mutate the live row (would fail acceptance test 6)' );
// Now prove OUR primitive does not, on the same fixture shape.
_sar_reset();
_sar_post( 8 );
$before_snapshot = $GLOBALS['__test_posts'][8];
snt_sn_apply_stage_revision( 8, array( 'post_content' => 'Correctly staged.' ) );
sar_eq( $before_snapshot, $GLOBALS['__test_posts'][8], 'Test 9.2: our primitive leaves the live row identical on the same fixture' );

// ─── Test 10a: wp_insert_post DB failure — REAL 7.0.2 path (WP_Error) ────
// Previously ZERO coverage: _wp_put_post_revision() passes $wp_error = true,
// so this is the documented, primary failure shape — not the empty() arm.
echo "\nTest 10a: wp_insert_post DB failure — WP_Error branch (real 7.0.2 path, primary)\n";
_sar_reset();
_sar_post( 9 );
$GLOBALS['__test_insert_fail']      = true;
$GLOBALS['__test_insert_fail_mode'] = 'wp_error';
$result = snt_sn_apply_stage_revision( 9, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 10a.1: returns WP_Error (real is_wp_error() branch, not the defensive fallback)' );
sar_eq( 'snt_sn_apply_write_failed', $result->get_error_code(), 'Test 10a.2: error code' );
sar_eq( 500, $result->data['status'], 'Test 10a.3: status 500' );
$GLOBALS['__test_insert_fail'] = false;

// ─── Test 10b: wp_insert_post DB failure — defensive empty() arm ─────────
// A synthetic cross-version scenario: even though $wp_error = true is
// passed, something hands back a falsy 0 anyway. The primitive's second
// check must still catch it.
echo "\nTest 10b: wp_insert_post DB failure — int-0 branch (defensive/cross-version arm)\n";
_sar_reset();
_sar_post( 10 );
$GLOBALS['__test_insert_fail']      = true;
$GLOBALS['__test_insert_fail_mode'] = 'int_zero';
$result = snt_sn_apply_stage_revision( 10, array( 'post_content' => 'x' ) );
sar_true( is_wp_error( $result ), 'Test 10b.1: returns WP_Error (translated from int 0 by the defensive empty() arm)' );
sar_eq( 'snt_sn_apply_write_failed', $result->get_error_code(), 'Test 10b.2: error code' );
sar_eq( 500, $result->data['status'], 'Test 10b.3: status 500' );
$GLOBALS['__test_insert_fail']      = false;
$GLOBALS['__test_insert_fail_mode'] = 'wp_error';

// ─── Test 11: stage_meta — draft queue, NOT postmeta ─────────────────
echo "\nTest 11: stage_meta writes a draft-queue row, never postmeta\n";
_sar_reset();
_sar_post( 10 );
$result = snt_sn_apply_stage_meta( 10, 'sn_og_card_title', 'New OG Title', 'fp-abc123' );
sar_true( is_array( $result ) && ! is_wp_error( $result ), 'Test 11.1: returns array (success)' );
sar_eq( 10, $result['post_id'], 'Test 11.2: post_id echoed' );
sar_eq( 'sn_og_card_title', $result['meta_key'], 'Test 11.3: meta_key echoed' );
sar_true( $result['staged_at'] > 0, 'Test 11.4: staged_at set' );
sar_eq( array(), get_post_meta( 10, 'sn_og_card_title' ), 'Test 11.5: postmeta untouched — draft queue is NOT postmeta' );
$staged = snt_sn_apply_get_staged_meta( 10, 'sn_og_card_title' );
sar_eq( 'New OG Title', $staged['proposed_value'], 'Test 11.6: staged value retrievable' );
sar_eq( 'fp-abc123', $staged['fingerprint'], 'Test 11.7: fingerprint preserved (opaque, caller-supplied)' );
sar_true( null === snt_sn_apply_get_staged_meta( 10, 'no_such_key' ), 'Test 11.8: absent staged meta returns null, not an error' );

echo "\nTest 12: stage_meta failure paths\n";
_sar_reset();
$result = snt_sn_apply_stage_meta( 999, 'k', 'v' );
sar_true( is_wp_error( $result ), 'Test 12.1: post not found → WP_Error' );
sar_eq( 'snt_sn_apply_post_not_found', $result->get_error_code(), 'Test 12.2: error code' );
_sar_post( 11 );
$result = snt_sn_apply_stage_meta( 11, '  ', 'v' );
sar_true( is_wp_error( $result ), 'Test 12.3: empty meta_key → WP_Error' );
sar_eq( 'snt_sn_apply_invalid_meta_key', $result->get_error_code(), 'Test 12.4: error code' );

// ─── Test 13: restore_revision (acceptance test 8) ───────────────────
echo "\nTest 13: restore_revision actually restores prior state\n";
_sar_reset();
_sar_post( 12 );
$stage = snt_sn_apply_stage_revision( 12, array( 'post_content' => 'Draft content.' ) );
// Live post changes independently after staging (simulating time passing).
$GLOBALS['__test_posts'][12]['post_content'] = 'Some other live edit.';
$restore = snt_sn_apply_restore_revision( $stage['revision_id'] );
sar_true( is_array( $restore ) && ! is_wp_error( $restore ), 'Test 13.1: restore succeeds' );
sar_eq( 12, $restore['post_id'], 'Test 13.2: post_id is the parent' );
sar_eq( 'Draft content.', $GLOBALS['__test_posts'][12]['post_content'], 'Test 13.3: live post_content now holds the RESTORED (staged) value' );

echo "\nTest 14: restore_revision — invalid id → 404\n";
$result = snt_sn_apply_restore_revision( 987654 );
sar_true( is_wp_error( $result ), 'Test 14.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_revision_not_found', $result->get_error_code(), 'Test 14.2: error code' );
sar_eq( 404, $result->data['status'], 'Test 14.3: status 404' );

// ─── Test 15: revision_diff ───────────────────────────────────────────
echo "\nTest 15: revision_diff reports before/after/fields_changed against CURRENT live post\n";
_sar_reset();
_sar_post( 13 );
$stage = snt_sn_apply_stage_revision( 13, array( 'post_content' => 'Proposed body.', 'post_title' => 'Proposed Title' ) );
$diff  = snt_sn_apply_revision_diff( $stage['revision_id'] );
sar_true( is_array( $diff ) && ! is_wp_error( $diff ), 'Test 15.1: returns array' );
sar_eq( 'Original content.', $diff['before']['post_content'], 'Test 15.2: before = live value' );
sar_eq( 'Proposed body.', $diff['after']['post_content'], 'Test 15.3: after = staged value' );
sar_true( in_array( 'post_content', $diff['fields_changed'], true ), 'Test 15.4: post_content flagged changed' );
sar_true( in_array( 'post_title', $diff['fields_changed'], true ), 'Test 15.5: post_title flagged changed' );
sar_true( ! in_array( 'post_excerpt', $diff['fields_changed'], true ), 'Test 15.6: post_excerpt NOT flagged (untouched)' );

echo "\nTest 16: revision_diff — revision not found → 404\n";
$result = snt_sn_apply_revision_diff( 555555 );
sar_true( is_wp_error( $result ), 'Test 16.1: returns WP_Error' );
sar_eq( 'snt_sn_apply_revision_not_found', $result->get_error_code(), 'Test 16.2: error code' );

// ─── Test 17: slash safety — gnarly content survives byte-identical ─────
// Real core runs wp_slash() once ("Since data is from DB") before
// wp_insert_post(), which unslashes on write. This primitive must pass its
// snapshot through RAW — if a future regression pre-slashes it (e.g. calls
// wp_slash() on $proposed before handing it to _wp_put_post_revision()),
// core's own slash pass doubles every backslash/quote and this test REDs.
echo "\nTest 17: gnarly content (literal backslashes, apostrophes, embedded quotes) — byte-identical, no double-slash\n";
_sar_reset();
_sar_post( 14 );
// A PHP single-quoted literal: \' is a literal apostrophe, \\ is a literal
// single backslash. The resulting runtime string contains real backslash
// bytes, real apostrophes, and an embedded double-quote — exactly the
// content a slash/unslash round trip can corrupt if done twice or not at all.
$gnarly = 'It\'s a path C:\\temp — O\'Brien\'s "quote"';
$before_snapshot = $GLOBALS['__test_posts'][14];

$stage = snt_sn_apply_stage_revision( 14, array( 'post_content' => $gnarly ) );
sar_true( is_array( $stage ) && ! is_wp_error( $stage ), 'Test 17.1: stage succeeds' );

$revision_row = $GLOBALS['__test_posts'][ $stage['revision_id'] ];
sar_eq( $gnarly, $revision_row['post_content'], 'Test 17.2: staged revision content is BYTE-IDENTICAL to the proposed string (no double-slash, no unslash loss)' );
sar_eq( 1, substr_count( $revision_row['post_content'], '\\' ), 'Test 17.3: exactly one backslash byte survives — a double-slash regression would show two' );
sar_eq( $before_snapshot, $GLOBALS['__test_posts'][14], 'Test 17.4: the crown jewel extends to gnarly content — live row still byte-identical' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
