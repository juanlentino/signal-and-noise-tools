# admin-page.php Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 1,468-line `inc/admin-page.php` monolith into 10 focused modules + a ~170-line orchestrator, changing **zero behavior**, then ship as v4.5.3 (PATCH).

**Architecture:** Pure extraction. Functions move verbatim into new `inc/admin-*.php` / `inc/admin-forms/*.php` files loaded via the existing flat `require_once` manifest in `signal-and-noise-tools.php`. The 22-branch `admin_init` dispatcher becomes an action→callback map + atomic handler functions; the duplicate flash-code→notice ladder becomes one shared data table + resolver; three inline-HTML forms become render functions.

**Tech Stack:** Procedural PHP 8.0+, WordPress plugin. No build step. Tests are standalone PHP files run with `php tests/<file>.php` (each prints `N passed, M failed` and exits non-zero on failure). No PHPUnit.

---

## Refactor-specific conventions (read before starting)

- **Verbatim moves are literal.** When a step says "move lines X–Y of `inc/admin-page.php`," copy them **byte-for-byte** (including comments, HTML entities, tabs) into the new file, then delete them from the source. Do NOT reformat, rename, "improve," or re-indent. Tab indentation only.
- **Every new file** opens with a `<?php` + file docblock (`@package SignalNoiseTools`) + the guard `if ( ! defined( 'ABSPATH' ) ) { exit; }` — matching every other `inc/` module.
- **Green at every commit.** Run the full suite after each task. Baseline to preserve: **945 passed / 0 failed across 27 suites.**
- **Aggregate tally command** (use after each task):

```bash
cd "$(git rev-parse --show-toplevel)"
total_pass=0; total_fail=0; suites=0; failed_suites=0; failing=""
for f in tests/*.php; do
  out=$(php "$f" 2>&1); code=$?
  suites=$((suites+1))
  p=$(printf '%s\n' "$out" | grep -oE '[0-9]+ passed' | tail -1 | grep -oE '^[0-9]+')
  fl=$(printf '%s\n' "$out" | grep -oE '[0-9]+ failed' | tail -1 | grep -oE '^[0-9]+')
  total_pass=$((total_pass + ${p:-0})); total_fail=$((total_fail + ${fl:-0}))
  [ $code -ne 0 ] && { failed_suites=$((failed_suites+1)); failing="$failing $(basename "$f")($code)"; }
done
echo "SUITES=$suites FAILED_SUITES=$failed_suites TOTAL_PASS=$total_pass TOTAL_FAIL=$total_fail"
[ -n "$failing" ] && echo "FAILING:$failing"
```

Expected after every task: `FAILED_SUITES=0 TOTAL_FAIL=0` and `TOTAL_PASS` ≥ 945 (grows as new tests land).

---

## Source line map (current `inc/admin-page.php`, 1,468 lines)

| Lines | Content | Destination |
|---|---|---|
| 1–27 | File docblock + ABSPATH guard | stays (rewritten for orchestrator) |
| 29–41 | Stale menu-description docblock | → `admin-menu.php` |
| 42–82 | `sn_admin_pages()` (+docblock, keep `@internal`) | → `admin-legacy-redirect.php` |
| 84–178 | `sn_admin_top_tabs()` (+docblock) | → `admin-tabs-data.php` |
| 180–214 | `sn_admin_render_toc()` | → `admin-tabs.php` |
| 216–253 | `sn_admin_render_sub_tabs()` | → `admin-tabs.php` |
| 255–270 | `sn_admin_get_sub_tabs()` | → `admin-tabs.php` |
| 272–292 | `sn_admin_resolve_active_sub()` | → `admin-tabs.php` |
| 294–314 | `sn_admin_render_section()` | → `admin-tabs.php` |
| 316–351 | `sn_admin_legacy_redirect_map()` | → `admin-legacy-redirect.php` |
| 353–412 | `sn_admin_maybe_redirect_legacy()` | → `admin-legacy-redirect.php` |
| 414–429 | `sn_admin_page_subtitle_for_tab()` | → `admin-tabs.php` |
| 431–447 | `sn_admin_page_valid_tabs()` | → `admin-tabs.php` |
| 449–459 | `sn_admin_page_tab_labels()` | → `admin-tabs.php` |
| 461–485 | `sn_admin_page_tab_for_slug()` | → `admin-legacy-redirect.php` |
| 487–502 | `sn_admin_page_hooks()` | → `admin-menu.php` |
| 504–534 | `add_action('admin_menu', …)` | → `admin-menu.php` |
| 536–616 | `add_action('admin_enqueue_scripts', …)` | → `admin-menu.php` |
| 618–631 | dispatcher docblock + `add_action('admin_init', …)` | → `admin-post-handler.php` |
| 633–901 | `sn_handle_admin_post()` | → `admin-post-handler.php` (core) + `admin-post-actions.php` (22 handlers) |
| 903–935 | `sn_theme_options_page()` head (cap/redirect/tab resolution) | stays |
| 937–1025 | flash→notice `if/elseif` | → `admin-flash-messages.php` (replaced by a loop) |
| 1028–1070 | new_id massaging + page shell | stays |
| 1095–1248 | Identity & SEO inline form | → `admin-forms/identity-and-seo.php` |
| 1254–1353 | Login inline section | → `admin-forms/login.php` |
| 1414–1462 | Links inline section | → `admin-forms/links.php` |
| other router arms | `do_action('sn_admin_*_tab')` + dashboard/automation/monitoring | stays |

> Line numbers are the pre-refactor baseline. After each task the file shrinks — **re-locate code by function name / unique comment, not by absolute line number**, once you've started editing.

---

## PHASE A — Data, framework, legacy (pure verbatim moves)

### Task 1: Extract `sn_admin_top_tabs()` → `inc/admin-tabs-data.php`

**Files:**
- Create: `inc/admin-tabs-data.php`
- Modify: `inc/admin-page.php` (delete `sn_admin_top_tabs` + its docblock, lines 84–178), `signal-and-noise-tools.php` (add require), `tests/admin-tabs.php` (add require)

- [ ] **Step 1: Create the new file with the docblock + ABSPATH guard, then move the function**

Create `inc/admin-tabs-data.php`:

```php
<?php
/**
 * Signal & Noise — admin tab data (v3.8.0+ IA).
 *
 * The single source of truth for the 6 top-level admin tabs and their
 * sub-tabs / in-page sub-sections. Pure data (no side effects, no output) so
 * registration, routing, rendering, and the legacy-redirect layer all read
 * from one place. Extracted from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// >>> paste lines 84–178 of the pre-refactor inc/admin-page.php here verbatim
//     (the `sn_admin_top_tabs()` docblock + function), then STOP.
```

Replace the `>>>` marker with the verbatim `sn_admin_top_tabs()` docblock + function body cut from `inc/admin-page.php`.

- [ ] **Step 2: Delete the function from `inc/admin-page.php`**

Remove lines 84–178 (the `sn_admin_top_tabs()` docblock + function) from `inc/admin-page.php`.

- [ ] **Step 3: Wire the require into the bootstrap**

In `signal-and-noise-tools.php`, immediately after the line `require_once SNT_PATH . 'inc/admin-page.php';`, add:

```php
// Admin UI — split out of the former 1,468-line inc/admin-page.php in v4.5.3.
// Order is irrelevant: every cross-call between these modules happens at
// runtime (inside admin_init / admin_menu / render hooks), never at load.
require_once SNT_PATH . 'inc/admin-tabs-data.php';
```

- [ ] **Step 4: Keep the tab test green by requiring the new file**

In `tests/admin-tabs.php`, change the require line (currently `require_once __DIR__ . '/../inc/admin-page.php';`) to:

```php
require_once __DIR__ . '/../inc/admin-tabs-data.php';
require_once __DIR__ . '/../inc/admin-page.php';
```

- [ ] **Step 5: Lint + run the suite**

Run: `php -l inc/admin-tabs-data.php && php tests/admin-tabs.php | tail -1`
Expected: `No syntax errors` then the admin-tabs suite passes. Then run the aggregate tally — expect `FAILED_SUITES=0 TOTAL_FAIL=0 TOTAL_PASS=945`.

- [ ] **Step 6: Commit**

```bash
git add inc/admin-tabs-data.php inc/admin-page.php signal-and-noise-tools.php tests/admin-tabs.php
git commit -m "refactor(admin): extract sn_admin_top_tabs() to inc/admin-tabs-data.php"
```

---

### Task 2: Extract accessors + nav renderers → `inc/admin-tabs.php`

**Files:**
- Create: `inc/admin-tabs.php`
- Modify: `inc/admin-page.php` (delete the 8 functions), `signal-and-noise-tools.php`, `tests/admin-tabs.php`

