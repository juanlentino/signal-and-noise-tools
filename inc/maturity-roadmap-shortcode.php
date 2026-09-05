<?php
/**
 * Signal & Noise Tools — [sn_maturity_roadmap], the HUB-WIDE roadmap
 * BOARD: one hard-framed row per family, three status columns
 * (done / planned / considering), readable both ways — scan a row for
 * one family's arc, scan a column for everything planned site-wide.
 * An empty cell renders an em-dash: a family with no future tense is
 * information, not a gap. An item moves LEFT as it matures, so the
 * page demonstrates the promotion flow it documents.
 *
 * Same family contract as every maturity sibling: static data behind a
 * filter seam, whitelisted statuses, escaped at build, its own front
 * stylesheet, and the security contract (no option names, endpoint
 * paths, tool or change-type slugs, or meta keys ever reach the public
 * page). 'considering' is an idea, never a commitment, and the copy
 * should read that way; 'planned' names its gate in the sentence.
 * Edits flow through the `sn_maturity_roadmap_board` filter, a
 * deliberate owner edit here, or — since the board-as-data release —
 * the sn_apply write door's 'roadmap_board' change type, which stores
 * an owner-approved override in an option. The override, when present
 * AND valid, replaces the static board wholesale (option-canonical,
 * the /resume pattern); anything invalid falls back to the static
 * board silently — the public page never renders a broken override.
 * The static array below stays the versioned default and the
 * disaster-recovery floor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MATURITY_ROADMAP_STATUSES = array( 'done', 'planned', 'considering', 'later' );

// The board-as-data override option (written ONLY by sn_apply's
// 'roadmap_board' change type; never rendered, never echoed) and the
// override's structural bounds — generous editorial ceilings, not
// design targets.
const SN_MATURITY_ROADMAP_OPTION       = 'snt_maturity_roadmap_board';
const SN_MATURITY_ROADMAP_MAX_FAMILIES = 12;
const SN_MATURITY_ROADMAP_MAX_ITEMS    = 12;
const SN_MATURITY_ROADMAP_MAX_ITEM_LEN = 400;
const SN_MATURITY_ROADMAP_MAX_LABEL_LEN = 80;

/**
 * The 'done' column's own, TIGHTER ceiling (v10.76.0).
 *
 * Every other column churns — rows leave by graduating or by being dropped.
 * 'done' only grows, and since v10.63.0's "fold the future" it is the column
 * left OPEN while the future tenses fold, so it is also the one that governs
 * how tall the board reads.
 *
 * The generic 12-item ceiling is the wrong instrument for it, because the
 * failure at that ceiling is WHOLESALE: the roadmap write replaces the entire
 * board, so the first family to overflow fails gate 2 and blocks EVERY board
 * edit — including the one that would fix it. The same validator guards the
 * read path (sn_maturity_roadmap_override_board()), so an over-cap override
 * returns null and the public page silently reverts to the static floor.
 *
 * A tighter ceiling does not make that failure shape better; it makes the
 * board hit it while the board is still small enough to fix by hand, and it
 * lets the refusal name the fix. The actual prevention is the CI canary in
 * tests/maturity-roadmap-shortcode.php, which reds one row BEFORE the wall.
 *
 * Graduating a row off the hub is not deletion: the family maturity pages
 * already state what acts today, so a shipped row that leaves this board is
 * still on record one level down.
 */
