# Signal & Noise AI Abilities Catalog

This is the canonical reference for the 29 Signal & Noise WordPress 7.0 Abilities (12 theme + 17 plugin) consumed by `wp ability run`, the REST endpoint `/wp-json/wp-abilities/v1/abilities/<slug>/run`, and future AI tool harvesters. When [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) lands the Agents framework with step 3's Abilities-as-tools bridge, every entry below auto-promotes to an LLM-callable tool with its `input_schema` becoming the function signature. Verified against the actual registrations in [theme abilities](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) + [plugin abilities](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php). Last verified against theme v9.1.2 + plugin v3.7.4.

> **⚠️ Stale — not a complete list.** Last verified against plugin **v3.7.4** (current: v6.19.3). It documents 17 plugin abilities, but the plugin now registers roughly **42**. The **live registry is the source of truth** — run the `signal-noise/list-abilities` ability or read `inc/abilities-*.php`. Treat this file as prose context, not a version-accurate or exhaustive catalog.

## Quick reference

| Slug | Category | Capability | Input | One-line description |
|---|---|---|---|---|
| `signal-and-noise/get-design-tokens` | diagnostics | `read` | none | SN theme palette + typography + spacing from theme.json |
| `signal-and-noise/list-block-patterns` | content | `read` | `category?` | Registered block patterns + categories |
| `signal-and-noise/get-active-template-structure` | diagnostics | `read` | `post_id\|slug` | FSE template slug + shallow block tree |
| `signal-and-noise/get-theme-version` | diagnostics | `read` | none | Active theme name + version + WP version |
| `signal-and-noise/get-page-notes-pillars` | content | `read` | none | /notes catalog pillar essays with reading time |
| `signal-and-noise/get-reading-time-for-slug` | content | `read` | `slug` | Reading-time minutes for a slug |
| `signal-and-noise/get-design-system-summary` | diagnostics | `read` | `format?` | Design tokens formatted for AI prompt embedding |
| `signal-and-noise/ai-generate-page-note-summary` | ai-generation | `edit_posts` | `post_id`, `max_words?` | Brand-voiced /notes summary of a post |
| `signal-and-noise/ai-suggest-block-pattern` | ai-generation | `edit_posts` | `draft_content`, `topic_hint?` | 1–3 SN patterns ranked for a draft |
| `signal-and-noise/ai-validate-brand-alignment` | ai-generation | `edit_posts` | `content`, `content_type?` | 0–100 score with per-dimension findings |
| `signal-and-noise/ai-generate-pattern-content` | ai-generation | `edit_posts` | `pattern_name`, `topic`, `tone_hint?` | Fills a pattern shell with brand-voiced copy |
| `signal-and-noise/ai-rewrite-in-brand-voice` | ai-generation | `edit_posts` | `source_text`, `intensity?`, `preserve_links?`, `preserve_lists?` | Rewrites external copy in SN voice |
| `signal-noise/purge-all-caches` | maintenance | `manage_options` | `include_template_overrides?` | Object cache + Breeze + Varnish + Cloudflare purge |
| `signal-noise/regenerate-og-card` | content | `edit_post` | `post_id` | Rebuilds the social-share PNG for a post |
| `signal-noise/get-deploy-status` | diagnostics | `manage_options` | none | Theme + plugin current vs latest GitHub versions |
| `signal-noise/clear-template-overrides` | maintenance | `manage_options` | none | Removes wp_template / wp_template_part / wp_navigation DB rows |
| `signal-noise/force-check-updates` | updates | `manage_options` | none | Clears update transients to force fresh GitHub fetch |
| `signal-noise/full-reset` | maintenance | `manage_options` | none | Clears overrides + purges every cache |
| `signal-noise/list-template-overrides` | diagnostics | `manage_options` | none | Lists DB template-override rows (read-only inspection) |
| `signal-noise/get-rss-stats` | diagnostics | `manage_options` | none | RSS feed 24h/7d/30d totals + uniques |
| `signal-noise/ai-generate-meta-description` | ai-generation | `edit_post` | `post_id` | 140–160 char meta description; writes to `_sn_meta_description` |
| `signal-noise/ai-generate-og-card-title` | ai-generation | `edit_post` | `post_id` | 60–90 char OG title + regenerates the PNG |
| `signal-noise/ai-generate-excerpt` | ai-generation | `edit_post` | `post_id` | 50–75 word, 2–3 sentence excerpt |
| `signal-noise/list-cron-events` | diagnostics | `manage_options` | `sn_only?` | All scheduled WP-Cron events with next-run + last-fired |
| `signal-noise/get-cron-event` | diagnostics | `manage_options` | `hook`, `args_signature` | Single cron event details by signature |
| `signal-noise/get-cron-history` | diagnostics | `manage_options` | `hook`, `limit?` | Last N firings of a cron hook (success/elapsed/error) |
| `signal-noise/run-insights-scan` | diagnostics | `manage_options` | `force?` | Cross-system synthesis scan returning 5 recommendations |
| `signal-noise/get-insights` | diagnostics | `manage_options` | none | Cached last-scan result; null if never scanned |
| `signal-noise/unschedule-cron-event` | maintenance | `manage_options` | `hook`, `args?` | Destructive — removes a scheduled cron event by hook + args |

## How to use this catalog

**WP-CLI** — pass JSON input via `--input`:
```bash
wp ability run <slug>
wp ability run <slug> --input='{"post_id": 42}'
```