Functions to move (verbatim, in this order): `sn_admin_render_toc()` (180–214), `sn_admin_render_sub_tabs()` (216–253), `sn_admin_get_sub_tabs()` (255–270), `sn_admin_resolve_active_sub()` (272–292), `sn_admin_render_section()` (294–314), `sn_admin_page_subtitle_for_tab()` (414–429), `sn_admin_page_valid_tabs()` (431–447), `sn_admin_page_tab_labels()` (449–459).

- [ ] **Step 1: Create the file scaffold**

Create `inc/admin-tabs.php`:

```php
<?php
/**
 * Signal & Noise — admin tab framework.
 *
 * Derived accessors over sn_admin_top_tabs() (valid tabs, labels, subtitle,
 * sub-tab resolution) plus the nav/section renderers (top-tab TOC, sub-tab
 * nav, section wrapper). No data tables here — those live in
 * inc/admin-tabs-data.php. Extracted from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// >>> paste the 8 functions listed in Task 2 here, verbatim, in order
```

- [ ] **Step 2: Move the 8 functions** out of `inc/admin-page.php` into the new file (cut from source, paste after the guard).

- [ ] **Step 3: Add the bootstrap require** — in `signal-and-noise-tools.php`, after the `admin-tabs-data.php` require, add:

```php
require_once SNT_PATH . 'inc/admin-tabs.php';
```

- [ ] **Step 4: Keep the tab test green** — in `tests/admin-tabs.php`, add after the `admin-tabs-data.php` require:

```php
require_once __DIR__ . '/../inc/admin-tabs.php';
```

- [ ] **Step 5: Lint + run** — `php -l inc/admin-tabs.php` then the aggregate tally. Expect `FAILED_SUITES=0 TOTAL_FAIL=0`.

- [ ] **Step 6: Commit**

```bash
git add inc/admin-tabs.php inc/admin-page.php signal-and-noise-tools.php tests/admin-tabs.php
git commit -m "refactor(admin): extract tab accessors + nav renderers to inc/admin-tabs.php"
```

---

### Task 3: Extract the legacy-URL layer → `inc/admin-legacy-redirect.php`

**Files:**
- Create: `inc/admin-legacy-redirect.php`
- Modify: `inc/admin-page.php` (delete 4 functions), `signal-and-noise-tools.php`, `tests/admin-tabs.php`, `tests/legacy-url-redirect.php`

Functions to move (verbatim, in this order): `sn_admin_pages()` (42–82, **keep the `@internal` docblock framing intact** — `tests/legacy-url-redirect.php` greps for it), `sn_admin_legacy_redirect_map()` (316–351), `sn_admin_page_tab_for_slug()` (461–485), `sn_admin_maybe_redirect_legacy()` (353–412).

- [ ] **Step 1: Create the file scaffold**

Create `inc/admin-legacy-redirect.php`:

```php
<?php
/**
 * Signal & Noise — legacy admin-URL compatibility.
 *
 * The legacy 12-slug page registry (sn_admin_pages), the legacy-tab →
 * canonical (top tab + sub-tab + anchor) redirect map, slug→tab resolution,
 * and the 301 redirect performed before dispatch. Keeps every pre-v3.8.0
 * ?page=sn-<slug> / ?tab=<slug> deep link working. Extracted from
 * inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// >>> paste the 4 functions listed in Task 3 here, verbatim, in order
```

- [ ] **Step 2: Move the 4 functions** out of `inc/admin-page.php`.

- [ ] **Step 3: Add the bootstrap require** — after the `admin-tabs.php` require:

```php
require_once SNT_PATH . 'inc/admin-legacy-redirect.php';
```

- [ ] **Step 4: Finalize the tab test requires** — in `tests/admin-tabs.php`, the require block should now load the three files the functions live in (drop the `admin-page.php` require — the test no longer needs it). Final state:

```php
require_once __DIR__ . '/../inc/admin-tabs-data.php';
require_once __DIR__ . '/../inc/admin-tabs.php';
require_once __DIR__ . '/../inc/admin-legacy-redirect.php';
```

- [ ] **Step 5: Re-point the legacy-redirect source-grep test** — in `tests/legacy-url-redirect.php`, change:

```php
$path = __DIR__ . '/../inc/admin-page.php';
```

to:

```php
$path = __DIR__ . '/../inc/admin-legacy-redirect.php';
```

(All 6 needles — `function sn_admin_maybe_redirect_legacy`, `$_GET['page']`, the canonical-URL string, `'login'`, `'rss'`, the `sn_admin_pages` `@internal`/`@deprecated` framing — now live in this one file by design.)

- [ ] **Step 6: Lint + run** — `php -l inc/admin-legacy-redirect.php`, then `php tests/legacy-url-redirect.php | tail -1`, then the aggregate tally. Expect `FAILED_SUITES=0 TOTAL_FAIL=0 TOTAL_PASS=945`.

- [ ] **Step 7: Commit**

```bash
git add inc/admin-legacy-redirect.php inc/admin-page.php signal-and-noise-tools.php tests/admin-tabs.php tests/legacy-url-redirect.php
git commit -m "refactor(admin): extract legacy-URL redirect layer to inc/admin-legacy-redirect.php"
```

---

### Task 4: Extract menu registration + asset enqueue → `inc/admin-menu.php`

**Files:**
- Create: `inc/admin-menu.php`
- Modify: `inc/admin-page.php` (delete `sn_admin_page_hooks` + both `add_action` closures + the stale 29–41 docblock), `signal-and-noise-tools.php`

Move (verbatim): the stale menu docblock (29–41), `sn_admin_page_hooks()` (487–502), the `add_action( 'admin_menu', … )` closure (504–534), the `add_action( 'admin_enqueue_scripts', … )` closure (536–616).

- [ ] **Step 1: Create the file scaffold**

Create `inc/admin-menu.php`:

```php
<?php
/**
 * Signal & Noise — admin menu registration + asset enqueue.
 *
 * Registers the top-level "Signal & Noise" menu and its 6 submenu entries
 * (admin_menu), caches the resulting hook suffixes (sn_admin_page_hooks), and
 * enqueues admin.css/admin.js + the per-tab Suggest+Apply scripts
 * (admin_enqueue_scripts). Extracted from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// >>> paste, verbatim and in this order:
//     1. the stale "Admin page: Signal & Noise — top-level menu" docblock (old lines 29–41)
//     2. sn_admin_page_hooks()           (old lines 487–502)
//     3. add_action( 'admin_menu', … )    (old lines 504–534)
//     4. add_action( 'admin_enqueue_scripts', … ) (old lines 536–616)
```

- [ ] **Step 2: Move the four blocks** out of `inc/admin-page.php`.

- [ ] **Step 3: Add the bootstrap require** — after the `admin-legacy-redirect.php` require:

```php
require_once SNT_PATH . 'inc/admin-menu.php';
```

- [ ] **Step 4: Lint + run** — `php -l inc/admin-menu.php`, then the aggregate tally. Expect `FAILED_SUITES=0 TOTAL_FAIL=0`. (No test directly covers menu/enqueue; the gate is "still parses + suite green". `cron-dashboard-admin.php` and `audit-log-admin.php` call `sn_admin_page_hooks()` at runtime — still globally available.)

- [ ] **Step 5: Commit**

```bash
git add inc/admin-menu.php inc/admin-page.php signal-and-noise-tools.php
git commit -m "refactor(admin): extract menu registration + asset enqueue to inc/admin-menu.php"
```

---

## PHASE B — Flash registry (responsibility #2)

### Task 5: TDD the shared flash registry → `inc/admin-flash-messages.php`

**Files:**
- Create: `tests/admin-flash-messages.php`, `inc/admin-flash-messages.php`
- Modify: `signal-and-noise-tools.php`

- [ ] **Step 1: Write the failing test**

Create `tests/admin-flash-messages.php`:

