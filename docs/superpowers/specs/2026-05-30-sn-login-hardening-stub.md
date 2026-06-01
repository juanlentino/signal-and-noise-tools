# Stub spec — `/sn-login` hardening (noindex + coexistence verification)

**Status:** STUB — not scheduled. Captured 2026-05-30 from user request ("harden the login option /sn-login… like not being indexed") + an LLAR screenshot warning about `authenticate`-filter contention. Docs-only; parked for a future plugin update (foldable into v4.6.0 or a focused patch). No commitment to ship now.

**Touches:** `inc/login-hide.php` (the custom-login-URL module; the proven wps-hide-login pattern S&N absorbed). Currently 259 lines: `plugins_loaded` intercept (routing decision) → `wp_loaded` handler (`sn_login_handle_request()`, 3 branches).

---

## Primary deliverable — `X-Robots-Tag` header on the served login form

### The actual gap (verified against WP core source, 2026-05-30)

**Important framing correction (per read-the-framework-source rule):** WordPress core ALREADY prevents login-page indexing — but via a `<meta name="robots" content="noindex, follow">` tag emitted by `login_header()` through the `wp_robots` filter + `wp_robots_sensitive_page()` callback. It does NOT send an `X-Robots-Tag` HTTP header.

Because S&N's Branch 1 serves the form via `require_once ABSPATH . 'wp-login.php'` (`sn_login_handle_request()`, line ~216), the full `login_header()` runs — so **the core meta-robots tag *should* already appear on `/sn-login`.** Therefore this is NOT "add noindex because it's missing." It is:

1. **VERIFY** the core `wp_robots` meta tag survives the custom-slug serve path (Branch 1). Confirm with `curl -s https://juanlentino.com/sn-login | grep -i robots`. If present → core protection is intact; if absent → the custom path is stripping it (investigate why).
2. **ADD the HTTP-header layer** S&N can uniquely provide: `X-Robots-Tag: noindex, nofollow` sent on the Branch-1 response. This is belt-and-suspenders over core's meta tag — an HTTP header is honored even by bots that don't parse HTML, and can't be removed by a later output filter. This is the genuine net-new protection.

### Implementation sketch

In `sn_login_handle_request()`, Branch 1 (`inc/login-hide.php` ~line 214-217), BEFORE `require_once ABSPATH . 'wp-login.php'`:

```php
if ( ! empty( $GLOBALS['sn_login_serve_form'] ) ) {
    // Defense-in-depth over core's wp_robots meta tag: an HTTP header is
    // honored by crawlers that don't parse HTML and survives output filters.
    if ( ! headers_sent() ) {
        header( 'X-Robots-Tag: noindex, nofollow', true );
    }
    require_once ABSPATH . 'wp-login.php';
    die;
}
```

- `headers_sent()` guard: `require_once wp-login.php` will send its own headers; ours must precede them. At `wp_loaded` priority (where this runs) nothing has output yet, so the guard is a safety net, not expected to fail.
- Use `header(..., true)` to replace, not stack, if a prior layer set `X-Robots-Tag`.
- Do NOT add `noarchive`/`nosnippet` unless wanted — `noindex, nofollow` is the standard login posture and matches the request.

### Test (TDD, fixture-style — mirrors existing `tests/login-intercept.php`)

`tests/login-noindex-header.php`: stub the serve-form branch + a `header()` capture, assert `X-Robots-Tag: noindex, nofollow` is emitted when `sn_login_serve_form` is set, and NOT emitted on the 404 branches (those already `nocache_headers()`; no robots header wanted there since they're 404s, not the login form). Extends the existing 19-assertion login-intercept suite.

---

## Secondary — LLAR + Two Factor coexistence (verification, from the screenshot)

The LLAR screenshot warns: *"These plugins register additional callbacks on the `authenticate` filter and may affect LLAR login — Two Factor v0.16.0."* Three security plugins now touch the login flow: **Two Factor** (2FA, on `authenticate`), **Limit Login Attempts Reloaded** (lockout, on `authenticate`/`wp_login_failed`), and **S&N login-hide** (routing, on `plugins_loaded` + `wp_loaded`).

**Key architectural fact (reassuring):** S&N intercepts at the *routing* layer (`plugins_loaded` priority 2 → 404 or serve-form), which runs BEFORE the `authenticate` filter fires. So **S&N does NOT compete with Two Factor or LLAR on `authenticate`** — by the time `authenticate` runs, S&N has already decided "serve the real wp-login.php," and Two Factor + LLAR operate normally inside that served form. The LLAR warning is about Two Factor ↔ LLAR contention, not S&N.

**Verification task (no code expected, just confirm):**
1. Confirm a login at `/sn-login` still triggers LLAR lockout on repeated failures (S&N's audit counter `wp_login_404` is separate from LLAR's lockout — both should work).
2. Confirm Two Factor's 2FA challenge still appears after primary auth on the `/sn-login`-served form.
3. Confirm S&N's `wp_login_failed` audit hook (`snt_audit_capture_login_failed_cb`) and LLAR's failure hook don't double-count or interfere (different concerns — audit-viz vs lockout — should coexist).
4. Document the load order in `inc/login-hide.php` docblock if any ordering fragility is found.

This is mostly a "verify it already works" item — S&N's earlier login-routing arc (v1.5.0 → v4.2.1, per [[feedback_read_peer_implementation_first]]) already proved the `plugins_loaded` intercept is the right layer. No conflict is *expected*; document the all-clear (or the fix) either way.

---

## Out of scope (deferred / YAGNI)
- **S&N-side rate-limiting on `/sn-login`** — LLAR is active and does this heavier/better. Don't duplicate; if anything, document "lockout is LLAR's job."
- **Referrer-Policy / frame-ancestors on the login response** — separate hardening surface; could be a follow-up but not requested here. **Verified 2026-05-30:** `inc/security-headers.php` only `header()`s `Permissions-Policy` from WP — Referrer-Policy + X-Frame-Options + HSTS + CSP are set at the **Cloudflare edge** (per its docblock), so they already cover the login response site-wide. A login-specific Referrer-Policy is therefore likely redundant (the edge `Referrer-Policy` already prevents the slug leaking via Referer). Confirm the edge policy is `no-referrer`/`strict-origin-when-cross-origin` before adding anything WP-side. By contrast, **`X-Robots-Tag` is genuinely net-new** — not in security-headers.php, and not a typical CF-edge default — so the primary deliverable stands.
- **Renaming/rotating the slug** — `login.slug` is already a configurable setting (`sn_settings`); rotation is a user action, not code.

## Risks / notes
- `headers_sent()` must be checked — `require_once wp-login.php` is output-producing.
- Verify the core `wp_robots` meta tag is actually present on `/sn-login` FIRST (curl). If it's somehow absent on the custom path, that's a more important finding than the header addition.
- Small enough to fold into v4.6.0 (as a workstream) or ship as a focused patch — decide at BC. Honors space-out: captured now, no version bump, no build.
