# Login Hardening Audit Log Implementation Plan (v3.8.3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a focused "Audit log" sub-tab under Security that captures 6 login-related events (1 per-event for `login_success`, 5 day-bucketed counters for the rest, plus `unique_ips_count` via ephemeral hashed-IP transient), with 90-day retention, full 4-surface dispatch (admin / REST / Abilities / desktop-mode ⌘K), and a small read-only LLA lockout summary.

**Architecture:** Two new files (`inc/audit-log.php` for impls + capture hooks + REST routes co-located + retention cron; `inc/audit-log-admin.php` for sub-tab renderer). Single autoloaded JSON-encoded `wp_options` blob `sn_audit_log_v1` for long-term storage; ephemeral 25h transient `sn_audit_today_ips` for the unique-IP hashed-fragment set that never persists. Polling fallback on LLA's `limit_login_lockouts` option (verified at design-time: LLA fires no lockout action hook). Adding the second sub-tab under Security automatically reveals the sub-tab nav row (per v3.8.1's hide-when-count=1 rule).

**Tech Stack:** PHP 8.0+, WordPress block theme + plugin admin UI, no test framework (manual smoke-test verification per gates G1-G16), no JavaScript framework (vanilla JS handlers for 2 desktop-mode ⌘K commands), tiny CSS addition for stat-cards + counter timeline table.

**Reference spec:** [`docs/superpowers/specs/2026-05-25-login-hardening-audit-log-design.md`](../specs/2026-05-25-login-hardening-audit-log-design.md) (commit `4f1d713`).

---

## File Structure

| File | Action | LOC est. | Responsibility |
|---|---|---|---|
| `inc/audit-log.php` | **Create** | ~300 | Pure-function impls + event capture hooks + REST routes (co-located) + retention cron + hashing helper |
| `inc/audit-log-admin.php` | **Create** | ~200 | Security → Audit log sub-tab renderer (stat-cards hero + tables + LLA summary + Prune-now button) |
| `assets/audit-log.css` | **Create** | ~50 | `.sn-audit-state-grid` + `.sn-audit-timeline` + `.sn-audit-logins` styling |
| `signal-and-noise-tools.php` | **Modify** | +2 lines + version bump | `require_once` the 2 new files; bump docblock `Version: 3.8.2` → `3.8.3` |
| `inc/login-hide.php` | **Modify** | +2 lines | At lines 144 + 152, increment audit counters before the existing `exit` |
| `inc/admin-page.php` | **Modify** | +5 lines | Add `'audit-log' => array( 'label' => 'Audit log' )` to Security `sub_tabs`; add `elseif ( 'audit-log' === $active_sub )` dispatch arm |
| `inc/abilities-registration.php` | **Modify** | +120 lines | Register 4 abilities (3 read + 1 write) + 4 execute callbacks |
| `inc/desktop-mode-integration.php` | **Modify** | +2 lines in `$commands` array | Register 2 ⌘K commands |
| `assets/desktop-mode.js` | **Modify** | +30 lines | Add `case 'sn-cmd-audit-summary':` + `case 'sn-cmd-audit-recent-logins':` JS handlers |
| `CHANGELOG.md` | **Modify** | +30 lines | New v3.8.3 entry at top |

**REST routes:** co-located in `audit-log.php` (NOT in `rest-api.php`). Reasoning: routes are a thin dispatch over the same impl functions in the same file; co-location keeps the audit-log unit cohesive. Avoids forcing edits across 2 files for any audit-log route change.

---

## Verification Approach

This is WordPress admin / capture-hook code with no automated test framework. Verification is **manual smoke testing** against the 16 gates from spec Section "Verification gates" (G1-G16). Each task includes verification steps relevant to that task. The full gate sweep is its own task at the end (Task 13).

---

## Commit Strategy

**4 commits + 1 tag** (one per wave; Wave 4 has no commit unless a fix is needed):

| Commit | After Task | Scope |
|---|---|---|
| 1 | Task 4 | Wave 1: impls + capture hooks + retention cron + entrypoint require + login-hide.php hooks |
| 2 | Task 7 | Wave 2: admin sub-tab UI + CSS + admin-page.php sub_tab registration + dispatch arm |
| 3 | Task 10 | Wave 3: REST routes + 4 Abilities + 2 desktop-mode ⌘K commands + JS handlers |
| 4 | Task 12 | Wave 5: CHANGELOG + version bump (docblock); tag + push happens after this commit |

Each commit leaves the codebase functional (event capture works after commit 1 even though no UI yet; UI works after commit 2; AI/REST/⌘K surfaces work after commit 3). Atomic rollback is simple at any commit.

---

# WAVE 1 — Data model, capture impls, hooks, retention cron

## Task 1: Create `inc/audit-log.php` skeleton with constants + helpers

**Files:**
- Create: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php`

### Step 1.1: Create the file with file docblock + constants + lazy-init + hash helper

Create `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php` with this content:

```php
<?php
/**
 * Signal & Noise Tools — Login hardening audit log.
 *
 * Captures 6 login-related events:
 *
 *   login_success         per-event row (timestamp + username; no IP)
 *   login_failed          day-bucketed counter
 *   wp_login_404          day-bucketed counter (from inc/login-hide.php)
 *   wp_admin_unauth_404   day-bucketed counter (from inc/login-hide.php)
 *   lockout_triggered     day-bucketed counter (polling fallback — LLA fires no hook)
 *   password_reset        day-bucketed counter
 *
 * Plus a per-day `unique_ips_count` computed via an ephemeral hashed-IP
 * transient set with 25h TTL. The set rolls forward at day-flip into the
 * long-term counter; the hashes themselves never persist beyond 25h.
 *
 * Storage:
 *   - Long-term: single autoloaded option `sn_audit_log_v1` (JSON-encoded,
 *     schema-versioned). Worst-case envelope ~100 KB after 90 days.
 *   - Ephemeral: transient `sn_audit_today_ips` (25h TTL), associative
 *     array of `{ hashed_ip_fragment => 1 }`.
 *
 * Retention: 90 days, enforced by daily cron `sn_audit_log_prune`.
 *
 * 4-surface dispatch (per project pattern; converges on snt_audit_*_impl()):
 *   - wp-admin form (Security → Audit log sub-tab, in inc/audit-log-admin.php)
 *   - REST routes signal-noise/v1/audit/* (co-located below)
 *   - Abilities (registered in inc/abilities-registration.php)
 *   - desktop-mode ⌘K commands (registered in inc/desktop-mode-integration.php)
 *
 * @package SignalNoiseTools
 * @since 3.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AUDIT_OPTION         = 'sn_audit_log_v1';
const SN_AUDIT_TRANSIENT_IPS  = 'sn_audit_today_ips';
const SN_AUDIT_IPS_TTL        = 25 * HOUR_IN_SECONDS;
const SN_AUDIT_RETENTION_DAYS = 90;
const SN_AUDIT_LOGIN_SUCCESS_CAP = 500;
const SN_AUDIT_PRUNE_HOOK     = 'sn_audit_log_prune';
const SN_AUDIT_LLA_LAST_COUNT_OPT = 'sn_audit_lla_last_lockout_count';

const SN_AUDIT_COUNTER_TYPES = array(
	'login_failed',
	'wp_login_404',
	'wp_admin_unauth_404',
	'lockout_triggered',
	'password_reset',
);

/**
 * Get the audit log blob, lazy-initializing if missing.
 *
 * @return array Always returns the valid schema-v1 shape.
 */
function snt_audit_get_blob() {
	$blob = get_option( SN_AUDIT_OPTION, null );
	if ( ! is_array( $blob ) || ! isset( $blob['schema_version'] ) ) {
		$blob = array(
			'schema_version' => 1,
			'created_at'     => time(),
			'counters'       => array(),
			'login_success'  => array(),
		);
	}
	return $blob;
}

/**
 * Hash an IP to a 16-char hex fragment, salted with wp_salt('auth').
 *
 * `wp_salt('auth')` rotates with the WP auth salt, so collision search is
 * bounded to a 16-char namespace per salt epoch. We never need cross-epoch
 * retention since the transient set lives only 25h.
 *
 * @param string $ip Raw IP address.
 * @return string 16-char hex hash.
 */
function snt_audit_hash_ip( $ip ) {
	$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
	return substr( hash( 'sha256', (string) $ip . $salt ), 0, 16 );
}

/**
 * Get today's date key (YYYY-MM-DD) in the site timezone.
 *
 * @return string
 */
function snt_audit_today_key() {
	return wp_date( 'Y-m-d' );
}
```

- [ ] **Step 1.1: Create the file with the content above.**

### Step 1.2: Verify file parses

Run:
```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php
```

Expected: `No syntax errors detected in /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php`

- [ ] **Step 1.2: Confirm `php -l` passes.**

---

## Task 2: Add pure-function impls to `inc/audit-log.php`

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php` (append after the helpers from Task 1)

### Step 2.1: Append the 7 pure-function impls

Append to the end of `inc/audit-log.php` (after the `snt_audit_today_key()` function):

