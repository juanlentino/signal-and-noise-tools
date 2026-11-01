# Analytics inventory: what is stored, what the dashboard shows, what the MCP doors return

2026-11-01, for the owner's "MCP full analytics access" brief. Read from origin/main at plugin
99a7f55e, analytics worker f2ff3bf and remote MCP worker 210b9c4.

## Stored

**Analytics Engine** (raw rows, about 90 days; `timestamp` UTC, `_sample_interval` weights every
count). Datasets: `sn_pageviews` (legacy), `sn_pageviews_v2` (from 2026-10-05), `sn_events_v2`
(custom events) and `sn_owner_hits` (the owner's own beacons only, never in reader counts).
Every row carries: event type (blob1), path (blob2), referrer host (blob3), country (blob4),
device (blob5), host (blob6), write-time class (blob7), browser (blob8), OS (blob9), region
(blob10), city (blob11), network (blob12), edge colo (blob13), protocol (blob14), TLS (blob15),
the visitor's time zone, UTM (legacy blob20; v2 blob17-20 on page views), scroll milestone
(double1), time-on-page delta in ms (double2), Cloudflare bot score (double3), RTT (double4),
latitude and longitude (double5-6), Core Web Vitals value (double7), the bot-signal bits
(double8), the browser's UTC offset (double9), referrer kind (double10), and index1, a daily
visitor hash. The salt behind index1 rotates at site-local midnight and is deleted after 74
hours, so no cross-day identity exists anywhere.

**Durable tables** (kept forever): `daily` (day, path, class: views, visitor-days, time and
scroll sums), `dims` (day, dim, value, class for referrer, country, device, browser, OS, region,
city, network, colo, protocol, TLS, time zone), `buckets` (UTC hour and the scroll, time, bot
score, RTT and vitals bands), `utm`, `page_roles` (entry: any page view arriving from outside;
exit: last page of a gap-split session; human only), `events`, `event_props`, `session_daily`
(sessions, bounce, pages per session, median duration, engaged), `lanes`, `edge_daily`,
`edge_dims`. Day boundaries: daily, dims, events and entry pages use the site's day; buckets,
utm, exit pages, session_daily and the bot-signals store use the UTC day.

**Live**: the realtime cache (5 minutes) and 24 hours of 5-minute slots, aggregates only by a
written rule (no visitor id, IP, user agent or cookie in the store).

## What the MCP doors return today

**Local door** (owner only): `get-analytics-summary`, `live-now` (with 24 h slots), `north-star`,
`sn-status` sections (live, bot_signals, edge, search, recompute, ...), and `sn-metrics`
sections: summary, events, rss, machine readers, top content, sources, series, geography
(country only), devices, journeys (entry and exit), analytics_query (one or two dimensions, the
second always day), analytics_rows (path, referrer, country, device, link_code or day, or one of
them with day) and vitals.

**Remote door** (off by default; owner capability, slug allowlist and Cloudflare Access):
summary, events, analytics rows (`sn_remote_analytics_query`), bot signals, machine readers,
agent networks, edge 5xx, RSS, insights, narration and the Search Console reads. Ruled local
only: live, top content, sources, series, geography, devices, journeys, vitals and the query.

## Gaps

| Asked for | Today | Stored? |
|---|---|---|
| City, region | No tool (left out of the query vocabulary on purpose) | dims table (forever), AE (90 d) |
| Entry page, exit page | Local door only (journeys); remote no; per-session entry nowhere | page_roles (forever, different definitions); AE (90 d) |
| Hour, site time | No tool; only the UTC hour is stored | AE timestamp (90 d) |
| Returning vs new | Nothing | Cross-day impossible by design; same-day repeat derivable from AE (90 d) |
| More than two dimensions | Refused (two must be day plus one) | AE rows carry every field together (90 d) |
| Custom from/to | Refused (7, 14, 30, 90, 365 or all only) | Durable back to the first stored day; AE 90 d |
| Finer than a day | Only live 5-minute slots (24 h) | AE timestamp (90 d) |
| Individual sessions | Nowhere (the Sessions tab shows aggregates only) | AE (90 d) |
| Live per reader: city, device, entry source | Aggregates only, by rule | AE within seconds |

Also not on any MCP door today: session quality (bounce, pages per session, duration), UTM
campaigns, browser, OS, protocol, TLS, time zone, network, colo, the hour heatmap, scroll and
time distributions and percentiles, event properties, the insights signals, drill-downs, edge
dimensions and the login-defense counts. Every field the brief names exists in Analytics Engine
for about 90 days: the ceiling was the tooling, not the storage.