```php
<?php
/**
 * Unit tests for the shared admin flash-message registry
 * (inc/admin-flash-messages.php).
 *
 * Guards the v4.5.3 collapse of the two duplicate flash ladders into one data
 * source + resolver. Covers all three message shapes: exact-match static
 * codes, count/id-prefixed codes, and live-data codes.
 *
 * Run: php tests/admin-flash-messages.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SN_PLAUSIBLE_BATCH_KEY', 'sn_pl_batch' );

$GLOBALS['__settings']  = array( 'login.slug' => 'secret-door' );
$GLOBALS['__transient'] = false;
$GLOBALS['__pl_error']  = null;

function sn_setting( $path, $default = null ) { return $GLOBALS['__settings'][ $path ] ?? $default; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function get_transient( $k ) { return $GLOBALS['__transient']; }
function sn_plausible_last_error() { return $GLOBALS['__pl_error']; }
function number_format_i18n( $n ) { return (string) $n; }

require_once __DIR__ . '/../inc/admin-flash-messages.php';

$pass = 0; $fail = 0;
function fm_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

echo "\nTest 1: exact-match static codes\n";
fm_eq( array( 'success', 'Identity settings saved.' ), sn_admin_flash_to_notice( 'identity_saved' ), 'identity_saved' );
fm_eq( array( 'info', 'No changes to save.' ), sn_admin_flash_to_notice( 'identity_unchanged' ), 'identity_unchanged' );
fm_eq( array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' ), sn_admin_flash_to_notice( 'cf_purged_unconfigured' ), 'cf_purged_unconfigured keeps warning severity' );
fm_eq( array( 'success', 'Block migration scan complete.' ), sn_admin_flash_to_notice( 'block_migrations_scanned' ), 'block_migrations_scanned' );

echo "\nTest 2: count-prefixed codes parse the trailing int\n";
fm_eq( array( 'success', '12 database override(s) cleared. Site is reading from theme files.' ), sn_admin_flash_to_notice( 'cleared_12' ), 'cleared_12' );
fm_eq( array( 'success', 'Full reset: 3 override(s) cleared + all caches purged.' ), sn_admin_flash_to_notice( 'reset_3' ), 'reset_3' );
fm_eq( array( 'success', '7 post(s) cleaned. Reading-time cache rebuilt.' ), sn_admin_flash_to_notice( 'rt_applied_7' ), 'rt_applied_7' );

echo "\nTest 3: id-prefixed codes resolve to static message\n";
fm_eq( array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' ), sn_admin_flash_to_notice( 'wh_added_abc123' ), 'wh_added_<id>' );
$rotated = sn_admin_flash_to_notice( 'wh_rotated_abc123' );
fm_eq( 'success', $rotated[0], 'wh_rotated_<id> severity' );
fm_eq( true, false !== strpos( $rotated[1], 'Signing secret was rotated' ), 'wh_rotated_<id> message body' );

echo "\nTest 4: live-data codes compute from state\n";
$login = sn_admin_flash_to_notice( 'login_saved' );
fm_eq( 'success', $login[0], 'login_saved severity' );
fm_eq( true, false !== strpos( $login[1], 'https://example.test/secret-door' ), 'login_saved embeds current slug URL' );

$GLOBALS['__transient'] = array( 'data' => array( 'visitors' => array( 'value' => 1234 ) ) );
$ok = sn_admin_flash_to_notice( 'pl_test_ok' );
fm_eq( 'success', $ok[0], 'pl_test_ok severity' );
fm_eq( true, false !== strpos( $ok[1], '1234 visitor(s)' ), 'pl_test_ok embeds visitor count from transient' );

$GLOBALS['__pl_error'] = array( 'code' => 503, 'message' => 'upstream down' );
$err = sn_admin_flash_to_notice( 'pl_test_err' );
fm_eq( 'error', $err[0], 'pl_test_err severity' );
fm_eq( true, false !== strpos( $err[1], 'HTTP 503' ), 'pl_test_err embeds error code' );

echo "\nTest 5: unknown code returns null (renders no notice)\n";
fm_eq( null, sn_admin_flash_to_notice( 'totally_unknown_code' ), 'unknown → null' );
fm_eq( null, sn_admin_flash_to_notice( '' ), 'empty → null' );

echo "\nTest 6: coordination guard — every exact code the dispatcher emits resolves\n";
$emitted = array(
	'identity_saved','identity_unchanged','login_empty','login_failed','pl_saved','pl_cleared',
	'pl_unchanged','pl_locked','pl_test_unconfigured','cf_saved','cf_purged_ok','cf_purged_unconfigured',
	'purged','wh_updated','wh_deleted','wh_invalid','wh_not_found','insights_scanned','insights_failed',
	'insights_dismissed','insights_snoozed','insights_done','insights_settings_saved','health_scanned',
	'pattern_adoption_scanned','block_migrations_scanned','audit_retention_saved','audit_retention_unchanged',
);
foreach ( $emitted as $code ) {
	fm_eq( true, null !== sn_admin_flash_to_notice( $code ), "resolver covers '$code'" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php tests/admin-flash-messages.php`
Expected: FATAL / failure — `inc/admin-flash-messages.php` does not exist yet (require fails).

- [ ] **Step 3: Create the implementation**

Create `inc/admin-flash-messages.php`:

```php
<?php
/**
 * Signal & Noise — admin flash-message registry.
 *
 * Single source of truth for the ?sn_flash=… → admin-notice translation.
 * Before v4.5.3 this lived as a second if/elseif inside sn_theme_options_page(),
 * maintained ~40 lines away from the dispatcher that emits the codes — the two
 * had to be hand-kept in sync. Now the dispatcher emits a code and this module
 * owns the code → [severity, message] mapping. Three message shapes:
 *   1. exact-match static codes      → sn_admin_flash_messages()
 *   2. count/id-prefixed codes        → parsed in the resolver
 *   3. live-data codes (login/pl_test)→ computed from current state
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static flash code → [ severity, message-html ] map (exact-match codes only).
 *
 * Messages may contain inline markup (<a>, <code>, <strong>, HTML entities) —
 * the renderer runs them through wp_kses_post, so do NOT escape here.
 *
 * @return array<string,array{0:string,1:string}>
 */
function sn_admin_flash_messages() {
	return array(
		'identity_saved'            => array( 'success', 'Identity settings saved.' ),
		'identity_unchanged'        => array( 'info', 'No changes to save.' ),
		'login_empty'               => array( 'error', 'Login slug cannot be empty.' ),
		'login_failed'              => array( 'error', 'Login slug save failed.' ),
		'pl_saved'                  => array( 'success', 'Stats API key saved. Caches purged — widgets refresh on next dashboard view.' ),
		'pl_cleared'                => array( 'success', 'Stats API key cleared. Caches purged.' ),
		'pl_unchanged'              => array( 'info', 'No changes to save.' ),
		'pl_locked'                 => array( 'error', 'Token is locked by the SN_PLAUSIBLE_STATS_TOKEN constant — remove the constant in wp-config.php to edit here.' ),
		'pl_test_unconfigured'      => array( 'error', 'Plausible not fully configured (missing domain or token).' ),
		'cf_saved'                  => array( 'success', 'Cloudflare settings saved.' ),
		'cf_purged_ok'              => array( 'success', 'Cloudflare zone purge dispatched.' ),
		'cf_purged_unconfigured'    => array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' ),
		'purged'                    => array( 'success', 'All caches purged.' ),
		'wh_updated'                => array( 'success', 'Webhook updated.' ),
		'wh_deleted'                => array( 'success', 'Webhook deleted. Pending retries (if any) will drop on next dispatch.' ),
		'wh_invalid'                => array( 'error', 'Could not add webhook — name and valid URL are required.' ),
		'wh_not_found'              => array( 'error', 'Webhook not found.' ),
		'insights_scanned'          => array( 'success', 'Insights scan complete — recommendations below.' ),
		'insights_failed'           => array( 'error', 'Insights scan failed. Check that an AI provider is configured under Settings → Connectors.' ),
		'insights_dismissed'        => array( 'success', 'Recommendation dismissed.' ),
		'insights_snoozed'          => array( 'success', 'Recommendation snoozed for 30 days.' ),
		'insights_done'             => array( 'success', 'Recommendation marked as done.' ),
		'insights_settings_saved'   => array( 'success', 'Insights settings saved.' ),
		'health_scanned'            => array( 'success', 'Scan complete — findings below.' ),
		'pattern_adoption_scanned'  => array( 'success', 'Scan complete.' ),
		'block_migrations_scanned'  => array( 'success', 'Block migration scan complete.' ),
		'audit_retention_saved'     => array( 'success', 'Audit retention saved.' ),
		'audit_retention_unchanged' => array( 'info', 'Audit retention unchanged.' ),
	);
}

/**
 * Resolve a flash code to a [ severity, message-html ] notice, or null when the
 * code is unknown (renders no notice — matches the old "no matching branch").
 *
 * @param string $flash The ?sn_flash=… value (already sanitized by the caller).
 * @return array{0:string,1:string}|null
 */
function sn_admin_flash_to_notice( $flash ) {
	$static = sn_admin_flash_messages();
	if ( isset( $static[ $flash ] ) ) {
		return $static[ $flash ];
	}

	// Live-data codes — message computed from current state at render time.
	if ( 'login_saved' === $flash ) {
		$slug_now  = sn_setting( 'login.slug', 'sn-login' );
		$login_url = home_url( '/' . $slug_now );
		return array( 'success', 'Login slug saved. New URL: <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>' );
	}
	if ( 'pl_test_ok' === $flash ) {
		$cached   = get_transient( SN_PLAUSIBLE_BATCH_KEY );
		$visitors = is_array( $cached ) && isset( $cached['data']['visitors']['value'] ) ? (int) $cached['data']['visitors']['value'] : 0;
		return array( 'success', '&#10003; API call succeeded — ' . number_format_i18n( $visitors ) . ' visitor(s) in last 7 days.' );
	}
	if ( 'pl_test_err' === $flash ) {
		$err    = sn_plausible_last_error();
		$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
		return array( 'error', '&#10005; API call failed &mdash; ' . $detail );
	}

	// Count-prefixed codes — parse the trailing int into the message template.
	if ( 0 === strpos( $flash, 'rt_applied_' ) ) {
		$count = (int) substr( $flash, strlen( 'rt_applied_' ) );
		return array( 'success', sprintf( '%d post(s) cleaned. Reading-time cache rebuilt.', $count ) );
	}
	if ( 0 === strpos( $flash, 'cleared_' ) ) {
		$count = (int) substr( $flash, strlen( 'cleared_' ) );
		return array( 'success', $count . ' database override(s) cleared. Site is reading from theme files.' );
	}
	if ( 0 === strpos( $flash, 'reset_' ) ) {
		$count = (int) substr( $flash, strlen( 'reset_' ) );
		return array( 'success', 'Full reset: ' . $count . ' override(s) cleared + all caches purged.' );
	}

	// Id-prefixed codes — static message; the id is consumed elsewhere
	// (sn_theme_options_page massages $_GET['new_id'] for the Webhooks row highlight).
	if ( 0 === strpos( $flash, 'wh_added_' ) ) {
		return array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' );
	}
	if ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
		return array( 'success', 'Webhook updated. <strong>Signing secret was rotated</strong> — copy the new value below before navigating away.' );
	}

	return null;
}
```