```php

/* ════════════════════════════════════════════════════════════════════════
 * Pure-function impls (4-surface dispatch convergence).
 * Every dispatch surface (admin form, REST, Abilities, desktop-mode ⌘K)
 * calls these. No surface duplicates business logic.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Increment a per-day counter. Lazy-initializes the blob if missing.
 * Also updates the ephemeral unique-IPs transient set if $ip is provided.
 *
 * @param string      $event_type One of SN_AUDIT_COUNTER_TYPES.
 * @param string|null $ip         Raw IP (will be hashed). Optional.
 */
function snt_audit_increment_counter_impl( $event_type, $ip = null ) {
	if ( ! in_array( $event_type, SN_AUDIT_COUNTER_TYPES, true ) ) {
		return;
	}

	$blob   = snt_audit_get_blob();
	$today  = snt_audit_today_key();

	if ( ! isset( $blob['counters'][ $today ] ) ) {
		$blob['counters'][ $today ] = array_fill_keys( SN_AUDIT_COUNTER_TYPES, 0 );
		$blob['counters'][ $today ]['unique_ips_count'] = 0;
	}

	$blob['counters'][ $today ][ $event_type ] = (int) ( $blob['counters'][ $today ][ $event_type ] ?? 0 ) + 1;

	// Update unique-IPs transient set if we have an IP.
	if ( null !== $ip && '' !== $ip ) {
		$hash = snt_audit_hash_ip( $ip );
		$set  = get_transient( SN_AUDIT_TRANSIENT_IPS );
		if ( ! is_array( $set ) ) {
			$set = array();
		}
		if ( ! isset( $set[ $hash ] ) ) {
			$set[ $hash ] = 1;
			set_transient( SN_AUDIT_TRANSIENT_IPS, $set, SN_AUDIT_IPS_TTL );
			$blob['counters'][ $today ]['unique_ips_count'] = (int) ( $blob['counters'][ $today ]['unique_ips_count'] ?? 0 ) + 1;
		}
	}

	update_option( SN_AUDIT_OPTION, $blob, true );
}

/**
 * Record a successful login as a per-event row.
 * No IP stored — just timestamp + username.
 *
 * @param int    $user_id   The logged-in user's ID.
 * @param string $username  The login username (preserved as-stored, even if user later deleted).
 */
function snt_audit_record_login_success_impl( $user_id, $username ) {
	$blob = snt_audit_get_blob();
	$blob['login_success'][] = array(
		'ts'   => time(),
		'user' => (string) $username,
	);

	// Cap to SN_AUDIT_LOGIN_SUCCESS_CAP — drop oldest entries.
	if ( count( $blob['login_success'] ) > SN_AUDIT_LOGIN_SUCCESS_CAP ) {
		$blob['login_success'] = array_slice( $blob['login_success'], -SN_AUDIT_LOGIN_SUCCESS_CAP );
	}

	update_option( SN_AUDIT_OPTION, $blob, true );
}

/**
 * Return the last N days of counter data, newest-first.
 *
 * @param int $days How many trailing days. Default 30, max 90.
 * @return array<int,array> Each row: { date, login_failed, wp_login_404, wp_admin_unauth_404, lockout_triggered, password_reset, unique_ips_count }
 */
function snt_audit_get_counters_impl( $days = 30 ) {
	$days  = max( 1, min( SN_AUDIT_RETENTION_DAYS, (int) $days ) );
	$blob  = snt_audit_get_blob();
	$out   = array();
	$today = time();

	for ( $i = 0; $i < $days; $i++ ) {
		$date = wp_date( 'Y-m-d', $today - $i * DAY_IN_SECONDS );
		$bucket = $blob['counters'][ $date ] ?? array();
		$out[]  = array(
			'date'                => $date,
			'login_failed'        => (int) ( $bucket['login_failed']        ?? 0 ),
			'wp_login_404'        => (int) ( $bucket['wp_login_404']        ?? 0 ),
			'wp_admin_unauth_404' => (int) ( $bucket['wp_admin_unauth_404'] ?? 0 ),
			'lockout_triggered'   => (int) ( $bucket['lockout_triggered']   ?? 0 ),
			'password_reset'      => (int) ( $bucket['password_reset']      ?? 0 ),
			'unique_ips_count'    => (int) ( $bucket['unique_ips_count']    ?? 0 ),
		);
	}

	return $out;
}

/**
 * Return the last N days of successful-login rows, newest-first.
 *
 * @param int $days How many trailing days. Default 30, max 90.
 * @return array<int,array> Each row: { ts, user, formatted }
 */
function snt_audit_get_login_successes_impl( $days = 30 ) {
	$days   = max( 1, min( SN_AUDIT_RETENTION_DAYS, (int) $days ) );
	$blob   = snt_audit_get_blob();
	$cutoff = time() - $days * DAY_IN_SECONDS;
	$out    = array();

	foreach ( $blob['login_success'] as $row ) {
		if ( (int) $row['ts'] < $cutoff ) {
			continue;
		}
		$out[] = array(
			'ts'        => (int) $row['ts'],
			'user'      => (string) $row['user'],
			'formatted' => wp_date( 'Y-m-d H:i:s', (int) $row['ts'] ),
		);
	}

	// Newest first.
	usort( $out, function( $a, $b ) {
		return $b['ts'] <=> $a['ts'];
	} );

	return $out;
}

/**
 * Build the hero-card summary: last 24h totals, last 7d trend, unique attackers 24h, LLA status.
 *
 * @return array { last_24h: { failed_total, recon_total, all_total }, last_7d_vs_prior: { current, prior, pct_delta }, unique_attackers_24h: int, lla: array }
 */
function snt_audit_get_summary_impl() {
	$counters_7  = snt_audit_get_counters_impl( 7 );  // includes today
	$counters_14 = snt_audit_get_counters_impl( 14 ); // for prior-7 comparison

	// Today's row (index 0).
	$today_row = $counters_7[0] ?? array();
	$last_24h = array(
		'failed_total' => (int) ( $today_row['login_failed'] ?? 0 ),
		'recon_total'  => (int) ( $today_row['wp_login_404'] ?? 0 ) + (int) ( $today_row['wp_admin_unauth_404'] ?? 0 ),
		'all_total'    => 0,
	);
	foreach ( SN_AUDIT_COUNTER_TYPES as $type ) {
		$last_24h['all_total'] += (int) ( $today_row[ $type ] ?? 0 );
	}

	// 7-day vs prior-7-day trend. Sum all counter types across each window.
	$current_sum = 0;
	$prior_sum   = 0;
	for ( $i = 0; $i < 7; $i++ ) {
		foreach ( SN_AUDIT_COUNTER_TYPES as $type ) {
			$current_sum += (int) ( $counters_14[ $i ][ $type ] ?? 0 );
			$prior_sum   += (int) ( $counters_14[ $i + 7 ][ $type ] ?? 0 );
		}
	}
	$pct_delta = 0;
	if ( $prior_sum > 0 ) {
		$pct_delta = (int) round( ( ( $current_sum - $prior_sum ) / $prior_sum ) * 100 );
	} elseif ( $current_sum > 0 ) {
		$pct_delta = 100; // From-zero growth.
	}

	return array(
		'last_24h'             => $last_24h,
		'last_7d_vs_prior'     => array(
			'current'   => $current_sum,
			'prior'     => $prior_sum,
			'pct_delta' => $pct_delta,
		),
		'unique_attackers_24h' => (int) ( $today_row['unique_ips_count'] ?? 0 ),
		'lla'                  => snt_audit_read_lla_summary_impl(),
	);
}

/**
 * Drop counter buckets and login_success rows older than SN_AUDIT_RETENTION_DAYS.
 * Returns prune stats.
 *
 * Also: implements the LLA polling fallback for lockout_triggered counter
 * (LLA fires no action hook — verified 2026-05-25). Reads current
 * `limit_login_lockouts` size; if greater than last-known, adds the delta
 * to today's lockout_triggered counter.
 *
 * @return array { counter_buckets_dropped: int, login_rows_dropped: int, lla_delta: int }
 */
function snt_audit_prune_impl() {
	$blob   = snt_audit_get_blob();
	$cutoff = strtotime( '-' . SN_AUDIT_RETENTION_DAYS . ' days' );
	$cutoff_date = wp_date( 'Y-m-d', $cutoff );

	// Prune counter buckets.
	$dropped_buckets = 0;
	foreach ( array_keys( $blob['counters'] ) as $date_key ) {
		if ( $date_key < $cutoff_date ) {
			unset( $blob['counters'][ $date_key ] );
			$dropped_buckets++;
		}
	}

	// Prune login_success rows.
	$before = count( $blob['login_success'] );
	$blob['login_success'] = array_values( array_filter( $blob['login_success'], function( $row ) use ( $cutoff ) {
		return (int) $row['ts'] >= $cutoff;
	} ) );
	$dropped_rows = $before - count( $blob['login_success'] );

	// LLA polling fallback for lockout_triggered.
	$lla_delta   = 0;
	$lla_current = is_array( get_option( 'limit_login_lockouts', array() ) )
		? count( get_option( 'limit_login_lockouts', array() ) )
		: 0;
	$lla_last = (int) get_option( SN_AUDIT_LLA_LAST_COUNT_OPT, 0 );
	if ( $lla_current > $lla_last ) {
		$lla_delta = $lla_current - $lla_last;
		$today     = snt_audit_today_key();
		if ( ! isset( $blob['counters'][ $today ] ) ) {
			$blob['counters'][ $today ] = array_fill_keys( SN_AUDIT_COUNTER_TYPES, 0 );
			$blob['counters'][ $today ]['unique_ips_count'] = 0;
		}
		$blob['counters'][ $today ]['lockout_triggered'] = (int) ( $blob['counters'][ $today ]['lockout_triggered'] ?? 0 ) + $lla_delta;
	}
	update_option( SN_AUDIT_LLA_LAST_COUNT_OPT, $lla_current, false );

	update_option( SN_AUDIT_OPTION, $blob, true );

	return array(
		'counter_buckets_dropped' => $dropped_buckets,
		'login_rows_dropped'      => $dropped_rows,
		'lla_delta'               => $lla_delta,
	);
}

/**
 * Safe read of LLA's `limit_login_lockouts` option.
 * Returns count + most-recent timestamp. Tolerates schema drift / missing option.
 *
 * @return array { active_lockouts: int, most_recent_lockout_ts: int|null }
 */
function snt_audit_read_lla_summary_impl() {
	$lockouts = get_option( 'limit_login_lockouts', array() );
	if ( ! is_array( $lockouts ) ) {
		return array( 'active_lockouts' => 0, 'most_recent_lockout_ts' => null );
	}

	$count       = count( $lockouts );
	$most_recent = null;
	foreach ( $lockouts as $ts ) {
		if ( is_numeric( $ts ) && (int) $ts > (int) $most_recent ) {
			$most_recent = (int) $ts;
		}
	}

	return array(
		'active_lockouts'         => $count,
		'most_recent_lockout_ts'  => $most_recent,
	);
}
```

