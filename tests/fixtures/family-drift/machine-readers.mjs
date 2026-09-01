// Machine-readership sensor (v1.4.0) — the edge half of the plugin's v10.0.0
// Machine Readers surface. AI crawlers do not execute JavaScript, so the
// beacon-based analytics pipeline is structurally blind to them; this Worker
// already intercepts every zone request, so it is the observation point.
//
// Privacy/security contract (scope doc §2.5a, load-bearing):
//   - The raw User-Agent NEVER leaves this module: classification is into the
//     FIXED enum below, and anything unmatched-but-automated buckets as
//     "other-bot" (a name from the enum, never an attacker-controlled string).
//     That kills the stored-XSS-into-admin pipeline by construction.
//   - Aggregate-only writes: family + surface class, one count. No IPs, no
//     paths beyond the coarse surface class, no per-visitor anything.
//   - Humans (browser UAs) are never recorded here — human readership is the
//     beacon pipeline's; the two are separate and never summed.
//   - Observation must never affect a response: observeMachineReader() is
//     fully try/catch'd and returns null on any failure.
//
// v1.11.0 amends ONE clause of that contract, deliberately and narrowly: a
// sanitised, length-capped UA sample is stored for requests the taxonomy could
// not match (RULE 2), because an unknown bucket nobody can inspect is not a
// measurement. Everything else above still holds, and recognised agents still
// store nothing but their enum values. See sanitizeUnknownUa() in taxonomy.mjs.

import {
  classifyVendorPurpose,
  sanitizeUnknownUa,
  TAXONOMY_VERSION,
  TAXONOMY_EFFECTIVE_DATE,
} from "./taxonomy.mjs";
import { observeRightsSurfaceDetail } from "./machine-readers-rights-detail.mjs";
import { prefersMarkdown } from "./accept-markdown.mjs";
import { SIG_UNSIGNED } from "./web-bot-auth.mjs";

/**
 * The one additive family value (v1.11.0). Carries rows the frozen classifier
 * would have dropped, so that no EXISTING family's meaning or population
 * shifts. Mirrored in the plugin's snt_mr_valid_families() — extend both or
 * neither, same rule as the original enum.
 */
export const UNCLASSIFIED_MACHINE = "unclassified-machine";

/**
 * FROZEN (v1.11.0). Fixed classification enum, first match wins — SPECIFIC
 * families before the generic buckets (applebot-extended must precede the plain
 * applebot in "search"; google-extended/googleother are AI fetchers, googlebot
 * is search).
 *
 * DO NOT EDIT THIS LIST. A published number (77 AI-training reads, 30d to
 * 31 July 2026, scheduled note 2071) depends on what these values meant, and
 * the field has already moved underneath a published figure once. Two entries
 * here are known to be WRONG against the vendors' current docs — googleother is
 * a generic Google crawler, not an AI fetcher, and /mistralai/i swallows
 * Mistral's index and user agents alongside its training one — and they stay
 * wrong on purpose. The correction lives on the vendor/purpose axes in
 * machine-reader-taxonomy.json, which are matched independently against the raw
 * UA and never derived from the value produced here.
 *
 * New coverage goes in the taxonomy file. Never in this list.
 */
export const MACHINE_FAMILIES = [
  ["openai", /gptbot|oai-searchbot|chatgpt-user/i],
  ["anthropic", /claudebot|claude-web|claude-user/i],
  ["google-ai", /google-extended|googleother/i],
  ["perplexity", /perplexitybot|perplexity-user/i],
  ["commoncrawl", /ccbot/i],
  ["bytedance", /bytespider/i],
  ["amazon-ai", /amazonbot/i],
  ["apple-ai", /applebot-extended/i],
  ["meta-ai", /meta-external(agent|fetcher)|facebookbot/i],
  ["mistral", /mistralai/i],
  ["cohere", /cohere/i],
  ["allen-ai", /ai2bot/i],
  ["diffbot", /diffbot/i],
  ["search", /googlebot|bingbot|duckduckbot|applebot|yandex|baiduspider|seznambot/i],
  ["seo", /ahrefsbot|semrushbot|mj12bot|dotbot|petalbot/i],
  ["feed", /feedly|feedbin|inoreader|newsblur|feedfetcher|miniflux|freshrss|tiny tiny rss|rssowl/i],
  ["uptime", /uptimerobot|better\s?stack|betteruptime|pingdom|statuscake/i],
  [
    "other-bot",
    /bot[\s\/;)]|bot$|crawler|spider|scraper|curl\/|wget\/|python-requests|go-http-client|httpie|node-fetch|axios\/|http\.rb\/|java\//i,
  ],
];

