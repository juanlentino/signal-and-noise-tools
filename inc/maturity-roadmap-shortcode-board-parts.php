<?php
/**
 * Signal & Noise Tools: [sn_maturity_roadmap], the hub-wide roadmap board, the static board (one 354-line function, moved whole).
 *
 * Split from inc/maturity-roadmap-shortcode.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The STATIC board: family label → status → sentences, families in
 * render order. Every 'done' claim is verifiable against shipped
 * behavior; 'planned' names its gate; 'considering' commits to
 * nothing. This is the versioned default and the fallback whenever no
 * valid override option exists.
 *
 * KEEP THIS IN SYNC WITH THE OVERRIDE. Board rows move through the
 * write door, which writes an option — this array is not touched by
 * that path, so every door write silently widens the gap between what
 * the public page says and what this floor would say if the option
 * were ever lost. It is a floor nobody looks at until the day it is
 * the only thing rendering, which is the worst day to discover it is
 * a release behind.
 *
 * v10.71.1 resynced four rows after that gap ran for three releases:
 * the public stats page still sat in 'planned' after shipping in
 * v10.65.0, and the agent threat model was listed in BOTH 'done' and
 * 'considering' — the graduated row was retired from the override and
 * left standing here, so the floor proposed as an idea the thing it
 * claimed as shipped one column to the left.
 *
 * The rule this earns: a door write that moves a row is not finished
 * until the same move lands here, in the next release. There is no
 * automatic comparator — nothing in CI can see the option, because the
 * CLI fixtures have no database.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_static_board() {
	$board = array(
		__( 'Analytics', 'signal-and-noise-tools' )           => array(
			'done'        => array(
				// v13.20.0: GRADUATED planned -> done. The section was built against this
				// row's own words and the row was never moved -- tests/insights-narration.php
				// Test 8b quotes the contract ("assembled from the ledger already kept, no
				// new collection") back at itself. It took the slot the AI-referral row
				// vacated one release earlier, which is exactly what that headroom was for.
				// The two disciplines ride INSIDE the sentence because they are what makes
				// the claim honest, not implementation detail: the ledger's own window is
				// cited rather than blended into the digest's week, and a window that
				// measured nothing stays silent instead of narrating a zero.
				__( 'AI attention in the weekly digest: which crawler families read the site, and how many of those reads landed on the published terms rather than the prose, from the ledger snapshot the site already holds, no new collection. The ledger\'s own thirty-day window is cited, never blended into the digest\'s week, and a window that measured nothing stays silent (a thread shared with Machine readability)', 'signal-and-noise-tools' ),
				// 2026-08-12: the founding measurement row RETIRED to the
				// Analytics family maturity page (the done-column ceiling at
				// work — graduation off the hub is not deletion; that page
				// states the whole pipeline).
				//
				// 2026-08-12, SECOND retirement (owner call): the PUBLIC STATS
				// PAGE row retires too. The column sat at 4 — legal, but the
				// wall canary reds at 5, so the planned digest row had nowhere
				// to graduate to. This row was the oldest and most settled of
				// the four, and unlike an internal invariant it is
				// SELF-EVIDENCING: /stats/ is a live page a reader can visit,
				// so dropping the board row conceals nothing. (Noted because
				// the usual justification does not apply here — the Analytics
				// maturity page describes the measurement pipeline, not the
				// reader-facing surface. The page is its own record.)
				// v13.19.0: the AI-referral row GRADUATED onto /maturity/analytics/ as the
				// thirteenth principle. It was the oldest row in this column (entered done
				// 2026-08-11 17:58, three hours ahead of the give-back row), and the column
				// had reached the canary limit when v13.18.0's fold landed the Search
				// Console row -- so the planned digest row had nowhere to graduate to.
				// This is a GRADUATION, not the bare retirement the two earlier Analytics
				// exits were: that page states tiers and honesty principles and had NOTHING
				// on what is counted (zero hits for referr/assistant/crawl/cadence), and
				// unlike the stats-page row this one is not self-evidencing -- /stats/
				// publishes only Reading rhythm and Most read. The FEATURE is untouched:
				// inc/insights-narration.php still assembles the ai_referrals signal.
				__( 'Which machines send a reader back: per operator, the ledger\'s crawl counts published beside that operator\'s referred human visits, stated as a sentence each, not a ratio, because crawls are requests and visits are visitor-days. An operator that read the site hundreds of times and sent nobody back is the finding, and a bare 0 would bury it (a thread shared with Machine readability)', 'signal-and-noise-tools' ),
				__( 'Traffic rhythm flags: the cadence watch now reads views, so a quiet week flags against the site\'s own trailing weeks (robust to a viral spike and one-sided by design), read from the rollups already kept, never profiling a reader', 'signal-and-noise-tools' ),
				// 2026-09-08: THIRD Analytics retirement, plus a graduation, in one move.
				// RETIRED: 'Search-side metrics from Search Console'. Chosen because it is the
				// only row in this column NO TEST PINS (0 references; the others carry 1, 2 and
				// 4). 'Which machines send a reader back' was the first candidate and is the
				// WRONG one: two DR-floor pins guard it, and the second exists so that a
				// DECLINED design ('ratio') cannot quietly return as an unmet promise. Retiring
				// it would have deleted that guard. Not self-evidencing either way, so the claim
				// GRADUATES onto /maturity/analytics/ rather than being deleted.
				// GRADUATED IN (later -> done): signature verification has been live since
				// rights-signals worker v1.24.0 (src/web-bot-auth.mjs); signed_agent is
				// normalized in machine-readers-taxonomy.php and rendered in
				// machine-readers-render.php. The row sat in `later` describing live work.
				// REWRITTEN for done: the old wording implied the separation is useful. The
				// measured population says otherwise -- the signers are search and testing
				// tools, the AI crawlers sign nothing. A done row carries its limit inside it.
				__( 'Verified versus claimed: crawler request signatures checked at the edge and recorded in the ledger, so a cryptographic signature can be told from a user-agent costume, with the honest reading beside it, because the crawlers that actually sign are search and testing tools while the AI crawlers sign nothing at all (a thread shared with Machine readability)', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'The questions search asked that the corpus doesn\'t answer: queries with impressions and no clicks, derived from the Search Console rows already synced (read by the owner\'s eyes, never by a model), landing once the daily sync has accrued a full month to speak from', 'signal-and-noise-tools' ),
				// 2026-09-08: gate NAMED — two runs of the weekly check must accrue before there is
				// anything to compare. The defect is real and was measured today: the coverage run
				// stores one option and overwrites it, so the crawl-frequency question cannot be
				// asked backwards. A baseline was captured by hand to make one comparison possible.
				__( 'Coverage history kept rather than overwritten: the weekly index check retains its earlier readings, so a change in how often Google crawls can be seen at all. Today each run replaces the last, so the question cannot be asked backwards. It lands once two runs have accrued to compare', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Where readers leave to: outbound clicks on citations counted as their own aggregate event, the first idea in this family to require new collection, named as such, and it ships only if the counting stays as blunt as the rest of the ledger', 'signal-and-noise-tools' ),
				__( 'A year told as a page: the deterministic narrator pointed at twelve months instead of a week (what rose, what faded, what the corpus became), computed from the rollups already kept, no new collection', 'signal-and-noise-tools' ),
				// 2026-09-08: the cadence watch already fires; this is the sentence after the flag.
				__( 'A quiet week explained, not only flagged: when the cadence watch fires, the same rollups say which surfaces moved, so a flag arrives carrying its own arithmetic instead of sending the reader back to the charts', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				// 2026-09-08: considering -> later. Reader-facing browser-local state with no
				// measurement payoff -- the nearest neighbour of the denied perimeter's
				// reader-facing surfaces, and the only Analytics idea that buys no number.
				__( 'A reading ledger that never phones home: which notes you have read, kept in your browser\'s own storage, shown on the index, sent nowhere: the site\'s privacy posture expressed as a feature only the reader can see', 'signal-and-noise-tools' ),
				// 2026-09-08: the instrument held to its own standard.
				__( 'What the measurement costs: the analytics stack\'s own footprint (rows written, storage held, queries run) reported beside what it reports, so the instrument is accountable on the same page as its findings', 'signal-and-noise-tools' ),
			),
		),
		__( 'Proof of origin', 'signal-and-noise-tools' )     => array(
			'done'        => array(
				__( 'Notes carry a signed commit chain anchored to Bitcoin, and every accepted edit re-anchors the note as a new version', 'signal-and-noise-tools' ),
				__( 'Key history with a future: the signing key\'s verifiable history published at a well-known path, retired keys kept inside dated validity windows so anchors made under them still verify, and the next key committed by hash before it is ever used, so a rotation never orphans years of anchors', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.6.0, R5): graduated considering -> done. The
				// verifier had substantially existed in the ledger repo; what
				// shipped here is the missing half — the surface: the /verify
				// page now names its own residual trust and hands the reader
				// the clone-and-run path. Rewritten present-tense; the wording
				// honours §9.5 P-54 (says what the verifier IS).
				__( 'A standalone verifier anyone can run outside the site: the checks live as a small readable program in the public ledger repository (clone, run, trust only the code in front of you), and the site\'s own verify page points at it as the way out of trusting the page itself', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.6.1, R5): graduated considering -> done, same
				// day as its sibling — the verifier row's completion, not a
				// separate idea. Owner-selected anchor: public-CI build
				// attestations, with the honest limit INSIDE the claim (the
				// anchor is the code host); the self-contained site-key path
				// stays recorded in threat model §9.5 P-54 as the upgrade.
				__( 'Provenance for the software itself: releases of the verifier are built in public CI and published with a build attestation, so the tool a stranger downloads can prove which commit built it; the verifier argument applied one layer down, with its limit stated: the attestation\'s anchor is the code host, and reading the cloned code remains the trust floor (a thread shared with Operations)', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend signing and anchoring beyond notes, to pages and then media', 'signal-and-noise-tools' ),
				// 2026-09-08: PROMOTED from the parked watch, which already carried both triggers.
				// This makes it a public promise; the gate is the watch's own condition, unchanged.
				__( 'A second timestamp anchor beside Bitcoin, the day either trigger fires: an observed window where pending proofs cannot upgrade, or a public anchoring authority whose liveness is not one chain\'s. Until then single-anchor is the stated posture, not an oversight', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'A quote that carries its receipt: select a passage and get the quote with its attribution, its link, and (on a signed note) its anchor attached, with academic citation formats beside it and the landing highlight styled: citation made honest at the moment of copying', 'signal-and-noise-tools' ),
				__( 'What changed, shown to readers: every accepted edit already re-anchors a note as a new version; a rendered difference between anchored versions turns that chain into legible editorial history, corrections owned in public', 'signal-and-noise-tools' ),
				__( 'The manuscript beside the proof: each signed note\'s canonical text mirrored into the public ledger repository beside its hash, so the proof is self-contained and a verifier needs no live site to check what was said', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'A second witness that names the author: each note\'s chain head countersigned into a public transparency log: an independent record binding the proof to an identity, not just a moment', 'signal-and-noise-tools' ),
				__( 'Authorship as a credential: each note carrying a standard verifiable credential of authorship in its structured data: the claim stated in a shared vocabulary instead of a house dialect (a thread shared with Machine readability)', 'signal-and-noise-tools' ),
				__( 'The chain outlives its curve: the anchors sign with Ed25519 today, and the key history already commits each next key by hash, so when post-quantum signature practice settles, the successor key can be post-quantum and the rotation is a planned crossing, not an emergency', 'signal-and-noise-tools' ),
				__( 'Continuity stated: a signed succession plan for keys and corpus: what a reader can still verify if the site ever goes silent, because a signed record outlives its subject', 'signal-and-noise-tools' ),
				// 2026-09-08: considering -> later. It needs a SECOND SITE to agree, and there is no
				// candidate -- that is blocked-on-a-partner, not considering.
				__( 'A witness that is a peer, not an institution: two independent sites countersign each other\'s chain heads: mutual custody with no authority between them, the thesis said out loud on live infrastructure', 'signal-and-noise-tools' ),
			),
		),
		__( 'AI', 'signal-and-noise-tools' )                  => array(
			'done'        => array(
				__( 'Two isolated agent doors (read-only and write) behind curated allowlists, kill switches, an audit trail, and rate limits', 'signal-and-noise-tools' ),
				// 2026-08-28 GRADUATION: the staged-body-edits row retired to
				// make room for the spend row below — the done ceiling's canary
				// reds at MAX_DONE, and this was the one neighbour whose claim
				// ALREADY stands on the family page: the AI maturity page's
				// FIRST principle states the same boundary in the same terms
				// ("nothing reaches a published body except through a staged
				// revision a person accepts"), so retirement moves nothing and
				// loses nothing. The threat-model precedent (2026-08-14), at
				// its cheapest: no page addition was even needed.
				__( 'The roadmap board as data: an agent proposes the whole board at once behind a fingerprint and a leak sweep, so planning copy ships like content instead of code', 'signal-and-noise-tools' ),
				// 2026-08-14 GRADUATION: the phone door shipped, and the done
				// ceiling reds at 5, so a row had to retire to make room. The
				// threat model retired rather than any of its neighbours for
				// one reason: it is a written document, so the claim survives
				// the board — it now stands as an honesty principle on the AI
				// maturity page (sn_ai_maturity_principles()), where a reader
				// who wants it can still find it. The three rows left here are
				// mechanisms with no document to point at; retiring one of
				// those would have deleted the claim, not moved it. Retirement
				// is removal from the HUB only, and the floor pins it in NO
				// column — see the DR-floor pins in the test.
				//
				// Rewritten, not moved: a done row states what acts today, so
				// the gate clauses that made it a legal PLANNED row stay inside
				// the claim as facts about the shipped door rather than as
				// preconditions it has yet to meet.
				__( 'Read the site\'s numbers from a phone: an analytics-only door, reached through a scoped token that expires rather than the site\'s own password, with the rate ceiling that guards it failing closed instead of open, a stop switch reachable from the phone it protects, and a record of what it served; writing stays on the desktop, and nothing that can name an unpublished draft goes through it', 'signal-and-noise-tools' ),
				// 2026-08-28 (v13.21.0): the spend row graduates considering →
				// done, skipping planned (the R4-ML precedent: shipped the
				// same arc the commitment was made). REWRITTEN HONESTLY, not
				// moved: the considering sentence promised "read from what the
				// platforms report, never estimated" — that clause describes
				// the Health spend WATCH (which stays platform-true), not what
				// shipped here. The itemization is the plugin's own priced
				// ledger, and the done claim says so rather than inheriting a
				// promise the shipped thing deliberately does not make.
				__( 'Spend with an address: the AI bill itemized by feature beside the monthly total, each call priced into a named bucket as it lands, so a rising bill names its cause before any statement arrives; the itemization is the site\'s own priced ledger, read beside, never inside, the platform-reported spend watch (a thread shared with Operations)', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Move the operative AI channel to the desktop platform\'s native agents, once that runner is stable enough to trust with the same fences', 'signal-and-noise-tools' ),
				__( 'Retire the legacy single-purpose tools the consolidated set absorbed, on usage evidence rather than on a date', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Richer edit primitives beyond sentence scale; the drafting boundary stands regardless of what is explored here', 'signal-and-noise-tools' ),
				// 2026-08-28: "Spend with an address" left this cell for done
				// (v13.21.0) — see the graduation note there for the honest
				// rewrite of its "never estimated" clause.
				// 2026-09-08: the considering cell fell to one row after the scheduled-agent
				// demotion. These two refill it from machinery that ALREADY EXISTS — neither
				// asks for new collection, and both clear the denied perimeter in
				// proposals/2026-08-27-roadmap-brainstorm.md. The first is the deliberate
				// companion to the shipped spend ledger in this family's done column.
				__( 'The refusals, counted: every write a door declines, set against the rule that declined it: the gates already reach these verdicts in private, and a refusal nobody can audit is a decision nobody can check. Read from decisions already made, never new collection, and the companion to the spend ledger: what it cost beside what it would not do', 'signal-and-noise-tools' ),
				__( 'The hand on the edit, named: an accepted change carries whether a machine proposed it and a person accepted, inside the version the chain already re-anchors; custody is untouched, the author still signs (a thread shared with Proof of origin)', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Injection self-sweep: every machine surface the site publishes (the crawler manifest, structured data, the board itself, the doors\' own descriptions) linted for instruction-shaped text before it ships, so a site that treats prose as data can prove its own prose is clean (a thread shared with Machine readability)', 'signal-and-noise-tools' ),
				__( 'A registry-listed read door: the read-only door published in the standard agent registry under a name this domain verifiably owns, so agents discover it by lookup instead of by reading a page written for humans', 'signal-and-noise-tools' ),
				__( 'Cross-family threads made visible: rows that span families carry their partners as chips, so the board shows the weave, not just the columns', 'signal-and-noise-tools' ),
				__( 'Doors with versioned contracts: an agent pins the contract it integrated against, so a door upgrade never breaks a client silently: API discipline applied before the consumers arrive rather than after', 'signal-and-noise-tools' ),
				// 2026-09-08: considering -> later. Downstream of a gate that has not opened -- this
				// family's planned row moves the operative AI channel to the native agents, and
				// that arc is DISABLED. Considering read as more available than it is.
				__( 'Scheduled read-only agent runs for recurring reports', 'signal-and-noise-tools' ),
			),
		),
		__( 'Machine learning', 'signal-and-noise-tools' )    => array(
			'done'        => array(
				// The founding row graduated onto /maturity/machine-learning/ (v14.0.0)
				// when this column hit the wall canary — the page's Corpus/Model/Compute
				// layers already state it.
				__( 'Draft-time echoes: while writing, the most similar existing note surfaces, so overlap is a choice instead of a surprise: the same corpus statistics the related layer already computes, asked from the draft\'s side, and below the bar it stays quiet rather than offering the least-bad match', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.2.0 + v11.3.0): both R4 ML rows graduated in
				// one sweep — considering → done, skipping planned, because the
				// features shipped the same day the commitment was made. This
				// EMPTIES the ML considering cell: the board's zero-empty pin
				// moves to 1, honestly (see tests). Rewritten, not moved.
				__( 'Corpus drift as an editorial mirror: the vocabulary\'s year-to-year movement shown to the writer as which terms rose, fell, entered, or went silent (and a year too thin to speak says so instead of publishing a confident zero), computed from corpus statistics, shown to the writer, never to a model', 'signal-and-noise-tools' ),
				__( 'Reading paths from cluster geometry: each topic cluster carries a precomputed note-to-note chain, entered at its most central note, identical for every reader and recomputed only when the corpus changes: sequencing, not personalization', 'signal-and-noise-tools' ),
				__( 'Search served by the kernel: the notes search box ranks by the same BM25 arithmetic that picks related notes: any word the reader typed can match, the best answer comes first, and each ranked row shows the sentence that answered with the words marked. Deterministic corpus arithmetic, no model in the browser; pages still follow in date order', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend the deterministic layer pipeline by pipeline, as real editorial questions demand it', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'The words a note owns: each note\'s distinctive vocabulary against the whole corpus, surfaced to the writer from the tf-idf arithmetic the related layer already computes: a new question asked of existing mathematics, not a new pipeline', 'signal-and-noise-tools' ),
				__( 'The calendar of themes: which subjects belong to which season, read from the corpus\'s month-of-year rhythm (distinct from drift, which watches years, and cadence, which watches output), and a month too thin to speak says so', 'signal-and-noise-tools' ),
				__( 'The corpus index, published: the distinctive-vocabulary arithmetic rendered as a browsable index where each term links to the note that owns it: the site becoming its own reference work', 'signal-and-noise-tools' ),
				__( 'A better lexical spine: the similarity core under related notes upgraded to the ranking statistics that stop long notes from hogging every match: the same deterministic corpus mathematics, corrected for length', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'The shape of a sentence, watched: a deterministic readability fingerprint per note, trended across the corpus and surfaced at draft time, so prose complexity drift becomes visible the way topic drift already is', 'signal-and-noise-tools' ),
				// 2026-09-08: the honest complement to the clustering rows in done.
				__( 'What the arithmetic cannot place: notes that sit in no cluster and resemble nothing, listed rather than hidden; a similarity engine is most honest about what it fails to place, and the outliers are where the corpus is actually thin', 'signal-and-noise-tools' ),
			),
		),
		__( 'Machine readability', 'signal-and-noise-tools' ) => array(
			'done'        => array(
				// v13.18.0: the crawler-manifest row GRADUATED off this board.
				// Unlike the a11y pair below it needed no new sentence at its
				// destination: /maturity/machine-readability/ already states all
				// three of its clauses -- the manifest "in the site's own words"
				// IS that page's second principle, and its Structured and Reserved
				// layers carry structured data and the rights terms. A row moves and
				// is never copied; here the destination already held it, so the move
				// was a removal plus a test pin, not a restatement.
				__( 'The rights-read count published on the machine-readability page itself, served from an hourly snapshot the site already holds: no sensor call on a reader\'s path, and a count never measured renders as unmeasured, never as zero (a thread shared with Analytics)', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.7.0, R5): both quartet MR rows graduated
				// considering -> done in one release — they publish the same
				// pointer set to the same invited anonymous caller (threat
				// model A6) and shipped as one module. Built against §9.5:
				// the manifest asserts nothing, adds no anonymous compute,
				// and every URL comes from the one endpoint producer the
				// /verify page itself consumes.
				__( 'Provenance pointers in the machine surfaces: a signed note\'s structured data carries its ledger identifier, joined to the record an agent can fetch, so a machine that reads the site can also verify it (a thread shared with Proof of origin)', 'signal-and-noise-tools' ),
				__( 'An in-page tool surface for verification: every signed page ships a data-shaped manifest of the exact calls (credential, record, proof, key history, block header), so verifying travels with the content; the page lists inputs and asserts nothing, because the verdict belongs to the caller (a thread shared with Proof of origin)', 'signal-and-noise-tools' ),
				__( 'Google\'s account cross-examined by the edge\'s: what Search Console says it showed set against what the crawler ledger says actually fetched, deliberately at the family level the ledger really keeps, not a per-page join it cannot support, so a disagreement between the two instruments is the finding and which way it falls names the problem (a thread shared with Analytics)', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Speak the coming standard: publish the usage-preference header and robots rule the day the internet standards body finalizes them, with a parity sweep proving every rights dialect the site speaks states the same reservation: one policy, never a family of drifting translations', 'signal-and-noise-tools' ),
				// 2026-09-08: gate NAMED — a second client. The negotiation already ships in the
				// worker; what is unproven is that anything but this site consumes it. Moved up from
				// later, where it read as further off than it is.
				__( 'Markdown at the agent door served under the site\'s own rights headers, once a second client proves the negotiation against something other than the site that wrote it', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'The corpus schema published as a machine surface: tier, number, and relation stated by the author rather than inferred by whatever reads the page', 'signal-and-noise-tools' ),
				// 2026-09-08: refills a cell left at one row after two stale duplicates were struck.
				// The drift is real and recorded: the denied list lives in three separate specs.
				__( 'The refusals stated once: the surfaces that publish this site\'s denials kept from drifting apart, so a policy is one fact with several renderings rather than three documents free to disagree', 'signal-and-noise-tools' ),
				// 2026-09-08: the read side of the declarations already published.
				__( 'What an agent actually took: the machine surfaces\' own read counts broken out per surface, so a declaration nobody fetches is visible as unread rather than assumed effective', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Homework shown: when the European list of machine-readable opt-out protocols is published, a page mapping this site\'s declarations to every protocol on it: conformance demonstrated, not claimed', 'signal-and-noise-tools' ),
				__( 'The corpus as a dataset, under its own terms: a versioned bulk export with the rights declarations stamped inside the artifact; an agent fetches once instead of crawling five hundred times, and the terms travel with the copy', 'signal-and-noise-tools' ),
			),
		),
		__( 'Accessibility', 'signal-and-noise-tools' )       => array(
			'done'        => array(
				// v13.18.0: the two ALT-TEXT rows graduated onto
				// /maturity/a11y-maturity/ as the eleventh and twelfth principles.
				// They moved as a PAIR at owner direction -- coverage and quality are
				// one story in two halves, and graduating only the older would have
				// left the page explaining whether a description exists while the
				// board still carried whether it is any good.
				//
				// Also gone: the per-palette contrast row, whose v12.6.3 comment
				// lived here. It had ALREADY graduated to that page as the tenth
				// principle (v13.8.2) through the board door; only the static floor
				// had not caught up. That note pointed at "the planned row below"
				// for the undelivered computed-styles half -- which is now done and
				// sits in this very cell, so the note could not be kept as written.
				__( 'Charts that speak: the stats page\'s chart ships with its voice built in: a deterministic one-paragraph summary and a calendar-shaped table twin a screen reader navigates with week and weekday context, the picture itself only decoration (a thread shared with Analytics)', 'signal-and-noise-tools' ),
				__( 'Contrast verified from COMPUTED styles: a browser-run instrument walks every rendered text node on the live pages in both palettes (real nesting, inline overrides, the opacity chain composited over the true ground), the half a file cannot answer; its first real catch was this board\'s own legend fades, lifted to the measured AA floor, and the closing run reads zero pairs below AA', 'signal-and-noise-tools' ),
				__( 'Motion that asks first: every animation and vestibular transition inventoried with its guard by a report-first scan (opt-in media gates, reduce resets, and soften counterparts all recognized, JS motion classified with each claim verified by grep), and the lazy transition-of-everything declarations it surfaced narrowed to the properties their hovers actually change', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'The keyboard walked, not assumed: a browser-run instrument tabs through the live pages recording focus order and focus visibility (the computed-contrast discipline applied to keyboard access), landing as the next instrument arc, on the runner the contrast walk already proved', 'signal-and-noise-tools' ),
				__( 'Targets measured, not eyeballed: the 24-pixel floor of WCAG 2.2 checked from rendered geometry (reading the page the finger actually meets), riding the keyboard walk\'s instrument arc, since both read the same rendered page', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				// 2026-08-12: the facade was BUILT (theme v11.8.0), tried live,
				// and reverted the same day by owner choice (v11.8.1). The
				// decline and its reopening condition ride inside the sentence,
				// the read-door pattern: the board records the shape the answer
				// took, not merely that one exists.
				__( 'An accessible facade for third-party embeds: built, shipped, and DECLINED in practice: the music page\'s hero returned to an eager player by owner choice after a day live, trading no-fetch-before-consent for immediacy; the discography grid keeps click-to-play. Reopens only if an embed ever lands in a note body, where the consent argument outweighs a catalog page\'s.', 'signal-and-noise-tools' ),
				__( 'Headings and link text as findings: skipped heading levels, click-here anchors, and bare-URL link text raised for a person to accept or reject: the alt-quality discipline extended to structure and wording', 'signal-and-noise-tools' ),
				__( 'Contrast on both scales: every instrument run records APCA beside AA, so when the standard turns, the site arrives with a history of the new number instead of a scramble', 'signal-and-noise-tools' ),
				__( 'Accessibility at the moment of publishing: the pre-publish panel\'s advisory warnings extended to missing alt text and skipped heading levels, so the site-wide sweeps stop being the first line of defense', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Conformance said out loud: a public accessibility self-assessment on the hub, fed by the scans and honest about what fails: the site\'s accessibility posture stated the way its rights posture already is', 'signal-and-noise-tools' ),
				// 2026-09-08: the gap every static check leaves open.
				__( 'Heard, not inspected: the pages walked through a screen reader\'s own accessibility tree instead of through markup, so what a reader hears is measured rather than inferred', 'signal-and-noise-tools' ),
			),
		),
		__( 'Operations', 'signal-and-noise-tools' )          => array(
			'done'        => array(
				// v13.18.0: the one-dashboard row graduated onto
				// /maturity/ops-maturity/ as the ninth principle. Only half of it was
				// new there -- the "unknown" discipline was already that page's third
				// principle -- so the graduated sentence carries the half stated
				// nowhere: that these signals answer from ONE surface, not several.
				__( 'Defense numbers: the login door\'s own gauges, owner-only: fail-opens and degraded reads counted with zeros stated out loud, and the unchecked address share measured against a threshold written down before the query, so the number triggers the decision instead of reopening the argument', 'signal-and-noise-tools' ),
				__( 'Spend watched like uptime: build minutes and AI spend on the health widget, owner-only: every number read from what the platforms actually report, "unknown" when a read fails, and never an estimated or invented figure', 'signal-and-noise-tools' ),
				// 2026-08-28 GRADUATION: the morning-brief row retired to make
				// room for the dependency-provenance row below (the done
				// canary reds at MAX_DONE). Its narration half already stands
				// as the ops page's Narrate layer; its OTHER half — the mail
				// path carries no AI and no content prose, so nothing injected
				// can become instructions — was stated nowhere, and now stands
				// as that page's TENTH principle (sn_ops_maturity_principles).
				// The v13.18.0 pattern: graduate the half stated nowhere.
				__( 'Configuration drift watched: the effective settings snapshotted against an acknowledged baseline that moves only on acknowledgment or a version change: a changed switch is a visible event, not a mystery', 'signal-and-noise-tools' ),
				// 2026-08-28: planned → done, both legs shipped. Rewritten as
				// facts: the audit gate clause ("landing after a one-time
				// audit…") became true 2026-08-14 and is now history, not a
				// precondition; the cooldown's reviewed-exception discipline
				// joins the claim because it is what ships.
				__( 'Dependency provenance gate: a worker ships only after its locked dependency tree verifies against the registry\'s provenance attestations and every version has waited out a minimum-age cooldown; a freshly poisoned upstream release sits in its detection window instead of going straight to the edge, and a deliberate young bump is a reviewed, per-version exception rather than a silent pass', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				// 2026-08-28: promoted considering → planned by the owner when
				// the dependency-provenance graduation emptied this cell — the
				// no-empty-cell rule forces the conversation, and this was the
				// commitment chosen. The gate is in the sentence, per the rule.
				__( 'The calendar of quiet failures: service keys, scoped tokens, domains, and certificates watched with their dates stated before they lapse (expiry as a scheduled event instead of an outage), landing when the first credential whose renewal no platform manages for us enters the estate', 'signal-and-noise-tools' ),
				// 2026-09-08: gate NAMED and already INSTRUMENTED — the criterion publishes its own
				// verdict, and the standing rule is to act only on decision = build_ranges.
				__( 'Login-defense ranges built from the measured criterion, on the day that criterion says ranges beat individual addresses; the decision is already computed and watched, so the gate is a reading rather than a judgement call', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Restore proof, not backup existence: a periodic check that a backup actually restores, closing the gap between having backups and having recovery', 'signal-and-noise-tools' ),
				__( 'The corpus in a pocket: a service worker caching the notes for offline reading: fifty-odd notes is nothing, and the site that can leave its platform should also survive a tunnel', 'signal-and-noise-tools' ),
				__( 'A passkey at the owner\'s door: sign-in by passkey with the password demoted to fallback; the most-attacked surface on the site should not be the last to walk away from passwords', 'signal-and-noise-tools' ),
				__( 'Journey checks, not pings: a scheduled pass that walks the flows that matter (the stats page rendering its numbers, the rights surfaces serving their terms), because a healthy status code is not a working site', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'The site that can leave: a periodic static export of the whole corpus with provenance intact: proof the content survives the platform it was written on, the standalone-verifier argument applied to the site itself (a thread shared with Proof of origin)', 'signal-and-noise-tools' ),
				// 2026-09-08: the inventory an incident wishes existed beforehand.
				__( 'The estate described to itself: one machine-readable inventory of every service, binding and door this site depends on, so a forgotten dependency is a missing row rather than a discovery during an incident', 'signal-and-noise-tools' ),
			),
		),
	);

	return $board;
}

