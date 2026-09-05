# The Signal & Noise app, phase one: the deeper note — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A note's dossier in the Signal & Noise OpenStation app shows everything the estate knows about that one note, in the order trust, numbers, operating state, editorial, fetched when the dossier opens through one new ability, with a live re-check action.

**Architecture:** Four pure block builders in `inc/note-dossier-*.php` (plugin infrastructure, loaded unconditionally, guarded with `function_exists` so each standalone test runs alone) are composed by `sn_note_dossier_compose()` and served by the ability `signal-noise/note-dossier` (`inc/abilities-note-dossier.php`, GET, `edit_post`). The app (`apps/signal-noise/`) gains a `verdict` state key, a `verify` server action, and passes the ability's run URL to the client through `App::config()`; the client fetches on open through `ctx.fetch`, caches per note and window in the `ctx.ui()` bag, and paints two new block kinds. The dock entry that shared the app's id becomes `sn-dashboard`.

**Tech Stack:** PHP 8.3+ (WordPress 7.1 plugin, standalone CLI tests under `tests/*.php` swept by `bash tests/run.sh`), OpenStation 1.1.6 App Framework client API (plain JS, no build), the plugin's existing readers (provenance integrity, analytics rollup table, Search Console store, machine-reader snapshot, probe log, schedule engine, corpus inspect, ML kernel).

**Spec:** `docs/proposals/2026-09-05-signal-noise-app-deeper-note.md`, with the amendments below, which come from reading the code (the map is in the session, summarised here so the engineer needs nothing else).

---

## Amendments to the spec (decided 2026-09-05 from the code)

1. **Views and visits** come from a NEW read over the durable daily table (`sn_analytics_path_window()`), not `sn_analytics_drilldown()`: the drill-down has no `path` dimension and returns null for it unconditionally. The S&N Analytics door lands on `snt_analytics_page_url( [ 'sn_view' => 'content', 'sn_range' => $days ] )`: the page has no per-path landing.
2. **Search Console** rows come from `snt_gsc_metrics_for_path()` after `snt_gsc_data()` (null = never synced; per-path null = not among the synced rows), in the sync's own 28-day window; `days` never re-windows it.
3. **Machine reads are not counted per document** (the sensor keeps no paths, by its privacy contract). The block is a `status`: "Not counted per note", with the site-wide 30-day total from the snapshot and a door to the Machine Readers leaf.
4. **Trust** reads the ledger record of the newest CONFIRMED version (`anchored_version`), never the head; the record carries `ots.bitcoin_block`, `ots.bitcoin_txid`, `ots.confirmations`, `pubkey_id`, `content_hash` and NO time; the only time is the local commit's optional `block_time`. The verify action composes `sn_prov_integrity_keys_probe()` + `sn_prov_integrity_check_note()` and states what it checked: the twin, the ledger record, the published key ids. No DID and no signature claim.
5. **The schedule door** goes to Connections → Scheduled (`snt_desktop_admin_url( 'sn-connections', 'scheduled-content' )`); the Content tab does not hold it.
6. **Related notes** come from the plugin's `snt_ml_related_for_post()` (null = kernel not built → block omitted; `[]` = none related, said so), not the theme's query, which backfills with recent posts.
7. **Builders live in `inc/`**, not `apps/`: the ability must work without OpenStation, and every guard (`tests/ability-permission-policy.php`, the orphan ratchet, stub-parity) walks `inc/` only.
8. **The dock entry** is not deleted: it is re-keyed to `sn-dashboard` with the title "S&N Dashboard" and the shield icon (the owner allowed icon changes), keeping its badge and submenu. The app keeps `signal-noise` and the megaphone.
9. **The verdict travels through declared state** (`'verdict' => array()`), projected by `payload()` into `data.verdict`; server actions cannot return data.
10. **The client calls the ability through `ctx.fetch`** on a URL the server hands it in `App::config()` (`ctx.extra.dossierUrl`), so no `/wp-abilities/` literal lives in JavaScript. Readonly ⇒ GET with bracket-encoded input.
11. **The excerpt is `snt_corpus_excerpt()`**, the one `signal-noise/sn-posts` returns to agents, and the block says so; core's `get_the_excerpt()` runs the theme's filters and is not what an agent receives.
12. **The loading state is one line for the dossier**, not a skeleton per block: the blocks are unknown until the response lands, and a per-block skeleton would have to invent the block list.
13. **The client's block kinds are pinned by substring** in `tests/openstation-app.php`; the repo has no JavaScript harness (no package.json, no tests/*.mjs; CI enumerates `*.php`).

## File structure

Create:
- `inc/note-dossier.php` — shared vocabulary: windows, post guard, tone map, block constructors, `sn_note_dossier_compose()`.
- `inc/note-dossier-trust.php` — `sn_note_dossier_trust()` and `sn_note_dossier_verify()`.
- `inc/note-dossier-numbers.php` — `sn_note_dossier_numbers()`.
- `inc/note-dossier-state.php` — `sn_note_dossier_state()` and `sn_note_dossier_last_probe()`.
- `inc/note-dossier-editorial.php` — `sn_note_dossier_editorial()`.
- `inc/abilities-note-dossier.php` — the ability.
- `tests/analytics-path-window.php`, `tests/note-dossier.php`, `tests/note-dossier-trust.php`, `tests/note-dossier-numbers.php`, `tests/note-dossier-state.php`, `tests/note-dossier-editorial.php`, `tests/abilities-note-dossier.php`.

Modify:
- `inc/analytics-posts.php` — add `sn_analytics_path_window()`.
- `inc/provenance-integrity.php` — extract `sn_prov_integrity_failure_sentence()` from `sn_prov_integrity_findings()`.
- `signal-and-noise-tools.php` — require the five `note-dossier*` files after `inc/ml-artifacts.php` (line ~502).
- `inc/abilities-registration.php` — require `abilities-note-dossier.php`.
- `apps/signal-noise/signal-noise.os.php` — state, config, `verify`, `go` reset.
- `apps/signal-noise/parts/payload.php` — project `verdict`.
- `apps/signal-noise/parts/notes.php` — the "Re-check now" action.
- `apps/signal-noise/signal-noise-client.js`, `apps/signal-noise/signal-noise.css` — the fetched dossier.
- `inc/desktop-mode-dock.php` — the dock entry id, title, icon.
- `tests/openstation-app.php`, `tests/desktop-mode-integration.php`, `tests/provenance-integrity.php` — moved pins.
- `CHANGELOG.md`, `docs/proposals/2026-09-05-signal-noise-app-deeper-note.md` (amendments section), `docs/openstation-compat.md` (the `config()` seam), `docs/ops/ability-permission-policy.md` (the tier row).

Conventions every task follows: standalone tests start with the CLI guard and `define( 'ABSPATH', '/' )`, stub WordPress functions BEFORE requiring the module, define `ok()`, and end with `echo "\nResult: $pass passed, $fail failed.\n"; exit( $fail > 0 ? 1 : 0 );`. Stubs for WP-shaped names must exist in WordPress (stub-parity); house-prefixed (`sn_`, `snt_`) stubs are free. Run one suite with `php tests/<file>.php`; the whole sweep with `bash tests/run.sh`.

---

### Task 1: The per-path analytics window read

**Files:**
- Modify: `inc/analytics-posts.php` (append after `sn_analytics_path_lifetime()`, ~line 230)
- Test: `tests/analytics-path-window.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: sn_analytics_path_window() — views and visits for ONE path
 * over a window from the durable daily table, both spellings of the path,
 * and the site-wide row count that separates "no analytics in this window"
 * from "this note had no views".
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }   // inc/analytics-rollup.php:111 evaluates it at load
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function add_action() { return true; }
function add_filter() { return true; }
function apply_filters( $h, $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }

class PW_WPDB {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();   // per-path result
	public $site = 0;         // site-wide count
	public function prepare( $sql, ...$args ) { $this->queries[] = array( $sql, $args ); return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function get_row( $sql, $out = OBJECT ) { return $this->rows; }
	public function get_var( $sql ) { return $this->site; }
}
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$wpdb = new PW_WPDB();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../inc/analytics-rollup.php';   // SN_ANALYTICS_DAILY_TABLE + canonical path helpers' file
require __DIR__ . '/../inc/analytics-derive.php';
require __DIR__ . '/../inc/analytics-posts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "analytics path window\n\n";

$wpdb->rows = array( 'views' => '312', 'visits' => '187', 'days' => '9' );
$wpdb->site = 40;
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( is_array( $r ) && 312 === $r['views'] && 187 === $r['visits'] && 9 === $r['days'] && 40 === $r['site_rows'], 'views, visits, days and the site-wide row count come back as ints' );
$q = $wpdb->queries[0][0];
ok( false !== strpos( $q, "class = 'human'" ) && false !== strpos( $q, 'path IN ( %s, %s )' ), 'the per-path query is human-class and asks for BOTH spellings of the path' );
ok( array( '/notes/foo', '/notes/foo/', '2026-08-07', '2026-09-05' ) === $wpdb->queries[0][1], 'the canonical spelling and the trailing-slash spelling are both bound, then the window' );

$wpdb->queries = array();
$r = sn_analytics_path_window( '/', '2026-08-07', '2026-09-05' );
ok( array( '/', '/', '2026-08-07', '2026-09-05' ) === $wpdb->queries[0][1], 'the root path binds itself twice rather than an empty spelling' );

$wpdb->rows = array( 'views' => null, 'visits' => null, 'days' => '0' );
$wpdb->site = 0;
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( is_array( $r ) && 0 === $r['views'] && 0 === $r['site_rows'], 'no rows anywhere reads as views 0 with site_rows 0 -- the caller can say "no analytics in this window"' );

$wpdb->rows = null;
ok( null === sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' ), 'a failed read is null, never a zero' );
ok( null === sn_analytics_path_window( '', '2026-08-07', '2026-09-05' ), 'an empty path is refused' );
ok( null === sn_analytics_path_window( '/notes/foo/', 'yesterday', '2026-09-05' ), 'a malformed day is refused' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/analytics-path-window.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function sn_analytics_path_window()` (no Result line: the sweep counts that as a failed suite).

Before writing the implementation, check what the three required files pull in at load: `grep -n "^add_action\|^add_filter\|^require\|^const\|^define\|_IN_SECONDS" inc/analytics-rollup.php inc/analytics-derive.php inc/analytics-posts.php`. Every hook call at top level is satisfied by the no-op stubs above and the three time constants are defined (the rollup file evaluates `MINUTE_IN_SECONDS` at load; without the define the fatal is the constant, not the function); if a required file uses another WordPress function OR constant at load, stub or define it in the test the same way.

- [ ] **Step 3: Write the implementation**

Append to `inc/analytics-posts.php`:

```php
/**
 * Views and visits for ONE path over an inclusive day window, from the
 * durable daily table, human class.
 *
 * The rollup stores paths VERBATIM: '/notes/foo' and '/notes/foo/' are two
 * rows (the 2026-08-19 finding). Both spellings are summed here, so a note
 * never under-counts because its permalink carries a trailing slash.
 *
 * `site_rows` is the number of (day, path) rows of ANY path in the window:
 * the caller separates "no analytics were collected in this window" (0) from
 * "this note had no views" (views 0 with site_rows > 0). The table never
 * stores a zero row, so absence IS zero once the window has rows at all.
 *
 * `visits` sums per-day distinct visitor-days -- visitor-days, not unique
 * visitors, the same unit sn_analytics_top_paths() reports.
 *
 * @param string $path Site-relative path (either spelling).
 * @param string $from 'YYYY-MM-DD' inclusive.
 * @param string $to   'YYYY-MM-DD' inclusive.
 * @return array{views:int,visits:int,days:int,site_rows:int}|null Null on a
 *                                                                 refused input or a failed read.
 */
function sn_analytics_path_window( $path, $from, $to ) {
	global $wpdb;
	$path = (string) $path;
	if ( '' === $path || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return null;
	}
	$canon = function_exists( 'sn_analytics_canonical_path' ) ? sn_analytics_canonical_path( $path ) : rtrim( $path, '/' );
	if ( '' === $canon ) {
		$canon = '/';
	}
	$slashed = '/' === $canon ? '/' : $canon . '/';
	$table   = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT SUM(views) AS views, SUM(visits) AS visits, COUNT(DISTINCT day) AS days
		 FROM {$table}
		 WHERE path IN ( %s, %s ) AND class = 'human' AND day >= %s AND day <= %s",
		$canon,
		$slashed,
		(string) $from,
		(string) $to
	), ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$site = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE class = 'human' AND day >= %s AND day <= %s",
		(string) $from,
		(string) $to
	) );
	return array(
		'views'     => (int) ( $row['views'] ?? 0 ),
		'visits'    => (int) ( $row['visits'] ?? 0 ),
		'days'      => (int) ( $row['days'] ?? 0 ),
		'site_rows' => (int) $site,
	);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/analytics-path-window.php`
Expected: `Result: 8 passed, 0 failed.`

If `require inc/analytics-rollup.php` fatals on an unstubbed WordPress function, stub that function in the test (a no-op returning the neutral value) and re-run; do not `require` more plugin files than the three named.

- [ ] **Step 5: Commit**

```bash
git add inc/analytics-posts.php tests/analytics-path-window.php
git commit -m "feat: sn_analytics_path_window() — views and visits for one path over a window, both spellings, with the site-wide row count"
```

---

### Task 2: The integrity wording helper

**Files:**
- Modify: `inc/provenance-integrity.php:572-586` (the `$legs` table inside `sn_prov_integrity_findings()`)
- Test: `tests/provenance-integrity.php` (append a group)

- [ ] **Step 1: Write the failing test**

Append before the Result line of `tests/provenance-integrity.php`:

```php
echo "\nfailure sentences are one table, reachable on their own\n";
ok( false !== strpos( sn_prov_integrity_failure_sentence( 'ledger_unreachable' ), 'could not be reached' ), 'the unreachable sentence names an outage' );
ok( false !== strpos( sn_prov_integrity_failure_sentence( 'twin_drift' ), 'twin drift' ), 'the drift sentence names drift' );
ok( 'bogus_code' === sn_prov_integrity_failure_sentence( 'bogus_code' ), 'an unknown code comes back as itself, never as an invented sentence' );
```

(That file uses `ok( $c, $m )` already; check its helper name with `grep -n "^function ok\|^function pi_ok" tests/provenance-integrity.php` and use whichever it defines.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/provenance-integrity.php | tail -5`
Expected: a fatal `Call to undefined function sn_prov_integrity_failure_sentence()`.

- [ ] **Step 3: Extract the helper**

In `inc/provenance-integrity.php`, move the `$legs` array out of `sn_prov_integrity_findings()` into a new function placed directly above it, and make `findings()` read through it:

```php
/**
 * The house sentence for one integrity failure code -- ONE table, so the
 * sweep's findings, the health check and the app's re-check verdict say the
 * same thing about the same leg. An unknown code comes back as itself: the
 * caller can see it was not translated, instead of a sentence that reads as
 * a verdict nobody made.
 *
 * @param string $code A failure code from sn_prov_integrity_check_note() or the keys probe.
 * @return string
 */
function sn_prov_integrity_failure_sentence( $code ) {
	static $legs = array(
		'hash_mismatch'           => 'stored payload no longer reproduces the anchored content hash (hash mismatch)',
		'twin_drift'              => 'the published .json twin\'s words no longer match the signed payload (twin drift)',
		'twin_unreachable'        => 'the published .json twin could not be fetched (unreachable: an outage, not drift)',
		'twin_missing'            => 'the published .json twin has 404ed for three consecutive sweeps (twin missing: the public twin is gone, not blipping)',
		'ledger_missing'          => 'the public ledger record <notes|pages>/<uid>/v<n>.json is absent (ledger missing)',
		'subject_kind_unresolved' => 'the subject kind could not be resolved, so the ledger directory is unknown and was NOT guessed (gap, never a drift claim)',
		'ledger_unreachable'      => 'the public ledger could not be reached (unreachable: an outage, not drift)',
		'ledger_hash_mismatch'    => 'the public ledger record attests a different content hash (ledger contradiction)',
		'ledger_record_malformed' => 'the public ledger record exists but carries no content_hash (malformed record: it attests nothing)',
		'no_signed_commit'        => 'the subject carries no signed v1+ commit at all, so /verify tells a reader no proof exists (unverifiable: absence, never drift)',
		'signing_key_unpublished' => 'the key this commit was signed with is no longer published in keys/provenance-keys.json, so readers can no longer verify its signature (retired key dropped)',
		'keys_unreachable'        => 'the ledger\'s key list could not be read (unreachable: an outage, not a mismatch)',
	);
	$code = (string) $code;
	return isset( $legs[ $code ] ) ? $legs[ $code ] : $code;
}
```

Then inside `sn_prov_integrity_findings()` delete the local `$legs = array( ... );` block and replace every read of `$legs[ $code ] ?? (string) $code` (there is one, in the loop that builds `$named`) with `sn_prov_integrity_failure_sentence( $code )`. Keep the eleven MOVED sentences byte-identical (`tests/provenance-integrity.php` pins some of them); `keys_unreachable` is a twelfth, new, added because the app's re-check folds the fleet-level keys verdict into a per-note verdict. The sweep never puts that code in a note's `failures`, so `findings()` is unchanged.

- [ ] **Step 4: Run the tests**

Run: `php tests/provenance-integrity.php | tail -3`
Expected: `Result: 121 passed, 0 failed.` (118 today, plus the three new assertions).

- [ ] **Step 5: Commit**

```bash
git add inc/provenance-integrity.php tests/provenance-integrity.php
git commit -m "refactor: one table of integrity failure sentences, reachable as sn_prov_integrity_failure_sentence()"
```

---

### Task 3: The dossier vocabulary and composer

**Files:**
- Create: `inc/note-dossier.php`
- Test: `tests/note-dossier.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/note-dossier.php — the block vocabulary the four
 * builders share, the window whitelist, the tone bridge, and the composer
 * that turns one failing builder into a block instead of a lost dossier.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array(
	7  => new WP_Post( array( 'ID' => 7 ) ),
	8  => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ),
	9  => new WP_Post( array( 'ID' => 9, 'post_type' => 'page' ) ),
	10 => new WP_Post( array( 'ID' => 10, 'post_password' => 'x' ) ),
);
require __DIR__ . '/../inc/note-dossier.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "note dossier -- vocabulary\n\n";

ok( 30 === sn_note_dossier_days( '30' ) && 7 === sn_note_dossier_days( 7 ) && 90 === sn_note_dossier_days( '90' ), 'the three windows pass, as ints, from ints or strings' );
ok( 30 === sn_note_dossier_days( 14 ) && 30 === sn_note_dossier_days( null ), 'anything else is 30' );

ok( 7 === sn_note_dossier_post( 7 )->ID, 'a note resolves' );
ok( null === sn_note_dossier_post( 9 ) && null === sn_note_dossier_post( 999 ), 'a page or a missing post does not' );
ok( sn_note_dossier_is_public( get_post( 7 ) ) && ! sn_note_dossier_is_public( get_post( 8 ) ) && ! sn_note_dossier_is_public( get_post( 10 ) ), 'public = published and not password-protected' );

ok( 'success' === sn_note_dossier_tone( 'ok' ) && 'warning' === sn_note_dossier_tone( 'warn' ) && 'neutral' === sn_note_dossier_tone( 'muted' ) && 'danger' === sn_note_dossier_tone( 'err' ) && 'info' === sn_note_dossier_tone( '' ), 'the admin pill kinds map onto the kit tones' );
ok( 'neutral' === sn_note_dossier_tone( 'anything' ), 'an unknown kind is neutral, never a made-up tone' );