/**
 * @param {string|null|undefined} ua Raw User-Agent header value.
 * @returns {string|null} Family name from the enum, or null for humans/empty
 *   (not recorded — the beacon pipeline owns human readership).
 */
export function classifyMachineReader(ua) {
  const s = typeof ua === "string" ? ua : "";
  if (s === "") return null;
  for (const [family, re] of MACHINE_FAMILIES) {
    if (re.test(s)) return family;
  }
  return null;
}

/** Fixed surface-class enum — coarse on purpose (no full paths stored). */
export function classifySurface(pathname) {
  const p = String(pathname || "/");
  if (p === "/robots.txt") return "robots";
  if (p === "/.well-known/tdmrep.json" || p === "/license.xml" || p === "/tdm-policy" || p === "/tdm-policy/") return "rights";
  if (p === "/llms.txt" || p === "/llms-full.txt") return "llms";
  if (p === "/.well-known/agents.json") return "agents-manifest";
  // v1.17.0: the STANDARD-named agent discovery documents get their own class.
  //
  // Not cosmetic. Until now these fell into the generic "well-known" bucket —
  // which sits INSIDE snt_mr_rights_surfaces() in the plugin, the set whose
  // reads are published as "a machine read the terms". An agent fetching the
  // MCP server card was therefore being counted as a machine reading our TDM
  // policy. It never was: a server card is discovery, not terms.
  //
  // Splitting them out does two things at once — it stops that over-count, and
  // it makes "did any agent actually USE the doors we opened?" a question the
  // sensor can answer at all. In one shared bucket with security.txt and
  // gpc.json it could not.
  //
  // Keep this list EXACT (no prefix match on /.well-known/mcp/): a new
  // well-known file should land in "well-known" until someone decides which
  // class it belongs to, not silently inherit this one.
  if (
    p === "/.well-known/mcp/server-card.json" ||
    p === "/.well-known/api-catalog" ||
    p === "/.well-known/ai-catalog.json" ||
    // Task 4: the served WebMCP bridge script. Not a well-known manifest, but
    // this bucket fits it better than any alternative — a fetch here is not a
    // rights-terms read ("rights"), not page content ("html"), and not a
    // generic static file ("asset"); it is an agent exercising the door this
    // Worker opened, exactly the population this class exists to isolate from
    // the generic buckets per the v1.17.0 rationale above. Without this, every
    // crawler fetch of the bridge script would silently fall through to the
    // "html" catch-all and pollute the surface-mix data the v1.5.0 per-
    // response reservation rationale rests on.
    p === "/webmcp/bridge.js"
  ) {
    return "agent-discovery";
  }
  if (p.startsWith("/.well-known/")) return "well-known";
  if (p === "/feed" || p === "/feed/" || p.startsWith("/feed/") || p.endsWith("/feed/")) return "feed";
  if (p === "/wp-json" || p.startsWith("/wp-json/")) return "wp-json";
  if (p.includes("sitemap")) return "sitemap";
  if (p.startsWith("/wp-content/") || p.startsWith("/wp-includes/")) return "asset";
  return "html";
}

