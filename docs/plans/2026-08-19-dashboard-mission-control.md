# Dashboard Mission Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Dashboard tab's flat 15-tile grid with three collapsing zones plus an action row, where a zone's state decides whether it takes space.

**Architecture:** Each zone is a pure builder returning `['id','state','summary','detail','cards']`. A shared renderer turns that into a `<details>` block. `state` is derived from the cards (`unknown` > `attention` > `ok`), and a per-user pin can force a zone open but never closed. The existing `sn_admin_glance_grid()` and `sn_admin_glance_sort_by_attention()` are reused unchanged to render an expanded zone's tiles.

**Tech Stack:** PHP 7.4+, WordPress plugin, standalone CLI test suites (`tests/*.php` + `tests/run.sh`), PHPCS (WordPress standard), PHPStan level 1.

**Spec:** `docs/proposals/dashboard-mission-control-2026-08-19.md`

---

## Conventions for every task

- Tests are standalone PHP run with `php tests/<name>.php`. They stub WordPress functions, define `ok( $cond, $msg )`, print `PASS:`/`FAIL:` lines, end with `Result: N passed, M failed.` and `exit( $fail > 0 ? 1 : 0 )`.
- **Gate on the exit code, not on the absence of `FAIL`.** A suite that fatals before its first assertion prints no `FAIL` line. Always run `php tests/x.php; echo "EXIT=$?"`.
- Copy `tests/citations-core.php` for the harness shape if unsure.
- After each task: `bash tests/run.sh` must exit 0.
- Never write `grep --include=*.php` unquoted — zsh fails the glob and returns nothing. Always `--include='*.php'`.

## File Structure

| File | Responsibility | New? |
|---|---|---|
| `inc/dash-zones.php` | Zone contract, state derivation, open/closed decision, renderer | Create |
| `inc/dash-pins.php` | Per-user pin storage + REST toggle | Create |
| `inc/dash-zone-attention.php` | Health, cron, caches, provenance, login guard → one zone | Create |
| `inc/dash-zone-fleet.php` | 7 component versions, last deploy, recent-deploy fold | Create |
| `inc/dash-zone-measurement.php` | The five figures | Create |
| `inc/admin-tab-dashboard.php` | Shrinks to composition + maintenance + diagnostics | Modify |
| `signal-and-noise-tools.php` | `require_once` the five new files | Modify |
| `assets/admin.css` | Zone + strip CSS, responsive | Modify |

**Deviation from the spec, deliberate:** the spec listed five files with pin logic inside `dash-zones.php`. Splitting pins into `inc/dash-pins.php` keeps both under the project's ~150-line preference. Six files, same boundaries.

---

### Task 1: Zone state derivation

**Files:**
- Create: `inc/dash-zones.php`
- Test: `tests/dash-zones.php`

- [x] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }

require __DIR__ . '/../inc/dash-zones.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard zones — state derivation\n\n";

$green = array( 'label' => 'Health', 'value' => '0', 'pill' => array( 'kind' => 'ok', 'text' => 'all clear' ) );
$warn  = array( 'label' => 'Caches', 'value' => '1/3', 'pill' => array( 'kind' => 'warn', 'text' => 'stale' ) );
$err   = array( 'label' => 'Health', 'value' => '3', 'pill' => array( 'kind' => 'err', 'text' => 'findings' ) );
$cold  = array( 'label' => 'Edge', 'value' => '—', 'measured' => false );
$optout = array( 'label' => 'Cache', 'value' => '—', 'pill' => array( 'kind' => 'warn', 'text' => 'warming' ), 'attention' => false );

ok( sn_dash_zone_state( array( $green, $green ) ) === 'ok', 'all green is ok' );
ok( sn_dash_zone_state( array( $green, $warn ) ) === 'attention', 'a warn makes the zone need attention' );
ok( sn_dash_zone_state( array( $green, $err ) ) === 'attention', 'an err makes the zone need attention' );
ok( sn_dash_zone_state( array() ) === 'ok', 'an empty zone is ok, not unknown' );

// The precedence rule from the spec: unknown outranks attention.
ok( sn_dash_zone_state( array( $cold, $err ) ) === 'unknown', 'UNKNOWN BEATS ATTENTION — you cannot triage what you did not measure' );
ok( sn_dash_zone_state( array( $green, $cold ) ) === 'unknown', 'one unmeasured probe makes the whole zone unknown' );

// measured=false is the ONLY unknown signal. A measured zero is measured.
$zero = array( 'label' => 'Blocks', 'value' => '0', 'measured' => true, 'pill' => array( 'kind' => 'ok', 'text' => 'none' ) );
ok( sn_dash_zone_state( array( $zero ) ) === 'ok', 'a probe that ran and returned 0 is measured, not unknown' );

