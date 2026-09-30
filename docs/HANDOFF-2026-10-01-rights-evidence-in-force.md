# Handoff, 2026-10-01: rights evidence in force

Picks up after plugin **19.9.0**. One branch, `feat/rights-evidence-in-force`,
one pull request, not merged, not cut. Nothing was posted to the ledger,
nothing retracted, the cron hook untouched, `rights-evidence-now` never called.
The September hold (`sn_rights_evidence_hold`, seeded `2026-09`) stands.

**Provenance of this document:** the findings below were measured on
2026-09-30 against the live sensor (rights-signals Worker 1.29.0) and the
public ledger, and handed to this session as verified facts; this session did
not re-derive them. Everything under "What changed" is code and tests in this
branch, run in the standalone harness (no live site).

## Findings

**F1. The reservation cited versions not in force.** The August OpenAI record
cites tdm-policy v8, anchored at block 967489, about two days before the record
was composed, as in force for August. The composer read `index.json`, which
carries only each signal's current version, and stamped it `as_of` the
composition time. For any past month that is wrong by construction: the current
version can post-date the month.

**F2. Our own traffic sat in the rights stream.** September: 1,042 rows of
`unclassified-machine` / `signal-and-noise` / `ops` (our probes) and 167
`other-bot` / `dev`. They shared the 500-row cap with the crawlers the record is
about, which is how September's stream came to be truncated by our own probes.

**F3. No divergence, only truncation.** The 14-day aggregate and detail agree
(14 = 14); at 30 days the detail reads 17 against the aggregate's 14 because
the aggregate truncates. Nothing disagrees; one read is capped.

**F4. OpenAI's September rights reads were not training.** All 16 were purpose
`search`. The v1 record counts every rights-file fetch under `rights_reads`,
so a search agent reading `/license.xml` read as the training crawler looking
for the reservation.

The GPTBot question stays open. `robots.txt` has carried `User-agent: GPTBot /
Disallow: /` since 2026-06-30 (plugin v6.53.0). The UA `GPTBot` fetched HTML
214 times in August and 370 in September, a few asset, feed and wp-json reads,
and `robots.txt` zero times. Every row is unsigned, and the network column
(blob13) has only been recorded since 2026-09-29, so whether these requests are
OpenAI's is **unverified**. Recommendation: watch the network column for
OpenAI's published ranges before saying anything. Nothing public.

**F5. The stored records went stale.** A posted record's status was whatever
the worker said at POST time (`pending`); nothing re-read the ledger, so the
site's view never learned a record had confirmed.

**F6. The taxonomy could ship blank.** `sensor.taxonomy` came from the first
aggregate row, which can be a row with no taxonomy version. A record that
cannot say which classification it counted under should not ship.
`snt_mr_sensor_info()` (the version endpoint) does not report a taxonomy; the
aggregate envelope does (`taxonomy_version`), and `snt_mr_fetch()` dropped it.

**F7. The `/license.xml` burst is an external RSL client.** 165 requests with
`Go-http-client/1.1` and `Accept: application/rsl+xml`: nothing in our stack
is Go, so this is someone else's RSL reader. Two `curl/8.7.1` requests were
ours.

Coverage note: the rights dataset starts **2026-08-11** (the main dataset
2026-07-28), so August's rights reads before the 11th never existed. Filtered
to `openai`, 90 days of rights rows is 22 rows and reaches 2026-08-11.

## What changed

- **F1, the reservation in force.** `sn_rights_evidence_signal_history()`
  (new `inc/rights-evidence-ledger.php`) walks `rights-signals/<slug>/vN.json`
  for N = 1 to the current version, read-only, through
  `sn_prov_integrity_http_fetch()`. The pure `sn_rights_evidence_reservation()`
  selects every version in force at any point of the month:
  `{window:{start,end}, signals:{<slug>:[{block, content_hash, valid_from, valid_to, version}]}}`.
  **What I used for time:** the ledger gives only the block height. The
  existing code gets `block_time` for notes from the worker's confirm webhook,
  and the worker has no block time for rights signals, so the block's time is
  read from the Esplora API at `blockstream.info` (height to hash to block
  `timestamp`), the explorer the provenance worker already calls for
  transaction status. Confirmed versions and block times are cached forever in
  `sn_rights_evidence_chain`. A pending version is not claimed and leaves the
  previous one open-ended. A missing version file or an unreadable block time
  refuses the record. `as_of` is gone.
- **F2, the filtered stream.** `snt_mr_fetch( $days, 'rights', $filter )`,
  `snt_mr_rights_filter()` (family in the AI-training list, purposes in the
  closed enum, else `bad_filter` with no request), the filter in
  `snt_mr_cache_key()` and in `snt_mr_cache_flush()`. The pass reads the stream
  once per family per pass with `exclude_purpose=dev,ops`; compose also drops
  `ops`/`dev` rows itself.
