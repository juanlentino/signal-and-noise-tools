# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Core stops going blank every evening.** After 20.3.1 the refill ran after an install (23:25 UTC on 2026-10-01), yet Core read "update check not cached" again by 00:14 UTC. Breeze's nightly purge (00:00 UTC) fires `breeze_clear_varnish`, which reaches the Cloudways app purge, documented to clear Redis as well, and nothing of ours hears that flush; Core then stayed blank until WordPress's 10:50 UTC check (about 20:00 to 06:50 in Orlando). Rather than hook each emptier, the 5-minute warm pass now runs `snt_core_refill_guard()` first: when `update_core` is missing and no refill is queued, it queues the same one-off `wp_version_check()`, at most once an hour (24 wordpress.org calls a day at worst; the stamp is an option, so a Redis flush cannot reset it). Expect the question mark to clear within about ten minutes of any purge. Pinned in `tests/core-fingerprint.php` (present schedules nothing, missing queues once, a second pass inside the hour does not, red with the hour check removed).

## [20.3.2] - 2026-10-01 — the resume PDF opens everywhere


- **The resume PDF opens in pypdf, so job sites that read uploads with it accept it.** Icebreaker answered "We couldn't read that PDF" on 2026-10-01. The cause is Dompdf: in justified text with a Unicode font, `Cpdf::addText()` wrote each word gap as `\x00\x20)\x00\x20-N\x00\x20(`, NUL bytes outside the strings, and pypdf (a common Python reader behind upload parsers) refuses the whole file on them; pdf.js, poppler, PyMuPDF and pdfplumber skip them, which is why other sites took it. The vendored `lib/pdf/vendor/dompdf/dompdf/lib/Cpdf.php` now writes the gap as plain ASCII (`) -N (`), unchanged on Dompdf's master, so a bump must keep the patch: `tests/resume-pdf.php` inflates the rendered streams and turns red if the NUL form returns. Measured on the live file: the repaired bytes render pixel-identical on both pages and pypdf reads them. The PDF Title is now "Juan Lentino, Resume" (no em dash). **After installing, press Generate on the resume admin** so the stored PDF is rebuilt; the stable file does not regenerate on install.


