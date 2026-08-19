# Dashboard Metabox Console Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Dashboard tab into a WordPress metabox host, so drag-to-reorder, collapse and Screen Options show/hide come from core — and the page is dense and arrangeable instead of three grey lines above a maintenance panel.

**Architecture:** `snt_dashboard_tab_render()` stops rendering content and becomes a shell. Boxes are registered on `load-{$hook_suffix}` (NOT at render time — see the timing trap below), each box callback is a thin wrapper over an existing pure builder, and all of them read from one memoized `snt_dashboard_snapshot()` so box order can never change behaviour.

**Tech Stack:** PHP 7.4+, WordPress metabox/postbox APIs, standalone CLI test suites (`tests/*.php` + `tests/run.sh`), PHPCS (WordPress standard), PHPStan level 1.

**Spec:** `docs/proposals/dashboard-metabox-console-2026-08-19.md`

---

## Conventions for every task

- Tests are standalone PHP run with `php tests/<name>.php`. They stub WordPress functions, define `ok( $cond, $msg )`, print `PASS:`/`FAIL:` lines, end with `Result: N passed, M failed.` and `exit( $fail > 0 ? 1 : 0 )`.
- **Gate on the exit code, never on a predicted assertion count.** The previous plan for this feature stated expected counts three times and was wrong three times, which trains a reader to shrug at a count mismatch — the one signal that catches a silently skipped assertion. This plan states `EXIT=0` and `0 failed` and never predicts a total.
- **`bash tests/run.sh` summarises per-suite results; it does not echo `FAIL` lines.** `grep -c '  FAIL'` on its output reads zero whether or not suites are failing. Gate on `echo $?` and on `grep '^ERROR'`.
- **A mutation killed by a fatal is not a pinned property.** If a mutation produces `EXIT=255` with no `FAIL:` line, the harness is missing a stub — fix the harness so the mutation fails a real assertion.
- After each task: `bash tests/run.sh; echo "EXIT=$?"` must print `EXIT=0`.
- Never write `grep --include=*.php` unquoted — zsh fails the glob. Always `--include='*.php'`.

## The three traps this plan exists to avoid

1. **Registration timing.** `do_action( "load-{$page_hook}" )` fires, THEN `admin-header.php` renders Screen Options, THEN the page callback runs (verified against `wp-admin/admin.php`). Register boxes in the render callback and `$wp_meta_boxes` is empty when Screen Options draws — the checkboxes silently never appear.
2. **The active tab is not `$_GET['tab']`.** `inc/admin-page.php:51-60` resolves it in three steps: explicit `?tab=`, else derive from `?page=` via `sn_admin_page_tab_for_slug()`, else default `dashboard`. The Dashboard is most commonly reached with **no `tab` param at all**. Task 1 extracts that resolution so registration and rendering cannot disagree.
3. **The nonce/action name mismatch.** AJAX actions are `closed-postboxes` and `meta-box-order`; the nonce action for the first is `closedpostboxes` — hyphen in the action, none in the nonce. Mismatching them breaks persistence *silently*: dragging works, the state never returns.

## Verified core contract

Checked against WP source 2026-08-19. Do not re-derive.

| Concern | Value |
|---|---|
| Collapsed boxes | user meta `closedpostboxes_{page}` |
| Hidden boxes | user meta `metaboxhidden_{page}` |
| Box order | user meta `meta-box-order_{page}` |
| Column count | user meta `screen_layout_{page}` |
| Nonce actions | `closedpostboxes`, `meta-box-order` |
| Script handle | `postbox` |
| JS init | `postboxes.add_postbox_toggles( page, args )` |
| Screen Options gate | `render_meta_boxes_preferences()` early-returns unless `isset( $wp_meta_boxes[ $screen->id ] )` |

## File Structure

| File | Responsibility | New? |
|---|---|---|
| `inc/admin-page.php` | Gains `sn_admin_page_active_tab()`; the render fn calls it | Modify |
| `inc/dash-console.php` | Snapshot, box registration, the `#poststuff` shell | Create |
| `inc/dash-boxes.php` | The six box callbacks + the title-with-state-dot helper | Create |
| `inc/dash-briefing.php` | The briefing sentence (pure) + its band renderer | Create |
| `inc/dash-zone-fleet.php` | Warming vs failed distinction | Modify |
| `inc/admin-tab-dashboard.php` | Shrinks to the shell call | Modify |
| `inc/dash-zones.php` | Loses `sn_dash_zone_is_open()` + `sn_dash_render_zone()` | Modify |
| `inc/dash-pins.php` | Deleted — core replaces it | Delete |
| `assets/admin.css` | Box internals; postbox chrome is core's | Modify |
| `signal-and-noise-tools.php` | Requires the three new files, drops `dash-pins.php` | Modify |

---

### Task 1: One source of truth for the active tab

The resolution currently lives inline inside the page renderer, so registration (on `load-`) and rendering cannot both use it. Extract it first — everything else depends on this.

**Files:**
- Modify: `inc/admin-page.php`
- Test: `tests/admin-active-tab.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }

// The two collaborators the resolver calls. Modelled on their REAL shapes:
// valid_tabs returns a flat list of slugs; tab_for_slug maps a ?page= slug.
function sn_admin_page_valid_tabs() { return array( 'dashboard', 'site', 'content', 'connections', 'measurement', 'ai', 'security', 'integrity' ); }
function sn_admin_page_tab_for_slug( $slug ) {
	$map = array( 'sn-theme-options' => 'dashboard', 'sn-content' => 'content', 'sn-security' => 'security' );
	return $map[ $slug ] ?? '';
}

require __DIR__ . '/../inc/admin-page.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "admin — active tab resolution\n\n";

// 1. Explicit ?tab= wins.
$_GET = array( 'tab' => 'security', 'page' => 'sn-theme-options' );
ok( 'security' === sn_admin_page_active_tab(), 'an explicit ?tab= wins over the page slug' );

// 2. THE CASE THAT MATTERS: no ?tab= at all. The Dashboard is normally
//    reached this way, so a naive $_GET['tab'] check would miss it entirely.
$_GET = array( 'page' => 'sn-theme-options' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'NO ?tab= STILL RESOLVES TO DASHBOARD via the page slug' );

// 3. A slug that maps elsewhere.
$_GET = array( 'page' => 'sn-security' );
ok( 'security' === sn_admin_page_active_tab(), 'another slug resolves to its own tab' );

// 4. Nothing at all.
$_GET = array();
ok( 'dashboard' === sn_admin_page_active_tab(), 'no page and no tab defaults to dashboard' );

// 5. An unknown tab falls back rather than passing through.
$_GET = array( 'tab' => 'bogus' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'an unknown tab falls back to dashboard, never passes through' );

// 6. Injection attempt — the value is sanitised and then allowlisted.
$_GET = array( 'tab' => '<script>alert(1)</script>' );
ok( 'dashboard' === sn_admin_page_active_tab(), 'a hostile tab value cannot escape the allowlist' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php tests/admin-active-tab.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function sn_admin_page_active_tab()`, `EXIT=255`. No `FAIL:` line prints, which is why this plan gates on the exit code.

- [ ] **Step 3: Add the resolver**

Add to `inc/admin-page.php`, immediately above `sn_theme_options_page()` (line 30 — that IS the page renderer; there is no `sn_admin_page_render()`):