- [ ] **Step 4: Wire the bootstrap require** — in `signal-and-noise-tools.php`, after the `admin-menu.php` require:

```php
require_once SNT_PATH . 'inc/admin-flash-messages.php';
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php tests/admin-flash-messages.php | tail -1`
Expected: PASS (all assertions). Then aggregate tally — `FAILED_SUITES=0 TOTAL_FAIL=0`, `TOTAL_PASS` now ≥ 945 + (new assertions).

- [ ] **Step 6: Commit**

```bash
git add inc/admin-flash-messages.php tests/admin-flash-messages.php signal-and-noise-tools.php
git commit -m "feat(admin): add shared flash-message registry + resolver with unit tests"
```

---

### Task 6: Swap the renderer to consume the resolver

**Files:**
- Modify: `inc/admin-page.php` (replace the 937–1025 `if/elseif` flash translator with the resolver loop)

- [ ] **Step 1: Replace the translator block**

In `inc/admin-page.php`, inside `sn_theme_options_page()`, delete the entire `if ( isset( $_GET['sn_flash'] ) ) { … }` block (the ~85-line `if/elseif` ladder over `$flash`, old lines 937–1025) and replace it with:

```php
		// Form processing happens in sn_handle_admin_post() on admin_init —
		// before any output. This block just translates ?sn_flash=… into a
		// notice for the post-redirect GET request, via the shared flash
		// registry (inc/admin-flash-messages.php) — the single source of truth
		// consumed by both the dispatcher (which emits the codes) and here.
		if ( isset( $_GET['sn_flash'] ) ) {
			$notice = sn_admin_flash_to_notice( sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) ) );
			if ( $notice ) {
				$notices[] = $notice;
			}
		}
```

Leave the downstream `new_id` massaging block (old lines 1028–1037) and the notices render loop unchanged.

- [ ] **Step 2: Lint + run** — `php -l inc/admin-page.php`, then the aggregate tally. Expect `FAILED_SUITES=0 TOTAL_FAIL=0`. (Behavior identical: the resolver returns the same `[severity, message]` pairs the ladder produced.)

- [ ] **Step 3: Commit**

```bash
git add inc/admin-page.php
git commit -m "refactor(admin): render flash notices via the shared resolver (removes the duplicate ladder)"
```

---

## PHASE C — Dispatcher + actions (responsibility #1)

### Task 7: TDD the extracted action handlers → `inc/admin-post-actions.php`

**Files:**
- Create: `tests/admin-post-actions.php`, `inc/admin-post-actions.php`
- Modify: `signal-and-noise-tools.php`, `tests/audit-retention-bounds.php`

> The 22 handler bodies are verbatim lifts of the `if/elseif` arms in `sn_handle_admin_post()` (old lines 654–848), each transformed by: wrap in `function sn_handle_<action>( $post ) { … }`, replace every `$_POST` with `$post`, and replace `$flash = '<code>';` with `return '<code>';`. **Preserve everything else byte-for-byte** — including the `'••••'` placeholder checks, the constant locks, every `function_exists()` guard, and `(int) $post['audit_retention_days']` (no `wp_unslash` — it was never there).

- [ ] **Step 1: Write the failing test**

Create `tests/admin-post-actions.php`:

```php
<?php
/**
 * Unit tests for the extracted admin POST action handlers
 * (inc/admin-post-actions.php) + the dispatch map (inc/admin-post-handler.php).
 *
 * Before v4.5.3 these lived inside a 270-line if/elseif in
 * sn_handle_admin_post() with ZERO unit coverage. Each handler is now a
 * standalone fn( array $post ): string returning a flash code, so flash +
 * side effects are assertable directly.
 *
 * Run: php tests/admin-post-actions.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$GLOBALS['__options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$changed = ! array_key_exists( $name, $GLOBALS['__options'] ) || $GLOBALS['__options'][ $name ] !== $value;
	$GLOBALS['__options'][ $name ] = $value;
	return $changed; // mirror WP: returns false when the value is unchanged
}
function delete_option( $name ) { unset( $GLOBALS['__options'][ $name ] ); return true; }
function get_bloginfo( $what ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '~[^a-z0-9\-]+~i', '-', (string) $s ), '-' ) ); }
function esc_url_raw( $s ) { return $s; }
function wp_unslash( $v ) { return $v; }
function add_action( $hook, $cb = null, $p = 10, $a = 1 ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }

require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/admin-post-actions.php';
require_once __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function pa_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function pa_reset_store() { $GLOBALS['__options'] = array(); sn_setting_reset_cache(); }

echo "\nTest: sn_handle_save_login()\n";
pa_reset_store();
pa_eq( 'login_empty', sn_handle_save_login( array() ), 'missing slug → login_empty' );
pa_eq( 'login_empty', sn_handle_save_login( array( 'login_slug' => '   ' ) ), 'blank slug → login_empty' );
pa_eq( 'login_saved', sn_handle_save_login( array( 'login_slug' => 'Secret Door' ) ), 'valid slug → login_saved' );
pa_eq( 'secret-door', sn_setting( 'login.slug' ), 'slug persisted + sanitized' );

echo "\nTest: sn_handle_audit_save_retention() clamps [7,365]\n";
pa_reset_store();
sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) );
pa_eq( 365, sn_setting( 'audit.retention_days' ), '999 → 365 (max)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 2 ) );
pa_eq( 7, sn_setting( 'audit.retention_days' ), '2 → 7 (min)' );
sn_handle_audit_save_retention( array( 'audit_retention_days' => 90 ) );
pa_eq( 90, sn_setting( 'audit.retention_days' ), '90 passes through' );
pa_eq( 'audit_retention_saved', sn_handle_audit_save_retention( array( 'audit_retention_days' => 45 ) ), 'changed → audit_retention_saved' );

echo "\nTest: sn_handle_save_identity()\n";
pa_reset_store();
pa_eq( 'identity_saved', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'first save → identity_saved' );
pa_eq( 'identity_unchanged', sn_handle_save_identity( array( 'identity_site_name' => 'Acme' ) ), 'identical re-save → identity_unchanged' );

echo "\nTest: sn_handle_cf_save() honors constant locks\n";
define( 'SN_CLOUDFLARE_API_TOKEN', 'locked-tok' );
define( 'SN_CLOUDFLARE_ZONE_ID', 'locked-zone' );
pa_reset_store();
pa_eq( 'cf_saved', sn_handle_cf_save( array( 'sn_cf_token' => 'attempt', 'sn_cf_zone' => 'attempt' ) ), 'returns cf_saved' );
pa_eq( array(), $GLOBALS['__options'], 'no option written when both constants are defined (locked)' );

echo "\nTest: sn_handle_pl_save() branches\n";
define( 'SN_PLAUSIBLE_TOKEN_OPT', 'sn_pl_token' );
function sn_pl_admin_invalidate_caches() {}
$GLOBALS['__options'] = array( 'sn_pl_token' => 'old' );
pa_eq( 'pl_cleared', sn_handle_pl_save( array( 'sn_pl_token' => 'clear' ) ), "'clear' → pl_cleared" );
pa_eq( false, array_key_exists( 'sn_pl_token', $GLOBALS['__options'] ), 'token option deleted' );
pa_eq( 'pl_unchanged', sn_handle_pl_save( array( 'sn_pl_token' => '' ) ), 'empty → pl_unchanged' );
pa_eq( 'pl_saved', sn_handle_pl_save( array( 'sn_pl_token' => 'real-new-token' ) ), 'real token → pl_saved' );
pa_eq( 'real-new-token', get_option( 'sn_pl_token' ), 'token persisted' );

echo "\nTest: sn_admin_post_handlers() map is complete + callable\n";
$map = sn_admin_post_handlers();
pa_eq( 22, count( $map ), 'map has 22 actions' );
foreach ( $map as $action => $cb ) {
	pa_eq( true, is_callable( $cb ), "handler for '$action' is callable" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php tests/admin-post-actions.php`
