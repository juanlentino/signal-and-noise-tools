# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The browser speculation rules no longer publish the login door.** The plugin listed the masked login slug (bare and wildcard) in the rules' "do not prerender" paths, and those rules are printed into every public page, so the hidden door's address sat in the HTML of notes, essays, About, Contact, the 404 page and the Spanish pages (found live by loading every unknown path linked from public pages and checking for the login form). Nothing public links to the door, and speculation rules only act on links on the page, so the exclusion protected nothing; `/contact/*` stays. `tests/speculation-rules.php` and `tests/speculation-rules-unguarded-slug.php` now pin that the slug never appears (red before).

## [2026.10.39] - 2026-10-18 — customize.php answers 404, not a fatal error


- **`/wp-admin/customize.php` answers 404 again, not a fatal 500.** The redirect guard from 2026.10.38 caught core's `auth_redirect()` on `customize.php`, but that redirect runs inside the admin bootstrap, where `get_query_template( '404' )` returns an empty string, so `include ''` threw `ValueError: Path must not be empty` (`login-hide.php:238`): a 500 and a fatal-error email, though nothing leaked. One `sn_login_send_404()` now serves every 404 in the login module. It uses the theme's 404 template when WordPress can resolve one and a bare "Not Found" page when it cannot. The decoy 404s for `/wp-login.php` and `/wp-admin/` use it too, so none can hit the same trap. `tests/login-redirect-guard.php` gains 2 checks (red before).