$s = sn_note_dossier_stats( 'trust', 'Numbers', array( array( 'label' => 'Views', 'value' => '312', 'window' => '30 days' ) ), 'analytics table' );
ok( 'stats' === $s['kind'] && 'trust' === $s['group'] && 'analytics table' === $s['source'] && 1 === count( $s['tiles'] ) && ! isset( $s['door'] ), 'a stats block carries group, kind, tiles and source; no door key when none given' );
$st = sn_note_dossier_status( 'state', 'Edge', 'success', 'Edge fresh', 'verified 2 hours ago', 'probe log', sn_note_dossier_door( 'Open', 'https://example.test/x' ) );
ok( 'status' === $st['kind'] && 'success' === $st['tone'] && 'verified 2 hours ago' === $st['meta'] && 'Open' === $st['door']['label'], 'a status block carries tone, text, meta and a door' );
ok( 'neutral' === sn_note_dossier_status( 'state', 'x', 'bogus', 'y' )['tone'], 'a tone outside the kit set falls to neutral' );
$u = sn_note_dossier_unreadable( 'numbers', 'Numbers', 'the analytics table' );
ok( 'status' === $u['kind'] && 'warning' === $u['tone'] && false !== strpos( $u['text'], 'could not be read' ) && false !== strpos( $u['meta'], 'the analytics table' ), 'an unreadable source is a warning block that names the source' );
ok( '2 hours ago' === sn_note_dossier_ago( time() - 7200 ) && '' === sn_note_dossier_ago( 0 ), 'ago wording; nothing for no time' );

echo "\ncompose: one failing builder is one block\n";
function sn_note_dossier_trust( $id, $f = null ) { return array( sn_note_dossier_text( 'trust', 'Trust', 'ok' ) ); }
function sn_note_dossier_numbers( $id, $d ) { throw new RuntimeException( 'boom' ); }
function sn_note_dossier_state( $id ) { return array(); }
function sn_note_dossier_editorial( $id ) { return array( sn_note_dossier_text( 'editorial', 'Tags', 'a, b' ) ); }
$c = sn_note_dossier_compose( 7, 30 );
ok( true === $c['ok'] && 7 === $c['post_id'] && 30 === $c['days'] && true === $c['is_public'] && is_int( $c['fetched_at'] ), 'the envelope: ok, post_id, days, is_public, fetched_at' );
$groups = array_map( static function ( $b ) { return $b['group'] . ':' . $b['kind']; }, $c['blocks'] );
ok( array( 'trust:text', 'numbers:status', 'editorial:text' ) === $groups, 'blocks keep the order trust, numbers, state, editorial; the throwing builder became one warning block; the empty builder added nothing' );
ok( false !== strpos( $c['blocks'][1]['meta'], 'numbers' ), 'the warning names the builder that failed' );
ok( null === sn_note_dossier_compose( 999, 30 ), 'no post, no dossier' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/note-dossier.php`
Expected: `PHP Warning: require(...inc/note-dossier.php): Failed to open stream` then a fatal.

- [ ] **Step 3: Write the module**

Create `inc/note-dossier.php`:

```php
<?php
/**
 * Signal & Noise Tools — the note dossier: the vocabulary four builders share.
 *
 * A dossier is everything the estate knows about ONE note, as a list of
 * BLOCKS the Signal & Noise app paints beside its list. Each block names its
 * `group` (trust | numbers | state | editorial), its `kind`, and, when it was
 * fetched from somewhere, its `source`; a stats tile names its own `window`,
 * so three sources with three windows never read as one number. A block may
 * carry a `door`: a
 * label and a URL into the app that owns the view (S&N Dashboard, S&N
 * Analytics); this app shows a glance and a door, not a second home.
 *
 * Kinds (the client paints exactly these):
 *   stats  { heading, tiles: [ { label, value, window, note? } ] }
 *   status { heading, tone, text, meta? }        tone ∈ success|warning|danger|info|neutral
 *   text   { heading, text }
 *   table  { heading, columns: [ { key, label } ], rows: [ { key: string | { text, tone } | { code, title } } ] }
 *
 * Builders are pure functions of a post id (and a window); each guards every
 * plugin reader with function_exists so its standalone test runs alone. The
 * composer runs each builder in its own try: a source that fails becomes a
 * warning block naming the source, and the other three still paint.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The windows the numbers block answers; anything else is the default. */
const SN_NOTE_DOSSIER_WINDOWS = array( 7, 30, 90 );
const SN_NOTE_DOSSIER_DEFAULT_DAYS = 30;

/**
 * @param mixed $raw The requested window (an int, or the string a GET delivers).
 * @return int One of SN_NOTE_DOSSIER_WINDOWS.
 */
function sn_note_dossier_days( $raw ) {
	$d = (int) $raw;
	return in_array( $d, SN_NOTE_DOSSIER_WINDOWS, true ) ? $d : SN_NOTE_DOSSIER_DEFAULT_DAYS;
}

/**
 * The note, or null. Only post_type 'post' is a dossier subject; every
 * builder resolves the post through here so a missing or foreign id is one
 * answer everywhere.
 *
 * @param int $post_id
 * @return WP_Post|null
 */
function sn_note_dossier_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
		return null;
	}
	return $post;
}

/**
 * Whether a reader can reach the note: published and not password-protected.
 * Numbers and operating state exist only for such a note.
 *
 * @param WP_Post|null $post
 * @return bool
 */
function sn_note_dossier_is_public( $post ) {
	return $post instanceof WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password;
}

/** The kit's badge tones, the only vocabulary the client paints. */
const SN_NOTE_DOSSIER_TONES = array( 'success', 'warning', 'danger', 'info', 'neutral' );

/**
 * The admin kit's pill kinds ('' | ok | warn | err | muted, e.g. from
 * sn_cit_tier_pill_kind()) onto the OpenStation badge tones.
 *
 * @param string $kind
 * @return string
 */
function sn_note_dossier_tone( $kind ) {
	switch ( (string) $kind ) {
		case 'ok':
			return 'success';
		case 'warn':
			return 'warning';
		case 'err':
			return 'danger';
		case 'muted':
			return 'neutral';
		case '':
			return 'info';
		default:
			return 'neutral';
	}
}

/**
 * A door: the label and URL of the view that owns a fact.
 *
 * @param string $label
 * @param string $url
 * @return array{label:string,url:string}
 */
function sn_note_dossier_door( $label, $url ) {
	return array( 'label' => (string) $label, 'url' => (string) $url );
}

/**
 * @param string     $group   trust | numbers | state | editorial
 * @param string     $heading
 * @param array[]    $tiles   Each { label, value, window, note? }.
 * @param string     $source  Where the numbers came from.
 * @param array|null $door
 * @return array<string,mixed>
 */