Expected: FATAL — `inc/admin-post-actions.php` / `inc/admin-post-handler.php` don't exist yet. (Task 8 creates the handler file; this test stays red until then — that's expected. To make Step-1 progress visible you may temporarily comment the `admin-post-handler.php` require + the map test block, but the canonical red→green happens once both files exist in Task 8. Simpler: author both files now — see Step 3 + Task 8 Step 1 — then run once.)

- [ ] **Step 3: Create the actions file**

Create `inc/admin-post-actions.php`:

```php
<?php
/**
 * Signal & Noise — admin POST action handlers.
 *
 * One small function per form action, each fn( array $post ): string that
 * performs the action's side effects (option writes, filter dispatch, module
 * calls) and returns a ?sn_flash=… code. Dispatched by sn_handle_admin_post()
 * (inc/admin-post-handler.php) via the sn_admin_post_handlers() map. Extracted
 * verbatim from the 270-line if/elseif in inc/admin-page.php in v4.5.3.
 *
 * Handlers receive the RAW $_POST and unslash per-field exactly as the original
 * arms did (notably: save_identity passes the raw array straight to
 * sn_settings_save(), which is the pre-existing behavior — do not "fix" it).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_clear_overrides( $post ) {
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return 'cleared_' . $count;
}

function sn_handle_purge_caches( $post ) {
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
	return 'purged';
}

function sn_handle_full_reset( $post ) {
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true ) );
	return 'reset_' . $count;
}

function sn_handle_save_identity( $post ) {
	$saved = sn_settings_save( $post );
	return $saved ? 'identity_saved' : 'identity_unchanged';
}

function sn_handle_save_login( $post ) {
	$slug = isset( $post['login_slug'] ) ? sanitize_title( wp_unslash( $post['login_slug'] ) ) : '';
	if ( ! $slug ) {
		return 'login_empty';
	}
	$ok = sn_setting_update( 'login.slug', $slug );
	return $ok ? 'login_saved' : 'login_failed';
}

function sn_handle_pl_save( $post ) {
	if ( defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN ) {
		return 'pl_locked';
	}
	$new_token = isset( $post['sn_pl_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_pl_token'] ) ) : '';
	if ( 'clear' === $new_token ) {
		delete_option( SN_PLAUSIBLE_TOKEN_OPT );
		sn_pl_admin_invalidate_caches();
		return 'pl_cleared';
	} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
		update_option( SN_PLAUSIBLE_TOKEN_OPT, $new_token, false ); // not autoloaded
		sn_pl_admin_invalidate_caches();
		return 'pl_saved';
	}
	return 'pl_unchanged';
}

function sn_handle_pl_test( $post ) {
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		return 'pl_test_unconfigured';
	}
	delete_transient( SN_PLAUSIBLE_ERR_KEY ); // force-fresh
	$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
	return is_array( $result ) ? 'pl_test_ok' : 'pl_test_err';
}

function sn_handle_cf_save( $post ) {
	$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

	if ( ! $token_const ) {
		$new_token = isset( $post['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( SN_CF_TOKEN_OPT );
		} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
			update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
		}
	}
	if ( ! $zone_const ) {
		$new_zone = isset( $post['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_zone'] ) ) : '';
		if ( 'clear' === $new_zone ) {
			delete_option( SN_CF_ZONE_OPT );
		} elseif ( '' !== $new_zone ) {
			update_option( SN_CF_ZONE_OPT, $new_zone, true );
		}
	}
	return 'cf_saved';
}

function sn_handle_cf_purge_now( $post ) {
	return sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
}

function sn_handle_apply_reading_time_cleanup( $post ) {
	$count = (int) sn_apply_legacy_reading_time_cleanup();
	return 'rt_applied_' . $count;
}

function sn_handle_health_scan( $post ) {
	if ( function_exists( 'sn_health_run_scan' ) ) {
		sn_health_run_scan();
	}
	return 'health_scanned';
}

function sn_handle_webhook_add( $post ) {
	if ( function_exists( 'sn_webhook_create' ) ) {
		$result = sn_webhook_create( wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_invalid';
		}
		return 'wh_added_' . $result['id'];
	}
	return 'wh_invalid';
}

function sn_handle_webhook_update( $post ) {
	if ( function_exists( 'sn_webhook_update' ) ) {
		$id     = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		$rotate = ! empty( $post['rotate_secret'] );
		$result = sn_webhook_update( $id, wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_not_found';
		}
		return $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
	}
	return 'wh_not_found';
}

function sn_handle_webhook_delete( $post ) {
	if ( function_exists( 'sn_webhook_delete' ) ) {
		$id = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		sn_webhook_delete( $id );
	}
	return 'wh_deleted';
}

function sn_handle_insights_run( $post ) {
	if ( function_exists( 'snt_insights_run_scan' ) ) {
		$force  = ! empty( $post['force'] );
		$result = snt_insights_run_scan( $force );
		return is_wp_error( $result ) ? 'insights_failed' : 'insights_scanned';
	}
	return 'insights_failed';
}

function sn_handle_insights_dismiss( $post ) {
	if ( function_exists( 'snt_insights_dismiss' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_dismiss( $id );
	}
	return 'insights_dismissed';
}

function sn_handle_insights_snooze( $post ) {
	if ( function_exists( 'snt_insights_snooze' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_snooze( $id );
	}
	return 'insights_snoozed';
}

function sn_handle_insights_mark_done( $post ) {
	if ( function_exists( 'snt_insights_mark_done' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_mark_done( $id );
	}
	return 'insights_done';
}

function sn_handle_save_insights_settings( $post ) {
	// v4.2.0 (D-06): write via sn_setting_update() — busts the per-request
	// cache so the cron sync below reads back the new value.
	$enabled = ! empty( $post['insights_weekly_cron'] );
	sn_setting_update( 'insights.weekly_cron_enabled', $enabled );

	if ( $enabled ) {
		if ( function_exists( 'snt_insights_maybe_schedule_weekly_cron' ) ) {
			snt_insights_maybe_schedule_weekly_cron();
		}
	} else {
		if ( function_exists( 'snt_insights_unschedule_weekly_cron' ) ) {
			snt_insights_unschedule_weekly_cron();
		}
	}
	return 'insights_settings_saved';
}

function sn_handle_audit_save_retention( $post ) {
	$raw  = isset( $post['audit_retention_days'] ) ? (int) $post['audit_retention_days'] : 90;
	$days = max( 7, min( 365, $raw ) );
	$ok   = sn_setting_update( 'audit.retention_days', $days );
	return $ok ? 'audit_retention_saved' : 'audit_retention_unchanged';
}

function sn_handle_pattern_adoption_scan( $post ) {
	if ( function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		snt_pattern_adoption_run_scan();
	}
	return 'pattern_adoption_scanned';
}

function sn_handle_block_migrations_scan( $post ) {
	if ( function_exists( 'snt_block_migrations_run_scan' ) ) {
		snt_block_migrations_run_scan();
	}
	return 'block_migrations_scanned';
}
```

- [ ] **Step 4: Add the bootstrap require** — in `signal-and-noise-tools.php`, after the `admin-flash-messages.php` require:

```php
require_once SNT_PATH . 'inc/admin-post-handler.php';
require_once SNT_PATH . 'inc/admin-post-actions.php';
```

(The `admin-post-handler.php` file is created in Task 8; add both require lines now so the order is final — but **do Task 8 before running the suite**, since the file must exist.)

- [ ] **Step 5: Re-point + upgrade the audit-retention test**

In `tests/audit-retention-bounds.php`:
1. After the existing `require __DIR__ . '/../inc/settings.php';`, add:
   ```php
   require_once __DIR__ . '/../inc/admin-post-actions.php';
   ```