```php
/**
 * Which tab is active for this request? (v11.29.0)
 *
 * Extracted so the RENDERER and the metabox registration cannot disagree.
 * Registration runs on `load-{$hook}`, long before the renderer, and a second
 * copy of this logic would be a third chance to get it wrong.
 *
 * Dispatch order is unchanged from the inline version it replaces:
 *   1. explicit ?tab=… (v1.8.x deep links must keep working)
 *   2. derive from ?page=… (v1.9.0 — each submenu has its own slug)
 *   3. default to dashboard
 *
 * The Dashboard is normally reached with NO ?tab= at all, so a bare
 * $_GET['tab'] check would miss the most common case.
 *
 * @since 11.29.0
 * @return string A slug guaranteed to be in sn_admin_page_valid_tabs().
 */
function sn_admin_page_active_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab dispatch, no state change.
	if ( isset( $_GET['tab'] ) ) {
		$active = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	} else {
		$slug   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
		$active = sn_admin_page_tab_for_slug( $slug );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return in_array( $active, sn_admin_page_valid_tabs(), true ) ? $active : 'dashboard';
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `php tests/admin-active-tab.php; echo "EXIT=$?"`
Expected: `0 failed` and `EXIT=0`.

- [ ] **Step 5: Replace the inline copy**

In `inc/admin-page.php`, replace this block inside the render function:

```php
	if ( isset( $_GET['tab'] ) ) {
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	} else {
		$current_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
		$active_tab   = sn_admin_page_tab_for_slug( $current_slug );
	}

	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'dashboard';
	}
```

with:

```php
	// v11.29.0: one source of truth, shared with the metabox registration that
	// runs on load-{$hook} long before this renderer.
	$active_tab = sn_admin_page_active_tab();
```

- [ ] **Step 6: Negative-control the fallback**

```bash
cp inc/admin-page.php /tmp/ap.php
perl -0777 -pi -e "s/return in_array\( \\\$active, sn_admin_page_valid_tabs\(\), true \) \? \\\$active : 'dashboard';/return \\\$active;/" inc/admin-page.php
php tests/admin-active-tab.php > /tmp/m.out 2>&1; echo "EXIT=$?"; grep '^FAIL:' /tmp/m.out
cp /tmp/ap.php inc/admin-page.php
php tests/admin-active-tab.php > /dev/null 2>&1; echo "RESTORED EXIT=$?"
diff -q /tmp/ap.php inc/admin-page.php
```

Expected: the mutated run fails the unknown-tab and hostile-value assertions with `EXIT=1`; the restored run is `EXIT=0` and `diff` reports no difference.

- [ ] **Step 7: Full sweep and commit**

```bash
bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log
git add inc/admin-page.php tests/admin-active-tab.php
git commit -m "refactor: one source of truth for the active admin tab"
```

Expected: `EXIT=0`, no `ERROR` lines.

---

### Task 2: The request snapshot

Box callbacks run in whatever order the user dragged them into. If each fetched its own data, dragging a box would change how many outbound probes fire. One memoized fetch removes that coupling — and retires the double `snt_deploy_workers_status()` call introduced in #728.

**Files:**
- Create: `inc/dash-console.php`
- Test: `tests/dash-console.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// Count every call so we can prove the probe fires ONCE.
$GLOBALS['__probe_calls'] = 0;
$GLOBALS['__card_calls']  = 0;
function snt_deploy_workers_status( $opts = array() ) {
	$GLOBALS['__probe_calls']++;
	return array(
		array( 'label' => 'Analytics', 'live' => '1.20.0', 'state' => 'ok', 'reason' => '' ),
		array( 'label' => 'Remote MCP', 'live' => '', 'state' => 'unknown', 'reason' => 'warming' ),
	);
}
function snt_deploy_status_for( $pkg ) { return array( 'current' => '11.28.0', 'state' => 'ok' ); }
function snt_dashboard_glance_cards( $t, $p, $r, $l ) { $GLOBALS['__card_calls']++; return array( array( 'label' => 'Health', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ) ) ); }
function snt_dashboard_last_deploy_label( $runs ) { return '1 minute ago'; }
function snt_dashboard_override_post_types() { return array( 'wp_template' ); }
function get_posts( $args = array() ) { return array(); }
function snt_dashboard_measurement_data() { return array( 'views_7d' => 103 ); }

require __DIR__ . '/../inc/dash-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard console — snapshot\n\n";

$a = snt_dashboard_snapshot();
ok( is_array( $a ) && isset( $a['workers'], $a['cards'], $a['theme'], $a['measurement'] ), 'the snapshot carries everything a box could need' );
ok( 1 === $GLOBALS['__probe_calls'], 'the worker probe fired once' );

// Six boxes asking six times must not mean six probes. This is the property:
// BEHAVIOUR MUST NOT DEPEND ON LAYOUT, and box order is user-controlled.
for ( $i = 0; $i < 6; $i++ ) { snt_dashboard_snapshot(); }
ok( 1 === $GLOBALS['__probe_calls'], 'SIX BOXES, ONE PROBE — box order cannot change behaviour' );
ok( 1 === $GLOBALS['__card_calls'], 'and the glance cards are built once' );

// The snapshot is a plain array, so a box cannot mutate another box's view.
$b = snt_dashboard_snapshot();
$b['cards'] = array();
ok( count( snt_dashboard_snapshot()['cards'] ) === 1, 'a box mutating its copy does not affect the next box' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php tests/dash-console.php; echo "EXIT=$?"`
Expected: fatal — missing `inc/dash-console.php`, `EXIT=255`.

- [ ] **Step 3: Write the implementation**

Create `inc/dash-console.php`:

```php
<?php
/**
 * Signal & Noise — Dashboard console host.
 *
 * The Dashboard tab is a metabox host. This file owns the once-per-request
 * data snapshot every box reads from.
 *
 * WHY A SNAPSHOT: box callbacks run in the order the USER dragged them into.
 * If each box fetched its own data, dragging a box would change how many
 * outbound probes fire — behaviour coupled to layout. One memoised fetch makes
 * that impossible.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the Dashboard boxes need, gathered once.
 *
 * probe_budget stays at 1: a cold page load must not fan out five live HTTP
 * calls (v11.11.4). A worker that is merely cold reads as `warming`, which
 * sn_dash_zone_fleet() treats as pending rather than unknown.
 *
 * @since 11.29.0
 * @return array<string,mixed>
 */
function snt_dashboard_snapshot() {
	static $snap = null;
	if ( null !== $snap ) {
		return $snap;
	}

	$theme  = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'theme' ) : array();
	$plugin = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();

	$runs = function_exists( 'snt_deploy_history_merged' ) && defined( 'SNT_DEPLOY_REPOS' )
		? snt_deploy_history_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();

	$last_deploy_ago = function_exists( 'snt_dashboard_last_deploy_label' )
		? snt_dashboard_last_deploy_label( $runs )
		: '';

	$workers = function_exists( 'snt_deploy_workers_status' )
		? snt_deploy_workers_status( array( 'probe_budget' => 1 ) )
		: array();

	$cards = function_exists( 'snt_dashboard_glance_cards' )
		? snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago )
		: array();

	$overrides = function_exists( 'snt_dashboard_override_post_types' )
		? get_posts( array(
			'post_type'      => snt_dashboard_override_post_types(),
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		) )
		: array();

	$snap = array(
		'theme'           => $theme,
		'plugin'          => $plugin,
		'runs'            => $runs,
		'last_deploy_ago' => $last_deploy_ago,
		'workers'         => $workers,
		'cards'           => $cards,
		'override_count'  => is_array( $overrides ) ? count( $overrides ) : 0,
		'measurement'     => function_exists( 'snt_dashboard_measurement_data' ) ? snt_dashboard_measurement_data() : array(),
	);

	return $snap;
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `php tests/dash-console.php; echo "EXIT=$?"`
Expected: `0 failed`, `EXIT=0`.

- [ ] **Step 5: Negative-control the memoisation**