function sn_note_dossier_stats( $group, $heading, array $tiles, $source, $door = null ) {
	$block = array(
		'group'   => (string) $group,
		'kind'    => 'stats',
		'heading' => (string) $heading,
		'tiles'   => array_values( $tiles ),
		'source'  => (string) $source,
	);
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * @param string     $group
 * @param string     $heading
 * @param string     $tone    A kit tone; anything else reads neutral.
 * @param string     $text    The sentence on the badge.
 * @param string     $meta    A second line, optional.
 * @param string     $source  Optional.
 * @param array|null $door    Optional.
 * @return array<string,mixed>
 */
function sn_note_dossier_status( $group, $heading, $tone, $text, $meta = '', $source = '', $door = null ) {
	$block = array(
		'group'   => (string) $group,
		'kind'    => 'status',
		'heading' => (string) $heading,
		'tone'    => in_array( (string) $tone, SN_NOTE_DOSSIER_TONES, true ) ? (string) $tone : 'neutral',
		'text'    => (string) $text,
	);
	if ( '' !== (string) $meta ) {
		$block['meta'] = (string) $meta;
	}
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * @param string $group
 * @param string $heading
 * @param string $text
 * @param string $source
 * @return array<string,mixed>
 */
function sn_note_dossier_text( $group, $heading, $text, $source = '' ) {
	$block = array( 'group' => (string) $group, 'kind' => 'text', 'heading' => (string) $heading, 'text' => (string) $text );
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	return $block;
}

/**
 * @param string     $group
 * @param string     $heading
 * @param array[]    $columns Each { key, label }.
 * @param array[]    $rows    Keyed by column key; a cell is a string, { text, tone } or { code, title }.
 * @param string     $source
 * @param array|null $door
 * @return array<string,mixed>
 */
function sn_note_dossier_table( $group, $heading, array $columns, array $rows, $source = '', $door = null ) {
	$block = array( 'group' => (string) $group, 'kind' => 'table', 'heading' => (string) $heading, 'columns' => array_values( $columns ), 'rows' => array_values( $rows ) );
	if ( '' !== (string) $source ) {
		$block['source'] = (string) $source;
	}
	if ( is_array( $door ) ) {
		$block['door'] = $door;
	}
	return $block;
}

/**
 * The block a source that could not be read becomes: a warning that names
 * the source, so the reader knows which door to try, and never a zero.
 *
 * @param string $group
 * @param string $heading
 * @param string $source_name E.g. 'the analytics table'.
 * @return array<string,mixed>
 */
function sn_note_dossier_unreadable( $group, $heading, $source_name ) {
	return sn_note_dossier_status(
		$group,
		$heading,
		'warning',
		__( 'This source could not be read.', 'signal-and-noise-tools' ),
		sprintf(
			/* translators: %s: the source that failed. */
			__( 'A gap in evidence, not a verdict: %s did not answer.', 'signal-and-noise-tools' ),
			(string) $source_name
		)
	);
}

/**
 * "2 hours ago" for a unix time; '' for no time.
 *
 * @param int $ts
 * @return string
 */
function sn_note_dossier_ago( $ts ) {
	$ts = (int) $ts;
	if ( $ts <= 0 ) {
		return '';
	}
	return sprintf(
		/* translators: %s: human time difference. */
		__( '%s ago', 'signal-and-noise-tools' ),
		human_time_diff( $ts, time() )
	);
}

/**
 * The whole dossier for one note: the four builders in the owner's order,
 * each in its own try. Null when the id is not a note.
 *
 * @param int           $post_id
 * @param int           $days    A window from SN_NOTE_DOSSIER_WINDOWS.
 * @param callable|null $fetcher HTTP seam for the trust builder (tests).
 * @return array<string,mixed>|null { ok, post_id, days, is_public, blocks, fetched_at }
 */
function sn_note_dossier_compose( $post_id, $days, $fetcher = null ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	$days   = sn_note_dossier_days( $days );
	$blocks = array();
	$runs   = array(
		'trust'     => array( 'sn_note_dossier_trust', array( $post->ID, $fetcher ), __( 'Trust', 'signal-and-noise-tools' ), __( 'the ledger or the citation graph', 'signal-and-noise-tools' ) ),
		'numbers'   => array( 'sn_note_dossier_numbers', array( $post->ID, $days ), __( 'Numbers', 'signal-and-noise-tools' ), __( 'the analytics table, the Search Console sync or the snapshot', 'signal-and-noise-tools' ) ),
		'state'     => array( 'sn_note_dossier_state', array( $post->ID ), __( 'Operating state', 'signal-and-noise-tools' ), __( 'the probe log, the coverage map or the schedule', 'signal-and-noise-tools' ) ),
		'editorial' => array( 'sn_note_dossier_editorial', array( $post->ID ), __( 'Editorial', 'signal-and-noise-tools' ), __( 'the post itself', 'signal-and-noise-tools' ) ),
	);
	foreach ( $runs as $group => $run ) {
		list( $fn, $args, $heading, $source_name ) = $run;
		if ( ! function_exists( $fn ) ) {
			continue;
		}
		try {
			$out = call_user_func_array( $fn, $args );
			foreach ( (array) $out as $block ) {
				if ( is_array( $block ) && isset( $block['kind'] ) ) {
					$blocks[] = $block;
				}
			}
		} catch ( \Throwable $e ) {
			$blocks[] = sn_note_dossier_unreadable( $group, $heading, $source_name . ' (' . $group . ')' );
		}
	}
	return array(
		'ok'         => true,
		'post_id'    => (int) $post->ID,
		'days'       => $days,
		'is_public'  => sn_note_dossier_is_public( $post ),
		'blocks'     => $blocks,
		'fetched_at' => time(),
	);
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/note-dossier.php`
Expected: `Result: 16 passed, 0 failed.`

- [ ] **Step 5: Commit**

```bash
git add inc/note-dossier.php tests/note-dossier.php
git commit -m "feat: the note dossier vocabulary — windows, block kinds, tone bridge, and a composer that turns a failing source into a block"
```

---

### Task 4: The trust builder and the verify runner

**Files:**
- Create: `inc/note-dossier-trust.php`
- Test: `tests/note-dossier-trust.php`

Readers this builder uses, all guarded with `function_exists`: `sn_prov_get_chain( $id )` (the local chain: commits with `version`, `status`, `content_hash`, optional `pubkey_id`, `block_time`), `sn_prov_subject_kind( $post )` + `sn_prov_ledger_dir( $kind )` ('' = refuse, never guess), `sn_prov_integrity_ledger_base()`, `sn_prov_integrity_fetch_json( $url, $fetcher )` → `{ code, json|null }`, `sn_prov_integrity_keys_probe( $fetcher )` → `{ verdict, code, published_ids: string[]|null }`, `sn_prov_key_id()` (the followed key), `sn_cit_for_post( $id, false )` (stdClass rows), `sn_cit_tier_pill_kind()`, `sn_cit_ago_label()`, `sn_prov_integrity_check_note( $id, $fetcher, $published_ids )` → `{ version, anchored_version, failures[] }|null`, `sn_prov_integrity_is_outage( $code )`, `sn_prov_integrity_failure_sentence( $code )` (Task 2), `sn_prov_integrity_http_fetch` (the default fetcher name). The uid is read with `get_post_meta( $id, '_sn_prov_uid', true )` — never `sn_prov_note_uid()`, which mints.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/note-dossier-trust.php — the anchor proof read from the
 * ledger record of the newest CONFIRMED version, the signer against the keys
 * the ledger publishes, the citations received, and the re-check verdict.
 * Every provenance and citation reader is a house-prefixed stub; HTTP is the
 * integrity module's fetcher seam.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? ''; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }

// ── provenance stubs (house prefix, free) ────────────────────────────────
$GLOBALS['__chain'] = array();
function sn_prov_get_chain( $id ) { return $GLOBALS['__chain']; }
function sn_prov_subject_kind( $post ) { return $GLOBALS['__kind'] ?? 'note'; }
function sn_prov_ledger_dir( $kind ) { return 'note' === $kind ? 'notes' : ( 'page' === $kind ? 'pages' : '' ); }
function sn_prov_integrity_ledger_base() { return 'https://raw.githubusercontent.com/o/r/main/'; }
function sn_prov_key_id() { return 'sn-ed25519-2026-07'; }
function sn_prov_integrity_fetch_json( $url, $fetcher ) { $r = $fetcher( $url ); $j = 200 === (int) $r['code'] ? json_decode( (string) $r['body'], true ) : null; return array( 'code' => (int) $r['code'], 'json' => is_array( $j ) ? $j : null ); }
function sn_prov_integrity_keys_probe( $fetcher ) { return $GLOBALS['__probe']; }
function sn_prov_integrity_check_note( $id, $fetcher, $ids = null ) { $GLOBALS['__check_ids'] = $ids; return $GLOBALS['__check']; }
function sn_prov_integrity_is_outage( $c ) { return in_array( $c, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true ); }
function sn_prov_integrity_failure_sentence( $c ) { return 'S:' . $c; }
function sn_prov_integrity_http_fetch( $url ) { return array( 'code' => 0, 'body' => '' ); }
function sn_cit_for_post( $id, $public_only = true ) { return $GLOBALS['__cits'] ?? array(); }
function sn_cit_tier_pill_kind( $t ) { return array( 'verified' => 'ok', 'asserted' => 'warn', 'unverified' => 'muted' )[ $t ] ?? ''; }
function sn_cit_ago_label( $gmt ) { return null === $gmt || '' === $gmt ? 'never' : '2 hours ago'; }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-trust.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fetcher( array $map ) { return function ( $url ) use ( $map ) { foreach ( $map as $needle => $resp ) { if ( false !== strpos( $url, $needle ) ) { return $resp; } } return array( 'code' => 404, 'body' => '' ); }; }
function jsonr( $d ) { return array( 'code' => 200, 'body' => json_encode( $d ) ); }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
echo "note dossier -- trust\n\n";

$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ) );
$GLOBALS['__meta']  = array( 7 => array( '_sn_prov_uid' => 'u-7' ) );
$GLOBALS['__chain'] = array(
	array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa', 'pubkey_id' => 'sn-ed25519-2026-07', 'block_time' => '2026-08-20T10:00:00Z' ),
	array( 'version' => 2, 'status' => 'pending', 'content_hash' => 'bbb' ),
);
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__cits']  = array( (object) array( 'tier' => 'verified', 'source_url' => 'https://blog.example.org/p', 'source_title' => 'A post', 'last_checked_gmt' => '2026-09-01 10:00:00' ), (object) array( 'tier' => 'unverified', 'source_url' => 'https://x.example.net/y', 'source_title' => '', 'last_checked_gmt' => null ) );
$fetch = fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'sha256:AAA', 'pubkey_id' => 'sn-ed25519-2026-07', 'ots' => array( 'bitcoin_block' => 910123, 'bitcoin_txid' => str_repeat( 'ab', 32 ), 'confirmations' => 6 ) ) ) ) );

$b = sn_note_dossier_trust( 7, $fetch );
$anchor = by_heading( $b, 'Anchor proof' );
ok( 'status' === $anchor['kind'] && 'success' === $anchor['tone'] && false !== strpos( $anchor['text'], 'v1' ) && false !== strpos( $anchor['text'], '910,123' ) && false !== strpos( $anchor['text'], '6 confirmations' ), 'the anchor proof reads the ledger record of the newest CONFIRMED version (v1, not the pending head v2)' );
ok( false !== strpos( $anchor['meta'], 'same content hash' ) && false !== strpos( $anchor['meta'], '2026-08-20T10:00:00Z' ), 'the record attests the same hash (sha256: prefix and case ignored); the local block time is named as the worker reported it' );
ok( 'View the transaction' === $anchor['door']['label'] && false !== strpos( $anchor['door']['url'], 'mempool.space/tx/' . str_repeat( 'ab', 32 ) ), 'the door opens the transaction on the explorer' );
$signer = by_heading( $b, 'Signer' );
ok( 'success' === $signer['tone'] && false !== strpos( $signer['text'], 'sn-ed25519-2026-07' ) && false !== strpos( $signer['text'], 'the followed key' ), 'signed by the followed key, and the ledger publishes it' );
$cits = by_heading( $b, 'Citations received' );
ok( 'table' === $cits['kind'] && 2 === count( $cits['rows'] ) && 'success' === $cits['rows'][0]['tier']['tone'] && 'A post' === $cits['rows'][0]['source'] && 'x.example.net' === $cits['rows'][1]['source'] && 'never' === $cits['rows'][1]['checked'], 'citations: tier as a toned cell, title or host, never vs a time' );

echo "\nthe gaps\n";
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => array( 'code' => 0, 'body' => '' ) ) ) );
$anchor = by_heading( $b, 'Anchor proof' );
ok( 'warning' === $anchor['tone'] && false !== stripos( $anchor['text'], 'could not be reached' ), 'the ledger unreachable is a gap, never "not anchored"' );
$b = sn_note_dossier_trust( 7, fetcher( array() ) );
ok( 'warning' === by_heading( $b, 'Anchor proof' )['tone'] && false !== stripos( by_heading( $b, 'Anchor proof' )['text'], 'no record' ), 'a 404 is a real absence and says so' );
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'zzz' ) ) ) ) );
ok( 'danger' === by_heading( $b, 'Anchor proof' )['tone'], 'a record that attests a different hash is danger' );
$GLOBALS['__probe'] = array( 'verdict' => 'keys_unreachable', 'code' => 0, 'published_ids' => null );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'warning' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'could not be checked' ), 'keys unreachable: the signer could not be checked, never a mismatch' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-09' ) );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'danger' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'no longer publishes' ), 'a key the ledger dropped is danger' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07', 'sn-ed25519-2026-09' ) );
$GLOBALS['__chain'][0]['pubkey_id'] = 'sn-ed25519-2026-09';
$b = sn_note_dossier_trust( 7, fetcher( array( '/notes/u-7/v1.json' => jsonr( array( 'content_hash' => 'aaa', 'pubkey_id' => 'sn-ed25519-2026-09' ) ) ) ) );
ok( 'info' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'the followed key is now' ), 'a published key that is not the followed one is info, and names the followed one' );
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'pending', 'content_hash' => 'aaa' ) );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'neutral' === by_heading( $b, 'Anchor proof' )['tone'] && false !== strpos( by_heading( $b, 'Anchor proof' )['text'], 'No confirmed anchor' ) && 'neutral' === by_heading( $b, 'Signer' )['tone'] && false !== strpos( by_heading( $b, 'Signer' )['text'], 'not recorded' ), 'a pending-only chain: no anchor to read, no signer recorded' );
$GLOBALS['__chain'] = array();
$GLOBALS['__cits']  = array();
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'neutral' === by_heading( $b, 'Anchor proof' )['tone'] && 'text' === by_heading( $b, 'Citations received' )['kind'] && false !== strpos( by_heading( $b, 'Citations received' )['text'], 'No citations' ), 'no chain, no citations: said plainly' );
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa' ) );
$GLOBALS['__meta']  = array( 7 => array() );
$b = sn_note_dossier_trust( 7, $fetch );
ok( 'warning' === by_heading( $b, 'Anchor proof' )['tone'] && false !== strpos( by_heading( $b, 'Anchor proof' )['text'], 'no ledger UID' ), 'a confirmed commit without a UID is a gap in the lookup, never "no confirmed anchor"' );
$GLOBALS['__meta']  = array( 7 => array( '_sn_prov_uid' => 'u-7' ) );

echo "\nverify\n";
$GLOBALS['__chain'] = array( array( 'version' => 1, 'status' => 'confirmed', 'content_hash' => 'aaa' ) );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => 'u-7', 'version' => 1, 'anchored_version' => 1, 'failures' => array(), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 7 === $v['post_id'] && 'success' === $v['tone'] && false !== strpos( $v['text'], 'holds' ) && false !== strpos( $v['meta'], 'twin' ) && false !== strpos( $v['meta'], 'ledger record' ) && false !== strpos( $v['meta'], 'key' ) && preg_match( '/^\d{4}-\d{2}-\d{2}T/', $v['checked_at'] ), 'a clean check: success, says what was checked, stamped' );
ok( array( 'sn-ed25519-2026-07' ) === $GLOBALS['__check_ids'], 'the published key ids from the probe reach the note check (leg d)' );
$GLOBALS['__check']['failures'] = array( 'twin_unreachable' );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:twin_unreachable' ), 'an outage-only failure is a warning carrying the house sentence' );
$GLOBALS['__check']['failures'] = array( 'twin_unreachable', 'ledger_hash_mismatch' );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'danger' === $v['tone'] && false !== strpos( $v['meta'], 'S:ledger_hash_mismatch' ), 'a real mismatch is danger even beside an outage' );
$GLOBALS['__probe'] = array( 'verdict' => 'keys_unreachable', 'code' => 0, 'published_ids' => null );
$GLOBALS['__check']['failures'] = array();
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:keys_unreachable' ), 'keys unreachable joins the failures as an outage' );
$GLOBALS['__probe'] = array( 'verdict' => 'ok', 'code' => 200, 'published_ids' => array( 'sn-ed25519-2026-07' ) );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => '', 'version' => 1, 'anchored_version' => 1, 'failures' => array(), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'success' === $v['tone'] && false === strpos( $v['meta'], 'ledger record for' ) && false !== strpos( $v['meta'], 'no ledger UID' ), 'without a UID the ledger leg never ran (integrity.php:353), and the verdict does not claim it did' );
$GLOBALS['__check'] = array( 'post_id' => 7, 'uid' => 'u-7', 'version' => 1, 'anchored_version' => 1, 'failures' => array( 'subject_kind_unresolved' ), 'twin_code' => 200 );
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'warning' === $v['tone'] && false !== strpos( $v['meta'], 'S:subject_kind_unresolved' ), 'an unresolved subject kind is a gap, never "does not hold"' );
$GLOBALS['__check'] = null;
$v = sn_note_dossier_verify( 7, $fetch );
ok( 'neutral' === $v['tone'] && false !== strpos( $v['text'], 'Nothing to verify' ), 'no signed version: nothing to verify, said so' );
$v = sn_note_dossier_verify( 999, $fetch );
ok( 'warning' === $v['tone'] && 999 === $v['post_id'], 'not a note: a warning verdict, never a crash' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/note-dossier-trust.php`
Expected: a fatal on the missing `inc/note-dossier-trust.php`.

- [ ] **Step 3: Write the module**

Create `inc/note-dossier-trust.php`:

```php
<?php
/**
 * Signal & Noise Tools — the note dossier: the trust block, and the re-check.
 *
 * Trust is this app's subject, so it is read in full here: the anchor proof
 * from the PUBLIC ledger's record of the newest confirmed version, the signer
 * against the keys the ledger publishes, the citations the note has received.
 * The re-check walks what the server can walk -- the published twin, the
 * ledger record, the published key ids -- and says exactly that; the
 * signature itself is verified by the public /verify page in the reader's
 * browser, and this never claims otherwise.
 *
 * Reads the head of nothing: the ledger record for a PENDING head does not
 * exist yet, so the newest CONFIRMED version is the one with a record. An
 * unreachable ledger is a gap in evidence, never "not anchored".
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The newest CONFIRMED commit with version >= 1, or null.
 *
 * @param array $chain sn_prov_get_chain() output.
 * @return array|null
 */
function sn_note_dossier_anchored_commit( array $chain ) {
	for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
		$c = $chain[ $i ];
		if ( is_array( $c ) && 'confirmed' === (string) ( $c['status'] ?? '' ) && (int) ( $c['version'] ?? 0 ) >= 1 ) {
			return $c;
		}
	}
	return null;
}

/**
 * Strip an optional 'sha256:' prefix and lowercase, the integrity module's
 * comparison form.
 *
 * @param string $hash
 * @return string
 */
function sn_note_dossier_hash_norm( $hash ) {
	$hash = strtolower( trim( (string) $hash ) );
	return 0 === strpos( $hash, 'sha256:' ) ? substr( $hash, 7 ) : $hash;
}

/**
 * The trust blocks for one note.
 *
 * @param int           $post_id
 * @param callable|null $fetcher callable( string $url ): array{code:int,body:string}; the integrity module's HTTP fetcher by default.
 * @return array<int,array<string,mixed>>
 */
function sn_note_dossier_trust( $post_id, $fetcher = null ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$fetcher = is_callable( $fetcher ) ? $fetcher : ( function_exists( 'sn_prov_integrity_http_fetch' ) ? 'sn_prov_integrity_http_fetch' : null );
	$blocks  = array();
	$chain   = function_exists( 'sn_prov_get_chain' ) ? (array) sn_prov_get_chain( $post->ID ) : array();
	$uid     = (string) get_post_meta( $post->ID, '_sn_prov_uid', true );
	$commit  = sn_note_dossier_anchored_commit( $chain );
	$record  = null;

	// ── Anchor proof ─────────────────────────────────────────────────────
	$anchor_door = function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Provenance in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-tools', 'provenance' ) ) : null;
	if ( ! $commit ) {
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'neutral', __( 'No confirmed anchor yet.', 'signal-and-noise-tools' ), __( 'A version is anchored once the ledger confirms it in Bitcoin; a pending anchor has no record to read yet.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ), $anchor_door );
	} elseif ( '' === $uid ) {
		// A confirmed commit whose note carries no `_sn_prov_uid`: the record
		// cannot be LOCATED. A gap in the lookup, never "no anchor".
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %d: version. */ __( 'v%d is confirmed locally, but this note carries no ledger UID.', 'signal-and-noise-tools' ), (int) $commit['version'] ), __( 'Without the UID the ledger record cannot be located; a gap in the lookup, not a missing anchor.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ), $anchor_door );
	} elseif ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_fetch_json' ) || ! function_exists( 'sn_prov_ledger_dir' ) || ! function_exists( 'sn_prov_subject_kind' ) || ! function_exists( 'sn_prov_integrity_ledger_base' ) ) {
		$blocks[] = sn_note_dossier_unreadable( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), __( 'the provenance module', 'signal-and-noise-tools' ) );
	} else {
		$dir = (string) sn_prov_ledger_dir( (string) sn_prov_subject_kind( $post ) );
		$v   = (int) $commit['version'];
		if ( '' === $dir ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', __( 'The subject kind could not be resolved.', 'signal-and-noise-tools' ), __( 'The ledger directory was not guessed; a gap, not a verdict.', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
		} else {
			$url = sn_prov_integrity_ledger_base() . $dir . '/' . rawurlencode( $uid ) . '/v' . $v . '.json';
			$res = sn_prov_integrity_fetch_json( $url, $fetcher );
			if ( 404 === (int) $res['code'] ) {
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %d: version number. */ __( 'The ledger holds no record for v%d.', 'signal-and-noise-tools' ), $v ), __( 'A real absence: the record is not there, the ledger answered.', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
			} elseif ( ! is_array( $res['json'] ) ) {
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), 'warning', __( 'The public ledger could not be reached.', 'signal-and-noise-tools' ), __( 'A gap in evidence, never "not anchored".', 'signal-and-noise-tools' ), __( 'public ledger', 'signal-and-noise-tools' ), $anchor_door );
			} else {
				$record = $res['json'];
				$ots    = is_array( $record['ots'] ?? null ) ? $record['ots'] : array();
				$txid   = (string) ( $ots['bitcoin_txid'] ?? ( $record['bitcoin_txid'] ?? '' ) );
				$block  = (int) ( $ots['bitcoin_block'] ?? 0 );
				$conf   = isset( $ots['confirmations'] ) ? (int) $ots['confirmations'] : null;
				$same   = '' !== (string) ( $record['content_hash'] ?? '' ) && sn_note_dossier_hash_norm( $record['content_hash'] ) === sn_note_dossier_hash_norm( $commit['content_hash'] ?? '' );
				if ( $block > 0 ) {
					$text = sprintf( /* translators: 1: version, 2: block number. */ __( 'v%1$d anchored in Bitcoin block %2$s', 'signal-and-noise-tools' ), $v, number_format_i18n( $block ) );
					if ( null !== $conf ) {
						$text .= sprintf( /* translators: %d: confirmations. */ _n( ', %d confirmation', ', %d confirmations', $conf, 'signal-and-noise-tools' ), $conf );
					}
					$text .= '.';
				} else {
					$text = sprintf( /* translators: %d: version. */ __( 'v%d is in the ledger; the record names no block yet.', 'signal-and-noise-tools' ), $v );
				}
				$meta = $same ? __( 'The ledger record attests the same content hash.', 'signal-and-noise-tools' ) : __( 'The ledger record attests a DIFFERENT content hash.', 'signal-and-noise-tools' );
				$time = (string) ( $commit['block_time'] ?? '' );
				if ( '' !== $time ) {
					$meta .= ' ' . sprintf( /* translators: %s: block time string. */ __( 'Block time as the worker reported it: %s.', 'signal-and-noise-tools' ), $time );
				}
				$door = '' !== $txid ? sn_note_dossier_door( __( 'View the transaction', 'signal-and-noise-tools' ), 'https://mempool.space/tx/' . rawurlencode( $txid ) ) : $anchor_door;
				$blocks[] = sn_note_dossier_status( 'trust', __( 'Anchor proof', 'signal-and-noise-tools' ), $same ? 'success' : 'danger', $text, $meta, __( 'public ledger', 'signal-and-noise-tools' ), $door );
			}
		}
	}

	// ── Signer ───────────────────────────────────────────────────────────
	$named = (string) ( is_array( $record ) ? ( $record['pubkey_id'] ?? '' ) : '' );
	if ( '' === $named ) {
		$head  = $commit ?: ( $chain ? end( $chain ) : null );
		$named = is_array( $head ) ? (string) ( $head['pubkey_id'] ?? '' ) : '';
	}
	if ( '' === $named ) {
		$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'neutral', __( 'Signer not recorded.', 'signal-and-noise-tools' ), __( 'Commits made before the worker returned a key id carry none; the ledger record names the key when there is one.', 'signal-and-noise-tools' ), __( 'local chain', 'signal-and-noise-tools' ) );
	} elseif ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_keys_probe' ) || ! function_exists( 'sn_prov_key_id' ) ) {
		$blocks[] = sn_note_dossier_unreadable( 'trust', __( 'Signer', 'signal-and-noise-tools' ), __( 'the provenance module', 'signal-and-noise-tools' ) );
	} else {
		$probe    = sn_prov_integrity_keys_probe( $fetcher );
		$ids      = isset( $probe['published_ids'] ) && is_array( $probe['published_ids'] ) ? $probe['published_ids'] : null;
		$followed = (string) sn_prov_key_id();
		if ( null === $ids ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'warning', sprintf( /* translators: %s: key id. */ __( 'Signed by %s; the ledger\'s key list could not be checked.', 'signal-and-noise-tools' ), $named ), __( 'Could not be checked, not a mismatch.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		} elseif ( ! in_array( $named, $ids, true ) ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'danger', sprintf( /* translators: %s: key id. */ __( 'Signed by %s, a key the ledger no longer publishes.', 'signal-and-noise-tools' ), $named ), __( 'Readers can no longer verify this signature from the published keys.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		} elseif ( $named === $followed ) {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'success', sprintf( /* translators: %s: key id. */ __( 'Signed by %s, the followed key.', 'signal-and-noise-tools' ), $named ), '', __( 'ledger keys', 'signal-and-noise-tools' ) );
		} else {
			$blocks[] = sn_note_dossier_status( 'trust', __( 'Signer', 'signal-and-noise-tools' ), 'info', sprintf( /* translators: 1: key id, 2: the followed key id. */ __( 'Signed by %1$s; the followed key is now %2$s.', 'signal-and-noise-tools' ), $named, $followed ), __( 'A retired key the ledger still publishes verifies.', 'signal-and-noise-tools' ), __( 'ledger keys', 'signal-and-noise-tools' ) );
		}
	}

	// ── Citations received ───────────────────────────────────────────────
	$rows = function_exists( 'sn_cit_for_post' ) ? (array) sn_cit_for_post( $post->ID, false ) : array();
	if ( array() === $rows ) {
		$blocks[] = sn_note_dossier_text( 'trust', __( 'Citations received', 'signal-and-noise-tools' ), __( 'No citations recorded for this note.', 'signal-and-noise-tools' ), __( 'citation graph', 'signal-and-noise-tools' ) );
	} else {
		$out = array();
		foreach ( $rows as $r ) {
			$tier  = (string) ( $r->tier ?? 'unverified' );
			$host  = (string) wp_parse_url( (string) ( $r->source_url ?? '' ), PHP_URL_HOST );
			$title = trim( (string) ( $r->source_title ?? '' ) );
			$out[] = array(
				'tier'    => array( 'text' => $tier, 'tone' => sn_note_dossier_tone( function_exists( 'sn_cit_tier_pill_kind' ) ? sn_cit_tier_pill_kind( $tier ) : '' ) ),
				'source'  => '' !== $title ? $title : ( '' !== $host ? $host : (string) ( $r->source_url ?? '' ) ),
				'checked' => function_exists( 'sn_cit_ago_label' ) ? sn_cit_ago_label( $r->last_checked_gmt ?? null ) : '',
			);
		}
		$blocks[] = sn_note_dossier_table(
			'trust',
			__( 'Citations received', 'signal-and-noise-tools' ),
			array( array( 'key' => 'tier', 'label' => __( 'Tier', 'signal-and-noise-tools' ) ), array( 'key' => 'source', 'label' => __( 'Source', 'signal-and-noise-tools' ) ), array( 'key' => 'checked', 'label' => __( 'Last checked', 'signal-and-noise-tools' ) ) ),
			$out,
			__( 'citation graph', 'signal-and-noise-tools' ),
			function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Citations in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-tools', 'citations' ) ) : null
		);
	}
	return $blocks;
}

/**
 * The re-check: what the server can verify about one note, right now.
 *
 * Walks the integrity module's legs -- the published twin, the ledger record
 * of the newest confirmed version, the key ids the ledger publishes -- and
 * returns a verdict the app stores in its state. The Ed25519 signature is
 * verified by the public /verify page in the browser; this verdict names
 * what it checked and never claims more.
 *
 * @param int           $post_id
 * @param callable|null $fetcher As in sn_note_dossier_trust().
 * @return array{post_id:int,tone:string,text:string,meta:string,checked_at:string}
 */
function sn_note_dossier_verify( $post_id, $fetcher = null ) {
	$checked_at = gmdate( 'c' );
	$post       = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array( 'post_id' => (int) $post_id, 'tone' => 'warning', 'text' => __( 'Not a note.', 'signal-and-noise-tools' ), 'meta' => '', 'checked_at' => $checked_at );
	}
	$fetcher = is_callable( $fetcher ) ? $fetcher : ( function_exists( 'sn_prov_integrity_http_fetch' ) ? 'sn_prov_integrity_http_fetch' : null );
	if ( ! is_callable( $fetcher ) || ! function_exists( 'sn_prov_integrity_keys_probe' ) || ! function_exists( 'sn_prov_integrity_check_note' ) ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'warning', 'text' => __( 'The verifier is not loaded.', 'signal-and-noise-tools' ), 'meta' => '', 'checked_at' => $checked_at );
	}
	$probe = sn_prov_integrity_keys_probe( $fetcher );
	$ids   = isset( $probe['published_ids'] ) && is_array( $probe['published_ids'] ) ? $probe['published_ids'] : null;
	$r     = sn_prov_integrity_check_note( (int) $post->ID, $fetcher, $ids );
	if ( ! is_array( $r ) ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'neutral', 'text' => __( 'Nothing to verify yet: no signed version.', 'signal-and-noise-tools' ), 'meta' => __( 'A note is signed when it is published.', 'signal-and-noise-tools' ), 'checked_at' => $checked_at );
	}
	$failures = array_values( array_map( 'strval', (array) ( $r['failures'] ?? array() ) ) );
	if ( 'keys_unreachable' === (string) ( $probe['verdict'] ?? '' ) ) {
		$failures[] = 'keys_unreachable';
	}
	// Outages AND named gaps are warnings: neither is a claim about the note.
	// `subject_kind_unresolved` is the house's own "a gap, never a drift claim".
	$gaps      = array( 'subject_kind_unresolved' );
	$is_outage = function_exists( 'sn_prov_integrity_is_outage' ) ? 'sn_prov_integrity_is_outage' : static function ( $c ) { return false; };
	$is_gap    = static function ( $c ) use ( $is_outage, $gaps ) { return (bool) call_user_func( $is_outage, $c ) || in_array( $c, $gaps, true ); };
	$real      = array_values( array_filter( $failures, static function ( $c ) use ( $is_gap ) { return ! $is_gap( $c ); } ) );
	$outages   = array_values( array_filter( $failures, $is_gap ) );
	$sentence  = function_exists( 'sn_prov_integrity_failure_sentence' ) ? 'sn_prov_integrity_failure_sentence' : 'strval';
	$anchored  = (int) ( $r['anchored_version'] ?? 0 );
	$version   = (int) ( $r['version'] ?? 0 );
	$uid       = (string) ( $r['uid'] ?? '' );
	// The ledger leg runs only for a confirmed version WITH a uid (provenance-
	// integrity.php:353). The sentence keys on the same precondition, so it never
	// claims a check that did not run.
	if ( $anchored > 0 && '' !== $uid ) {
		$checked = sprintf( /* translators: %d: anchored version. */ __( 'Checked: the published twin, the ledger record for v%d, and the key ids the ledger publishes. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' ), $anchored );
	} elseif ( '' === $uid ) {
		$checked = __( 'Checked: the published twin and the key ids the ledger publishes; this note carries no ledger UID, so no ledger record was located. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' );
	} else {
		$checked = __( 'Checked: the published twin and the key ids the ledger publishes; no confirmed anchor yet, so there is no ledger record to read. The signature itself is verified by the public /verify page.', 'signal-and-noise-tools' );
	}
	if ( $real ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'danger', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d does not hold.', 'signal-and-noise-tools' ), $version ), 'meta' => implode( '; ', array_map( $sentence, $failures ) ) . '. ' . $checked, 'checked_at' => $checked_at );
	}
	if ( $outages ) {
		return array( 'post_id' => (int) $post->ID, 'tone' => 'warning', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d could not be fully checked.', 'signal-and-noise-tools' ), $version ), 'meta' => implode( '; ', array_map( $sentence, $outages ) ) . '. ' . $checked, 'checked_at' => $checked_at );
	}
	return array( 'post_id' => (int) $post->ID, 'tone' => 'success', 'text' => sprintf( /* translators: %d: version. */ __( 'v%d holds.', 'signal-and-noise-tools' ), $version ), 'meta' => $checked, 'checked_at' => $checked_at );
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/note-dossier-trust.php`
Expected: `Result: 23 passed, 0 failed.`

- [ ] **Step 5: Commit**

```bash
git add inc/note-dossier-trust.php tests/note-dossier-trust.php
git commit -m "feat: the trust block — anchor proof from the ledger record, the signer against the published keys, citations received — and the re-check runner"
```

---

### Task 5: The numbers builder

**Files:**
- Create: `inc/note-dossier-numbers.php`
- Test: `tests/note-dossier-numbers.php`

Readers: `sn_analytics_post_path( $id )`, `snt_analytics_range_dates( $days )` → `[ $from, $to ]`, `sn_analytics_path_window()` (Task 1), `snt_gsc_data()`, `sn_path_join_key( $url )`, `snt_gsc_metrics_for_path( $key )`, `snt_gsc_window_totals()`, `snt_mr_snapshot()`, `snt_mr_snapshot_total( $snap )`, `snt_analytics_page_url( $args )`, `snt_desktop_admin_url( 'sn-monitoring', 'machine-readers' )`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/note-dossier-numbers.php — the numbers block for one
 * note: views and visits over the window, Search Console in the sync's own
 * window, and the honest machine-reads line. Every reader is a stub.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ), 8 => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ) );

