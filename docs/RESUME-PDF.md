# Resume PDF: the generator

The PDF behind the /resume Download link is built from the same document as the page, so it can
never drift from it again. The theme's `docs/RESUME-PDF.md` covers Phase 1 (browser print); this is
Phase 2.

## How to regenerate

1. S&N → Content → Resume. Edit, **Save draft**, then **Publish**.
2. The **PDF only** section holds the PDF's own fields: headline, tagline,
   professional summary (blank: the Hero summary), location, phone, email, website (blank: this site), core competencies (one per line), technical
   toolkit (one per line). The phone is stored and never printed (see "The phone" below).
   These are the music PDF's; /resume shows the Operations résumé's headline and subhead instead
   (see "One résumé on /resume").
3. **Generate PDF** (the Resume PDF box below the form). It renders the published document, writes
   `uploads/resume/JuanLentino_Resume.pdf`, stores the timestamp, size, page count and SHA-256 in
   the `sn_resume_pdf` option, re-renders /resume so the Download link reads
   `…/resume/JuanLentino_Resume.pdf?v=<first 8 of the hash>`, and purges only what changed: the
   page save purges /resume/ as any page save does (the cache-tag purge, or the per-URL list with
   an older theme; Breeze through its own save handler), and the
   file's bare URL plus every `?v=` URL issued so far go through the per-URL Cloudflare purge.
   It no longer runs **Purge All Caches**.

Until the first generation, the Download link keeps using the **PDF URL** field in Hero.

## Two résumés from one document (2026-11-01)

The document builds two views: **music** (the music PDF and the Spanish CV) and **operations**
(since 2026-11-12 the one /resume, its PDF, JSON Resume, Markdown and vCard; see "One résumé on
/resume" below).
`inc/resume-variants.php` holds the rules; `sn_resume_doc_get()` returns the music view in the shape
every reader already knew, and the editor reads the stored document through `sn_resume_doc_get_raw()`.

- **On /resume** (until 2026-11-12 "Shows in"). Every experience and earlier entry, role, education and affiliation row,
  publication and skills row has a choice: Shown (stored '', the default, and what every row had
  before), Hidden (stored `music`: the music PDF keeps it), or "Shown, not on the music PDF" (stored
  `operations`). Under each role, each saved bullet has the same choice; the flag is keyed by the bullet's text, so
  reordering bullets never moves it, and a bullet whose text changes goes back to both.
- **What I've Built.** An intro line and one card per project (title, link, one or two
  sentences). The operations PDF shows it; the music PDF only with "Show What I've Built in the
  music PDF" (PDF only). On /resume it shows while "built" is in the Sections list.
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
  running it again only fills what is missing and never undoes an edit.
- **Generate PDF** builds both: the music file as before, then the operations PDF, served at
  `/resume/JuanLentino_Resume_Operations.pdf` (the `sn_resume_pdf_operations` option holds its facts,
  `sn_resume_pdf_operations_bytes` the file). The music PDF's HTML is identical to what it was
  (pinned by `tests/resume-variants.php`).

**The check on every generation** (`inc/resume-pdf/text-check.php`). Each PDF's text is read back by
a built-in reader for Dompdf's CPDF output (inflate the streams, read each font's ToUnicode map,
decode the text operators; held word for word to `pdftotext` by `tests/resume-pdf-text-check.php`),
and the PDF box flags a PDF whose text could not be read at all or reads under 1,000 characters,
control characters (U+0000 to U+001F but tab, newline and form feed), ligature
code points (U+FB00 to U+FB06), a phone number in the text or the metadata, an operations PDF over
two pages, and an operations file without `X-Robots-Tag: noindex`.

**The operations file's noindex.** A static file's headers belong to the web server, and the owner
was unsure a Cloudflare rule was available for one (2026-11-01), so the operations PDF is not a file in
uploads. Its bytes live in an option, and WordPress serves them at `/resume/<file name>` with
`X-Robots-Tag: noindex, nofollow` (`sn_resume_ops_pdf_maybe_serve()`); before the first generation
that address is the ordinary 404. Every generation reads the header back and flags it if missing.
The address is in no sitemap. Since 2026-11-12 it is /resume's only Download, and the format
list and llms.txt name it.

## One résumé on /resume (owner, 2026-11-12)

