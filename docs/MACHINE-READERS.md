# Machine readership

Shipped as a flagged preview in v9.85.0, GA in v10.0.0. Implementation:
[`inc/machine-readers-api.php`](../inc/machine-readers-api.php) (sensor read and
row normalization), [`inc/machine-readers-render.php`](../inc/machine-readers-render.php)
(pure renderers), [`inc/machine-readers-admin.php`](../inc/machine-readers-admin.php)
(tab registration and settings). The edge half lives in the `sn-rights-signals`
Worker, `src/machine-readers.mjs`. Tests:
[`tests/machine-readers-api.php`](../tests/machine-readers-api.php),
[`tests/machine-readers-render.php`](../tests/machine-readers-render.php),
[`tests/machine-readers-docs.php`](../tests/machine-readers-docs.php).

## Why

The beacon analytics pipeline is structurally blind to crawlers. Beacons need a
browser to execute JavaScript, and AI crawlers do not, so the entire machine
half of the audience was invisible to a site whose whole rights posture is aimed
at machines. Meanwhile the `sn-rights-signals` Worker already intercepts every
request on the zone, which makes it the natural observation point: no new
runtime, no new request path, no cost to the response.

Two questions justify the surface, and nothing beyond them:

1. Who reads the machine surfaces (`robots.txt`, `llms.txt`, the rights files,
   the feeds, the manifests)?
2. Do the crawlers that publicly declare themselves AI-training actually read
   the rights declarations that apply to them?

This is observation, never enforcement, and never proof of identity. User agents
are self-reported, so every reading in this surface is "what the edge observed",
crossed with "what the operator publicly declares". The admin captions say so on
the page, not just here.

## What the surface is

| Where | What it shows |
| --- | --- |
| **S&N Analytics, Machine readers** (view; native painter `apps/sn-analytics/parts/painters/view-machine-readers.php`, classic body `inc/analytics-view-machine-readers.php`) | Who reads, and for what. Its own 7/14/30/90-day window (a longer page range opens at 90 days and says so). A hero band: the evidence legend, the headline tiles, and one finding built from the payload (training reads claimed, the control-token reads taken out, how many Cloudflare verified and from whose network), linking to Reader evidence. Then the family delta cards (readers only); Who reads and What for (each family and purpose with its evidence strip) and Rights files and Feed fetches, each pair side by side only when within 1.5x of each other's height; and the reference bands (observed vs declared, AI-training counted both ways, agent by purpose, surfaces), each capped with a "+N more" line. No folds. Both machine views read the edge sensor with its own credentials, so they open with Analytics Engine unconfigured. |
| **S&N Analytics, Reader evidence** (view; `view-reader-evidence.php`, `inc/analytics-view-reader-evidence.php`) | How each count is known. Claim against proof (two tiles, the strip, the since line); Signed beside Verified, and the line that tells them apart; the split by family and by purpose; control tokens; "Is this crawler real" (named agents with reads Cloudflare did not verify, each with its top network); the unnamed samples with their verified reads and networks; and reader anomalies (a two-series verified and unverified day histogram on the native surface, a day table on the classic one, then the co-movement verdicts). |
| **Monitoring, Machine Readers** (S&N Dashboard leaf and wp-admin tab) | Configuration only since the evidence-basis arc: the sensor status pills with the two links "Machine readers in S&N Analytics" and "Reader evidence in S&N Analytics", the two Site Health verdicts (crawler-list drift, reader anomalies), the Edge sensor readout beside the Sensor settings form (`sn_action=machine_readers_save`, unchanged), and the monthly rights evidence, which stays here because it posts forms. Before the arc this leaf was the MIXED composition of v12.22.0 (`docs/proposals/admin-leaf-composition-2026-08-23.md`); every reading it carried now lives in one of the two views above. |
| **SN Provenance** (Desktop Mode tile; SN Machine Readers from v10.1.0, folded into it on 2026-10-04) | A glance under the anchors: machine reads in the window, the identity split (this site’s own tools a part of their own, not readership), the parts of the bar as rows with what sits inside each nested under it (verified by Cloudflare and by the vendor's published ranges; reads that failed a check, by reason, and control tokens under "Named themselves, not verified"), AI-training reads as the verified figure with the claimed figure in the row's title and a small evidence strip, the top reader family (first-party and control tokens out), and the crawler-list verdict only when it is not in sync, served by `/wp-json/signal-noise/v1/desktop/machine-readers`. Its link opens Machine readers in S&N Analytics. |
| **Content Health, Rights signals** | A separate drift probe ([`inc/health-check-rights-signals.php`](../inc/health-check-rights-signals.php)) that verifies the rights surfaces themselves are still standing. It is a sibling of this surface, not part of it. |

WordPress stores none of this data. Every number on the tab is a live read of the
sensor, held only in a short display transient.

## The sensor contract

Three endpoints on `juanlentino.com`, all served by the `sn-rights-signals`
Worker's own pathname dispatch (one wildcard Cloudflare route, not one route per
surface).

| Endpoint | Method | Auth | Answers |
| --- | --- | --- | --- |
| `/_sn/rights-signals/machine-readers?days=N` | GET | `Authorization: Bearer <SN_MR_READ_TOKEN>` | `200 { worker, days, data: [ { family, surface, day, hits } ] }` |
| `/_sn/rights-signals/machine-readers?days=N&view=totals` | GET | same | `200 { worker, days, view, data: [ { day, hits } ] }` |
| `/_sn/rights-signals/version` | GET | none | `200 { worker, version, cf_version_id, cf_version_tag, deployed_at }` |
| `/_sn/rights-signals/crawler-list-status` | GET | none | `200 { worker, last_check }` |

Notes that matter when reading the responses:

- **The aggregate read is CAPPED, and the cap is now declared.** The aggregate
  groups by eleven dimensions crossed with day, so its row count scales with the
  window. Until Worker v1.23.0 it declared no `LIMIT` and inherited the SQL API's
  own silently while reporting `limit: null`. Because a consumer derives a total
  by summing the returned rows, a truncated read did not look degraded, it looked
  like less traffic: a 60-day read once summed to barely more than a 30-day read,
  and a derived prior period reported a 15x surge that never happened.