function sn_analytics_post_path( $id ) { return '/notes/foo/'; }
function snt_analytics_range_dates( $days ) { return array( '2026-08-07', '2026-09-05' ); }
function sn_analytics_path_window( $path, $from, $to ) { $GLOBALS['__win_args'] = array( $path, $from, $to ); return $GLOBALS['__win']; }
function snt_gsc_data() { return $GLOBALS['__gsc']; }
function sn_path_join_key( $u ) { return '/notes/foo'; }
function snt_gsc_metrics_for_path( $k ) { return $GLOBALS['__gsc_row']; }
function snt_gsc_window_totals() { return $GLOBALS['__gsc_tot']; }
function snt_mr_snapshot() { return $GLOBALS['__snap']; }
function snt_mr_snapshot_total( $s ) { return is_array( $s ) && isset( $s['captured_at'] ) ? (int) $s['total'] : null; }
function snt_analytics_page_url( $args = array() ) { return 'https://example.test/wp-admin/admin.php?page=sn-analytics&' . http_build_query( $args ); }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-numbers.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function tile( $block, $label ) { foreach ( $block['tiles'] as $t ) { if ( $t['label'] === $label ) { return $t; } } return null; }
echo "note dossier -- numbers\n\n";

$GLOBALS['__win']     = array( 'views' => 312, 'visits' => 187, 'days' => 9, 'site_rows' => 40 );
$GLOBALS['__gsc']     = array( 'window' => array( 'start' => '2026-08-05', 'end' => '2026-09-01' ), 'synced_at' => 1788600000, 'pages' => array() );
$GLOBALS['__gsc_row'] = array( 'clicks' => 4, 'impressions' => 120, 'position' => 8.4, 'ctr' => 0.0333 );
$GLOBALS['__gsc_tot'] = array( 'clicks' => 40, 'impressions' => 1200, 'days' => 28, 'capped' => false );
$GLOBALS['__snap']    = array( 'captured_at' => 1788600000, 'total' => 72597, 'days' => 30 );

$b = sn_note_dossier_numbers( 7, 30 );
ok( 2 === count( $b ) && 'stats' === $b[0]['kind'] && 'numbers' === $b[0]['group'] && 'status' === $b[1]['kind'], 'two blocks: the tiles, then the machine-reads line' );
ok( array( '/notes/foo/', '2026-08-07', '2026-09-05' ) === $GLOBALS['__win_args'], 'the window read gets the note path and the days as dates' );
ok( '312' === tile( $b[0], 'Views' )['value'] && '30 days' === tile( $b[0], 'Views' )['window'] && '187' === tile( $b[0], 'Visits' )['value'], 'views and visits, each naming the 30-day window' );
ok( '120' === tile( $b[0], 'Impressions' )['value'] && '4' === tile( $b[0], 'Clicks' )['value'] && false !== strpos( tile( $b[0], 'Impressions' )['window'], '2026-08-05' ) && false !== strpos( tile( $b[0], 'Clicks' )['note'], '8.4' ), 'Search Console tiles name the SYNC window, not the switch, and carry the position' );
ok( false !== strpos( $b[0]['door']['url'], 'sn_view=content' ) && false !== strpos( $b[0]['door']['url'], 'sn_range=30' ), 'the door lands on the Analytics content view for the window' );
ok( 'neutral' === $b[1]['tone'] && false !== strpos( $b[1]['text'], 'Not counted per note' ) && false !== strpos( $b[1]['meta'], '72,597' ) && false !== strpos( $b[1]['door']['url'], 'machine-readers' ), 'machine reads: not counted per note, the site-wide figure named as such, a door to the leaf' );

echo "\nthe honest zeros\n";
$GLOBALS['__win'] = array( 'views' => 0, 'visits' => 0, 'days' => 0, 'site_rows' => 40 );
$b = sn_note_dossier_numbers( 7, 7 );
ok( '0' === tile( $b[0], 'Views' )['value'] && '7 days' === tile( $b[0], 'Views' )['window'], 'no rows for this note while the site has rows: a real zero' );
$GLOBALS['__win'] = array( 'views' => 0, 'visits' => 0, 'days' => 0, 'site_rows' => 0 );
$b = sn_note_dossier_numbers( 7, 90 );
ok( '—' === tile( $b[0], 'Views' )['value'] && false !== strpos( tile( $b[0], 'Views' )['note'], 'no analytics' ), 'no rows anywhere in the window: not a zero, a note' );
$GLOBALS['__win'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( '—' === tile( $b[0], 'Views' )['value'] && false !== strpos( tile( $b[0], 'Views' )['note'], 'could not be read' ), 'a failed read is named, never a zero' );
$GLOBALS['__gsc_row'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( '—' === tile( $b[0], 'Impressions' )['value'] && false !== strpos( tile( $b[0], 'Impressions' )['note'], 'not shown' ), 'no row for this note: Google did not show it in the window' );
$GLOBALS['__gsc_tot'] = array( 'clicks' => 40, 'impressions' => 1200, 'days' => 28, 'capped' => true );
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( tile( $b[0], 'Impressions' )['note'], 'top 250' ), 'when the sync is capped, absence may be truncation and says so' );
$GLOBALS['__gsc'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( tile( $b[0], 'Impressions' )['note'], 'never synced' ), 'Search Console never synced is its own sentence' );
$GLOBALS['__snap'] = null;
$b = sn_note_dossier_numbers( 7, 30 );
ok( false !== strpos( $b[1]['meta'], 'No site-wide measurement' ), 'no snapshot: no site-wide figure, said so' );
ok( array() === sn_note_dossier_numbers( 8, 30 ), 'a draft has no numbers: no URL a reader reaches' );
ok( array() === sn_note_dossier_numbers( 999, 30 ), 'no post, no blocks' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/note-dossier-numbers.php`
Expected: fatal on the missing module.

- [ ] **Step 3: Write the module**

Create `inc/note-dossier-numbers.php`:

```php
<?php
/**
 * Signal & Noise Tools — the note dossier: the numbers block.
 *
 * A glance and a door. Views and visits over the requested window from the
 * durable daily table (both spellings of the path); impressions, clicks and
 * position from the Search Console sync in ITS window, which the switch
 * cannot change; and the machine-reads line, which is honest about the one
 * thing the sensor does not do: count per document. Every tile names its
 * own window, so three windows never read as one.
 *
 * Zeros are earned, never assumed: a note absent from the table while the
 * table holds rows had no views; a table with no rows in the window has no
 * analytics; a failed read is named.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param int $post_id
 * @param int $days One of SN_NOTE_DOSSIER_WINDOWS.
 * @return array<int,array<string,mixed>> Empty for an unpublished note.
 */
function sn_note_dossier_numbers( $post_id, $days ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post || ! sn_note_dossier_is_public( $post ) ) {
		return array();
	}
	$days  = sn_note_dossier_days( $days );
	$dash  = '—';
	$tiles = array();
	$wl    = sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', $days, 'signal-and-noise-tools' ), $days );

	// ── Views and visits: the durable daily table ────────────────────────
	$path = function_exists( 'sn_analytics_post_path' ) ? (string) sn_analytics_post_path( $post->ID ) : (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
	if ( function_exists( 'snt_analytics_range_dates' ) ) {
		list( $from, $to ) = snt_analytics_range_dates( $days );
	} else {
		$to   = gmdate( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	}
	$win = ( '' !== $path && function_exists( 'sn_analytics_path_window' ) ) ? sn_analytics_path_window( $path, $from, $to ) : null;
	if ( ! is_array( $win ) ) {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => __( 'the analytics table could not be read', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => '' );
	} elseif ( 0 === (int) $win['site_rows'] ) {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => __( 'no analytics recorded in this window', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => '' );
	} else {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) $win['views'] ), 'window' => $wl, 'note' => '' );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) $win['visits'] ), 'window' => $wl, 'note' => __( 'visitor-days', 'signal-and-noise-tools' ) );
	}

	// ── Search Console: the sync's own window ────────────────────────────
	$gsc = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	if ( ! is_array( $gsc ) ) {
		$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => __( 'Search Console', 'signal-and-noise-tools' ), 'note' => __( 'never synced', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => __( 'Search Console', 'signal-and-noise-tools' ), 'note' => '' );
	} else {
		$gw  = is_array( $gsc['window'] ?? null ) ? $gsc['window'] : array();
		$gwl = sprintf( /* translators: 1: start date, 2: end date. */ __( '%1$s to %2$s', 'signal-and-noise-tools' ), (string) ( $gw['start'] ?? '?' ), (string) ( $gw['end'] ?? '?' ) );
		$key = function_exists( 'sn_path_join_key' ) ? (string) sn_path_join_key( (string) get_permalink( $post ) ) : '';
		$row = ( '' !== $key && function_exists( 'snt_gsc_metrics_for_path' ) ) ? snt_gsc_metrics_for_path( $key ) : null;
		$tot = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
		if ( ! is_array( $row ) ) {
			$why     = ( is_array( $tot ) && ! empty( $tot['capped'] ) ) ? __( 'not among the top 250 rows the sync keeps', 'signal-and-noise-tools' ) : __( 'not shown by Google in this window', 'signal-and-noise-tools' );
			$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $gwl, 'note' => $why );
			$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $gwl, 'note' => '' );
		} else {
			$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) ( $row['impressions'] ?? 0 ) ), 'window' => $gwl, 'note' => '' );
			$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ), 'window' => $gwl, 'note' => sprintf( /* translators: %s: average position. */ __( 'average position %s', 'signal-and-noise-tools' ), number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ) ) );
		}
	}

	$blocks   = array();
	$blocks[] = sn_note_dossier_stats(
		'numbers',
		__( 'Numbers', 'signal-and-noise-tools' ),
		$tiles,
		__( 'analytics table; Search Console sync', 'signal-and-noise-tools' ),
		function_exists( 'snt_analytics_page_url' ) ? sn_note_dossier_door( __( 'Open S&N Analytics', 'signal-and-noise-tools' ), snt_analytics_page_url( array( 'sn_view' => 'content', 'sn_range' => $days ) ) ) : null
	);

	// ── Machine reads: not counted per note, by design ───────────────────
	$snap  = function_exists( 'snt_mr_snapshot' ) ? snt_mr_snapshot() : null;
	$total = function_exists( 'snt_mr_snapshot_total' ) ? snt_mr_snapshot_total( $snap ) : null;
	$meta  = __( 'The sensor keeps no document paths, by its privacy contract.', 'signal-and-noise-tools' );
	$meta .= null === $total
		? ' ' . __( 'No site-wide measurement yet.', 'signal-and-noise-tools' )
		: ' ' . sprintf( /* translators: %s: reads. */ __( 'Site-wide over the last 30 days: %s.', 'signal-and-noise-tools' ), number_format_i18n( (int) $total ) );
	$blocks[] = sn_note_dossier_status(
		'numbers',
		__( 'Machine reads', 'signal-and-noise-tools' ),
		'neutral',
		__( 'Not counted per note.', 'signal-and-noise-tools' ),
		$meta,
		__( 'daily snapshot', 'signal-and-noise-tools' ),
		function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Machine Readers in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-monitoring', 'machine-readers' ) ) : null
	);
	return $blocks;
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/note-dossier-numbers.php`
Expected: `Result: 15 passed, 0 failed.`

- [ ] **Step 5: Commit**

```bash
git add inc/note-dossier-numbers.php tests/note-dossier-numbers.php
git commit -m "feat: the numbers block — views and visits over the window, Search Console in its own window, machine reads said honestly"
```

---

### Task 6: The operating-state builder

**Files:**
- Create: `inc/note-dossier-state.php`
- Test: `tests/note-dossier-state.php`

Readers: `sn_cf_is_configured()`, `get_option( SN_CF_PROBE_LOG_OPT )` with `SN_CF_PROBE_ALGO` (rows newest-first, filter `algo >= SN_CF_PROBE_ALGO`, match on `post_id`), `snt_cf_freshness_headline( $result )`, `snt_cf_freshness_phrase( $result, $time, $now )`, `snt_gsc_coverage_data()`, `snt_gsc_coverage_for_path( $permalink )`, `sn_post_settings_get_noindex( $id )`, `sn_post_settings_get_canonical_url( $id )`, `the_seo_framework` (existence only), `sn_schedule_all()` (rows `target_type`, `target_ref`, `starts_at`, `ends_at`, `status`), `sn_schedule_is_open( $from, $until, $now )`, `sn_admin_schedule_fmt_gmt( $gmt )`, `snt_desktop_admin_url()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/note-dossier-state.php — the last edge verdict for
 * one note from the site-wide probe log, coverage, sitemap membership, and
 * the scheduled fragments that target it.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_CF_PROBE_LOG_OPT', 'sn_cf_purge_probe_log' );
define( 'SN_CF_PROBE_ALGO', 2 );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function current_time( $t, $gmt = 0 ) { return 1788600000; }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7 ) ), 8 => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ) );