// Sensor-alive state — isolate-memory, best-effort, same convention as
// crawler-list-status's lastCheck: resets on eviction/deploy, "if available"
// data, not a durable log. Exists because the fail-open contract above cuts
// both ways: a dropped SN_MR binding used to make the dataset simply go
// quiet, indistinguishable from "no crawlers came". The state (surfaced on
// /_sn/rights-signals/version) plus the console.error trail below make
// "sensor dead" and "site unvisited" different answers.
//   ae_bound      — whether SN_MR was usable on the LAST observe attempt
//                   (null until the first attempt; the version endpoint also
//                   reflects the binding live from env, so it never waits).
//   last_write_ok — outcome of the last actual write attempt.
//   last_write_at — timestamp of the last SUCCESSFUL write.
//   last_error    — last failure message, LOG/MEMORY ONLY: never serialized
//                   into a response (the getter's copy is for callers that
//                   know the contract; version.mjs deliberately omits it).
const sensorState = { ae_bound: null, last_write_ok: null, last_write_at: null, last_error: null };

/** @returns {{ae_bound:boolean|null, last_write_ok:boolean|null, last_write_at:string|null, last_error:string|null}} */
export function getSensorState() {
  return { ...sensorState };
}

// Injectable seam for tests only — module state persists across cases within
// a pool isolate (same reason crawler-list-status exposes _setCrawlerCacheForTests).
export function _resetSensorStateForTests() {
  sensorState.ae_bound = null;
  sensorState.last_write_ok = null;
  sensorState.last_write_at = null;
  sensorState.last_error = null;
}

/**
 * Observe one request. Aggregate-only, fire-and-forget, never throws, never
 * blocks or alters the response path. Failures stay fail-open (return null)
 * but are no longer silent: they update sensorState and console.error.
 *
 * @returns {{family:string, surface:string}|null} What was recorded, or null.
 */