- **`view=totals` is the figure to trust.** It groups by day alone, so a 90-day
  window returns at most 90 rows and the sum is exact however wide the window
  gets. `snt_mr_summary_payload()` reads it for `total` and falls back to the
  aggregate sum against an older edge, reporting which it used in `total_exact`.
  Every response also carries `rows` and `truncated`.
### Why the numbers are the size they are (2026-08-29)

Two figures on this surface look alarming and are not. Both were investigated to a
definite answer, and both are recorded here so the investigation is not repeated.

**Coverage is 33 days because the sensor is 32 days old.** A 60-day read returns
about 33 day-rows, so a derived prior period over days 31 to 60 sees roughly two
days of traffic and reports it as a collapse or a surge depending on direction.
The cause is neither retention nor truncation nor sampling: the machine-readership
sensor shipped in Worker **v1.4.0 on 2026-07-28**, and the dataset holds exactly
what has been written since. Analytics Engine's own retention is far longer, so
coverage grows by one day per day with no action required.

- `days_covered` reports it, counted from the totals view's day-rows.
- The dashboard widget refuses to derive a delta while the requested baseline
  window exceeds it, showing "33d of data" in place of a comparison.
- **REVISIT CONDITION: the 30-day-over-30-day delta becomes honest on 2026-09-26**,
  when the sensor reaches 60 days. Nothing needs changing then; the guard stops
  firing on its own. If a delta is still suppressed after that date, the cause is
  new and worth investigating.

The separate `LIMIT` work in Worker v1.23.0 stands on its own terms. The aggregate
view really did inherit the SQL API's row cap while reporting `limit: null`, which
is a defect whether or not it had fired yet. It was not, however, the cause of the
delta, and the `totals` view added alongside it is what made the real cause
measurable.

**The `unknown` purpose share is a maturity curve, not a gap in the code.** About
a third of third-party reads carry `purpose = unknown`, which means the taxonomy's
substring tokens matched nothing rather than that an entry declares that purpose
(only four entries do). The purpose axis landed 2026-08-10 and the taxonomy has
been effective since 2026-08-11, so the classification is younger than three
weeks. The mechanism that closes the gap is already running: RULE 2 stores a
sanitised user-agent sample for every unmatched row, and the review list on this
tab ranks them by volume. Coverage is extended from that evidence, on the vendor
and purpose axes, never by editing the frozen family enum.

Note that `unknown` folds two populations into one bucket: rows the Worker marked
unknown, and rows carrying a purpose this plugin does not recognise
(`snt_mr_normalize_taxonomy_fields()` maps both). Today those are the same
population, because the Worker's `purpose_vocabulary` and
`snt_mr_valid_purposes()` are identical, 13 values each. Extend both halves in
lockstep or that distinction goes quiet.

- **`days` is clamped on both sides**, 1 to 90, default 30. The plugin clamps
  before it asks ([`snt_mr_fetch()`](../inc/machine-readers-api.php)) and the
  Worker clamps again before it queries. The value is never string-interpolated
  as user input into SQL.
- **`hits` is a sampled count.** Analytics Engine samples, so the Worker reads
  `sum(_sample_interval)`, not `count(*)`. Treat the numbers as accurate in
  proportion, not as an exact request log.
- **`last_check` is isolate memory, best effort.** It resets on deploy or
  eviction, so `null` right after a deploy is expected and is not a failure. The
  durable trail is Workers Logs.
- **The contract minimum is `SN_MR_SENSOR_MIN`, currently `1.12.0`.** The Sensor
  panel compares the deployed `version` against it and warns when the edge is
  behind what these panels are built for.

### The two enums

Everything the Worker returns is one of a fixed set of strings. This is the load
bearing privacy and security property of the whole surface, so both enums are
mirrored in `inc/machine-readers-api.php` and in `src/machine-readers.mjs`, and
the rule is: extend BOTH or neither. `tests/machine-readers-docs.php` fails if
the code allowlists and this page drift apart.

**19 families** (`snt_mr_valid_families()`), first match wins in the Worker, with
the specific families ahead of the generic buckets:

| Class | Families |
| --- | --- |
| Declared AI training | `openai`, `anthropic`, `google-ai`, `commoncrawl`, `bytedance`, `apple-ai`, `meta-ai`, `mistral`, `cohere`, `allen-ai` |
| Other named machines | `perplexity`, `amazon-ai`, `diffbot` |
| Generic buckets | `search`, `seo`, `feed`, `uptime`, `other-bot` |
| Additive (v10.79.0) | `unclassified-machine` |

The AI-training class is the static half of the observed vs declared read
(`snt_mr_ai_training_families()` in the render lane). It comes from public
declarations, not from anything the request proves.

### `family` is frozen

The first 18 values are **frozen**: their meaning and their population do not
change, and `tests/machine-readers-docs.php` pins the list and its order. A
published number (77 AI-training reads, 30d to 31 July 2026, scheduled note
2071) depends on what they meant, and this field has already moved underneath a
published figure once.

Two of them are **known to be wrong** against the vendors' current documentation
and stay wrong deliberately:

- `google-ai` matches `googleother`, which Google documents as a *generic*
  crawler used by various product teams, not an AI fetcher.
- `mistral` matches all three Mistral agents, including `MistralAI-Index` and
  `MistralAI-User`, both of which Mistral states are **not** used for training.

Both sit inside the AI-training class, so both inflate it. The correction lives
on the `purpose` axis, never by editing the family list.

`unclassified-machine` is the one addition. It carries **only** rows the
Worker's frozen classifier would have dropped entirely: `facebookexternalhit`,
`meta-webindexer`, Slackbot, WhatsApp, `ia_archiver` and similar, all of which
returned `null` and were indistinguishable from humans. No existing value's
meaning or population moves, so a query filtering the original 18 returns the
same rows it always did.

