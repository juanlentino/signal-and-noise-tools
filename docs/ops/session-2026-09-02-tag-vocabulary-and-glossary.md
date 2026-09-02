# Session — 2026-09-02: the tag vocabulary, and the glossary that reads it

Cross-repo. Four theme releases (12.14.0 → 12.16.0), no plugin release. The
content work — splitting an over-broad tag and retagging 59 notes — came first
and everything else followed from it.

## What I set out to do, and what actually needed doing

The stated task was the tag archives. The real find was upstream of them.

`Provenance` was on 26 of 38 published notes (68%), co-occurring 100% with
Authorship, AI Detection and C2PA — a tag that had stopped discriminating. It
split into **Creation-Time Capture**, **Verification Limits** and **Provenance
Adoption**, applied by WP-CLI.

Then the owner asked whether the *scheduled* notes needed the same treatment,
and that question is the most valuable thing in the session.

### WordPress tag counts are publish-only

`wp_update_term_count()` counts only `post_status = 'publish'` for `post_tag`.
`Provenance` read **count 0** while still attached to **13 of 21 scheduled
notes**. Two ways that bites:

- delete the term → those 13 silently lose a tag;
- leave it → it resurrects to 13 over the next ten weeks as they publish, and
  the split is undone.

The safe pre-delete check is `--post_status=any`, never the count:

```bash
wp post list --post_type=post --post_status=any --tag=provenance --format=count
```

All 13 were retagged (3 / 5 / 5). Final projection once the queue drains:
Creation-Time Capture 11, Verification Limits 15, Provenance Adoption 13,
against 59 notes.

## /notes/tags/ — a glossary, not a tag cloud

All 25 tags carry owner-written descriptions, which is what makes a glossary
possible and a cloud unnecessary. A cloud encodes **frequency** as type size,
and frequency is the one property a reader cannot act on. Worse here: the thin
tags are the most *specific* ones, so frequency-as-salience inverts the page's
usefulness. Every term renders at exactly one size — measured in the browser
(`distinctSizes: ["12px"]` across all 25), not asserted.

Four editorial groups, each a section with a dek. The row is the site's
split-hero composition at row scale: term left in the label register, prose
right.

**The guard that matters** is `sn_notes_tag_groups_resolved()`. A hardcoded
grouping drifts the moment a tag is added and the failure is SILENT — the tag
simply is not on the page. Any in-use tag named in no group falls through to a
trailing "Not yet filed" section, which is loud by comparison.

## The pattern that cost three of four releases

Three releases today were fixes for things I introduced and shipped green:

| shipped | wrong | caught by |
|---|---|---|
| 12.13.1 | subscribe line kept a type register its own comment called unreadable | the owner, looking at the page |
| 12.15.0 | three container class names that do not exist | the owner, looking at the page |
| (pre-ship) | `--wp--preset--color--rule`, an invented token | my own token check |

Every one is a claim about a **vocabulary** — CSS class names, custom
properties, type registers — and nothing in the pipeline validates that a name
refers to something real. PHP lints clean, HTML validates, all 111 suites pass,
the page returns 200 with every row present. An invented CSS class is
indistinguishable from a correct one, because CSS has no such thing as an
unresolved selector.

`tests/notes-tags-class-parity.php` (theme) closes the class half: every class
the renderer emits must exist in `notes.css` or in the index renderer it
borrows its shell from. It carries vacuity guards, strips comments first, fails
on the exact code that shipped, and **found a fourth orphan on its first run**
(`sn-tags-page`, a hook with no rule) — which I removed rather than exempted.

The type-register vocabulary still has no guard. That one is still "a human
reads it", worth knowing before touching the hero again.

## Measurement artifacts, twice

- `grep -c` counts **lines**, not occurrences. It reported "1 group, 1 row" on
  a live page that had 4 and 25. `grep -o … | wc -l` is the honest count.
- I checked the combined stylesheet (`sn-styles-*.css`) for the glossary rules,
  found zero, and nearly reported the page unstyled. The rules were in
  `notes.css`, linked separately on the same page. I checked the wrong file.

Both are the standing rule in practice: before concluding from tool output,
rule out that the instrument measured something other than what you asked.

## Also shipped

- **12.14.0** — reader names back in the hero (NetNewsWire, Reeder, Feedbin,
  "among others" as a deliberate hedge); retired-tag 301 map, matched on the
  REQUEST PATH so the URL outlives the deleted term.
- **12.14.1** — the subscribe line into the prose register.
- **12.16.0** — the class fix, the parity guard, and the glossary's only
  inbound link, in the index hero's closing stamp (`38 entries · Last updated
  … · All tags`). Not the top nav: a tag map is a fact about the corpus, which
  is what that stamp states. It inherits the stamp's suppression in filtered
  views — verified live: absent on tag archives and search, present only on the
  unfiltered index.

## Corrections I made to my own reporting

- I claimed `apply-tag-description` mutates on `dry_run: true`. **Wrong** — the
  ability has no `dry_run` parameter; I invented it and misread `status:
  "written"`. The real, much smaller finding: `input_schema` declares
  `additionalProperties: false` but nothing enforces it. Input validation is
  deliberately delegated to the client (`sn_mcp_output_schema_violation()` is
  output-only by design), so an undeclared key is dropped rather than rejected.
  **Open question, unanswered: should the write door validate inputs?**
- The docblock on `sn_notes_is_tag_request()` said archives live at
  `/notes/tag/{slug}/`. They do not — that 404s. Canonical is `/tag/{slug}/`.
  Corrected in 12.15.0. The v12.14.0 redirect matched the right path only
  because I wrote it from live URLs rather than from that comment; written from
  the comment it would have matched nothing and its test would have passed,
  both from the same wrong premise.

## Housekeeping

I wrote a `.claude/launch.json` into the theme repo for a CSS preview harness
and deleted it afterward. `preview_start` was never using it — it resolved
`theme-css-harness` from the plugin repo's config, which is intact. Git shows
the theme repo never tracked a `launch.json`, so nothing versioned was lost,
but I cannot rule out having overwritten an untracked one. Use the scratchpad
for harnesses; do not write into a repo you are only reading from.