2. Replace the source-grep block (the `$admin_src = file_get_contents( … 'admin-page.php' );` + `strpos( $admin_src, "max( 7, min( 365" )` check) with a real behavioral assertion + a re-pointed source-grep:
   ```php
   // Real behavioral check: the extracted handler clamps + persists.
   $GLOBALS['__options'] = array();
   sn_setting_reset_cache();
   sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) );
   assertEq( 365, sn_setting( 'audit.retention_days' ), 'handler clamps 999 → 365 (real call)' );
   sn_handle_audit_save_retention( array( 'audit_retention_days' => 1 ) );
   assertEq( 7, sn_setting( 'audit.retention_days' ), 'handler clamps 1 → 7 (real call)' );

   // The clamp expression now lives in inc/admin-post-actions.php.
   $actions_src = file_get_contents( __DIR__ . '/../inc/admin-post-actions.php' );
   if ( false !== strpos( $actions_src, "max( 7, min( 365" ) ) {
       $pass++;
       echo "PASS: admin-post-actions.php contains the clamp expression\n";
   } else {
       $fail++;
       echo "FAIL: admin-post-actions.php does not contain expected clamp expression\n";
   }
   ```
   (`tests/audit-retention-bounds.php` already stubs `get_option`/`update_option`; `sn_setting`/`sn_setting_update`/`sn_setting_reset_cache` come from the already-required `settings.php`. `admin-post-actions.php` defines only functions at load, so requiring it needs only `ABSPATH`.)

- [ ] **Step 6: Run** (after Task 8 creates the handler file): `php tests/admin-post-actions.php | tail -1` and `php tests/audit-retention-bounds.php | tail -1` → both PASS.

- [ ] **Step 7: Commit** (together with Task 8 — see Task 8 Step 5).

---

### Task 8: Extract the dispatcher core → `inc/admin-post-handler.php`

**Files:**
- Create: `inc/admin-post-handler.php`
- Modify: `inc/admin-page.php` (delete the dispatcher docblock, `add_action('admin_init', …)`, and the whole `sn_handle_admin_post()` function — old lines 618–901)

- [ ] **Step 1: Create the handler file**

Create `inc/admin-post-handler.php`:

```php
<?php
/**
 * Signal & Noise — admin form-submission dispatcher.
 *
 * Handles all SN admin POST submissions on admin_init (before any output, so
 * wp_safe_redirect/header work cleanly — Post/Redirect/Get). Validates the
 * shared nonce + capability + page allowlist, dispatches to the matching
 * sn_handle_<action>() in inc/admin-post-actions.php via sn_admin_post_handlers(),
 * then redirects to the canonical top-tab + sub-tab + anchor carrying the
 * resulting ?sn_flash=… code. Extracted from inc/admin-page.php in v4.5.3.
 *
 * Save status survives the redirect via ?sn_flash, which sn_theme_options_page()
 * resolves to an admin notice through inc/admin-flash-messages.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action → handler-callback map. Single source of truth for which form actions
 * the dispatcher accepts; each callback lives in inc/admin-post-actions.php and
 * returns a ?sn_flash=… code.
 *
 * @return array<string,callable-string>
 */
function sn_admin_post_handlers() {
	return array(
		'clear_overrides'            => 'sn_handle_clear_overrides',
		'purge_caches'               => 'sn_handle_purge_caches',
		'full_reset'                 => 'sn_handle_full_reset',
		'save_identity'              => 'sn_handle_save_identity',
		'save_login'                 => 'sn_handle_save_login',
		'pl_save'                    => 'sn_handle_pl_save',
		'pl_test'                    => 'sn_handle_pl_test',
		'cf_save'                    => 'sn_handle_cf_save',
		'cf_purge_now'               => 'sn_handle_cf_purge_now',
		'apply_reading_time_cleanup' => 'sn_handle_apply_reading_time_cleanup',
		'health_scan'                => 'sn_handle_health_scan',
		'webhook_add'                => 'sn_handle_webhook_add',
		'webhook_update'             => 'sn_handle_webhook_update',
		'webhook_delete'             => 'sn_handle_webhook_delete',
		'insights_run'               => 'sn_handle_insights_run',
		'insights_dismiss'           => 'sn_handle_insights_dismiss',
		'insights_snooze'            => 'sn_handle_insights_snooze',
		'insights_mark_done'         => 'sn_handle_insights_mark_done',
		'save_insights_settings'     => 'sn_handle_save_insights_settings',
		'audit_save_retention'       => 'sn_handle_audit_save_retention',
		'pattern_adoption_scan'      => 'sn_handle_pattern_adoption_scan',
		'block_migrations_scan'      => 'sn_handle_block_migrations_scan',
	);
}

add_action( 'admin_init', 'sn_handle_admin_post' );

function sn_handle_admin_post() {
	if ( ! isset( $_POST['sn_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only process for our admin pages — guards against the handler firing for
	// an unrelated $_POST that happens to carry sn_action.
	$current_page = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
	$our_slugs    = array_column( sn_admin_pages(), 'slug' );
	if ( ! in_array( $current_page, $our_slugs, true ) ) {
		return;
	}

	check_admin_referer( 'sn_theme_options_nonce' );

	$action   = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );
	$handlers = sn_admin_post_handlers();
	if ( ! isset( $handlers[ $action ] ) ) {
		return; // unknown action — same as the old trailing `else { return; }`
	}
	// Handlers receive the RAW $_POST and unslash per-field exactly as their
	// original arms did (see inc/admin-post-actions.php docblock).
	$flash = (string) call_user_func( $handlers[ $action ], $_POST );

	$redirect_args = array(
		'page'     => $current_page,
		'sn_flash' => $flash,
	);

	// v3.8.0+: redirect to canonical top-tab + anchor (instead of legacy tab
	// slug). v3.8.1+: also preserves &sub= so flash notices land on the right
	// sub-tab. The legacy tab slug from the form POST is mapped via
	// sn_admin_legacy_redirect_map().
	$anchor = '';
	if ( isset( $_REQUEST['tab'] ) ) {
		$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
		$map           = sn_admin_legacy_redirect_map();
		$top_tabs      = array_column( sn_admin_top_tabs(), 'tab' );

		if ( in_array( $requested_tab, $top_tabs, true ) ) {
			// Already a canonical top tab; pass through.
			$redirect_args['tab'] = $requested_tab;
			if ( isset( $_REQUEST['sub'] ) ) {
				$redirect_args['sub'] = sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) );
			}
		} elseif ( isset( $map[ $requested_tab ] ) ) {
			// Legacy slug; rewrite to canonical destination.
			$redirect_args['tab'] = $map[ $requested_tab ]['tab'];
			if ( ! empty( $map[ $requested_tab ]['sub'] ) ) {
				$redirect_args['sub'] = $map[ $requested_tab ]['sub'];
			}
			if ( ! empty( $map[ $requested_tab ]['anchor'] ) ) {
				$anchor = '#sn-sec-' . rawurlencode( $map[ $requested_tab ]['anchor'] );
			}
		} else {
			// Unknown slug; fall back to dashboard.
			$redirect_args['tab'] = 'dashboard';
		}
	}

	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

	// Raw header() because wp_safe_redirect() strips URL fragments. Destination
	// is admin_url() (same-host, trusted) with a sanitized top-tab name from a
	// fixed allowlist — safe. 302 (transient post-save redirect), not 301.
	header( 'Location: ' . $redirect_url, true, 302 );
	exit;
}
```

> **Fidelity check:** diff this `sn_handle_admin_post()` against the old one (old lines 633–651 for the guards and 853–900 for the redirect) — the guards, nonce, allowlist, and the entire redirect block are byte-for-byte identical; only the 22-branch `if/elseif` is replaced by the map dispatch.

- [ ] **Step 2: Delete the old dispatcher from `inc/admin-page.php`** — remove the dispatcher docblock + `add_action( 'admin_init', 'sn_handle_admin_post' )` + the entire `sn_handle_admin_post()` function (old lines 618–901). `inc/admin-page.php` now contains only `sn_theme_options_page()` (plus the file docblock).

- [ ] **Step 3: Lint** — `php -l inc/admin-post-handler.php && php -l inc/admin-post-actions.php && php -l inc/admin-page.php` → all clean.

- [ ] **Step 4: Run the new + re-pointed tests, then the full suite**

```bash
php tests/admin-post-actions.php | tail -1     # PASS
php tests/audit-retention-bounds.php | tail -1 # PASS
```
Then the aggregate tally → `FAILED_SUITES=0 TOTAL_FAIL=0`, `TOTAL_PASS` ≥ 945 + new assertions.

- [ ] **Step 5: Commit** (Tasks 7 + 8 together — the handler + actions are one coherent unit)

```bash
git add inc/admin-post-handler.php inc/admin-post-actions.php inc/admin-page.php signal-and-noise-tools.php tests/admin-post-actions.php tests/audit-retention-bounds.php
git commit -m "refactor(admin): replace 22-branch dispatcher with action→callback map + handler unit tests"
```

