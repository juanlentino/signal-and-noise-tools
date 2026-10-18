# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **No redirect hands a stranger the login door.** Right after a slug change, `/wp-admin/customize.php` (core's `auth_redirect()`) and the OpenStation launch URLs (`/openstation`, `/wp-admin/admin.php?page=openstation`) still answered `302` to the new door, because every redirect to `wp-login.php` is rewritten to the masked slug. One guard on `wp_redirect`, after that rewrite, now answers any redirect to the door with the theme's 404 for a visitor who is not logged in, unless the request is already at the door, so logout, lost password and the rest of `wp-login.php`'s own flows keep working. Logged-in users are never stopped. It covers every route at once, including ones a future core or plugin version adds. The cost: the OpenStation app's launch URL now shows a 404 while you are logged out (sign in at the door first). Refusals count in the audit log's `wp_admin_unauth_404`. New `tests/login-redirect-guard.php` (12, red before).

## [2026.10.37] - 2026-10-18 — changing the Login URL moves the login guard with it


- **Changing the Login URL moves the login guard with it: one save, one place.** The sn-login-guard worker guards the door by Cloudflare route, a route is a literal pattern, and it lived in the worker's `wrangler.toml`, so a slug changed in S&N Home went unguarded until someone also edited that file. The worker now declares no routes (wrangler then leaves them alone on deploy), and the new `inc/login-guard-routes.php` owns them through the Cloudflare API. Saving the Login URL adds the apex and www routes for the new door, re-reads them to confirm (an accepted POST alone is not trusted), stores the slug, then removes the old door's routes. A save the guard cannot confirm is refused with Cloudflare's own error, so the door is never left unguarded. Only the guard's door routes are touched: its status route and other workers' routes are left alone. It needs one more grant on the central Cloudflare token, **Zone › Workers Routes › Edit**, now in the token's grant list. New `tests/login-guard-routes.php` (16, red before).