```bash
cp inc/dash-console.php /tmp/dc.php
perl -0777 -pi -e "s/\tstatic \\\$snap = null;\n\tif \( null !== \\\$snap \) \{\n\t\treturn \\\$snap;\n\t\}\n//" inc/dash-console.php
php tests/dash-console.php > /tmp/m.out 2>&1; echo "EXIT=$?"; grep '^FAIL:' /tmp/m.out
cp /tmp/dc.php inc/dash-console.php
php tests/dash-console.php > /dev/null 2>&1; echo "RESTORED EXIT=$?"
diff -q /tmp/dc.php inc/dash-console.php
```

Expected: the mutated run fails `SIX BOXES, ONE PROBE` and the card-count assertion with `EXIT=1`; restored run `EXIT=0` and no diff. If it fails nothing, the memo is not the thing being tested — fix the test before continuing.

- [ ] **Step 6: Commit**

```bash
git add inc/dash-console.php tests/dash-console.php
git commit -m "feat: one memoised Dashboard snapshot so box order cannot change behaviour"
```

---

### Task 3: Warming is pending, not unknown

The shipped Fleet zone says "1 of 7 never probed" while the Deploy Status widget beside it shows all seven with versions. Both are correct as coded — the widget's ability uses `probe_budget => 5`. The zone is reporting its own budget as a fact about the fleet.

**Files:**
- Modify: `inc/dash-zone-fleet.php`
- Modify: `tests/dash-zone-builders.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/dash-zone-builders.php`, before the `Result:` line:

```php
// ── warming is pending, not unknown (v11.29.0) ──────────────────────────────
// A cold worker is one this page load CHOSE not to probe (probe_budget = 1).
// Reporting that as "never probed" states our own budget as a fact about the
// fleet — and forces the zone to `unknown`, which outranks everything.
// v11.16.0 settled this one layer down: cold is not broken.
$z = sn_dash_zone_fleet( array( 'Theme' => '11.12.0', 'Remote MCP' => array( 'version' => '', 'reason' => 'warming' ) ), '1 minute ago' );
ok( $z['state'] === 'ok', 'A WARMING WORKER LEAVES THE FLEET OK — pending is not unknown' );
ok( false !== stripos( $z['summary'], 'warming' ), 'and the summary says warming rather than never probed' );

// A probe that actually FAILED is real missing evidence and still forces unknown.
$z = sn_dash_zone_fleet( array( 'Theme' => '11.12.0', 'Edge' => array( 'version' => '', 'reason' => 'http_500' ) ), '' );
ok( $z['state'] === 'unknown', 'a FAILED probe is genuinely unknown' );

// The plain-string form still works — most components have no reason to give.
$z = sn_dash_zone_fleet( array( 'Theme' => '11.12.0', 'Plugin' => '11.28.0' ), '' );
ok( $z['state'] === 'ok', 'plain version strings still read as measured' );
$z = sn_dash_zone_fleet( array( 'Theme' => '11.12.0', 'Plugin' => null ), '' );
ok( $z['state'] === 'unknown', 'a null with no reason is still unknown' );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-zone-builders.php; echo "EXIT=$?"`
Expected: `EXIT=1` with `A WARMING WORKER LEAVES THE FLEET OK` failing.

- [ ] **Step 3: Implement**

In `inc/dash-zone-fleet.php`, replace the body of the `foreach` inside `sn_dash_zone_fleet()`:

```php
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
```

with:

```php
	foreach ( $components as $name => $component ) {
		// A component is either a plain version string, or an array carrying the
		// probe's own `reason` so we can tell PENDING from FAILED.
		$version = is_array( $component ) ? (string) ( $component['version'] ?? '' ) : $component;
		$reason  = is_array( $component ) ? (string) ( $component['reason'] ?? '' ) : '';

		$measured = null !== $version && '' !== $version;
		$warming  = ! $measured && 'warming' === $reason;

		if ( $warming ) {
			++$pending;
		} elseif ( ! $measured ) {
			++$unknown;
		}

		$cards[] = array(
			'label' => (string) $name,
			'value' => $measured ? (string) $version : ( $warming ? __( 'warming…', 'signal-and-noise-tools' ) : '—' ),
			// A warming probe is PENDING, not unknown. Marking it unmeasured would
			// force the whole zone to `unknown`, which outranks everything — so one
			// cold cache would report the fleet as unmeasurable. v11.16.0 settled
			// the same question for the glance sort: cold is not broken.
			'measured'  => $measured || $warming,
			'attention' => false,
		);
	}
```

Add `$pending = 0;` beside the existing `$unknown = 0;` at the top of the function, and replace the summary block:

```php
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
```

with:

```php
	if ( 'unknown' === $state ) {
		$summary = sprintf(
			/* translators: 1: unprobed count, 2: total components */
			__( 'Fleet not measured — %1$d of %2$d never probed', 'signal-and-noise-tools' ),
			$unknown,
			$total
		);
	} elseif ( $pending > 0 ) {
		$summary = sprintf(
			/* translators: 1: total components, 2: count still warming */
			__( 'Fleet current — %1$d components, %2$d warming', 'signal-and-noise-tools' ),
			$total,
			$pending
		);
	} else {
		$summary = sprintf(
			/* translators: %d component count */
			__( 'Fleet current — %d components', 'signal-and-noise-tools' ),
			$total
		);
	}
```

- [ ] **Step 4: Update the producer**

In `inc/dash-zone-measurement.php`… **no** — the producer is `snt_dashboard_fleet_components()` in `inc/dash-zone-fleet.php`. Replace its worker loop:

```php
		$live          = (string) ( $worker['live'] ?? '' );
		$out[ $label ] = '' !== $live ? $live : null;
```

with:

```php
		$live   = (string) ( $worker['live'] ?? '' );
		$reason = (string) ( $worker['reason'] ?? '' );
		// Hand the builder the reason so it can tell a budget-skipped probe
		// (pending) from one that ran and failed (genuinely unknown).
		$out[ $label ] = '' !== $live
			? $live
			: array( 'version' => '', 'reason' => $reason );
```

- [ ] **Step 5: Run and confirm it passes**

Run: `php tests/dash-zone-builders.php; echo "EXIT=$?"`
Expected: `0 failed`, `EXIT=0`.

- [ ] **Step 6: Negative-control**

```bash
cp inc/dash-zone-fleet.php /tmp/zf.php
perl -0777 -pi -e "s/'measured'  => \\\$measured \|\| \\\$warming,/'measured'  => \\\$measured,/" inc/dash-zone-fleet.php
php tests/dash-zone-builders.php > /tmp/m.out 2>&1; echo "EXIT=$?"; grep '^FAIL:' /tmp/m.out
cp /tmp/zf.php inc/dash-zone-fleet.php
php tests/dash-zone-builders.php > /dev/null 2>&1; echo "RESTORED EXIT=$?"
diff -q /tmp/zf.php inc/dash-zone-fleet.php
```

Expected: the mutated run fails `A WARMING WORKER LEAVES THE FLEET OK` with `EXIT=1`; restored `EXIT=0`, no diff.

- [ ] **Step 7: Commit**

```bash
bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log
git add inc/dash-zone-fleet.php tests/dash-zone-builders.php
git commit -m "fix: a warming worker is pending, not a fleet that cannot be measured"
```

---

### Task 4: The briefing sentence

Fixed chrome, not a box — it is the backstop that still states the situation when every box has been hidden in Screen Options. Pure function first, renderer second.