- [ ] **Step 2.1: Append the 7 impls above to `inc/audit-log.php`.**

### Step 2.2: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 2.2: Confirm `php -l` passes.**

---

## Task 3: Add capture hook callbacks + register hooks + register cron

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php` (append at end)

### Step 3.1: Append the capture hook callbacks + add_action registrations

Append to the end of `inc/audit-log.php`:

```php

/* ════════════════════════════════════════════════════════════════════════
 * Event capture hook callbacks.
 *
 * NOTE: lockout_triggered uses a polling fallback (in snt_audit_prune_impl)
 * because LLA fires no action hook. Verified 2026-05-25 — only LLA
 * do_action calls in core are `llar_plugin_version_updated` and
 * `llar_mfa_generate_codes`. Polling kicks in via the daily prune tick.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * wp_login action callback. Records the per-event row.
 *
 * @param string  $user_login Username that logged in.
 * @param WP_User $user       User object.
 */
function snt_audit_capture_login_success_cb( $user_login, $user ) {
	$user_id = $user instanceof WP_User ? (int) $user->ID : 0;
	snt_audit_record_login_success_impl( $user_id, $user_login );
}
add_action( 'wp_login', 'snt_audit_capture_login_success_cb', 10, 2 );

/**
 * wp_login_failed action callback. Increments the daily counter + updates unique-IPs.
 *
 * @param string $username The username that failed auth (unused for now; future PII consideration).
 */
function snt_audit_capture_login_failed_cb( $username ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	snt_audit_increment_counter_impl( 'login_failed', $ip );
}
add_action( 'wp_login_failed', 'snt_audit_capture_login_failed_cb', 10, 1 );

/**
 * after_password_reset action callback. Increments the daily counter.
 * No IP capture — password_reset is post-auth, IP context not meaningful.
 *
 * @param WP_User $user The user whose password was reset (unused; future use possible).
 */
function snt_audit_capture_password_reset_cb( $user ) {
	snt_audit_increment_counter_impl( 'password_reset' );
}
add_action( 'after_password_reset', 'snt_audit_capture_password_reset_cb', 10, 1 );

/* ════════════════════════════════════════════════════════════════════════
 * Retention cron registration.
 *
 * Daily cron `sn_audit_log_prune` fires snt_audit_prune_impl(). Scheduled
 * via init hook (idempotent: check wp_next_scheduled before scheduling).
 * Cron-dashboard module (inc/cron-dashboard.php) will automatically pick
 * this up and display it in the SN admin Cron tab.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'init', function() {
	if ( ! wp_next_scheduled( SN_AUDIT_PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_AUDIT_PRUNE_HOOK );
	}
} );

add_action( SN_AUDIT_PRUNE_HOOK, 'snt_audit_prune_impl' );
```

- [ ] **Step 3.1: Append the capture callbacks + add_action calls + cron registration.**

### Step 3.2: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3.2: Confirm `php -l` passes.**

---

## Task 4: Wire entrypoint + login-hide.php capture points + Wave 1 commit

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php` (add 1 `require_once` line)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/login-hide.php:144-148, 152-157` (add 2 lines)

### Step 4.1: Add `require_once` for audit-log.php in plugin entrypoint

Find this section in `signal-and-noise-tools.php` (around lines 64-74):

```php
// Module includes.
require_once SNT_PATH . 'inc/settings.php';
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/plausible-api.php';
require_once SNT_PATH . 'inc/plausible-admin.php';
require_once SNT_PATH . 'inc/plausible-widget.php';
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
require_once SNT_PATH . 'inc/rest-api.php';
```

(There may be more `require_once` lines after this — this is just the start. Find the LAST `require_once SNT_PATH . 'inc/...'` line of the section.)

Add this line AFTER the last `require_once` of the existing section:

```php
require_once SNT_PATH . 'inc/audit-log.php';
```

- [ ] **Step 4.1: Add the `require_once` line for `audit-log.php`.**

### Step 4.2: Add capture call at line 144 of login-hide.php

Find lines 144-148 of `inc/login-hide.php`:

```php
		// 404 direct visits to /wp-login.php.
		if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
```

Replace with:

```php
		// 404 direct visits to /wp-login.php.
		if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
			if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
				snt_audit_increment_counter_impl( 'wp_login_404', $_SERVER['REMOTE_ADDR'] ?? null );
			}
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
```

- [ ] **Step 4.2: Add the `wp_login_404` capture before the 404 block at line 144.**

### Step 4.3: Add capture call at line 152 of login-hide.php

Find lines 152-157 of `inc/login-hide.php`:

```php
		// 404 unauthenticated visits to /wp-admin.
		if ( strpos( $request_uri, '/wp-admin' ) === 0 && ! is_user_logged_in() ) {
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
```

Replace with:

```php
		// 404 unauthenticated visits to /wp-admin.
		if ( strpos( $request_uri, '/wp-admin' ) === 0 && ! is_user_logged_in() ) {
			if ( function_exists( 'snt_audit_increment_counter_impl' ) ) {
				snt_audit_increment_counter_impl( 'wp_admin_unauth_404', $_SERVER['REMOTE_ADDR'] ?? null );
			}
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
```

- [ ] **Step 4.3: Add the `wp_admin_unauth_404` capture before the 404 block at line 152.**

### Step 4.4: Verify both files parse

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/login-hide.php
```

Both should print `No syntax errors detected`.

- [ ] **Step 4.4: Confirm both `php -l` pass.**

### Step 4.5: Server-side smoke test — option lazy-init + capture works

SSH to production and verify the impl is callable and lazy-initializes the option:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; require_once WP_PLUGIN_DIR . \"/signal-and-noise-tools/inc/audit-log.php\"; snt_audit_increment_counter_impl(\"login_failed\", \"127.0.0.1\"); print_r( get_option(\"sn_audit_log_v1\") );"'
```

Expected: prints an array with `schema_version => 1`, `counters` containing today's date as a key with `login_failed => 1`, `unique_ips_count => 1`.

**Note:** Wave 1 hasn't been deployed yet — this requires the audit-log.php file on the server. If you haven't deployed via WP UI / SSH, the smoke test will fail with "file not found." Either deploy first (commit + push then `gh workflow run` if doing manual SSH) OR skip this step and rely on Step 4.6 (file parse verification was enough; runtime test happens after deploy in Wave 4).

- [ ] **Step 4.5: Optional — server-side smoke if deployed. Skip if not yet deployed.**

### Step 4.6: Commit Wave 1

Commit the 3 changes in one atomic commit:

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git add inc/audit-log.php signal-and-noise-tools.php inc/login-hide.php
git commit -m "$(cat <<'EOF'
wave 1: audit log impls + capture hooks + retention cron (v3.8.3 in flight)

New module inc/audit-log.php with:
- Pure-function impls (increment_counter / record_login_success / get_counters /
  get_login_successes / get_summary / prune / read_lla_summary)
- Hashing helper (16-char sha256 + wp_salt('auth'))
- Capture hooks for wp_login, wp_login_failed, after_password_reset
- Polling fallback for lockout_triggered (LLA fires no hook; verified 2026-05-25)
- Daily retention cron sn_audit_log_prune (90-day window)
- Single autoloaded option sn_audit_log_v1 + ephemeral transient
  sn_audit_today_ips (25h TTL) for hashed-IP unique-count

Wired into entrypoint via require_once. login-hide.php now increments
wp_login_404 + wp_admin_unauth_404 counters before its existing 404 exits.

Wave 1 of 4 for v3.8.3 (login hardening audit log). No UI yet — admin
sub-tab + REST + abilities + ⌘K land in Waves 2-3.

Spec: docs/superpowers/specs/2026-05-25-login-hardening-audit-log-design.md
Plan: docs/superpowers/plans/2026-05-25-login-hardening-audit-log-v3.8.3.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 4.6: Commit Wave 1.**

---

# WAVE 2 — Admin sub-tab UI + CSS

## Task 5: Create `inc/audit-log-admin.php`

**Files:**
- Create: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log-admin.php`

### Step 5.1: Create the file with the full renderer

Create `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log-admin.php` with this content:

```php
<?php
/**
 * Signal & Noise Tools — Audit Log admin sub-tab renderer.
 *
 * Renders the Security → Audit log sub-tab. Layout matches the Dashboard
 * tab pattern (4-card hero grid + tables below).
 *
 * Wired by inc/admin-page.php's security tab dispatch arm:
 *     elseif ( 'audit-log' === $active_sub ) {
 *         sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
 *     }
 *
 * @package SignalNoiseTools
 * @since 3.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main render entrypoint for the Audit log sub-tab.
 */
