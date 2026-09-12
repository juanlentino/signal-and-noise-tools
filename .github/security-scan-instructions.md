# Project-specific audit instructions — Signal & Noise Tools

Appended to the security audit prompt. These are findings this codebase has
actually shipped, not hypotheticals.

## 1. `sn_settings` subtree clobber — silent data destruction (CRITICAL, bitten 4×)

`sn_settings_save()` in `inc/settings.php` builds a fresh `$sanitized` array and
ends with a **whole-option replace**:

    return (bool) update_option( SN_SETTINGS_OPTION, $sanitized );

Anything not explicitly re-included in `$sanitized` is **silently wiped** the
next time any tab is saved. There is no error, no notice, and no failing test —
the setting simply ceases to exist, and the tab that owns it renders its default
as though the user had never configured it.

This is an integrity issue, not a style one: it destroys user data on an
unrelated action.

**Flag when a change adds a new `sn_settings` subtree — or a new key inside one —
without re-including it in `sn_settings_save()`.** Saving the Identity tab is the
usual trigger, because that form posts only its own fields.

Two subtrees are already preserved by hand, and they are the pattern to follow:

- `login.slug`, read back via `sn_setting( 'login.slug', … )` before the write
- `audit`, re-included wholesale from `get_option( SN_SETTINGS_OPTION )`

Both carry comments naming this bug. A third subtree that omits the same
treatment is the regression to catch.

Report it even when the new subtree is written by a different file or a
different tab's handler — the clobber happens at save time in `settings.php`,
far from wherever the subtree was introduced, which is why it has recurred.

## 2. The abilities run route is a WRITE surface, and the MCP door is not in front of it

Every ability registered with `meta.show_in_rest => true` is reachable at
`POST /wp-abilities/v1/abilities/<slug>/run` with **any** `manage_options`
application password. That includes every slug on the rw MCP door and the
pre-consolidation apply abilities (`block-migrations-apply`,
`pattern-adoption-apply`, `ai-*-apply`, `update-post-surfaces`). The rw door at
`/signal-noise/v1/mcp-rw` — kill switch, bound credential, rate limit, audit
row (`inc/mcp/mcp-rw-guard.php`, `inc/mcp/mcp-rw-audit.php`) — does **not** sit
on that path. The 2026-09-11 enforcement audit found the route unguarded.

The control is `sn_mcp_rw_guard_run_route()` (`inc/mcp/mcp-rw-guard.php`),
on `rest_pre_dispatch`: for a request that authenticated by application
password against an ability that is not annotated `readonly`, it applies the
door's four controls in the door's order. Cookie+nonce requests from
wp-admin's own buttons (`assets/snt-ability-run.js`) use the same route and
must pass untouched — that is the negative assertion in
`tests/mcp-rw-guard-run-route.php`.

**Flag when a change:**

- registers a mutating ability without a `readonly => false` / `destructive`
  annotation (the guard keys on `readonly` for slugs OFF the rw allowlist; an
  unannotated write is treated as a write, but an ability wrongly annotated
  `readonly => true` walks past it unless it is on `sn_mcp_rw_allowlist()`,
  which the guard checks first — `tests/mcp-capabilities.php` pins that every
  read-door slug is `readonly => true` and names the rw-door exceptions);
- adds a `rest_pre_dispatch` filter at priority < 10 on the abilities
  namespace, or returns a non-null result before the guard runs;
- adds a filter on `sn_mcp_rw_allowlist` / `sn_mcp_allowlist` /
  `sn_apply_granted_modes` (all three widen a door at runtime);
- registers a new REST route with `permission_callback => '__return_true'`
  (two exist by design: the webmention receiver and the VC fetch — a third
  needs the same argument written down);
- reads `rest_get_authenticated_app_password()` and treats a null as "the
  owner" rather than "no application password".

A Cloudflare WAF rule ("Block Basic-auth on abilities API") also targets
`Authorization`-bearing requests to `/wp-abilities/` at the edge. It lives in
the dashboard, not in this repo; it is confirmed present, Active, and measured
in force from an external host (2026-09-12, both URL spellings). It does not
refuse the origin's own requests, so `inc/health-check-cf-security-headers.php`
reads a Better Stack witness monitor instead of probing it. It is gone on any
direct-to-origin path. Do not credit it as the control — the in-plugin guard
above is.