**Files:**
- Create: `inc/dash-briefing.php`
- Test: `tests/dash-briefing.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require __DIR__ . '/../inc/dash-briefing.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard briefing\n\n";

$calm = array( 'needy' => 0, 'views' => 103, 'views_delta' => 39, 'anchored' => 33, 'citations' => 0, 'warming' => 1 );
$s = sn_dash_briefing_sentence( $calm );
ok( false !== stripos( $s, 'holding' ) || false !== stripos( $s, 'fine' ), 'a calm site opens by saying so' );
ok( false !== strpos( $s, '103' ), 'the headline figure is in the sentence' );
ok( false !== strpos( $s, '39' ), 'and its change' );
ok( false !== stripos( $s, 'warming' ), 'a warming worker is mentioned, not hidden' );

// THE BACKSTOP PROPERTY. With every box hidden this sentence is the only thing
// left on the page, so it must never open with "everything is fine" when it is not.
$bad = array( 'needy' => 2, 'views' => 103, 'views_delta' => -4, 'anchored' => 33, 'citations' => 0, 'warming' => 0 );
$s = sn_dash_briefing_sentence( $bad );
ok( false === stripos( $s, 'holding' ), 'A SITE WITH FINDINGS NEVER OPENS WITH "HOLDING"' );
ok( false !== strpos( $s, '2' ), 'it leads with the count that needs attention' );
ok( false !== stripos( $s, 'attention' ), 'and names what the count is' );

// One finding reads as one, not "1 checks".
$one = array( 'needy' => 1, 'views' => 0, 'views_delta' => 0, 'anchored' => 0, 'citations' => 0, 'warming' => 0 );
ok( false === strpos( sn_dash_briefing_sentence( $one ), 'checks need' ), 'one finding is singular' );

// A negative delta is not dressed up as growth.
$down = array( 'needy' => 0, 'views' => 90, 'views_delta' => -13, 'anchored' => 1, 'citations' => 0, 'warming' => 0 );
ok( false === stripos( sn_dash_briefing_sentence( $down ), 'up 13' ), 'A FALL IS NOT REPORTED AS A RISE' );
ok( false !== stripos( sn_dash_briefing_sentence( $down ), 'down' ), 'it says down' );

// Missing data shortens the sentence rather than inventing a figure.
$thin = array( 'needy' => 0 );
$s = sn_dash_briefing_sentence( $thin );
ok( '' !== $s, 'a sentence is still produced with almost no data' );
ok( false === strpos( $s, '0 views' ), 'and it does not claim a figure it never received' );

// Escaping: the sentence is echoed, so the renderer must escape it.
ob_start(); sn_dash_render_briefing( $calm ); $h = ob_get_clean();
ok( false !== strpos( $h, 'sn-dash-briefing' ), 'the band renders its wrapper' );
ok( false === strpos( $h, '<script>' ), 'the band escapes its content' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-briefing.php; echo "EXIT=$?"`
Expected: fatal — missing `inc/dash-briefing.php`, `EXIT=255`.

- [ ] **Step 3: Implement**

Create `inc/dash-briefing.php`:

```php
<?php
/**
 * Signal & Noise — the Dashboard briefing band.
 *
 * One sentence stating the situation, rendered as FIXED CHROME above the
 * metaboxes: not draggable, not collapsible, not hideable.
 *
 * That is a safety property, not a style choice. Core lets a user collapse a
 * box (`closedpostboxes_{page}`) and hide it outright (`metaboxhidden_{page}`).
 * A user who hides every box must still be told when something needs them, so
 * this band is the backstop and cannot be switched off.
 *
 * The sentence must never overstate. A wrong number is a bug; a sentence that
 * says "everything is holding" over two open findings is a lie, and it reads
 * as judgement rather than data.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the briefing sentence. Pure.
 *
 * @since 11.29.0
 * @param array<string,mixed> $f Keys: needy, views, views_delta, anchored,
 *                               citations, warming. Absent keys are omitted
 *                               from the sentence rather than defaulted.
 * @return string Plain text; the caller escapes.
 */
function sn_dash_briefing_sentence( array $f ) {
	$needy   = isset( $f['needy'] ) ? (int) $f['needy'] : 0;
	$parts   = array();

	if ( $needy > 0 ) {
		$open = sprintf(
			/* translators: %d number of checks needing attention */
			_n( '%d check needs attention.', '%d checks need attention.', $needy, 'signal-and-noise-tools' ),
			$needy
		);
	} else {
		$open = __( 'Everything is holding.', 'signal-and-noise-tools' );
	}

	if ( array_key_exists( 'views', $f ) ) {
		$views = sprintf(
			/* translators: %s view count */
			__( '%s views this week', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $f['views'] )
		);
		$delta = isset( $f['views_delta'] ) ? (int) $f['views_delta'] : 0;
		if ( 0 !== $delta ) {
			$views .= $delta > 0
				/* translators: %s absolute change in views */
				? sprintf( __( ', up %s on last', 'signal-and-noise-tools' ), number_format_i18n( $delta ) )
				/* translators: %s absolute change in views */
				: sprintf( __( ', down %s on last', 'signal-and-noise-tools' ), number_format_i18n( abs( $delta ) ) );
		}
		$parts[] = $views;
	}

	if ( array_key_exists( 'anchored', $f ) && (int) $f['anchored'] > 0 ) {
		$parts[] = sprintf(
			/* translators: %s count of anchored notes */
			__( 'all %s notes anchored', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $f['anchored'] )
		);
	}

	if ( array_key_exists( 'citations', $f ) && 0 === (int) $f['citations'] ) {
		$parts[] = __( 'nothing has cited you yet', 'signal-and-noise-tools' );
	}

	if ( isset( $f['warming'] ) && (int) $f['warming'] > 0 ) {
		$parts[] = sprintf(
			/* translators: %d number of workers still warming */
			_n( '%d worker still warming', '%d workers still warming', (int) $f['warming'], 'signal-and-noise-tools' ),
			(int) $f['warming']
		);
	}

	return empty( $parts ) ? $open : $open . ' ' . implode( ', ', $parts ) . '.';
}

/**
 * Render the band.
 *
 * @since 11.29.0
 * @param array<string,mixed> $f See sn_dash_briefing_sentence().
 * @return void
 */
function sn_dash_render_briefing( array $f ) {
	$needy = isset( $f['needy'] ) ? (int) $f['needy'] : 0;
	$state = $needy > 0 ? 'attention' : 'ok';

	echo '<div class="sn-dash-briefing sn-dash-briefing--' . esc_attr( $state ) . '">';
	echo '<p>' . esc_html( sn_dash_briefing_sentence( $f ) ) . '</p>';
	echo '</div>';
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-briefing.php; echo "EXIT=$?"`
Expected: `0 failed`, `EXIT=0`. If `esc_attr` is undefined, add the stub `if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }` to the test harness.

- [ ] **Step 5: Negative-control the backstop**

```bash
cp inc/dash-briefing.php /tmp/db.php
perl -0777 -pi -e "s/\tif \( \\\$needy > 0 \) \{/\tif ( false ) {/" inc/dash-briefing.php
php tests/dash-briefing.php > /tmp/m.out 2>&1; echo "EXIT=$?"; grep '^FAIL:' /tmp/m.out
cp /tmp/db.php inc/dash-briefing.php
php tests/dash-briefing.php > /dev/null 2>&1; echo "RESTORED EXIT=$?"
diff -q /tmp/db.php inc/dash-briefing.php
```

Expected: the mutated run fails `A SITE WITH FINDINGS NEVER OPENS WITH "HOLDING"` with `EXIT=1`; restored `EXIT=0`, no diff.

- [ ] **Step 6: Commit**

```bash
git add inc/dash-briefing.php tests/dash-briefing.php
git commit -m "feat: the briefing band — the backstop that cannot be hidden"
```

---

### Task 5: Register the boxes on `load-`

**This is the task the whole design hangs on.** `do_action( "load-{$page_hook}" )` fires, THEN `admin-header.php` renders Screen Options, THEN the page callback runs. Register at render time and `$wp_meta_boxes` is empty when Screen Options draws, so the show/hide checkboxes silently never appear.