export function observeMachineReader(request, env, pathname, signatureState = SIG_UNSIGNED) {
  try {
    const bound = !!(env && env.SN_MR && typeof env.SN_MR.writeDataPoint === "function");
    sensorState.ae_bound = bound;
    if (!bound) {
      sensorState.last_error = "SN_MR binding missing or unusable";
      console.error(`[machine-readers] observe skipped: ${sensorState.last_error}`);
      return null;
    }
    const ua = request.headers.get("user-agent");

    // TWO INDEPENDENT PASSES. The family pass is the frozen v1.4.0 classifier,
    // untouched. The taxonomy pass reads the same raw UA and knows nothing
    // about what family said. Nothing below derives one axis from the other.
    const legacyFamily = classifyMachineReader(ua);
    const vp = classifyVendorPurpose(ua);

    // Neither pass recognised it: a browser, or a machine nobody has named yet
    // and whose UA carries no bot/crawler marker. Unchanged from v1.4.0 — we do
    // not start recording humans in order to widen a crawler taxonomy.
    if (legacyFamily === null && vp === null) return null;

    // The additive family value (v1.11.0), used ONLY where the frozen
    // classifier would have dropped the row entirely — facebookexternalhit,
    // meta-webindexer, Slackbot, WhatsApp, ia_archiver and friends, all of
    // which returned null and were silently treated as human. No EXISTING
    // family value changes meaning or population, so any query filtering the
    // original 18 families returns bit-identical results across the cutover.
    const family = legacyFamily === null ? UNCLASSIFIED_MACHINE : legacyFamily;
    const surface = classifySurface(pathname);

    // RULE 2: the unknown bucket has to be reviewable or it is not a
    // measurement. Sample the UA ONLY when the taxonomy failed to match —
    // a recognised agent contributes nothing by having its UA stored, and
    // storing less is the whole posture. See sanitizeUnknownUa() for the
    // allowlist, and MACHINE-READERS.md for the privacy trade this makes.
    const uaSample = vp === null ? sanitizeUnknownUa(ua) : "";

    // v1.18.0: did this reader ASK for markdown?
    //
    // WHY THIS AXIS EXISTS: v1.16.0 opened a markdown door and left it
    // unmeasurable. A markdown request lands on a content page, so it classifies
    // as `html` like any other page read, and the Accept header is only retained
    // for rights surfaces (DETAIL_SURFACES). "How many agents actually use the
    // markdown door?" — the one number that says whether that build was worth
    // anything — had no answer.
    //
    // WHY A NEW DIMENSION AND NOT A NEW SURFACE CLASS: a `markdown` surface
    // would drain reads OUT of `html`, changing the meaning and population of an
    // existing value. This tree's additive rule (see the v1.11.0 family note
    // above) forbids that. A tenth blob changes nothing that already exists; it
    // adds an axis. Old rows simply carry "" and read as not-requested.
    //
    // WHY "REQUESTED" AND NOT "SERVED": this runs before the origin fetch, so
    // whether conversion succeeded is not yet known — but that is OUR
    // reliability, answerable from logs. Adoption is a fact about the AGENT, and
    // the request alone states it.
    const markdownRequested = prefersMarkdown(request.headers.get("accept")) ? "1" : "0";

    env.SN_MR.writeDataPoint({
      blobs: [
        family,
        surface,
        vp?.vendor ?? "",
        vp?.purpose ?? "unknown",
        TAXONOMY_VERSION,
        vp?.training_corpus_source ? "1" : "0",
        vp?.first_party ? "1" : "0",
        uaSample,
        // v1.12.0: the taxonomy entry id, so the surface can name the EXACT
        // agent rather than leaving it to be inferred from vendor+purpose.
        // Answering "was the 8 August sweep GPTBot or ChatGPT-User?" required a
        // trip to Workers Logs, which retains 7 days. This field means the next
        // such question is answerable from the dataset itself, for 90.
        vp?.id ?? "",
        markdownRequested,
        // v1.19.0: blob11, the Web Bot Auth signature state -- one of
        // unsigned / valid / invalid / unknown-key. APPENDED, never inserted:
        // blob order is the read query's contract, and inserting would
        // silently relabel every column after it. Old rows carry "" and read
        // as NOT MEASURED, which is a different fact from "unsigned", itself a
        // measurement that the agent did not sign.
        signatureState,
      ],
      doubles: [1],
      // Still exactly one index: Analytics Engine permits one per data point,
      // so purpose cannot also be indexed. That costs sampling granularity on
      // the purpose axis, not queryability — blobs are fully queryable in SQL.
      indexes: [family],
    });

    // RULE 3: full-fidelity detail for rights-surface hits only (~80 reads per
    // 30 days). Separate dataset, separate contract, never summed with the
    // aggregate above. Cheap because rare, and these are the events the
    // published claim actually rests on.
    observeRightsSurfaceDetail(request, env, pathname, surface, family, vp);

    sensorState.last_write_ok = true;
    sensorState.last_write_at = new Date().toISOString();
    sensorState.last_error = null;
    // NOTE: markdownRequested is written to the dataset but deliberately NOT
    // added to this return value. Two tests pin this shape with toEqual, nothing
    // in the Worker consumes it, and widening it would break an existing
    // contract to carry a field no caller reads — the opposite of additive.
    return { family, surface, vendor: vp?.vendor ?? null, purpose: vp?.purpose ?? "unknown" };
  } catch (err) {
    sensorState.last_write_ok = false;
    sensorState.last_error = err && err.message ? err.message : String(err);
    console.error(`[machine-readers] AE write failed: ${sensorState.last_error}`);
    return null;
  }
}

const DAYS_MIN = 1;
const DAYS_MAX = 90;
const DAYS_DEFAULT = 30;

/** Fixed view allowlist — nothing from the query string is ever interpolated. */
const VIEWS = new Set(["aggregate", "unknown", "rights", "totals"]);

// How many unclassified user-agent strings the review view returns. Capped, and
// the cap is REPORTED in the response, because a silently truncated leaderboard
// reads as "that is all of them" when it is not.
const UNKNOWN_LIMIT = 50;
const RIGHTS_LIMIT = 500;