function snt_audit_log_render_tab() {
	// Handle "Prune now" POST first so the redirect happens before output.
	if ( isset( $_POST['sn_action'] ) && 'audit_prune_now' === $_POST['sn_action'] ) {
		check_admin_referer( 'sn_theme_options_nonce' );
		if ( current_user_can( 'manage_options' ) ) {
			$stats = snt_audit_prune_impl();
			echo '<div class="notice notice-success is-dismissible"><p><strong>Prune complete.</strong> ' .
				esc_html( sprintf(
					'%d counter bucket(s) dropped, %d login row(s) dropped, LLA delta +%d.',
					$stats['counter_buckets_dropped'],
					$stats['login_rows_dropped'],
					$stats['lla_delta']
				) ) .
				'</p></div>';
		}
	}

	$summary  = snt_audit_get_summary_impl();
	$counters = snt_audit_get_counters_impl( 30 );
	$logins   = snt_audit_get_login_successes_impl( 30 );

	echo '<p class="sn-prose">Captures login-related events (successful logins, failed attempts, our /wp-login.php and unauth /wp-admin reconnaissance 404s, password resets, LLA lockouts). 90-day retention. Hashed-IP unique-attacker count via ephemeral transient — no raw or hashed IPs are stored long-term.</p>';

	// 1. Hero stat-cards.
	snt_audit_log_render_hero( $summary );

	// 2. Counter timeline table.
	snt_audit_log_render_counter_table( $counters );

	// 3. Recent successful logins.
	snt_audit_log_render_logins_table( $logins );

	// 4. LLA summary card (deep-link to LLA settings).
	snt_audit_log_render_lla_card( $summary['lla'] );

	// 5. Maintenance — Prune now button.
	snt_audit_log_render_prune_form();
}

/**
 * Render the 4-card hero grid.
 */
function snt_audit_log_render_hero( $summary ) {
	$delta_class = '';
	$delta_sign  = '';
	if ( $summary['last_7d_vs_prior']['pct_delta'] > 0 ) {
		$delta_class = 'sn-trend-up';
		$delta_sign  = '+';
	} elseif ( $summary['last_7d_vs_prior']['pct_delta'] < 0 ) {
		$delta_class = 'sn-trend-down';
	}

	echo '<div class="sn-audit-state-grid">';

	// Card 1: Last 24h.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Last 24h</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['last_24h']['all_total'] . '</span>';
	echo '<span class="sn-audit-card-sub">' . (int) $summary['last_24h']['failed_total'] . ' failed · ' . (int) $summary['last_24h']['recon_total'] . ' recon</span>';
	echo '</div>';

	// Card 2: 7d vs prior 7d.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Last 7d trend</span>';
	echo '<span class="sn-audit-card-value ' . esc_attr( $delta_class ) . '">' . esc_html( $delta_sign . $summary['last_7d_vs_prior']['pct_delta'] . '%' ) . '</span>';
	echo '<span class="sn-audit-card-sub">' . (int) $summary['last_7d_vs_prior']['current'] . ' vs ' . (int) $summary['last_7d_vs_prior']['prior'] . '</span>';
	echo '</div>';

	// Card 3: Unique attackers 24h.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">Unique IPs (24h)</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['unique_attackers_24h'] . '</span>';
	echo '<span class="sn-audit-card-sub">hashed, not stored</span>';
	echo '</div>';

	// Card 4: LLA status.
	echo '<div class="sn-audit-card">';
	echo '<span class="sn-audit-card-label">LLA status</span>';
	echo '<span class="sn-audit-card-value">' . (int) $summary['lla']['active_lockouts'] . '</span>';
	echo '<span class="sn-audit-card-sub">active lockouts</span>';
	echo '</div>';

	echo '</div>';
}

/**
 * Render the day-bucketed counter timeline table.
 */
