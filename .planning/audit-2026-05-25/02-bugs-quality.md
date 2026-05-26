# Bug + quality audit — plugin (signal-and-noise-tools)

**Scanned:** 2026-05-25

## Summary

The plugin is in solid shape overall — security fundamentals (nonces, capability checks, SQL prepare(), output escaping) are consistently applied across the 48 PHP files. No SQL injection vectors or XSS found in admin surfaces. The `SNT_VERSION` constant is correctly derived from the docblock (no drift risk). AI error handling uses `is_wp_error()` at every call site.

**Two high-confidence bugs found:**

1. **Critical correctness bug (high):** Drift-phrase positions are byte offsets in *stripped* content (after `strip_shortcodes` + `wp_strip_all_tags`) but the apply impl uses them as byte offsets into *raw* `post_content`. Any post with shortcodes or block comments before the target phrase will apply the replacement to the wrong character position — potentially corrupting post content silently.

2. **Medium UX/correctness bug:** The `orphaned_media` health check fetches ALL attachment types (PDFs, videos, ZIPs) but `snt_ai_orphan_suggest_impl` rejects non-image MIME types with a 422 error. "Suggest" buttons shown for non-image orphans will always fail.

---

## Findings

### B-01 [severity: high] — Drift-apply position offset is in stripped content, not raw post_content

**File:** `inc/health-checks.php:436-464` (extractor), `inc/ai-drift-phrase-suggest.php:104-105, 205-206` (consumer)

**Issue:**
`sn_health_extract_time_phrase_candidates()` runs `strip_shortcodes()` + `wp_strip_all_tags()` on `$content`, then captures byte offsets with `PREG_OFFSET_CAPTURE` against the *stripped* text:

```php
// health-checks.php:442-456
$text = strip_shortcodes( $text );
$text = wp_strip_all_tags( $text );
// ...
$pos = (int) $hit[1];  // offset in $text — stripped
$out[] = array( 'position' => $pos, ... );
```

These positions are stored in findings and passed via JS data-attributes to `snt_ai_drift_suggest_impl()` and `snt_ai_drift_apply_impl()`, which use them against raw `post_content`:

```php
// ai-drift-phrase-suggest.php:104-105
$current_content = (string) $post->post_content;  // raw, with blocks + shortcodes
$at_position     = substr( $current_content, $position, strlen( $phrase ) );
```

For any post containing Gutenberg block comments (`<!-- wp:paragraph -->`) or shortcodes before the target phrase, the stripped offset will be smaller than the raw offset by the sum of all markup removed before that point. The pre-flight check (`$at_position !== $phrase`) will fail and return a `snt_ai_phrase_drifted` 409 error — meaning the Suggest button appears to work (returns a suggestion) but Apply always rejects with "Phrase no longer at the recorded position."

**Why it matters:** Drift-phrase Suggest+Apply (v4.0.0) is silently broken for any post that uses Gutenberg blocks. In practice, almost all posts on a Gutenberg site use block markup. The feature effectively does not work. If the 409 guard were ever removed or bypassed, the splice at a wrong position could corrupt arbitrary post content.

**Proposed fix:** Extract positions against raw `post_content` (not the stripped version). Instead of stripping before scanning, apply the regex patterns directly to the raw content (accepting false-positive matches inside HTML attributes) OR store both the phrase and the 80-char raw-context fingerprint so the apply impl can use `strpos($raw_content, $phrase)` to locate the correct offset at apply time rather than relying on a stored absolute position. The fingerprint approach (already used) is the safer path — replace the stored `position` integer with `strpos()` lookup at apply time, using the fingerprint as the collision guard.

