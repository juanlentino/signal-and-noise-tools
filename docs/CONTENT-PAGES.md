# Content pages: from the Content tab to a real Page

Four public pages are written in the plugin, not in the block editor: `/now`,
`/about/uses`, `/resume` and `/workflow`. All four follow one pipeline:

```
Content tab form  ->  plugin option  ->  generator  ->  Page post_content  ->  theme template
```

1. **Form.** Each page has a classic form (`inc/admin-forms/*-page.php`) and a
   native twin (`apps/sn-dashboard/parts/leaves/content-*.php`). Both post the
   same field names to the same `sn_<page>_save` action; a leaf test pins that.
2. **Option.** The handler (`inc/admin-post-actions/`) normalizes the post and
   stores it in an autoload=no option. The option is the source of truth.
3. **Generator.** A pure function turns the option into the Page body.
4. **Page.** The body is written to a real WordPress Page's `post_content`
   (created on the first save that has content). The write passes
   `snt_generated_page_guard()` (`inc/generated-page-contract.php`), which
   refuses a body that lost its structural markers.
5. **Theme.** The theme renders the Page through its own template and CSS.

The edge cache for the page is purged after a save that changed it.

| Page | Option | Generator / upsert | Page | Template |
|---|---|---|---|---|
| /now | `sn_now_page` | `inc/page-sync-engine.php` | top-level `now` | `page-now` |
| /about/uses | `sn_uses_page` | `inc/page-sync-engine.php` | child of About, `uses` | `page-uses` |
| /resume | `sn_resume_doc` (drafts: `sn_resume_draft`) | `inc/resume-sync-engine.php` | top-level `resume` | `page-resume` |
| /workflow | `sn_workflow_page` | `inc/workflow-page-render.php` | top-level `workflow` | `page-workflow` |

/now, /uses and /workflow are one `wp:html` block around a wrapper div.
/resume is real block markup (a `wp:html` /resume loses its core block styles;
the contract refuses it).

## The Workflow group

Content > Workflow edits `/workflow`. Data layer: `inc/workflow-page.php`.

- **Page:** Title (the Page title), Dek (the Page excerpt, which becomes the
  meta description).
- **Sample:** Label, Title, Intro, Outcome (optional), Body, in that order on the page and in the form. The result leads and the long Body follows as its evidence; an empty Outcome renders nothing and Body follows Intro. The Body is
  stored verbatim (only NUL bytes are removed) and shown in a focusable
  `<pre><code>` region. On output it is escaped with `esc_html`, and `[` `]`
  are encoded as `&#91;` `&#93;` because WordPress runs shortcodes over
  `post_content`. Every other field gets the same escaping.
- **Map heading** (text, in the card beside Title and Dek) and **Map** (one card per step): Title, Line, Show on page. The heading renders only over at least one shown row.
- **Rules heading** (text, beside the Map heading) and **Rules** (one card per rule): Rule, Explanation. Rendered as an ordered list; the heading renders only over at least one rule.

Row order is display order. A section with no content renders nothing,
heading included. A save with nothing public creates no Page; until then
`/workflow` is a 404.

## The "Show on page" rule (fail closed)

Some map rows name work that must never be public. So a map row reaches the
page only when "Show on page" is checked, and the box is unchecked by
default, including on a newly added row. A missing or malformed flag counts
as hidden.

The filter sits at one point: `sn_workflow_public_data()`. Every public
surface (the Page, core REST `/wp/v2/pages`, the theme's `.json` twin, the
index, llms.txt, provenance signing) reads the generated Page, and the
generator reads only `sn_workflow_public_data()`. A hidden row leaves no
trace: no comment, hidden element, data attribute or CSS-hidden node. Hidden
rows stay in the option, visible only in the admin forms; no REST route,
ability or MCP tool reads that option.

If a save leaves nothing public (for example, the last shown row is
unchecked), an existing `/workflow` Page is moved to draft so it stops
showing rows that are no longer public.

Pinned by `tests/workflow-page.php` and `tests/os-leaf-content-workflow.php`.

## Known limits

- **Hiding is from now on, not a retraction.** A row that was public and is later unchecked leaves the Page, but it stays in the Page's older revisions (readable only by editors), in anything that copied the page while it was public (search caches, archives, a provenance signature if the page was signed), and in edge caches until their purge. Delete the revisions by hand if a row must leave the site's own history too.
- **The sample is verbatim up to core's own filters.** WordPress's `capital_P_dangit` runs over page content and does not skip code blocks, so a sample containing "Wordpress" shows "WordPress".
- **The generated markup assumes an administrator saves it.** A user without `unfiltered_html` gets the page through kses, which drops `tabindex` from the sample block and leaves it unreachable by keyboard.
- **Only the Content form regenerates the page.** Editing the option by other means (WP-CLI, an import) does not.