function snt_audit_log_render_counter_table( $counters ) {
	echo '<h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
	echo '<table class="widefat sn-audit-timeline">';
	echo '<thead><tr>';
	echo '<th>Date</th>';
	echo '<th>Failed</th>';
	echo '<th>Login 404</th>';
	echo '<th>Admin 404</th>';
	echo '<th>Lockouts</th>';
	echo '<th>Pwd reset</th>';
	echo '<th>Unique IPs</th>';
	echo '</tr></thead>';
	echo '<tbody>';
	foreach ( $counters as $row ) {
		$row_total = (int) $row['login_failed'] + (int) $row['wp_login_404'] + (int) $row['wp_admin_unauth_404'] + (int) $row['lockout_triggered'] + (int) $row['password_reset'];
		$row_class = $row_total > 0 ? '' : ' sn-audit-row-empty';
		echo '<tr class="' . esc_attr( trim( $row_class ) ) . '">';
		echo '<td>' . esc_html( $row['date'] ) . '</td>';
		echo '<td>' . (int) $row['login_failed'] . '</td>';
		echo '<td>' . (int) $row['wp_login_404'] . '</td>';
		echo '<td>' . (int) $row['wp_admin_unauth_404'] . '</td>';
		echo '<td>' . (int) $row['lockout_triggered'] . '</td>';
		echo '<td>' . (int) $row['password_reset'] . '</td>';
		echo '<td>' . (int) $row['unique_ips_count'] . '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
}

/**
 * Render the recent successful logins table.
 */
function snt_audit_log_render_logins_table( $logins ) {
	echo '<h2 class="sn-fieldset-h">Recent successful logins (last 30 days)</h2>';
	if ( empty( $logins ) ) {
		echo '<p class="sn-prose">No successful logins recorded in this window.</p>';
		return;
	}
	echo '<table class="widefat sn-audit-logins">';
	echo '<thead><tr><th>Timestamp</th><th>User</th></tr></thead>';
	echo '<tbody>';
	foreach ( $logins as $row ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( $row['formatted'] ) . '</code></td>';
		echo '<td>' . esc_html( $row['user'] ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';
}

/**
 * Render the small LLA summary card with a deep-link to LLA settings.
 */
function snt_audit_log_render_lla_card( $lla ) {
	$recent = $lla['most_recent_lockout_ts']
		? wp_date( 'Y-m-d H:i:s', (int) $lla['most_recent_lockout_ts'] )
		: 'never';
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">limit-login-attempts-reloaded</p>';
	echo '<p>Active lockouts: <strong>' . (int) $lla['active_lockouts'] . '</strong>. Most recent lockout: <code>' . esc_html( $recent ) . '</code>.</p>';
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=limit-login-attempts' ) ) . '" class="button button-secondary">Manage in LLA →</a></p>';
	echo '</div>';
}

/**
 * Render the "Prune now" form.
 */
function snt_audit_log_render_prune_form() {
	echo '<h2 class="sn-fieldset-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="audit_prune_now">';
	echo '<p class="sn-prose">Manually run the daily prune now. Drops counter buckets and login_success rows older than ' . (int) SN_AUDIT_RETENTION_DAYS . ' days, plus polls LLA for new lockouts.</p>';
	echo '<p><button type="submit" class="button">Prune now</button></p>';
	echo '</form>';
}
```

- [ ] **Step 5.1: Create the file with the content above.**

### Step 5.2: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log-admin.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5.2: Confirm `php -l` passes.**

---

## Task 6: Create `assets/audit-log.css`

**Files:**
- Create: `/Users/juanlentino/projects/signal-and-noise-tools/assets/audit-log.css`

### Step 6.1: Create the CSS file

Create `/Users/juanlentino/projects/signal-and-noise-tools/assets/audit-log.css`:

```css
/* Signal & Noise Tools — Audit log sub-tab styles. */

.sn-audit-state-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 12px;
	margin: 16px 0 24px;
}

.sn-audit-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sn-audit-card-label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: #50575e;
}

.sn-audit-card-value {
	font-size: 28px;
	font-weight: 600;
	color: #1d2327;
	line-height: 1.1;
}

.sn-audit-card-value.sn-trend-up   { color: #d63638; }
.sn-audit-card-value.sn-trend-down { color: #00a32a; }

.sn-audit-card-sub {
	font-size: 12px;
	color: #646970;
}

.sn-audit-timeline,
.sn-audit-logins {
	margin: 8px 0 24px;
}

.sn-audit-timeline td,
.sn-audit-timeline th,
.sn-audit-logins td,
.sn-audit-logins th {
	font-variant-numeric: tabular-nums;
}

.sn-audit-row-empty {
	color: #a7aaad;
}

@media (max-width: 960px) {
	.sn-audit-state-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}
```

- [ ] **Step 6.1: Create the CSS file with the content above.**

### Step 6.2: Enqueue the CSS from `audit-log-admin.php`

The CSS needs to be enqueued only on the SN admin page + only when the audit-log sub-tab is active. Append this hook to the end of `inc/audit-log-admin.php`:

```php

/**
 * Enqueue audit-log.css on the SN admin page when the audit-log sub-tab is active.
 */
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// Only on the SN admin page.
	if ( 'toplevel_page_sn-theme-options' !== $hook_suffix ) {
		return;
	}
	// Only when audit-log sub-tab is active.
	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	$sub = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : '';
	if ( 'security' !== $tab || 'audit-log' !== $sub ) {
		return;
	}
	wp_enqueue_style(
		'snt-audit-log',
		plugins_url( 'assets/audit-log.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
} );
```

- [ ] **Step 6.2: Append the enqueue hook to `audit-log-admin.php`.**

### Step 6.3: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log-admin.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6.3: Confirm `php -l` passes.**

---

## Task 7: Wire admin-page.php + Wave 2 commit

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php` (add sub_tab entry around line 124-128; add dispatch arm around line 1147-1239)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php` (add `require_once` for audit-log-admin.php)

### Step 7.1: Add audit-log sub_tab entry under Security

Find this block in `inc/admin-page.php` (around lines 124-128):

```php
			'sub_tabs' => array(
				// Only 1 sub-tab at v3.8.1 → sn_admin_render_sub_tabs() hides the nav.
				// Future v3.8.x adds 'audit-log' which reveals the sub-tab nav automatically.
				'login' => array( 'label' => 'Login URL' ),
			),
```

Replace with:

```php
			'sub_tabs' => array(
				'login'     => array( 'label' => 'Login URL' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				'audit-log' => array( 'label' => 'Audit log' ),
			),
```

- [ ] **Step 7.1: Add the `audit-log` entry to Security's `sub_tabs`.**

### Step 7.2: Add audit-log dispatch arm under Security tab

Find this block in `inc/admin-page.php` (around line 1147 + ending around line 1239):

```php
		// Only 1 sub-tab at v3.8.1 — but gate on $active_sub for forward-compat
		// (future audit-log addition gates on $active_sub === 'audit-log').
		if ( 'login' === $active_sub ) {
		sn_admin_render_section( 'login', function() {
			// ... 90 lines of login UI ...
		} );
		}  // close: if ( 'login' === $active_sub )
```

Replace ONLY the comment + the `if ( 'login' === ... )` opening line + the closing `}` line. Keep the entire login UI body intact. The new structure:

```php
		// v3.8.3+: 2 sub-tabs (Login URL + Audit log) — sub-tab nav now visible.
		if ( 'audit-log' === $active_sub ) {
			sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
		} elseif ( 'login' === $active_sub || '' === $active_sub ) {
		sn_admin_render_section( 'login', function() {
			// ... 90 lines of login UI unchanged ...
		} );
		}  // close: elseif login (default)
```

**Exact replacement instructions:**

1. Find the line `// Only 1 sub-tab at v3.8.1 — but gate on $active_sub for forward-compat`
2. Find the next line `// (future audit-log addition gates on $active_sub === 'audit-log').`
3. Find the next line `if ( 'login' === $active_sub ) {`
4. Replace those 3 lines with:

```php
		// v3.8.3+: 2 sub-tabs (Login URL + Audit log) — sub-tab nav now visible.
		if ( 'audit-log' === $active_sub ) {
			sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
		} elseif ( 'login' === $active_sub || '' === $active_sub ) {
```

5. Find the closing `}  // close: if ( 'login' === $active_sub )` (around line 1239)
6. Replace with:

```php
		}  // close: elseif login (default)
```

This preserves the entire login UI body unchanged but routes the audit-log sub-tab to its own renderer.

- [ ] **Step 7.2: Add the audit-log dispatch arm; preserve the login UI body verbatim.**

### Step 7.3: Add require_once for audit-log-admin.php in entrypoint

Find the require_once line you added in Task 4.1:

```php
require_once SNT_PATH . 'inc/audit-log.php';
```

Add immediately after it:

```php
require_once SNT_PATH . 'inc/audit-log-admin.php';
```

- [ ] **Step 7.3: Add the `require_once` for `audit-log-admin.php`.**

### Step 7.4: Verify both files parse

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
php -l /Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php
```

Both should print `No syntax errors detected`.

- [ ] **Step 7.4: Confirm both `php -l` pass.**

### Step 7.5: Commit Wave 2

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git add inc/audit-log-admin.php assets/audit-log.css inc/admin-page.php signal-and-noise-tools.php
git commit -m "$(cat <<'EOF'
wave 2: audit log admin sub-tab UI + CSS (v3.8.3 in flight)

New module inc/audit-log-admin.php renders the Security → Audit log
sub-tab: 4-card stat hero (last 24h / 7d trend / unique IPs / LLA
status) + 30-day counter timeline table + recent-logins table + LLA
summary card with deep-link + Prune-now form. Layout matches the
Dashboard tab pattern.

inc/admin-page.php gets 5 lines: 'audit-log' added to Security's
sub_tabs (2nd entry — reveals the sub-tab nav row); audit-log
dispatch arm routes to snt_audit_log_render_tab().

assets/audit-log.css: stat-cards grid + counter timeline + responsive
breakpoint. Enqueued only on the audit-log sub-tab.

Wave 2 of 4 for v3.8.3. REST + abilities + ⌘K land in Wave 3.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7.5: Commit Wave 2.**

---

# WAVE 3 — REST routes + Abilities + desktop-mode ⌘K

## Task 8: Add 4 REST routes co-located in `inc/audit-log.php`

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php` (append at end)

### Step 8.1: Append REST route registration block

Append to the end of `inc/audit-log.php` (after the cron registration from Task 3.1):

```php

/* ════════════════════════════════════════════════════════════════════════
 * REST routes — co-located here (not in inc/rest-api.php) for cohesion.
 * All require manage_options. Surface 2 of 4 in the 4-surface dispatch.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	$manage_options_cap = function() {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'signal-noise/v1', '/audit/summary', array(
		'methods'             => 'GET',
		'callback'            => function() {
			return new WP_REST_Response( snt_audit_get_summary_impl(), 200 );
		},
		'permission_callback' => $manage_options_cap,
	) );

	register_rest_route( 'signal-noise/v1', '/audit/counters', array(
		'methods'             => 'GET',
		'callback'            => function( $request ) {
			$days = (int) ( $request->get_param( 'days' ) ?: 30 );
			return new WP_REST_Response( snt_audit_get_counters_impl( $days ), 200 );
		},
		'permission_callback' => $manage_options_cap,
		'args'                => array(
			'days' => array(
				'required'          => false,
				'default'           => 30,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/audit/login-successes', array(
		'methods'             => 'GET',
		'callback'            => function( $request ) {
			$days = (int) ( $request->get_param( 'days' ) ?: 30 );
			return new WP_REST_Response( snt_audit_get_login_successes_impl( $days ), 200 );
		},
		'permission_callback' => $manage_options_cap,
		'args'                => array(
			'days' => array(
				'required'          => false,
				'default'           => 30,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	register_rest_route( 'signal-noise/v1', '/audit/prune', array(
		'methods'             => 'POST',
		'callback'            => function() {
			return new WP_REST_Response( snt_audit_prune_impl(), 200 );
		},
		'permission_callback' => $manage_options_cap,
	) );
} );
```

- [ ] **Step 8.1: Append the REST route registration block.**

### Step 8.2: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 8.2: Confirm `php -l` passes.**

---

## Task 9: Register 4 Abilities in `inc/abilities-registration.php`

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/abilities-registration.php` (add 4 `wp_register_ability` calls + 4 execute callbacks)

### Step 9.1: Add 4 ability registrations inside the `wp_abilities_api_init` action

Find the closing `} );` of the `add_action( 'wp_abilities_api_init', function() { ... } );` block in `inc/abilities-registration.php` (it's around line 808 — preceded by the last `wp_register_ability(...)` call which itself ends with `) );`).

Add these 4 ability registrations IMMEDIATELY BEFORE that closing `} );`:

```php

	// v3.8.3: 4 audit log abilities (3 read + 1 maintenance).
	wp_register_ability( 'signal-noise/get-audit-summary', array(
		'label'               => 'Get audit log hero summary',
		'description'         => 'Returns last-24h totals, 7-day trend vs. prior, unique attackers in 24h, and LLA lockout status. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_summary',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'last_24h'             => array( 'type' => 'object' ),
				'last_7d_vs_prior'     => array( 'type' => 'object' ),
				'unique_attackers_24h' => array( 'type' => 'integer' ),
				'lla'                  => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-counters', array(
		'label'               => 'Get audit log counter timeline',
		'description'         => 'Returns per-day event counters for the last N days (default 30, max 90). Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_counters',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-login-successes', array(
		'label'               => 'Get recent successful logins',
		'description'         => 'Returns recent per-event successful login records for the last N days (default 30, max 90). Each row: timestamp + username. No IP info. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_login_successes',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/run-audit-prune', array(
		'label'               => 'Run audit log prune now',
		'description'         => 'Manually drops counter buckets and login_success rows older than 90 days. Also polls LLA for new lockouts. Destructive of historical data — NOT exposed to AI.',
		'category'            => 'maintenance',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_run_audit_prune',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'counter_buckets_dropped' => array( 'type' => 'integer' ),
				'login_rows_dropped'      => array( 'type' => 'integer' ),
				'lla_delta'               => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );
```

- [ ] **Step 9.1: Add the 4 `wp_register_ability` calls inside the existing `wp_abilities_api_init` block, before its closing `} );`.**

### Step 9.2: Add 4 execute callbacks at the end of the file

Find the end of `inc/abilities-registration.php` (the file ends after the last callback — likely `snt_ability_unschedule_cron_event` or similar). Append these 4 callbacks at the bottom of the file:

```php