const SN_MATURITY_ROADMAP_MAX_DONE = 5;

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
				__( 'AI attention in the weekly digest: which crawler families read the site, and how many of those reads landed on the published terms rather than the prose — from the ledger snapshot the site already holds, no new collection. The ledger\'s own thirty-day window is cited, never blended into the digest\'s week, and a window that measured nothing stays silent — a thread shared with Machine readability', 'signal-and-noise-tools' ),
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
				__( 'Which machines send a reader back: per operator, the ledger\'s crawl counts published beside that operator\'s referred human visits — stated as a sentence each, not a ratio, because crawls are requests and visits are visitor-days. An operator that read the site hundreds of times and sent nobody back is the finding, and a bare 0 would bury it — a thread shared with Machine readability', 'signal-and-noise-tools' ),
				__( 'Traffic rhythm flags: the cadence watch now reads views — a quiet week flags against the site\'s own trailing weeks, robust to a viral spike and one-sided by design — read from the rollups already kept, never profiling a reader', 'signal-and-noise-tools' ),
				__( 'Search-side metrics from Search Console: the queries, impressions, and positions before the click, in their own Search view and beside Top pages — one hand-rolled service-account client, a rolling window that ends three days back because Google is still counting, and since v13.9.0 a daily sync that keeps its own schedule instead of waiting for a button', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'The questions search asked that the corpus doesn\'t answer: queries with impressions and no clicks, derived from the Search Console rows already synced — read by the owner\'s eyes, never by a model — landing once the daily sync has accrued a full month to speak from', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Where readers leave to: outbound clicks on citations counted as their own aggregate event — the first idea in this family to require new collection, named as such, and it ships only if the counting stays as blunt as the rest of the ledger', 'signal-and-noise-tools' ),
				__( 'A year told as a page: the deterministic narrator pointed at twelve months instead of a week — what rose, what faded, what the corpus became — computed from the rollups already kept, no new collection', 'signal-and-noise-tools' ),
				__( 'A reading ledger that never phones home: which notes you have read, kept in your browser\'s own storage, shown on the index, sent nowhere — the site\'s privacy posture expressed as a feature only the reader can see', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Verified versus claimed: crawler request signatures checked at the edge and recorded in the ledger — so the attention story can separate cryptographic fact from a user-agent costume — a thread shared with Machine readability', 'signal-and-noise-tools' ),
			),
		),
		__( 'Proof of origin', 'signal-and-noise-tools' )     => array(
			'done'        => array(
				__( 'Notes carry a signed commit chain anchored to Bitcoin, and every accepted edit re-anchors the note as a new version', 'signal-and-noise-tools' ),
				__( 'Key history with a future: the signing key\'s verifiable history published at a well-known path, retired keys kept inside dated validity windows so anchors made under them still verify, and the next key committed by hash before it is ever used — so a rotation never orphans years of anchors', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.6.0, R5): graduated considering -> done. The
				// verifier had substantially existed in the ledger repo; what
				// shipped here is the missing half — the surface: the /verify
				// page now names its own residual trust and hands the reader
				// the clone-and-run path. Rewritten present-tense; the wording
				// honours §9.5 P-54 (says what the verifier IS).
				__( 'A standalone verifier anyone can run outside the site: the checks live as a small readable program in the public ledger repository — clone, run, trust only the code in front of you — and the site\'s own verify page points at it as the way out of trusting the page itself', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.6.1, R5): graduated considering -> done, same
				// day as its sibling — the verifier row's completion, not a
				// separate idea. Owner-selected anchor: public-CI build
				// attestations, with the honest limit INSIDE the claim (the
				// anchor is the code host); the self-contained site-key path
				// stays recorded in threat model §9.5 P-54 as the upgrade.
				__( 'Provenance for the software itself: releases of the verifier are built in public CI and published with a build attestation, so the tool a stranger downloads can prove which commit built it — the verifier argument applied one layer down, with its limit stated: the attestation\'s anchor is the code host, and reading the cloned code remains the trust floor — a thread shared with Operations', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend signing and anchoring beyond notes, to pages and then media', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'A second, independent anchor: a standards-based timestamp authority alongside Bitcoin, so the chain\'s integrity never rests on a single mechanism\'s longevity', 'signal-and-noise-tools' ),
				__( 'A witness that is a peer, not an institution: two independent sites countersign each other\'s chain heads — mutual custody with no authority between them, the thesis said out loud on live infrastructure', 'signal-and-noise-tools' ),
				__( 'A quote that carries its receipt: select a passage and get the quote with its attribution, its link, and — on a signed note — its anchor attached, with academic citation formats beside it and the landing highlight styled — citation made honest at the moment of copying', 'signal-and-noise-tools' ),
				__( 'What changed, shown to readers: every accepted edit already re-anchors a note as a new version — a rendered difference between anchored versions turns that chain into legible editorial history, corrections owned in public', 'signal-and-noise-tools' ),
				__( 'The manuscript beside the proof: each signed note\'s canonical text mirrored into the public ledger repository beside its hash — so the proof is self-contained and a verifier needs no live site to check what was said', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'A second witness that names the author: each note\'s chain head countersigned into a public transparency log — an independent record binding the proof to an identity, not just a moment', 'signal-and-noise-tools' ),
				__( 'Authorship as a credential: each note carrying a standard verifiable credential of authorship in its structured data — the claim stated in a shared vocabulary instead of a house dialect — a thread shared with Machine readability', 'signal-and-noise-tools' ),
				__( 'The chain outlives its curve: the anchors sign with Ed25519 today, and the key history already commits each next key by hash — so when post-quantum signature practice settles, the successor key can be post-quantum and the rotation is a planned crossing, not an emergency', 'signal-and-noise-tools' ),
				__( 'Continuity stated: a signed succession plan for keys and corpus — what a reader can still verify if the site ever goes silent, because a signed record outlives its subject', 'signal-and-noise-tools' ),
			),
		),
		__( 'AI', 'signal-and-noise-tools' )                  => array(
			'done'        => array(
				__( 'Two isolated agent doors — read-only and write — behind curated allowlists, kill switches, an audit trail, and rate limits', 'signal-and-noise-tools' ),
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
				__( 'Read the site\'s numbers from a phone: an analytics-only door, reached through a scoped token that expires rather than the site\'s own password, with the rate ceiling that guards it failing closed instead of open, a stop switch reachable from the phone it protects, and a record of what it served — writing stays on the desktop, and nothing that can name an unpublished draft goes through it', 'signal-and-noise-tools' ),
				// 2026-08-28 (v13.21.0): the spend row graduates considering →
				// done, skipping planned (the R4-ML precedent: shipped the
				// same arc the commitment was made). REWRITTEN HONESTLY, not
				// moved: the considering sentence promised "read from what the
				// platforms report, never estimated" — that clause describes
				// the Health spend WATCH (which stays platform-true), not what
				// shipped here. The itemization is the plugin's own priced
				// ledger, and the done claim says so rather than inheriting a
				// promise the shipped thing deliberately does not make.
				__( 'Spend with an address: the AI bill itemized by feature beside the monthly total — each call priced into a named bucket as it lands, so a rising bill names its cause before any statement arrives — the itemization is the site\'s own priced ledger, read beside, never inside, the platform-reported spend watch — a thread shared with Operations', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Move the operative AI channel to the desktop platform\'s native agents, once that runner is stable enough to trust with the same fences', 'signal-and-noise-tools' ),
				__( 'Retire the legacy single-purpose tools the consolidated set absorbed, on usage evidence rather than on a date', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Scheduled read-only agent runs for recurring reports', 'signal-and-noise-tools' ),
				__( 'Richer edit primitives beyond sentence scale — the drafting boundary stands regardless of what is explored here', 'signal-and-noise-tools' ),
				// 2026-08-28: "Spend with an address" left this cell for done
				// (v13.21.0) — see the graduation note there for the honest
				// rewrite of its "never estimated" clause.
			),
			'later'       => array(
				__( 'Injection self-sweep: every machine surface the site publishes — the crawler manifest, structured data, the board itself, the doors\' own descriptions — linted for instruction-shaped text before it ships, so a site that treats prose as data can prove its own prose is clean — a thread shared with Machine readability', 'signal-and-noise-tools' ),
				__( 'A registry-listed read door: the read-only door published in the standard agent registry under a name this domain verifiably owns — so agents discover it by lookup instead of by reading a page written for humans', 'signal-and-noise-tools' ),
				__( 'Cross-family threads made visible: rows that span families carry their partners as chips, so the board shows the weave, not just the columns', 'signal-and-noise-tools' ),
				__( 'Doors with versioned contracts: an agent pins the contract it integrated against, so a door upgrade never breaks a client silently — API discipline applied before the consumers arrive rather than after', 'signal-and-noise-tools' ),
			),
		),
		__( 'Machine learning', 'signal-and-noise-tools' )    => array(
			'done'        => array(
				__( 'A deterministic layer — related notes, topic clusters, cadence watch — computed from corpus statistics, with no model ever in the reader\'s browser', 'signal-and-noise-tools' ),
				__( 'Draft-time echoes: while writing, the most similar existing note surfaces, so overlap is a choice instead of a surprise — the same corpus statistics the related layer already computes, asked from the draft\'s side, and below the bar it stays quiet rather than offering the least-bad match', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.2.0 + v11.3.0): both R4 ML rows graduated in
				// one sweep — considering → done, skipping planned, because the
				// features shipped the same day the commitment was made. This
				// EMPTIES the ML considering cell: the board's zero-empty pin
				// moves to 1, honestly (see tests). Rewritten, not moved.
				__( 'Corpus drift as an editorial mirror: the vocabulary\'s year-to-year movement shown to the writer as which terms rose, fell, entered, or went silent — and a year too thin to speak says so instead of publishing a confident zero — computed from corpus statistics, shown to the writer, never to a model', 'signal-and-noise-tools' ),
				__( 'Reading paths from cluster geometry: each topic cluster carries a precomputed note-to-note chain, entered at its most central note, identical for every reader and recomputed only when the corpus changes — sequencing, not personalization', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Extend the deterministic layer pipeline by pipeline, as real editorial questions demand it', 'signal-and-noise-tools' ),
				__( 'Search served by the kernel: the notes search box ranked by the same tf-idf mathematics that picks related notes — deterministic corpus arithmetic instead of database substring order, and still no model in the reader\'s browser — landing after the lexical-spine decision, so search ships on the spine the corpus will keep', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'The words a note owns: each note\'s distinctive vocabulary against the whole corpus, surfaced to the writer from the tf-idf arithmetic the related layer already computes — a new question asked of existing mathematics, not a new pipeline', 'signal-and-noise-tools' ),
				__( 'The calendar of themes: which subjects belong to which season, read from the corpus\'s month-of-year rhythm — distinct from drift, which watches years, and cadence, which watches output — and a month too thin to speak says so', 'signal-and-noise-tools' ),
				__( 'The corpus index, published: the distinctive-vocabulary arithmetic rendered as a browsable index where each term links to the note that owns it — the site becoming its own reference work', 'signal-and-noise-tools' ),
				__( 'A better lexical spine: the similarity core under related notes upgraded to the ranking statistics that stop long notes from hogging every match — the same deterministic corpus mathematics, corrected for length', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'The shape of a sentence, watched: a deterministic readability fingerprint per note, trended across the corpus and surfaced at draft time — so prose complexity drift becomes visible the way topic drift already is', 'signal-and-noise-tools' ),
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
				__( 'The rights-read count published on the machine-readability page itself, served from an hourly snapshot the site already holds — no sensor call on a reader\'s path, and a count never measured renders as unmeasured, never as zero — a thread shared with Analytics', 'signal-and-noise-tools' ),
				// 2026-08-14 (v11.7.0, R5): both quartet MR rows graduated
				// considering -> done in one release — they publish the same
				// pointer set to the same invited anonymous caller (threat
				// model A6) and shipped as one module. Built against §9.5:
				// the manifest asserts nothing, adds no anonymous compute,
				// and every URL comes from the one endpoint producer the
				// /verify page itself consumes.
				__( 'Provenance pointers in the machine surfaces: a signed note\'s structured data carries its ledger identifier, joined to the record an agent can fetch — so a machine that reads the site can also verify it — a thread shared with Proof of origin', 'signal-and-noise-tools' ),
				__( 'An in-page tool surface for verification: every signed page ships a data-shaped manifest of the exact calls — credential, record, proof, key history, block header — so verifying travels with the content; the page lists inputs and asserts nothing, because the verdict belongs to the caller — a thread shared with Proof of origin', 'signal-and-noise-tools' ),
				__( 'Google\'s account cross-examined by the edge\'s: what Search Console says it showed set against what the crawler ledger says actually fetched — deliberately at the family level the ledger really keeps, not a per-page join it cannot support, so a disagreement between the two instruments is the finding and which way it falls names the problem — a thread shared with Analytics', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'Speak the coming standard: publish the usage-preference header and robots rule the day the internet standards body finalizes them, with a parity sweep proving every rights dialect the site speaks states the same reservation — one policy, never a family of drifting translations', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Provenance pointers in the machine surfaces, so an agent that reads the site can also verify it — a thread shared with Proof of origin', 'signal-and-noise-tools' ),
				__( 'An in-page tool surface for verification: the page offers an agent the calls to check a signature and its anchor, so verifying travels with the content instead of waiting for anyone to adopt an API — a thread shared with Proof of origin', 'signal-and-noise-tools' ),
				__( 'The corpus schema published as a machine surface: tier, number, and relation stated by the author rather than inferred by whatever reads the page', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Markdown at the agent door: agent-negotiated markdown served with the site\'s rights headers attached to every converted response — the declaration travels with the token-cheap copy, not just the page', 'signal-and-noise-tools' ),
				__( 'Homework shown: when the European list of machine-readable opt-out protocols is published, a page mapping this site\'s declarations to every protocol on it — conformance demonstrated, not claimed', 'signal-and-noise-tools' ),
				__( 'The corpus as a dataset, under its own terms: a versioned bulk export with the rights declarations stamped inside the artifact — an agent fetches once instead of crawling five hundred times, and the terms travel with the copy', 'signal-and-noise-tools' ),
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
				__( 'Charts that speak: the stats page\'s chart ships with its voice built in — a deterministic one-paragraph summary and a calendar-shaped table twin a screen reader navigates with week and weekday context, the picture itself only decoration — a thread shared with Analytics', 'signal-and-noise-tools' ),
				__( 'Contrast verified from COMPUTED styles: a browser-run instrument walks every rendered text node on the live pages in both palettes — real nesting, inline overrides, the opacity chain composited over the true ground — the half a file cannot answer; its first real catch was this board\'s own legend fades, lifted to the measured AA floor, and the closing run reads zero pairs below AA', 'signal-and-noise-tools' ),
				__( 'Motion that asks first: every animation and vestibular transition inventoried with its guard by a report-first scan — opt-in media gates, reduce resets, and soften counterparts all recognized, JS motion classified with each claim verified by grep — and the lazy transition-of-everything declarations it surfaced narrowed to the properties their hovers actually change', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				__( 'The keyboard walked, not assumed: a browser-run instrument tabs through the live pages recording focus order and focus visibility — the computed-contrast discipline applied to keyboard access — landing as the next instrument arc, on the runner the contrast walk already proved', 'signal-and-noise-tools' ),
				__( 'Targets measured, not eyeballed: the 24-pixel floor of WCAG 2.2 checked from rendered geometry — reading the page the finger actually meets — riding the keyboard walk\'s instrument arc, since both read the same rendered page', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				// 2026-08-12: the facade was BUILT (theme v11.8.0), tried live,
				// and reverted the same day by owner choice (v11.8.1). The
				// decline and its reopening condition ride inside the sentence,
				// the read-door pattern: the board records the shape the answer
				// took, not merely that one exists.
				__( 'An accessible facade for third-party embeds: built, shipped, and DECLINED in practice — the music page\'s hero returned to an eager player by owner choice after a day live, trading no-fetch-before-consent for immediacy; the discography grid keeps click-to-play. Reopens only if an embed ever lands in a note body, where the consent argument outweighs a catalog page\'s.', 'signal-and-noise-tools' ),
				__( 'Headings and link text as findings: skipped heading levels, click-here anchors, and bare-URL link text raised for a person to accept or reject — the alt-quality discipline extended to structure and wording', 'signal-and-noise-tools' ),
				__( 'Contrast on both scales: every instrument run records APCA beside AA — so when the standard turns, the site arrives with a history of the new number instead of a scramble', 'signal-and-noise-tools' ),
				__( 'Accessibility at the moment of publishing: the pre-publish panel\'s advisory warnings extended to missing alt text and skipped heading levels — so the site-wide sweeps stop being the first line of defense', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'Conformance said out loud: a public accessibility self-assessment on the hub, fed by the scans and honest about what fails — the site\'s accessibility posture stated the way its rights posture already is', 'signal-and-noise-tools' ),
			),
		),
		__( 'Operations', 'signal-and-noise-tools' )          => array(
			'done'        => array(
				// v13.18.0: the one-dashboard row graduated onto
				// /maturity/ops-maturity/ as the ninth principle. Only half of it was
				// new there -- the "unknown" discipline was already that page's third
				// principle -- so the graduated sentence carries the half stated
				// nowhere: that these signals answer from ONE surface, not several.
				__( 'Defense numbers: the login door\'s own gauges, owner-only — fail-opens and degraded reads counted with zeros stated out loud, and the unchecked address share measured against a threshold written down before the query, so the number triggers the decision instead of reopening the argument', 'signal-and-noise-tools' ),
				__( 'Spend watched like uptime: build minutes and AI spend on the health widget, owner-only — every number read from what the platforms actually report, "unknown" when a read fails, and never an estimated or invented figure', 'signal-and-noise-tools' ),
				// 2026-08-28 GRADUATION: the morning-brief row retired to make
				// room for the dependency-provenance row below (the done
				// canary reds at MAX_DONE). Its narration half already stands
				// as the ops page's Narrate layer; its OTHER half — the mail
				// path carries no AI and no content prose, so nothing injected
				// can become instructions — was stated nowhere, and now stands
				// as that page's TENTH principle (sn_ops_maturity_principles).
				// The v13.18.0 pattern: graduate the half stated nowhere.
				__( 'Configuration drift watched: the effective settings snapshotted against an acknowledged baseline that moves only on acknowledgment or a version change — a changed switch is a visible event, not a mystery', 'signal-and-noise-tools' ),
				// 2026-08-28: planned → done, both legs shipped. Rewritten as
				// facts: the audit gate clause ("landing after a one-time
				// audit…") became true 2026-08-14 and is now history, not a
				// precondition; the cooldown's reviewed-exception discipline
				// joins the claim because it is what ships.
				__( 'Dependency provenance gate: a worker ships only after its locked dependency tree verifies against the registry\'s provenance attestations and every version has waited out a minimum-age cooldown — a freshly poisoned upstream release sits in its detection window instead of going straight to the edge, and a deliberate young bump is a reviewed, per-version exception rather than a silent pass', 'signal-and-noise-tools' ),
			),
			'planned'     => array(
				// 2026-08-28: promoted considering → planned by the owner when
				// the dependency-provenance graduation emptied this cell — the
				// no-empty-cell rule forces the conversation, and this was the
				// commitment chosen. The gate is in the sentence, per the rule.
				__( 'The calendar of quiet failures: service keys, scoped tokens, domains, and certificates watched with their dates stated before they lapse — expiry as a scheduled event instead of an outage — landing when the first credential whose renewal no platform manages for us enters the estate', 'signal-and-noise-tools' ),
			),
			'considering' => array(
				__( 'Restore proof, not backup existence: a periodic check that a backup actually restores, closing the gap between having backups and having recovery', 'signal-and-noise-tools' ),
				__( 'The corpus in a pocket: a service worker caching the notes for offline reading — fifty-odd notes is nothing, and the site that can leave its platform should also survive a tunnel', 'signal-and-noise-tools' ),
				__( 'A passkey at the owner\'s door: sign-in by passkey with the password demoted to fallback — the most-attacked surface on the site should not be the last to walk away from passwords', 'signal-and-noise-tools' ),
				__( 'Journey checks, not pings: a scheduled pass that walks the flows that matter — the stats page rendering its numbers, the rights surfaces serving their terms — because a healthy status code is not a working site', 'signal-and-noise-tools' ),
			),
			'later'       => array(
				__( 'The site that can leave: a periodic static export of the whole corpus with provenance intact — proof the content survives the platform it was written on — the standalone-verifier argument applied to the site itself — a thread shared with Proof of origin', 'signal-and-noise-tools' ),
			),
		),
	);

	return $board;
}

/**
 * Tokens that must never appear in board copy — the write-gate mirror of
 * the public page's leak sweep (tests/maturity-roadmap-shortcode.php's
 * SECURITY CONTRACT block): option names, endpoint paths, tool and
 * change-type slugs, internal prefixes. Rejecting them at the WRITE gate
 * keeps the sweep green by construction instead of by luck.
 *
 * @return string[]
 */
function sn_maturity_roadmap_banned_tokens() {
	return array( 'sn_mcp', 'snt_', '_sn_', 'wp-json', 'sn_apply', 'sn-apply', 'sentence_replace', 'restore_revision', 'roadmap_board', 'openstation', 'desktop_mode', 'MCP' );
}

/**
 * Validate a candidate board's structure and content. Returns a flat list
 * of human-readable problems — empty means valid. Shared by the read side
 * (an override that fails here is IGNORED, never partially rendered) and
 * sn_apply's 'roadmap_board' gate 2 (where each problem becomes an
 * error-severity finding that blocks the write).
 *
 * @param mixed $board Candidate board (family label → status → sentences).
 * @return string[] Problems; empty when the board is valid.
 */
function sn_maturity_roadmap_board_problems( $board ) {
	$problems = array();
	if ( ! is_array( $board ) || array() === $board ) {
		return array( 'board must be a non-empty object of family label → { done/planned/considering/later: sentence[] }.' );
	}
	if ( count( $board ) > SN_MATURITY_ROADMAP_MAX_FAMILIES ) {
		$problems[] = sprintf( 'board has %d families; the maximum is %d.', count( $board ), SN_MATURITY_ROADMAP_MAX_FAMILIES );
	}
	foreach ( $board as $family => $columns ) {
		$label = is_string( $family ) ? trim( $family ) : '';
		if ( '' === $label || strlen( $label ) > SN_MATURITY_ROADMAP_MAX_LABEL_LEN ) {
			$problems[] = sprintf( 'family label "%s" must be a non-empty string of at most %d characters.', (string) $family, SN_MATURITY_ROADMAP_MAX_LABEL_LEN );
		}
		foreach ( sn_maturity_roadmap_banned_tokens() as $token ) {
			if ( is_string( $family ) && false !== strpos( $family, $token ) ) {
				$problems[] = sprintf( 'family label "%s" contains a banned internal token.', $family );
				break;
			}
		}
		if ( ! is_array( $columns ) ) {
			$problems[] = sprintf( 'family "%s" must map to an object of status → sentence[].', $label );
			continue;
		}
		foreach ( $columns as $status => $items ) {
			if ( ! in_array( (string) $status, SN_MATURITY_ROADMAP_STATUSES, true ) ) {
				$problems[] = sprintf( 'family "%s" carries unknown status "%s" (allowed: %s).', $label, (string) $status, implode( ', ', SN_MATURITY_ROADMAP_STATUSES ) );
				continue;
			}
			if ( ! is_array( $items ) ) {
				$problems[] = sprintf( 'family "%s" status "%s" must be an array of sentences.', $label, (string) $status );
				continue;
			}
			if ( count( $items ) > SN_MATURITY_ROADMAP_MAX_ITEMS ) {
				$problems[] = sprintf( 'family "%s" status "%s" has %d items; the maximum is %d.', $label, (string) $status, count( $items ), SN_MATURITY_ROADMAP_MAX_ITEMS );
			}
			// The done column's tighter ceiling. The message names the fix,
			// per the door's standing rule that a refusal must say what to
			// do about it — here that is graduating the oldest shipped row
			// onto its family maturity page, which already states what acts
			// today, rather than deleting anything.
			if ( 'done' === (string) $status && count( $items ) > SN_MATURITY_ROADMAP_MAX_DONE ) {
				$problems[] = sprintf(
					'family "%s" has %d done rows; the maximum is %d. Graduate the oldest shipped row onto the %s maturity page and drop it from this board — the hub board is the planning surface, not the ledger.',
					$label,
					count( $items ),
					SN_MATURITY_ROADMAP_MAX_DONE,
					$label
				);
			}
			foreach ( $items as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) || strlen( $item ) > SN_MATURITY_ROADMAP_MAX_ITEM_LEN ) {
					$problems[] = sprintf( 'family "%s" status "%s" carries an item that is not a non-empty string of at most %d characters.', $label, (string) $status, SN_MATURITY_ROADMAP_MAX_ITEM_LEN );
					continue;
				}
				if ( false !== strpos( $item, '<' ) ) {
					$problems[] = sprintf( 'family "%s" status "%s" carries an item containing markup — board copy is plain prose only.', $label, (string) $status );
					continue;
				}
				foreach ( sn_maturity_roadmap_banned_tokens() as $token ) {
					if ( false !== strpos( $item, $token ) ) {
						$problems[] = sprintf( 'family "%s" status "%s" carries an item containing a banned internal token.', $label, (string) $status );
						break;
					}
				}
			}
		}
	}
	return $problems;
}

/**
 * The stored override, or null when absent/invalid. Absent and invalid
 * collapse deliberately: the public page's contract is "never render a
 * broken board", so an override that fails validation is IGNORED wholesale
 * rather than partially applied — the fallback is the static board, which
 * is always renderable.
 *
 * @return array<string,array<string,string[]>>|null
 */
function sn_maturity_roadmap_override_board() {
	$stored = get_option( SN_MATURITY_ROADMAP_OPTION, null );
	if ( ! is_array( $stored ) || array() !== sn_maturity_roadmap_board_problems( $stored ) ) {
		return null;
	}
	return $stored;
}

/**
 * The EFFECTIVE board (override-if-valid, else static) — pre-filter. This
 * is what sn_apply's 'roadmap_board' fingerprint binds to: it must hash
 * exactly the state a subsequent write would replace, so the filter (which
 * may be dynamic) deliberately stays outside it.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_effective_board() {
	$report = sn_maturity_roadmap_effective_report();
	return $report['merged'];
}

/**
 * The merge report against the CURRENT static board — what the Health check
 * and sn_apply's dry run consume.
 *
 * @return array{merged:array,conflicts:array,code_landed:array,override_held:array}
 */
function sn_maturity_roadmap_effective_report() {
	$static = sn_maturity_roadmap_static_board();
	$report = snt_roadmap_merge_report( $static );
	// A merged board that fails validation is not served: fall back to code,
	// the same posture the old override_board() had for an invalid option.
	// `invalid => true` is what stops that fallback from being silent — see
	// the Health check in inc/health-check-roadmap-drift.php, which reads it.
	if ( array() !== sn_maturity_roadmap_board_problems( $report['merged'] ) ) {
		return array( 'merged' => $static, 'conflicts' => array(), 'code_landed' => array(), 'override_held' => array(), 'invalid' => true );
	}
	return $report;
}

/**
 * The optimistic-concurrency fingerprint of a board state — sn_apply's
 * 'roadmap_board' change type refuses a write whose fingerprint does not
 * match the CURRENT effective board's (the stale-branch merge conflict,
 * exactly sentence_replace's content_hash binding for posts).
 *
 * @param array $board
 * @return string
 */
function sn_maturity_roadmap_board_fingerprint( $board ) {
	return md5( (string) wp_json_encode( $board ) );
}

/**
 * The rendered board: effective (override-aware) + the filter seam.
 * Signature and filter contract unchanged from every prior release —
 * existing consumers and the filter's test fixtures are untouched.
 *
 * @return array<string,array<string,string[]>>
 */
function sn_maturity_roadmap_board() {
	/**
	 * Filter the roadmap board. Family label → status → sentences;
	 * unknown statuses are dropped at render, everything is escaped at
	 * the point of build.
	 *
	 * @param array<string,array<string,string[]>> $board
	 */
	return apply_filters( 'sn_maturity_roadmap_board', sn_maturity_roadmap_effective_board() );
}

/**
 * Per-status item totals across the whole board — the legend's and the
 * header badges' numbers. Computed from the same filtered board the
 * render walks, so a filtered/override board always counts itself.
 *
 * @param array<string,array<string,string[]>> $board
 * @return array<string,int>
 */
function sn_maturity_roadmap_counts( $board ) {
	$counts = array_fill_keys( SN_MATURITY_ROADMAP_STATUSES, 0 );
	foreach ( $board as $columns ) {
		foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
			if ( isset( $columns[ $status ] ) && is_array( $columns[ $status ] ) ) {
				$counts[ $status ] += count( $columns[ $status ] );
			}
		}
	}
	return $counts;
}

