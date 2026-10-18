# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Three security watches: application passwords, administrator 2FA, and the login guard routes.** They watch and never enforce; LLAR, Two Factor and Cloudflare keep doing that. **`app_passwords`** reads core's application passwords. They are the one standing credential that signs in to the REST API without the login address, Two Factor or a passkey. The watch turns ripe when one was created this week, or one has gone unused for 90 days, and names the user and the app, never the secret. **`admin_2fa`** reads Two Factor, where the WebAuthn provider plugin adds passkeys. It turns ripe when Two Factor is inactive, or an administrator has nothing stronger than email codes or backup codes. **`guard_routes`** reads the Cloudflare routes once a day. It turns ripe when the login address's routes no longer point at the login guard, a retired address's route is left over, Cloudflare cannot be read, or the check has not run for two days. It stores counts only, never route patterns; saving the Login URL again restores and prunes the routes.

- **The 404 a stranger gets at `/wp-admin/` now looks like every other 404.** It was rendered while WordPress still counted the request as admin, and in the admin core leaves out the block stylesheets: the header's navigation collapsed into its overlay menu at desktop width, and the page lacked the `error404` body class. The plugin now switches the request to front-end context before rendering the theme's 404 (`sn_login_front_context()`), so `/wp-admin/`, `/wp-login.php`, customize.php and a random dead link all answer with the same page. That also stops the admin routes from giving themselves away by looking different.

- **The login-address leak check no longer reads a blind run as clean.** If most of the public pages it reads fail to load (for example when the server cannot reach itself), the `login_door_leak` watch now turns ripe and says how many pages it managed to read. Before, a run that read nothing reported clean.

## [2026.10.40] - 2026-10-18 — the login door is published nowhere, and a daily check keeps it so


- **A daily check that the login address is published nowhere.** The owner's rule: the masked login address gets out only if the owner hands it out. Once a day the plugin reads what a stranger reads (home, about, the newest note, a dead link's 404, the feed, the REST index, the sitemap, robots.txt and a search page), bodies and response headers alike, with no cookies and no redirects followed. If any of them contains the login address, the new `login_door_leak` watch turns ripe and names the pages, never the address; the stored result (`sn_login_door_leak_last`) never holds it either. A check that has not run for two days is ripe too, since silence is not a clean result. The job is in the cron dashboard registry and is cleared on deactivation.
- **The browser speculation rules no longer publish the login door.** The plugin listed the masked login slug (bare and wildcard) in the rules' "do not prerender" paths, and those rules are printed into every public page, so the hidden door's address sat in the HTML of notes, essays, About, Contact, the 404 page and the Spanish pages (found live by loading every unknown path linked from public pages and checking for the login form). Nothing public links to the door, and speculation rules only act on links on the page, so the exclusion protected nothing; `/contact/*` stays. `tests/speculation-rules.php` and `tests/speculation-rules-unguarded-slug.php` now pin that the slug never appears (red before).