/**
 * Execute callback for signal-noise/get-audit-summary.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_summary() {
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_get_summary_impl();
}

/**
 * Execute callback for signal-noise/get-audit-counters.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_counters( $input ) {
	if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_counters_impl( $days );
}

/**
 * Execute callback for signal-noise/get-audit-login-successes.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_login_successes( $input ) {
	if ( ! function_exists( 'snt_audit_get_login_successes_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_login_successes_impl( $days );
}

/**
 * Execute callback for signal-noise/run-audit-prune.
 *
 * @since 3.8.3
 */
function snt_ability_run_audit_prune() {
	if ( ! function_exists( 'snt_audit_prune_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_prune_impl();
}
```

- [ ] **Step 9.2: Append the 4 execute callbacks to the end of `abilities-registration.php`.**

### Step 9.3: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/abilities-registration.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 9.3: Confirm `php -l` passes.**

---

## Task 10: Register 2 desktop-mode ⌘K commands + JS handlers + Wave 3 commit

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/desktop-mode-integration.php` (add 2 entries to `$commands` array around line 270-276)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/assets/desktop-mode.js` (add 2 case branches in the command dispatch switch)

### Step 10.1: Add 2 commands to the registration array

Find this section in `inc/desktop-mode-integration.php` (around lines 270-276):

```php
		// Cron Dashboard (v3.0.0).
		array( 'slug' => 'sn-cmd-cron-health', 'label' => 'SN: Cron health overview',    'description' => 'Toast a summary of scheduled events + navigate to the Cron tab.',     'icon' => 'dashicons-clock' ),
		array( 'slug' => 'sn-cmd-cron-list',   'label' => 'SN: Open Cron tab',           'description' => 'Navigate directly to the SN Cron tab in wp-admin.',                  'icon' => 'dashicons-list-view' ),

		// Insights (v3.6.0).
		array( 'slug' => 'sn-cmd-insights',    'label' => 'SN: Open Insights tab',       'description' => 'Navigate to the AI-powered Insights tab in wp-admin.',               'icon' => 'dashicons-lightbulb' ),
```

Add 2 entries IMMEDIATELY AFTER the Insights line:

```php
		// Audit log (v3.8.3).
		array( 'slug' => 'sn-cmd-audit-summary',       'label' => 'SN: Audit log summary',        'description' => 'Toast last-24h totals, 7-day trend, unique IPs, LLA lockout count.', 'icon' => 'dashicons-shield-alt' ),
		array( 'slug' => 'sn-cmd-audit-recent-logins', 'label' => 'SN: Recent successful logins', 'description' => 'Toast last 10 successful login timestamps + usernames.',              'icon' => 'dashicons-admin-users' ),
```

- [ ] **Step 10.1: Add 2 audit commands to the `$commands` array.**

### Step 10.2: Understand the file's pattern (no dispatch switch — per-command `registerCommand`)

The actual pattern (verified 2026-05-25): each command registers itself via `window.wp.desktop.registerCommand({ slug, aiCallable, run })`. There is no shared switch/case dispatch. New commands are added by appending new `registerCommand` calls.

The file ends at line 212 with `} )();` (the IIFE close). Add new commands BEFORE that close.

Available helpers in scope:
- `toast( message, type )` — defined at line ~33; `type` is `'success'|'info'|'error'`
- `callRest( action )` — defined at line ~56; calls `signal-noise/v1/cmd/<action>` via `wp.apiFetch`. **NOT useful for us** — we need `signal-noise/v1/audit/<endpoint>`, different namespace path.
- We will use `wp.apiFetch` directly with an absolute path.

- [ ] **Step 10.2: Confirm understanding — file uses `registerCommand` per command, not a switch.**

### Step 10.3: Add 2 `registerCommand` calls

Find the LAST `registerCommand` call in the file (around line 210, the `sn-cmd-insights` command — ends with `} );` followed by a blank line then `} )();`).

Add these 2 new `registerCommand` calls IMMEDIATELY AFTER the `sn-cmd-insights` block + before the final `} )();`:

```javascript

	// Audit log (v3.8.3) — both aiCallable, read-only fetch + toast.
	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-summary',
		aiCallable: true,
		run: function() {
			if ( ! window.wp.apiFetch ) {
				toast( 'wp.apiFetch unavailable.', 'error' );
				return;
			}
			window.wp.apiFetch( { path: '/signal-noise/v1/audit/summary' } )
				.then( function( s ) {
					var msg = 'Last 24h: ' + ( s.last_24h.all_total || 0 ) + ' events (' +
						( s.last_24h.failed_total || 0 ) + ' failed, ' +
						( s.last_24h.recon_total || 0 ) + ' recon). ' +
						'7d trend: ' + ( s.last_7d_vs_prior.pct_delta >= 0 ? '+' : '' ) +
						s.last_7d_vs_prior.pct_delta + '%. ' +
						'Unique IPs (24h): ' + ( s.unique_attackers_24h || 0 ) + '. ' +
						'LLA lockouts: ' + ( s.lla.active_lockouts || 0 ) + '.';
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Audit summary failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );

	window.wp.desktop.registerCommand( {
		slug: 'sn-cmd-audit-recent-logins',
		aiCallable: true,
		run: function() {
			if ( ! window.wp.apiFetch ) {
				toast( 'wp.apiFetch unavailable.', 'error' );
				return;
			}
			window.wp.apiFetch( { path: '/signal-noise/v1/audit/login-successes?days=30' } )
				.then( function( rows ) {
					if ( ! rows || ! rows.length ) {
						toast( 'No successful logins in last 30 days.', 'info' );
						return;
					}
					var last10 = rows.slice( 0, 10 );
					var msg = 'Last ' + last10.length + ' logins: ' +
						last10.map( function( r ) { return r.formatted + ' (' + r.user + ')'; } ).join( '; ' );
					toast( msg, 'info' );
				} )
				.catch( function( err ) {
					toast( 'Recent logins failed: ' + ( err.message || 'unknown error' ), 'error' );
				} );
		}
	} );
```

- [ ] **Step 10.3: Append the 2 `registerCommand` calls before the file's closing `} )();`.**

### Step 10.4: Verify files parse / lint

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/desktop-mode-integration.php
node --check /Users/juanlentino/projects/signal-and-noise-tools/assets/desktop-mode.js
```

Both should pass without error.

- [ ] **Step 10.4: Confirm PHP + JS syntax checks pass.**

### Step 10.5: Commit Wave 3

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git add inc/audit-log.php inc/abilities-registration.php inc/desktop-mode-integration.php assets/desktop-mode.js
git commit -m "$(cat <<'EOF'
wave 3: audit log REST routes + 4 abilities + 2 ⌘K commands (v3.8.3 in flight)