- **F4, the purpose split.** `rights_reads` is purpose `train`;
  `retrieval_reads` (owner ruling D2) carries every recorded non-training
  purpose but `ops`/`dev`; `unlabelled_reads` carries rows with no recorded
  purpose (`''` or `unknown`), claimed by neither other block, since an
  unlabelled row could be a training crawler under a user agent the taxonomy
  missed. All three with `by_purpose` and `by_path` keyed by purpose.
  `crawling.by_surface` keyed by purpose, the unlabelled under `unlabelled`.
- **F5, the refresh.** `sn_rights_evidence_refresh()` runs first in every
  pass, held, not ready, or not: each stored record with a ledger path that is
  not `confirmed` or `conflict` is re-read from its ledger file and takes the
  file's `ots.status` and `ots.bitcoin_block`. Capped at 12 reads per pass
  (`SN_RIGHTS_EVIDENCE_REFRESH_CAP`), oldest month first.
- **F6.** The envelope's `taxonomy_version` rides the fetch result; the
  taxonomy is the envelope's, else the first row naming one. Empty refuses.
- **Schema.** `schema: 2` on every new payload; v1 records are implicitly 1.
- **Backlog fixes (Codex review, owner approved).** (1) A held month is
  queued before the readiness check. (2) The 90-day window guards composition
  only: stored unposted bytes are re-sent whatever their age. (3) Every stored
  unposted record of the month is re-sent whatever family the sensor lists; a
  backlog month is dequeued only when none remain unposted. Run order: refresh,
  enqueue-if-held, readiness, backlog target, lock, re-send stored, compose.
- **Dry run.** `sn_rights_evidence_compose_month()` (new
  `inc/rights-evidence-dry-run.php`) is the one composition the pass and the dry
  run share. `sn_rights_evidence_dry_run( 'YYYY-MM' )` and
  `wp sn rights-evidence dry-run <YYYY-MM>` (new `inc/rights-evidence-cli.php`,
  the first WP-CLI command in the repo) print canonical payloads per family.
  The dry-run file names no POST, send, pass or record option (a source pin);
  the only thing it can write is the block-time cache.
- **D1, owner ruling 2026-09-30: retraction plus erratum, never a v2.** The
  ledger's own rule (`rights-evidence-checks.mjs`: "a month's record is minted
  once; a correction is a retraction, never a v2") rules a superseding v2 out.
  `sn_rights_evidence_erratum( '2026-08' )` and
  `wp sn rights-evidence dry-run 2026-08 --erratum` print, per posted v1, the
  record corrected (`corrects:{ledger_path, content_hash, version}`), the
  reason and the reservation that was in force: the data for the erratum
  document in the ledger repo. Printed only; never a record.
- **Lift, both surfaces.** Monitoring > Machine Readers, native and classic:
  the status line (held, backlog, next pass), per held month a "View <Month>
  payloads" door (admin-post GET with its nonce, a JSON download) and, only when
  the worker is set up and the sensor answers, a "Lift <Month> hold" button
  behind a confirm (removes the month from `sn_rights_evidence_hold`, runs
  nothing). The Lift handler re-checks the gate.
  Bitcoin block timestamps can sit an hour or two off wall-clock; the block
  height rides beside each `valid_from`, so a verifier can recompute the time
  without trusting blockstream.info. It matters only for a version anchored
  within hours of a month boundary.

**First-run cost.** The first walk of the live ledger reads every version of
every signal (tdm-policy alone is at v8), three HTTP calls per confirmed
version (the version file, block-height, block), in one request. That can take
tens of seconds, and a heavy cron request has died near 60 s before without a
trace. Each confirmed version is cached the moment it is read, so a pass that
dies midway resumes the next day where it stopped, and a click on a View door
warms the same cache. A refusal or timeout on the first pass is that, not a
bug; after it, a pass reads only pending versions.

## Open decisions

1. **The ledger must learn schema 2 before the hold lifts.** Its checker
   (`rights-evidence-checks.mjs`) requires `reservation.as_of` and a flat
   `reservation.signals` list; schema-2 records would land and fail its own
   verification. A ledger PR teaches it schema 2 and keeps checking v1 as v1.
2. **August: retraction plus erratum.** One retraction per August v1 record
   (a signed record in `retractions/`; the v1 bytes stay) and an erratum
   document in the ledger giving the versions in force, from `--erratum`.
   Posting the retractions is its own owner-approved act. August rights
   coverage starts 2026-08-11 (the dataset's first row); the erratum says so.
3. **Lifting September.** The hold stands. The View door shows exactly what
   would be posted before the Lift.
4. **GPTBot.** Watch the network column; decide nothing until identity is
   verified.