function sn_cf_is_configured() { return $GLOBALS['__cf'] ?? true; }
function snt_cf_freshness_headline( $r ) { return 'fresh' === $r ? 'Edge fresh' : 'Edge served a stale render'; }
function snt_cf_freshness_phrase( $r, $t, $now ) { return 'verified 2 hours ago'; }
function snt_gsc_coverage_data() { return $GLOBALS['__cov']; }
function snt_gsc_coverage_for_path( $p ) { return $GLOBALS['__cov_row']; }
function sn_post_settings_get_noindex( $id ) { return $GLOBALS['__noindex'] ?? false; }
function sn_post_settings_get_canonical_url( $id ) { return $GLOBALS['__canon'] ?? ''; }
function sn_schedule_all() { return $GLOBALS['__rows'] ?? array(); }
function sn_schedule_is_open( $f, $u, $now ) { return true; }
function sn_admin_schedule_fmt_gmt( $g ) { return '' === (string) $g || null === $g ? '' : '2026-09-10 09:00'; }
function snt_desktop_admin_url( $slug, $sub = '' ) { return 'https://example.test/wp-admin/admin.php?page=sn-theme-options&slug=' . $slug . '&sub=' . $sub; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-state.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
echo "note dossier -- operating state\n\n";

$GLOBALS['__opt'] = array( 'sn_cf_purge_probe_log' => array(
	array( 'time' => 1788599000, 'post_id' => 9, 'url' => 'https://example.test/notes/bar/', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
	array( 'time' => 1788598000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'fresh', 'algo' => 2 ),
	array( 'time' => 1788500000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'stale', 'escalated' => true, 'algo' => 1 ),
	array( 'time' => 1788400000, 'post_id' => 7, 'url' => 'https://example.test/notes/foo/', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
) );
$GLOBALS['__cov']     = array( 'complete' => true, 'entries' => array() );
$GLOBALS['__cov_row'] = array( 'indexed' => true, 'coverage_state' => 'Submitted and indexed', 'last_crawl_time' => '2026-09-01T10:00:00Z' );
$GLOBALS['__rows']    = array(
	array( 'id' => '3', 'target_type' => 'fragment', 'target_ref' => '7', 'starts_at' => '2026-09-10 09:00:00', 'ends_at' => null, 'status' => 'queued' ),
	array( 'id' => '4', 'target_type' => 'fragment', 'target_ref' => '9', 'starts_at' => null, 'ends_at' => null, 'status' => 'active' ),
);

$p = sn_note_dossier_last_probe( 7 );
ok( 'fresh' === $p['result'] && 1788598000 === $p['time'] && false === $p['escalated'], 'the newest current-detector row for THIS post wins; another post\'s newer row and a retired-detector row are skipped' );
ok( null === sn_note_dossier_last_probe( 8 ), 'no row for a post is null' );

$b = sn_note_dossier_state( 7 );
$edge = by_heading( $b, 'Edge' );
ok( 'success' === $edge['tone'] && 'Edge fresh' === $edge['text'] && 'verified 2 hours ago' === $edge['meta'] && false !== strpos( $edge['door']['url'], 'sub=cloudflare' ), 'the edge verdict uses the house headline and phrase and opens the Cloudflare leaf' );
$cov = by_heading( $b, 'Search index' );
ok( 'success' === $cov['tone'] && 'Indexed' === $cov['text'] && false !== strpos( $cov['meta'], 'Submitted and indexed' ) && false !== strpos( $cov['meta'], '2026-09-01' ) && false !== strpos( $cov['door']['url'], 'search-console' ), 'coverage: indexed, Google\'s own wording, the last crawl, a door to the Search Console leaf' );
$map = by_heading( $b, 'Sitemap' );
ok( 'success' === $map['tone'] && 'In the sitemap' === $map['text'], 'a published, indexable note with no canonical elsewhere is in the sitemap' );
$sch = by_heading( $b, 'Scheduled fragments' );
ok( 'table' === $sch['kind'] && 1 === count( $sch['rows'] ) && '2026-09-10 09:00 → never' === $sch['rows'][0]['window'] && 'queued' === $sch['rows'][0]['status']['text'] && 'visible' === $sch['rows'][0]['now'] && false !== strpos( $sch['door']['url'], 'scheduled-content' ), 'only the rows that target this post, with window, status and whether it is open now; the door opens Connections → Scheduled' );

echo "\nthe other states\n";
$GLOBALS['__opt']['sn_cf_purge_probe_log'][1]['result'] = 'stale';
$GLOBALS['__opt']['sn_cf_purge_probe_log'][1]['escalated'] = true;
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Edge' )['tone'] && false !== strpos( by_heading( $b, 'Edge' )['meta'], 'zone purge' ), 'a stale verdict is a warning and names the forced zone purge' );
$GLOBALS['__opt']['sn_cf_purge_probe_log'] = array();
$b = sn_note_dossier_state( 7 );
ok( 'neutral' === by_heading( $b, 'Edge' )['tone'] && false !== strpos( by_heading( $b, 'Edge' )['text'], 'No probe in the last 20' ), 'no row: no probe among the last twenty site-wide, never "fresh"' );
$GLOBALS['__cf'] = false;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Edge' )['text'], 'not configured' ), 'Cloudflare unconfigured is its own sentence' );
$GLOBALS['__cf'] = true;
$GLOBALS['__cov_row'] = array( 'indexed' => false, 'coverage_state' => 'Crawled - currently not indexed', 'last_crawl_time' => '' );
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Search index' )['tone'] && 'Not indexed' === by_heading( $b, 'Search index' )['text'] && false !== strpos( by_heading( $b, 'Search index' )['meta'], 'Crawled' ), 'not indexed carries Google\'s reason verbatim' );
$GLOBALS['__cov_row'] = array( 'error' => 'no_index_status', 'message' => 'quota' );
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Search index' )['tone'] && false !== strpos( by_heading( $b, 'Search index' )['text'], 'Inspection failed' ) && false !== strpos( by_heading( $b, 'Search index' )['meta'], 'quota' ), 'an error entry is read as an error, never as unknown' );
$GLOBALS['__cov_row'] = null;
$GLOBALS['__cov']['complete'] = false;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Search index' )['text'], 'Not yet inspected' ), 'no entry while a run is partial is "not yet", not "never"' );
$GLOBALS['__cov'] = null;
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Search index' )['text'], 'never run' ), 'no coverage map: the inspection never ran' );
$GLOBALS['__noindex'] = true;
$b = sn_note_dossier_state( 7 );
ok( 'warning' === by_heading( $b, 'Sitemap' )['tone'] && false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'noindex' ), 'a noindex note is out of the sitemap, and the reason is named' );
$GLOBALS['__noindex'] = false;
$GLOBALS['__canon'] = 'https://elsewhere.example/x';
$b = sn_note_dossier_state( 7 );
ok( false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'canonical' ), 'a canonical elsewhere keeps it out, and says so' );
$GLOBALS['__canon'] = '';
$GLOBALS['__rows'] = array();
$b = sn_note_dossier_state( 7 );
ok( 'status' === by_heading( $b, 'Scheduled fragments' )['kind'] && 'neutral' === by_heading( $b, 'Scheduled fragments' )['tone'], 'no fragments: a neutral line, not an empty table' );
ok( array() === sn_note_dossier_state( 8 ), 'a draft has no operating state' );
// The LIVE site runs The SEO Framework. Declared inside a block so it binds only here, after the assertions above.
if ( true ) { function the_seo_framework() { return true; } }
$b = sn_note_dossier_state( 7 );
ok( 'neutral' === by_heading( $b, 'Sitemap' )['tone'] && false !== strpos( by_heading( $b, 'Sitemap' )['meta'], 'does not read its per-post exclusions' ), 'with TSF active -- the LIVE configuration -- membership is a stated gap, never "In the sitemap"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/note-dossier-state.php`
Expected: fatal on the missing module.

- [ ] **Step 3: Write the module**

Create `inc/note-dossier-state.php`:

```php
<?php
/**
 * Signal & Noise Tools — the note dossier: the operating-state block.
 *
 * A glance and a door: the last edge verdict for this URL, whether Google
 * indexes it, whether the sitemap carries it, the scheduled fragments that
 * target it. Each fact opens the S&N Dashboard leaf that owns it.
 *
 * The probe log is a twenty-row SITE-WIDE buffer, newest first by insertion;
 * a note's verdict is evicted by twenty later saves of other notes, so "no
 * probe in the last 20" is the honest absence, never "fresh". Rows are
 * matched on post_id, not url: url is the permalink at probe time.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The newest current-detector probe row for a post, or null.
 *
 * @param int $post_id
 * @return array{result:string,time:int,url:string,escalated:bool}|null
 */
function sn_note_dossier_last_probe( $post_id ) {
	if ( ! defined( 'SN_CF_PROBE_LOG_OPT' ) || ! defined( 'SN_CF_PROBE_ALGO' ) ) {
		return null;
	}
	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		return null;
	}
	foreach ( $log as $row ) {
		if ( ! is_array( $row ) || (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO || (int) ( $row['post_id'] ?? 0 ) !== (int) $post_id ) {
			continue;
		}
		$result = (string) ( $row['result'] ?? '' );
		return array(
			'result'    => in_array( $result, array( 'fresh', 'stale' ), true ) ? $result : 'unknown',
			'time'      => (int) ( $row['time'] ?? 0 ),
			'url'       => (string) ( $row['url'] ?? '' ),
			'escalated' => ! empty( $row['escalated'] ),
		);
	}
	return null;
}

/**
 * @param int $post_id
 * @return array<int,array<string,mixed>> Empty for an unpublished note.
 */
function sn_note_dossier_state( $post_id ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post || ! sn_note_dossier_is_public( $post ) ) {
		return array();
	}
	$blocks = array();
	$door   = static function ( $label, $slug, $sub ) {
		return function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( $label, snt_desktop_admin_url( $slug, $sub ) ) : null;
	};

	// ── Edge verdict ─────────────────────────────────────────────────────
	$cf_door = $door( __( 'Open Cloudflare in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-connections', 'cloudflare' );
	if ( function_exists( 'sn_cf_is_configured' ) && ! sn_cf_is_configured() ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), 'neutral', __( 'Edge purge not configured.', 'signal-and-noise-tools' ), __( 'No probe is ever written without a Cloudflare token and zone.', 'signal-and-noise-tools' ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
	} else {
		$probe = sn_note_dossier_last_probe( $post->ID );
		if ( null === $probe ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), 'neutral', __( 'No probe in the last 20 site-wide.', 'signal-and-noise-tools' ), __( 'Each save probes the edge two minutes later; the log keeps the newest twenty across the site. Absence is a gap, never a pass.', 'signal-and-noise-tools' ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
		} else {
			$tone = 'fresh' === $probe['result'] ? 'success' : ( 'stale' === $probe['result'] ? 'warning' : 'neutral' );
			$text = function_exists( 'snt_cf_freshness_headline' ) ? snt_cf_freshness_headline( $probe['result'] ) : $probe['result'];
			$meta = function_exists( 'snt_cf_freshness_phrase' ) ? snt_cf_freshness_phrase( $probe['result'], $probe['time'], time() ) : '';
			if ( $probe['escalated'] ) {
				$meta .= ' ' . __( 'A zone purge was forced.', 'signal-and-noise-tools' );
			}
			$blocks[] = sn_note_dossier_status( 'state', __( 'Edge', 'signal-and-noise-tools' ), $tone, $text, trim( $meta ), __( 'probe log', 'signal-and-noise-tools' ), $cf_door );
		}
	}

	// ── Coverage ─────────────────────────────────────────────────────────
	$sc_door = $door( __( 'Open Search Console in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-monitoring', 'search-console' );
	$cov     = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	if ( ! is_array( $cov ) ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', __( 'Coverage inspection has never run.', 'signal-and-noise-tools' ), '', __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
	} else {
		$e = function_exists( 'snt_gsc_coverage_for_path' ) ? snt_gsc_coverage_for_path( (string) get_permalink( $post ) ) : null;
		if ( ! is_array( $e ) ) {
			$text = empty( $cov['complete'] ) ? __( 'Not yet inspected: a run is in progress or was interrupted.', 'signal-and-noise-tools' ) : __( 'Not inspected.', 'signal-and-noise-tools' );
			$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', $text, '', __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
		} elseif ( isset( $e['error'] ) ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'warning', __( 'Inspection failed.', 'signal-and-noise-tools' ), (string) ( $e['message'] ?? $e['error'] ), __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
		} else {
			$state = (string) ( $e['coverage_state'] ?? '' );
			$crawl = (string) ( $e['last_crawl_time'] ?? '' );
			$meta  = $state;
			if ( '' !== $crawl ) {
				$meta .= ( '' !== $meta ? '. ' : '' ) . sprintf( /* translators: %s: RFC3339 time. */ __( 'Last crawl %s.', 'signal-and-noise-tools' ), $crawl );
			}
			if ( true === ( $e['indexed'] ?? null ) ) {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'success', __( 'Indexed', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			} elseif ( false === ( $e['indexed'] ?? null ) ) {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'warning', __( 'Not indexed', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			} else {
				$blocks[] = sn_note_dossier_status( 'state', __( 'Search index', 'signal-and-noise-tools' ), 'neutral', __( 'No coverage state', 'signal-and-noise-tools' ), $meta, __( 'Search Console inspection', 'signal-and-noise-tools' ), $sc_door );
			}
		}
	}

	// ── Sitemap ──────────────────────────────────────────────────────────
	if ( function_exists( 'the_seo_framework' ) ) {
		// The LIVE configuration: TSF serves the sitemap and this app does not
		// read its per-post exclusions. A gap, stated as one.
		$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'neutral', __( 'Sitemap membership not checked.', 'signal-and-noise-tools' ), __( 'The SEO Framework serves the sitemap here, and this app does not read its per-post exclusions. A gap, not a verdict.', 'signal-and-noise-tools' ), __( 'the sitemap', 'signal-and-noise-tools' ) );
	} else {
		$noindex = function_exists( 'sn_post_settings_get_noindex' ) && sn_post_settings_get_noindex( $post->ID );
		$canon   = function_exists( 'sn_post_settings_get_canonical_url' ) ? (string) sn_post_settings_get_canonical_url( $post->ID ) : '';
		if ( $noindex ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'warning', __( 'Not in the sitemap', 'signal-and-noise-tools' ), __( 'The note is marked noindex.', 'signal-and-noise-tools' ) );
		} elseif ( '' !== $canon ) {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'warning', __( 'Not in the sitemap', 'signal-and-noise-tools' ), sprintf( /* translators: %s: URL. */ __( 'The note declares a canonical URL elsewhere: %s', 'signal-and-noise-tools' ), $canon ) );
		} else {
			$blocks[] = sn_note_dossier_status( 'state', __( 'Sitemap', 'signal-and-noise-tools' ), 'success', __( 'In the sitemap', 'signal-and-noise-tools' ), __( 'Published, indexable, canonical here.', 'signal-and-noise-tools' ) );
		}
	}

	// ── Scheduled fragments ──────────────────────────────────────────────
	$mine = array();
	if ( function_exists( 'sn_schedule_all' ) ) {
		foreach ( (array) sn_schedule_all() as $row ) {
			if ( is_array( $row ) && 'fragment' === (string) ( $row['target_type'] ?? '' ) && (string) ( $row['target_ref'] ?? '' ) === (string) $post->ID ) {
				$mine[] = $row;
			}
		}
	}
	$sched_door = $door( __( 'Open Scheduled in S&N Dashboard', 'signal-and-noise-tools' ), 'sn-connections', 'scheduled-content' );
	if ( array() === $mine ) {
		$blocks[] = sn_note_dossier_status( 'state', __( 'Scheduled fragments', 'signal-and-noise-tools' ), 'neutral', __( 'No scheduled fragments target this note.', 'signal-and-noise-tools' ), '', __( 'schedule', 'signal-and-noise-tools' ), $sched_door );
	} else {
		$now  = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
		$rows = array();
		foreach ( $mine as $row ) {
			$from   = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? sn_admin_schedule_fmt_gmt( $row['starts_at'] ?? '' ) : (string) ( $row['starts_at'] ?? '' );
			$until  = function_exists( 'sn_admin_schedule_fmt_gmt' ) ? sn_admin_schedule_fmt_gmt( $row['ends_at'] ?? '' ) : (string) ( $row['ends_at'] ?? '' );
			$status = (string) ( $row['status'] ?? 'queued' );
			$open   = function_exists( 'sn_schedule_is_open' ) ? (bool) sn_schedule_is_open( $row['starts_at'] ?? null, $row['ends_at'] ?? null, $now ) : false;
			$rows[] = array(
				'window' => ( '' !== $from ? $from : __( 'always', 'signal-and-noise-tools' ) ) . ' → ' . ( '' !== $until ? $until : __( 'never', 'signal-and-noise-tools' ) ),
				'status' => array( 'text' => $status, 'tone' => 'active' === $status ? 'success' : ( 'error' === $status ? 'warning' : 'neutral' ) ),
				'now'    => $open ? __( 'visible', 'signal-and-noise-tools' ) : __( 'hidden', 'signal-and-noise-tools' ),
			);
		}
		$blocks[] = sn_note_dossier_table(
			'state',
			__( 'Scheduled fragments', 'signal-and-noise-tools' ),
			array( array( 'key' => 'window', 'label' => __( 'Window', 'signal-and-noise-tools' ) ), array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ), array( 'key' => 'now', 'label' => __( 'Now', 'signal-and-noise-tools' ) ) ),
			$rows,
			__( 'schedule', 'signal-and-noise-tools' ),
			$sched_door
		);
	}
	return $blocks;
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/note-dossier-state.php`
Expected: `Result: 18 passed, 0 failed.`

- [ ] **Step 5: Commit**

```bash
git add inc/note-dossier-state.php tests/note-dossier-state.php
git commit -m "feat: the operating-state block — the last edge verdict, coverage, sitemap membership, scheduled fragments, each with its door"
```

---

### Task 7: The editorial builder

**Files:**
- Create: `inc/note-dossier-editorial.php`
- Test: `tests/note-dossier-editorial.php`

Readers: `wp_get_post_terms( $id, 'post_tag', [ 'fields' => 'names' ] )` (WP_Error → "could not be read"), `get_post_meta( $id, SN_READING_TIME_META_KEY, true )` ('' → "not computed yet"; the getter writes meta on a miss, so it is not called), `snt_corpus_word_count( $post->post_content )`, `snt_corpus_excerpt( $post )` (what agents get through the sn-posts ability, labelled so), `snt_ml_related_for_post( $id, 3 )` (null → omitted; `[]` → none; rows `{ post_id, score }`), `get_the_title( $id )`.

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/note-dossier-editorial.php — tags, reading time,
 * word count, the excerpt served to agents, and related notes from the
 * plugin's own kernel, never the theme's backfilling query.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_READING_TIME_META_KEY', '_sn_reading_time_minutes' );
define( 'SN_READING_TIME_DEFAULT_WPM', 225 );
function __( $t, $d = null ) { return $t; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function apply_filters( $h, $v ) { return 'sn_reading_time_wpm' === $h ? ( $GLOBALS['__wpm'] ?? $v ) : $v; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ (int) $id ][ $key ] ?? ''; }
function get_the_title( $p = 0 ) { return 'Note ' . (int) $p; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return $GLOBALS['__tags']; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error { public $code; public function __construct( $c = '' ) { $this->code = $c; } }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public $post_content = ''; public $post_excerpt = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array( 7 => new WP_Post( array( 'ID' => 7, 'post_content' => '<!-- wp:paragraph --><p>one two three four</p><!-- /wp:paragraph -->' ) ) );
function snt_corpus_word_count( $c ) { return 4; }
function snt_corpus_excerpt( $p ) { return 'one two three four'; }
function snt_ml_related_for_post( $id, $limit ) { return $GLOBALS['__related']; }

require __DIR__ . '/../inc/note-dossier.php';
require __DIR__ . '/../inc/note-dossier-editorial.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function by_heading( $blocks, $h ) { foreach ( $blocks as $b ) { if ( $b['heading'] === $h ) { return $b; } } return null; }
function tile( $block, $label ) { foreach ( $block['tiles'] as $t ) { if ( $t['label'] === $label ) { return $t; } } return null; }
echo "note dossier -- editorial\n\n";

$GLOBALS['__meta']    = array( 7 => array( '_sn_reading_time_minutes' => '3' ) );
$GLOBALS['__tags']    = array( 'provenance', 'signatures' );
$GLOBALS['__related'] = array( array( 'post_id' => 11, 'score' => 0.42 ), array( 'post_id' => 12, 'score' => 0.31 ) );
$b = sn_note_dossier_editorial( 7 );
$e = by_heading( $b, 'Editorial' );
ok( 'stats' === $e['kind'] && '3 min' === tile( $e, 'Reading time' )['value'] && '4' === tile( $e, 'Words' )['value'] && '2' === tile( $e, 'Tags' )['value'], 'reading time from the meta, the word count, the tag count' );
$GLOBALS['__wpm'] = 180;
ok( false !== strpos( tile( by_heading( sn_note_dossier_editorial( 7 ), 'Editorial' ), 'Reading time' )['note'], 'at 180 words' ), 'the pace named is the FILTERED sn_reading_time_wpm, not the default' );
unset( $GLOBALS['__wpm'] );
ok( false !== strpos( tile( $e, 'Words' )['note'], 'whitespace' ), 'the word count names its counter, because reading time uses another' );
ok( 'provenance, signatures' === by_heading( $b, 'Tags' )['text'], 'tags listed by name' );
ok( 'one two three four' === by_heading( $b, 'Excerpt served to agents' )['text'] && false !== strpos( by_heading( $b, 'Excerpt served to agents' )['source'], 'sn-posts' ), 'the excerpt is the one agents get, and says which' );
$r = by_heading( $b, 'Related notes' );
ok( 'table' === $r['kind'] && 2 === count( $r['rows'] ) && 'Note 11' === $r['rows'][0]['title'] && '0.42' === $r['rows'][0]['score'], 'related notes from the kernel with their scores' );

echo "\nthe absences\n";
$GLOBALS['__meta'] = array();
$GLOBALS['__tags'] = array();
$GLOBALS['__related'] = array();
$b = sn_note_dossier_editorial( 7 );
ok( '—' === tile( by_heading( $b, 'Editorial' ), 'Reading time' )['value'] && false !== strpos( tile( by_heading( $b, 'Editorial' ), 'Reading time' )['note'], 'not computed' ), 'no cached reading time: not computed yet, and the getter is NOT called (it writes meta)' );
ok( false !== strpos( by_heading( $b, 'Tags' )['text'], 'Untagged' ), 'no tags says untagged' );
ok( 'text' === by_heading( $b, 'Related notes' )['kind'] && false !== strpos( by_heading( $b, 'Related notes' )['text'], 'None' ), 'the kernel answered "none": said so' );
$GLOBALS['__related'] = null;
$b = sn_note_dossier_editorial( 7 );
ok( null === by_heading( $b, 'Related notes' ), 'the kernel not built: the block is omitted, not faked' );
$GLOBALS['__tags'] = new WP_Error( 'x' );
$b = sn_note_dossier_editorial( 7 );
ok( false !== strpos( by_heading( $b, 'Tags' )['text'], 'could not be read' ) && '—' === tile( by_heading( $b, 'Editorial' ), 'Tags' )['value'], 'a taxonomy error is named, never "untagged"' );
ok( array() === sn_note_dossier_editorial( 999 ), 'no post, no blocks' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/note-dossier-editorial.php`
Expected: fatal on the missing module.

- [ ] **Step 3: Write the module**

Create `inc/note-dossier-editorial.php`:

```php
<?php
/**
 * Signal & Noise Tools — the note dossier: the editorial block.
 *
 * Tags, reading time, word count, the excerpt as agents receive it, and
 * related notes -- from the plugin's own kernel, which answers "none"
 * honestly; the theme's related-notes query backfills with recent posts and
 * would present recency as relation.
 *
 * Reading time is READ from its cached meta, never through the getter: the
 * getter computes and WRITES on a miss, and a dossier is a read.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param int $post_id
 * @return array<int,array<string,mixed>>
 */
function sn_note_dossier_editorial( $post_id ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$dash  = '—';
	$tags  = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
	$terr  = is_wp_error( $tags );
	$tags  = $terr ? array() : array_values( array_map( 'strval', (array) $tags ) );
	$mins  = defined( 'SN_READING_TIME_META_KEY' ) ? (string) get_post_meta( $post->ID, SN_READING_TIME_META_KEY, true ) : '';
	$words = function_exists( 'snt_corpus_word_count' ) ? (int) snt_corpus_word_count( (string) $post->post_content ) : null;
	// The live pace, not the default: the site can filter sn_reading_time_wpm.
	$wpm   = (int) apply_filters( 'sn_reading_time_wpm', defined( 'SN_READING_TIME_DEFAULT_WPM' ) ? SN_READING_TIME_DEFAULT_WPM : 225, $post );
	$wpm   = $wpm > 0 ? $wpm : 225;

	$tiles   = array();
	$tiles[] = '' === $mins
		? array( 'label' => __( 'Reading time', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => '', 'note' => __( 'not computed yet', 'signal-and-noise-tools' ) )
		: array( 'label' => __( 'Reading time', 'signal-and-noise-tools' ), 'value' => sprintf( /* translators: %d: minutes. */ __( '%d min', 'signal-and-noise-tools' ), (int) $mins ), 'window' => '', 'note' => sprintf( /* translators: %d: words per minute. */ __( 'at %d words a minute', 'signal-and-noise-tools' ), $wpm ) );
	$tiles[] = null === $words
		? array( 'label' => __( 'Words', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => '', 'note' => __( 'counter not loaded', 'signal-and-noise-tools' ) )
		: array( 'label' => __( 'Words', 'signal-and-noise-tools' ), 'value' => number_format_i18n( $words ), 'window' => '', 'note' => __( 'whitespace count, as agents receive it', 'signal-and-noise-tools' ) );
	$tiles[] = array( 'label' => __( 'Tags', 'signal-and-noise-tools' ), 'value' => $terr ? $dash : (string) count( $tags ), 'window' => '', 'note' => '' );

	$blocks   = array();
	$blocks[] = sn_note_dossier_stats( 'editorial', __( 'Editorial', 'signal-and-noise-tools' ), $tiles, __( 'the post', 'signal-and-noise-tools' ) );
	$blocks[] = sn_note_dossier_text(
		'editorial',
		__( 'Tags', 'signal-and-noise-tools' ),
		$terr ? __( 'The tags could not be read.', 'signal-and-noise-tools' ) : ( $tags ? implode( ', ', $tags ) : __( 'Untagged.', 'signal-and-noise-tools' ) )
	);
	if ( function_exists( 'snt_corpus_excerpt' ) ) {
		$excerpt = (string) snt_corpus_excerpt( $post );
		$blocks[] = sn_note_dossier_text( 'editorial', __( 'Excerpt served to agents', 'signal-and-noise-tools' ), '' !== $excerpt ? $excerpt : __( 'Empty.', 'signal-and-noise-tools' ), __( 'the sn-posts ability', 'signal-and-noise-tools' ) );
	}
	if ( function_exists( 'snt_ml_related_for_post' ) ) {
		$related = snt_ml_related_for_post( $post->ID, 3 );
		if ( is_array( $related ) ) {
			if ( array() === $related ) {
				$blocks[] = sn_note_dossier_text( 'editorial', __( 'Related notes', 'signal-and-noise-tools' ), __( 'None related in the kernel.', 'signal-and-noise-tools' ), __( 'the ML kernel', 'signal-and-noise-tools' ) );
			} else {
				$rows = array();
				foreach ( $related as $r ) {
					$rid    = (int) ( $r['post_id'] ?? 0 );
					$rows[] = array( 'title' => (string) get_the_title( $rid ), 'score' => number_format_i18n( (float) ( $r['score'] ?? 0 ), 2 ) );
				}
				$blocks[] = sn_note_dossier_table( 'editorial', __( 'Related notes', 'signal-and-noise-tools' ), array( array( 'key' => 'title', 'label' => __( 'Note', 'signal-and-noise-tools' ) ), array( 'key' => 'score', 'label' => __( 'Score', 'signal-and-noise-tools' ) ) ), $rows, __( 'the ML kernel', 'signal-and-noise-tools' ) );
			}
		}
		// null: the kernel was never built -- omitted, not faked.
	}
	return $blocks;
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/note-dossier-editorial.php`
Expected: `Result: 12 passed, 0 failed.`

- [ ] **Step 5: Commit**

```bash
git add inc/note-dossier-editorial.php tests/note-dossier-editorial.php
git commit -m "feat: the editorial block — tags, reading time read not computed, word count, the excerpt agents get, related notes from the kernel"
```

---

### Task 8: The ability and the requires

**Files:**
- Create: `inc/abilities-note-dossier.php`
- Modify: `inc/abilities-registration.php:90` (append one require), `signal-and-noise-tools.php:502` (append five requires after `inc/ml-artifacts.php`)
- Test: `tests/abilities-note-dossier.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Standalone test: inc/abilities-note-dossier.php — the registration
 * contract (GET, edit_post, the window enum) and the execute callback's
 * envelope, with the composer stubbed. The file is a DOOR: it must load and
 * register with the builders absent, and answer a clean error when they are.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
$GLOBALS['__registered'] = array();
function wp_register_ability( $name, $args ) { $GLOBALS['__registered'][ $name ] = $args; return (object) array( 'name' => $name ); }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

require __DIR__ . '/../inc/abilities-note-dossier.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "abilities -- note-dossier\n\n";

foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) { call_user_func( $cb ); }
$a = $GLOBALS['__registered']['signal-noise/note-dossier'] ?? null;
ok( is_array( $a ), 'registers signal-noise/note-dossier on wp_abilities_api_init' );
ok( 'content' === $a['category'], 'category: content (one of the six registered)' );
ok( 'snt_ability_perm_edit_post' === $a['permission_callback'], 'gated on edit_post for the note itself' );
ok( 'snt_ability_note_dossier' === $a['execute_callback'], 'execute callback is the named function' );
ok( true === $a['meta']['show_in_rest'] && true === $a['meta']['annotations']['readonly'] && true === $a['meta']['annotations']['idempotent'] && true === $a['meta']['annotations']['open_world_hint'], 'REST-reachable, readonly (=> GET), idempotent, and honest that it reads the public ledger over HTTP' );
ok( 'object' === $a['input_schema']['type'] && array( 'post_id' ) === $a['input_schema']['required'] && 'integer' === $a['input_schema']['properties']['post_id']['type'] && 1 === $a['input_schema']['properties']['post_id']['minimum'], 'input: an object requiring post_id (integer >= 1)' );
ok( array( 7, 30, 90 ) === $a['input_schema']['properties']['days']['enum'] && 30 === $a['input_schema']['properties']['days']['default'] && false === $a['input_schema']['additionalProperties'], 'days: 7 | 30 | 90, default 30, nothing else accepted' );
ok( 'object' === $a['output_schema']['type'] && isset( $a['output_schema']['properties']['blocks'], $a['output_schema']['properties']['fetched_at'], $a['output_schema']['properties']['is_public'] ), 'output: the envelope names blocks, is_public and fetched_at' );
ok( ! isset( $a['meta']['public'] ) && ! isset( $a['meta']['mcp'] ), 'no MCP Adapter opt-in keys' );
ok( ! preg_match( "/'(public|mcp)'\\s*=>/", (string) file_get_contents( __DIR__ . '/../inc/abilities-note-dossier.php' ) ), 'the file never spells the adapter opt-in keys: tests/mcp-connect-render.php greps the SOURCE for them, which is why the envelope key is is_public' );

echo "\nexecute: the door with the builders absent\n";
$r = snt_ability_note_dossier( array( 'post_id' => '7', 'days' => '30' ) );
ok( is_wp_error( $r ) && 'snt_note_dossier_unavailable' === $r->get_error_code() && 500 === $r->data['status'], 'builders not loaded: a 500 that says so, never a crash' );

echo "\nexecute: with the composer\n";
// Declared CONDITIONALLY: PHP early-binds unconditional top-level declarations,
// which would make the 500 assertion above impossible (function_exists would
// already be true on line one). Inside a block they bind when execution arrives.
if ( ! function_exists( 'sn_note_dossier_days' ) ) {
	function sn_note_dossier_days( $raw ) { $d = (int) $raw; return in_array( $d, array( 7, 30, 90 ), true ) ? $d : 30; }
	function sn_note_dossier_compose( $id, $days ) { $GLOBALS['__compose_args'] = array( $id, $days ); return 7 === (int) $id ? array( 'ok' => true, 'post_id' => 7, 'days' => $days, 'is_public' => true, 'blocks' => array(), 'fetched_at' => 1 ) : null; }
}
$r = snt_ability_note_dossier( array( 'post_id' => '7', 'days' => '90' ) );
ok( is_array( $r ) && true === $r['ok'] && array( 7, 90 ) === $GLOBALS['__compose_args'], 'GET input arrives as strings; post_id and days are cast before the composer sees them' );
$r = snt_ability_note_dossier( array( 'post_id' => 7, 'days' => 14 ) );
ok( array( 7, 30 ) === $GLOBALS['__compose_args'], 'a window outside the enum falls to 30 in PHP too (the schema is the first gate, not the only one)' );
$r = snt_ability_note_dossier( array( 'post_id' => 999 ) );
ok( is_wp_error( $r ) && 'snt_note_dossier_not_found' === $r->get_error_code() && 404 === $r->data['status'], 'not a note: 404' );
$r = snt_ability_note_dossier( null );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'null input (an input-less GET) is a 404, not a warning' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/abilities-note-dossier.php`
Expected: fatal on the missing module.

- [ ] **Step 3: Write the ability file**

Create `inc/abilities-note-dossier.php`:

```php
<?php
/**
 * Signal & Noise Tools — ability: signal-noise/note-dossier.
 *
 * Everything the estate knows about ONE note, as blocks, for the Signal &
 * Noise OpenStation app's dossier. Read-only, so the run route is GET with
 * bracket-encoded input (input[post_id]=N&input[days]=30); scalars arrive as
 * strings and are cast here. Gated on edit_post for that note: the ability's
 * own permission callback is the only gate on the native run route.
 *
 * This file is a DOOR: it registers with the builders absent (every
 * standalone suite loads it alone) and answers a 500 that says so. It is
 * not on the MCP read door and needs no listing: REST-reachable behind its
 * own permission is what the app needs. The ledger is read over HTTP on each
 * call, which is what `open_world_hint` says.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array|null $input { post_id, days? } -- strings on GET.
 * @return array<string,mixed>|WP_Error
 */
function snt_ability_note_dossier( $input ) {
	if ( ! function_exists( 'sn_note_dossier_compose' ) || ! function_exists( 'sn_note_dossier_days' ) ) {
		return new WP_Error( 'snt_note_dossier_unavailable', __( 'The dossier builders are not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
	$days    = sn_note_dossier_days( is_array( $input ) ? ( $input['days'] ?? 30 ) : 30 );
	$out     = $post_id > 0 ? sn_note_dossier_compose( $post_id, $days ) : null;
	if ( ! is_array( $out ) ) {
		return new WP_Error( 'snt_note_dossier_not_found', __( 'No such note.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}
	return $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/note-dossier', array(
		'label'               => 'Note dossier',
		'description'         => 'Everything the estate knows about ONE note, as blocks the Signal & Noise desktop app paints beside its list: trust (the anchor proof read from the public ledger record of the newest confirmed version, the signer against the keys the ledger publishes, the citations received), numbers (views and visits over the requested window from the durable analytics table; impressions and clicks from the Search Console sync in ITS window; machine reads, which the sensor does not count per document, said so), operating state (the last edge-freshness verdict for the URL, coverage, sitemap membership, scheduled fragments) and editorial (tags, reading time, word count, the excerpt agents receive, related notes from the kernel). Each block names its source and window; a source that could not be read is a warning block naming it, never a zero; an unreachable ledger is a gap, never "not anchored". An unpublished note gets trust and editorial only. Read-only: GET with input[post_id] and input[days] in {7, 30, 90}.',
		'category'            => 'content',
		'permission_callback' => 'snt_ability_perm_edit_post',
		'execute_callback'    => 'snt_ability_note_dossier',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The note (post_type post).' ),
				'days'    => array( 'type' => 'integer', 'enum' => array( 7, 30, 90 ), 'default' => 30, 'description' => 'The window for the analytics tiles. Search Console keeps its own window.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'days'       => array( 'type' => 'integer' ),
				'is_public'  => array( 'type' => 'boolean', 'description' => 'Published and not password-protected; numbers and operating state exist only then.' ),
				'blocks'     => array( 'type' => 'array', 'description' => 'Blocks in the order trust, numbers, state, editorial; each { group, kind: stats|status|text|table, heading, ... , source?, window?, door? }.' ),
				'fetched_at' => array( 'type' => 'integer', 'description' => 'Unix time the dossier was composed.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// readonly => the run controller requires GET.
				'readonly'        => true,
				'idempotent'      => true,
				// It reads the public ledger over HTTP on every call.
				'open_world_hint' => true,
			),
		),
	) );
} );
```

- [ ] **Step 4: Run the test**

Run: `php tests/abilities-note-dossier.php`
Expected: `Result: 15 passed, 0 failed.`

- [ ] **Step 5: Wire the requires**

In `inc/abilities-registration.php`, after line 90 (`abilities-machine-readers.php`), add:

```php
require_once __DIR__ . '/abilities-note-dossier.php';      // v13.100.0: 1 ability (read-only note dossier for the Signal & Noise app)
```

In `signal-and-noise-tools.php`, directly after the `inc/ml-artifacts.php` require (line ~502), add:

```php
require_once __DIR__ . '/inc/note-dossier.php';            // v13.100.0: the note dossier vocabulary + composer (needs nothing at load)
require_once __DIR__ . '/inc/note-dossier-trust.php';      // trust: ledger record, signer, citations, the re-check (readers guarded)
require_once __DIR__ . '/inc/note-dossier-numbers.php';    // numbers: analytics window, Search Console, machine reads
require_once __DIR__ . '/inc/note-dossier-state.php';      // state: edge verdict, coverage, sitemap, schedule
require_once __DIR__ . '/inc/note-dossier-editorial.php';  // editorial: tags, reading time, words, excerpt, related
```

Then run the guards that read the registry and the require list:

Run: `php tests/abilities-integration.php | tail -1 && php tests/ability-permission-policy.php | tail -1 && php tests/mcp-connect-render.php | tail -1 && php tests/dash-widgets.php | tail -1 && php -l signal-and-noise-tools.php`
Expected: four `... 0 failed.` lines and `No syntax errors detected`.

If `tests/abilities-integration.php` fatals on load, the new file called something at top level; it must not (only the function and the `add_action`).

- [ ] **Step 6: Commit**

```bash
git add inc/abilities-note-dossier.php inc/abilities-registration.php signal-and-noise-tools.php tests/abilities-note-dossier.php
git commit -m "feat: the note-dossier ability — GET, edit_post, the 7/30/90 window, the composed envelope; builders required from the bootstrap"
```

---

### Task 9: The app's PHP half — state, config, verify, the action

**Files:**
- Modify: `apps/signal-noise/signal-noise.os.php`, `apps/signal-noise/parts/payload.php`, `apps/signal-noise/parts/notes.php`
- Test: `tests/openstation-app.php`

- [ ] **Step 1: Move the pins and add the failing assertions**

In `tests/openstation-app.php`:

1. Extend the `OpenStation\App` stub with a `config()` setter: add `public $config = array();` to the property list and `public function config( array $c ) { $this->config = $c; return $this; }`.
2. Give the `Os` stub a variadic `can`: `public function can( $c, ...$a ) { return $GLOBALS['__os_can'] ?? true; }`.
3. Add `function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }` to the flat WordPress stubs.
4. Add a house stub the verify action calls: `function sn_note_dossier_verify( $id, $f = null ) { return array( 'post_id' => (int) $id, 'tone' => 'success', 'text' => 'v1 holds.', 'meta' => 'checked', 'checked_at' => '2026-09-05T20:00:00+00:00' ); }`.
5. Change the Group 2 pins to:

```php
ok( array( 'section', 'item', 'status', 'query', 'view', 'verdict' ) === array_keys( $app->state ) && 'icons' === $app->state['view'] && array() === $app->state['verdict'], 'state schema: section, item, status, query, view, verdict (an array slot)' );
ok( array( 'go', 'edit', 'verify' ) === array_keys( $app->actions ), 'three server actions: go, edit, verify -- everything else is local in the browser' );
ok( 'https://example.test/wp-json/wp-abilities/v1/abilities/signal-noise/note-dossier/run' === ( $app->config['dossierUrl'] ?? '' ), 'the ability run URL rides the window config, so the client never spells the abilities path' );
```

6. Append to Group 5 (Notes), after the actions assertion:

```php
ok( in_array( 'Re-check now', array_column( $d['actions'], 'label' ), true ) && 'verify' === $d['actions'][ array_search( 'Re-check now', array_column( $d['actions'], 'label' ), true ) ]['dispatch'], 'a signed note offers Re-check now as a verify dispatch' );
ok( ! in_array( 'Re-check now', array_column( $d12['actions'], 'label' ), true ), 'a draft with no chain does not' );
```

7. Append a group before the "Group 9" heading:

```php
echo "\nGroup 8b: the verify action and the verdict in data\n";
$st = new \OpenStation\App\State( $app->state, array( 'section' => 'notes', 'item' => '11' ) );
$os = new \OpenStation\App\Os();
$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
ok( 11 === $st->get( 'verdict' )['post_id'] && 'success' === $st->get( 'verdict' )['tone'], 'verify stores the verdict in the declared state slot' );
$p = ( $app->data )( $st, $os );
ok( 11 === $p['verdict']['post_id'] && 'v1 holds.' === $p['verdict']['text'], 'the payload projects the verdict into data' );
$GLOBALS['__os_can'] = false;
$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
ok( array() === $st->get( 'verdict' ), 'without edit_post on THAT note the verdict is cleared, and nothing is fetched' );
$GLOBALS['__os_can'] = true;
$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
$app->actions['go']( $st, $os, array( 'section' => 'notes' ) );
ok( array() === $st->get( 'verdict' ), 'go clears the verdict' );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/openstation-app.php | grep -E "FAIL|Result"`
Expected: the state-schema, actions and config pins FAIL; the verify group fatals on the missing action.

- [ ] **Step 3: Change the app**

In `apps/signal-noise/signal-noise.os.php`:

State: add the slot after `'view' => 'icons',`:

```php
			'verdict' => array(), // The last re-check verdict { post_id, tone, text, meta, checked_at }; cleared by go. Server-only.
```

After `->title_bar_button( ... )` and before `->client( ... )`, add:

```php
	// Static values that ship once with the window config (`ctx.extra` in the
	// client): the ability's run URL, so the client never spells the abilities
	// path itself. rest_url() carries the pretty or ?rest_route= form the site
	// uses, which is also what the runtime's nonce injection keys on.
	->config(
		array(
			'dossierUrl' => rest_url( 'wp-abilities/v1/abilities/signal-noise/note-dossier/run' ),
		)
	)
```

In the `go` action, chain `->reset( 'verdict' )` after `->reset( 'query' )`.

After the `edit` action, add:

```php
	// The re-check: the server walks what it can walk (the twin, the ledger
	// record, the published key ids) and the verdict lands in state, which
	// payload() projects into data. Gated on THIS note, not the app.
	->action(
		'verify',
		static function ( State $state, Os $os, array $args ) {
			$id = (int) ( $args['item'] ?? 0 );
			if ( $id <= 0 || ! $os->can( 'edit_post', $id ) || ! function_exists( 'sn_note_dossier_verify' ) ) {
				$state->reset( 'verdict' );
				return;
			}
			$state->set( 'verdict', \sn_note_dossier_verify( $id ) );
		}
	);
```

In `apps/signal-noise/parts/payload.php`, add to the `$out` array after `'cap'`:

```php
		'verdict'  => (array) $state->get( 'verdict', array() ),
```

In `apps/signal-noise/parts/notes.php`, inside `if ( $prov ) {` after the Verify action is appended (the `if ( ! empty( $prov['uid'] ) )` block), add -- gated on the same capability the server action re-checks, so a user the action would refuse is never offered the button:

```php
		if ( current_user_can( 'edit_post', $id ) ) {
			$actions[] = array( 'label' => __( 'Re-check now', 'signal-and-noise-tools' ), 'dispatch' => 'verify', 'args' => array( 'item' => (string) $id ) );
		}
```

That moves one existing pin. In `tests/openstation-app.php` (line 158 today), the labels assertion for note 11 becomes:

```php
ok( array( 'Open in editor', 'Verify', 'Re-check now', 'View on site' ) === $labels && 'edit' === $d['actions'][0]['dispatch'] && '11' === $d['actions'][0]['args']['item'], 'actions: the editor first (a dispatch), the verifier and the site (URLs), the re-check between them' );
```

The revoked-capability pin (`array( 'Verify', 'View on site' )`) stays as it is: without edit_post there is no editor and no re-check. The assertion count is unchanged by the move.

Update the header docblock of `signal-noise.os.php`: "actions `go`, `edit` and `verify`".

- [ ] **Step 4: Run the tests**

Run: `php tests/openstation-app.php | tail -1 && php -l apps/signal-noise/signal-noise.os.php`
Expected: `Result: 59 passed, 0 failed.` (52 before, plus seven) and no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add apps/signal-noise/signal-noise.os.php apps/signal-noise/parts/payload.php apps/signal-noise/parts/notes.php tests/openstation-app.php
git commit -m "feat(app): the verify action, the verdict state slot projected into data, the dossier URL in the window config"
```

---

### Task 10: The client — fetched blocks, the window switch, the verdict, the doors

**Files:**
- Modify: `apps/signal-noise/signal-noise-client.js`, `apps/signal-noise/signal-noise.css`
- Test: `tests/openstation-app.php` (Group 1 substring pins)

- [ ] **Step 1: Add the failing pins**

In `tests/openstation-app.php` Group 1, extend the kit-parts loop and add pins:

```php
foreach ( array( "kind === 'stats'", "kind === 'status'", 'ctx.extra', 'dossierUrl', 'ctx.fetch(', '@os-pick', 'ctx.host.openUrl(', 'data.verdict', 'updated:', "section.id === 'notes'" ) as $part ) { ok( false !== strpos( $js, $part ), "the client carries the dossier: $part" ); }
ok( false === strpos( $js, '/wp-abilities/' ), 'the client never spells the abilities path; it comes from the window config' );
```

Run: `php tests/openstation-app.php | grep -c FAIL` → expected `10` (the ten new parts; the `/wp-abilities/` literal check passes already).

- [ ] **Step 2: Rewrite the block renderers and add the dossier**

In `apps/signal-noise/signal-noise-client.js`:

(a) `ctx.ui()` hands out ONE bag per mounted view -- the factory runs on the first call only -- so a second `ctx.ui( factory )` would return the root's `{ folderSel }` bag and `ui.dossiers.has()` would throw. Change the existing `uiOf` line (34 today) to carry the dossier state too, then alias it:

```js
	/** Client-only state that never travels. ONE bag per mounted view (ctx.ui runs its factory once), so everything lives here. */
	const uiOf = ( ctx ) => ctx.ui( () => ( { folderSel: null, dossiers: new Map(), inflight: new Set(), days: 30 } ) );
	/** The fetched half of a dossier lives in the same bag; keys are `${id}:${days}`. */
	const dossierOf = uiOf;

	const WINDOWS = [ 7, 30, 90 ];

	/** Fetch a note's dossier once per (note, window); repaint when it lands. */
	const loadDossier = ( ctx, itemId ) => {
		const ui = dossierOf( ctx );
		const key = `${ itemId }:${ ui.days }`;
		if ( ui.dossiers.has( key ) || ui.inflight.has( key ) ) {
			return;
		}
		const base = ctx.extra && ctx.extra.dossierUrl ? String( ctx.extra.dossierUrl ) : '';
		if ( ! base ) {
			ui.dossiers.set( key, { error: __( 'The dossier endpoint is not configured.' ) } );
			// This runs from updated(), after the paint that showed the spinner:
			// one repaint, in a microtask so it does not re-enter the render on
			// the stack. It cannot loop: the next updated() finds the key cached.
			Promise.resolve().then( () => ctx.repaint() );
			return;
		}
		ui.inflight.add( key );
		const url = base + ( base.includes( '?' ) ? '&' : '?' ) + 'input[post_id]=' + encodeURIComponent( itemId ) + '&input[days]=' + ui.days;
		ctx.fetch( url )
			.then( ( res ) => ( res.ok ? res.json() : Promise.reject( new Error( String( res.status ) ) ) ) )
			.then( ( body ) => {
				ui.dossiers.set( key, body && Array.isArray( body.blocks ) ? body : { error: __( 'The dossier answered without blocks.' ) } );
			} )
			.catch( ( e ) => {
				ui.dossiers.set( key, { error: sprintf( /* translators: %s: HTTP status or message. */ __( 'The dossier could not be read (%s).' ), e && e.message ? e.message : '' ) } );
			} )
			.then( () => {
				ui.inflight.delete( key );
				ctx.repaint();
			} );
	};
```

(b) Replace `renderBlock` (the whole `const renderBlock = ( block ) => { ... };`) with a body renderer, a heading wrapper, and the fetched-block renderer:

```js
	/** The body of a block, by kind. Unknown kinds paint their text, muted. */
	const renderBlockBody = ( ctx, block ) => {
		if ( block.kind === 'table' ) {
			return html`
				<table class="snt-facts-table">
					<thead><tr>${ ( block.columns || [] ).map( ( c ) => html`<th scope="col">${ c.label }</th>` ) }</tr></thead>
					<tbody>${ ( block.rows || [] ).map( ( row ) => html`<tr>${ ( block.columns || [] ).map( ( c ) => html`<td>${ cell( row[ c.key ] ) }</td>` ) }</tr>` ) }</tbody>
				</table>
			`;
		}
		if ( block.kind === 'code' ) {
			return html`<os-code wrap>${ block.text }</os-code>`;
		}
		if ( block.kind === 'stats' ) {
			return html`<div class="snt-tiles">${ ( block.tiles || [] ).map( ( t ) => html`
				<div class="snt-tile">
					<span class="snt-tile__label">${ t.label }</span>
					<span class="snt-tile__value">${ t.value }</span>
					${ t.window ? html`<span class="snt-tile__window">${ t.window }</span>` : '' }
					${ t.note ? html`<span class="snt-tile__note">${ t.note }</span>` : '' }
				</div>` ) }</div>`;
		}
		if ( block.kind === 'status' ) {
			return html`
				<p class="snt-status"><os-badge tone=${ block.tone || 'neutral' } no-dot>${ block.text }</os-badge></p>
				${ block.meta ? html`<p class="snt-muted">${ block.meta }</p>` : '' }
			`;
		}
		return html`<p class="snt-muted">${ block.text }</p>`;
	};

	/** An inline block: heading + body (the local half of a dossier). */
	const renderBlock = ( ctx, block ) => html`<h3 class="snt-h">${ block.heading }</h3>${ renderBlockBody( ctx, block ) }`;

	/** A fetched block: heading, body, then its source and door (a window belongs to a tile, not a block). */
	const renderFetchedBlock = ( ctx, block ) => html`
		<section class="snt-block snt-block--${ block.group || 'fetched' }">
			<h3 class="snt-h">${ block.heading }</h3>
			${ renderBlockBody( ctx, block ) }
			${ block.source || block.door ? html`<p class="snt-source">
				${ block.source ? html`<span>${ block.source }</span>` : '' }
				${ block.door ? html`<os-button variant="secondary" @click=${ () => ctx.host.openUrl( block.door.url, block.door.label, 'dashicons-shield-alt' ) }>${ block.door.label }</os-button>` : '' }
			</p>` : '' }
		</section>
	`;

	/** The last re-check verdict, when it is this note's. */
	const renderVerdict = ( ctx, item ) => {
		const v = ctx.data.verdict;
		if ( ! v || String( v.post_id ) !== String( item.id ) ) {
			return '';
		}
		return html`
			<section class="snt-block snt-block--verdict">
				<h3 class="snt-h">${ __( 'Re-check' ) }</h3>
				<p class="snt-status"><os-badge tone=${ v.tone || 'neutral' } no-dot>${ v.text }</os-badge></p>
				<p class="snt-muted">${ v.meta }${ v.checked_at ? ' · ' + v.checked_at : '' }</p>
			</section>
		`;
	};

	/** The fetched dossier: the window switch, the verdict, then the blocks or their state. */
	const renderDossier = ( ctx, item ) => {
		const ui = dossierOf( ctx );
		const key = `${ item.id }:${ ui.days }`;
		const got = ui.dossiers.get( key );
		const pick = ( e ) => {
			const v = parseInt( e.detail && e.detail.value, 10 );
			if ( WINDOWS.includes( v ) && v !== ui.days ) {
				ui.days = v;
				ctx.repaint();
			}
		};
		const sw = html`<os-segmented class="snt-window" value=${ String( ui.days ) } label=${ __( 'Window' ) } @os-pick=${ pick }>
			${ WINDOWS.map( ( d ) => html`<os-segment value=${ String( d ) }>${ sprintf( /* translators: %d: days. */ __( '%dd' ), d ) }</os-segment>` ) }
		</os-segmented>`;
		let body;
		if ( ! got ) {
			body = html`<p class="snt-muted snt-loading"><os-spinner></os-spinner> ${ __( 'Reading the estate…' ) }</p>`;
		} else if ( got.error ) {
			body = html`<p class="snt-status"><os-badge tone="warning" no-dot>${ got.error }</os-badge></p>`;
		} else {
			body = got.blocks.map( ( b ) => renderFetchedBlock( ctx, b ) );
		}
		return html`<div class="snt-dossier">${ sw }${ renderVerdict( ctx, item ) }${ body }</div>`;
	};
```

(c) In `renderDetail` (which already destructures `const { data } = ctx;`), change the inline blocks line to pass `ctx` and insert the dossier, for the Notes section only, between the blocks and the actions:

```js
				${ ( d.blocks || [] ).map( ( b ) => renderBlock( ctx, b ) ) }
				${ data.section && data.section.id === 'notes' ? renderDossier( ctx, item ) : '' }
				${ ( d.actions || [] ).length
```

(d) In `defineApp( 'signal-noise', { ... } )`, add after `view:`:

```js
		// After every paint: an open item whose dossier is not cached or in
		// flight gets fetched. Idempotent -- the cache and the inflight set
		// make a second call a no-op -- so painting often costs nothing.
		// Notes only: a Discography id (or a third-party section's) is not a
		// post id, and the ability would answer 400 for it.
		updated: ( ctx ) => {
			if ( ctx.state.item && ctx.data && ctx.data.section && ctx.data.section.id === 'notes' ) {
				loadDossier( ctx, ctx.state.item );
			}
		},
```

(e) Append to `apps/signal-noise/signal-noise.css`:

```css
/* The fetched dossier: the window switch, the verdict, one section per block. */
.snt-dossier { display: flex; flex-direction: column; gap: 4px; align-self: stretch; }
.snt-window { align-self: flex-start; margin: 4px 0 2px; }
.snt-block { display: flex; flex-direction: column; gap: 4px; align-self: stretch; }
.snt-tiles { display: grid; grid-template-columns: repeat( auto-fill, minmax( 120px, 1fr ) ); gap: 8px; align-self: stretch; }
.snt-tile { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border: 1px solid var( --os-ui-border, rgba( 127, 127, 127, 0.22 ) ); border-radius: 8px; min-width: 0; }
.snt-tile__label { font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var( --os-ui-fg-muted, #646970 ); }
.snt-tile__value { font-size: 20px; font-weight: 600; line-height: 1.1; }
.snt-tile__window { font-size: 11px; color: var( --os-ui-fg-muted, #646970 ); }
.snt-tile__note { font-size: 12px; color: var( --os-ui-fg-muted, #646970 ); overflow-wrap: anywhere; }
.snt-status { margin: 0; }
.snt-source { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin: 0; font-size: 11px; color: var( --os-ui-fg-muted, #646970 ); }
.snt-loading { display: flex; align-items: center; gap: 8px; }
```

- [ ] **Step 3: Check syntax and the pins**

Run: `node --check apps/signal-noise/signal-noise-client.js && php tests/openstation-app.php | tail -1`
Expected: no output from node; `Result: 70 passed, 0 failed.` (59 after Task 9, plus eleven pins.)

- [ ] **Step 4: Commit**

```bash
git add apps/signal-noise/signal-noise-client.js apps/signal-noise/signal-noise.css tests/openstation-app.php
git commit -m "feat(app): the fetched dossier — stats and status blocks, the 7/30/90 switch, the verdict, doors into the sibling apps"
```

---

### Task 11: The dock entry gets its own id

**Files:**
- Modify: `inc/desktop-mode-dock.php:118-125`
- Test: `tests/desktop-mode-integration.php:1605-1607`

- [ ] **Step 1: Move the pin**

Replace the assertion at lines 1605-1607 with:

```php
$dock_items_via_new = apply_filters( 'openstation_dock_items', array() );
ok( is_array( $dock_items_via_new ) && 1 === count( $dock_items_via_new ) && 'sn-dashboard' === ( $dock_items_via_new[0]['id'] ?? '' ) && 'S&N Dashboard' === ( $dock_items_via_new[0]['title'] ?? '' ) && 'dashicons-shield-alt' === ( $dock_items_via_new[0]['icon'] ?? '' ),
	'openstation_dock_items builds the single "sn-dashboard" dock entry -- its own id since v13.100.0, so it no longer shares "signal-noise" with the App Framework app' );
```

Run: `php tests/desktop-mode-integration.php | grep -E "FAIL|Result"` → expected one FAIL.

Also run `grep -rn "'signal-noise'" tests/desktop-mode*.php tests/dash-widgets.php` and move any other pin of the dock id the same way.

- [ ] **Step 2: Change the entry**

In `inc/desktop-mode-dock.php`, the item at lines 118-125 becomes:

```php
	// v13.100.0: its OWN id. From v12.4.0 to v13.99.2 this entry was `signal-noise`,
	// the id the App Framework app took in v13.98.0 (apps/signal-noise/
	// signal-noise.os.php, ->placement('dock')), so one id named two things on
	// the same dock. Re-keyed so each id names one thing; whether the shell's
	// registry dropped one of them was never measured. The entry is the admin
	// page -- S&N Dashboard -- and now says so, with the shield the desktop icon
	// `sn-icon-dashboard` already wears. The app keeps `signal-noise` and the megaphone.
	$items[] = array(
		'id'      => 'sn-dashboard',
		'title'   => 'S&N Dashboard',
		'icon'    => 'dashicons-shield-alt',
		'url'     => admin_url( 'admin.php?page=sn-theme-options' ),
		'badge'   => snt_desktop_dock_badge(),
		'submenu' => $dock_submenu,
	);
```

Also replace the file's older comment (lines 89-91 today: "Icon is dashicons-megaphone (matches the icon passed to add_menu_page() in admin-page.php:121, which is what was rendering on the auto-imported entry before suppression).") with: "Icon is dashicons-shield-alt (v13.100.0): the shield the desktop icon `sn-icon-dashboard` already wears (`snt_os_register_icon()` below). The megaphone now belongs to the App Framework app alone."

- [ ] **Step 3: Run the tests**

Run: `php tests/desktop-mode-integration.php | tail -1 && php tests/dash-widgets.php | tail -1`
Expected: both `... 0 failed.`

- [ ] **Step 4: Commit**

```bash
git add inc/desktop-mode-dock.php tests/desktop-mode-integration.php
git commit -m "fix(desktop): the admin's dock entry is sn-dashboard, S&N Dashboard, the shield — no longer sharing the app's id"
```

---

### Task 12: The sandbox pass — seed, HTTP stubs, desk and phone

The sandbox is `/private/tmp/claude-501/-Users-juanlentino-Projects-signal-and-noise-tools/74643de8-ea47-472c-a093-ad9c1c9dbd69/scratchpad/wprepro` (WP 7.1, SQLite, OpenStation 1.1.6 as `desktop-mode`, this plugin symlinked, the theme active), served by `.claude/launch.json` config `sandbox-wp` on :8099 (`preview_start`). Log in at `/sn-login` with the sandbox's throwaway admin account; the desktop is `/openstation/`; open the app with `wp.os.openWindow('signal-noise')`; drive it with `wp.os.apps.dispatch('signal-noise', 'go', { section: 'notes' })`. The browser pane's click coordinates are in the SCREENSHOT frame. Fifteen notes are seeded (published 4, 23-29; scheduled 30-32; drafts 33-34; pending 35; private 36) with chains; six citations exist.

**Files:**
- Create (scratchpad, never committed): `<scratchpad>/seed-dossier.php`, `<sandbox>/wp-content/mu-plugins/00-sn-sandbox-http.php`

- [ ] **Step 1: The HTTP stubs**

Write `<sandbox>/wp-content/mu-plugins/00-sn-sandbox-http.php` (the sandbox's `php -S` is single-worker, so every loopback fetch must be short-circuited or it blocks for the 5 s timeout):

```php
<?php
/* Plugin Name: S&N sandbox HTTP stubs — ledger, keys, twin loopback. Never ship. */
function snx_resp( $body, $code = 200 ) {
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Not Found' ), 'cookies' => array(), 'filename' => null );
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$base = '/juanlentino/signal-and-noise-provenance/main/';
	if ( 'raw.githubusercontent.com' === $host && 0 === strpos( $path, $base ) ) {
		$rel = substr( $path, strlen( $base ) );
		if ( 'keys/provenance-keys.json' === $rel ) {
			return snx_resp( array( 'schema' => 'sn-provenance-keys-v1', 'keys' => array( array( 'id' => sn_prov_key_id(), 'public_key_base64' => sn_prov_pubkey_b64() ), array( 'id' => 'sn-ed25519-2025-01', 'public_key_base64' => '' ) ) ) );
		}
		if ( preg_match( '#^(notes|pages)/([0-9a-f-]{36})/v(\d+)\.json$#', $rel, $m ) ) {
			$ids = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'meta_key' => '_sn_prov_uid', 'meta_value' => $m[2], 'fields' => 'ids', 'posts_per_page' => 1 ) );
			foreach ( $ids ? sn_prov_get_chain( (int) $ids[0] ) : array() as $c ) {
				if ( (int) ( $c['version'] ?? 0 ) === (int) $m[3] ) {
					return snx_resp( array( 'content_hash' => 'sha256:' . $c['content_hash'], 'pubkey_id' => (string) ( $c['pubkey_id'] ?? sn_prov_key_id() ), 'ots' => array( 'status' => 'confirmed', 'bitcoin_block' => 910000 + (int) $m[3], 'bitcoin_txid' => str_repeat( 'ab', 32 ), 'confirmations' => 6 ) ) );
				}
			}
			return snx_resp( '', 404 );
		}
	}
	if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && str_ends_with( $path, '.json' ) && 0 !== strpos( $path, '/.well-known/' ) ) {
		$post = get_page_by_path( trim( preg_replace( '#\.json$#', '', $path ), '/' ), OBJECT, array( 'post', 'page' ) );
		if ( $post ) {
			$c = sn_prov_get_chain( $post->ID ); $latest = end( $c );
			return snx_resp( array( 'content_text' => (string) ( $latest['payload']['content'] ?? '' ), 'note_uid' => (string) get_post_meta( $post->ID, '_sn_prov_uid', true ) ) );
		}
		return snx_resp( '', 404 );
	}
	return $pre;
}, 10, 3 );
```

- [ ] **Step 2: The seed**

Write `<scratchpad>/seed-dossier.php` and run it with `cd <sandbox> && wp eval-file <scratchpad>/seed-dossier.php`:

```php
<?php
global $wpdb;
$by_slug = function ( $slug ) { $p = get_page_by_path( $slug, OBJECT, 'post' ); return $p ? (int) $p->ID : 0; };
$a = $by_slug( 'the-signer-keeps-moving' );      // confirmed v2
$b = $by_slug( 'what-the-ledger-cannot-say' );   // confirmed v3
$c = $by_slug( 'a-readout-that-cannot-separate-two-states' ); // pending
// 1. pubkey_id + block facts on the confirmed commits.
foreach ( array( $a, $b ) as $id ) {
	$chain = sn_prov_get_chain( $id );
	foreach ( $chain as $i => $commit ) {
		if ( 'confirmed' === ( $commit['status'] ?? '' ) ) {
			$chain[ $i ]['pubkey_id'] = sn_prov_key_id();
			$chain[ $i ]['bitcoin_block'] = 910000 + (int) $commit['version'];
			$chain[ $i ]['block_time'] = '2026-08-20T10:00:00Z';
		}
	}
	update_post_meta( $id, '_sn_prov_chain', $chain );
}
// 2. Analytics daily rows: note A busy, note B quiet, both spellings for A.
$t = $wpdb->prefix . 'sn_analytics_daily';
$wpdb->query( "DELETE FROM {$t}" );
for ( $d = 0; $d < 90; $d++ ) {
	$day = gmdate( 'Y-m-d', time() - $d * DAY_IN_SECONDS );
	$wpdb->insert( $t, array( 'day' => $day, 'path' => '/the-signer-keeps-moving/', 'class' => 'human', 'views' => 3 + ( $d % 5 ), 'visits' => 2 + ( $d % 3 ), 'scroll_avg' => 0, 'time_avg' => 0 ) );
	if ( 0 === $d % 7 ) { $wpdb->insert( $t, array( 'day' => $day, 'path' => '/the-signer-keeps-moving', 'class' => 'human', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ) ); }
	if ( $d < 10 ) { $wpdb->insert( $t, array( 'day' => $day, 'path' => '/', 'class' => 'human', 'views' => 20, 'visits' => 12, 'scroll_avg' => 0, 'time_avg' => 0 ) ); }
}
// 3. Search Console store + coverage.
update_option( 'snt_gsc_data', array( 'property' => 'sc-domain:example.test', 'window' => array( 'start' => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ), 'pages' => array( '/the-signer-keeps-moving' => array( 'clicks' => 4, 'impressions' => 120, 'position' => 8.4, 'ctr' => 0.0333 ) ), 'queries' => array(), 'synced_at' => time() - 3600 ), false );
update_option( 'snt_gsc_coverage', array( 'property' => 'sc-domain:example.test', 'synced_at' => time() - 7200, 'started_at' => time() - 7300, 'complete' => true, 'inspected' => 2, 'errors' => 1, 'skipped' => 0, 'capped' => false, 'entries' => array(
	'/the-signer-keeps-moving' => array( 'verdict' => 'PASS', 'coverage_state' => 'Submitted and indexed', 'indexing_state' => '', 'robots_txt_state' => '', 'page_fetch_state' => '', 'crawled_as' => '', 'last_crawl_time' => gmdate( 'c', time() - 86400 ), 'google_canonical' => '', 'user_canonical' => '', 'canonical_match' => null, 'indexed' => true, 'inspected_at' => time() - 7200, 'post_id' => $a, 'url' => get_permalink( $a ) ),
	'/what-the-ledger-cannot-say' => array( 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'indexing_state' => '', 'robots_txt_state' => '', 'page_fetch_state' => '', 'crawled_as' => '', 'last_crawl_time' => '', 'google_canonical' => '', 'user_canonical' => '', 'canonical_match' => null, 'indexed' => false, 'inspected_at' => time() - 7200, 'post_id' => $b, 'url' => get_permalink( $b ) ),
	'/a-readout-that-cannot-separate-two-states' => array( 'error' => 'no_index_status', 'message' => 'quota exceeded', 'inspected_at' => time() - 7200, 'post_id' => $c, 'url' => get_permalink( $c ) ),
) ), false );
// 4. Probe log: A fresh, B stale + escalated.
update_option( 'sn_cf_purge_probe_log', array(
	array( 'time' => time() - 600, 'post_id' => $b, 'url' => get_permalink( $b ), 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
	array( 'time' => time() - 3600, 'post_id' => $a, 'url' => get_permalink( $a ), 'result' => 'fresh', 'algo' => 2 ),
), false );
update_option( 'sn_cf_api_token', 'sandbox' ); update_option( 'sn_cf_zone_id', 'sandbox' ); // whatever sn_cf_is_configured() reads: check with `grep -n "function sn_cf_get_token" -A6 inc/cloudflare-purge.php` and set THOSE options
// 5. A scheduled fragment on A.
$s = $wpdb->prefix . 'sn_schedules';
$wpdb->query( "DELETE FROM {$s}" );
$wpdb->insert( $s, array( 'schedule_id' => 'seed-1', 'target_type' => 'fragment', 'target_ref' => (string) $a, 'action' => 'reveal', 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ), 'ends_at' => null, 'status' => 'queued', 'purge_urls' => wp_json_encode( array( get_permalink( $a ) ) ), 'updated' => gmdate( 'Y-m-d H:i:s' ) ) );
// 6. Machine-reader snapshot, reading time, tags.
update_option( 'sn_mr_snapshot', array( 'captured_at' => time() - 1800, 'last_attempt_at' => time() - 1800, 'last_error' => null, 'days' => 30, 'total' => 72597, 'by_family' => array( 'openai' => 30000 ), 'by_surface' => array( 'html' => 60000 ), 'by_day' => array( gmdate( 'Y-m-d' ) => 2400 ) ), false );
update_post_meta( $a, '_sn_reading_time_minutes', 3 );
wp_set_post_tags( $a, array( 'provenance', 'signatures' ), false );
echo "seeded a=$a b=$b c=$c\n";
```

Then `wp transient delete --all` in the sandbox, and `preview_start` (or restart) the server.

- [ ] **Step 3: The REST route by hand**

In the pane, logged in, run through `javascript_tool`:

```js
const r = await fetch( wp.apiFetch.nonceEndpoint ? location.origin + '/wp-json/wp-abilities/v1/abilities/signal-noise/note-dossier/run?input[post_id]=4&input[days]=30' : '', { headers: { 'X-WP-Nonce': openStationConfig.restNonce } } ); const j = await r.json(); JSON.stringify( { status: r.status, ok: j.ok, groups: ( j.blocks || [] ).map( b => b.group + ':' + b.kind + ':' + b.heading ), code: j.code } )
```

Expected: `status: 200`, `ok: true`, groups beginning `trust:status:Anchor proof`, `trust:status:Signer`, `trust:table:Citations received`, `numbers:stats:Numbers`, `numbers:status:Machine reads`, `state:status:Edge`, … The reviewers read WP 7.1's `rest_validate_enum()`: it sanitises "30" to int 30 before comparing, so the string validates and no fallback should be needed. If the status is nevertheless 400 with a message about `days`: change the ability's `days` schema to `array( 'type' => array( 'integer', 'string' ), 'enum' => array( 7, 30, 90, '7', '30', '90' ), 'default' => 30 )`, update the enum pin in `tests/abilities-note-dossier.php` to match, re-run that test, note it in the CHANGELOG bullet, and re-run the route check. Also confirm `input[days]=14` answers 400 (the enum holds) and that a logged-out fetch answers 401/403.

- [ ] **Step 4: Desk pass**

Open the desktop, open the app, go to Notes, click "The signer keeps moving". Verify with `read_page`/`javascript_tool` on the window's document, then screenshot:
- the window switch shows 7d / 30d / 90d with 30d selected; the Views tile reads a number with the window "30 days"; switching to 90d refetches and the number grows;
- Anchor proof reads `v2 anchored in Bitcoin block 910,002, 6 confirmations.` in the success tone with "View the transaction";
- Signer reads "Signed by sn-ed25519-2026-07, the followed key.";
- Citations received lists the seeded rows with tier badges;
- Numbers: Impressions 120, Clicks 4 with the sync window; Machine reads "Not counted per note." with the site-wide 72,597 and its door;
- Edge: "Edge fresh · verified 1 hour ago", door to Cloudflare; Search index "Indexed"; Sitemap: "Sitemap membership not checked." if The SEO Framework is active in the sandbox (it is on the LIVE site), otherwise "In the sitemap" -- record which the sandbox has; Scheduled fragments one row `… → never · queued · hidden`;
- Editorial: 3 min, words, 2 tags; the excerpt; related notes present or absent (the kernel may not be built in the sandbox: absent is correct);
- clicking a door opens an OpenStation window titled with the door's label (not a browser tab);
- "Re-check now" dispatches; the Re-check section appears under the chain with a success badge and a timestamp; opening another note hides it; `go` to the root clears it.
Then open "What the ledger cannot say": Edge is a warning naming the forced zone purge; Search index "Not indexed" with Google's wording. Open a draft: no Numbers, no Operating state; Trust says no confirmed anchor; Editorial present.
Check the console for runtime warnings (`read_console_messages` with pattern `openstation`): none expected.

- [ ] **Step 5: Phone pass**

`resize_window` preset `mobile`, reload, open the app, open the same note: the dossier page scrolls; tiles wrap to two per row; the window switch and doors are tappable; the Re-check button is full width. Screenshot. Then `resize_window` preset `desktop`.

- [ ] **Step 6: Remove the sandbox artefacts from the record**

Nothing in this task is committed. Leave the mu-plugin in the sandbox (it is outside the repo).

---

### Task 13: CHANGELOG, docs, the sweep, the PR

**Files:**
- Modify: `CHANGELOG.md`, `docs/proposals/2026-09-05-signal-noise-app-deeper-note.md`, `docs/openstation-compat.md`

- [ ] **Step 1: CHANGELOG**

Under `## [Unreleased]`:

```markdown
### Added
- The Signal & Noise window's dossier is now everything the estate knows about
  one note, in the order trust, numbers, operating state, editorial. Trust reads
  the public ledger's record of the newest confirmed version (block, txid,
  confirmations, and whether it attests the same hash), names the signing key
  against the keys the ledger publishes, and lists the citations received;
  "Re-check now" walks the twin, the ledger record and the published key ids
  and says exactly that. Numbers: views and visits over a 7 / 30 / 90 window
  from the durable analytics table (both spellings of the path, a new read:
  `sn_analytics_path_window()`), impressions and clicks from the Search Console
  sync in ITS window, and machine reads said honestly -- the sensor keeps no
  document paths, so the line names the site-wide figure and nothing per note.
  Operating state: the last edge verdict for the URL from the probe log, coverage,
  sitemap membership, scheduled fragments. Editorial: tags, reading time (read,
  never computed on read), word count, the excerpt agents receive, related notes
  from the kernel. Every fetched block names the source it came from, and its
  window where it has one; a source that could not be read is a warning block
  naming it, never a zero; each fact that another app owns carries a door into
  that view, opened as a window: the S&N
  Dashboard or S&N Analytics view that owns it, as a window. Fetched when the
  dossier opens through one new ability, `signal-noise/note-dossier` (GET,
  `edit_post` on the note), cached per note and window for the session; the
  list payload is unchanged. Builders live in `inc/note-dossier*.php` and work
  without OpenStation. (#1058)

### Changed
- The admin page's dock entry is `sn-dashboard`, titled S&N Dashboard with the
  shield: since v13.98.0 it shared the id `signal-noise` with the App Framework
  app, so one id named two things on the same dock. Re-keyed so each id names
  one thing; the app keeps `signal-noise` and the megaphone; the entry keeps its
  badge and submenu. (#1058)
- The eleven failure sentences of `sn_prov_integrity_findings()` are one table,
  `sn_prov_integrity_failure_sentence()`, joined by a twelfth for
  `keys_unreachable`, so the sweep, the health check and the app's re-check say
  the same thing about the same leg. (#1058)
- `signal-noise/note-dossier` is the first ability gated on `edit_post` rather
  than `manage_options`: the dossier is the editor's own view of one note they
  may edit, one note at a time. Scope, not sensitivity, is what changed; the
  tier is recorded in `docs/ops/ability-permission-policy.md`. (#1058)
```

- [ ] **Step 2: The spec's amendments, and the permission tier**

Append to `docs/proposals/2026-09-05-signal-noise-app-deeper-note.md` a section `## Amendments (2026-09-05, from the code map)` listing the thirteen amendments at the top of this plan, verbatim.

Add a row to the Tier-B table in `docs/ops/ability-permission-policy.md` (the policy requires every new ability to be classified deliberately; the guard cross-checks tier A only, so this row is documentation the guard cannot enforce, which is exactly why it must be written):

```markdown
| `note-dossier` | `edit_post` on the note, deliberately below `manage_options`: the dossier is the editor's own view of one post they may edit. It exposes, for that post only, per-path views and visits, Search Console impressions and clicks, the edge-freshness verdict and the site-wide machine-read total: audience and operational data that stays at `manage_options` when asked site-wide (`get-analytics-summary`, `get-machine-readers-summary`). Scope, not sensitivity, is what changed. |
```

Run `php tests/ability-permission-policy.php | tail -1` afterwards: green.

- [ ] **Step 3: The compat doc**

Append to the v13.98.0 section of `docs/openstation-compat.md` (where the client view is described): "Phase one (13.100.0) adds `App::config()` (static values shipped once with the window config, read as `ctx.extra` in the client) and `ctx.fetch()` (the runtime's REST client: relative to the REST root, nonce and JSON Accept attached, the request attributed to the window). Both are documented in `docs/app-framework.md` at 1.1.6." Run `php tests/openstation-compat.php | tail -1` — green, since no new `openstation_*` PHP name is used.

- [ ] **Step 4: The full sweep and the lint**

Run: `bash tests/run.sh 2>&1 | tail -2 && vendor/bin/phpcs -q inc/note-dossier.php inc/note-dossier-trust.php inc/note-dossier-numbers.php inc/note-dossier-state.php inc/note-dossier-editorial.php inc/abilities-note-dossier.php inc/analytics-posts.php inc/provenance-integrity.php inc/desktop-mode-dock.php apps/signal-noise/signal-noise.os.php apps/signal-noise/parts/payload.php apps/signal-noise/parts/notes.php; echo "phpcs exit=$?" && php tests/admin-class-orphans.php | tail -1 && php tools/stub-parity.php | tail -1`
Expected: `-- swept N suites, M assertions passed, 0 failed, 1 skipped --`, `phpcs exit=0`, the orphan ratchet and stub-parity green.

- [ ] **Step 5: Commit and PR**

```bash
git add CHANGELOG.md docs/proposals/2026-09-05-signal-noise-app-deeper-note.md docs/openstation-compat.md docs/ops/ability-permission-policy.md
git commit -m "docs: CHANGELOG, the spec's amendments, the config/fetch seams in the compat doc, the note-dossier tier row"
git push -u origin feat/signal-noise-deeper-note
gh pr create --base main --title "feat: the deeper note — the Signal & Noise dossier reads the whole estate" --body "Fixes #1058. …"
```

The PR body: what shipped (the four blocks and the ability), the ten amendments, the verification (test counts, the sandbox pass with the REST route status), and the cut recommendation (MINOR: a new ability and new capability in the window).

---

## Self-review

**Spec coverage.** Trust: anchor proof (Task 4), signer (Task 4), citations (Task 4), re-check (Tasks 4, 9, 10). Numbers: views/visits (Tasks 1, 5), Search Console (Task 5), machine reads (Task 5, amended), the door (Task 5). Operating state: edge verdict, coverage, sitemap, schedule, doors (Task 6). Editorial: tags, reading time, words, excerpt, related (Task 7, amended). Ability + permission (Task 8). Block contract with `stats`/`status`, `source`, `window`, `door` (Tasks 3, 10). Window switch, cache, skeleton, per-block failure (Tasks 3, 10). Unpublished note gets trust and editorial only (Tasks 5, 6). Registry cleanup (Task 11, amended to a re-key). Tests per builder, for the ability, the client pins, the sandbox pass (Tasks 1-12).

**Type consistency.** Block shape everywhere: `{ group, kind, heading, ... , source?, window?, door: { label, url }? }`; tiles `{ label, value, window, note }`; verdict `{ post_id, tone, text, meta, checked_at }`; the composer's envelope `{ ok, post_id, days, is_public, blocks, fetched_at }` is what the ability returns and what the client reads (`body.blocks`). `sn_note_dossier_days()` is used by the composer and the ability. The fetcher seam is `callable( string $url ): array{ code, body }` throughout.

**Placeholders.** None: every step carries its code and its expected output. Core's enum validation accepts "30" as a string on GET (`rest_validate_enum()` sanitises before comparing); Task 12 Step 3 keeps the fallback written out in case the sandbox says otherwise.

**Review folded in (2026-09-05).** Four independent reviewers (API correctness, test validity, client contract, spec coverage) returned 34 findings; all are applied above. The blockers: the missing `MINUTE_IN_SECONDS` define and `wp_parse_url` stub, PHP's early binding of top-level stubs (now conditional), the `'public' =>` literal that trips the MCP opt-in grep (now `is_public`), the single `ctx.ui()` bag. The defects: the Notes-only gate on the fetch, the uid-less confirmed commit, the verdict's ledger sentence keyed on the leg's real precondition, `subject_kind_unresolved` as a gap, The SEO Framework as the live sitemap branch, the filtered reading pace, the re-check action gated on `edit_post`, the tier row in the permission policy, the honest wording about the dock id, and amendments 11 to 13.
