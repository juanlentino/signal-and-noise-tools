# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **The resume PDF opens in pypdf, so job sites that read uploads with it accept it.** Icebreaker answered "We couldn't read that PDF" on 2026-10-01. The cause is Dompdf: in justified text with a Unicode font, `Cpdf::addText()` wrote each word gap as `\x00\x20)\x00\x20-N\x00\x20(`, NUL bytes outside the strings, and pypdf (a common Python reader behind upload parsers) refuses the whole file on them; pdf.js, poppler, PyMuPDF and pdfplumber skip them, which is why other sites took it. The vendored `lib/pdf/vendor/dompdf/dompdf/lib/Cpdf.php` now writes the gap as plain ASCII (`) -N (`), unchanged on Dompdf's master, so a bump must keep the patch: `tests/resume-pdf.php` inflates the rendered streams and turns red if the NUL form returns. Measured on the live file: the repaired bytes render pixel-identical on both pages and pypdf reads them. The PDF Title is now "Juan Lentino, Resume" (no em dash). **After installing, press Generate on the resume admin** so the stored PDF is rebuilt; the stable file does not regenerate on install.

## [20.3.1] - 2026-10-01 — Core comes back after an install


- **Core reads ✓ again right after an install.** On this site WordPress's `update_core` check lives in the persistent object cache, and the theme's full purge (which runs after every update) calls `wp_cache_flush()`, emptying it just after WordPress refilled it; the Core row then read "update check not cached" until the next twice-daily check. The plugin now hooks `sn_after_full_cache_flush` and, when the object cache was flushed, schedules one `wp_version_check()` a minute later through cron, so the purge request never waits on wordpress.org and `snt_core_status()` stays read-only. Pinned in `tests/core-fingerprint.php` (hooked, skipped when the object cache was not flushed, scheduled once, runs the check).