The two lanes (2026-11-01, brief #2) are gone: readers had to notice the switch to use it, and two
résumés were two things to keep straight. Once the operations settings exist, `sn_resume_sync_page()`
writes the English /resume through `sn_resume_page_body()` (`inc/resume-page-lanes.php`) as one body:
a head band (eyebrow and the page title), then the bands of `sn_resume_lane_doc( $raw, 'operations' )`,
numbered as they fall, each h2 anchored `#resume-<band>`. That document is the operations view with
its headline, subhead, location and summary, its experience order, earlier career without bullets
when it asks, only the sections its Sections list names, and "What I've Built" as `built`. Rows the
owner wants on /resume are Shown; Hidden rows stay on the music PDF.
The stored document is unchanged, so the music view still exists.

- **The hero** carries the optional **kicker** (`variants.operations.kicker`, a small line straight
  over the headline), the headline as an h2, the subhead, the summary; on the right the chips, the
  contact rail with the extra links, the line "Verified credits: juanlentino.com/music" (only while
  /music is published, `sn_resume_credits_url()`), and the one Download.
- **One PDF**: the operations build, printing and linking `juanlentino.com/resume` and the
  verified-credits line, Info Title "Juan Lentino · Résumé". The music PDF is still built for the
  record but nothing on /resume, in the format list or in llms.txt links it.
- **`?lane=` means nothing.** Every address shows the same body; the canonical and `og:url` are
  `/resume/`; the formats ignore it too: `/resume.md`, `/resume/json-resume.json` and
  `/resume/contact.vcf` are the one résumé, with no `lane:` / `lanes:` / `meta.x-lane(s)`. The JSON
  Resume lists /music in `basics.profiles` as `{"network":"Verified credits"}`. The one résumé never
  links the vCard (owner, 2026-11-02); the route still answers. `tests/resume-one.php`,
  `tests/resume-head.php`.
- **The description** of /resume (meta, og, twitter, ProfilePage) is the first sentence of the
  résumé's summary, verbatim (`inc/resume-page-description.php`): the page's own was written for the
  music résumé.
- **An update re-renders the page once**, and /cv with it (`SN_RESUME_ENGINE_REV` 7 in
  `inc/split-hero-migration.php`). Leaving rev 6 also purges the old lane addresses once
  (`SN_CF_RESUME_LANE_URLS_RETIRED`); they are off the per-save purge list.
- **Spanish and no operations settings** keep `sn_resume_body_html()`, the single body, unchanged.
- **Analytics**: the owner-only "Résumé lanes" panel keeps its history with a dated note; from
  2026-11-12 its lane field means nothing.
- **Receipts** (`inc/resume-receipts.php`): `credential_url` on education and affiliation rows shows
  `verify`, a publication's `url` is its title's link (no separate mark since the layout pass), a
  "What I've Built" `url` shows `code` after the project's text, never inside its heading; experience
  and earlier entries take `receipt_url` + `receipt_type` (an employer's shows at the end of its date
  and place line, never inside its heading), and roles `bullet_receipts` keyed like
  `bullet_variants`. `credential_url`, `receipt_url` and `bullet_receipts` take https only; a
  publication's and a project's `url` take http or https. The English page shows them; the PDFs never
  do.

## The phone

Owner, 2026-11-02: "The phone shouldn't be in any version: human and machine." So:

- **No PDF prints it**: not the music PDF, the operations PDF, the Spanish CV or the draft preview.
  The template has no phone in its contact line, and the include-phone argument of
  `sn_resume_pdf_html()` and `sn_resume_pdf_render()` is ignored (the slot stays so positional
  callers keep their meaning). The private copy (with phone) and the "Include the phone in the public
  PDF" switch are gone; `pdf.phone_public` may still be stored, and nothing that builds a PDF or a
  page reads it.
- **The web pages never show it.** The sync engine does not read `pdf`, so /resume, /cv and the hero
  rail have no phone; the identity schema (JSON-LD) carries no `telephone`.
- **The agent copies never carry it**: `/resume.md`, the JSON Resume, `/cv.md`,
  `/cv/json-resume.json` and `/resume/contact.vcf` have no phone field (`sn_jsonresume_basics()`
  builds none, and the Markdown and the vCard read it).
- **`pdf.phone` stays stored, never output.** It exists only as the tripwire's input: every render
  is read back (`sn_resume_pdf_check()`), and one with a phone pattern in its text or metadata, or
  the stored phone's digits (7 or more), is refused (`sn_resume_pdf_phone`): no file is written, no
  stream is sent, the error is stored and the PDF box shows it. An operations PDF refused this way
  also withdraws the file served before it and purges its address.
- A file published before this rule keeps what it was built with until the next **Generate PDF**.

A draft save never renders a PDF: a save is frequent and cheap, a render is neither. **Publish**
and **Revert** rebuild the public PDF, the operations one with it, once a PDF exists
(`sn_resume_refresh_outputs()`), so the file never lags the page; before the first **Generate PDF**,
nothing is built.

## Where resume content lives

One document, `sn_resume_doc` (an option), edited in the resume form: S&N > Content > Resume Page
in OpenStation, or the classic tab. It is the single source for BOTH the /resume page (rebuilt from
it on Publish, Revert and every PDF generation; a form save stores a draft) and this PDF. Edit the
form, never the page: a resync overwrites page edits. Keep the stat at 15+, and add any schema award to the copy too
(`tests/resume-integrity.php`). The seed (`inc/seed-content/resume-data.json`) only fills a fresh
install; it is kept in step with the live document so the tests read current copy.

## Data sources

| PDF section | Source in `sn_resume_doc` |
|---|---|
| Name | the site title (`get_bloginfo( 'name' )`, filter `sn_resume_pdf_name`) |
| Headline, tagline, contact line | `pdf.headline`, `pdf.tagline`; location = `pdf.location` else `hero.contact_line`; `pdf.email`; `hero.linkedin`; site = `pdf.website` else `/resume/` (the operations PDF always `/resume/`), printed without its scheme; the operations PDF adds "Verified credits: …/music" |
| Professional summary | `pdf.summary`, else `hero.summary` |
| Stats band | `stats[]` |
| Core competencies | `pdf.competencies[]` (three columns) |
| Professional experience | `experience[]` then `earlier.entries[]`; a role line "Title · dates" is split into title and dates, an earlier entry's "ORG · City" into org and location |
| Research & publications | `publications[]` |
| Education, Affiliations & certifications | `education[]`, `affiliations[]` (title, then its lines, separated by bars) |
| Technical toolkit | `pdf.toolkit[]` |

`pdf` is a top-level key the single /resume body never reads; the one-résumé body reads only
`pdf.headline` and `pdf.tagline` (the operations ones), and its document blanks the phone, so the phone cannot reach
the page. `tests/resume-pdf-page-invariance.php` pins the single body (the only change to it is the
Download link's URL); `tests/resume-one.php` pins the one-résumé body by sha, and that it is
the same with the phone set and public.

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

- `tests/resume-no-phone.php`: with the phone stored and `phone_public` on, no PDF text layer (read back with pdftotext when installed), no page body and no JSON-LD carries it, and a phone in the résumé text stops every PDF.
- `tests/resume-pdf.php`: the draft preview streams and never touches disk; and it renders the fixture through the real renderer; `%PDF-`, two pages or
  fewer, Letter, Lato embedded, no images, Title and Author set, key strings (read back with
  `pdftotext` when installed), remote loading off, stable path, `?v=` hash, atomic write, purge,
  the dispatcher's nonce and `manage_options`.
- `tests/resume-pdf-page-invariance.php` (18): /resume is byte-identical with the PDF fields filled
  and before generation; after generation only the Download URL differs.
- `tests/resume-integrity.php`: the rendered page has no space before punctuation, every Person
  `award` appears in the visible copy, and the years figure is 15+ everywhere.
- `tests/resume-variants.php`: the stored flags, the music and operations views, the setup (the
  music résumé unchanged by it, idempotent, misses reported), the music PDF HTML unchanged, the
  operations PDF's order, its missing sections, its headline and links, and no phone.
- `tests/resume-pdf-text-check.php`: the built-in reader against pdftotext, and every flag.
- `tests/resume-one.php`: the one-résumé body (pinned by sha, no switch or marks, the kicker, the
  verified-credits line, parse-and-re-save), the receipts, and every format (JSON Resume, Markdown,
  vCard, the format list, the head links) the same whatever `?lane=` says.
- `tests/resume-head.php`: one head on every `?lane=`, canonical and og:url /resume/, the description.
- `tests/resume-ops-pdf-route.php`: the operations PDF's route and its noindex header.
- `tests/os-leaf-content-resume.php`: the dashboard leaf and the classic form post the same fields,
  including `pdf[…]`, and both carry the separate Generate PDF form.

## Known limits

- Two pages is a property of today's content, pinned by the test fixture. A much longer resume
  will render a third page; the generator reports the count in the admin box, and the operations
  check flags one over two.
- Measured 2026-11-02, with each PDF printing its lane address: the seed fixture renders music 2
  pages and operations 2. With the live header strings the music contact line fits on one line
  (348 of 533 pt) and the operations one wraps "github.com/juanlentino" to a second line, one line
  of 8.5 pt type; the live operations PDF's second page has 487 pt free and the music one's 81 pt,
  so both stay at two.
- The page-count and text read-back tests use the fixture, not the live option.
- The stats band shows whatever `stats[]` holds; keep it consistent with the summary.