**REST API** — POST to `/wp-json/wp-abilities/v1/abilities/<slug>/run` with the `wordpress_logged_in_*` session cookie and an `X-WP-Nonce` header for write operations. Readonly abilities accept GET; readonly + idempotent abilities accept `?input=` URL-encoded JSON, though omitting the parameter is the supported pattern (the controller passes `null`, which the input schemas explicitly permit via `type: ['object', 'null']`).

**Future AI tool harvester** — once [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) ships the Agents framework, the Abilities-as-tools bridge auto-converts every entry on this page into an LLM-callable tool. The ability `input_schema` becomes the function-call signature; the `output_schema` shapes the response. The capability check (`permission_callback`) stays enforced — the harvester respects WP auth.

## Theme abilities (`signal-and-noise/*`)

### `signal-and-noise/get-design-tokens`

**Category:** `diagnostics`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Returns the SN theme's color palette, typography (font families + sizes), and spacing scale from theme.json. Read-only.

**Input:** None.

**Output:**
```json
{
  "colors": {
    "white": "#ffffff",
    "black": "#000000",
    "blood-red": "#e00404"
  },
  "typography": {
    "fontFamilies": [
      { "slug": "bebas", "name": "Bebas Neue", "fontFamily": "'Bebas Neue', sans-serif" },
      { "slug": "dm-mono", "name": "DM Mono", "fontFamily": "'DM Mono', monospace" }
    ],
    "fontSizes": [
      { "slug": "small", "name": "Small", "size": "0.875rem" },
      { "slug": "medium", "name": "Medium", "size": "1rem" }
    ]
  },
  "spacing": {
    "spacingScale": { "operator": "*", "increment": 1.5, "steps": 7, "mediumStep": 1.5, "unit": "rem" },
    "spacingSizes": [
      { "slug": "30", "name": "1", "size": "clamp(1rem, 2vw, 1.5rem)" }
    ]
  },
  "version": "9.1.2"
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-design-tokens
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-design-tokens/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Summarize the SN design system in an AI prompt before generating brand-aligned UI suggestions
- Verify theme.json tokens loaded correctly after editing the file
- Provide canonical token values to a content-generation workflow that needs to reference brand colors or typography

---

### `signal-and-noise/list-block-patterns`

**Category:** `content`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Enumerates all registered block patterns with category + keywords + viewport hints. Optional `category` input filters to a single pattern category.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `category` | string | no | — | — | Optional filter to a single pattern category slug |

**Output:**
```json
{
  "patterns": [
    {
      "name": "signal-and-noise/hero-catalog",
      "title": "Hero Catalog",
      "description": "Brutalist catalog-style hero with mono heading and blood-red accent.",
      "categories": ["hero"],
      "keywords": ["hero", "catalog", "header"],
      "viewport_width": 1400
    }
  ],
  "categories": [
    { "name": "hero", "label": "Hero" },
    { "name": "text", "label": "Text" }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/list-block-patterns
wp ability run signal-and-noise/list-block-patterns --input='{"category": "hero"}'
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/list-block-patterns/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Provide the catalog to `ai-suggest-block-pattern` upstream (this ability is what that one calls internally)
- Audit the registered pattern surface after adding new patterns to `inc/block-patterns/`
- Generate documentation listing every pattern + its keywords for editorial reference

---

### `signal-and-noise/get-active-template-structure`

**Category:** `diagnostics`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Returns the FSE template slug + a shallow block tree (blockName + attrs + innerBlocks count) for a given post by ID or slug. Does not recurse into innerBlocks beyond a count — keeps payload bounded.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | one of `post_id` or `slug` required | minimum 1 | — | WordPress post ID |
| `post_type` | string | no | `post`, `page` | `page` (when using slug) | Post type to resolve when looking up by slug |
| `slug` | string | one of `post_id` or `slug` required | — | — | Post slug |

**Output:**
```json
{
  "template_slug": "single",
  "template_part_slugs": ["header", "footer"],
  "blocks": [
    { "blockName": "core/template-part", "attrs": { "slug": "header", "theme": "signal-and-noise" }, "innerBlocksCount": 0 },
    { "blockName": "core/group", "attrs": { "tagName": "main" }, "innerBlocksCount": 4 },
    { "blockName": "core/template-part", "attrs": { "slug": "footer", "theme": "signal-and-noise" }, "innerBlocksCount": 0 }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-active-template-structure --input='{"post_id": 42}'
wp ability run signal-and-noise/get-active-template-structure --input='{"slug": "provenance/over-detection", "post_type": "post"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-active-template-structure/run" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Diagnose which FSE template is rendering a given URL before editing template files
- Provide template context to an AI assistant before it suggests block-tree edits
- Verify a template-part wiring after a `clear-template-overrides` recovery

---

### `signal-and-noise/get-theme-version`

**Category:** `diagnostics`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Returns the active theme name + version + parent template + is_block_theme flag + WP version. Use to detect drift between published roadmap docs and the live site.

**Input:** None.

**Output:**
```json
{
  "theme_version": "9.1.2",
  "theme_name": "Signal & Noise",
  "theme_template": "signal-and-noise",
  "is_block_theme": true,
  "supports_fse": true,
  "wp_version": "7.0"
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-theme-version
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-theme-version/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Confirm a deploy landed by comparing the response to the expected `style.css` Version header
- Verify the SN theme is the active theme before invoking theme-scoped abilities (avoids no-op work on a sibling site)
- Pair with `signal-noise/get-deploy-status` to compare current vs latest GitHub release in a single workflow

---

### `signal-and-noise/get-page-notes-pillars`

**Category:** `content`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Returns metadata for the SN /notes catalog pillar essays — slug, title, URL, summary dek, reading time, last modified. The pillars are project-defined in inc/page-notes-render.php and frame the /notes index.

**Input:** None.

**Output:**
```json
{
  "pillars": [
    {
      "slug": "provenance/over-detection",
      "title": "Provenance Over Detection",
      "url": "https://juanlentino.com/provenance/over-detection/",
      "summary": "Detection chases what isn't. Provenance proves what is.",
      "reading_time_minutes": 7,
      "last_modified": "2026-05-20"
    },
    {
      "slug": "provenance/as-substrate",
      "title": "Provenance as Substrate",
      "url": "https://juanlentino.com/provenance/as-substrate/",
      "summary": "Music files need fingerprints, not name tags.",
      "reading_time_minutes": 5,
      "last_modified": "2026-05-18"
    }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-page-notes-pillars
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-page-notes-pillars/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Provide pillar context to an AI generating a new /notes essay that should fit alongside existing ones
- Render an external preview of the /notes catalog without scraping the rendered HTML
- Verify pillars resolve to actual posts (non-zero reading_time_minutes) after a content migration

---

### `signal-and-noise/get-reading-time-for-slug`

**Category:** `content`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Returns the computed reading-time minutes for a post identified by slug. Wraps sn_notes_reading_time_for_slug() (the same helper that powers the [sn_reading_time] shortcode). Returns minutes=0 if the slug does not resolve.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `slug` | string | yes | minLength 1 | — | Post slug to look up |

**Output:**
```json
{
  "slug": "provenance/over-detection",
  "minutes": 7,
  "wpm_basis": 220
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-reading-time-for-slug --input='{"slug": "provenance/over-detection"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-reading-time-for-slug/run" \
  -H "Content-Type: application/json" \
  -d '{"slug": "provenance/over-detection"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Provide reading-time context to an AI suggesting whether to split a long essay
- Detect a slug rename or 404 (response returns `minutes: 0`)
- Bench the 220-wpm basis against other reading-time estimators before changing the default

---

### `signal-and-noise/get-design-system-summary`

**Category:** `diagnostics`
**Capability:** `read`
**Annotations:** `idempotent: true, open_world_hint: false, readonly: true`

**Description:** Formats the design tokens for AI prompt embedding. format=markdown (default) for structured prose, format=compact-text for minimum-token single-line embedding, format=json for full passthrough. Typical 70-80% token reduction vs raw get-design-tokens JSON on compact-text.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `format` | string | no | `markdown`, `compact-text`, `json` | `markdown` | Output formatting mode |

**Output:**
```json
{
  "format": "markdown",
  "summary": "# Signal & Noise design system\n\n## Colors\n- `white` — #ffffff\n- `black` — #000000\n- `blood-red` — #e00404\n\n## Typography\n\n### Font families\n- `bebas` (Bebas Neue) — 'Bebas Neue', sans-serif\n- `dm-mono` (DM Mono) — 'DM Mono', monospace\n\n### Font sizes\n- `small` — 0.875rem\n- `medium` — 1rem\n\n## Spacing\n- `30` — clamp(1rem, 2vw, 1.5rem)",
  "token_estimate": 95
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/get-design-system-summary
wp ability run signal-and-noise/get-design-system-summary --input='{"format": "compact-text"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/get-design-system-summary/run" \
  -H "Content-Type: application/json" \
  -d '{"format": "compact-text"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Embed brand tokens in an LLM system prompt without burning context on raw JSON (use `compact-text`)
- Generate human-readable design-system docs for handoff to a contractor (use `markdown`)
- Pipe to a downstream tool that wants the same shape as `get-design-tokens` (use `json`)

---

### `signal-and-noise/ai-generate-page-note-summary`

**Category:** `ai-generation`
**Capability:** `edit_posts`
**Annotations:** `idempotent: false, open_world_hint: false, readonly: false`

**Description:** Generates a brand-voiced single-sentence summary of a post in the SN /notes catalog vocabulary. Calls the plugin's AI helper (Sonnet 4.6 pinned via plugin v3.7.2+). Requires signal-and-noise-tools plugin.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | yes | minimum 1 | — | Post ID to summarize |
| `max_words` | integer | no | 10–60 | 30 | Hard word limit on the summary |

**Output:**
```json
{
  "summary": "Provenance treats music as a forensic substrate where origin is proven by cryptographic fingerprint, not claimed by metadata.",
  "post_id": 42,
  "tokens_used": 28
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/ai-generate-page-note-summary --input='{"post_id": 42}'
wp ability run signal-and-noise/ai-generate-page-note-summary --input='{"post_id": 42, "max_words": 25}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/ai-generate-page-note-summary/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Pre-populate a /notes catalog row dek before publishing a new pillar essay
- Refresh stale summaries after a major post revision
- Bench the /notes-voice system prompt by running it across the pillar set and reviewing the output shapes

---

### `signal-and-noise/ai-suggest-block-pattern`

**Category:** `ai-generation`
**Capability:** `edit_posts`
**Annotations:** `idempotent: false, open_world_hint: false, readonly: false`

**Description:** AI recommends 1–3 SN block patterns that fit a draft. Caller supplies the draft content; ability fetches the SN pattern catalog and asks the AI to pick the best matches. Requires signal-and-noise-tools plugin.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `draft_content` | string | yes | 20–4000 chars | — | The draft text to analyze |
| `topic_hint` | string | no | max 200 chars | — | Optional topic hint to bias suggestions |

**Output:**
```json
{
  "suggestions": [
    {
      "pattern_name": "signal-and-noise/hero-catalog",
      "reasoning": "Draft opens with a declarative claim suited to the catalog-style hero with mono heading.",
      "confidence": "high"
    },
    {
      "pattern_name": "signal-and-noise/spec-sheet-list",
      "reasoning": "Body contains enumerated technical primitives that map to the spec-sheet pattern's list shape.",
      "confidence": "medium"
    }
  ],
  "tokens_used": 412
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/ai-suggest-block-pattern --input='{"draft_content": "Provenance is the forensic substrate of every claim..."}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/ai-suggest-block-pattern/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"draft_content": "...", "topic_hint": "audio provenance"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Suggest a hero pattern to an editor staring at a blank draft
- Audit whether the registered pattern surface is being picked up by the AI for representative drafts (catch dead patterns)
- Sanity-check pattern naming and descriptions by reading which slugs the AI returns

---

### `signal-and-noise/ai-validate-brand-alignment`

**Category:** `ai-generation`
**Capability:** `edit_posts`
**Annotations:** `idempotent: false, open_world_hint: false, readonly: false`

**Description:** AI scores content (0-100) for fit with the SN brand: voice, tone, vocabulary, palette references, structure. Returns score + per-dimension findings with verdict (aligned|drift|off-brand) + note. Uses the shared brand-voice constant.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `content` | string | yes | 50–8000 chars | — | Content to evaluate |
| `content_type` | string | no | `copy`, `title`, `summary`, `longform` | `copy` | Content classification hint |

**Output:**
```json
{
  "overall_score": 72,
  "findings": [
    { "dimension": "voice", "verdict": "aligned", "note": "Direct, declarative; no apology or hedging." },
    { "dimension": "tone", "verdict": "drift", "note": "One sentence drifts into marketing register near the close." },
    { "dimension": "vocabulary", "verdict": "aligned", "note": "Engineering nouns (substrate, fingerprint, provenance) used correctly." },
    { "dimension": "palette_fit", "verdict": "aligned", "note": "References blood-red sparingly as an accent, not a primary." },
    { "dimension": "structure", "verdict": "drift", "note": "List items not verb-leading; would benefit from spec-sheet rewrite." }
  ],
  "tokens_used": 624
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/ai-validate-brand-alignment --input='{"content": "Provenance is...", "content_type": "summary"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/ai-validate-brand-alignment/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"content": "...", "content_type": "longform"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Pre-publish gate: reject a draft that scores below 70 and surface the per-dimension drift notes to the editor
- Audit a corpus of published essays to find the lowest-scoring pieces and queue them for rewrite
- Validate that copy written by an external contractor meets the SN voice bar before paying out

---

### `signal-and-noise/ai-generate-pattern-content`

**Category:** `ai-generation`
**Capability:** `edit_posts`
**Annotations:** `idempotent: false, open_world_hint: false, readonly: false`

**Description:** Fills a chosen SN block pattern's shell with brand-voiced copy on a given topic. Returns ready-to-paste serialized Gutenberg block markup. Does NOT save anything — caller decides whether to use the markup.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `pattern_name` | string | yes | must exist in pattern registry | — | Pattern slug from `list-block-patterns` |
| `topic` | string | yes | 5–500 chars | — | Topic to write about |
| `tone_hint` | string | no | `technical`, `narrative`, `manifesto`, `spec-sheet` | `spec-sheet` | Voice register modifier |

**Output:**
```json
{
  "block_markup": "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\"><!-- wp:heading {\"level\":1} -->\n<h1>Provenance</h1>\n<!-- /wp:heading --></section>\n<!-- /wp:group -->",
  "pattern_name": "signal-and-noise/hero-catalog",
  "tokens_used": 487,
  "warnings": []
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/ai-generate-pattern-content --input='{"pattern_name": "signal-and-noise/hero-catalog", "topic": "audio provenance"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/ai-generate-pattern-content/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"pattern_name": "signal-and-noise/spec-sheet-list", "topic": "provenance primitives", "tone_hint": "technical"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Generate a starter draft for a new pillar essay from a topic + chosen pattern
- Produce ready-to-paste block markup for an editor who wants to skip the "fill in the template" step
- A/B test tone hints (`technical` vs `manifesto`) on the same pattern + topic to compare brand-voice fidelity

---

### `signal-and-noise/ai-rewrite-in-brand-voice`

**Category:** `ai-generation`
**Capability:** `edit_posts`
**Annotations:** `idempotent: false, open_world_hint: false, readonly: false`

**Description:** Transforms external/generic copy into the SN voice register. Intensity controls aggression (light: vocabulary swaps; medium: sentence restructure; full: full rewrite). Preserves links + list structures when flagged. Net-new vs ai/ai's Editorial Notes which only flag grammar/SEO/a11y — this changes voice.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `source_text` | string | yes | 20–8000 chars | — | Copy to rewrite |
| `preserve_links` | boolean | no | — | true | Keep URLs verbatim |
| `preserve_lists` | boolean | no | — | true | Keep list structures intact |
| `intensity` | string | no | `light`, `medium`, `full` | `medium` | Transform aggression |

**Output:**
```json
{
  "rewritten_text": "Provenance is forensic. Detection chases what isn't; provenance proves what is. Every claim is anchored by cryptographic fingerprint, not metadata.",
  "summary_of_changes": "Replaced marketing register with declarative engineering nouns; removed hedging; tightened sentence shapes.",
  "preserved_elements": {
    "links_count": 0,
    "lists_count": 0
  },
  "tokens_used": 312
}
```

**Invocation:**
```bash
wp ability run signal-and-noise/ai-rewrite-in-brand-voice --input='{"source_text": "We are excited to explore the world of provenance..."}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-and-noise/ai-rewrite-in-brand-voice/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"source_text": "...", "intensity": "full", "preserve_links": true}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Convert a syndicated post written in a generic register into SN voice before republishing on /notes
- Rewrite ChatGPT-generated copy that's drifted off-brand without going to a full manual edit
- Bench intensity levels by running the same source text three times with `light` / `medium` / `full` and inspecting `summary_of_changes`

---

## Plugin abilities (`signal-noise/*`)

### `signal-noise/purge-all-caches`

**Category:** `maintenance`
**Capability:** `manage_options`
**Annotations:** `destructive: true, idempotent: true`

**Description:** Clears WordPress object cache, transients, Breeze page cache, Varnish, and Cloudflare edge cache. Use after deploys or when content appears stale.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `include_template_overrides` | boolean | no | — | false | Also clear wp_template / wp_template_part / wp_navigation DB rows |

**Output:**
```json
{
  "ok": true,
  "message": "All caches purged.",
  "count": 0
}
```

**Invocation:**
```bash
wp ability run signal-noise/purge-all-caches
wp ability run signal-noise/purge-all-caches --input='{"include_template_overrides": true}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/purge-all-caches/run" \
  -H "X-WP-Nonce: <nonce>" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Run immediately after a deploy to make sure the new theme/plugin version is what visitors load
- Diagnose "is this stale cache or actual broken HTML?" by purging and reloading
- Pair with `include_template_overrides: true` for a heavier reset after Site Editor experiments

---

### `signal-noise/regenerate-og-card`

**Category:** `content`
**Capability:** `edit_post` (scoped to the supplied `post_id`)
**Annotations:** `idempotent: true`

**Description:** Rebuilds the social-share card image (/wp-content/uploads/sn-og/post-{ID}.png) for a single post. Use after editing post title or featured image.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | yes | minimum 1 | — | Post whose OG card should be rebuilt |

**Output:**
```json
{
  "ok": true,
  "image_url": "https://juanlentino.com/wp-content/uploads/sn-og/post-42.png",
  "message": "OG card regenerated for \"Provenance Over Detection\"."
}
```

**Invocation:**
```bash
wp ability run signal-noise/regenerate-og-card --input='{"post_id": 42}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/regenerate-og-card/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Refresh the social share image after editing a post title or swapping the featured image
- Recover from a missing or corrupt OG card PNG without manually re-saving the post
- Chain after `ai-generate-og-card-title` (which already triggers the regeneration internally — call this only when the title is unchanged)

---

### `signal-noise/get-deploy-status`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true`

**Description:** Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available. Read-only; safe to call anytime.

**Input:** None.

**Output:**
```json
{
  "theme": {
    "current": "9.1.2",
    "latest": "9.1.2",
    "state": "ok"
  },
  "plugin": {
    "current": "3.7.4",
    "latest": "3.7.4",
    "state": "ok"
  }
}
```

**Invocation:**
```bash
wp ability run signal-noise/get-deploy-status
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-deploy-status/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Confirm the live site is on the version you just shipped before announcing the release
- Detect drift between local and production (e.g., local on 3.7.4, prod stuck on 3.7.3)
- Surface `state: available` to a dashboard so the operator sees the WP Updates page has a pending update

---

### `signal-noise/clear-template-overrides`

**Category:** `maintenance`
**Capability:** `manage_options`
**Annotations:** `destructive: true, idempotent: true`

**Description:** Removes any wp_template, wp_template_part, or wp_navigation rows the Site Editor has saved that override the theme files. Returns the count cleared. Use this if Site Editor edits have introduced regressions.

**Input:** None.

**Output:**
```json
{
  "ok": true,
  "count": 3,
  "message": "3 database template overrides cleared."
}
```

**Invocation:**
```bash
wp ability run signal-noise/clear-template-overrides
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/clear-template-overrides/run" \
  -H "X-WP-Nonce: <nonce>" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Roll back a Site Editor accident (saved an override that broke a layout) without manual SQL
- Reset to "theme files are the source of truth" before a release that ships template changes
- Call after `list-template-overrides` confirms there are rows to clear (otherwise this is a no-op returning count=0)

---

### `signal-noise/force-check-updates`

**Category:** `updates`
**Capability:** `manage_options`
**Annotations:** `idempotent: true`

**Description:** Clears the sn_gh_latest_* + update_themes + update_plugins site transients so the next admin page-load refetches fresh data from GitHub. No user data deleted.

**Input:** None.

**Output:**
```json
{
  "ok": true,
  "message": "Update transients cleared."
}
```

**Invocation:**
```bash
wp ability run signal-noise/force-check-updates
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/force-check-updates/run" \
  -H "X-WP-Nonce: <nonce>" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Force WP to notice a release you just tagged (the 12-hour update-check cycle hides it otherwise)
- Diagnose "why doesn't wp-admin see my new release?" by clearing caches and reloading the Updates page
- Run as the first step of a manual deploy ritual so the Updates page lights up immediately

---

### `signal-noise/full-reset`

**Category:** `maintenance`
**Capability:** `manage_options`
**Annotations:** `destructive: true, idempotent: true`

**Description:** Clears wp_template / wp_template_part / wp_navigation DB overrides AND purges every cache (object cache, Breeze, Varnish, Cloudflare). Use after a theme/plugin update or when content appears stale.

**Input:** None.

**Output:**
```json
{
  "ok": true,
  "message": "Full reset complete; 3 template overrides cleared and all caches purged.",
  "data": {
    "count": 3
  }
}
```

**Invocation:**
```bash
wp ability run signal-noise/full-reset
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/full-reset/run" \
  -H "X-WP-Nonce: <nonce>" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- "Nuke from orbit" recovery after a botched Site Editor session
- One-call post-deploy hygiene: clear overrides + purge edge cache
- Run before reporting a "I think something's stuck" bug to rule out cache or override interference

---

### `signal-noise/list-template-overrides`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true`

**Description:** Returns the slugs and post types of any wp_template / wp_template_part / wp_navigation rows currently overriding theme files. Read-only inspection before the destructive clear-template-overrides.

**Input:** None.

**Output:**
```json
{
  "ok": true,
  "count": 2,
  "items": [
    { "post_type": "wp_template", "slug": "single", "id": 891 },
    { "post_type": "wp_template_part", "slug": "header", "id": 894 }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-noise/list-template-overrides
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/list-template-overrides/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Inspect what would be destroyed by `clear-template-overrides` before running it
- Audit which templates have been modified in the Site Editor without going through the UI
- Diagnose "the theme template change isn't showing up" — overrides shadow theme files

---

### `signal-noise/get-rss-stats`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true`

**Description:** Returns the most recent RSS feed request timestamp + 24h / 7d / 30d totals + unique visitor counts. Backed by the sn_rss_tracker module. Use to verify RSS feed traffic before changing feed structure or auditing crawler activity.

**Input:** None.

**Output:**
```json
{
  "ok": true,
  "data": {
    "last_request": "2026-05-24 02:18:37",
    "last_request_relative": "3 hours ago",
    "windows": {
      "1":  { "total": 84,  "uniques": 23 },
      "7":  { "total": 612, "uniques": 89 },
      "30": { "total": 2487, "uniques": 217 }
    }
  }
}
```

**Invocation:**
```bash
wp ability run signal-noise/get-rss-stats
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-rss-stats/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Confirm RSS subscriber base size before deciding to deprecate the feed format
- Detect a crawler runaway (sudden spike in 24h total without matching uniques growth)
- Sanity-check that the RSS tracker module is still recording after a plugin update

---

### `signal-noise/ai-generate-meta-description`

**Category:** `ai-generation`
**Capability:** `edit_post` (scoped to the supplied `post_id`)
**Annotations:** `idempotent: true`

**Description:** Generates a 140-160 character meta description from post content via the WP AI Client. Writes to the _sn_meta_description post meta override.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | yes | minimum 1 | — | Post to summarize |

**Output:**
```json
{
  "ok": true,
  "description": "Provenance is the forensic substrate of every claim. Music files need cryptographic fingerprints, not metadata name tags.",
  "length": 148
}
```

**Invocation:**
```bash
wp ability run signal-noise/ai-generate-meta-description --input='{"post_id": 42}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/ai-generate-meta-description/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Backfill missing meta descriptions across a corpus of legacy posts
- Refresh a stale meta description after a major post revision
- Pre-publish editor workflow: auto-suggest a meta description the editor can accept or edit

---

### `signal-noise/ai-generate-og-card-title`

**Category:** `ai-generation`
**Capability:** `edit_post` (scoped to the supplied `post_id`)
**Annotations:** `idempotent: true`

**Description:** Generates a 60-90 character punchy variant of the post title via the WP AI Client, writes to _sn_og_card_title post meta, AND re-runs sn_generate_og_card so the social-share PNG reflects the new title immediately.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | yes | minimum 1 | — | Post to retitle for OG card |

**Output:**
```json
{
  "ok": true,
  "title": "Provenance Proves What Detection Cannot",
  "length": 41,
  "card_regenerated": true,
  "card_url": "https://juanlentino.com/wp-content/uploads/sn-og/post-42.png"
}
```

**Invocation:**
```bash
wp ability run signal-noise/ai-generate-og-card-title --input='{"post_id": 42}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/ai-generate-og-card-title/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Replace a long post title with a punchier social-share variant without changing the on-page H1
- Refresh both the title meta and the rendered PNG in one call after a content update
- A/B test social-share variants by running this multiple times and inspecting the generated titles

---

### `signal-noise/ai-generate-excerpt`

**Category:** `ai-generation`
**Capability:** `edit_post` (scoped to the supplied `post_id`)
**Annotations:** `idempotent: true`

**Description:** Generates a 50-75 word, 2-3 sentence excerpt from post content via the WP AI Client. Returns the text; the caller writes it to WP's native post_excerpt field.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `post_id` | integer | yes | minimum 1 | — | Post to summarize |

**Output:**
```json
{
  "ok": true,
  "excerpt": "Provenance is the forensic substrate of every claim about a music file. This essay argues that detection chases what isn't, while provenance proves what is. The shift matters because anchoring claims by cryptographic fingerprint replaces the fragile name-tag metadata that streaming platforms ship today.",
  "length": 318,
  "words": 53
}
```

**Invocation:**
```bash
wp ability run signal-noise/ai-generate-excerpt --input='{"post_id": 42}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/ai-generate-excerpt/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"post_id": 42}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Backfill empty `post_excerpt` fields on legacy posts so listing pages stop falling back to the auto-excerpt
- Suggest a starter excerpt for an editor to refine before publishing
- Refresh excerpts after major revisions without re-typing them by hand

---

### `signal-noise/list-cron-events`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true, open_world_hint: false`

**Description:** Returns all scheduled WP-Cron events with next-run, recurrence, last-fired, args, has_handler flag, and is_sn_owned flag. Pass sn_only=true to filter to the 3 SN-owned hooks (Plausible refresh, RSS prune, deploy webhook).

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `sn_only` | boolean | no | — | false | Filter to the 3 SN-owned hooks only |

**Output:**
```json
[
  {
    "hook": "sn_plausible_refresh",
    "args_signature": "40cd750bba9870f18aada2478b24840a",
    "next_run_ts": 1748086800,
    "schedule": "hourly",
    "interval_s": 3600,
    "args": [],
    "last_fired_ts": 1748083200,
    "has_handler": true,
    "is_sn_owned": true
  },
  {
    "hook": "wp_version_check",
    "args_signature": "40cd750bba9870f18aada2478b24840a",
    "next_run_ts": 1748090400,
    "schedule": "twicedaily",
    "interval_s": 43200,
    "args": [],
    "last_fired_ts": 1748047200,
    "has_handler": true,
    "is_sn_owned": false
  }
]
```

**Invocation:**
```bash
wp ability run signal-noise/list-cron-events
wp ability run signal-noise/list-cron-events --input='{"sn_only": true}'
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/list-cron-events/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Audit the full cron surface after installing a new plugin to find unexpected schedules
- Discover an `args_signature` for use in `get-cron-event` or `unschedule-cron-event`
- Detect orphaned cron events (`has_handler: false`) left by a removed plugin

---

### `signal-noise/get-cron-event`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true, open_world_hint: false`

**Description:** Returns details for a single scheduled cron event identified by hook + args_signature. Returns null if no match. `args_signature` is the md5 hash returned by signal-noise/list-cron-events. Use that ability first to discover signatures.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `hook` | string | yes | minLength 1 | — | The cron hook name |
| `args_signature` | string | yes | minLength 1 | — | md5 args signature from `list-cron-events` |

**Output:**
```json
{
  "hook": "sn_plausible_refresh",
  "args_signature": "40cd750bba9870f18aada2478b24840a",
  "next_run_ts": 1748086800,
  "schedule": "hourly",
  "interval_s": 3600,
  "args": [],
  "last_fired_ts": 1748083200,
  "has_handler": true,
  "is_sn_owned": true
}
```

**Invocation:**
```bash
wp ability run signal-noise/get-cron-event --input='{"hook": "sn_plausible_refresh", "args_signature": "40cd750bba9870f18aada2478b24840a"}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-cron-event/run" \
  -H "Content-Type: application/json" \
  -d '{"hook": "sn_plausible_refresh", "args_signature": "40cd750bba9870f18aada2478b24840a"}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Drill into a single event after spotting an anomaly in `list-cron-events`
- Verify `next_run_ts` for a hook to confirm it hasn't been silently rescheduled
- Confirm an event still exists before invoking `unschedule-cron-event` against it

---

### `signal-noise/get-cron-history`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true, open_world_hint: false`

**Description:** Returns the most recent N firings of a cron hook with elapsed time, success/failure, and any error message. Backed by the snt_cron_history table populated since plugin v3.2.0; retention is a rolling 30 days OR 1000 rows per hook, whichever is shorter.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `hook` | string | yes | minLength 1 | — | The cron hook name |
| `limit` | integer | no | 1–100 | 10 | Maximum rows to return (newest first) |

**Output:**
```json
[
  {
    "id": 1842,
    "hook": "sn_plausible_refresh",
    "args_signature": "40cd750bba9870f18aada2478b24840a",
    "fired_at": "2026-05-24 02:00:00",
    "fired_at_ts": 1748080800,
    "elapsed_ms": 387,
    "success": true,
    "error_message": null
  },
  {
    "id": 1841,
    "hook": "sn_plausible_refresh",
    "args_signature": "40cd750bba9870f18aada2478b24840a",
    "fired_at": "2026-05-24 01:00:00",
    "fired_at_ts": 1748077200,
    "elapsed_ms": null,
    "success": false,
    "error_message": "Plausible API request timed out after 5000ms."
  }
]
```

**Invocation:**
```bash
wp ability run signal-noise/get-cron-history --input='{"hook": "sn_plausible_refresh"}'
wp ability run signal-noise/get-cron-history --input='{"hook": "sn_plausible_refresh", "limit": 50}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-cron-history/run" \
  -H "Content-Type: application/json" \
  -d '{"hook": "sn_plausible_refresh", "limit": 20}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Diagnose why a cron hook is "broken" by reading the actual error_message from recent failures
- Track elapsed_ms trends to spot performance regressions in a periodic job
- Confirm a cron hook is firing at all (empty result = never fired since v3.2.0 history started)

---

### `signal-noise/run-insights-scan`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `idempotent: true, open_world_hint: false`

**Description:** Triggers a cross-system synthesis scan that combines Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable content recommendations. Cached for 7 days. Pass force=true to bypass the cache.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `force` | boolean | no | — | false | Bypass the 7-day cache and run a fresh AI call |

**Output:**
```json
{
  "scanned_at": 1748073600,
  "elapsed_ms": 4287,
  "recommendations": [
    {
      "id": "rec_01",
      "type": "write_about",
      "title": "Write the third Provenance pillar",
      "rationale": "/provenance traffic is the strongest growth signal but the catalog stops at two pillars.",
      "evidence_pills": ["plausible:+38% /provenance MoM", "publish:none since 2026-04"],
      "target": null
    },
    {
      "id": "rec_02",
      "type": "update_post",
      "title": "Refresh `audio-engineering-101` — stale references",
      "rationale": "Post 218 cites 2024 specs; spike in /search for newer engineering nouns.",
      "evidence_pills": ["age:14mo", "plausible:high bounce"],
      "target": { "post_id": 218 }
    }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-noise/run-insights-scan
wp ability run signal-noise/run-insights-scan --input='{"force": true}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/run-insights-scan/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"force": true}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Generate weekly content-direction recommendations grounded in actual analytics + publish cadence
- Force a fresh scan after a major content push so the recommendations reflect the new reality
- Surface the recommendations to an editorial dashboard so the operator sees them next admin session

---

### `signal-noise/get-insights`

**Category:** `diagnostics`
**Capability:** `manage_options`
**Annotations:** `readonly: true, idempotent: true, open_world_hint: false`

**Description:** Returns the cached result of the last synthesis scan (recommendations array + metadata). Returns null when no scan has run yet.

**Input:** None.

**Output:**
```json
{
  "scanned_at": 1748073600,
  "elapsed_ms": 4287,
  "recommendations": [
    {
      "id": "rec_01",
      "type": "write_about",
      "title": "Write the third Provenance pillar",
      "rationale": "/provenance traffic is the strongest growth signal but the catalog stops at two pillars.",
      "evidence_pills": ["plausible:+38% /provenance MoM"],
      "target": null
    }
  ]
}
```

**Invocation:**
```bash
wp ability run signal-noise/get-insights
```
```bash
curl -X GET "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/get-insights/run" \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Cheap read of the last scan result without paying the AI cost of a fresh `run-insights-scan`
- Render the cached recommendations in a dashboard widget on every admin page-load
- Detect "scan never ran" (returns null) and prompt the operator to trigger the first scan

---

### `signal-noise/unschedule-cron-event`

**Category:** `maintenance`
**Capability:** `manage_options`
**Annotations:** `destructive: true, idempotent: true, open_world_hint: false`

**Description:** Permanently removes a scheduled WP-Cron event (single OR recurring) by hook + args. SN-owned hooks (Plausible refresh, RSS prune) are refused with a clear error. The matching event is identified by exact args match — pass [] for events scheduled without args. Returns the count cleared (0 if no match). Useful for pruning orphaned cron events left by uninstalled plugins.

**Warning — destructive:** This permanently unschedules the cron event. There is no undo. SN-owned hooks are refused; everything else is fair game. Always inspect first with `list-cron-events` or `get-cron-event`.

**Input:**

| Property | Type | Required? | Allowed values | Default | Description |
|---|---|---|---|---|---|
| `hook` | string | yes | minLength 1 | — | The cron hook name to unschedule |
| `args` | array | no | — | `[]` | Must match the scheduled signature exactly; `[]` for events scheduled without args |

**Output:**
```json
{
  "success": true,
  "hook": "orphaned_plugin_cleanup",
  "args": [],
  "cleared": 1
}
```

**Invocation:**
```bash
wp ability run signal-noise/unschedule-cron-event --input='{"hook": "orphaned_plugin_cleanup", "args": []}'
```
```bash
curl -X POST "https://juanlentino.com/wp-json/wp-abilities/v1/abilities/signal-noise/unschedule-cron-event/run" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{"hook": "orphaned_plugin_cleanup", "args": []}' \
  -b "wordpress_logged_in_..."
```

**Use cases:**
- Remove an orphaned cron event left by a deactivated plugin (use `list-cron-events` first to confirm `has_handler: false`)
- Clean up a duplicated schedule after a buggy plugin re-registered the same hook with different args
- Attempt to unschedule an SN-owned hook to verify the safety refusal still fires (regression test)

---

## Cross-references

- Theme abilities source: [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise/blob/main/inc/abilities-registration.php) (theme v9.1.2+)
- Plugin abilities source: [`inc/abilities-registration.php`](https://github.com/juanlentino/signal-and-noise-tools/blob/main/inc/abilities-registration.php) (plugin v3.7.4+)
- Future AI harvester: [WordPress/desktop-mode PR #240](https://github.com/WordPress/desktop-mode/pull/240) (Agents framework, step 3 = Abilities-as-tools bridge)
- Upstream issue tracking: [WordPress/desktop-mode#271](https://github.com/WordPress/desktop-mode/issues/271)
