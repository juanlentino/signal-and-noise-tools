# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.40] - 2026-10-18 — the login door is published nowhere, and a daily check keeps it so


- **A daily check that the login address is published nowhere.** The owner's rule: the masked login address gets out only if the owner hands it out. Once a day the plugin reads what a stranger reads (home, about, the newest note, a dead link's 404, the feed, the REST index, the sitemap, robots.txt and a search page), bodies and response headers alike, with no cookies and no redirects followed. If any of them contains the login address, the new `login_door_leak` watch turns ripe and names the pages, never the address; the stored result (`sn_login_door_leak_last`) never holds it either. A check that has not run for two days is ripe too, since silence is not a clean result. The job is in the cron dashboard registry and is cleared on deactivation.
- **The browser speculation rules no longer publish the login door.** The plugin listed the masked login slug (bare and wildcard) in the rules' "do not prerender" paths, and those rules are printed into every public page, so the hidden door's address sat in the HTML of notes, essays, About, Contact, the 404 page and the Spanish pages (found live by loading every unknown path linked from public pages and checking for the login form). Nothing public links to the door, and speculation rules only act on links on the page, so the exclusion protected nothing; `/contact/*` stays. `tests/speculation-rules.php` and `tests/speculation-rules-unguarded-slug.php` now pin that the slug never appears (red before).