## The vendor and purpose axes (v10.79.0, Worker v1.11.0)

`family` answers *which crawler*. `purpose` answers *what for*, which is the
axis the published claims run along. They are matched **independently** against
the raw User-Agent: purpose is never derived from family, which is what lets
`Claude-SearchBot` keep `family=other-bot` while being visible as
`anthropic` / `search`.

The classification lives in **data, not code**: `src/machine-reader-taxonomy.json`
in the Worker repo, versioned and dated, served verbatim and unauthenticated at
**`https://juanlentino.com/_sn/rights-signals/taxonomy`** so any number derived
from it can be checked against it.

**13 purposes** (`snt_mr_valid_purposes()`), a closed set:

| Purpose | Meaning |
| --- | --- |
| `train` | Declared AI training-corpus collection |
| `search` | Index building for a search product |
| `retrieval` | Live grounding for an AI answer, agent-initiated |
| `user` | User-directed single fetch, not a crawl |
| `archive` | Web archiving |
| `ops` | Uptime and monitoring |
| `seo` | Backlink and SEO crawlers |
| `feed` | RSS and JSON Feed readers |
| `social` | Link unfurlers and preview fetchers |
| `security` | Scanners and internet measurement |
| `dev` | Libraries and scripted clients |
| `ads` | Ad-safety validation and catalogue fetches |
| `unknown` | Unclassified, or a documented agent the vocabulary has no home for |

`vendor` is an **open** field, not an enum: new organisations appear without a
plugin release. It is constrained by shape instead (`snt_mr_normalize_vendor()`:
lowercase alphanumerics, dot and hyphen, 32 characters), so nothing that
survives can carry markup even before escaping.

### Rules that hold across the whole file

- **Every `*-User` agent is `user`, never `train`.** Vendors treat these as
  outside their training-crawler rules; counting them as training would
  overstate the claim. Pinned by test in both repos.
- **`training_corpus_source` is a separate boolean**, so an agent can be both
  archival and a known training-corpus source without either `archive` or
  `train` quietly meaning two things. CCBot is `archive` + true; Amazonbot is
  `search` + true.
- **`declared` separates a vendor's own published statement from third-party
  inference.** Bytespider, cohere-ai and Diffbot are `declared: false`: no
  first-party crawler page exists for them. A surface whose claim is "observed
  versus declared" must not blur the two.
- **Control tokens that never fetch are exempt from drift, not deleted.** Apple documents
  that **Applebot-Extended does not crawl**: it is a robots.txt token used only
  to govern how data already collected by Applebot may be used. The requested
  Apple train/search split therefore **cannot be measured from request logs**,
  and the `apple-ai` family reports a phantom: any non-zero count is spoofed or
  synthetic. `Google-Extended` is the same shape. Since v13.74.0 this is
  ENFORCED, not merely described: `SN_FAMILY_DRIFT_UNOBSERVABLE` in
  `inc/family-drift.php` exempts such families from the weekly `ours_unmatched`
  row, whose sentence ("either the vendor is gone or its user agents changed")
  was wrong about `apple-ai` every week, and reports them in a separate
  `unobservable` row, so the exemption is visible rather than silent. (Earlier
  revisions of this file described an `observable: false` FIELD. No such field
  ever existed in the code; the prose had outrun the implementation.)
