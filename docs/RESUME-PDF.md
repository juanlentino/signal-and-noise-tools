# Resume PDF: the generator

The PDF behind the /resume Download link is built from the same document as the page, so it can
never drift from it again. The theme's `docs/RESUME-PDF.md` covers Phase 1 (browser print); this is
Phase 2.

## How to regenerate

1. S&N → Content → Resume. Edit and **Save resume** as usual.
2. The **PDF only** section holds what the PDF shows and the page never does: headline, tagline,
   location, phone, email, core competencies (one per line), technical toolkit (one per line), and
   the switch **Include the phone in the public PDF** (off by default).
3. **Generate PDF** (the Resume PDF box below the form). It renders the SAVED document, writes
   `uploads/resume/JuanLentino_Resume.pdf`, stores the timestamp, size, page count and SHA-256 in
   the `sn_resume_pdf` option, re-renders /resume so the Download link reads
   `…/resume/JuanLentino_Resume.pdf?v=<first 8 of the hash>`, and purges caches exactly as
   **Purge All Caches** does.

Until the first generation, the Download link keeps using the **PDF URL** field in Hero.

## The phone

The owner does not want the phone downloadable by the public (2026-09-22), and wants that under his
control in the resume editor:

- **The web page never shows it.** The sync engine does not read `pdf`.
- **The public PDF** (Generate PDF, the Download link) includes it only when **Include the phone in
  the public PDF** is on. Off by default; an unchecked box posts nothing, so absent means off.
- **Download private copy (with phone)** builds the same PDF with the phone and streams it to the
  requesting admin as an attachment (`no-store`, `noindex`). It is never written to disk, so it has
  no URL anyone else can fetch. Own nonce (`sn_resume_pdf_private`), `manage_options`.
- Turning the switch off takes effect at the next **Generate PDF**; the file already published
  keeps whatever it was built with until then.

Generation is manual on purpose. Auto-regenerating on every save was offered as optional in the
brief and not wired: a save is frequent and cheap, a render is neither, and the owner chooses when
the public file changes.

## Data sources

| PDF section | Source in `sn_resume_doc` |
|---|---|
| Name | the site title (`get_bloginfo( 'name' )`, filter `sn_resume_pdf_name`) |
| Headline, tagline, contact line | `pdf.headline`, `pdf.tagline`, `pdf.location`, `pdf.email`, `hero.linkedin`; `pdf.phone` only per `pdf.phone_public` (public) or always (private copy) |
| Professional summary | `hero.summary` |
| Stats band | `stats[]` |
| Core competencies | `pdf.competencies[]` (three columns) |
| Professional experience | `experience[]` then `earlier.entries[]`; a role line "Title · dates" is split into title and dates, an earlier entry's "ORG · City" into org and location |
| Research & publications | `publications[]` |
| Education, Affiliations & certifications | `education[]`, `affiliations[]` (title, then its lines, separated by bars) |
| Technical toolkit | `pdf.toolkit[]` |

`pdf` is a top-level key the /resume sync engine never reads, so no PDF-only value (the phone
above all) can reach the page. `tests/resume-pdf-page-invariance.php` pins that, and that the only
change to /resume is the Download link's URL.

## Renderer: Dompdf

- **Why:** pure PHP, runs on Cloudways PHP 8.4 with no binary, writes real selectable text
  (applicant tracking systems read the text layer). Headless Chrome is not guaranteed on the host
  and would be new infrastructure.
- **Where:** `lib/pdf/`, its own Composer project (`dompdf/dompdf` ^3.1, platform PHP 8.3). Its
  `vendor/` is **committed**: the self-updater installs the tag archive and the root `vendor/` is
  gitignored (now `/vendor/`, root only). `lib/pdf/composer.json` and `.lock` are export-ignored.
  A post-install script prunes DejaVu Serif and Mono; DejaVu Sans stays as the fallback for
  glyphs Lato lacks (the competency diamond, U+25C6).
- **Font:** Lato (SIL OFL 1.1, `lib/pdf/fonts/OFL.txt`), embedded and subset in the PDF only.
  No font family is added to the theme.
- **Safety:** `isRemoteEnabled` and `isPhpEnabled` are off; `chroot` is `lib/pdf` plus the font
  cache. The template loads nothing remote.
- **Design:** the owner's navy (`#1f3864`) and gold (`#c9a227`) rebrand, in
  `inc/resume-pdf/template.php` only. Block flow and tables, no flex or grid.

To update Dompdf: `cd lib/pdf && composer update`, then run `php tests/resume-pdf.php`.

## Tests

- `tests/resume-pdf.php` (26): the phone is absent from the public PDF's bytes while the switch is off and present in the private copy's (both read back with pdftotext), Generate follows the switch, the private copy streams and never touches disk; and it renders the fixture through the real renderer; `%PDF-`, two pages or
  fewer, Letter, Lato embedded, no images, Title and Author set, key strings (read back with
  `pdftotext` when installed), remote loading off, stable path, `?v=` hash, atomic write, purge,
  the dispatcher's nonce and `manage_options`.
- `tests/resume-pdf-page-invariance.php` (17): /resume is byte-identical with the PDF fields filled
  and before generation; after generation only the Download URL differs.
- `tests/os-leaf-content-resume.php`: the dashboard leaf and the classic form post the same fields,
  including `pdf[…]`, and both carry the separate Generate PDF form.

## Known limits

- Two pages is a property of today's content, pinned by the test fixture. A much longer resume
  will render a third page; the generator reports the count in the admin box.
- The page-count and text read-back tests use the fixture, not the live option.
- The stats band shows whatever `stats[]` holds; keep it consistent with the summary.
