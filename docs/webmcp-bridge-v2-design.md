# WebMCP bridge v2: the reader's agent gets the site's own arithmetic (design)

Date: 2026-09-16. Status: approved design (owner answers folded in the same evening), pre-plan.
Amends: `docs/webmcp-native-design.md` (2026-08-28), whose "Decisions already made"
section fixed the tool pack at two and named a third tool out of scope. The owner
reversed that on 2026-09-16: "the additions are worth it for the times we're living
and all the capabilities everything has." This note records the reversal and what
it opens; the original's principle is untouched and repeated below because it is
the one that decides every question here.

## One sentence

The bridge grows from two tools to six in two arcs, each tool reading public bytes
and calling no authenticated door: arc one adds `related-notes`, `get-site-map`,
`get-citation` and a tool-call beacon so use is measured, not assumed; arc two adds
`search-notes` on the rights-signals worker with a Workers AI query embedding over
an index the plugin publishes. The bridge stays the fifth anchored rights surface;
each arc is one new anchored version.

## The rule that survives

**A rights-anchored site ships agent surfaces only from inside the anchored bytes,
and a bridge tool reads public bytes and calls no authenticated door.** Both existing
tools obey it (`verify-page` computes from the page's manifest; `get-rights-terms`
fetches public files). Every tool below is checked against it first.

## Decisions for the owner (2026-09-16)

- Tool pack after arc one: `verify-page`, `get-rights-terms`, `related-notes`,
  `get-site-map`, `get-citation`. After arc two: plus `search-notes`.
- Markdown stays out (Accept negotiation; the 2026-08-28 decision stands).
- No writes, nothing behind Access, `data-mcp-url="none"` unchanged.
- Arc two is gated on arc one's instrument: it is built when the beacon shows tool
  calls, or when the owner says build it regardless; either is a decision, not a drift.
- Cloudflare's WebMCP toggle stays off, forever.

## The tools

Each tool: what it answers, where the bytes come from, and its honest absence.

### `related-notes` (arc one)

- Answers: the notes the kernel ranks closest to this one: `[{title, url, score,
  shared_tags[]}]`, at most the block's limit, in the block's order.
- Source: a data-shaped `<script type="application/json" id="sn-related">` block the
  plugin emits on singular notes beside the verification manifest, from the same
  `snt_ml_related_for_post()` the block paints (one producer, two painters: the
  block for people, the manifest for agents). The tool parses it; no fetch.
- Absence: no manifest (a page that is not a note) returns `{ related: [] , reason:
  "not a note" }`; a note with an empty artifact returns `[]` with `reason: "nothing
  related"`; artifacts never built returns `reason: "not built"`. Zero and null stay
  different answers (the realtime-zero-vs-null rule).

### `get-site-map` (arc one)

- Answers: what the site is, as structure, everything public: pillars with their
  notes, every published note with title, url, date, tags, pillar, every published
  page (services, music, resume, contact, now, uses, accessibility, provenance and
  the hub), the provenance papers (SSRN ids, the JAES manuscript as "in submission"),
  the feeds, and the rights terms pointer. Owner decision (2026-09-16): "the sitemap
  should have everything"; the file is the machine twin of the site, not of the
  notes archive alone. Drafts, private pages and anything noindex stay out by the
  same rule that keeps them out of the XML sitemap.
- Source: `/notes/index.json`, published by the plugin at each artifact rebuild (the
  same trigger as related notes: a publish transition plus the daily backstop) and
  served as a static file with a short max-age. Public data only; the file carries a
  `built_at` and the corpus size. The tool fetches it once per page and caches.
- Absence: the file missing (never built) returns `reason: "not built"`; a fetch
  failure names the leg.

### `get-citation` (arc one)

- Answers: this note as `{bibtex, csl_json, canonical_url, anchored_hash, ledger_url,
  orcid}`. The hash and ledger URL come from the verification manifest when the note
  is signed, so a citation can carry the anchor.
- Source: the page's own JSON-LD Article (`inc/seo-schema.php`) for author, dates,
  headline, canonical, plus the verification manifest for the anchor. No fetch.
- Absence: not a note returns `reason: "not a note"`; unsigned returns the citation
  without `anchored_hash`, never an error.
- Owner decision (2026-09-16): the author field is `Lentino, Juan`, and the ORCID
  (0009-0006-8151-5920) rides on every note's citation, not only the provenance
  track's; one author, one identifier, whatever the note argues.

### `search-notes` (arc two)

- Answers: `[{title, url, score}]` for a free-text query, ranked by cosine over the
  notes' embeddings, the same `@cf/baai/bge-base-en-v1.5` vectors the kernel blends.
- Source: the bridge calls `POST /_sn/rights-signals/search` on the rights-signals
  worker (same-origin, the worker fronts the site). The worker embeds the query with
  its Workers AI binding, ranks against a vector index it holds in KV, and answers.
  The index is `/notes/vectors.json`, published by the plugin at rebuild beside the
  site map (55 × 768 floats, int8-quantized, about 40KB), fetched by the worker on a
  cron and on KV miss. The origin never sees a query.