- **A crawler that advertises no User-Agent of its own cannot be attributed at
  all.** Brave Search states that its crawler "does not advertise a
  differentiated user agent", deliberately, so that sites allowing only
  Googlebot cannot single it out
  ([Brave Search Crawler](https://search.brave.com/help/brave-search-crawler)).
  That leaves nothing to put in `match`, so no taxonomy entry is possible, and
  `observable: false` would be the wrong shape: Brave does fetch, it simply
  does not say so. Inventing a token would manufacture a cohort on a surface
  that is published precisely so its numbers can be checked, so the entry stays
  unwritten and Brave's reads land wherever its borrowed User-Agent lands. The
  beacon pipeline cannot see it either, since a crawler runs no JavaScript, so
  this reader sits outside both instruments by construction. Read the absence
  as **unmeasurable, never as zero**. Brave also gates on Googlebot: a page
  Googlebot cannot crawl is one Brave will not crawl, so the `robots.txt`
  posture is inherited rather than separately expressible. Checked against
  Brave's published crawler page on 2026-08-29.
- **`agent` names the exact crawler** (v10.80.0). vendor plus purpose already
  separates GPTBot from ChatGPT-User, but the agent id says so outright, and it
  is stored for Analytics Engine's 90 days rather than Workers Logs' 7.
- **`first_party` flags the site's own monitoring.** At v1.11.0 the owner's
  Better Stack monitor was 6,403 of 17,463 reads (37%): the site measuring
  itself rather than readership. Purpose totals exclude it and say so.

**12 surface classes** (`snt_mr_valid_surfaces()`), coarse on purpose so that no
full path is ever stored:

| Surface | Matches |
| --- | --- |
| `robots` | `/robots.txt` |
| `rights` | `/.well-known/tdmrep.json`, `/license.xml`, `/tdm-policy` |
| `llms` | `/llms.txt`, `/llms-full.txt` |
| `agents-manifest` | `/.well-known/agents.json` |
| `agent-discovery` | `/.well-known/mcp/server-card.json`, `/.well-known/api-catalog`, `/.well-known/ai-catalog.json` |
| `well-known` | any other `/.well-known/` path |
| `feed` | the feed routes |
| `wp-json` | the REST surface |
| `sitemap` | any path containing `sitemap` |
| `resume` | the résumé's format files: `/resume.md`, `/cv.md`, `/resume/json-resume.json`, `/cv/json-resume.json`, `/resume/contact.vcf`, a PDF under `/wp-content/uploads/resume/`, and the `/resume.json` and `/cv.json` page twins. Not a rights surface. The format rides `resume_format` (blob17): `md`, `json`, `vcf`, `pdf`, `twin`, and `html` or `md` for a read of the `/resume/` or `/cv/` page, which stays `html`. The Machine readers view paints it as the Résumé panel, beside the people who downloaded the PDF |
| `asset` | `/wp-content/`, `/wp-includes/` |
| `html` | everything else |

Unknown values fail into the enum rather than through it: an unrecognized family
normalizes to `other-bot`, an unrecognized surface to `html`, a malformed day to
an empty string, and `hits` to a non negative integer
([`snt_mr_normalize_rows()`](../inc/machine-readers-api.php)). A hostile Worker
response therefore cannot put an arbitrary string on an admin page even before
escaping gets a turn, and the render lane escapes every cell anyway.

## Evidence basis (Unreleased, remote contract 16)

Every count attributed to an identity (a vendor family, a purpose, a traffic
class) says how that identity is known, in one of five words
(`SN_MR_BASIS_VALUES`, `inc/machine-readers-basis.php`):

| Basis | Means |
| --- | --- |
| `signed` | A cryptographic signature checked out: Web Bot Auth `valid` (the summary's `identity`), or the provenance ledger's own signature. |
| `verified` | Cloudflare's verified-bot category was on the request (`verified_bot`), on a day the sensor recorded it. Not a signature. |
| `claimed` | The client said so: a user agent the taxonomy matched. |
| `inferred` | A rule or a score decided it: the human, suspect and bot classes; the beacon's likely-automated score. |
| `unmeasured` | The signal was not recorded for these hits. |

Verified keeps the rights-evidence meaning: a row is classed by
`sn_rights_evidence_identity_class()` and `SN_RIGHTS_EVIDENCE_VERIFIED_SINCE`,
called, never copied (`snt_mr_row_verification()`). A row with no valid day is
unverifiable. Rights evidence schema 2 is unchanged.

### The split

The machine-readers summary carries `verification`: per family, per purpose,
for `ai_training` and for `ai_training_by_purpose`, a split
`{claimed, verified, verified_matched, unverified, unverifiable}` where claimed
is the sum of the last three. `verified_matched` is verified AND the category
fits the claimed purpose (`SN_MR_PURPOSE_CATEGORIES`):

| Purpose | Categories that fit |
| --- | --- |
| `train` | AI Crawler, Training |
| `search` | Search Engine Crawler, AI Search, Search |
| `retrieval` | AI Assistant, AI Search, Agent, Search |
| `user` | AI Assistant, Agent |
| `archive` | Archiver, Academic Research |
| `ops` | Monitoring & Analytics, Webhooks, Monitoring & Operations |
| `seo` | Search Engine Optimization, SEO |
| `feed` | Feed Fetcher, Aggregator, Feed Fetching |
| `social` | Page Preview, Social Media Marketing, Social / Link Preview |
| `security` | Security, Security Testing |
| `ads` | Advertising & Marketing, Ads Verification |
| `dev`, `unknown` | none: they never match |

Each row lists Cloudflare's legacy category string and the July 2026 behavior
name, matched without regard to case, because the strings the edge stores could
not be read back through any tool when the table was written. Crosstab cells
carry the same split, plus `first_party_hits` (the exact part from this site's
own tools; `first_party` is true when it is above zero), `impersonation`,
`impostor_hits` and `basis`. A read before the since date is unverifiable: the family and agent
exceptions in Reader evidence read each agent's whole split, so such a read
never makes an agent or family "never verified".

### Control tokens are not crawlers

Google-Extended and Applebot-Extended are names a vendor reads in robots.txt,
not user agents of their own (Google's common-crawlers page; Apple's support
article 119829). A request presenting one is never the vendor's crawler, so its
hits are credited to no family and no purpose: they leave `families`,
`purposes`, `ai_training` and `ai_training_by_purpose`, stay in `total`, and are
listed in `impersonations` (`{agent, token, hits, networks, basis: claimed}`).
The list mirrors the taxonomy's `observable: false` entries
(`SN_MR_CONTROL_TOKENS`). Rights evidence still counts the google-ai family's
hits, a decision left to the owner.

### Impostors are counted, not credited

An impostor is a hit that claims an agent Cloudflare verifies when genuine and
that Cloudflare did not verify, on a day the sensor recorded verification. The
rights-signals taxonomy (1.5.0) marks those agents `cf_verified: true`: the ones
Cloudflare's verified-bots directory lists. The sensor stamps `cf_verified` on
every aggregate row from its agent id at read time, so the rule is a pure
function of the row (`snt_mr_is_impostor()`, `snt_mr_credit_class()`:
`credited`, `control_token` or `impostor`). Like a control token, an impostor
leaves `families`, `purposes`, `ai_training`, `ai_training_by_purpose` and
`reader_families`, stays in `total`, and is listed apart in `impostors`
(`{agent, hits, reasons, networks, basis: inferred}`): a rule decided it, so its basis
is inferred, never claimed. Crosstab cells keep every hit and name the part in
`impostor_hits`; lanes file it as `claimed_bot` / `impostor`.

Never an impostor: a verified hit, whatever its network (Claude-User fetches
from Google Cloud and Cloudflare verifies it); a hit before the since date
(unverifiable), by any rule; this site's own tools; a row from a sensor that
sends none of the fields below.

#### Reasons beyond Cloudflare's directory

Cloudflare's directory does not list PerplexityBot, Perplexity-User, CCBot,
Bytespider or cohere-ai, so `cf_verified` alone left about 2,400 of their reads
in 14 days credited, mostly from "Google LLC". Three more signals, each with
its own reason, decided in one ladder (`snt_mr_credit_verdict()`,
`inc/machine-readers-basis-ladder.php`), first match wins:

1. control token: `control_token`.
2. first party, or unverifiable: credited.
3. Cloudflare verified: credited, basis `verified`.
4. `vendor_verified` `1` (rights-signals taxonomy 1.6.0, blob16: the client IP
   is inside the vendor's own published ranges, Perplexity and Common Crawl):
   credited, basis `vendor_verified`. It counts as verified everywhere verified
   is shown, and the verification split names it (`vendor_verified`, a part of
   `verified`).
5. a `cf_verified` agent, unverified: impostor, `cf_directory`.
6. `vendor_verified` `0` AND off the vendor's home network: impostor,
   `vendor_ranges`. An out-of-range IP alone is not enough: vendor lists are
   incomplete in practice.
7. off the home network (`home_network` `0`, stamped at read time from the
   taxonomy's `home_networks`; a row with no network is never off it):
   impostor, `off_home_network`.
8. a vendor claim from a rotator network: impostor, `rotator_network`. A
   rotator network presents at least `SN_MR_ROTATOR_MIN_AGENTS` (5) distinct
   vendor agents in the window being folded, each unverified from it.
   First-party rows, control tokens and the vendorless dev agents do not count.
   It is window-dependent, so `snt_mr_credit_context()` computes it once per
   fold, and every per-row call (`snt_mr_credit_class()`,
   `snt_mr_is_credited()`, `snt_mr_is_impostor()`, `sn_lanes_machine_key()`)
   requires that context: a call site that forgets it fails loudly.
9. otherwise credited, basis claimed.

`impostors[]` gains `reasons` (per agent, hits per reason), and every surface
that names impostors says the total, then the count per reason. The reader-anomalies series keep
impostor reads, because a co-movement of them is the signal that found them
(2026-10-18: one "Google LLC" operator, about 30 crawler names, all
unverified). The "is this crawler real" table and the daily verified and
unverified columns keep them too: they are the evidence.

### First-party apart

The sensor's `first_party` flag (this site's own probes, ledger verifier and
uptime monitor) decides first-party. `families[].first_party_hits` shows the
part of a family that is first-party (unclassified-machine is mostly that);
the desktop and public split of machine reads gains a `first_party` part; the
reader-anomalies series leave first-party reads out entirely.

### The unnamed list

`get-machine-readers-crosstab`'s `unnamed` reads the unknown view with
`detail=network` (rights-signals 1.30.0: rows split by `verified_bot` and
`network`, capped at 10,000), folds them per sample and keeps the top 20, each
with `verified_hits`, `not_measured` and its top 5 networks. A worker before
1.30.0 ignores the parameter, sends no `detail` in the envelope, and the list
reads `verified_hits: null`, `basis: unmeasured`, never 0. That view has no
day, so hits less `verified_hits` is unverified or unverifiable: a read from
before the since date counts as not verified there. Reader evidence in
S&N Analytics reads the same split view and counts every sample, paints the
busiest 20 with a "+N more" line, and says "at least" when the count is a
floor: the read was truncated, or an older worker answered only its top 50.

### Where it is painted

The two S&N Analytics views (see the surfaces table) paint the split with one
primitive, the evidence strip (`snt_evidence_strip()`, `inc/evidence-kit.php`):
a bar whose parts are verified for the claimed purpose (solid), verified but
not for that purpose (accent outline over a half fill), unverified (hatched)
and unverifiable (dashed outline), with the counts as text beside it, so the
bar is never the only carrier of a number (it is `aria-hidden`). A view states
the legend once, a swatch per part (`snt_evidence_legend()`), and each count that has a basis carries a basis chip
(`snt_basis_chip()`; `signed` reads as success, `verified` as info, the rest
plain, `unmeasured` as "not measured"). A null split paints "not measured",
never a zero bar.

The summary gains `reader_families`: the top 3 third-party families by reads,
first-party and control tokens out, each `{family, hits, verified}`. The
desktop tile and the dashboard's Top families list read it, so the top family
is a reader, not this site's own ledger verifier.

### Reader anomalies

First-party reads and control tokens leave every series. Each family carries `by_verification`, a
verified and an unverified series over the days the sensor recorded
verification, with signals withheld until a series is present on 20 of them.
Three or more families deviating on the same day, mostly on unverified reads,
from the same network are one `reader_co_movement` signal naming the network,
with the per-family signals inside it as `members`.

## Rights evidence, schema 2 (Worker v1.29.0)

Once a month the plugin composes one signed, anchored record per AI-training
family (`inc/rights-evidence*.php`, posted by the provenance worker under
`rights-evidence/<uuid>/v1`). Schema 2 changes what the record claims.

### The filtered rights view

`?view=rights&days=N` takes two optional filters since Worker v1.29.0:
`family=<one family>` and `exclude_purpose=<comma list>`. Both apply in SQL
before the 500-row `LIMIT`, so a filtered read is not truncated by other
families' rows. The response echoes `filter:{family,exclude_purpose}`; an
invalid value is a 400 `{error:"bad_filter",field,reason}`.

On the WordPress side `snt_mr_fetch( $days, 'rights', $filter )` passes the
filter through `snt_mr_rights_filter()`: the family must be one of
`snt_mr_ai_training_families()`, each purpose one of `snt_mr_valid_purposes()`.
A refused filter returns `bad_filter` with no request; it never falls back to
an unfiltered read. The filter is part of the cache key
(`sn_mr_rows_<days>_rights_f<family>_x<purposes>_g<generation>`), and
`snt_mr_cache_flush()` bumps the generation (option `snt_mr_cache_gen`) instead
of deleting every possible filtered key, so after a flush every filtered read
refetches; unfiltered keys carry no generation and are deleted as before. Rights evidence reads the stream
once per family per pass with `exclude_purpose=dev,ops` (`SNT_MR_RIGHTS_EXCLUDE`):
our own probes and scripted clients are not evidence of anything.

The worker excludes on the RAW purpose. A row written with an empty purpose
survives the filter and is normalized to `unknown` here.

### The purpose split

- `rights_reads`: fetches of the rights files with purpose `train` only, the
  training claim. `{reads, by_purpose, by_path, first, last, complete}`, with
  `by_path` keyed by purpose, then path.
- `retrieval_reads` (owner ruling D2): the same shape for every other recorded
  purpose except `ops` and `dev`: `search`, `user`, and so on. A search or user
  agent reading `/license.xml` is a retrieval read, not a training crawler
  going looking for the reservation.
- `unlabelled_reads`: the same shape for rows with no recorded purpose (`''` or
  `unknown`), claimed by neither block above: an unlabelled row could be a
  training crawler under a user agent the taxonomy missed.
- `crawling.by_surface` is keyed by purpose (the unlabelled under
  `unlabelled`), then surface. `crawling.train` is
  unchanged.

### What "in force" means

The reservation block is `{window:{start,end}, signals:{<slug>:[...]}}`. Each
entry is `{block, content_hash, valid_from, valid_to, version}`:

- `block` is the Bitcoin block height the public ledger names for that version
  (`rights-signals/<slug>/vN.json`, `ots.bitcoin_block`), walked for
  N = 1 to the current version in `index.json`.
- `valid_from` is that block's time, read from the Esplora API at
  `blockstream.info` (the explorer the provenance worker already uses). The
  anchor is the earliest moment the ledger can prove the bytes existed, so a
  version is never claimed before it. Block header times can differ from wall
  time by up to about two hours.
- `valid_to` is the next anchored version's `valid_from`, or `null`.
- A version not yet in a block is not listed, and leaves the version before it
  open-ended.

Every version in force at any point of `[window.start, window.end]` is listed.
Confirmed versions and block times never change, so they are cached for good in
`sn_rights_evidence_chain` (versions per ledger base URL, so another owner/repo
never reuses them); a malformed `rights_signals` row in the index, a missing
version file or a block time the explorer cannot give refuses the record rather
than shipping a reservation with a hole.

### Identity

`crawling.reads` and `crawling.train` count requests by the user agent they
claimed (owner ruling, 2026-10-01). The `identity` block says how much of each
count could be checked:

```json
"identity": {
  "basis": "claimed user agent",
  "verification": { "source": "cloudflare verified bot category", "since": "2026-09-27T15:49:59Z" },
  "crawling": {
    "reads": { "verified": 0, "unverified": 0, "unverifiable": 0 },
    "train": { "verified": 0, "unverified": 0, "unverifiable": 0 }
  },
  "rights_files": "claimed user agent; the rights stream records no verification"
}
```

- `verification.source`: the verified-bot category Cloudflare sets on a request
  it has matched to a known bot operator (`verified_bot` on an aggregate row,
  Worker v1.27.0, set by a zone Transform Rule, so a client cannot set it).
- `verification.since`: when the sensor began recording it, the first
  aggregate row with a non-empty `verified_bot`
  (`SN_RIGHTS_EVIDENCE_VERIFIED_SINCE`).
- `verified`: requests on a day wholly at or after `since` that carried a
  verified-bot category.
- `unverified`: requests on such a day that carried none. This means the
  request was not matched to a verified bot. On its own it says nothing about
  who sent the request.
- `unverifiable`: requests on a day before `since`, when nothing was recorded.
  Aggregate rows are per UTC day, so the partial day verification began on
  (2026-09-27) is unverifiable as a whole.
- Each triple sums to its crawling count, and no `train` component exceeds the
  matching `reads` component. A window ending before `since` has no verified or
  unverified requests; a window starting at or after it has no unverifiable
  ones. The ledger's checker (`rights-evidence-checks.mjs`) enforces all of
  this for schema 2.
- `rights_files`: the rights-file read blocks (`rights_reads`,
  `retrieval_reads`, `unlabelled_reads`) are by claimed user agent only. The
  rights stream records no verification.

### Schema and the rest

- `schema: 2` rides the payload. v1 records carry no field and are implicitly 1.
- `sensor.taxonomy` comes from the aggregate envelope's `taxonomy_version`
  (else the first row that names one); the version endpoint does not report it.
  An empty taxonomy refuses the record.
- `wp sn rights-evidence dry-run <YYYY-MM>` prints the payloads a month would
  carry today (identity included); only a complete month whose start is within
  the sensor's 90-day window can be composed. `--erratum` prints, per posted v1
  record with a ledger path (retracted ones included), the reservation that was
  in force, for the ledger's erratum document (a correction on the ledger is a
  retraction, never a v2); it reads no sensor. Neither posts; Monitoring > Machine Readers has a View
  door per held or waiting month, and Lift, Hold and Post now buttons (both twins).

### The review window, holds and refusals

Monthly evidence posts without anyone pressing a button, after a window in
which it can be stopped.

- **Compose, then wait.** The first daily pass of a month composes the last
  complete month's records and stores each with status `composed`,
  `review_until` (compose time + 72 hours, `SN_RIGHTS_EVIDENCE_REVIEW`) and a
  `summary` of its `{reads, train}`. It posts nothing. A later pass posts a
  composed record from an hour before `review_until` on
  (`SN_RIGHTS_EVIDENCE_REVIEW_SLACK`, so the fixed-time daily pass on the third
  day is not a few minutes early), and only while the month is not held. An entry stored before the window existed has no `review_until` and is
  due, so an `unanchored` retry is unchanged. `rights-evidence-now` runs the
  same pass: it composes and refreshes, and posts only what is past its window.
- **Hold is the opt-out.** The hold list (`sn_rights_evidence_hold`) is
  unchanged in meaning: a held month never posts. It still composes, so View
  streams the stored bytes that would post (a dry run only when nothing is
  stored). A held month is queued in the backlog; once lifted, its stored bytes
  post at the next pass if their window has passed. On Machine Readers a
  waiting month has View, Hold and Post now; a held month has View and Lift.
  Post now (owner, behind a confirm) posts a composed, unheld month at once,
  bypassing the window; it never composes.
- **Two rules hold a month on their own,** each storing a reason in
  `sn_rights_evidence_hold_reasons` (shown on both twins, in the watch and in
  the `rights-evidence` read's `hold_reasons`; Lift clears it):
  (a) a compose or ledger-walk error (a failed sensor read, an unreadable
  ledger index or rights-signal history, no taxonomy) holds the month with the
  error; (b) a family whose `crawling.train` moved more than 3x either way
  against the previous month's posted value, both at 50 or more, holds it with
  both numbers. The previous month's bytes are dropped once posted, which is
  why each entry keeps its `summary`; the rule applies once the previous month
  has one (the month after this shipped).
- **The worker can refuse.** A record the worker finds invalid is answered
  `422 {ok:false, error:"rights-evidence refused", divergences:[[block,
  message], ...]}`. The entry becomes `refused` with its `divergences`, its
  bytes are dropped (refused bytes are never re-sent) and the month is held
  with the divergences as the reason; the next pass recomposes the family into
  a fresh window, even while the month is held (a held backlog month is worked
  only for that, inside the sensor window, and never posts). Only that exact
  shape is a refusal (`ok` false, `error` exactly `rights-evidence refused`,
  `divergences` a non-empty list of `[string, string]`); any other 422, like a
  transport failure, stays `unanchored` with its bytes and is retried daily.
- **One hold per pass.** A pass reads the hold at its start (and once more as
  it takes its lock) and keeps that answer: a Lift landing while it runs takes
  effect at the next pass, never this one. A refusal during the pass stops the
  month's later sends at once.
- A month's window, as the status line, the watch and `in_review` report it,
  is the earliest `review_until` among its unposted records: each record posts
  on its own window. A hold reason is cut at 300 bytes on a character boundary.
- **No month is stranded.** At the start of every pass (after the ledger
  refresh, before the target is chosen) any stored month other than the
  current last complete month that still has unposted work (bytes composed or
  unanchored, or a refused entry awaiting recompose) is added to the backlog.
  A month composed late, whose window ends after the calendar turns, or lifted
  after the turnover, is worked by a later pass and posts once its window
  passes; Post now is never the only way out.
- **The backlog is not fooled by the window.** A backlog month whose unposted
  records are all in their window is not a backlog target (the pass works the
  current month meanwhile) and not a failure: it stays queued with its failure
  count unchanged, posts once the window passes, then leaves the backlog.
- **The notice is a watch,** `rights_evidence_review` in `inc/watches.php`:
  ripe while a month is composed, unposted and not held (the note carries the
  hours left) or while a rule holds one (the note carries the reason). An
  owner's own hold has no reason and stays quiet. The morning brief lists ripe
  watches; there is no email.

### Retracting a record

A posted record is never edited; it is retracted. Monitoring > Machine Readers
(both twins) paints, per record that is `confirmed`, has a ledger path and has
owner-approved text in `inc/rights-evidence-retractions.php`, the exact text
that will be published and a Retract button behind a confirm. One click posts
one body to the provenance worker's `POST /retract` (same HMAC, no redirects,
same outbound gate as the record post):
`{note_uid, version: 1, retracted_path, what_was_wrong, claimed, root_cause,
what_changed, retracted_at}`, `retracted_path` being the stored ledger path
byte for byte. The worker signs, stamps and commits
`retractions/<uid>/v<version>.{json,ots}`; the record's bytes stay. A 200
stores status `retracted`, `retraction_path` and `retraction_hash`; a 409
(absent subject, already retracted, bad shape) or a failed call changes
nothing and flashes the worker's error. `retracted` is final: the daily
refresh never re-reads it. Post one, wait for the ledger's checks, then the
next. The text is code, not input: a new month's retraction is a pull request.
The button and the handler need the worker URL and secret set. A 409 saying
already retracted (a lost 200) is reconciled from the ledger, read-only: the
record is marked retracted only when `retractions/<uid>/v1.json` exists and its
`payload.retracted_path` is the record's path. The refresh re-reads the option
before it writes and never touches an entry that turned final meanwhile.

## Privacy posture

The sensor is deliberately the least data it can be and still answer the two
questions.

- **Aggregate-only writes.** One data point per observed machine request:
  family, surface class, and a count. No IP addresses, no full paths, no
  referrers, no per-visitor anything, nothing that could be joined back to a
  session.
- **The raw User-Agent never leaves the Worker module.** It is classified into
  the fixed enum and discarded; it is never stored, never returned by the read
  endpoint, and never reaches WordPress. Anything automated but unmatched
  becomes `other-bot`, a name from the enum, never an attacker controlled
  string. That closes the stored-XSS-into-admin pipeline by construction rather
  than by sanitizing.
- **Humans are never recorded.** `classifyMachineReader()` returns null for
  browser user agents and empty ones, and a null classification writes nothing.
  Human readership belongs to the beacon pipeline, which is a separate system.
- **The two counts are never summed.** Beacons see people, the edge sensor sees
  machines, and the overlap between the two populations is not zero-sum or even
  well defined. The Machine Readers tab and SN Provenance's machine-readers rows
  report machine reads only, the analytics dashboard and the SN Traffic tile
  report beacon reads only, and no view adds the two together.
- **Cookieless, like everything else here.** The sensor sets nothing, reads no
  cookie, and has no notion of identity beyond the family enum. This is a
  standing project principle, not a property of this feature.
- **Observation never affects a response.** `observeMachineReader()` is fully
  wrapped in try/catch, returns null on any failure, and is skipped entirely for
  `/_sn/` paths so the sensor never observes itself.

## Defense in depth on the read path

The plugin treats its own Worker as untrusted input and its own read as an
outbound request that must be gated.

- **The outbound gate.** Every fetch (`snt_mr_fetch()`, `snt_mr_sensor_info()`,
  `snt_mr_crawler_list_status()`) requires https, passes `wp_http_validate_url()`,
  and goes through the shared resolve-then-range-check guard
  (`sn_ssrf_host_blocked()`, [`inc/ssrf-guard.php`](../inc/ssrf-guard.php)).
  Hosts are resolved, never string matched.
- **`redirection => 0`.** The host check only ever sees the first hop, so
  redirects are refused outright. A validated host cannot bounce the Bearer
  token to an internal one.
- **Fail closed, and loudly.** A missing token returns `not_configured`, not an
  empty result that would read as "no crawlers". The other terminal states are
  `blocked`, `network`, `http_<code>`, and `bad_schema`, and each one is
  reported by the Sensor status pills.
- **The token is write-only in the UI.** It is stored under the
  `machine_readers` subtree with `autoload=no`, never echoed back into the form,
  and preserved byte for byte by `sn_settings_save()` (pinned in
  [`tests/settings-save-preserves-subtrees.php`](../tests/settings-save-preserves-subtrees.php)
  after v9.88.0 found an Identity save destroying it).
- **Only the two public endpoints are fixed constants.**
  `SN_MR_VERSION_ENDPOINT` and `SN_MR_CRAWLER_STATUS_URL` are never derived from
  settings, so no configuration value can retarget them.
- **Transients are display-only.** Fifteen minutes, volatile-OK under Breeze.
  Nothing durable lives in them.

## Deploy and secret requirements

### Worker side (`sn-rights-signals`)

Secrets are set at deploy and never live in the repo:

```bash
wrangler secret put SN_MR_READ_TOKEN   # the Bearer the plugin presents
wrangler secret put SN_MR_SQL_TOKEN    # a Cloudflare API token with Analytics Engine read
```

`CF_ACCOUNT_ID` is a var, not a secret, and supplies the account id in the
Analytics Engine SQL API URL. Without any one of the three, the read endpoint
answers `503 { "error": "not_configured" }` instead of guessing.

The dataset binding is declared in `wrangler.jsonc`:

```jsonc
"analytics_engine_datasets": [
  { "binding": "SN_MR", "dataset": "sn_machine_readers" }
]
```

Ingress is locked to the zone (`workers_dev: false`, `preview_urls: false`, one
`juanlentino.com/*` route). The weekly crawler-list drift check runs on the
`23 7 * * 1` cron and is what fills `last_check`.

### WordPress side

Constants win over settings, the same shape as the analytics module:

| Constant (wp-config.php) | Setting fallback | Default |
| --- | --- | --- |
| `SN_MR_READ_TOKEN` | `machine_readers.read_token` (write-only field) | none, and a missing token is `not_configured` |
| `SN_MR_WORKER_URL` | `machine_readers.worker_url` | `SN_MR_DEFAULT_ENDPOINT`, the live endpoint |

A blank Worker URL means the built-in default, both when the key is absent and
when it is stored empty (the v9.85.1 fix). When a constant is set, the matching
field renders disabled with a note saying so. The tab requires `manage_options`,
and the settings form posts through the house `sn_action=machine_readers_save`
contract with the shared nonce and capability check.

## How to verify

End to end, from the edge inward. Replace the token with the real one; do not
paste it into a shell history you keep.

**1. The sensor is deployed and new enough.**

```bash
curl -s https://juanlentino.com/_sn/rights-signals/version
```

Expect `"worker": "sn-rights-signals"` and a `version` at or above the
`SN_MR_SENSOR_MIN` value above (`1.12.0`). A lower version is exactly what makes
the Sensor status row show a warn pill naming the deployed version.

**2. The crawler-list drift check has run.**

```bash
curl -s https://juanlentino.com/_sn/rights-signals/crawler-list-status
```

Expect `last_check` with `"drift": false` and a `checked_at` timestamp. A `null`
`last_check` means the isolate restarted since the last weekly cron, which is
normal, not a failure.

**3. The gate is on.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  https://juanlentino.com/_sn/rights-signals/machine-readers?days=7
```

Expect `401`. That 401 is the observable proof the Bearer gate is doing its job;
an anonymous 200 here would be the incident.

**4. The authenticated read returns the documented shape.**

```bash
curl -s -H "Authorization: Bearer $SN_MR_READ_TOKEN" \
  'https://juanlentino.com/_sn/rights-signals/machine-readers?days=7' \
  | python3 -m json.tool | head -30
```

Expect `worker`, `days` echoed back clamped, and a `data` array whose members
carry exactly `family`, `surface`, `day`, `hits`, with every `family` and
`surface` drawn from the enums above.

**5. The observation path actually writes.**

```bash
curl -s -o /dev/null -A 'GPTBot/1.0 (+https://openai.com/gptbot)' \
  https://juanlentino.com/llms.txt
```

Then re-run step 4 and look for a row with `family` `openai` and `surface`
`llms`. Analytics Engine writes are not instantaneous, so allow a minute or two
before treating an absent row as a failure.

**6. The admin surface agrees.**

In wp-admin, Measurement, Machine Readers: the Sensor status pills
should all read with a check mark, the version should match step 1, and the crawler list
verdict should match step 2. If a pill reads `Read token missing` or `Not configured`, the token is
missing on the WordPress side, not on the Worker side.

**7. The offline contract still holds.**

```bash
php tests/machine-readers-api.php
php tests/machine-readers-render.php
php tests/machine-readers-docs.php
```

## The hourly smoke test

The site's hourly live probe is `.github/workflows/smoke-test.yml` in the
**theme repo** (`signal-and-noise`), not in this plugin repo, so nothing in this
repository can add a check to it. That is a deliberate split: the smoke test
probes the live site, which is theme-shaped, and it runs on the theme's cron.

Two of the three sensor endpoints are good candidates for a check there, and one
is not:

- `/_sn/rights-signals/version` and `/_sn/rights-signals/crawler-list-status`
  are public, cheap, and answer JSON, so a `check` block asserting HTTP 200 plus
  a `sn-rights-signals` marker would catch a Worker that stopped being deployed.
  They need an explicit block rather than the workflow's manifest-driven loop:
  that loop enumerates `/.well-known/agents.json`, and these are operational
  diagnostics, not advertised content surfaces, so they do not belong in the
  manifest.
- `/_sn/rights-signals/machine-readers` must **not** go in. The smoke runner has
  no read token, the correct anonymous answer is 401, and a workflow that
  carried the token would put a live secret in a job that logs response
  excerpts.

Until that block lands in the theme repo, steps 1 through 3 above are the manual
equivalent, and the Content Health rights-signals probe covers the adjacent
question (are the rights surfaces themselves still standing) on the site's own
scan schedule.