**Files:**
- Modify: `inc/dash-console.php`
- Modify: `tests/dash-console.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/dash-console.php`, before the `Result:` line:

```php
// ── registration ────────────────────────────────────────────────────────────
$GLOBALS['__boxes']  = array();
$GLOBALS['__screen'] = array();
function add_meta_box( $id, $title, $cb, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
	$GLOBALS['__boxes'][] = array( 'id' => $id, 'screen' => $screen, 'context' => $context );
}
function add_screen_option( $opt, $args = array() ) { $GLOBALS['__screen'][ $opt ] = $args; }

$GLOBALS['__tab'] = 'dashboard';
function sn_admin_page_active_tab() { return $GLOBALS['__tab']; }

snt_dash_boxes_register( 'toplevel_page_sn-theme-options' );
$ids = array_column( $GLOBALS['__boxes'], 'id' );
ok( in_array( 'sn-dash-systems', $ids, true ), 'the Systems box registers' );
ok( in_array( 'sn-dash-fleet', $ids, true ), 'the Fleet box registers' );
ok( in_array( 'sn-dash-maintenance', $ids, true ), 'the Maintenance box registers' );

$ctx = array_column( $GLOBALS['__boxes'], 'context', 'id' );
ok( 'normal' === $ctx['sn-dash-systems'], 'Systems is a main-column box' );
ok( 'side' === $ctx['sn-dash-maintenance'], 'Maintenance is a side-column box' );
ok( isset( $GLOBALS['__screen']['layout_columns'] ), 'the two-column layout option is offered' );

// THE GATE. Screen Options is per-SCREEN, not per-tab — every tab shares
// toplevel_page_sn-theme-options. Registering anywhere else would put our
// checkboxes in Screen Options on the Security tab.
$GLOBALS['__boxes'] = array();
$GLOBALS['__tab']   = 'security';
snt_dash_boxes_register( 'toplevel_page_sn-theme-options' );
ok( array() === $GLOBALS['__boxes'], 'NO BOXES REGISTER ON ANOTHER TAB' );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-console.php; echo "EXIT=$?"`
Expected: fatal — `Call to undefined function snt_dash_boxes_register()`, `EXIT=255`.

- [ ] **Step 3: Implement**

Append to `inc/dash-console.php`:

```php
/**
 * The six Dashboard boxes, in registration order.
 *
 * Registration order is only the DEFAULT — core persists the user's own order
 * in `meta-box-order_{page}` and applies it on top.
 *
 * @since 11.29.0
 * @return array<int,array{id:string,title:string,callback:string,context:string}>
 */
function snt_dash_boxes() {
	return array(
		array( 'id' => 'sn-dash-systems',     'title' => __( 'Systems', 'signal-and-noise-tools' ),     'callback' => 'snt_dash_box_systems',     'context' => 'normal' ),
		array( 'id' => 'sn-dash-fleet',       'title' => __( 'Fleet', 'signal-and-noise-tools' ),       'callback' => 'snt_dash_box_fleet',       'context' => 'normal' ),
		array( 'id' => 'sn-dash-traffic',     'title' => __( 'Traffic', 'signal-and-noise-tools' ),     'callback' => 'snt_dash_box_traffic',     'context' => 'normal' ),
		array( 'id' => 'sn-dash-glance',      'title' => __( 'At a glance', 'signal-and-noise-tools' ), 'callback' => 'snt_dash_box_glance',      'context' => 'side' ),
		array( 'id' => 'sn-dash-maintenance', 'title' => __( 'Maintenance', 'signal-and-noise-tools' ), 'callback' => 'snt_dash_box_maintenance', 'context' => 'side' ),
		array( 'id' => 'sn-dash-diagnostics', 'title' => __( 'Diagnostics', 'signal-and-noise-tools' ), 'callback' => 'snt_dash_box_diagnostics', 'context' => 'side' ),
	);
}

/**
 * Register the boxes for one screen. MUST run on `load-{$hook}`.
 *
 * TIMING, verified against wp-admin/admin.php: load-{$page_hook} fires, THEN
 * admin-header.php renders Screen Options, THEN the page callback runs. If we
 * registered inside the renderer, $wp_meta_boxes would be empty when Screen
 * Options draws and the show/hide checkboxes would silently never appear.
 *
 * THE TAB GATE: every SN tab shares the screen id, and Screen Options is
 * per-screen. WP_Screen::render_meta_boxes_preferences() early-returns unless
 * $wp_meta_boxes[$screen->id] is set, so registering only on the Dashboard tab
 * leaves the panel empty elsewhere by construction.
 *
 * @since 11.29.0
 * @param string $hook_suffix The screen id to register against.
 * @return void
 */
function snt_dash_boxes_register( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_active_tab' ) || 'dashboard' !== sn_admin_page_active_tab() ) {
		return;
	}

	add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );

	foreach ( snt_dash_boxes() as $box ) {
		add_meta_box( $box['id'], $box['title'], $box['callback'], $hook_suffix, $box['context'] );
	}
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-console.php; echo "EXIT=$?"`
Expected: `0 failed`, `EXIT=0`.

- [ ] **Step 5: Hook it up**

In `inc/admin-menu.php`, immediately after `sn_admin_page_hooks( $hooks );` inside the `admin_menu` callback, add:

```php
	// v11.29.0: metaboxes MUST register on load-{$hook}, before admin-header.php
	// renders Screen Options. Registering in the page callback is too late.
	foreach ( sn_admin_page_hooks() as $sn_hook ) {
		add_action( 'load-' . $sn_hook, function () use ( $sn_hook ) {
			if ( function_exists( 'snt_dash_boxes_register' ) ) {
				snt_dash_boxes_register( $sn_hook );
			}
		} );
	}
```

In the existing `admin_enqueue_scripts` callback in the same file, after the stylesheet enqueues, add:

```php
	// Core's postbox behaviour: drag, collapse, and the Screen Options toggles.
	wp_enqueue_script( 'postbox' );
```

- [ ] **Step 6: Verify the hook fires**

Run: `php -l inc/admin-menu.php && bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log`
Expected: no syntax errors, `EXIT=0`, no `ERROR` lines.

- [ ] **Step 7: Negative-control the tab gate**

```bash
cp inc/dash-console.php /tmp/dc.php
perl -0777 -pi -e "s/if \( ! function_exists\( 'sn_admin_page_active_tab' \) \|\| 'dashboard' !== sn_admin_page_active_tab\(\) \) \{\n\t\treturn;\n\t\}/if ( false ) {\n\t\treturn;\n\t}/" inc/dash-console.php
php tests/dash-console.php > /tmp/m.out 2>&1; echo "EXIT=$?"; grep '^FAIL:' /tmp/m.out
cp /tmp/dc.php inc/dash-console.php
php tests/dash-console.php > /dev/null 2>&1; echo "RESTORED EXIT=$?"
diff -q /tmp/dc.php inc/dash-console.php
```

Expected: the mutated run fails `NO BOXES REGISTER ON ANOTHER TAB` with `EXIT=1`; restored `EXIT=0`, no diff.

- [ ] **Step 8: Commit**

```bash
git add inc/dash-console.php inc/admin-menu.php tests/dash-console.php
git commit -m "feat: register Dashboard boxes on load-, before Screen Options renders"
```

---

### Task 6: The six box callbacks

Each is a thin wrapper over an existing builder. **No box computes anything new** — they all read `snt_dashboard_snapshot()`.

