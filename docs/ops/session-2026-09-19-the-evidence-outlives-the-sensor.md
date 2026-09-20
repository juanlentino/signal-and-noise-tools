# Session, 2026-09-19: the evidence outlives the sensor

Two arcs in one day, and they rhyme. The first was a judge whose question was
stricter than the house it judged: three fixes in a row, and only the third
one touched the question. The second was a ledger that kept the reservation
and the notes and nothing about who read them: by the end it holds one
anchored record per crawler family per month, the policy says so in its own
text, and the ledger's CI verifies the records the way it verifies everything
else. Along the way the ledger's own capture turned out to be the loudest
reader of the rights files, and a doubly anchored record had kept the
ledger's verifier red for two days.

## A shrug is not a verdict (16.9.1)

16.9.0 shipped Jev's tag fit onto Content › Tags, and the owner opened it to a
wall: 40 misfits and 171 adds across 63 of 69 notes. I read the stored pass
through the door before touching anything. Twenty-seven of the forty misfits
sat between 0.5 and 0.99 with confidence 0 to 0.26. Jev had shrugged and the
leaf had painted each shrug as a verdict. The adds were four umbrella tags
(Authorship, Content Authenticity, Creation-Time Capture, Verification Limits)
suggested on fifteen to twenty-five notes each.

The fix moved the lines at read time, so the 422k-token pass was re-read
rather than re-bought: a misfit needs confidence 0.5, an add starts at 0.8, and
a tag Jev would attach to a third of the corpus is one vocabulary line at the
top, never a row per note. Same shape as 16.3.4 and 16.5.1: the first live
reading moves the line.

## A tag names what a note touches (16.9.2)

The owner sent two screenshots. "The unlabeled majority" wanted seven tags
added; "Market harm names no track" wanted three of its four removed at 0.07
with confidence 0.9. The bodies were there (752 words). Both walls came from
one mismatch: Jev was asked whether a note *argues* what a tag names, and the
site tags by what a note *touches*. Under the strict question a broad facet
fits 44 of 69 notes, and a touched-not-argued tag reads as attached for
reach.

So the third fix was the question. The add side went entirely: no Noul per
candidate (sixty questions a note, most of the tokens), no umbrella lines, no
Add boxes, no `assign[]` in the handler, and `suggest-tags` unregistered with
its only source. The attached-tag question now asks whether the note touches
what the tag names so a reader browsing the tag would find it relevant, and a
misfit is under 0.5 at confidence 0.7 or better. The lesson I want to keep: a
wall of findings is a question mismatch before it is a threshold. Two
threshold fixes could not have found it; the screenshots did.

## The rule is the description (16.9.3)

Asked how many tags a note should carry, I read the corpus (32 of 44 at 3 or
4, mean 3.43) and shipped a ceiling of four in three places. The owner pointed
at /notes/tags and doubted it. The page's promise is "each one says what it
covers, so you can tell before you click", which makes the rule the
description, not a number. And the five notes over the ceiling had zero
misfits in the pass: five fitting tags each. A count read off the
distribution is circular and flags nothing wrong. 16.9.3 dropped the ceiling
rows from tag hygiene, sn-scan and both Tags surfaces the same day, and kept
the gate's prompt at five, reworded to the rule: each tag promises the note
covers its description; drop any it only brushes.

## The ledger reads (17.0.0, part one)

The machine-readers investigation had three objectives. One and two were
answerable from data the sensor already stores and nothing exposed:
`get-machine-readers-crosstab` (family x purpose x agent, with days seen and
hits per surface) and `get-rights-reads` (every fetch of the rights files
from the full-fidelity stream, no user-agent string, a cadence per family and
path with a poller flag, and `ai_rights` counted from both datasets so the
10-versus-8 gap is a reading). Pure folds over `snt_mr_fetch()`; no twin
shape moved; the durable snapshot gained `by_family_purpose`.

## The evidence (17.0.0, part three, and worker 1.21.0)