---

## PHASE D — Form partials (responsibility #3)

### Task 9: Extract the Identity & SEO form → `inc/admin-forms/identity-and-seo.php`

**Files:**
- Create: `inc/admin-forms/identity-and-seo.php`
- Modify: `inc/admin-page.php` (the `site` tab's `else` branch), `signal-and-noise-tools.php`

- [ ] **Step 1: Create the form file with four named field-renderers + the form wrapper**

Create `inc/admin-forms/identity-and-seo.php`:

```php
<?php
/**
 * Signal & Noise — Identity & SEO admin form (Site tab, default sub-tab).
 *
 * Renders the bundled 4-section form (Identity / Social / Open Graph / SEO Copy)
 * saved by a single "Save Identity Settings" button (sn_action=save_identity →
 * sn_handle_save_identity → sn_settings_save). Each section body is a named
 * field-renderer passed to sn_admin_render_section() so the anchor wrappers and
 * TOC links keep working. Extracted verbatim from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit the full Identity & SEO form (wrapper + 4 sections + savebar).
 * Caller renders the TOC (sn_admin_render_toc) before this.
 */
function sn_admin_render_identity_and_seo_form() {
	echo '<form method="post" class="sn-identity-form">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="save_identity">';

	sn_admin_render_section( 'identity', 'sn_admin_render_identity_fields' );
	sn_admin_render_section( 'social', 'sn_admin_render_social_fields' );
	sn_admin_render_section( 'open-graph', 'sn_admin_render_open_graph_fields' );
	sn_admin_render_section( 'seo-copy', 'sn_admin_render_seo_copy_fields' );

	// Sticky save bar — saves Identity / Social / OG / SEO Copy (the 4 above).
	// Cloudflare's save is separate (its own form on its own sub-tab now).
	echo '<div class="sn-savebar">';
	echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
	echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
	echo '</div>';
	echo '</form>';
}

// >>> Below, add four functions — sn_admin_render_identity_fields(),
//     sn_admin_render_social_fields(), sn_admin_render_open_graph_fields(),
//     sn_admin_render_seo_copy_fields() — each containing, VERBATIM, the body
//     of the corresponding closure passed to sn_admin_render_section() in the
//     old inc/admin-page.php site-tab block:
//       identity   ← old lines 1112–1152 (closure body)
//       social     ← old lines 1154–1183
//       open-graph ← old lines 1185–1204
//       seo-copy   ← old lines 1206–1239
//     Copy only the closure BODIES (the echo statements between `function() {`
//     and the matching `}`), wrapped as `function sn_admin_render_<x>_fields() { … }`.
```

Replace the `>>>` marker with the four field-renderer functions, each body lifted verbatim from the corresponding old closure.

- [ ] **Step 2: Replace the site-tab inline block in `inc/admin-page.php`**

In `sn_theme_options_page()`, the `} elseif ( 'site' === $active_tab ) {` arm's `else` branch (the "Default sub-tab: identity-and-seo" block, old lines 1104–1248) currently inlines the TOC call, the `<form>`, the four `sn_admin_render_section( …, function() { … } )` closures, the savebar, and the `</form>`. Replace that entire `else { … }` body with:

```php
		} else {
			// Default sub-tab: 'identity-and-seo' (bundle of 4 form sections with one Save).
			sn_admin_render_toc( 'site', 'identity-and-seo' );
			sn_admin_render_identity_and_seo_form();
		}  // close: else (identity-and-seo sub-tab)
```

Leave the `if ( 'cloudflare' === $active_sub ) { … }` branch above it untouched.

- [ ] **Step 3: Add the bootstrap require** — after the `admin-post-actions.php` require:

```php
require_once SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
```

- [ ] **Step 4: Lint + run** — `php -l inc/admin-forms/identity-and-seo.php && php -l inc/admin-page.php`, then the aggregate tally → `FAILED_SUITES=0 TOTAL_FAIL=0`.

- [ ] **Step 5: Commit**

```bash
git add inc/admin-forms/identity-and-seo.php inc/admin-page.php signal-and-noise-tools.php
git commit -m "refactor(admin): extract Identity & SEO form to inc/admin-forms/identity-and-seo.php"
```

---

### Task 10: Extract the Login section → `inc/admin-forms/login.php`

**Files:**
- Create: `inc/admin-forms/login.php`
- Modify: `inc/admin-page.php` (the `security` tab's `login` branch), `signal-and-noise-tools.php`

- [ ] **Step 1: Create the file**

Create `inc/admin-forms/login.php`:

```php
<?php
/**
 * Signal & Noise — Login URL admin section (Security tab → Login sub-tab).
 *
 * Renders the custom-login-URL module status (active / dormant-conflict /
 * bypassed / constant-locked), the slug form (sn_action=save_login), and the
 * emergency-unlock docs. Extracted verbatim from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Login URL section body. Used as the sn_admin_render_section()
 * callback for the 'login' sub-tab.
 */
function sn_admin_render_login_section() {
	// >>> paste the body of the old `sn_admin_render_section( 'login', function() { … } )`
	//     closure here verbatim (old lines 1262–1352, i.e. everything between
	//     `function() {` and its matching `}`).
}
```

Replace the `>>>` marker with the verbatim closure body (the module-state detection, status box, slug form, and emergency-unlock callout).

- [ ] **Step 2: Replace the login branch in `inc/admin-page.php`**

In the `} elseif ( 'security' === $active_tab ) {` arm, the `} elseif ( 'login' === $active_sub || '' === $active_sub ) {` branch currently calls `sn_admin_render_section( 'login', function() { … } );` with the long inline closure. Replace that call with:

```php
			} elseif ( 'login' === $active_sub || '' === $active_sub ) {
				sn_admin_render_section( 'login', 'sn_admin_render_login_section' );
			}  // close: elseif login (default)
```

Leave the `if ( 'audit-log' === $active_sub )` branch (`sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' )`) untouched.

- [ ] **Step 3: Add the bootstrap require** — after the `identity-and-seo.php` require:

```php
require_once SNT_PATH . 'inc/admin-forms/login.php';
```

- [ ] **Step 4: Lint + run** — `php -l inc/admin-forms/login.php && php -l inc/admin-page.php`, then aggregate tally → `FAILED_SUITES=0 TOTAL_FAIL=0`.

- [ ] **Step 5: Commit**

```bash
git add inc/admin-forms/login.php inc/admin-page.php signal-and-noise-tools.php
git commit -m "refactor(admin): extract Login URL section to inc/admin-forms/login.php"
```

---

### Task 11: Extract the Links section → `inc/admin-forms/links.php`

**Files:**
- Create: `inc/admin-forms/links.php`
- Modify: `inc/admin-page.php` (the `tools` tab's `links` branch), `signal-and-noise-tools.php`

- [ ] **Step 1: Create the file**

Create `inc/admin-forms/links.php`:

```php
<?php
/**
 * Signal & Noise — Links admin section (Tools tab → Links sub-tab).
 *
 * Renders the external-shortcuts grid (source repos, releases, infrastructure).
 * Extracted verbatim from inc/admin-page.php in v4.5.3.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Links section body. Used as the sn_admin_render_section()
 * callback for the 'links' sub-tab.
 */
function sn_admin_render_links_section() {
	// >>> paste the body of the old `sn_admin_render_section( 'links', function() { … } )`
	//     closure here verbatim (old lines 1415–1452: the $link_groups array
	//     + the .sn-link-grid render loop).
}
```

Replace the `>>>` marker with the verbatim closure body.

- [ ] **Step 2: Replace the links branch in `inc/admin-page.php`**

In the `} elseif ( 'tools' === $active_tab ) {` arm, the `if ( 'links' === $active_sub ) {` branch currently calls `sn_admin_render_section( 'links', function() { … } );` with the long inline closure. Replace with:

```php
			if ( 'links' === $active_sub ) {
				sn_admin_render_section( 'links', 'sn_admin_render_links_section' );
			} elseif ( 'block-migrations' === $active_sub ) {
```

(i.e. swap the inline closure for the named callback; leave the `block-migrations` and `reading-time` branches untouched).

- [ ] **Step 3: Add the bootstrap require** — after the `login.php` require:

```php
require_once SNT_PATH . 'inc/admin-forms/links.php';
```

- [ ] **Step 4: Lint + run** — `php -l inc/admin-forms/links.php && php -l inc/admin-page.php`, then aggregate tally → `FAILED_SUITES=0 TOTAL_FAIL=0`.

- [ ] **Step 5: Commit**

```bash
git add inc/admin-forms/links.php inc/admin-page.php signal-and-noise-tools.php
git commit -m "refactor(admin): extract Links section to inc/admin-forms/links.php"
```

---

## PHASE E — Verify + ship

### Task 12: Final verification gates

- [ ] **Step 1: Lint every touched/new PHP file**

```bash
cd "$(git rev-parse --show-toplevel)"
for f in inc/admin-tabs-data.php inc/admin-tabs.php inc/admin-legacy-redirect.php inc/admin-menu.php \
         inc/admin-flash-messages.php inc/admin-post-handler.php inc/admin-post-actions.php \
         inc/admin-forms/identity-and-seo.php inc/admin-forms/login.php inc/admin-forms/links.php \
         inc/admin-page.php signal-and-noise-tools.php; do
  php -l "$f" || echo "LINT FAIL: $f"
done
```
Expected: `No syntax errors detected` for all 12.

- [ ] **Step 2: Full suite + assertion floor**

Run the aggregate tally. Expected: `FAILED_SUITES=0 TOTAL_FAIL=0` and `TOTAL_PASS` ≥ 945 (now higher — the two new suites add assertions). Record the new total for the CHANGELOG.

- [ ] **Step 3: Orchestrator size + no orphaned definitions**

```bash
wc -l inc/admin-page.php   # expect < 300 (target ~170)
# admin-page.php must no longer DEFINE the moved functions (only call them):
grep -nE 'function (sn_admin_top_tabs|sn_admin_pages|sn_admin_render_section|sn_admin_maybe_redirect_legacy|sn_handle_admin_post|sn_admin_page_hooks)\b' inc/admin-page.php
# expect NO output
```

- [ ] **Step 4: New-file hygiene** — confirm each of the 10 new files starts with `<?php`, a `@package SignalNoiseTools` docblock, and `if ( ! defined( 'ABSPATH' ) ) { exit; }`, and uses tab indentation:

```bash
for f in inc/admin-tabs-data.php inc/admin-tabs.php inc/admin-legacy-redirect.php inc/admin-menu.php \
         inc/admin-flash-messages.php inc/admin-post-handler.php inc/admin-post-actions.php \
         inc/admin-forms/identity-and-seo.php inc/admin-forms/login.php inc/admin-forms/links.php; do
  head -3 "$f" | grep -q "ABSPATH" || echo "guard? $f"
  grep -qP '^\t' "$f" || echo "tabs? $f"
done
```
Expected: no warnings.

- [ ] **Step 5: Commit any hygiene fixes** (only if Step 4 surfaced issues):

```bash
git add -A && git commit -m "refactor(admin): hygiene pass on extracted modules"
```

---

### Task 13: Version bump, CHANGELOG, ship

> Release workflow per CLAUDE.md: bump the docblock (single source of truth — `SNT_VERSION` derives from it via `get_file_data`), CHANGELOG entry, commit, merge to `main`, annotated tag, push branch + tag. **Do NOT auto-deploy.** Version bumps/tags only at END of session.

- [ ] **Step 1: Bump the plugin version (docblock only)**

In `signal-and-noise-tools.php`, change the docblock header `* Version:     4.5.2` to:

```
 * Version:     4.5.3
```

(Do NOT touch the `define( 'SNT_VERSION', … )` line — it reads the docblock via `get_file_data`.)

- [ ] **Step 2: Add the CHANGELOG entry**

In `CHANGELOG.md`, insert a new entry at the top of the entries (above `## [4.5.1]`), Mimestream format with a `### Cleanup` section. Fill `<N>` with the assertion total recorded in Task 12 Step 2:

```markdown
## [4.5.3] - 2026-05-29 — Refactor: split inc/admin-page.php into handler + flash-data + form modules

**Released:** 2026-05-29.

**Headline:** Pure, behavior-preserving refactor of the 1,468-line `inc/admin-page.php` monolith (flagged HIGH in the 2026-05-29 full-codebase QA audit — ~10× the ~150-line convention, 2× the next-biggest file) into 10 focused modules + a ~170-line orchestrator. No functional change: every form action, nonce, capability gate, sanitize/unslash, flash message, redirect, and tab behaves identically.

### Cleanup

- **Dispatcher de-monolithed** — the 270-line, 22-branch `if/elseif` in `sn_handle_admin_post()` is now an action→callback map (`sn_admin_post_handlers()` in `inc/admin-post-handler.php`) dispatching to 22 atomic, individually-testable `sn_handle_<action>()` functions in `inc/admin-post-actions.php`. Per-handler unslash behavior preserved verbatim (incl. `save_identity`'s raw-`$_POST` pass-through to `sn_settings_save()`).
- **Two duplicate flash ladders collapsed into one** — the dispatcher's emitted `?sn_flash=…` codes and the renderer's notice translation are now driven by a single shared registry (`inc/admin-flash-messages.php`: `sn_admin_flash_messages()` + `sn_admin_flash_to_notice()`), removing the hand-synced second `if/elseif`. Handles all three message shapes (static, count/id-prefixed, live-data).
- **Inline-HTML form walls extracted** — Identity & SEO (`inc/admin-forms/identity-and-seo.php`), Login URL (`inc/admin-forms/login.php`), and Links (`inc/admin-forms/links.php`) moved out of the renderer as faithful echo-for-echo lifts.
- **Tab data/framework/legacy split out** — `inc/admin-tabs-data.php` (the v3.8.0+ IA), `inc/admin-tabs.php` (accessors + nav renderers), `inc/admin-legacy-redirect.php` (legacy-URL layer), `inc/admin-menu.php` (menu registration + asset enqueue). All loaded via the existing flat `require_once` manifest.
- **`inc/admin-page.php` reduced from 1,468 → ~170 lines**, now a thin orchestrator (cap check → legacy redirect → tab resolution → flash loop → page shell → tab router).

### Improvements

- **New unit coverage where there was none** — `tests/admin-post-actions.php` (handler flash codes + side effects: `save_login`, `audit_save_retention` clamp, `save_identity`, `cf_save` constant-lock, `pl_save` branches, plus a map-completeness guard) and `tests/admin-flash-messages.php` (all three message shapes + a coordination guard that every emitted code resolves). `tests/audit-retention-bounds.php` upgraded from a source-grep proxy to a real behavioral call.

**Tests:** <N> assertions across 28 suites (was 945 across 27), 0 failed. Two new suites added; `admin-tabs`/`legacy-url-redirect`/`audit-retention-bounds` re-pointed at the new file locations.

**Refs:** 2026-05-29 full-codebase QA audit (HIGH). Spec: `docs/superpowers/specs/2026-05-29-admin-page-refactor-design.md`. Plan: `docs/superpowers/plans/2026-05-29-admin-page-refactor.md`.
```

- [ ] **Step 3: Sanity-check the derived version**

```bash
php -r 'define("ABSPATH","/"); $d = get_file_data("signal-and-noise-tools.php", array("Version"=>"Version"), "plugin"); echo $d["Version"], "\n";' 2>/dev/null || \
grep -m1 "Version:" signal-and-noise-tools.php
```
Expected: `4.5.3`.

- [ ] **Step 4: Commit the release**

```bash
git add signal-and-noise-tools.php CHANGELOG.md
git commit -m "v4.5.3: refactor — split admin-page.php into handler + flash-data + form modules"
```

- [ ] **Step 5: Integrate to main, tag, push** (uses superpowers:finishing-a-development-branch — confirm merge strategy with the user first)

```bash
git checkout main
git merge --no-ff <feature-branch> -m "Merge v4.5.3 admin-page.php refactor"
git tag -a v4.5.3 -m "v4.5.3: refactor — split admin-page.php into handler + flash-data + form modules"
git push origin main
git push origin v4.5.3
```

Do NOT trigger any deploy. Stop here and report.

---

## Self-Review (completed by plan author)

**1. Spec coverage:** Every spec section maps to tasks — Responsibility #1 → Tasks 7–8; #2 → Tasks 5–6; #3 → Tasks 9–11; data/framework/legacy/menu → Tasks 1–4; test updates → Tasks 1,3,7; new tests → Tasks 5,7; verification gates → Task 12; ship → Task 13. ✓
**2. Placeholder scan:** No "TBD"/"handle edge cases"/"similar to" — verbatim-move steps cite exact line ranges; all authored code (flash module, handlers, dispatcher, both test files, CHANGELOG) is shown in full. The four `>>>` markers are explicit verbatim-copy instructions with exact source line ranges, not vague placeholders. ✓
**3. Type/name consistency:** `sn_admin_post_handlers()` keys ↔ `sn_handle_<action>()` names match across Tasks 7/8; `sn_admin_flash_to_notice()` / `sn_admin_flash_messages()` consistent across Tasks 5/6; `sn_admin_render_identity_and_seo_form` + the four `sn_admin_render_*_fields` consistent in Task 9; `sn_admin_render_login_section` / `sn_admin_render_links_section` consistent in Tasks 10/11. ✓
