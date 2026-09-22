# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.7.0] - 2026-09-22 — the resume PDF

### New
- **Generate the resume PDF from the resume itself.** S&N → Content → Resume gains a **Generate PDF** button (its own form, nonce `sn_resume_pdf_generate`, `manage_options` through the dispatcher). It renders the saved resume with Dompdf into the owner's navy and gold design, writes `uploads/resume/JuanLentino_Resume.pdf` atomically, stores the timestamp, size, page count and SHA-256 in `sn_resume_pdf`, points the /resume Download link at `…?v=<hash prefix>`, and purges caches the way Purge All Caches does. The hand-made PDF had drifted from the page (old skills, two papers, "20+ years"); this one cannot. Until the first generation the link keeps the hand-set URL. `docs/RESUME-PDF.md`.
- **A "PDF only" section in the resume editor**: headline, tagline, location, phone, email, core competencies and technical toolkit, in both the classic form and the dashboard leaf. They live under a top-level `pdf` key the /resume sync engine never reads, so none of them (the phone above all) can reach the page.
- **The phone is under the owner's control, and off the public PDF by default.** A switch in the same section, **Include the phone in the public PDF**, decides whether Generate PDF prints it (an unchecked box posts nothing, so absent means off). **Download private copy (with phone)** streams the same PDF with the phone to the requesting admin, never written to disk, so it has no public URL (owner, 2026-09-22: "I don't want people to be able to download my resume with the phone at all").

### Changed
- **/resume looks exactly as it did.** The only change on the page is the Download link's URL once a PDF is generated; label, button and every other byte are the same. Pinned by `tests/resume-pdf-page-invariance.php`, which fails on a leaked field or any other change (both mutations tried).
- **Dompdf ships inside the plugin**, in `lib/pdf/` with its `vendor/` committed, because the self-updater installs the tag archive. `.gitignore`'s `vendor/` is now `/vendor/` (it silently ignored `lib/pdf/vendor`); `lib/pdf`'s Composer files are export-ignored; Plugin Check, the security review and the syntax lint skip `lib/pdf/vendor`; PHPStan scans Dompdf for class discovery only. Lato (SIL OFL) is embedded in the PDF only. 7 MB after pruning unused DejaVu faces.

### Tests
- **`tests/resume-pdf.php` (26)** pins the phone: absent from the public PDF's bytes while the switch is off, present in the private copy's, Generate follows the switch, the private copy never touches disk (falsified: forcing the phone public, and a template that ignores the flag, each fail). It also renders fixture data through the real renderer: a PDF, two pages or fewer, Letter, Lato embedded, no images, Title "Juan Lentino — Resume" and Author set, key strings read back with pdftotext, remote loading off, the stable path, the hash, the atomic write, the purge, the nonce and capability. Falsified (a three-page fixture, remote loading on).
- **`tests/resume-pdf-page-invariance.php` (17)**: see Changed. The dashboard leaf's parity suite now expects the second form and the `pdf[…]` fields; the section count is nine and the admin-post map 68 (resume_pdf_generate, resume_pdf_private).