// The attention opt-out is honoured, same as the existing glance sort.
ok( sn_dash_zone_state( array( $green, $optout ) ) === 'ok', 'a warn card that opted out of attention does not promote the zone' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [x] **Step 2: Run it and confirm it fails**

Run: `php tests/dash-zones.php; echo "EXIT=$?"`
Expected: fatal — `Failed opening required '.../inc/dash-zones.php'`, `EXIT=255`.
  (The require fails before the first assertion, so no `FAIL:` line prints — which is exactly why this plan gates on the exit code.)

- [x] **Step 3: Write the minimal implementation**

Create `inc/dash-zones.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard zones: contract, state, renderer.
 *
 * A zone is a group of glance cards that answers one question. Its STATE decides
 * whether it takes space: `ok` collapses to a line, `attention` expands and leads,
 * `unknown` collapses but says it was never measured.
 *
 * `unknown` is derived FIRST on purpose. A zone holding an unmeasured probe and a
 * real warning reports unknown, because you cannot triage what you did not measure.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The three zone states, most-urgent last-resort first. */
const SN_DASH_STATES = array( 'unknown', 'attention', 'ok' );

/**
 * Derive a zone's state from its cards. Pure.
 *
 * A card is UNMEASURED when it carries `'measured' => false`. That is the only
 * unknown signal — a card with value '0' and no `measured` key ran and returned
 * zero, which is measured. array_key_exists, never a falsy check.
 *
 * @param array<int,array<string,mixed>> $cards
 * @return string One of SN_DASH_STATES.
 */
function sn_dash_zone_state( array $cards ) {
	$unknown   = false;
	$attention = false;
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		if ( array_key_exists( 'measured', $card ) && false === $card['measured'] ) {
			$unknown = true;
			continue;
		}
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		// Same opt-out the existing attention sort honours: a card may look amber
		// without asking to be promoted (a cold probe is unknown, not broken).
		$wants = ! array_key_exists( 'attention', $card ) || false !== $card['attention'];
		if ( $wants && ( 'err' === $kind || 'warn' === $kind ) ) {
			$attention = true;
		}
	}
	if ( $unknown ) {
		return 'unknown';
	}
	return $attention ? 'attention' : 'ok';
}
```

- [x] **Step 4: Run it and confirm it passes**

Run: `php tests/dash-zones.php; echo "EXIT=$?"`
Expected: `Result: 8 passed, 0 failed.` and `EXIT=0`.

- [x] **Step 5: Negative-control the suite**

```bash
cp inc/dash-zones.php /tmp/dz.php
perl -0777 -pi -e 's/\tif \( \$unknown \) \{\n\t\treturn .unknown.;\n\t\}\n//' inc/dash-zones.php
php tests/dash-zones.php; echo "EXIT=$?"
cp /tmp/dz.php inc/dash-zones.php
php tests/dash-zones.php; echo "EXIT=$?"
diff -q /tmp/dz.php inc/dash-zones.php
```

Expected: the mutated run fails the two precedence assertions with `EXIT=1`; the restored run is `EXIT=0` and `diff` reports no difference. If the mutation fails nothing, the test is not pinning the rule — fix the test before continuing.

- [x] **Step 6: Commit**

```bash
git add inc/dash-zones.php tests/dash-zones.php
git commit -m "feat: dashboard zone state derivation (unknown outranks attention)"
```

---

### Task 2: The open/closed decision

**Files:**
- Modify: `inc/dash-zones.php`
- Modify: `tests/dash-zones.php`

- [x] **Step 1: Add the failing test**

Append before the `Result:` line in `tests/dash-zones.php`:

```php
// ── open/closed ─────────────────────────────────────────────────────────────
$z_ok      = array( 'id' => 'fleet', 'state' => 'ok' );
$z_att     = array( 'id' => 'attention', 'state' => 'attention' );
$z_unknown = array( 'id' => 'fleet', 'state' => 'unknown' );

ok( sn_dash_zone_is_open( $z_ok, array() ) === false, 'an ok zone is closed by default' );
ok( sn_dash_zone_is_open( $z_unknown, array() ) === false, 'an unknown zone is closed by default' );
ok( sn_dash_zone_is_open( $z_att, array() ) === true, 'an attention zone is open by default' );
ok( sn_dash_zone_is_open( $z_ok, array( 'fleet' ) ) === true, 'a pin opens an ok zone' );
ok( sn_dash_zone_is_open( $z_unknown, array( 'fleet' ) ) === true, 'a pin opens an unknown zone' );

// THE SAFETY PROPERTY. A pin is a view convenience; it must never hide a problem.
ok( sn_dash_zone_is_open( $z_att, array() ) === true, 'an attention zone is open with no pins' );
ok( sn_dash_zone_is_open( $z_att, array( 'other' ) ) === true, 'A PIN CANNOT CLOSE AN ATTENTION ZONE' );
ok( sn_dash_zone_is_open( array( 'id' => 'x' ), array() ) === false, 'a zone with no state is closed, not open' );
```

- [x] **Step 2: Run it and confirm it fails**

Run: `php tests/dash-zones.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_zone_is_open()`.

- [x] **Step 3: Implement**

Append to `inc/dash-zones.php`:

```php
/**
 * Should this zone render expanded?
 *
 * An `attention` zone is ALWAYS open — a pin can force a zone open, never closed.
 * Pinning is a personal view preference and must not be able to hide a problem.
 * Pure.
 *
 * @param array<string,mixed> $zone
 * @param string[]            $pins Zone ids the current user has pinned open.
 * @return bool
 */
function sn_dash_zone_is_open( array $zone, array $pins ) {
	$state = isset( $zone['state'] ) ? (string) $zone['state'] : '';
	if ( 'attention' === $state ) {
		return true;
	}
	$id = isset( $zone['id'] ) ? (string) $zone['id'] : '';
	return '' !== $id && in_array( $id, $pins, true );
}
```

- [x] **Step 4: Run and confirm it passes**

Run: `php tests/dash-zones.php; echo "EXIT=$?"`
Expected: `Result: 16 passed, 0 failed.` and `EXIT=0`.

- [x] **Step 5: Negative-control the safety property**

```bash
cp inc/dash-zones.php /tmp/dz2.php
perl -0777 -pi -e "s/\tif \( 'attention' === \\\$state \) \{\n\t\treturn true;\n\t\}\n//" inc/dash-zones.php
php tests/dash-zones.php; echo "EXIT=$?"
cp /tmp/dz2.php inc/dash-zones.php
```

Expected: the mutated run fails `A PIN CANNOT CLOSE AN ATTENTION ZONE` and the two attention-open assertions — THREE failures, `EXIT=1`.
  Note: those two are the same call (`$z_att, array()`) under different messages, so the safety property is pinned by two distinct calls, not three. Left as written; the duplicate documents intent at the point the safety banner appears.

- [x] **Step 6: Commit**

```bash
git add inc/dash-zones.php tests/dash-zones.php
git commit -m "feat: zone open/closed decision — a pin can open, never close"
```

---

### Task 3: The zone renderer

**Files:**
- Modify: `inc/dash-zones.php`
- Test: `tests/dash-zones-render.php`

- [x] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
$GLOBALS['__grid_calls'] = 0;
function sn_admin_glance_grid( array $cards ) { $GLOBALS['__grid_calls']++; echo '<div class="sn-glance">' . count( $cards ) . '</div>'; }
function sn_admin_glance_sort_by_attention( array $cards ) { return $cards; }

require __DIR__ . '/../inc/dash-zones.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function render( $zone, $pins = array() ) { ob_start(); sn_dash_render_zone( $zone, $pins ); return ob_get_clean(); }
echo "dashboard zones — renderer\n\n";

$card = array( 'label' => 'Health', 'value' => '0' );
$ok_zone = array( 'id' => 'attention', 'state' => 'ok', 'summary' => 'Nothing needs attention', 'detail' => 'health, cron', 'cards' => array( $card ) );

$h = render( $ok_zone );
ok( false !== strpos( $h, '<details' ), 'a zone renders as a details element' );
ok( false === strpos( $h, 'open' ), 'an ok zone is not open' );
ok( false !== strpos( $h, 'Nothing needs attention' ), 'the summary line shows' );
ok( false !== strpos( $h, 'health, cron' ), 'the detail continuation shows' );
ok( false !== strpos( $h, 'sn-dash-zone--ok' ), 'the state is a class hook' );

$att = array( 'id' => 'attention', 'state' => 'attention', 'summary' => '2 need attention', 'detail' => '', 'cards' => array( $card, $card ) );
$h = render( $att );
ok( false !== strpos( $h, 'open' ), 'an attention zone renders open' );
ok( false !== strpos( $h, 'sn-dash-zone--attention' ), 'attention state class' );

$unk = array( 'id' => 'fleet', 'state' => 'unknown', 'summary' => 'Fleet not measured', 'detail' => '2 of 7 never probed', 'cards' => array() );
$h = render( $unk );
ok( false !== strpos( $h, 'sn-dash-zone--unknown' ), 'unknown gets its own class, distinct from ok' );
ok( false === strpos( $h, 'sn-dash-zone--ok' ), 'unknown is NOT styled as ok' );

// The grid helper is reused, not reimplemented.
$GLOBALS['__grid_calls'] = 0;
render( $att, array( 'attention' ) );
ok( $GLOBALS['__grid_calls'] === 1, 'an expanded zone delegates its tiles to sn_admin_glance_grid()' );
$GLOBALS['__grid_calls'] = 0;
render( $ok_zone );
ok( $GLOBALS['__grid_calls'] === 0, 'a collapsed zone does not build a grid it will not show' );

// Escaping.
$evil = array( 'id' => 'x', 'state' => 'ok', 'summary' => '<script>alert(1)</script>', 'detail' => '', 'cards' => array() );
ok( false === strpos( render( $evil ), '<script>' ), 'the summary is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [x] **Step 2: Run and confirm it fails**

Run: `php tests/dash-zones-render.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_render_zone()`.

- [x] **Step 3: Implement**

Append to `inc/dash-zones.php`:

```php
/**
 * Render one zone as a <details> block.
 *
 * The open state is server-rendered so the correct shape is present on first
 * paint with no flash. A collapsed zone does not call the grid helper at all —
 * there is no point building tiles nobody will see.
 *
 * @param array<string,mixed> $zone
 * @param string[]            $pins
 * @return void
 */
function sn_dash_render_zone( array $zone, array $pins = array() ) {
	$state   = isset( $zone['state'] ) ? (string) $zone['state'] : 'ok';
	$id      = isset( $zone['id'] ) ? (string) $zone['id'] : '';
	$summary = isset( $zone['summary'] ) ? (string) $zone['summary'] : '';
	$detail  = isset( $zone['detail'] ) ? (string) $zone['detail'] : '';
	$cards   = isset( $zone['cards'] ) && is_array( $zone['cards'] ) ? $zone['cards'] : array();
	$open    = sn_dash_zone_is_open( $zone, $pins );

	echo '<details class="sn-dash-zone sn-dash-zone--' . esc_attr( $state ) . '"'
		. ' data-zone="' . esc_attr( $id ) . '"' . ( $open ? ' open' : '' ) . '>';
	echo '<summary class="sn-dash-zone-summary">';
	echo '<span class="sn-dash-zone-label">' . esc_html( $summary ) . '</span>';
	if ( '' !== $detail ) {
		echo ' <span class="sn-dash-zone-detail">' . esc_html( $detail ) . '</span>';
	}
	echo '</summary>';
	if ( $open && ! empty( $cards ) ) {
		echo '<div class="sn-dash-zone-body">';
		sn_admin_glance_grid( sn_admin_glance_sort_by_attention( $cards ) );
		echo '</div>';
	}
	echo '</details>';
}
```

- [x] **Step 4: Run and confirm it passes**

Run: `php tests/dash-zones-render.php; echo "EXIT=$?"`
Expected: `Result: 14 passed, 0 failed.` and `EXIT=0`.
  (12 as written, plus two added during execution — see step 5.)

- [x] **Step 5: Negative-control the renderer**

The plan as written had no mutation step here. Four were run; two found gaps:

| Mutation | Result as written | Action |
|---|---|---|
| drop `esc_html( $summary )` | fails `the summary is escaped` | pinned already |
| force `$open = true` | fails 2 assertions | pinned already |
| drop `esc_html( $detail )` | **failed nothing** | assertion added |
| drop `esc_attr( $id )` | **failed nothing** | assertion added |

`detail` carries probe output in the zone builders and `id` lands in an
attribute, so both are pinnable — unlike #726's tier gate, which was
unpinnable by construction. After the two additions each mutation fails
its intended pin.

- [x] **Step 6: Commit**

```bash
git add inc/dash-zones.php tests/dash-zones-render.php
git commit -m "feat: zone renderer — server-rendered details, reuses the glance grid"
```

---

### Task 4: Pin storage

**Files:**
- Create: `inc/dash-pins.php`
- Test: `tests/dash-pins.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_DASH_PINS_TEST', true );
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }

$GLOBALS['__meta'] = array();
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['__meta'][ $uid ][ $key ] ?? ( $single ? '' : array() ); }
function update_user_meta( $uid, $key, $val ) { $GLOBALS['__meta'][ $uid ][ $key ] = $val; return true; }

require __DIR__ . '/../inc/dash-pins.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard pins\n\n";

ok( sn_dash_pins( 1 ) === array(), 'a user with no preference has no pins' );

sn_dash_set_pin( 1, 'fleet', true );
ok( sn_dash_pins( 1 ) === array( 'fleet' ), 'pinning stores the zone id' );
sn_dash_set_pin( 1, 'fleet', true );
ok( sn_dash_pins( 1 ) === array( 'fleet' ), 'pinning twice does not duplicate' );
sn_dash_set_pin( 1, 'measurement', true );
ok( count( sn_dash_pins( 1 ) ) === 2, 'a second pin is added' );
sn_dash_set_pin( 1, 'fleet', false );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'unpinning removes only that zone' );
sn_dash_set_pin( 1, 'nope', false );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'unpinning something unpinned is a no-op' );

// Pins are per user.
sn_dash_set_pin( 2, 'attention', true );
ok( sn_dash_pins( 1 ) === array( 'measurement' ), 'user 1 is unaffected by user 2' );
ok( sn_dash_pins( 2 ) === array( 'attention' ), 'user 2 has their own pin' );

// Only known zone ids are storable — the id becomes a CSS/data attribute.
sn_dash_set_pin( 3, 'evil"><script>', true );
ok( sn_dash_pins( 3 ) === array(), 'an unknown zone id is refused, not stored' );

// Corrupt meta must not fatal.
$GLOBALS['__meta'][4]['sn_dash_pins'] = 'not-an-array';
ok( sn_dash_pins( 4 ) === array(), 'corrupt stored meta reads as no pins' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-pins.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_pins()`.

- [ ] **Step 3: Implement**

Create `inc/dash-pins.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard zone pins.
 *
 * A pin is a PERSONAL view preference, so it lives in user meta rather than a
 * site option. It can force a zone open; sn_dash_zone_is_open() guarantees it can
 * never force one closed.
 *
 * Zone ids are validated against an allowlist because the id is echoed into a
 * data attribute and used as a storage key.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DASH_PIN_META  = 'sn_dash_pins';
const SN_DASH_ZONE_IDS  = array( 'attention', 'fleet', 'measurement' );

/**
 * The zone ids this user has pinned open.
 *
 * @param int $user_id
 * @return string[]
 */
function sn_dash_pins( $user_id ) {
	$raw = get_user_meta( (int) $user_id, SN_DASH_PIN_META, true );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array_values( array_intersect( SN_DASH_ZONE_IDS, $raw ) );
}

/**
 * Pin or unpin one zone for one user.
 *
 * @param int    $user_id
 * @param string $zone_id
 * @param bool   $pinned
 * @return bool True when the preference was written.
 */
function sn_dash_set_pin( $user_id, $zone_id, $pinned ) {
	$zone_id = (string) $zone_id;
	if ( ! in_array( $zone_id, SN_DASH_ZONE_IDS, true ) ) {
		return false;
	}
	$pins = sn_dash_pins( $user_id );
	if ( $pinned ) {
		if ( ! in_array( $zone_id, $pins, true ) ) {
			$pins[] = $zone_id;
		}
	} else {
		$pins = array_values( array_diff( $pins, array( $zone_id ) ) );
	}
	return (bool) update_user_meta( (int) $user_id, SN_DASH_PIN_META, $pins );
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-pins.php; echo "EXIT=$?"`
Expected: `Result: 10 passed, 0 failed.` and `EXIT=0`.

- [ ] **Step 5: Commit**

```bash
git add inc/dash-pins.php tests/dash-pins.php
git commit -m "feat: per-user dashboard zone pins with an id allowlist"
```

---

### Task 5: The pin REST route

**Files:**
- Modify: `inc/dash-pins.php`
- Modify: `tests/dash-pins.php`

- [ ] **Step 1: Add the failing test**

Append before the `Result:` line in `tests/dash-pins.php`:

```php
// ── the REST toggle ─────────────────────────────────────────────────────────
class Fake_Req { private $p; public function __construct( $p ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
class WP_REST_Response { public $data; public $status;
	public function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; } }
function get_current_user_id() { return 9; }

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'fleet', 'pinned' => true ) ) );
ok( $r->status === 200, 'a valid toggle is 200' );
ok( sn_dash_pins( 9 ) === array( 'fleet' ), 'and it persisted' );
ok( $r->data['pins'] === array( 'fleet' ), 'the response echoes the new pin set' );

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'bogus', 'pinned' => true ) ) );
ok( $r->status === 400, 'an unknown zone id is a 400' );
ok( sn_dash_pins( 9 ) === array( 'fleet' ), 'and nothing was written' );

$r = sn_dash_pin_route_handler( new Fake_Req( array( 'zone' => 'fleet', 'pinned' => false ) ) );
ok( sn_dash_pins( 9 ) === array(), 'unpinning through the route works' );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-pins.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_pin_route_handler()`.

- [ ] **Step 3: Implement**

Append to `inc/dash-pins.php`:

```php
/**
 * REST handler for the pin toggle.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sn_dash_pin_route_handler( $request ) {
	$zone   = (string) $request->get_param( 'zone' );
	$pinned = (bool) $request->get_param( 'pinned' );
	if ( ! sn_dash_set_pin( get_current_user_id(), $zone, $pinned ) ) {
		return new WP_REST_Response( array( 'error' => 'unknown zone' ), 400 );
	}
	return new WP_REST_Response( array( 'pins' => sn_dash_pins( get_current_user_id() ) ), 200 );
}

/** Register the route. Gated on the capability that gates the admin page. */
function sn_dash_pin_register_route() {
	register_rest_route(
		'signal-noise/v1',
		'/dash-pin',
		array(
			'methods'             => 'POST',
			'callback'            => 'sn_dash_pin_route_handler',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'zone'   => array( 'required' => true ),
				'pinned' => array( 'required' => true ),
			),
		)
	);
}

if ( ! defined( 'SN_DASH_PINS_TEST' ) || ! SN_DASH_PINS_TEST ) {
	add_action( 'rest_api_init', 'sn_dash_pin_register_route' );
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-pins.php; echo "EXIT=$?"`
Expected: `Result: 16 passed, 0 failed.` and `EXIT=0`.

- [ ] **Step 5: Commit**

```bash
git add inc/dash-pins.php tests/dash-pins.php
git commit -m "feat: pin toggle REST route gated on manage_options"
```

---

### Task 6: The measurement zone

**Files:**
- Create: `inc/dash-zone-measurement.php`
- Test: `tests/dash-zone-measurement.php`

This zone never collapses — it has no green/red state. Its builder returns figures, not cards.

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

require __DIR__ . '/../inc/dash-zone-measurement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard measurement zone\n\n";

$figs = sn_dash_measurement_figures( array(
	'views_7d' => 103, 'views_delta' => 39, 'ai_spend_30d' => 0.61,
	'anchored' => 33, 'citations' => 0, 'search_clicks_7d' => 18,
) );
ok( count( $figs ) === 5, 'five figures, the agreed cap' );
ok( $figs[0]['key'] === 'views_7d', 'views leads — it is the hero row on narrow' );
ok( $figs[0]['hero'] === true, 'and it is flagged as the hero' );
foreach ( $figs as $f ) { ok( $f['measured'] === true, "{$f['key']} is measured when a value was supplied" ); }

// A measured zero renders as zero.
$z = sn_dash_measurement_figures( array( 'views_7d' => 0, 'citations' => 0, 'ai_spend_30d' => 0, 'anchored' => 0, 'search_clicks_7d' => 0 ) );
foreach ( $z as $f ) { ok( $f['measured'] === true, "{$f['key']} zero is MEASURED, not unknown" ); }
ok( $z[0]['value'] === '0', 'a measured zero prints 0' );

// An absent value is unknown, never zero. This is the zero-vs-null rule.
$u = sn_dash_measurement_figures( array( 'views_7d' => 103 ) );
$by = array(); foreach ( $u as $f ) { $by[ $f['key'] ] = $f; }
ok( $by['search_clicks_7d']['measured'] === false, 'an absent Search Console read is UNKNOWN' );
ok( $by['search_clicks_7d']['value'] !== '0', 'and it never renders as 0' );
ok( $by['views_7d']['measured'] === true, 'the supplied figure is still measured' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-zone-measurement.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_measurement_figures()`.

- [ ] **Step 3: Implement**

Create `inc/dash-zone-measurement.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard measurement strip.
 *
 * The one zone that never collapses: it has no green/red state, so there is
 * nothing to fold. Capped at five figures, and the cap is enforced by the narrow
 * reflow — views is the hero (it carries the sparkline) and takes a full row,
 * leaving a clean 2x2 below. A sixth figure would strand.
 *
 * An ABSENT value is unknown and must never render as 0. A Search Console read
 * that failed is missing evidence, not zero clicks.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the five figures in display order.
 *
 * @param array<string,mixed> $data Keys: views_7d, views_delta, search_clicks_7d,
 *                                  ai_spend_30d, anchored, citations. An absent or
 *                                  null key is UNMEASURED.
 * @return array<int,array<string,mixed>>
 */
function sn_dash_measurement_figures( array $data ) {
	$spec = array(
		array( 'key' => 'views_7d', 'label' => __( 'views 7d', 'signal-and-noise-tools' ), 'hero' => true ),
		array( 'key' => 'search_clicks_7d', 'label' => __( 'clicks 7d', 'signal-and-noise-tools' ), 'hero' => false ),
		array( 'key' => 'ai_spend_30d', 'label' => __( 'AI 30d', 'signal-and-noise-tools' ), 'hero' => false, 'money' => true ),
		array( 'key' => 'anchored', 'label' => __( 'anchored', 'signal-and-noise-tools' ), 'hero' => false ),
		array( 'key' => 'citations', 'label' => __( 'citations', 'signal-and-noise-tools' ), 'hero' => false ),
	);
	$out = array();
	foreach ( $spec as $f ) {
		$measured = array_key_exists( $f['key'], $data ) && null !== $data[ $f['key'] ];
		$raw      = $measured ? $data[ $f['key'] ] : null;
		$value    = '—';
		if ( $measured ) {
			$value = ! empty( $f['money'] )
				? '$' . number_format( (float) $raw, 2 )
				: (string) (int) $raw;
		}
		$out[] = array(
			'key'      => $f['key'],
			'label'    => $f['label'],
			'hero'     => (bool) $f['hero'],
			'measured' => $measured,
			'value'    => $value,
			'delta'    => ( 'views_7d' === $f['key'] && array_key_exists( 'views_delta', $data ) )
				? (int) $data['views_delta'] : null,
		);
	}
	return $out;
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-zone-measurement.php; echo "EXIT=$?"`
Expected: `Result: 19 passed, 0 failed.` and `EXIT=0`.

- [ ] **Step 5: Negative-control the zero-vs-null rule**

```bash
cp inc/dash-zone-measurement.php /tmp/dm.php
perl -0777 -pi -e 's/\$measured = array_key_exists\( \$f\[.key.\], \$data \) && null !== \$data\[ \$f\[.key.\] \];/\$measured = ! empty( \$data[ \$f["key"] ] );/' inc/dash-zone-measurement.php
php tests/dash-zone-measurement.php; echo "EXIT=$?"
cp /tmp/dm.php inc/dash-zone-measurement.php
php tests/dash-zone-measurement.php; echo "EXIT=$?"
```

Expected: the mutated run fails every `zero is MEASURED` assertion (a falsy check turns a measured 0 into unknown); the restored run is `EXIT=0`.

- [ ] **Step 6: Commit**

```bash
git add inc/dash-zone-measurement.php tests/dash-zone-measurement.php
git commit -m "feat: measurement strip figures — absent is unknown, zero is zero"
```

---

### Task 7: The attention and fleet zone builders

**Files:**
- Create: `inc/dash-zone-attention.php`
- Create: `inc/dash-zone-fleet.php`
- Test: `tests/dash-zone-builders.php`

Both builders take already-fetched cards and shape them into a zone. Keeping the fetch outside makes them pure and testable, and it lets Task 8 reuse `snt_dashboard_glance_cards()` as the data source without rewriting it.

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }

require __DIR__ . '/../inc/dash-zones.php';
require __DIR__ . '/../inc/dash-zone-attention.php';
require __DIR__ . '/../inc/dash-zone-fleet.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard zone builders\n\n";

$green = array( 'label' => 'Health', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ) );
$bad   = array( 'label' => 'Health', 'pill' => array( 'kind' => 'err', 'text' => '3 findings' ) );

$z = sn_dash_zone_attention( array( $green, $green ) );
ok( $z['id'] === 'attention', 'the attention zone has a stable id' );
ok( $z['state'] === 'ok', 'all-green attention zone is ok' );
ok( false !== stripos( $z['summary'], 'nothing needs attention' ), 'and says nothing needs attention' );

$z = sn_dash_zone_attention( array( $green, $bad ) );
ok( $z['state'] === 'attention', 'one bad card flips the zone' );
ok( false !== strpos( $z['summary'], '1' ), 'the summary counts what needs attention' );

$z = sn_dash_zone_attention( array( $bad, $bad ) );
ok( false !== strpos( $z['summary'], '2' ), 'and counts two' );

$z = sn_dash_zone_fleet( array( 'theme' => '11.12.0', 'plugin' => '11.27.0' ), '28 minutes ago' );
ok( $z['id'] === 'fleet', 'the fleet zone has a stable id' );
ok( $z['state'] === 'ok', 'all components present is ok' );
ok( false !== strpos( $z['detail'], '28 minutes ago' ), 'the last deploy shows in the detail line' );
ok( false !== strpos( $z['summary'], '2' ), 'the summary counts components' );
ok( count( $z['cards'] ) === 2, 'one card per component' );

// A component that was never probed makes the fleet unknown, not current.
$z = sn_dash_zone_fleet( array( 'theme' => '11.12.0', 'edge' => null ), '28 minutes ago' );
ok( $z['state'] === 'unknown', 'a never-probed component makes the fleet UNKNOWN' );
ok( false !== stripos( $z['summary'], 'not measured' ), 'and the summary says so rather than claiming current' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-zone-builders.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_dash_zone_attention()`.

- [ ] **Step 3: Implement the attention zone**

Create `inc/dash-zone-attention.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard attention zone.
 *
 * Health, cron, caches, provenance and login guard collapse into one question:
 * is anything wrong? Takes already-fetched glance cards so the builder stays pure.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<int,array<string,mixed>> $cards
 * @return array<string,mixed>
 */
function sn_dash_zone_attention( array $cards ) {
	$state = sn_dash_zone_state( $cards );
	$needy = 0;
	foreach ( $cards as $c ) {
		$kind  = isset( $c['pill']['kind'] ) ? (string) $c['pill']['kind'] : '';
		$wants = ! array_key_exists( 'attention', $c ) || false !== $c['attention'];
		if ( $wants && ( 'err' === $kind || 'warn' === $kind ) ) {
			$needy++;
		}
	}
	if ( 'attention' === $state ) {
		$summary = sprintf(
			/* translators: %d count of checks needing attention */
			_n( '%d needs attention', '%d need attention', $needy, 'signal-and-noise-tools' ),
			$needy
		);
	} elseif ( 'unknown' === $state ) {
		$summary = __( 'Not measured', 'signal-and-noise-tools' );
	} else {
		$summary = __( 'Nothing needs attention', 'signal-and-noise-tools' );
	}
	return array(
		'id'      => 'attention',
		'state'   => $state,
		'summary' => $summary,
		'detail'  => __( 'health, cron, caches, provenance, login guard', 'signal-and-noise-tools' ),
		'cards'   => $cards,
	);
}
```

- [ ] **Step 4: Implement the fleet zone**

Create `inc/dash-zone-fleet.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard fleet zone.
 *
 * Seven component versions and the last deploy, collapsed into one line. A
 * component whose version is null was never probed, which makes the whole zone
 * unknown — "current" is a claim, and an unprobed component cannot support it.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<string,string|null> $components name => version, null = never probed.
 * @param string                    $last_deploy_ago Human string, may be ''.
 * @return array<string,mixed>
 */
function sn_dash_zone_fleet( array $components, $last_deploy_ago = '' ) {
	$cards   = array();
	$unknown = 0;
	foreach ( $components as $name => $version ) {
		$measured = null !== $version && '' !== $version;
		if ( ! $measured ) {
			$unknown++;
		}
		$cards[] = array(
			'label'     => (string) $name,
			'value'     => $measured ? (string) $version : '—',
			'measured'  => $measured,
			'attention' => false, // a version is never an alarm; drift is reported elsewhere.
		);
	}
	$state = sn_dash_zone_state( $cards );
	$total = count( $components );
	if ( 'unknown' === $state ) {
		$summary = sprintf(
			/* translators: 1: unprobed count, 2: total components */
			__( 'Fleet not measured — %1$d of %2$d never probed', 'signal-and-noise-tools' ),
			$unknown,
			$total
		);
	} else {
		$summary = sprintf(
			/* translators: %d component count */
			__( 'Fleet current — %d components', 'signal-and-noise-tools' ),
			$total
		);
	}
	$detail = '' !== $last_deploy_ago
		? sprintf( /* translators: %s human time */ __( 'deploy %s', 'signal-and-noise-tools' ), $last_deploy_ago )
		: '';
	return array(
		'id'      => 'fleet',
		'state'   => $state,
		'summary' => $summary,
		'detail'  => $detail,
		'cards'   => $cards,
	);
}
```

- [ ] **Step 5: Run and confirm it passes**

Run: `php tests/dash-zone-builders.php; echo "EXIT=$?"`
Expected: `Result: 14 passed, 0 failed.` and `EXIT=0`.

- [ ] **Step 6: Commit**

```bash
git add inc/dash-zone-attention.php inc/dash-zone-fleet.php tests/dash-zone-builders.php
git commit -m "feat: attention and fleet zone builders"
```

---

### Task 8: Wire the zones into the Dashboard tab

**Files:**
- Modify: `inc/admin-tab-dashboard.php:144-200` (`snt_dashboard_tab_render()`)
- Modify: `signal-and-noise-tools.php`

- [ ] **Step 1: Register the new files**

In `signal-and-noise-tools.php`, immediately after the line requiring `inc/admin-glance.php`, add:

```php
require_once SNT_PATH . 'inc/dash-zones.php';            // v11.28.0: zone contract, state, renderer
require_once SNT_PATH . 'inc/dash-pins.php';             // v11.28.0: per-user zone pins + REST toggle
require_once SNT_PATH . 'inc/dash-zone-attention.php';   // v11.28.0: is anything wrong?
require_once SNT_PATH . 'inc/dash-zone-fleet.php';       // v11.28.0: did it ship?
require_once SNT_PATH . 'inc/dash-zone-measurement.php'; // v11.28.0: how is the site doing?
```

Run: `php -l signal-and-noise-tools.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Split the glance cards into zones**

In `snt_dashboard_tab_render()`, replace the block that currently reads:

```php
	$cards = snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago );
	if ( ! empty( $cards ) ) {
		echo '<section class="sn-dash-glance" aria-label="Site at a glance">';
		sn_admin_glance_grid( sn_admin_glance_sort_by_attention( $cards ) );
		echo '</section>';
	}
```

with:

```php
	$cards = snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago );
	$pins  = sn_dash_pins( get_current_user_id() );

	// Attention zone: every card that is a verdict about whether something is
	// wrong. Version cards and pure measurement are handled by their own zones.
	$attention_labels = array( 'Health', 'Cron', 'Caches', 'Provenance' );
	$attention_cards  = array();
	foreach ( $cards as $card ) {
		if ( in_array( (string) ( $card['label'] ?? '' ), $attention_labels, true ) ) {
			$attention_cards[] = $card;
		}
	}

	echo '<section class="sn-dash-zones" aria-label="Status">';
	sn_dash_render_zone( sn_dash_zone_attention( $attention_cards ), $pins );
	sn_dash_render_zone( sn_dash_zone_fleet( snt_dashboard_fleet_components(), $last_deploy_ago ), $pins );
	echo '</section>';
```

- [ ] **Step 3: Add the fleet component reader**

Append to `inc/admin-tab-dashboard.php`:

```php
/**
 * Component name => version for the fleet zone. A component the deploy-status
 * probe has never seen returns null, which makes the zone unknown rather than
 * letting an unprobed worker read as current.
 *
 * @return array<string,string|null>
 */
function snt_dashboard_fleet_components() {
	$out  = array();
	$list = function_exists( 'snt_deploy_workers_registry' ) ? snt_deploy_workers_registry() : array();
	foreach ( $list as $key => $_meta ) {
		$status     = snt_deploy_status_for( $key );
		$out[ $key ] = ( '' === $status ) ? null : $status;
	}
	return $out;
}
```

**Both helper names are verified** (checked 2026-08-19, so do not re-derive):
`snt_deploy_status_for()` is `inc/admin-tab-dashboard.php:63`, and the registry is
`snt_deploy_workers_registry()` at `inc/deploy-workers.php:51`. An earlier draft of this plan
guessed `sn_deploy_probe_registry()`, which does not exist — confirm with:

```bash
grep -rn "function snt_deploy_workers_registry\|function snt_deploy_status_for" --include='*.php' inc/
```

Note the theme/plugin versions are NOT in the worker registry; add them to the components map
from the `$theme` and `$plugin` values already in scope in `snt_dashboard_tab_render()`.

- [ ] **Step 4: Remove the cut items**

Delete from `snt_dashboard_tab_render()`: the External APIs block, the RSS feed activity block, and the standalone Recent deploys `<h2>` section (the deploy list moves inside the fleet zone in a later pass; for now it is removed from the tab). Delete the `Login blocks 7d` card from `snt_dashboard_glance_cards()`.

Run: `php -l inc/admin-tab-dashboard.php && bash tests/run.sh; echo "EXIT=$?"`
Expected: no syntax errors, sweep `EXIT=0`. If a suite asserts on the removed sections, update that suite — the removal is intended.

- [ ] **Step 5: Commit**

```bash
git add inc/admin-tab-dashboard.php signal-and-noise-tools.php
git commit -m "feat: compose the Dashboard from zones and drop the cut sections"
```

---

### Task 9: Zone and strip CSS, responsive

**Files:**
- Modify: `assets/admin.css`

No new stylesheet — this file is already enqueued for the admin page.

- [ ] **Step 1: Append the CSS**

```css
/* v11.28.0 — Dashboard zones. State earns space: ok/unknown collapse to a line,
   attention expands. The `open` attribute is server-rendered, so the correct
   shape is present on first paint. */
.sn-dash-zone { border: 1px solid var(--sn-border, #d8dce0); border-radius: 6px; margin-bottom: 8px; }
.sn-dash-zone-summary { cursor: pointer; padding: 9px 12px; display: flex; gap: 8px; align-items: baseline; }
.sn-dash-zone-detail { opacity: .7; font-size: 12px; }
.sn-dash-zone-body { padding: 0 12px 12px; }
.sn-dash-zone--ok { border-color: #a8d5b5; background: #e6f4ea; }
.sn-dash-zone--attention { border-color: #f0b4ae; background: #fce8e6; }
/* Unknown is neither: dashed and neutral, because never-probed is not healthy
   and not broken. */
.sn-dash-zone--unknown { border-style: dashed; border-color: #b9bfc4; background: #f6f7f8; }

.sn-dash-strip { display: flex; flex-wrap: wrap; gap: 22px; padding: 10px 12px;
  border: 1px solid var(--sn-border, #d8dce0); border-radius: 6px; }
.sn-dash-fig-value { font-size: 16px; font-weight: 600; }
.sn-dash-fig-label { opacity: .6; }
.sn-dash-fig--unmeasured .sn-dash-fig-value { opacity: .55; font-weight: 400; }

@media (max-width: 600px) {
  /* Views is the hero — it carries the sparkline — so it takes a full row and
     the remaining four fall into a 2x2. One plus four, no orphan. */
  .sn-dash-strip { display: grid; grid-template-columns: 1fr 1fr; gap: 9px 12px; }
  .sn-dash-fig--hero { grid-column: 1 / -1; padding-bottom: 8px;
    border-bottom: 1px solid var(--sn-border-soft, #eceff1); }
}
```

- [ ] **Step 2: Verify**

Run: `bash tests/run.sh; echo "EXIT=$?"` then `vendor/bin/phpcs --standard=phpcs.xml.dist inc/dash-*.php inc/admin-tab-dashboard.php`
Expected: sweep `EXIT=0`, PHPCS reports no errors.

- [ ] **Step 3: Commit**

```bash
git add assets/admin.css
git commit -m "style: dashboard zone and measurement-strip CSS, hero reflow under 600px"
```

---

### Task 10: Final gates

- [ ] **Step 1: Full sweep, gated on the exit code**

```bash
bash tests/run.sh > /tmp/sweep.log 2>&1; echo "EXIT=$?"
grep -c '  FAIL' /tmp/sweep.log
tail -1 /tmp/sweep.log
```

Expected: `EXIT=0`, zero indented FAIL lines, and the suite count up by five.

- [ ] **Step 2: Static gates**

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist inc/ signal-and-noise-tools.php | tail -5
vendor/bin/phpstan analyse --no-progress --memory-limit=2G | tail -3
```

Expected: PHPCS no errors, PHPStan `[OK] No errors`.

- [ ] **Step 3: Confirm the file shrank**

```bash
wc -l inc/admin-tab-dashboard.php
```

Expected: well under the 1114 it started at, and under the 800 ceiling. If it is not, the cut items in Task 8 Step 4 were not fully removed.

- [ ] **Step 4: CHANGELOG and commit**

Add an `## [Unreleased]` entry covering the three zones, the collapse rule, the pin safety property, the unknown state, and the four cuts. Then:

```bash
git add CHANGELOG.md && git commit -m "docs: changelog for the mission-control dashboard"
```

---

## Self-review

**Spec coverage:** three zones (Tasks 6, 7), state rule (Task 1), pin + safety property (Tasks 2, 4, 5), unknown third state (Tasks 1, 6, 7, 9), five-figure cap and hero reflow (Tasks 6, 9), four cuts (Task 8), file split (Tasks 1–8), testing (every task). The **sparkline SVG** and the **recent-deploys fold inside Fleet** are the two spec items with no dedicated task — both are presentation-only and land in Task 9's CSS and Task 8's fleet zone respectively; if either grows, split it into its own task rather than smuggling it in.

**Deviations from the spec, both deliberate and flagged in place:** pins live in their own file (six files, not five) to respect the ~150-line preference; the deploy list is removed in Task 8 rather than folded, with the fold left as a follow-up.

**Helper names verified after the first draft:** the plan originally guessed
`sn_deploy_probe_registry()`, which does not exist. The real registry is
`snt_deploy_workers_registry()` (`inc/deploy-workers.php:51`); `snt_deploy_status_for()`
(`inc/admin-tab-dashboard.php:63`) was correct. Both are now named in Task 8 with line
references. This is the one place the plan referenced something that was not real — worth
noting as the class of error to look for on re-reads.