Objective three: the ledger held the reservation and the notes, not the
triple a dispute needs. Now, on the first daily pass after a month closes,
the plugin composes one canonical JSON per AI-training family the sensor saw:
the reservation in force from the ledger's own index, that family's fetches of
the rights files, and its crawling per day with the training share, each
block saying whether the sensor read covered the month. The id is the UUIDv5
of the record's own URL; the bytes are stored before the POST and re-sent
verbatim on failure; a lock keeps cron and the on-demand pass apart; a 409 is
a terminal conflict whose record stands. The PHP canonical bytes were run
through the worker's own canonicalizer and came back byte-identical, empty
maps as objects. Worker 1.21.0 learned the kind, exempt from the WordPress
confirm and outside the verify index. Three security reviews, no findings.

The first pass, run from here after 17.0.0 installed: four August records on
the ledger within a minute (openai 2,504 reads and 218 training, anthropic
396 and 255, google-ai 287 and 184, commoncrawl 17), OTS pending, anchored by
the next sweep. The ledger's new verifier reads them as 4/4.

## The loudest reader of the rights files was us

All four records carried `rights_reads.complete: false`. The stream's 500-row
cap reached back eight days, because 468 of the last 500 rights-file fetches
were `unclassified-machine`: the provenance worker capturing the five
rights files every hour with no User-Agent. The sensor could not tell our own
ledger from a stranger, and it drowned the stream the evidence reads. Two
fixes: the capture names itself (sn-provenance 1.21.1), the taxonomy marks it
first-party, and a first-party read never spends the detail stream's cap
(sn-rights-signals 1.26.1, guard verified red). August's rights counts were
still in the records, from the aggregate; October's will carry the per-path
detail.

## A forked proof names several blocks

The ledger's hourly verify had been red since the 18th, and the first PR I
opened there caught it. Worker 1.20.0 sends every digest to every calendar
and forks the proof, so `tdm-policy v8` commits to blocks 967489 and 967491;
the record names the earlier one and `bitcoinAttestation()` read the first in
serialization order. The verifier now lists every attestation and cites the
block the record names when the proof carries it, else the earliest. Four
tests against the real forked proof in the ledger. Then `verify:rights-evidence`
landed behind it: hash, signature, OTS digest and block, then the claim, with
the directory name recomputed from the record's site, family and month, pinned
against the plugin's own ids.

## The policy says so

Nothing in the arc touched the theme, and the public site renders none of it.
The one public surface that describes the ledger is the TDM policy's section
6, which said the policy's versions are anchored. 1.3 adds one paragraph
after it: from September 2026 a record is anchored for each month and each
crawler family that read the site, counts and paths, never a browser string,
evidence of what was published and read and not a finding of acceptance or
breach. The owner read the sentence before it went. An appendix note records
1.3 the way the 1.0 note does; no term in sections 1 to 3 changed. Workers
Builds deployed it from the merge, and the sweep anchored it as
`tdm-policy v9` within the hour.

## Cuts and versions

Plugin 16.9.1, 16.9.2, 16.9.3, 17.0.0 (16.9.x plus a release rolls X); the
ledger arc cut once, as policy says. sn-provenance 1.21.0 and 1.21.1 deployed
from here; sn-rights-signals 1.26.0 and 1.26.1 deployed by Workers Builds;
worker releases are drafts. Ledger PRs #32 and #30 merged; main's verify is
green again.

## Left open

- The four August records are OTS pending; the sweep confirms them. Read the
  ledger tomorrow: `rights-evidence/<uuid>/v1.json` should say confirmed with
  a block.
- The daily pass composes September on 1 October. With the capture now
  first-party, the rights stream holds strangers only, so September's records
  should carry `rights_reads.complete: true`. If one says false, the stream's
  cap is the next thing to look at (a `family` filter on the rights view is
  the shape).
- Tags: press Read tags now once on 17.0.0 for the house-question pass (the
  stored pass is still the strict one, read under the new lines). The two
  umbrella facts stand as vocabulary: Authorship on 19 notes, Content
  Authenticity on 9.
- Check 30 (Jev notes) overlaps check 28 (search titles) on the worklist;
  fold or advisory is the owner's call.
- The rights-signals repo tracks a `node_modules` symlink that points at its
  own path. `npm ci` replaces it with a directory and `git add -A` then
  commits the deletion; stage files by name there.
- Worker release drafts: sn-provenance 1.21.0 and 1.21.1, sn-rights-signals
  1.26.0 and 1.26.1, all drafts per policy.