// The aggregate had NO declared limit and therefore inherited the SQL API's own
// row cap silently — the one view that skipped the discipline stated directly
// above it. It groups by ELEVEN dimensions x day, so its row count scales with
// the window, and a wide window truncates. The consumer sums those rows to get
// a total, so a truncated read does not look degraded: it looks like less
// traffic. Measured consequence: a 60-day read summed to barely more than a
// 30-day read, which made a derived prior period report a 15x surge that never
// happened.
//
// Declaring the cap makes truncation OURS and reportable. It does not make the
// total correct — dropped rows are still dropped — which is what TOTALS_* below
// is for.
const AGGREGATE_LIMIT = 10000;

/**
 * @param {"aggregate"|"unknown"|"rights"} view
 * @param {number} days Already clamped to an integer in [1, 90].
 */
export function buildQuery(view, days) {
  const since = `WHERE timestamp > NOW() - INTERVAL '${days}' DAY `;

  // RULE 2: the top unclassified UAs by volume, so other-bot can be reviewed
  // and the taxonomy extended from evidence instead of from guesswork.
  if (view === "unknown") {
    return (
      "SELECT blob8 AS user_agent, sum(_sample_interval) AS hits " +
      "FROM sn_machine_readers " +
      since +
      "AND blob4 = 'unknown' AND blob8 != '' " +
      `GROUP BY user_agent ORDER BY hits DESC LIMIT ${UNKNOWN_LIMIT} FORMAT JSON`
    );
  }

  // RULE 3: the full-fidelity rights-surface stream. Separate dataset; never
  // summed with the aggregate.
  if (view === "rights") {
    return (
      "SELECT blob1 AS surface, blob2 AS family, blob3 AS vendor, blob4 AS purpose, " +
      "blob5 AS path, blob6 AS user_agent, blob7 AS accept, blob8 AS observed_at, " +
      "sum(_sample_interval) AS hits FROM sn_machine_readers_rights " +
      since +
      "GROUP BY surface, family, vendor, purpose, path, user_agent, accept, observed_at " +
      `ORDER BY observed_at DESC LIMIT ${RIGHTS_LIMIT} FORMAT JSON`
    );
  }

  // RULE 4 (v1.23.0): totals ONLY, one row per day. The aggregate above cannot
  // answer "how many reads" reliably because its row count scales with the
  // window; this groups by day alone, so a 90-day window returns at most 90
  // rows and the sum is exact no matter how wide the window gets. It is a
  // separate query on purpose: the breakdown needs its dimensions, and the
  // total needs to not have them.
  if (view === "totals") {
    return (
      "SELECT toDate(timestamp) AS day, sum(_sample_interval) AS hits " +
      "FROM sn_machine_readers " +
      since +
      `GROUP BY day ORDER BY day ASC LIMIT ${DAYS_MAX} FORMAT JSON`
    );
  }

  // Default: the aggregate. family and surface keep their original names and
  // meaning so existing consumers are unaffected; the rest are additive.
  return (
    "SELECT blob1 AS family, blob2 AS surface, blob3 AS vendor, blob4 AS purpose, " +
    "blob5 AS taxonomy_version, blob6 AS training_corpus_source, blob7 AS first_party, " +
    "blob9 AS agent, blob10 AS markdown_requested, blob11 AS signed_agent, " +
    "toDate(timestamp) AS day, sum(_sample_interval) AS hits " +
    "FROM sn_machine_readers " +
    since +
    "GROUP BY family, surface, vendor, purpose, taxonomy_version, training_corpus_source, " +
    `first_party, agent, markdown_requested, signed_agent, day ORDER BY day ASC LIMIT ${AGGREGATE_LIMIT} FORMAT JSON`
  );
}

/**
 * Token-auth read path for the plugin (analytics-worker pattern): Bearer token
 * gate, then the Analytics Engine SQL API, sampled counts read via
 * sum(_sample_interval). Secrets (set at deploy, never in repo):
 *   SN_MR_READ_TOKEN — the bearer the plugin presents.
 *   SN_MR_SQL_TOKEN  — a Cloudflare API token with Analytics Engine read.
 *   CF_ACCOUNT_ID    — account id for the SQL API URL (a var, not a secret).
 */