/**
 * Render the board. Escaped HTML; statuses outside the whitelist never
 * render; an empty cell renders an em-dash, never collapses.
 *
 * v10.63.0 — "fold the future": done cells stay open (the record IS the
 * page's argument); planned and considering fold into native <details>
 * per cell, closed by default, each summary carrying its count — the
 * board's commitment gradient (solid → dashed → dotted) made physical.
 * A count-trio legend above the table gives the site-wide column scan
 * with zero folds opened. No script: keyboard, screen readers, and
 * find-in-page (which auto-opens matching folds) are the browser's own.
 *
 * @return string
 */
function sn_maturity_roadmap_html() {
	$headings = array(
		'done'        => __( 'Done', 'signal-and-noise-tools' ),
		'planned'     => __( 'Planned', 'signal-and-noise-tools' ),
		'considering' => __( 'Considering', 'signal-and-noise-tools' ),
		'later'       => __( 'Later', 'signal-and-noise-tools' ),
	);
	$summaries = array(
		/* translators: %d: number of planned roadmap items inside the fold. */
		'planned'     => __( '%d planned', 'signal-and-noise-tools' ),
		/* translators: %d: number of considering roadmap items inside the fold. */
		'considering' => __( '%d considering', 'signal-and-noise-tools' ),
		/* translators: %d: number of later roadmap items inside the fold. */
		'later'       => __( '%d later', 'signal-and-noise-tools' ),
	);
	$sublines  = array(
		'done'        => __( 'shipped & verifiable', 'signal-and-noise-tools' ),
		'planned'     => __( 'each names its gate', 'signal-and-noise-tools' ),
		'considering' => __( 'commitments to nothing', 'signal-and-noise-tools' ),
		'later'       => __( 'vetted, not yet weighed', 'signal-and-noise-tools' ),
	);

	$board  = sn_maturity_roadmap_board();
	$counts = sn_maturity_roadmap_counts( $board );

	// H2: the shortcode sits directly under the page's H1 post title (#1040).
	$out = '<h2>' . esc_html__( 'Roadmap', 'signal-and-noise-tools' ) . '</h2>';

	// The legend: the commitment gradient as a count trio, each cell an
	// anchor to the board so the legend is navigation, not decoration.
	$out .= '<nav class="sn-maturity-roadmap-legend" aria-label="' . esc_attr__( 'Roadmap totals by status', 'signal-and-noise-tools' ) . '">';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$out .= '<a class="sn-maturity-roadmap-legend__cell sn-maturity-roadmap-legend__cell--' . esc_attr( $status ) . '" href="#sn-maturity-roadmap-board">'
			. '<span class="sn-maturity-roadmap-legend__stat">' . esc_html( (string) $counts[ $status ] ) . '</span>'
			. '<span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] ) . '</span>'
			. '<span class="sn-maturity-roadmap-legend__sub">' . esc_html( $sublines[ $status ] ) . '</span>'
			. '</a>';
	}
	$out .= '</nav>';

	$out .= '<table class="sn-maturity-roadmap-board" id="sn-maturity-roadmap-board"><thead><tr>'
		. '<th class="sn-maturity-roadmap-board__family-h">' . esc_html__( 'Family', 'signal-and-noise-tools' ) . '</th>';
	foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
		$out .= '<th><span class="sn-maturity-roadmap-badge sn-maturity-roadmap-badge--' . esc_attr( $status ) . '">' . esc_html( $headings[ $status ] )
			. '<span class="sn-maturity-roadmap-badge__n">' . esc_html( (string) $counts[ $status ] ) . '</span></span></th>';
	}
	$out .= '</tr></thead><tbody>';

	foreach ( $board as $family => $columns ) {
		$out .= '<tr><td class="sn-maturity-roadmap-board__family" data-label="' . esc_attr__( 'Family', 'signal-and-noise-tools' ) . '">' . esc_html( (string) $family ) . '</td>';
		foreach ( SN_MATURITY_ROADMAP_STATUSES as $status ) {
			$rows = isset( $columns[ $status ] ) && is_array( $columns[ $status ] ) ? $columns[ $status ] : array();
			$out .= '<td class="sn-maturity-roadmap-board__cell sn-maturity-roadmap-board__cell--' . esc_attr( $status ) . '" data-label="' . esc_attr( $headings[ $status ] ) . '">';
			if ( empty( $rows ) ) {
				$out .= '<span class="sn-maturity-roadmap-board__empty" aria-label="' . esc_attr__( 'nothing here', 'signal-and-noise-tools' ) . '">&mdash;</span>';
			} else {
				$list = '<ul>';
				foreach ( $rows as $row ) {
					$list .= '<li>' . esc_html( (string) $row ) . '</li>';
				}
				$list .= '</ul>';
				if ( isset( $summaries[ $status ] ) ) {
					// The future tenses fold; done never does.
					$out .= '<details class="sn-maturity-roadmap-fold"><summary>'
						. '<span class="sn-maturity-roadmap-fold__glyph" aria-hidden="true"></span>'
						. esc_html( sprintf( $summaries[ $status ], count( $rows ) ) )
						. '</summary>' . $list . '</details>';
				} else {
					$out .= $list;
				}
			}
			$out .= '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>';
}

/** Enqueue the front stylesheet; shortcode-render time only. */
function sn_maturity_roadmap_enqueue() {
	wp_enqueue_style(
		'sn-maturity-roadmap-front',
		plugins_url( 'assets/maturity-roadmap-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}

/**
 * [sn_maturity_roadmap] — returns (never echoes), static content only,
 * safe for any public maturity page. The wrapper rides `alignfull`
 * (the constrained layout's own exemption) and the stylesheet caps it
 * at the site's 1320px frame, so the board earns its width WITH the
 * theme's layout system instead of against it.
 *
 * @param array|string $atts Shortcode attributes (unused; reserved).
 * @return string
 */
function sn_maturity_roadmap_shortcode( $atts = array() ) {
	sn_maturity_roadmap_enqueue();
	// `alignfull` is the theme's own escape hatch from the constrained
	// layout: `.is-layout-constrained` clamps every non-align child to the
	// content width WITH forced auto margins, so a margin-calc breakout
	// silently loses (measured live: the board rendered 760px). Speaking
	// the layout system's dialect wins; the stylesheet then caps at 1320.
	return '<div class="sn-maturity-roadmap sn-maturity-roadmap--wide alignfull">' . sn_maturity_roadmap_html() . '</div>';
}
add_shortcode( 'sn_maturity_roadmap', 'sn_maturity_roadmap_shortcode' );
