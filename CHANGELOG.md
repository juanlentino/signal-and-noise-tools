# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **`/notas/` goes to `/notes/` instead of a 404.** The notes are only in English, and a Spanish reader goes to them anyway; a browser translator covers the rest. `/es/notes/` and `/es/notas/` redirect there too. They are permanent redirects from the existing old-paths map, so they fire only when nothing else answers that address, and a deeper `/notas/<something>/` path stays a 404.

## [2026.10.46] - 2026-10-18 — a daily check keeps the strict CSP's script hashes current


- **The CSP hash check now pins the speculation rules too.** With the Report-Only policy live, Chromium 152 still reported the inline speculation rules under a policy that carries `'inline-speculation-rules'`, and named their hash. Enforcing as planned would have silently turned off link prefetching. The check now hashes `<script type="speculationrules">` like the other inline scripts, so a change to the rules turns the `csp_hashes` watch ripe with the new line to paste.

### Added
- **A daily check that the strict CSP pins every inline script (`csp_hashes`).** The theme's planned strict `script-src` (theme `docs/CSP.md`) drops `'unsafe-inline'` and pins the sha256 of three inline scripts: theme-init, core's import map and core's emoji module. The import map changes with every core update, and a stale hash silently stops every script module (the dark-mode toggle, navigation). Once a day the plugin reads `/`, `/notes/`, the newest note, `/provenance/` and `/index/` as a stranger (the login door leak check's fetch: no cookies, no redirects followed, 10s), extracts every executable inline script with WordPress's `WP_HTML_Tag_Processor` (no `src`; type absent, JavaScript, `module` or `importmap`; JSON islands and speculation rules skipped), hashes exactly the bytes between the opening tag's `>` and `</script>`, and reads the strict policy (the `script-src` without `'unsafe-inline'`, enforced over Report-Only) from the response headers. It stores the hashes seen with a label and the pages carrying them, which header was strict, and what is missing, never page bodies, in `sn_csp_hash_last`. The watch is ripe when the strict policy is deployed and lacks a hash (the note gives the exact `script-src` line to paste into the Cloudflare Transform Rule), when most pages could not be read or answered other than 200, when no CSP header arrived at all, or when the check has not run for two days. No strict policy deployed is not ripe. A Site Health direct test, "Strict CSP matches the inline scripts", reads the same stored run and is critical only when the enforced policy lacks a hash. The shared stranger fetch now also returns the response code. `/contact/` is skipped by design.

