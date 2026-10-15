# The bilingual storefront (English / Spanish)

Four storefront pages have Spanish versions, so managers, artists and labels in Latin America can read them in Spanish. Everything else on the site stays English-only: the notes, the provenance hub, the résumé, workflow, uses and the colophon.

| English | Spanish |
|---|---|
| `/` (Page 383, the front page) | `/inicio/` |
| `/about/` | `/sobre-mi/` |
| `/music/` | `/musica/` |
| `/services/` | `/servicios/` |
| `/resume/` | `/cv/` (since 2026-10-15) |

No plugin does this. It is built into the companion plugin (`inc/bilingual*.php`) and the theme (`inc/lang-toggle.php`).

## The model: one meta row

A Spanish page is an ordinary WordPress Page with its own Spanish slug. It has one extra row, `_sn_en_counterpart`, which holds the English Page's id. That row does two things: it marks the page Spanish, and it pairs the page with its English version. Everything below is read from that row.

- English pages carry nothing. Their URLs did not change.
- There is no `/es/` prefix and there are no rewrite rules. WordPress serves `/musica/` like any other Page.
- In the block editor, the row is the **Spanish version of** picker in the Signal & Noise sidebar panel. Set it to the English page on a Spanish page, and leave it at **None** on every English page.

## Where the Spanish copy goes

The pages are CMS pages, and their text is the Page body. **S&N → Content** has no fields for them: its editors cover Now, Uses, Résumé and Workflow only. So the Spanish copy goes into each Spanish Page in the block editor, the same way the English copy goes into the English page.

- **Body.** Write it in the Spanish Page's editor.
- **SEO title and meta description.** Use the Spanish Page's own **SEO title** and **Meta description** fields in the Signal & Noise panel.
- **Release notes on /music** (the Richter stage-manager line). These are under **Connections › Discography › Release notes**, with an English and a Spanish field per release.

## The fallback rule: an empty Spanish field shows the English one

- **Body.** An empty Spanish Page body renders the English page's body, wrapped in `<div lang="en">` so a screen reader reads it in English (`sn_lang_content_fallback()`).
- **Meta description.** When the Spanish page has no override and no excerpt, the English page's description is used (`sn_seo_resolve_singular_description()`). For `/inicio/`, that is **S&N → Settings → SEO copy → home description**.
- **SEO title.** There is no English fallback here, because a Spanish page always has a Spanish title. Without an override, the tab shows "Música — Juan Lentino". Set an SEO title on `/inicio/`, since "Inicio" alone says little.
- **Release notes.** An empty Spanish note shows the English note, marked `lang="en"`.
- **Discography labels** ("releases", "All", "Credits", "Liner notes" and the like) switch to Spanish on `/musica/`. The bridge line under the filters ("A selection. Since 2022 alone…") is the owner's copy, not an interface label, so it stays English and is marked `lang="en"`.

## What each Spanish page gets

| Surface | English page | Spanish page |
|---|---|---|
| `<html lang>` | the site language (`en-US`) | `es-AR` (`language_attributes` filter; the copy is Argentine Spanish, voseo) |
| hreflang | `en`, `es`, `x-default` → English | the same three, reciprocal (the broad `es`, so search offers the page to every Spanish reader) |
| `og:locale` | `en_US` (`identity.locale`) | `es_AR` |
| schema `inLanguage` (WebPage) | `en-US` | `es-AR` |
| canonical | its own URL | its own URL |
| template | its own | the English page's (`page_template_hierarchy`): `/musica/` renders through `page-music.html`, `/inicio/` through `front-page.html` |
| slug gates | `is_page( 'music' )` | `sn_lang_is_page( 'music' )` is also true on `/musica/`, so the music schema, the services schema and the ProfilePage type follow |
| EN / ES toggle | a link to the Spanish page | a link to the English page |

The toggle is the `signal-noise/lang-toggle` block in the theme's header. It renders nothing on a page without a **published** counterpart, so other pages never show it. It is a plain link with `hreflang` and `lang`, and its accessible name keeps the visible "ES" or "EN". It uses no cookie and no storage, and it never redirects based on the browser's language (the site keeps zero device storage and shows no consent banner).

