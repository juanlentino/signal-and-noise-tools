# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **`/wp-admin/customize.php` answers 404 again, not a fatal 500.** The redirect guard from 2026.10.38 caught core's `auth_redirect()` on `customize.php`, but that redirect runs inside the admin bootstrap, where `get_query_template( '404' )` returns an empty string, so `include ''` threw `ValueError: Path must not be empty` (`login-hide.php:238`): a 500 and a fatal-error email, though nothing leaked. One `sn_login_send_404()` now serves every 404 in the login module. It uses the theme's 404 template when WordPress can resolve one and a bare "Not Found" page when it cannot. The decoy 404s for `/wp-login.php` and `/wp-admin/` use it too, so none can hit the same trap. `tests/login-redirect-guard.php` gains 2 checks (red before).

## [2026.10.38] - 2026-10-18 — no redirect hands a stranger the login door


- **No redirect hands a stranger the login door.** Right after a slug change, `/wp-admin/customize.php` (core's `auth_redirect()`) and the OpenStation launch URLs (`/openstation`, `/wp-admin/admin.php?page=openstation`) still answered `302` to the new door, because every redirect to `wp-login.php` is rewritten to the masked slug. One guard on `wp_redirect`, after that rewrite, now answers any redirect to the door with the theme's 404 for a visitor who is not logged in, unless the request is already at the door, so logout, lost password and the rest of `wp-login.php`'s own flows keep working. Logged-in users are never stopped. It covers every route at once, including ones a future core or plugin version adds. The cost: the OpenStation app's launch URL now shows a 404 while you are logged out (sign in at the door first). Refusals count in the audit log's `wp_admin_unauth_404`. New `tests/login-redirect-guard.php` (12, red before).