**Files:**
- Create: `inc/dash-boxes.php`
- Test: `tests/dash-boxes.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
foreach ( array( '__', 'esc_html', 'esc_attr' ) as $fn ) {
	if ( 'x' === $fn ) { continue; }
}
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return '/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (float) $n ); } }

require __DIR__ . '/../inc/dash-boxes.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard boxes\n\n";

// ── the title dot ───────────────────────────────────────────────────────────
// THE SAFETY PROPERTY. Core lets a user collapse a box. If state lived only in
// the body, collapsing Systems would hide an open finding. The dot is in the
// TITLE, so collapse hides detail and never state.
$t = snt_dash_box_title( 'Systems', 'attention' );
ok( false !== strpos( $t, 'sn-dash-dot--attention' ), 'THE STATE DOT IS IN THE TITLE, so a collapsed box still reports state' );
ok( false !== strpos( $t, 'Systems' ), 'the title text survives' );
ok( false === strpos( snt_dash_box_title( 'Fleet', 'ok' ), 'attention' ), 'a calm box carries no attention class' );
ok( false === strpos( snt_dash_box_title( '<script>x</script>', 'ok' ), '<script>' ), 'the title is escaped' );
ok( false === strpos( snt_dash_box_title( 'Fleet', '"><b>' ), '<b>' ), 'a hostile state cannot break out of the class attribute' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run and confirm it fails**

Run: `php tests/dash-boxes.php; echo "EXIT=$?"`
Expected: fatal — missing `inc/dash-boxes.php`, `EXIT=255`.

- [ ] **Step 3: Implement the title helper**

Create `inc/dash-boxes.php`:

```php
<?php
/**
 * Signal & Noise — the Dashboard metaboxes.
 *
 * Six thin callbacks. Every one reads snt_dashboard_snapshot() and none
 * computes anything: the builders they wrap are already pure and tested.
 *
 * @package SignalNoiseTools
 * @since 11.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A box title carrying its state as a dot.
 *
 * The dot belongs in the TITLE, not the body. Core lets a user collapse a box
 * (`closedpostboxes_{page}`); if state lived in the body, collapsing Systems
 * would hide an open finding. Collapse must hide detail, never state — the
 * same guarantee the superseded pin design made.
 *
 * @since 11.29.0
 * @param string $label
 * @param string $state One of SN_DASH_STATES.
 * @return string Escaped HTML.
 */