**Risk:** medium — the fix itself is low risk (changes only the apply impl's position-resolution step); the current code is already failing safely (409 not corruption) for Gutenberg posts.

---

### B-02 [severity: medium] — Orphaned-media health check includes non-image attachments; suggest impl rejects them

**File:** `inc/health-checks.php:192-196` (query), `inc/ai-orphan-suggest.php:70-75` (gate)

**Issue:**
The orphaned-media health check queries for ALL attachment types:

```sql
-- health-checks.php:192-196
WHERE post_type = 'attachment'
  AND post_date_gmt < %s
```

No `post_mime_type` filter. PDFs, videos, audio files, ZIP archives all appear in findings. The health-checks-admin.php then renders a "Suggest" button for every `orphaned_media` finding (line 187-190), including non-image types.

`snt_ai_orphan_suggest_impl()` opens with:

```php
// ai-orphan-suggest.php:73-75
if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
    return new WP_Error( 'snt_ai_not_attachment', __( 'Attachment is not an image MIME type.', ... ), ... );
}
```

Every non-image orphan returns a 422 instantly from the AI layer. The user sees "Suggest" buttons that always fail for PDFs/videos/audio in their media library.

**Why it matters:** Degraded UX — confusing error on every non-image orphan. The fix also improves AI cost efficiency (no wasted capability-check round-trips).

**Proposed fix:** Add `AND post_mime_type LIKE 'image/%'` to the `sn_health_check_orphaned_media()` query, OR add a per-finding guard in `sn_health_render_suggest_cell()` that only renders the Suggest button when `$finding['subject_type'] === 'attachment'` AND the MIME type starts with `image/` (requires including MIME in the finding array). The query filter is simpler.

**Risk:** low — query change is purely additive (fewer results). Non-image orphans would still appear as findings; they'd just lack the Suggest button.

---

### B-03 [severity: medium] — `sanitize_text_field` on the `phrase` REST arg may corrupt multi-byte or special-character phrases

**File:** `inc/ai-drift-phrase-suggest.php:253, 277`

**Issue:**
Both the drift-suggest and drift-apply REST endpoints register `phrase` with `'sanitize_callback' => 'sanitize_text_field'`. `sanitize_text_field()` removes multi-line whitespace, strips tags, and normalises Unicode — including stripping some Unicode characters. A phrase extracted from post content that contains special Unicode (e.g., em-dashes `—`, curly quotes `"`, or unusual Unicode whitespace) will arrive at the impl with those characters stripped, while the impl then does a byte-exact comparison against the phrase in raw `post_content`:

```php
// ai-drift-phrase-suggest.php:105-106
$at_position = substr( $current_content, $position, strlen( $phrase ) );
if ( $at_position !== $phrase ) { ... }  // always fails if phrase was sanitized
```

The result is that any drift phrase containing special Unicode characters will always get a `snt_ai_phrase_drifted` 409 from the apply endpoint, even on a fresh scan.

**Why it matters:** Combined with B-01, this means Suggest+Apply for drift phrases fails for: (a) any post with block markup (B-01), and (b) any phrase with Unicode characters (B-03). Given that `—` and curly quotes are common in written prose, the feature's real-world failure rate is high.

**Proposed fix:** Use `wp_check_invalid_utf8( $value, true )` or simply no sanitize_callback for `phrase` (REST type=string with no sanitize does NOT strip Unicode). The impl already validates the phrase at the byte-exact comparison step, so input safety is achieved through that check, not through sanitization.

**Risk:** low — removing sanitize_callback from `phrase` only affects what reaches `$phrase` in the impl; the byte-exact comparison is the real gate.

---

### B-04 [severity: medium] — Three cron schedules are registered on `admin_init` instead of `init`, so WP-CLI cron runs never register them

**File:** `inc/cron-history.php:276`, `inc/rss-plausible-tracker.php:240`, `inc/insights.php:513`

**Issue:**
```php
add_action( 'admin_init', 'snt_cron_history_schedule_cron' );   // cron-history.php:276
add_action( 'admin_init', 'sn_rss_tracker_schedule_cron' );     // rss-plausible-tracker.php:240
add_action( 'admin_init', 'snt_insights_maybe_schedule_weekly_cron' );  // insights.php:513
```

`admin_init` does not fire on WP-CLI invocations or on front-end requests. WP-Cron fires on front-end page loads. If none of these crons were ever scheduled during an admin session (e.g., on a fresh install only accessed via CLI or Cloudways SSH), the events will never be added to the cron table. The audit-log prune (line 409 in `audit-log.php`) correctly uses `init`, which fires on all contexts.

**Why it matters:** On a newly deployed environment where the first page load is a CLI or front-end request, these crons are never scheduled. Cron-history, RSS-tracker prune, and insights scan never run automatically. Data builds up without pruning.

**Proposed fix:** Change all three `admin_init` hooks to `init`. The idempotent `wp_next_scheduled()` guard prevents double-scheduling.

**Risk:** low — adding a schedule check to `init` is inexpensive; the `wp_next_scheduled()` guard makes it idempotent.

---

### B-05 [severity: medium] — "4 checks" hardcoded in two places; the scan actually runs 5 checks

**File:** `inc/health-checks.php:15, 51`, `inc/health-checks-admin.php:45`

**Issue:**
The module docblock (line 15), the `sn_health_run_scan()` docblock (line 51), and the admin status box (line 45) all say "4 checks." The `sn_health_run_scan()` function (lines 62-68) dispatches five checks: `missing_alt`, `orphaned_media`, `broken_links`, `stale_posts`, `drift_time_phrases`. The admin UI message hard-codes the count:

```php
// health-checks-admin.php:45
echo '... across 4 checks ...'
```

**Why it matters:** The UI states an incorrect count to the user. A minor credibility issue; also makes code maintenance confusing (future developers expect 4, find 5).

**Proposed fix:** Replace the hardcoded `'4 checks'` in the admin render with a dynamic count: `count( $last_scan['checks'] ) . ' checks'`. Update the docblock comments in health-checks.php.

**Risk:** low — string change only.

---

### B-06 [severity: medium] — Missing `is_wp_error()` check after `update_post_meta` in `snt_ai_drift_apply_impl` (positive path)

**File:** `inc/ai-drift-phrase-suggest.php:213-220`

**Issue:**
The apply impl uses `wp_update_post()` (not `update_post_meta()`) and does check for `WP_Error`. This finding was investigated but is clean — `wp_update_post(..., true)` correctly returns WP_Error on failure and is checked at line 218.

However, there is a subtle issue: `wp_update_post()` triggers `save_post` and all related hooks, including any third-party hook that might further modify `post_content`. If a hook re-writes `post_content` after the drift splice, the edit is written but subsequent splice attempts would use stale fingerprints from the same scan. This is a design limitation, not a bug, and is already partially mitigated by the fingerprint check.

**Revised severity: low** — design limitation, not a concrete defect. See below for proper entry.

---

### B-07 [severity: low] — Orphan-suggest system-prompt string is `const` at file scope (PHP 8.0 class-context warning risk)

**File:** `inc/ai-orphan-suggest.php:30-43`

**Issue:**
```php
const SNT_AI_ORPHAN_SUGGEST_SYSTEM = "You are evaluating...";
```

PHP allows `const` at file scope (outside classes) but the value is concatenated with the `.` operator on a multi-line string. In PHP 8.0 (the plugin's minimum per `Requires PHP: 8.0`) this is valid. However, if a future developer wraps this in a class, `const` expressions cannot use non-literal concatenation. Not a current bug — a future-fragility note.

**Revised severity: low** — stylistic. Other AI system-prompt constants use identical patterns with no issue.

---

### B-08 [severity: low] — Stale comment in `health-checks.php` says drift-detection is "Detection only in v1" and "AI-suggested replacement text is a future v3.7.x feature"

**File:** `inc/health-checks.php:481-484`

**Issue:**
```php
// health-checks.php:481-484
/**
 * Detection only in v1 — findings deep-link to the editor. AI-suggested
 * replacement text is a future v3.7.x feature.
```

Drift-phrase AI-suggested replacement shipped in v4.0.0 (`inc/ai-drift-phrase-suggest.php`). The comment is stale by multiple major versions.

**Why it matters:** Confuses future developers looking at the file, suggesting the feature doesn't exist when it does.

**Proposed fix:** Update the docblock to reflect that AI Suggest+Apply is live since v4.0.0.

**Risk:** none — comment-only change.

---

### B-09 [severity: low] — `health-checks-admin.php` comment says "4 surfaces" and references a v1 that was detection-only

**File:** `inc/health-checks-admin.php:33`

**Issue:**
```php
echo '<p class="sn-prose">Detection-only scans of your post + attachment graph. v1 finds problems; the editor is the fix surface.</p>';
```

This is the live admin copy shown to the user. As of v4.0.0 the Health tab has AI Suggest+Apply. The copy contradicts the buttons visible directly below it on the same page.

**Why it matters:** User-visible incorrect copy. Lowers trust in the admin UI.

**Proposed fix:** Update to: "Scans your post and attachment graph. AI-assisted Suggest+Apply is available for flagged items when a provider is configured."

**Risk:** none — copy-only change.

---

### B-10 [severity: low] — Magic number `25` (candidate cap per post) in `sn_health_check_drift_time_phrases` not defined as a constant

**File:** `inc/health-checks.php:517-519`

**Issue:**
```php
if ( count( $candidates ) > 25 ) {
    // Cap candidates per post: max_tokens=600 budgets for ~25 verdicts
    $candidates = array_slice( $candidates, 0, 25 );
```

The `25` is a budget-derived constraint (max_tokens=600 with ~24 tokens/verdict). The same value appears twice in 3 lines. If the AI max-tokens budget for drift detection is ever tuned, the cap must be updated by searching for the literal `25` rather than changing a named constant.

**Proposed fix:** Define `const SN_HEALTH_DRIFT_MAX_CANDIDATES_PER_POST = 25;` next to the other health-check constants at the top of the file.

**Risk:** none — constant extraction only.

---

### B-11 [severity: low] — File size violations: 13 of 48 PHP files exceed the 150-LOC project ceiling

Per `CLAUDE.md`: "No file over ~150 lines."

| File | Lines |
|---|---|
| `inc/abilities-registration.php` | 1642 |
| `inc/admin-page.php` | 1371 |
| `inc/desktop-mode-integration.php` | 717 |
| `inc/content-migrations.php` | 700 |
| `inc/health-checks.php` | 620 |
| `inc/rss-plausible-tracker.php` | 575 |
| `inc/insights.php` | 538 |
| `inc/audit-log.php` | 476 |
| `inc/admin-tab-dashboard.php` | 470 |
| `inc/rest-api.php` | 458 |
| `inc/reading-time.php` | 416 |
| `inc/seo.php` | 407 |
| `inc/cron-dashboard.php` | 403 |

`abilities-registration.php` at 1642 lines is 10× the ceiling. `admin-page.php` at 1371 is 9×. These are not operational emergencies, but they increase cognitive load and make targeted changes riskier.

**Why it matters:** Maintenance burden. The ceiling exists to enforce componentization. Each of these files already has natural split points (per-ability section in abilities-registration.php, per-tab rendering in admin-page.php).

**Proposed fix:** Split abilities-registration.php into per-feature ability files (e.g., `abilities-ai.php`, `abilities-system.php`, `abilities-cron.php`) referenced from a thin orchestrator. Split admin-page.php's tab rendering into the existing per-tab action-hook pattern.

**Risk:** medium — refactoring large files risks subtle include-order or global-scope issues. Must be done with test coverage in place.

---

### B-12 [severity: low] — Tests stub the drift-apply impl; the critical position-offset bug (B-01) cannot be caught by current tests

**File:** `tests/abilities-integration.php:268-280`, `tests/health-checks.php` (no drift-apply integration test)

**Issue:**
`tests/abilities-integration.php` replaces `snt_ai_drift_apply_impl` with a stub that does not interact with real post content or byte offsets. The only test that exercises the real impl path is in `tests/health-checks.php`, which tests the drift-detect phase (not the apply phase). There is no test that:
1. Creates a Gutenberg-formatted post (with `<!-- wp:paragraph -->` block markup)
2. Runs `sn_health_extract_time_phrase_candidates()` to get a position
3. Feeds that position to `snt_ai_drift_apply_impl()` and asserts whether the phrase was found at the correct raw-content offset

This gap is why B-01 existed undetected since v4.0.0.

**Proposed fix:** Add a test in `tests/health-checks.php` that constructs a fake post with a block-comment prefix, extracts candidates, and verifies that the returned position is valid for `substr($raw_content, $position, strlen($phrase)) === $phrase`. This test would fail today (confirming B-01) and pass after the fix.

**Risk:** none — test addition only.
