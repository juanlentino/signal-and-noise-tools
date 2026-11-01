# Resume PDF: the generator

The PDF behind the /resume Download link is built from the same document as the page, so it can
never drift from it again. The theme's `docs/RESUME-PDF.md` covers Phase 1 (browser print); this is
Phase 2.

## How to regenerate

1. S&N → Content → Resume. Edit and **Save resume** as usual.
2. The **PDF only** section holds what the PDF shows and the page never does: headline, tagline,
   professional summary (blank: the Hero summary), location, phone, email, website (blank: this site), core competencies (one per line), technical
   toolkit (one per line), and the checkbox **Include the phone in the public PDF** (off by default).
   It is a checkbox, never an `os-switch`: os-form reads only checkboxes as booleans, so a switch
   would post `1` in both positions and publish the phone on every save.
3. **Generate PDF** (the Resume PDF box below the form). It renders the SAVED document, writes
   `uploads/resume/JuanLentino_Resume.pdf`, stores the timestamp, size, page count and SHA-256 in
   the `sn_resume_pdf` option, re-renders /resume so the Download link reads
   `…/resume/JuanLentino_Resume.pdf?v=<first 8 of the hash>`, and purges only what changed: the
   page save purges /resume/ (Cloudflare per URL, Breeze through its own save handler), and the
   file's bare URL plus every `?v=` URL issued so far go through the per-URL Cloudflare purge.
   It no longer runs **Purge All Caches**.

Until the first generation, the Download link keeps using the **PDF URL** field in Hero.

## Two résumés from one document (2026-11-01)

The document builds two résumés: **music** (the /resume page, its Download link, the Spanish CV,
JSON Resume, Markdown and vCard; the public one) and **operations** (its own PDF, never linked).
`inc/resume-variants.php` holds the rules; `sn_resume_doc_get()` returns the music view in the shape
every reader already knew, and the editor reads the stored document through `sn_resume_doc_get_raw()`.

- **Shows in.** Every experience and earlier entry, role, education and affiliation row,
  publication and skills row has a "Shows in" choice: both (the default, and what every row had
  before), music only, or operations only. Under each role, "Which résumé each bullet shows in"
  lists its saved bullets with the same choice; the flag is keyed by the bullet's text, so
  reordering bullets never moves it, and a bullet whose text changes goes back to both.
- **What I've Built.** An intro line and one card per project (title, link, one or two
  sentences). The operations PDF shows it; the music PDF only with "Show What I've Built in the
  music PDF" (PDF only). Never on /resume.
- **Operations résumé.** Headline, subhead, location, extra contact links (after the website),
  summary, the section order (one key per line from `summary, stats, competencies, built,
  experience, publications, education, affiliations, skills, toolkit`; a key left out is a section
  left out), the experience order (organizations by name; the rest follow as listed), earlier career
  one line per role, and the file name. Blank fields fall back to the music PDF's. The phone is
  never printed in it.
- **Set up the operations résumé** (Content → Resume) writes the operations résumé's starting
  content (the owner brief's appendix) into the **draft**: its settings, What I've Built, its four
  skills rows and two affiliations, and "music only" on what it leaves out. It finds rows by the
  start of their text, never rewords them, lists anything it cannot find (those stay in both), and
  running it twice changes nothing more.
- **Generate PDF** builds both: the music file as before, then
  `uploads/resume/JuanLentino_Resume_Operations.pdf` (the `sn_resume_pdf_operations` option holds its
  facts). The music PDF's HTML is identical to what it was (pinned by `tests/resume-variants.php`).

**The check on every generation** (`inc/resume-pdf/text-check.php`). Each PDF's text is read back by
a built-in reader for Dompdf's CPDF output (inflate the streams, read each font's ToUnicode map,
decode the text operators; held word for word to `pdftotext` by `tests/resume-pdf-text-check.php`),
and the PDF box flags control characters (U+0000 to U+001F but tab, newline and form feed), ligature
code points (U+FB00 to U+FB06), a phone number in the text or the metadata, an operations PDF over
two pages, and an operations file without `X-Robots-Tag: noindex`.

**The operations file's noindex.** The file is static, served by the web server and Cloudflare, so
WordPress cannot set its headers. One Cloudflare rule does: Rules → Transform Rules → **Modify
Response Header**, when `http.request.uri.path eq "/wp-content/uploads/resume/JuanLentino_Resume_Operations.pdf"`,
**Set static** `X-Robots-Tag` = `noindex, nofollow`. Every generation reads the header back and flags
it while it is missing. The file is in no sitemap, llms file or format list, and no page links it.

## The phone

The owner does not want the phone downloadable by the public (2026-09-22), and wants that under the
owner's control in the resume editor:

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

## Where resume content lives

One document, `sn_resume_doc` (an option), edited in the resume form: S&N > Content > Resume Page
in OpenStation, or the classic tab. It is the single source for BOTH the /resume page (rebuilt from
it on every form save and every PDF generation) and this PDF. Edit the form, never the page: a
resync overwrites page edits. Keep the stat at 15+, and add any schema award to the copy too
(`tests/resume-integrity.php`). The seed (`inc/seed-content/resume-data.json`) only fills a fresh
install; it is kept in step with the live document so the tests read current copy.

## Data sources

| PDF section | Source in `sn_resume_doc` |
|---|---|
| Name | the site title (`get_bloginfo( 'name' )`, filter `sn_resume_pdf_name`) |
| Headline, tagline, contact line | `pdf.headline`, `pdf.tagline`; location = `pdf.location` else `hero.contact_line`; `pdf.phone` only per `pdf.phone_public` (public) or always (private copy); `pdf.email`; `hero.linkedin`; site = `pdf.website` else the home URL |
| Professional summary | `pdf.summary`, else `hero.summary` |
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
- `tests/resume-integrity.php`: the rendered page has no space before punctuation, every Person
  `award` appears in the visible copy, and the years figure is 15+ everywhere.
- `tests/resume-variants.php`: the stored flags, the music and operations views, the setup (the
  music résumé unchanged by it, idempotent, misses reported), the music PDF HTML unchanged, the
  operations PDF's order, its missing sections, its headline and links, and no phone.
- `tests/resume-pdf-text-check.php`: the built-in reader against pdftotext, and every flag.
- `tests/os-leaf-content-resume.php`: the dashboard leaf and the classic form post the same fields,
  including `pdf[…]`, and both carry the separate Generate PDF form.

## Known limits

- Two pages is a property of today's content, pinned by the test fixture. A much longer resume
  will render a third page; the generator reports the count in the admin box.
- The page-count and text read-back tests use the fixture, not the live option.
- The stats band shows whatever `stats[]` holds; keep it consistent with the summary.