- REST: 4 routes under signal-noise/v1/audit/* (summary/counters/
  login-successes/prune), co-located in audit-log.php to keep the
  audit unit cohesive (single file for impls + capture + REST + cron).

- Abilities: 4 registered (3 read with aiCallable-equivalent
  annotations.destructive=false; 1 maintenance prune with destructive=true,
  not exposed to AI per spec § 4). Categories: diagnostics (3) +
  maintenance (1) — both pre-existing.

- desktop-mode: 2 ⌘K commands (sn-cmd-audit-summary,
  sn-cmd-audit-recent-logins), both read-only toast outputs that
  call the REST endpoints. Both aiCallable-eligible.

Wave 3 of 4. Wave 4 = full G1-G16 gate sweep; Wave 5 = changelog +
version bump + tag + push.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 10.5: Commit Wave 3.**

---

# WAVE 4 — Verification (full gate sweep)

## Task 11: Deploy waves 1-3 to production, then run G1-G16

**Why:** verification has to happen against the live site (production WP, real LLA, real desktop-mode portal). Local php -l only catches syntax.

### Step 11.1: Push the 3 commits to GitHub

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git push origin main
```

- [ ] **Step 11.1: Push commits to plugin repo main.**

### Step 11.2: Trigger deploy via gh CLI

```bash
gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref main
```

Wait ~30-60 seconds for deploy to complete. Verify deploy succeeded:

```bash
gh run list --repo juanlentino/signal-and-noise-tools --limit 1
```

Expected: most-recent run shows `completed success`.

- [ ] **Step 11.2: Trigger + verify deploy.**

### Step 11.3: Server smoke — confirm the new module is loaded

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; echo function_exists(\"snt_audit_get_summary_impl\") ? \"AUDIT MODULE LOADED\" : \"NOT LOADED\";"'
```

Expected: `AUDIT MODULE LOADED`.

- [ ] **Step 11.3: Confirm audit module loaded on production.**

### Step 11.4: Gate G1 — option lazy-init works

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; snt_audit_increment_counter_impl(\"login_failed\", \"127.0.0.1\"); \$opt = get_option(\"sn_audit_log_v1\"); echo \"schema_version=\" . \$opt[\"schema_version\"] . \"\n\"; print_r( \$opt[\"counters\"] );"'
```

Expected: prints `schema_version=1` + a counter row for today with `login_failed => 1`, `unique_ips_count => 1`.

- [ ] **G1: Confirm option lazy-init + first event.**

### Step 11.5: Gate G2 — wp_login_failed hook fires the counter

In your browser: visit `https://juanlentino.com/sn-login` (or the custom slug) and submit BAD credentials twice. Then run:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); \$today = wp_date(\"Y-m-d\"); echo \"login_failed today: \" . \$opt[\"counters\"][\$today][\"login_failed\"];"'
```

Expected: shows ≥2 (your 2 failed attempts plus any from G1).

- [ ] **G2: Confirm `wp_login_failed` increments the counter.**

### Step 11.6: Gate G3 — wp_login records a row

Log in successfully via `/sn-login`. Then run:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); echo \"login_success count: \" . count( \$opt[\"login_success\"] ) . \"\n\"; print_r( end( \$opt[\"login_success\"] ) );"'
```

Expected: count ≥1, last row has `ts` (recent unix timestamp) + `user` (your username).

- [ ] **G3: Confirm `wp_login` records the per-event row.**

### Step 11.7: Gate G4 — direct `/wp-login.php` visit increments counter

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://juanlentino.com/wp-login.php
```

Expected: 404.

Then:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); \$today = wp_date(\"Y-m-d\"); echo \"wp_login_404 today: \" . \$opt[\"counters\"][\$today][\"wp_login_404\"];"'
```

Expected: ≥1.

- [ ] **G4: Confirm `wp_login_404` increments on direct `/wp-login.php` curl.**

### Step 11.8: Gate G5 — unauth `/wp-admin/index.php` visit increments counter

Open a private/incognito window (no login session). Visit `https://juanlentino.com/wp-admin/index.php`.

Expected: page shows 404.

Then:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); \$today = wp_date(\"Y-m-d\"); echo \"wp_admin_unauth_404 today: \" . \$opt[\"counters\"][\$today][\"wp_admin_unauth_404\"];"'
```

Expected: ≥1.

- [ ] **G5: Confirm `wp_admin_unauth_404` increments on unauth /wp-admin visit.**

### Step 11.9: Gate G6 — unique_ips_count behaves correctly

Trigger 2 failed logins from the SAME IP, then check the count:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); \$today = wp_date(\"Y-m-d\"); echo \"unique_ips_count today: \" . \$opt[\"counters\"][\$today][\"unique_ips_count\"] . \"\n\"; \$set = get_transient(\"sn_audit_today_ips\"); echo \"transient set size: \" . count( (array) \$set );"'
```

Expected: `unique_ips_count` = small number (e.g., 1-3 depending on prior captures); transient set size ≈ same. Importantly: should NOT increment per event from same IP — should match unique IP count.

- [ ] **G6: Confirm unique IPs counter doesn't double-count same-IP events.**

### Step 11.10: Gate G7 — REST GET /audit/summary returns valid JSON

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'curl -s -u "<APP_USERNAME>:<APP_PASSWORD>" https://juanlentino.com/wp-json/signal-noise/v1/audit/summary | head -c 500'
```

(Replace `<APP_USERNAME>:<APP_PASSWORD>` with an Application Password if needed; or run a logged-in browser session against the URL.)

Expected: JSON with keys `last_24h`, `last_7d_vs_prior`, `unique_attackers_24h`, `lla`.

- [ ] **G7: Confirm REST `/audit/summary` returns valid JSON.**

### Step 11.11: Gates G8-G10 — Abilities REST surface

For each ability, hit `/wp-json/wp-abilities/v1/abilities/<name>/run`. Example for `get-audit-summary`:

```bash
curl -s -X POST -u "<APP_USER>:<APP_PWD>" \
  -H "Content-Type: application/json" \
  -d '{}' \
  https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-audit-summary/run | head -c 500
```

Expected: JSON with the summary shape.

Repeat for `get-audit-counters`, `get-audit-login-successes`, `run-audit-prune`. The 3 read abilities should succeed; `run-audit-prune` should also succeed (it's a write ability but you have manage_options).

- [ ] **G8-G10: Confirm 4 abilities reachable via REST + return valid JSON.**

### Step 11.12: Gates G11-G12 — desktop-mode ⌘K commands

In the desktop-mode portal (juanlentino.com wp-admin via desktop-mode):
- Open ⌘K
- Type "audit" — verify the 2 new commands appear
- Run `SN: Audit log summary` — verify a toast appears with last-24h + 7d trend + unique IPs + LLA info
- Run `SN: Recent successful logins` — verify a toast appears with up to 10 login timestamps + usernames

- [ ] **G11-G12: Confirm 2 ⌘K commands work in desktop-mode portal.**

### Step 11.13: Gate G13 — admin sub-tab renders

In your browser: visit `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=security`.

Expected:
- Sub-tab nav row VISIBLE at top (2 pills: "Login URL" + "Audit log")
- "Login URL" tab still works (unchanged)
- Click "Audit log" — URL becomes `&sub=audit-log`
- Page renders: 4 hero stat cards + counter timeline table + recent logins table + LLA summary card + Prune button

No PHP notices/warnings visible.

- [ ] **G13: Confirm Audit log sub-tab renders correctly.**

### Step 11.14: Gate G14 — sub-tab nav now visible

This is implicitly covered by G13 (the row appears with 2 pills). Confirm explicitly: previously with only 'login', sub-tab nav was hidden. Now with `audit-log` added as the 2nd entry, the nav is visible.

- [ ] **G14: Confirm sub-tab nav row is now visible on Security tab.**

### Step 11.15: Gate G15 — "Prune now" button works

In the Audit log sub-tab, click "Prune now". Expected: success notice appears with prune stats (0 buckets, 0 rows likely on first day; LLA delta may show whatever LLA has). Then re-check the option blob via SSH:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$opt = get_option(\"sn_audit_log_v1\"); echo \"counter buckets: \" . count( \$opt[\"counters\"] ) . \"\n\"; echo \"login rows: \" . count( \$opt[\"login_success\"] );"'
```

Expected: no errors; row counts still reasonable.

- [ ] **G15: Confirm "Prune now" button executes successfully.**

### Step 11.16: Gate G16 — daily cron is scheduled

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; \$ts = wp_next_scheduled(\"sn_audit_log_prune\"); echo \$ts ? \"Next prune: \" . wp_date(\"Y-m-d H:i:s\", \$ts) : \"NOT SCHEDULED\";"'
```

Expected: `Next prune: <date within next 24h>`.

Also verify it shows up in cron-dashboard tab: visit `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=automation&sub=cron`. Expected: `sn_audit_log_prune` appears in the events list.

- [ ] **G16: Confirm daily prune cron is scheduled + visible in cron-dashboard.**

### Step 11.17: LLA defensive read works on missing/empty option

If LLA's `limit_login_lockouts` is currently empty/missing, this is already implicitly tested. Verify explicitly:

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; print_r( snt_audit_read_lla_summary_impl() );"'
```

Expected: returns `array( 'active_lockouts' => N, 'most_recent_lockout_ts' => <int|null> )` without warnings or errors.

- [ ] **G16-bonus: Confirm LLA read works without warnings.**

### Step 11.18: Fix-forward any gate failures

If any gate failed: identify the issue, edit the relevant file, repeat `php -l`, commit the fix as a separate commit (e.g., `wave 4: fix G7 — REST route 404`), and re-run the failing gate. Don't proceed to Wave 5 until all gates pass.

- [ ] **Step 11.18: Fix-forward any failures.**

---

# WAVE 5 — Ship (CHANGELOG, version bump, tag, push)

## Task 12: CHANGELOG entry + version bump + commit + tag + push + deploy

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/CHANGELOG.md` (add v3.8.3 entry at top)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php:6` (bump Version)

### Step 12.1: Add CHANGELOG entry

Open `CHANGELOG.md` and add this entry at the TOP (immediately under the file's main heading, before the previous most-recent entry):

```markdown
## v3.8.3 — 2026-05-25 — Login hardening audit log

**New feature:** Security → Audit log sub-tab. Captures 6 login-related events:

- `login_success` as per-event rows (timestamp + username, no IP)
- `login_failed`, `wp_login_404`, `wp_admin_unauth_404`, `lockout_triggered`, `password_reset` as day-bucketed counters
- `unique_ips_count` per day, computed via ephemeral hashed-IP transient set with 25h TTL (no IPs persisted long-term)

**Surfaces (4-surface dispatch):**

- Admin sub-tab under Security with stat-card hero + counter timeline + recent-logins table + LLA lockout summary
- REST routes: `GET /signal-noise/v1/audit/{summary,counters,login-successes}` + `POST /audit/prune`
- Abilities: `get-audit-summary` + `get-audit-counters` + `get-audit-login-successes` (read, ai-eligible) + `run-audit-prune` (write, not ai-callable)
- desktop-mode ⌘K: `SN: Audit log summary` + `SN: Recent successful logins`

**Storage:** single autoloaded option `sn_audit_log_v1` (JSON-encoded, schema-versioned). Daily prune cron `sn_audit_log_prune` enforces 90-day retention.

**Notable verified-at-design-time finding:** LLA fires NO action hook on lockout (only `llar_plugin_version_updated` + `llar_mfa_generate_codes` exist in LLA core). The `lockout_triggered` counter is therefore captured via a polling fallback — daily prune tick reads `limit_login_lockouts` array size delta. Imprecise but acceptable for trend detection.

**Visible UI change:** the Security tab's sub-tab nav row is now visible (was hidden when count=1; adding "Audit log" makes count=2).

**Patch 3/7 in v3.8.x.**
```

- [ ] **Step 12.1: Add CHANGELOG entry at top of file.**

### Step 12.2: Bump Version in plugin docblock

Find line 6 of `signal-and-noise-tools.php`:

```php
 * Version:     3.8.2
```

Replace with:

```php
 * Version:     3.8.3
```

SNT_VERSION derives from this docblock at load time (per v3.8.2 retrofit) — no other constant edits needed.

- [ ] **Step 12.2: Bump `Version: 3.8.2` → `3.8.3`.**

### Step 12.3: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 12.3: Confirm `php -l` passes.**

### Step 12.4: Commit ship

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git add CHANGELOG.md signal-and-noise-tools.php
git commit -m "$(cat <<'EOF'
v3.8.3: login hardening audit log

Security → Audit log sub-tab. 6 captured events (1 per-event for
login_success, 5 day-bucketed counters for failed/wp_login_404/
wp_admin_unauth_404/lockout_triggered/password_reset), plus
unique_ips_count via ephemeral hashed-IP transient (no IPs persist
long-term). Single autoloaded option sn_audit_log_v1, 90-day
retention via daily cron sn_audit_log_prune.

Full 4-surface dispatch: admin form (stat-card hero + counter
timeline + recent-logins tables + LLA summary), REST under
signal-noise/v1/audit/*, 4 Abilities (3 read + 1 maintenance), 2
desktop-mode ⌘K commands.

LLA fires no lockout hook (verified 2026-05-25) — lockout_triggered
counter uses polling fallback on limit_login_lockouts size delta
inside the daily prune tick.

Adding the audit-log sub-tab under Security automatically reveals the
sub-tab nav row (was hidden at count=1).

Patch 3/7 in v3.8.x.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 12.4: Commit ship.**

### Step 12.5: Tag v3.8.3

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git tag -a v3.8.3 -m "v3.8.3 — login hardening audit log under Security"
```

- [ ] **Step 12.5: Create annotated tag v3.8.3.**

### Step 12.6: Push commit + tag

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git push origin main
git push origin v3.8.3
```

Expected: both push successfully.

- [ ] **Step 12.6: Push commit + tag to GitHub.**

### Step 12.7: Deploy via WP Updates UI OR gh CLI

**Canonical path:** in wp-admin → Dashboard → Updates → "Update plugin" for Signal & Noise Tools. WP self-update path handles `.git` preservation per the v1.11.2+ pre/post-install filter pair.

**Manual path (faster):**

```bash
gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref v3.8.3
```

Wait ~30-60 seconds, then verify:

```bash
gh run list --repo juanlentino/signal-and-noise-tools --limit 1
```

Expected: most-recent run `completed success`.

- [ ] **Step 12.7: Deploy v3.8.3.**

### Step 12.8: Post-deploy server verification

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; echo SNT_VERSION;"'
```

Expected: `3.8.3`.

- [ ] **Step 12.8: Confirm SNT_VERSION=3.8.3 on production.**

---

## Task 13: Update reconciliation handoff residual list

**Files:**
- Modify: `/Users/juanlentino/Projects/signal-and-noise/docs/superpowers/handoffs/2026-05-25-roadmap-reconciliation.md`

### Step 13.1: Mark item #1 as shipped

Open the reconciliation handoff and edit the "What's actually left to build" section. Cross out item #1 (login hardening audit log) and add a note indicating it shipped as plugin v3.8.3 on 2026-05-25.

Then commit + push the theme repo:

```bash
cd /Users/juanlentino/Projects/signal-and-noise/.claude/worktrees/nice-goldstine-063551
git add docs/superpowers/handoffs/2026-05-25-roadmap-reconciliation.md
git commit -m "docs(handoff): mark login hardening shipped as plugin v3.8.3"
git push origin HEAD:main
```

- [ ] **Step 13.1: Update reconciliation handoff + commit/push.**

---

## Rollback plan

If v3.8.3 introduces a problem post-deploy:

1. **Quick revert:** `git revert v3.8.3` on main, push, redeploy. The option blob `sn_audit_log_v1` persists harmlessly (orphan option, no code reads it after revert).
2. **Hard revert:** `git reset --hard <pre-v3.8.3-commit>`, force-push to main (avoid unless absolutely necessary; force-push is destructive). Then `gh workflow run deploy.yml --ref main`.
3. **Disable just the audit log:** comment out the 2 `require_once` lines for `audit-log.php` + `audit-log-admin.php` in `signal-and-noise-tools.php`. Capture hooks won't fire; sub-tab won't render; rest of plugin works.

The `sn_audit_log_v1` option + `sn_audit_today_ips` transient can be manually deleted via WP-CLI or SSH if needed:
```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; delete_option(\"sn_audit_log_v1\"); delete_option(\"sn_audit_lla_last_lockout_count\"); delete_transient(\"sn_audit_today_ips\"); echo \"cleaned\";"'
```

---

## Self-review

Performed inline after writing. Checked against spec sections + verification gates:

- **Spec coverage:** every G1-G16 gate has a corresponding step in Task 11. Every spec Section 1-6 element has a corresponding Task 1-12 step. Schema, hashing, retention, all 4 surfaces, edge cases E1-E7 — all addressed.
- **Placeholder scan:** no TBDs, no "implement later," no "similar to Task N" (every code block is complete).
- **Type consistency:** function names + signatures consistent across tasks (`snt_audit_get_summary_impl` used in Task 2 def, Task 5 admin caller, Task 8 REST callback, Task 9 ability callback).
- **Wave commit boundaries:** 4 commits total (Waves 1, 2, 3, 5; Wave 4 has no commit unless fix-forward). Each wave leaves codebase functional.
- **REST routes decision resolved:** co-located in `audit-log.php` for cohesion (not in `rest-api.php`).
- **JS handler pattern verified:** Task 10.3 uses `window.wp.desktop.registerCommand({ slug, aiCallable, run })` (the file's actual per-command registration pattern, verified 2026-05-25 — NOT a switch dispatch) with `wp.apiFetch` for REST calls (NOT raw `fetch` — matches the file's existing `callRest` helper's auth model).

Fixed inline: Task 10.2 + 10.3 originally guessed at a switch/case dispatch; replaced with the verified `registerCommand` pattern + correct `wp.apiFetch` usage.

No other issues found.