- Limits: per-IP rate limit in the worker (the login-guard worker's shape), query
  length cap, a daily neuron budget with a refusal when crossed that names the
  budget. The refusal is a verdict, never an empty result.
- Absence: no index yet returns `reason: "not built"`; Workers AI unreachable
  returns `reason: "embedding unavailable"`.
- Why worker-side and not plugin-side: a public REST route on the origin would put a
  Workers AI call behind every anonymous request; the worker already fronts the
  site, already holds a Workers AI binding, and already rate-limits.

## The instrument (arc one, load-bearing)

Every tool call reports itself: the bridge fires a `sendBeacon` to
`POST /_sn/rights-signals/webmcp-call` on the rights-signals worker with
`{tool, outcome, ms}` and nothing else; the worker writes one data point to the
existing `SN_MR` Analytics Engine dataset with `family: "webmcp"`, `surface: <tool>`,
and the outcome in a blob. No IP, no UA sample, no page URL. The Machine Readers
sensor already reads that dataset; the plugin's summary gains a `webmcp` line (calls
per tool, 7 days) and the tile a short row. Retention is the dataset's own (Analytics
Engine keeps 90 days); a tool name and an outcome carry nothing worth keeping
shorter, and the gate for arc two wants at least a month of them. Owner: fine. The health check for agent readiness
does not change: the ladder is maxed and this is a reading, not a rung.

This is what arc two is gated on. A month of zero calls is a finding, and a cheap
one.

## Components

### 1. signal-and-noise-tools plugin (minor version bump)

- `inc/ml-related-manifest.php`: emits the `#sn-related` JSON on singular notes,
  from `snt_ml_related_for_post()`. Pinned by the same suite that pins the block.
- `inc/notes-index.php`: builds `/notes/index.json` (arc one) and `/notes/vectors.json`
  (arc two) at rebuild; both written to the uploads-adjacent static path the
  provenance ledger already uses, never generated per request.
- `inc/seo-schema.php`: no change; the citation reads what it already emits.
- `inc/machine-readers-summary.php` and the tile: the `webmcp` line.
- Health: `rights-anchored` already watches `webmcp-bridge`; a new bridge version is
  minted by the sweep. No new check.

### 2. sn-rights-signals-worker (minor version bump per arc)

- `src/webmcp-bridge-client.mjs`: the three tools (arc one) and the beacon; arc two
  adds `search-notes`. Still hand-written, no bundler, byte-deterministic, under the
  `keep_names: false` rule and the behavioural pretest gate (the `__name()` trap).
- `src/webmcp-call.mjs` (arc one): the beacon route, `SN_MR` write.
- `src/notes-search.mjs` (arc two): the search route, Workers AI binding, KV index,
  rate limit, budget.

### 3. sn-provenance-worker

- Nothing new. The hourly sweep versions `rights-signals/webmcp-bridge/v2` (arc one)
  and `v3` (arc two) automatically; the validator's marker (`registerTool`,
  `snWebmcpMain`) still matches.

## Rollout order (each arc)

1. Plugin first this time: the manifests and `/notes/index.json` must exist before a
   bridge that reads them ships, or the tools answer "not built" site-wide for the
   gap. Plugin release, updater install, files verified live.
2. Rights-signals worker second: the new bridge goes live; the sweep mints the next
   bridge version within the hour, inside the plugin check's 2h grace.
3. Plugin's `webmcp` sensor line can ride step 1 (it reads zero until step 2).

Arc two: plugin publishes `vectors.json` first, the worker's cron picks it up, then
the bridge with `search-notes`.

## Failure modes

| Condition | Behavior |
|---|---|
| Browser has no agent API | Silent, as today |
| Not a note | `related-notes`, `get-citation` return the honest absence |
| Artifacts never built | `related-notes`, `get-site-map` say `not built`, never `[]` |
| Bridge fetch of `index.json` fails | The tool names the leg; nothing thrown into the page |
| Beacon endpoint down | `sendBeacon` is fire-and-forget; tools still answer |
| Workers AI budget crossed (arc two) | Refusal naming the budget; never an empty list |
| Vectors stale vs corpus (arc two) | Result carries `index_built_at`; the agent can tell |
| Bridge bytes change | The sweep mints a new version; the plugin check watches the gap |

## Testing

- Plugin: manifest emission pinned per note state (signed, unsigned, no artifacts);
  `index.json` shape derived from the corpus fixture; the `webmcp` sensor line with
  zero and non-zero fixtures; negative controls for zero vs not-built.
- Worker: each tool as a pure function over a fixture page (manifest present, absent,
  malformed); beacon route writes the expected data point and nothing else (no IP, no
  UA); arc two: rate limit, budget refusal, and the KV-miss fetch, each with a
  negative control; the behavioural pretest gate drives registration on the built
  artifact.
- Live: curl the manifests on a note and on a page; fetch `index.json`; on a note in
  Chrome 146+, call each tool from the agent surface and read the beacon in the sensor.

## Out of scope (named, so they stay out)

- Markdown as a tool (Accept negotiation).
- Any write, any tool behind Access, any MCP server connection from the page.
- A browser-side model. The reader's agent brings its own; the PWA is the admin's.
- Policy prose. If the tools ever earn a §-mention, that is a `POLICY_VERSION` arc.
