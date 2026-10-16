# SN Traffic Lanes: step 2 report (read before build)

2026-10-15. Brief: "SN Traffic Lanes: label every hit at arrival, one lane per hit" (claude.ai, written without repo access). This is the step-2 report the brief asks for. No code is written until the owner answers the decisions below. It becomes the seed of the session log the brief requires.

Read at: plugin origin/main 1aebd176, theme origin/main ff4c3f1, analytics Worker origin/main 1cbed6e (1.25.0, deployed), rights-signals Worker origin/main 39d6ca7 (1.31.0, deployed), remote MCP Worker origin/main 9c056b8 (2.4.0, contract 18). Three local Worker checkouts sit on stale branches; everything below is origin/main.

## Decisions for the owner

1. **The theme.** (Also: the `Claude/\d` skip came from theme #533, because the Claude desktop app's browser pane counted as a visitor. If the skip goes, that pane must be enrolled, one wp-admin login, or its views land in `human`.) The beacon script is theme-owned (`assets/js/sn-beacon.js`, enqueued by `inc/beacon.php`). It sends nothing when the `sn_owner=1` cookie is present (`sn-beacon.js:40,267-271`) or the user agent matches `Claude/\d`. Those views never reach the edge, so they can never be stored as lane `owner`. Hard limit 4 ("nothing silently disappears") and "do not touch the theme" conflict. Options: (a) allow a one-file theme change so those views are sent and the Worker lanes them; (b) keep the theme as is and document "owner views from an enrolled device are not stored" as a known limit.
2. **Logged in, not enrolled.** Today the analytics Worker drops every beacon that carries a `wordpress_logged_in_*` cookie (`src/index.js:226-233,509-515`, `reject('loggedin')`, no row). Under the brief, owner identity comes only from a signed marker. Options: (a) keep the drop (violates limit 4: those hits vanish); (b) store them as `human` (a regression: logged-in admins, including you on an unenrolled device, would count as readers); (c) store them in their own reason-coded slot (`logged_in`, not a brief lane) so they are visible and never human. Recommendation: (c).
3. **`sn_owner` already exists.** Since plugin 6.23.0, `inc/beacon-owner-exclusion.php:127-235` writes an owner-device cookie: `sn_owner=1`, 400 days, `Secure`, `SameSite=Lax`, **not** HttpOnly (the theme JS reads it), not cleared at logout, unsigned. It is set at login for a role in `analytics.exclude_roles` and backfilled on `init`. It is set on your Chrome profile today (read 2026-10-15), so `exclude_roles` holds your role. Public sentences that say the site sets no cookies of its own are already untrue for your devices. Proposed wording below; nothing is published without your yes on the exact text.
4. **Phasing (recommendation).** The problem you have, your own views counted as human, is solved by owner identity alone. Lanes 3 to 8 are almost all derivable today from fields the sensors already store. Phase 1: signed owner marker and agent token, stored at the edge; a read-time lane derivation over existing fields, pinned by `lane_rule_version`; a separate lanes rollup table; the Lanes report. Phase 2: the opt-in MCP surfaces, the contract bump, `/stats`. This keeps the cross-repo risk in one place at a time.
5. **Arrival vs read time (deviation from the brief).** See "What the brief got wrong", item 1. The proposal: the edge stores only what only the edge can know (owner marker verified, agent token verified or invalid, the agent label, the rule version); every other lane is derived at read time, deterministically, from stored fields. Both positions are written out there.
6. **`/stats`.** It shows human views, sessions, an "Automated" tile (suspect plus bot), a presumed-human vs machine-readers bar, a verified/unverified/first-party split of machine reads, and AI-training claimed vs proven. It shows no families. Switching its human figure to lane `human` is a public change; proposed for Phase 2, with a line stating `lanes_since`.

## What the brief got wrong

1. **"Every hit gets a lane at the moment it is recorded."** Not possible for three lanes:
   - `suspected_automation`: the bot-signal score is per visitor-day, the max of each signal bit across all that hash's rows (`inc/analytics-bot-signals.php:57`). No single hit knows it.
   - The over-50-pageview visitor-day rule (`SNT_ANALYTICS_VDAY_PV_CAP`) is a day-end fact.
   - `verified_matched` and `impersonation` are computed in the plugin at read time (`inc/machine-readers-basis.php:79,107-113`), and so is counted-human (network lists re-applied, `inc/analytics-human-rule.php:169-177`).
   - **Brief's position:** assign at arrival, store the lane. **Proposed:** store the identity facts at arrival; derive the lane at read time under a pinned `lane_rule_version`, never relabeling stored rows. A rule change bumps the version going forward, as the brief wants.
2. **"Exclude my own visits fails because cached pages never tell WordPress who is asking."** Wrong. Two layers already work on cached HTML: the Worker's logged-in-cookie drop and the `sn_owner` device cookie. The real gaps: a device or browser profile that never logged in (your phone, a separate Chrome profile for Claude in Chrome, a fresh Playwright context); a headed automation run (`navigator.webdriver` sets the `wd` bit, scored "likely automated" but still counted human). Headless Playwright is already classed `bot` by the Worker's user-agent list.
3. **"/stats 6 views at ~110 seconds were the owner."** Unproven. `time_avg` is the mean time per `tm` flush (one per time the tab was hidden), not per view, and stored rows carry no owner tag. The salt that makes a visitor-day hash expires after 74 hours, so the visit cannot be traced now.
4. **"Lanes sum to the total."** Only per sensor. Beacon pageviews and machine reads are different units on different sensors; they never sum together.
5. **`unknown_machine` (lane 6) has almost no data source.** The machine sensor records nothing when neither of its classifiers matches (`machine-readers.mjs:218`), and humans are never written there. The lane will be near-empty.
6. **"Impersonations"** today means only the two robots control tokens (`Google-Extended`, `Applebot-Extended`), at read time, regardless of network. Network-based impostor detection ("ClaudeBot from Google LLC") is not classified anywhere; it is visible only in `sn_remote_agent_networks`. Under the proposed lanes those are `claimed_bot`, which is right.
7. **`sensor_version` 1.31.0** is the rights-signals Worker only. The analytics Worker (the beacon side) is 1.25.0 and reports no `sensor_version`.
8. **Web Bot Auth** is verified by the rights-signals Worker itself (Ed25519 against the agent's directory), because this zone has no Enterprise Bot Management. "Verified" should mean only state `valid`. The Cloudflare verified-bot category comes from a dashboard Transform Rule header, and only since 2026-09-27T15:49:59Z.
9. **`no_input` alone cannot move a hit.** It weighs 2 against a threshold of 3 (`inc/analytics-bot-signals.php:31-32`). It crosses only with another bit. The brief's gate is cleared.

## Where things are (short)

- **Beacon:** theme JS sends `pv/sc/tm/ce/vl/vi/vc` with signal bits `sg {wd, ni, um, hl, tz}` to `/_sn/px` (analytics Worker). The Worker classifies `human/suspect/bot` at arrival (UA list, Private Relay, datacenter and hosting networks), packs signal bits into `double8`, writes legacy `sn_pageviews` and v2 `sn_pageviews_v2`/`sn_events_v2`. All 20 blobs are used in both pageview datasets; doubles have room (legacy d11+, v2 d14+).
- **Machine reads:** the rights-signals Worker on `juanlentino.com/*` observes every request except `/_sn/*` and admin paths. It writes `sn_machine_readers` (13 blobs used; blob14+ free): family, surface, vendor, purpose, first_party, signature state, verified category, network. `first_party` = taxonomy flag or `x-sn-first-party` header against `SN_FIRST_PARTY_TOKEN`. No durable rollup; the plugin reads live with 15-minute transients.
- **Origin rollups:** `wp_sn_analytics_daily` (key `day, path, class`), `wp_sn_analytics_dims` (key `day, dim, value, class`), siblings for buckets, UTM, page roles, events, sessions. Analytics Engine keeps about 90 days; rollups are kept forever.
- **Tools:** remote summary and query read the rollups; remote machine readers and agent networks read the sensor live; bot signals reads a nightly option. Admin and remote twins share callbacks.
- **Keyring:** `inc/keyring.php`, plain options with autoload off, a wp-config constant wins. No rotation overlap anywhere today. Worker secrets are set by hand with `wrangler secret put`; the plugin pushes none.
- **Integrity → Reports:** classic `sn_health_report_renderers()`, native `tools_reports_renderers()`; a new report needs both.
- **WP-CLI:** one command today (`sn rights-evidence dry-run`); the template for `wp sn owner-token`.

## Today's fields mapped to the eight lanes (the exhaustiveness proof)

First match wins. Every beacon pageview and every machine read lands in exactly one row.

| Today, stored or derived | Lane | Reason code | Note |
|---|---|---|---|
| Beacon or machine read with a valid owner device marker | `owner` | `owner_device` | beacon only in practice |
| Valid `SN-Owner-Agent` token | `owner_agent` | `owner_token` | if both 1 and 2 are present, lane 1 wins and the agent label is still stored |
| Invalid or expired token | falls through | `owner_token_invalid` (kept beside the final lane) | |
| Beacon carrying a logged-in cookie, no marker | `logged_in` (**not one of the eight**, decision 2c) | `logged_in` | today dropped |
| Machine read, `first_party` (taxonomy flag or header) | `own_infra` | `first_party` | |
| Machine read, signature `valid` | `verified_bot` | `web_bot_auth` | `purpose` sub-key kept |
| Machine read, CF category matches purpose (`verified_matched`) | `verified_bot` | `cf_verified` | |
| Machine read, CF category does not match purpose | `claimed_bot` | `category_mismatch` | |
| Machine read, robots control token (`Google-Extended`, `Applebot-Extended`) | `claimed_bot` | `impersonation` | |
| Machine read, taxonomy claim, no proof | `claimed_bot` | `ua_claim` | `family`, `purpose` sub-keys |
| Machine read, frozen-family match only (`unclassified-machine`) | `unknown_machine` | `no_claim` | near-empty, see above |
| Beacon class `bot` (Worker UA list: headless, webdriver, playwright...) | `suspected_automation` | `ua_list` (**new code**) | |
| Beacon class `suspect` (datacenter or hosting network) | `suspected_automation` | `network` (**new code**) | |
| Beacon visitor-day over 50 page views | `suspected_automation` | `over_cap` (**new code**) | today folded into `bot` |
| Beacon visitor-day bot-signal score >= 3 | `suspected_automation` | `bot_score` | |
| Beacon, Private Relay or anything else | `human` | `remainder` | |
| `srv:1` server beacons (the RSS tracker's feed-fetch pageviews, forced human today) | **decision** | | human readers via feed, or `own_infra`? |
| `webmcp` rows in the machine dataset | excluded from the machine total today | | "total" is defined per sensor as today's total |

Open mapping questions for the owner: the three new reason codes; `srv:1` placement; the local MCP server is `owner_agent` (label `mcp-local`) when it carries a token and `own_infra` when it carries the first-party header, so pick which header it sends.

Cost note: the score and the over-cap rule are visitor-day aggregates, so the lanes rollup needs `index1 IN (...)` subqueries like today's over-cap exclusion. Feasible in Analytics Engine SQL, heavier than the current pass, bounded by its 10,000-row read cap.

## Proposed design

**Lane 1 (`owner`), the device marker.**
- Verified on the analytics Worker, which owns `/_sn/px`, already has Web Crypto, and has the only KV binding (`SN_SALT`), so a revocation list fits there.
- Value: `v1.<device_id>.<issued_day>.<hmac>`, HMAC-SHA256 over `v1|device_id|issued_day` with `SN_OWNER_DEVICE_KEY`; constant-time compare.
- Cookie name distinct from `sn_owner` (proposed `sn_owner_dev`), `Secure`, `HttpOnly`, `SameSite=Lax`, host-only, 400 days, renewed on each wp-admin visit. HttpOnly is possible because the Worker, not the theme JS, reads it (it rides the same-origin beacon POST).
- Enrolled, listed and revoked in wp-admin (capability-checked admin-post actions). Revocation list in KV (`owner_revoked:<device_id>`); "revoke all" rotates the key.
- The old unsigned `sn_owner` stays as the theme's send gate until decision 1 is answered.
- **Issued only from wp-admin requests** (`is_admin()` and the enrollment action), never on `init` for a front-end render. A `Set-Cookie` on a response Cloudflare caches would hand the marker to every reader; logged-in responses bypass the edge today, which is the only reason `sn_owner` has not leaked that way. A test asserts no front-end response carries the marker's `Set-Cookie`.
- **Edge cache behavior:** the theme's cache rules (`inc/cache-headers-rules.php`, `docs/CACHING.md`) key on `wordpress_logged_in_*`, not on any cookie, so an enrolled but logged-out device still gets the cached page readers get. To re-verify at build.
- **Known limit, by design:** the marker is a 400-day bearer cookie. Anyone holding it can hide from the counts until it is revoked. That matches the threat model (hiding, never inflating).

**Lane 2 (`owner_agent`), the agent header.**
- `SN-Owner-Agent: v1.<agent>.<expires_unix>.<hmac>`, HMAC over `v1|agent|expires` with `SN_OWNER_AGENT_KEY`; `agent` from the allowlist.
- Minted by `wp sn owner-token --agent=claude-code --ttl=12h` (max TTL capped). Verified on both Workers (beacon side and machine side). Expiry is the revocation; "revoke all" rotates the key. No list needed, which matters because rights-signals has no KV or D1.
- Invalid or expired: reason `owner_token_invalid`, falls through the precedence.
- The header is stripped before the request is forwarded to the origin (as `x-sn-first-party` is today).
- **Always recorded on the machine sensor.** Today a request whose user agent matches neither classifier is not recorded at all, so a plain curl or MCP client with a token (valid or not) would vanish, hiding exactly the `owner_token_invalid` signal. Rule: a request carrying `SN-Owner-Agent` is always recorded.
- **Claude Code's WebFetch cannot send it** (no custom headers). Only Bash-driven curl, Playwright (`extraHTTPHeaders`, which also covers the beacon POST from the page) and the local MCP server can. WebFetch traffic lands wherever its user agent lands, like Claude chat's.
- **Known limit, by design:** a bearer token for its TTL (capped at 12 hours).

**Secrets and rotation.** Two Worker secrets per key (`_CURRENT`, `_PREVIOUS`) with a dated cutover; the keyring gains two rows that print the `wrangler secret put` commands, as the other paired rows do. No keyring redesign.

**What is stored.**
- Beacon (v2 only, so the dual-write check is untouched): one new double for the identity code (0 none, 1 owner device, 2 owner agent, 3 owner token invalid, 4 logged-in not enrolled if decision 2 is (c)), one for the agent label code, one for `lane_rule_version`.
- Machine sensor: append `blob14` (identity code) and `blob15` (agent label); house rule is append only.
- Origin: a new table `wp_sn_analytics_lanes` (day, lane, reason, agent, views, visits, visitor_days), filled by the same rollup pass. The existing daily table and its `(day, path, class)` key are not touched, so every current reader is byte-identical by construction.
- `lanes_since` option, set once at deploy.

**Surfaces (Phase 2).** Opt-in `lane` and `breakdown:"lane"` arguments as the brief lists. Any new output field changes a remote twin's `output_schema`, so this is remote contract 19 (plugin constant and red-then-pin hash, worker bump in a separate PR).

**Effects to expect.** Beacons the Worker used to drop (logged-in, and owner-device views if decision 1 is (a)) are stored. All-class totals rise by that amount; the human figure falls where your unenrolled devices were counted. The v2 verification compares both generations identically, so it does not trip.

## Proposed public wording (needs your yes on each exact text)

| Where | Today | Proposed |
|---|---|---|
| theme `inc/llms-txt.php:77` (/llms.txt) | "the site sets no cookies of its own" | "the site sets no cookies on readers; the owner's own enrolled devices carry an owner-only marker" |
| Privacy Policy (live page, in no repo; memory says never to edit the live legal page, so this text is for you to paste) | "We set no cookies of our own and use no local storage" | "We set no cookies on readers. The site owner's own devices carry an owner-only marker that keeps his visits out of the counts. Your browser stores your light or dark theme choice on your device; nothing is sent to us." |
| theme `inc/gpc-json.php:9` (comment) | "The site is cookieless" | "The site sets no cookies on readers" |
| plugin `docs/ops/wordpress-7-2-roadmap-read.md:47` | "we set no cookies" | "we set no reader cookies; the owner marker is first-party, HttpOnly, SameSite=Lax" |

`/stats` (`inc/public-stats.php:455`) is already scoped to readers and needs no change. The memory note "cookies out, full stop" (2026-06-14) needs the 2026-10-15 owner-marker exception added once you confirm.

## Found, not acted on

- The analytics refresh cron recorded `refresh_status:"error"` at 2026-10-16T01:15Z. Worth a check today, separate from this brief.
- Over-50-pageview visitor-days are dropped from every daily rollup row, `bot` included, while the live readers fold them into `bot`. The `/stats` "Automated" tile therefore disagrees with the dashboards.
- The keyring's `bridge_token` row names the login-guard Worker as its other half; the real consumer is the remote MCP Worker.
- `inc/analytics-network-terms.php` pins its network-list mirror to analytics Worker 1.21.7; 1.25.0 is live.
- **The 9 × 500 on `get-analytics-vitals` were all on 2026-10-12** (edge rollup by day, read 2026-10-15): the 2026.10.8 query bug fixed by #2041. Closed.
- 2026-10-15 shows 58 edge 5xx, mostly 503s on the desktop pulse and machine-readers polls; to read against that day's installs.
- The AI-training spikes on 10-06, 10-10 and 10-12 and the drop on 10-13: no rule, version, robots or rights change explains them. They match one unverified scraper on Google Cloud presenting about 25 AI-crawler user agents on alternating days, then stopping (14 days: train 3,176 claimed, 59 verified). The 1.31.0 deploy came after the drop.

## Advisor checkpoint 1

Asked: attack lanes 1 and 2 (forgery, replay, caching), find overlaps and fall-throughs, and judge the arrival vs read-time split.

Answers, and what was done:
1. A `Set-Cookie` on a cacheable front-end response is the one real forgery path. **Done:** admin-only issuance rule and a test, under Lane 1.
2. Check the zone's cache rules do not key on any cookie. **Done:** the theme's rules key on `wordpress_logged_in_*`; noted, to re-verify at build.
3. Replay and theft are accepted by design. **Done:** written as known limits.
4. Claude Code's WebFetch cannot carry a header. **Done:** under Lane 2.
5. A token-bearing request must always be recorded on the machine sensor. **Done:** force-record rule under Lane 2.
6. No mapping table existed; building it surfaces undecided cases (`bot`/`suspect`, over-cap, `srv:1`, the local MCP server, `webmcp`, the `logged_in` ninth slot, lanes 1 and 2 together). **Done:** the mapping table, with the open questions.
7. The `Claude/\d` skip matters if decision 1 is (a). **Done:** added to decision 1.
8. The Privacy Policy is not in any repo and is not to be edited by an agent. **Done:** noted in the wording table.

The advisor agreed with the recommendation (Phase 1 = owner identity plus read-time lanes) and with the arrival vs read-time split. No disagreement with the brief on a hard limit beyond those already listed as decisions.

## Owner decisions (2026-10-15)

"Go with your recommendations, allow the theme change." So:
1. Theme change allowed (decision 1a). The theme stops skipping on the unsigned `sn_owner` cookie; the `Claude/\d` user-agent skip stays (removing it would also count other people's Claude desktop panes, and the owner's pane can be enrolled later).
2. Logged in, not enrolled: stored under its own `logged_in` code, never human (2c).
3. `sn_owner`: the proposed wording is the approved text. The theme's `/llms.txt` and the `gpc.json` comment change in the theme PR; the Privacy Policy text is handed to the owner to paste.
4. Phasing: Phase 1 now (identity at the edge, lanes derived at read time, lanes table and report). Phase 2 later (opt-in MCP surfaces, contract 19, `/stats`, the "Exclude my own visits" repurpose).
5. Arrival vs read time: the split as proposed.
6. `/stats`: Phase 2.
Small questions, as recommended: the new reason codes `ua_list`, `network`, `over_cap`, `logged_in` are added; `srv:1` server beacons stay `human` with reason `feed_server` (no change to today's counts); the local MCP server sends the owner token (lane `owner_agent`, label `mcp-local`).

## Phase 1 build contract (shared by all four repos)

**Identity codes** (one integer per hit): 0 none, 1 `owner_device`, 2 `owner_token`, 3 `owner_token_invalid`, 4 `logged_in` (WordPress auth cookie present, no valid marker), 5 `owner_legacy` (unsigned `sn_owner=1` present, no valid marker). Precedence at the edge: 1, then 2, then 3, then 4, then 5, then 0.
**Agent codes**: 0 none, 1 `browser`, 2 `chrome-extension`, 3 `claude-code`, 4 `playwright`, 5 `script`, 6 `mcp-local`, 7 `other`. Lane 1 stores 1 (browser) unless a valid token is also present, then the token's agent.
**`lane_rule_version`**: integer, starts at 1.

**Device marker** cookie `sn_owner_dev` = `v1.<device_id>.<issued_day>.<sig>`; `device_id` 16 lowercase hex, `issued_day` YYYYMMDD (UTC), `sig` = base64url (no padding) of HMAC-SHA256(key, `v1|dev|<device_id>|<issued_day>`). `Secure; HttpOnly; SameSite=Lax; Path=/`, host-only, Max-Age 400 days. Set ONLY on wp-admin responses (enroll action, and renewal on any `is_admin()` request from an enrolled device), never on a front-end render.
**Agent token** header `SN-Owner-Agent: v1.<agent>.<exp>.<sig>`; `agent` from the agent allowlist (string), `exp` unix seconds, `sig` = base64url HMAC-SHA256(key, `v1|agent|<agent>|<exp>`). Valid iff signature matches AND now < exp AND exp - now <= 43200 + 300.
**Keys**: Worker secrets `SN_OWNER_DEVICE_KEY` and `SN_OWNER_AGENT_KEY`, each with an optional `_PREVIOUS` twin accepted during an overlap; constant-time comparison. The plugin holds the same values in keyring rows `owner_device_key`, `owner_device_key_previous`, `owner_agent_key`, `owner_agent_key_previous`; the keyring prints the `wrangler secret put` command, the owner sets the secrets by hand (no plugin push).
**Revocation (device only)**: plugin route `GET /wp-json/signal-noise/v1/owner/revoked` (Bearer `SN_SRV_TOKEN`, the existing shared secret) returns `{revoked:[device_id...], at}`. The analytics Worker caches it in KV `SN_SALT` under `owner:revoked` on its existing cron and treats a listed id as invalid (falls to code 4 or 5 or 0). "Revoke all" = rotate the device key. Agent tokens are revoked by expiry or key rotation.

**Analytics Worker (beacon)**:
- Stops dropping logged-in beacons. Any beacon with identity 1, 4 or 5 is written ONLY to a new dataset `sn_owner_hits` (binding `SN_AE_OWNER`), never to `sn_pageviews`, `sn_pageviews_v2` or `sn_events_v2`. These hits were never stored before, so every existing figure stays byte-identical.
- Beacons with identity 2 or 3, or 0, go where they go today; in v2 rows only, append double14 identity, double15 agent, double16 rule version. The legacy row is unchanged, so the dual-write check is unaffected.
- `sn_owner_hits` row: same blob layout as the v2 pageview row plus double14-16.
- Strips nothing the beacon sends (it is a POST to the Worker, never forwarded).
**Rights-signals Worker (machine reads)**:
- Verifies `SN-Owner-Agent` (codes 2 and 3 only; no device marker on machine reads), strips it before forwarding to the origin.
- A request carrying the header is ALWAYS recorded (family `unclassified-machine` if nothing matches).
- Appends blob14 identity code and blob15 agent code (as decimal strings) to `sn_machine_readers`. Append only.
**Plugin**:
- Enroll, revoke, revoke all (admin-post, `manage_options`), device list option `sn_owner_devices` (id, label, enrolled, last_seen; no secrets), classic card + native twin under Measurement › Analytics beside "Exclude my own visits".
- The revoked-list route.
- `wp sn owner-token --agent=<label> [--ttl=<seconds>]` (max 43200), prints the header line; never logs the token.
- Keyring rows above.
- `lanes_since` option (set when the lanes rollup first runs after deploy), table `wp_sn_analytics_lanes` (day, sensor, lane, reason, agent, views, visits, visitor_days; unique on day, sensor, lane, reason, agent), filled by the daily rollup from v2 + `sn_owner_hits` (beacon sensor) and from the live machine sensor (machine sensor).
- Lane derivation as in the mapping table, pinned by `lane_rule_version`.
- Lanes report under Integrity → Reports (classic + native), showing 7/30/90 days, a daily view, `lanes_since`, the rule version, the reason split, and `owner_token_invalid` prominently.
**Theme**: `sn-beacon.js` drops the `sn_owner` skip (keeps the DNT/GPC and `Claude/\d` skips); `/llms.txt` and the `gpc.json` comment take the approved wording.
**Deploy order** (owner actions marked): plugin first (keyring rows, enrollment, route; nothing reads the new fields yet) → owner sets the four Worker secrets → analytics Worker → rights-signals Worker (owner deploys) → owner enrolls each device → theme last.