// Constant-time bearer comparison, ported from sn-analytics-worker's
// safeEqual(): hash both sides to fixed-length SHA-256 digests, then compare
// with the Workers-native primitive. Hashing first removes any length branch.
async function safeEqual(a, b) {
  const encoder = new TextEncoder();
  const [aHash, bHash] = await Promise.all([
    crypto.subtle.digest("SHA-256", encoder.encode(String(a ?? ""))),
    crypto.subtle.digest("SHA-256", encoder.encode(String(b ?? ""))),
  ]);
  if (typeof crypto.subtle.timingSafeEqual === "function") {
    return crypto.subtle.timingSafeEqual(aHash, bHash);
  }

  // Node's Web Crypto does not yet expose the Workers-only primitive. Both
  // digests are fixed at 32 bytes, so this fallback has no length branch.
  const aBytes = new Uint8Array(aHash);
  const bBytes = new Uint8Array(bHash);
  let diff = 0;
  for (let i = 0; i < aBytes.length; i += 1) diff |= aBytes[i] ^ bBytes[i];
  return diff === 0;
}

export async function machineReadersResponse(request, env) {
  const json = (status, body) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" },
    });

  const expected = env && env.SN_MR_READ_TOKEN;
  if (!expected) return json(503, { error: "not_configured" });
  const auth = request.headers.get("authorization") || "";
  if (!(await safeEqual(auth, `Bearer ${expected}`))) return json(401, { error: "unauthorized" });

  if (!env.CF_ACCOUNT_ID || !env.SN_MR_SQL_TOKEN) return json(503, { error: "not_configured" });

  const url = new URL(request.url);
  const raw = parseInt(url.searchParams.get("days") || "", 10);
  const days = Number.isFinite(raw) ? Math.min(DAYS_MAX, Math.max(DAYS_MIN, raw)) : DAYS_DEFAULT;

  // view is matched against a fixed allowlist and never interpolated; days is
  // clamped to a small integer above. Nothing user-supplied reaches the SQL.
  const view = VIEWS.has(url.searchParams.get("view") || "") ? url.searchParams.get("view") : "aggregate";
  const query = buildQuery(view, days);

  try {
    const res = await fetch(`https://api.cloudflare.com/client/v4/accounts/${env.CF_ACCOUNT_ID}/analytics_engine/sql`, {
      method: "POST",
      headers: { authorization: `Bearer ${env.SN_MR_SQL_TOKEN}` },
      body: query,
    });
    if (!res.ok) return json(502, { error: "upstream", status: res.status });
    const data = await res.json();
    // taxonomy_version rides the ENVELOPE as well as every row: the envelope
    // says which definition this Worker would write today, the row field says
    // which definition each historical row was written under. A window that
    // spans a taxonomy change is then visibly mixed rather than quietly so.
    return json(200, {
      worker: "sn-rights-signals",
      days,
      view,
      taxonomy_version: TAXONOMY_VERSION,
      taxonomy_effective_date: TAXONOMY_EFFECTIVE_DATE,
      // Reported, never silent: a truncated leaderboard that does not say it is
      // truncated reads as complete coverage. The aggregate reported `null`
      // here — "no cap" — while silently inheriting the SQL API's own.
      limit:
        view === "unknown"
          ? UNKNOWN_LIMIT
          : view === "rights"
            ? RIGHTS_LIMIT
            : view === "totals"
              ? DAYS_MAX
              : AGGREGATE_LIMIT,
      // The upstream row count was already in the response and was being
      // discarded, which is why truncation has been unobservable rather than
      // merely unnoticed. A consumer can now refuse to derive a total from a
      // read that says it is truncated.
      rows: Number.isFinite(data.rows) ? data.rows : (data.data || []).length,
      truncated: ((data.data || []).length >= (view === "unknown" ? UNKNOWN_LIMIT : view === "rights" ? RIGHTS_LIMIT : view === "totals" ? DAYS_MAX : AGGREGATE_LIMIT)),
      data: data.data || [],
    });
  } catch {
    return json(502, { error: "upstream" });
  }
}