function snt_dash_box_title( $label, $state ) {
	return '<span class="sn-dash-dot sn-dash-dot--' . esc_attr( (string) $state ) . '" aria-hidden="true"></span>'
		. esc_html( (string) $label );
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php tests/dash-boxes.php; echo "EXIT=$?"`
Expected: `0 failed`, `EXIT=0`.

- [ ] **Step 5: Add the six callbacks**

Append to `inc/dash-boxes.php`:

```php
/**
 * Systems — every check that is a verdict about whether something is wrong.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_systems() {
	$snap  = snt_dashboard_snapshot();
	$labels = array( 'Health', 'Cron', 'Caches', 'Provenance' );
	$rows  = array();
	foreach ( $snap['cards'] as $card ) {
		if ( in_array( (string) ( $card['label'] ?? '' ), $labels, true ) ) {
			$rows[] = $card;
		}
	}
	snt_dash_render_rows( $rows );
}

/**
 * Fleet — component versions, plus the recent deploys folded in.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_fleet() {
	$snap = snt_dashboard_snapshot();
	$zone = sn_dash_zone_fleet(
		snt_dashboard_fleet_components( $snap['theme'], $snap['plugin'], $snap['workers'] ),
		$snap['last_deploy_ago']
	);
	snt_dash_render_rows( $zone['cards'] );

	if ( ! empty( $snap['runs'] ) && function_exists( 'snt_dashboard_render_deploy_row' ) ) {
		echo '<ul class="sn-deploy-list">';
		foreach ( $snap['runs'] as $run ) {
			snt_dashboard_render_deploy_row( $run );
		}
		echo '</ul>';
	}
}

/**
 * Traffic — the 30-day trend.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_traffic() {
	if ( ! function_exists( 'sn_analytics_daily_series' ) || ! function_exists( 'snt_analytics_sparkline' ) ) {
		echo '<p class="description"><em>' . esc_html__( 'Analytics is not configured.', 'signal-and-noise-tools' ) . '</em></p>';
		return;
	}
	$from   = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
	$to     = gmdate( 'Y-m-d', time() );
	$series = sn_analytics_daily_series( $from, $to, 'human', 'day' );
	if ( empty( $series ) ) {
		echo '<p class="description"><em>' . esc_html__( 'No traffic recorded in this window.', 'signal-and-noise-tools' ) . '</em></p>';
		return;
	}
	echo '<div class="sn-dash-trend">';
	// snt_analytics_sparkline returns pre-escaped SVG (coords esc_attr'd, chrome static).
	echo snt_analytics_sparkline( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped SVG from the shared helper.
	echo '</div>';
}

/**
 * At a glance — the five figures.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_glance() {
	$snap = snt_dashboard_snapshot();
	sn_dash_render_measurement_strip( sn_dash_measurement_figures( $snap['measurement'] ) );
}

/**
 * Maintenance — the four actions, unchanged.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_maintenance() {
	snt_dashboard_render_maintenance_actions();
}

/**
 * Diagnostics — the override detail list.
 *
 * @since 11.29.0
 * @return void
 */
function snt_dash_box_diagnostics() {
	snt_dashboard_render_diagnostics();
}

/**
 * Render a list of glance cards as console rows.
 *
 * @since 11.29.0
 * @param array<int,array<string,mixed>> $cards
 * @return void
 */
function snt_dash_render_rows( array $cards ) {
	if ( empty( $cards ) ) {
		return;
	}
	echo '<ul class="sn-dash-rows">';
	foreach ( sn_admin_glance_sort_by_attention( $cards ) as $card ) {
		$kind  = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';
		$href  = isset( $card['href'] ) ? (string) $card['href'] : '';
		$label = (string) ( $card['label'] ?? '' );
		$value = (string) ( $card['value'] ?? '' );

		echo '<li class="sn-dash-row">';
		echo '<span class="sn-dash-dot sn-dash-dot--' . esc_attr( $kind ) . '" aria-hidden="true"></span>';
		if ( '' !== $href ) {
			echo '<a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
		} else {
			echo '<span>' . esc_html( $label ) . '</span>';
		}
		echo '<span class="sn-dash-row__value">' . esc_html( $value ) . '</span>';
		echo '</li>';
	}
	echo '</ul>';
}
```

- [ ] **Step 6: Extract the two renderers the boxes call**

`snt_dash_box_maintenance()` and `snt_dash_box_diagnostics()` call functions that do not exist yet — that markup currently sits inline in `snt_dashboard_tab_render()` (the Maintenance block starts at `inc/admin-tab-dashboard.php:243`, Diagnostics at `:313`).

Wrap each in a function **in the same file**, moving the existing lines verbatim. Change nothing inside them except where noted:

```php
/**
 * The Maintenance action grid. (v11.29.0: extracted from the tab renderer so a
 * metabox can call it. The markup and the nonce are unchanged.)
 *
 * @since 11.29.0
 * @return void
 */
function snt_dashboard_render_maintenance_actions() {
	// MOVE VERBATIM: everything from `echo '<form method="post">';` through the
	// closing `echo '</form>';` — the four cards, wp_nonce_field(
	// 'sn_theme_options_nonce' ), and the .sn-card-grid--dash wrapper.
	// Drop only the surrounding <h2 class="sn-section-h">Maintenance</h2>: the
	// box title now carries that word, and repeating it reads as a stutter.
}

/**
 * The override diagnostics fold. (v11.29.0: extracted; still renders nothing
 * when there are no overrides.)
 *
 * @since 11.29.0
 * @return void
 */
function snt_dashboard_render_diagnostics() {
	$snap = snt_dashboard_snapshot();
	if ( 0 === (int) $snap['override_count'] ) {
		return;
	}
	// MOVE VERBATIM: the <details class="sn-override-details"> block and its
	// list. Drop the <h2 class="sn-section-h" id="sn-dash-diagnostics"> for the
	// same reason as above. Where the old code read a local $overrides array,
	// re-query inside this function with snt_dashboard_override_post_types() —
	// the snapshot carries the COUNT only, deliberately, because the full post
	// objects are needed by nothing else on the page.
}
```

Then delete the now-empty section-3 and section-4 blocks from `snt_dashboard_tab_render()`; Task 7 replaces that function's body wholesale, so this is only to keep the file parsing between commits.

Run: `php -l inc/admin-tab-dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log
git add inc/dash-boxes.php inc/admin-tab-dashboard.php tests/dash-boxes.php
git commit -m "feat: the six Dashboard boxes, each a thin wrapper over an existing builder"
```

---

### Task 7: The shell

**Files:**
- Modify: `inc/dash-console.php`
- Modify: `inc/admin-tab-dashboard.php`

- [ ] **Step 1: Implement the shell**

Append to `inc/dash-console.php`:

```php
/**
 * Render the metabox shell.
 *
 * The nonce fields are what make core's persistence work, and their names are
 * the trap in this whole feature: the AJAX actions are `closed-postboxes` and
 * `meta-box-order`, but the NONCE action for the first is `closedpostboxes` —
 * hyphen in the action, none in the nonce. Mismatch them and nothing errors:
 * dragging works and the state simply never comes back.
 *
 * @since 11.29.0
 * @param string $screen_id
 * @return void
 */
function snt_dash_render_shell( $screen_id ) {
	$columns = (int) get_current_screen()->get_columns();
	$columns = ( 2 === $columns ) ? 2 : 1;

	echo '<div id="poststuff">';
	echo '<div id="post-body" class="metabox-holder columns-' . esc_attr( (string) $columns ) . '">';

	echo '<div id="postbox-container-1" class="postbox-container">';
	do_meta_boxes( $screen_id, 'side', null );
	echo '</div>';

	echo '<div id="postbox-container-2" class="postbox-container">';
	do_meta_boxes( $screen_id, 'normal', null );
	echo '</div>';

	echo '</div></div>';

	wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
	wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );

	// postboxes.add_postbox_toggles( page ) binds the drag/collapse handlers and
	// tells core which screen's preferences to save against.
	wp_add_inline_script(
		'postbox',
		'jQuery(function($){ if ( window.postboxes ) { postboxes.add_postbox_toggles( ' . wp_json_encode( $screen_id ) . ' ); } });'
	);
}
```

- [ ] **Step 2: Replace the tab renderer**

In `inc/admin-tab-dashboard.php`, replace the whole body of `snt_dashboard_tab_render()` with:

```php
function snt_dashboard_tab_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$snap = snt_dashboard_snapshot();

	// The band is fixed chrome, ABOVE the boxes and outside them: a user who
	// hides every box in Screen Options must still be told when something
	// needs attention. See inc/dash-briefing.php.
	$needy = 0;
	foreach ( $snap['cards'] as $card ) {
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		if ( sn_admin_card_wants_attention( $card ) && ( 'err' === $kind || 'warn' === $kind ) ) {
			++$needy;
		}
	}

	sn_dash_render_briefing( array_merge(
		$snap['measurement'],
		array( 'needy' => $needy )
	) );

	$screen = get_current_screen();
	snt_dash_render_shell( $screen ? $screen->id : '' );
}
```

- [ ] **Step 3: Verify**

Run: `php -l inc/admin-tab-dashboard.php && php -l inc/dash-console.php && bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log`
Expected: no syntax errors, `EXIT=0`, no `ERROR` lines. Suites asserting on the old markup will fail here — that is Task 9.

- [ ] **Step 4: Commit**

```bash
git add inc/dash-console.php inc/admin-tab-dashboard.php
git commit -m "feat: the Dashboard tab becomes a metabox shell"
```

---

### Task 8: Delete what core replaces

**Files:**
- Delete: `inc/dash-pins.php`, `tests/dash-pins.php`
- Modify: `inc/dash-zones.php`, `tests/dash-zones.php`, `tests/dash-zones-render.php`, `signal-and-noise-tools.php`, `inc/desktop-mode-widgets.php`

- [ ] **Step 1: Delete the pin module**

```bash
git rm inc/dash-pins.php tests/dash-pins.php
```

Core stores collapse state in `closedpostboxes_{page}`, hidden state in `metaboxhidden_{page}` and order in `meta-box-order_{page}`, all per user. This module reimplemented a subset of that, including a REST route that nothing in the admin ever called.

- [ ] **Step 2: Remove the superseded renderer**

From `inc/dash-zones.php`, delete `sn_dash_zone_is_open()` and `sn_dash_render_zone()` entirely. Keep `sn_dash_zone_state()` and `SN_DASH_STATES` — the state still drives the title dot.

From `tests/dash-zones.php`, delete the `── open/closed ──` block. Delete `tests/dash-zones-render.php` entirely:

```bash
git rm tests/dash-zones-render.php
```

- [ ] **Step 3: Update the loader**

In `signal-and-noise-tools.php`, remove the `dash-pins.php` require and add the three new files:

```php
require_once SNT_PATH . 'inc/dash-console.php';          // v11.29.0: snapshot, box registration, the shell
require_once SNT_PATH . 'inc/dash-boxes.php';            // v11.29.0: the six box callbacks
require_once SNT_PATH . 'inc/dash-briefing.php';         // v11.29.0: the band that cannot be hidden
```

- [ ] **Step 4: Retire the redundant desktop widget**

In `inc/desktop-mode-widgets.php`, remove the `snt_os_register_widget( 'sn-deploy-status', … )` block. The Fleet box now carries all seven versions, so the widget repeats them verbatim — the cut the superseding proposal asked for and the previous implementation plan omitted.

- [ ] **Step 5: Verify nothing still references the deleted code**

```bash
grep -rn "sn_dash_pins\|sn_dash_set_pin\|sn_dash_zone_is_open\|sn_dash_render_zone\|sn-deploy-status" --include='*.php' inc/ tests/ signal-and-noise-tools.php
```

Expected: **no output**. Any hit is a dangling reference that would fatal at runtime.

- [ ] **Step 6: Commit**

```bash
bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log
git add -A
git commit -m "refactor: delete the pin module and zone renderer that core replaces"
```

---

### Task 9: Update the existing suites and the CSS

**Files:**
- Modify: `tests/dashboard-layout.php`, `tests/admin-tab-dashboard-glance.php`
- Modify: `assets/admin.css`

- [ ] **Step 1: Point the layout suite at the new markup**

In `tests/dashboard-layout.php`, replace the `sn-dash-zones` anchor with the shell and band, and add stubs for the core functions the shell calls:

```php
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() { return new class { public $id = 'toplevel_page_sn-theme-options'; public function get_columns() { return 2; } }; }
}
if ( ! function_exists( 'do_meta_boxes' ) ) { function do_meta_boxes( $s, $c, $o ) { echo '<div class="mb-' . $c . '"></div>'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a, $n, $r = true ) { echo '<input name="' . $n . '">'; } }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $s ) { return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
```

Then replace the four ordering assertions with:

```php
ok( false !== strpos( $html, 'sn-dash-briefing' ), 'the briefing band renders' );
ok( false !== strpos( $html, 'id="poststuff"' ), 'the metabox shell renders' );
ok( false !== strpos( $html, 'closedpostboxesnonce' ), 'the collapse nonce is present — without it persistence fails silently' );
ok( false !== strpos( $html, 'meta-box-order-nonce' ), 'the order nonce is present' );
ok( strpos( $html, 'sn-dash-briefing' ) < strpos( $html, 'id="poststuff"' ), 'THE BAND LEADS — it is the backstop when every box is hidden' );
```

- [ ] **Step 2: Update the glance suite**

In `tests/admin-tab-dashboard-glance.php`, replace the `sn-dash-zones`, `sn-dash-strip` and `sn-dash-fig--hero` assertions with:

```php
dg_contains( $tab, 'sn-dash-briefing', 'v11.29.0: the briefing band leads the tab' );
dg_contains( $tab, 'id="poststuff"', 'and the metaboxes host the rest' );
```

The strip is now inside a box callback and is covered by `tests/dash-zone-measurement.php`, so it is no longer part of this suite's surface.

- [ ] **Step 3: Add the CSS**

Append to `assets/admin.css`. Core supplies all postbox chrome; this is only the box internals.

```css

/* ──────────────────────────────────────────────────────────────────────────
   v11.29.0 — Dashboard console. Core owns the postbox chrome (drag, collapse,
   Screen Options); everything here is what goes INSIDE a box.

   Tokens only, no var() fallbacks: every token below is defined at :root in
   this same file, and a fallback that disagrees with its token is worse than
   no fallback at all.
   ────────────────────────────────────────────────────────────────────────── */
.sn-dash-briefing {
	border: 1px solid var(--sn-border);
	border-left: 3px solid var(--sn-ok);
	border-radius: var(--sn-radius);
	background: var(--sn-surface);
	padding: var(--sn-space-3);
	margin-bottom: var(--sn-space-3);
}
.sn-dash-briefing--attention { border-left-color: var(--sn-err); }
.sn-dash-briefing p { margin: 0; font-size: 14px; line-height: 1.5; }

.sn-dash-rows { margin: 0; list-style: none; }
.sn-dash-row {
	display: flex;
	align-items: center;
	gap: var(--sn-space-2);
	padding: var(--sn-space-1) 0;
	border-bottom: 1px solid var(--sn-border);
	font-size: 13px;
}
.sn-dash-row:last-child { border-bottom: 0; }
.sn-dash-row__value {
	margin-left: auto;
	font-variant-numeric: tabular-nums;
	color: var(--sn-text-muted);
}

/* The dot sits in the box TITLE as well as the rows, so a COLLAPSED box still
   reports its state. Shape is not the only signal — the title text says it
   too — but the dot is what survives a glance. */
.sn-dash-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	display: inline-block;
	flex: none;
	margin-right: var(--sn-space-1);
	background: var(--sn-border);
}
.sn-dash-dot--ok { background: var(--sn-ok); }
.sn-dash-dot--warn,
.sn-dash-dot--attention { background: var(--sn-warn); }
.sn-dash-dot--err { background: var(--sn-err); }
.sn-dash-dot--unknown { background: transparent; box-shadow: inset 0 0 0 1px var(--sn-text-muted); }