**The header and footer follow the page** (theme `inc/lang-chrome.php`, since theme signal-and-noise#538):
- **Header nav on a Spanish page:** Inicio, Sobre mí, Currículum, Música, Servicios, Notas, Contacto. Each link goes to the published Spanish page where one exists, and to the English page otherwise.
- **Logo:** links to /inicio/.
- **Labels:** the menu, search and theme-toggle labels are in Spanish.
- **Footer meta-nav:** the labels are in Spanish, but the pages they reach (Now, Accessibility, Colophon, Stats, Privacy) stay English. Each label says "(en inglés)" and carries `hreflang="en"`.
- **Site-wide language link:** the footer has one (the same block, `placement: footer`). Once /inicio/ is published, it reads "Español" on every English page, going to that page's translation or else /inicio/, and "English" on a Spanish page.

**A draft translation is invisible.** Until the Spanish page is published, the English page shows no toggle and no hreflang. The four Spanish pages were seeded once as drafts with empty bodies (`sn_lang_seed_pages()`, flag `sn_spanish_pages_seeded_v1`, which also records any slug it skipped). Publish each one when its copy is ready.

## Sitemap, cache, analytics, integrity

- **Sitemap.** A published Spanish page is a Page, so core `wp-sitemaps` lists it. No code was needed.
- **Cache.** There is no APO. HTML is cached by a Cloudflare Cache Rule, and pages are tagged `sn-render`. Saving any Page purges the tag, so the Spanish and English pages purge together. `/inicio/` also gets the `sn-lists` tag (theme `sn_edge_cache_request_lists()`), so a note save refreshes it the way it refreshes the home page. Saving a release note purges the tag too.
- **Analytics.** The cookieless beacon records `location.pathname`. `/musica` is stored as its own path, separate from `/music`, with no change. The North Star sections and the session funnels group by English path prefixes, so they do not count the Spanish pages yet. Add their paths there if you want them counted.
- **Integrity reports.** The motion check reads stylesheets, so it is unaffected. The rendered contrast check walks every URL in the sitemap, so published Spanish pages are covered automatically.
- **Out of scope:** `llms.txt` (left English by owner decision), the Markdown twins (notes only) and the header navigation labels (still English on Spanish pages).

## /cv/ and the Spanish PDF come from one file

/resume is generated from the structured résumé editor (S&N → Content → Résumé). /cv/ and `JuanLentino_CV.pdf` are generated from **one Spanish file**, `inc/seed-content/resume-data-es.json`, in the same shape. The same page builder and the same PDF template render it, with their fixed headings in Spanish (`inc/resume-lang.php`, `inc/resume-es.php`). There is no Spanish screen in the settings (owner, 2026-10-15).

- **Contact details** (LinkedIn, email, phone, website, and whether the phone is public) always come from the live English résumé, so the file holds none and the phone never reaches the repo.
- **/cv/ is rendered** on the next admin load after the file or the English contact details change. Its PDF is rendered with it, and again every time the English PDF is generated (action `sn_resume_pdf_generated`).
- **The page's status is never touched:** the owner publishes /cv/.
- **The English wording does not flow into the Spanish file.** When the English résumé changes, update `resume-data-es.json` and release.

## Adding another page to the bilingual set

1. Create a Page with its Spanish slug (for example `/contacto/`), write the copy, and set **Spanish version of** to the English page. Publish it.
2. That is all the code needs: the toggle, hreflang, `lang`, `og:locale`, the template and the fallback all follow from the row.
3. If the English page is gated by slug somewhere (an `is_page( 'contact' )` check), change it to `sn_lang_is_page( 'contact' )` so the gate also opens on the Spanish page.
4. If the page should appear in the one-time seed for fresh installs, add it to `sn_lang_seed_map()`. The seed runs once per site, so on the live site, create the page by hand.

Tests: `tests/bilingual.php` and `tests/discography-notes.php` (plugin), `tests/lang-toggle.php` (theme).
