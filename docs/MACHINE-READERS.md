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
| **Monitoring, Machine Readers** (wp-admin tab) | Summary stat strip, reads per family, reads per surface class, the observed vs declared compliance read, and the Sensor panel (identity, connection state, crawler-list verdict, settings). |
| **SN Machine Readers** (Desktop Mode tile, v10.1.0) | The same aggregates in tile form, served by `/wp-json/signal-noise/v1/desktop/machine-readers`. |
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
| `/_sn/rights-signals/version` | GET | none | `200 { worker, version, cf_version_id, cf_version_tag, deployed_at }` |
| `/_sn/rights-signals/crawler-list-status` | GET | none | `200 { worker, last_check }` |

Notes that matter when reading the responses:

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
- **The contract minimum is `SN_MR_SENSOR_MIN`, currently `1.4.0`.** The Sensor
  panel compares the deployed `version` against it and warns when the edge is
  behind what these panels are built for.

### The two enums

Everything the Worker returns is one of a fixed set of strings. This is the load
bearing privacy and security property of the whole surface, so both enums are
mirrored in `inc/machine-readers-api.php` and in `src/machine-readers.mjs`, and
the rule is: extend BOTH or neither. `tests/machine-readers-docs.php` fails if
the code allowlists and this page drift apart.

**18 families** (`snt_mr_valid_families()`), first match wins in the Worker, with
the specific families ahead of the generic buckets:

| Class | Families |
| --- | --- |
| Declared AI training | `openai`, `anthropic`, `google-ai`, `commoncrawl`, `bytedance`, `apple-ai`, `meta-ai`, `mistral`, `cohere`, `allen-ai` |
| Other named machines | `perplexity`, `amazon-ai`, `diffbot` |
| Generic buckets | `search`, `seo`, `feed`, `uptime`, `other-bot` |

The AI-training class is the static half of the observed vs declared read
(`snt_mr_ai_training_families()` in the render lane). It comes from public
declarations, not from anything the request proves.

**10 surface classes** (`snt_mr_valid_surfaces()`), coarse on purpose so that no
full path is ever stored:

| Surface | Matches |
| --- | --- |
| `robots` | `/robots.txt` |
| `rights` | `/.well-known/tdmrep.json`, `/license.xml`, `/tdm-policy` |
| `llms` | `/llms.txt`, `/llms-full.txt` |
| `agents-manifest` | `/.well-known/agents.json` |
| `well-known` | any other `/.well-known/` path |
| `feed` | the feed routes |
| `wp-json` | the REST surface |
| `sitemap` | any path containing `sitemap` |
| `asset` | `/wp-content/`, `/wp-includes/` |
| `html` | everything else |

Unknown values fail into the enum rather than through it: an unrecognized family
normalizes to `other-bot`, an unrecognized surface to `html`, a malformed day to
an empty string, and `hits` to a non negative integer
([`snt_mr_normalize_rows()`](../inc/machine-readers-api.php)). A hostile Worker
response therefore cannot put an arbitrary string on an admin page even before
escaping gets a turn, and the render lane escapes every cell anyway.

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
  well defined. The Machine Readers tab and the SN Machine Readers tile report
  machine reads only, the analytics dashboard and the SN Site Views tile report
  beacon reads only, and no view adds the two together.
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
  reported in the Sensor panel's connection line.
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
`SN_MR_SENSOR_MIN` value above (`1.4.0`). A lower version is exactly what makes
the Sensor panel show its "sensor outdated" warning.

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

In wp-admin, Monitoring, Machine Readers: the Sensor panel's connection pill
should read `connected`, the version should match step 1, and the crawler list
verdict should match step 2. If the pill reads `not configured`, the token is
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