.sn-dash-trend svg { display: block; width: 100%; height: 90px; }
```

- [ ] **Step 4: Verify**

```bash
bash tests/run.sh > /tmp/sw.log 2>&1; echo "EXIT=$?"; grep '^ERROR' /tmp/sw.log
vendor/bin/phpcs --standard=phpcs.xml.dist inc/ signal-and-noise-tools.php > /tmp/cs.out 2>&1; echo "PHPCS EXIT=$?"
vendor/bin/phpstan analyse --no-progress --memory-limit=2G > /tmp/st.out 2>&1; echo "PHPSTAN EXIT=$?"; tail -2 /tmp/st.out
```

Expected: `EXIT=0` on all three, no `ERROR` lines, PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add tests/dashboard-layout.php tests/admin-tab-dashboard-glance.php assets/admin.css
git commit -m "test: pin the console shell, and style the box internals"
```

---

### Task 10: Final gates

- [ ] **Step 1: Full sweep, gated on the exit code**

```bash
bash tests/run.sh > /tmp/final.log 2>&1; echo "EXIT=$?"
grep -c '^ERROR' /tmp/final.log
tail -1 /tmp/final.log
```

Expected: `EXIT=0`, zero `ERROR` lines. Do NOT gate on `grep -c '  FAIL'` — the runner summarises and never echoes `FAIL` lines, so that count reads zero either way.

- [ ] **Step 2: Static gates**

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist inc/ signal-and-noise-tools.php; echo "PHPCS EXIT=$?"
vendor/bin/phpstan analyse --no-progress --memory-limit=2G | tail -3
```

Expected: PHPCS exit 0, PHPStan `[OK] No errors`.

- [ ] **Step 3: Prove no redeclare across the whole dashboard set**

A green suite cannot catch this: each standalone suite loads only the files it needs, so a duplicate declaration across two files never fires.

```bash
php -r '
define("ABSPATH","/");
function add_action(){return true;} function add_filter(){return true;}
foreach (["inc/admin-glance.php","inc/dash-zones.php","inc/dash-zone-attention.php","inc/dash-zone-fleet.php","inc/dash-zone-measurement.php","inc/dash-deploy-rows.php","inc/dash-api-summary.php","inc/dash-debug-info.php","inc/dash-console.php","inc/dash-boxes.php","inc/dash-briefing.php","inc/admin-tab-dashboard.php"] as $f) { require_once $f; }
echo "ALL DASHBOARD FILES LOAD TOGETHER\n";'
```

Expected: `ALL DASHBOARD FILES LOAD TOGETHER`.

- [ ] **Step 4: Confirm the orchestrator did not regrow**

```bash
wc -l inc/admin-tab-dashboard.php inc/dash-console.php inc/dash-boxes.php inc/dash-briefing.php
```

Expected: `admin-tab-dashboard.php` well under its current 726 lines, and each new file under ~150 per the project's file-size preference. If a new file is over, split it rather than shipping it.

- [ ] **Step 5: CHANGELOG and commit**

Add an `## [Unreleased]` entry covering: the metabox host, drag/collapse/Screen-Options coming from core, the pin module's deletion, the warming fix, the briefing band as the hide-proof backstop, and the Deploy Status widget retirement. Then:

```bash
git add CHANGELOG.md && git commit -m "docs: changelog for the Dashboard console"
```

---

## Self-review

**Spec coverage.** Metabox host (Tasks 5, 7) · six boxes (Task 6) · snapshot and behaviour-independent-of-layout (Task 2) · probe budget and the warming fix (Task 3) · briefing band and the backstop property (Tasks 4, 7) · state dot in the title (Task 6) · deletions incl. the Deploy Status widget (Task 8) · Screen Options per-screen gating (Task 5) · CSS (Task 9) · testing (every task) · final gates (Task 10).

**Two spec items deliberately carry no task**, both listed there as out of scope: the GSC 250-row cap, and a realtime box. Neither is needed for this to ship.

**The tab-resolution extraction (Task 1) is not in the spec.** It was found while writing this plan: the spec says "registered only when `tab=dashboard` is active", and the obvious implementation — checking `$_GET['tab']` — is wrong, because the Dashboard is normally reached with no `tab` param at all. Task 1 exists so registration and rendering cannot disagree.

**No predicted assertion counts anywhere in this plan**, by design. The previous plan for this feature stated them three times and was wrong three times.

**Name consistency check:** `snt_dashboard_snapshot()`, `snt_dash_boxes()`, `snt_dash_boxes_register()`, `snt_dash_render_shell()`, `snt_dash_box_title()`, `snt_dash_render_rows()`, the six `snt_dash_box_*()` callbacks, `sn_dash_briefing_sentence()`, `sn_dash_render_briefing()`, `sn_admin_page_active_tab()`, `snt_dashboard_render_maintenance_actions()`, `snt_dashboard_render_diagnostics()` — each is defined in exactly one task and referenced consistently after it.
