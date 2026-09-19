# AI in Signal & Noise

What every model does in this ecosystem, what none of them is allowed to do, and what the site looks like to a model reading it. The plugin is [signal-and-noise-tools](https://github.com/juanlentino/signal-and-noise-tools); the theme, the workers and the connector are linked where they appear. Numbers here are the ones the tests pin; when this file and a test disagree, the test is right.

## The rule

A model can read, relate or judge, and it can suggest. A human clicks before anything a model said reaches a published note. Notes are never edited after publication, so the whole apparatus sits before the publish button, not after it. No model call is made on a reader's page load: every reading below is computed on a schedule or on a click in wp-admin, stored, and painted from the store.

## Three kinds of model, one job each

Three kinds of model run in the ecosystem, and each has exactly one job. The rule that holds them together: a model can read, relate or judge, and it can suggest; a human clicks before anything a model said reaches a published note. Notes are never edited after publication, so the whole apparatus sits before the publish button, not after it.

### A text model suggests

Anthropic's Claude through WordPress's AI Client (Sonnet 5 pinned as the default with a same-model safety net, so an unresolvable id never falls through to the provider's most expensive default). Every use is an opt-in suggest-and-apply surface in the editor: alt text (whole-library and inline), meta description, excerpt, OG card title, brand-voice alignment, tag descriptions in the house register from owner-approved seed sentences, internal-link and note-pair suggestions from the health scan's own nominations, drift phrases, orphan rescue. (Tag suggestion left this list in 16.9.0: Jev's tag fit reads every note against every tag and needs no generation.) Over the analytics it writes the Insights advisor (five structured recommendations) and the weekly narration (what happened, as prose), both from the same first-party rollups. Inside OpenStation it is the Copilot behind the shell's own AI switch, with two read-only tools of ours registered for it. Every feature is itemised on a monthly budget page with a per-feature line, and a cache probe (`ai-cache-probe-status`) says whether the provider's prompt cache is doing its job.

### An embedding model relates

Cloudflare Workers AI (`@cf/baai/bge-base-en-v1.5`) turns each note into a vector, cached on the content hash plus the model id so a model swap can never silently inflate a score. The `ml-*` kernel reads those vectors for the related-notes manifest the theme renders, cousins (notes closer than they should be, a health check), semantic drift between a note's title and its body, publishing cadence, keyword candidates, link isolation and paths through the corpus, reader anomalies (a note read in a way its neighbours are not), and draft echoes (a draft whose nearest published note is too near). Nothing here generates a word.

### Jev judges

TypeSafe's System One model (Jev) answers typed questions with a probability, a position on a rubric, or a choice, plus a confidence; it produces no prose, so it cannot be asked to write and cannot drift into the notes' voice. It reaches the site through [Connector for TypeSafe Jev](https://github.com/juanlentino/jev-connector), which registers `typesafe` with Core's Connectors API (Settings › Connectors owns the key; this plugin holds none). Six readings, each one request per note and each a list for a human:

- **Check 30, daily.** Is the search title the words a person would type, and does the description say what the note argues? A position below level one ("names the subject") on a 0..2 rubric is a finding whatever the confidence; under 0.5 confidence the finding says "Jev is unsure; read it yourself". The pass is `jev-pass-now`, the reading `jev-notes`.
- **The collision gate, on every save of a draft.** One Noul per published note: does this draft make the same central argument, so a reader of that note would learn nothing new? Stored on the post; the pre-publish panel warns per note at or above 0.6 and names the irreversibility. The lane map (`jev-lane-map`, `jev-lanes`) runs the same question across the whole published corpus and keeps the pairs at or above 0.5.
- **Query-to-page fit, weekly.** For the queries Search Console already sends to each note, one Score per query: does the note answer it (2), touch it (1), or did the query land on shared vocabulary (0)? Two lists on Analytics › Search: gaps (seen with impressions, not answered; the next note) and stray traffic (clicked, answered at zero; a title chasing the wrong search). `jev-fit-now`, `jev-query-fit`.
- **The anti-tell pass, on every draft save.** The playbook's banned constructions: em dashes, "quietly", "not just X but Y", hedge clusters and three same-length sentences are counted by regex with no model; a three-part list built for rhythm, repeated openings, the "It is not X. It is Y." pair and a closer that restates the thesis are Nouls per paragraph, one request per note, warned at 0.6 in the pre-publish panel. `jev-tells-check`, `jev-tells-pass` (the corpus, a reading only), `jev-tells`.
- **Tag fit, weekly.** Tags are the one field a published note can still change (they are not prose; the signature covers none of it). One request per note: a Score per attached tag (does the note argue what the tag names, or was it attached for reach) and a Noul per tag it does not carry (would a reader browsing it expect this note), the tag descriptions as the state. Content › Tags lists the misfits and the missing with a remove box and an add box; the advisory count rides the health scan; a wrong reading of a right tag is the description to fix. `jev-tags-now`, `jev-tags`.
- **The connector's own modules,** off by default: a comment guardrail (two questions must agree, never worse than spam, an outage changes nothing) and term suggestions from existing terms only.

Every request is metered (16.6.0): per feature per credit cycle, priced from reported tokens, on AI › Models & Budget beside the Claude itemization and through `jev-meter`. At the site's scale this costs cents: a full pass over the notes is about 80k input tokens, the lane map about 500k, the fit pass under 10k, at $0.042 per million. The rubric numbers above were each set by reading a live pass and moving a line, and are pinned in `tests/typesafe-jev.php`, `tests/jev-collision.php`, `tests/jev-query-fit.php`.

## AI as reader

The site is also something models fetch, and the plugin treats that as a first-class surface. The machine-readers ledger (a sensor Worker, `Measurement › Machine readers`) records which crawler families fetched what, with the rights reads counted separately: reads of `/.well-known/tdmrep.json`, `/license.xml` (RSL), the `Content-Signal` line in `robots.txt` (`ai-input=yes`, `ai-train=no`) and the WebMCP bridge, each health-checked daily against what was shipped. Markdown for Agents negotiates a Markdown rendering of every note at the edge; the agent discovery set (A2A card, ARD, `auth.md`, skills) says what the site is and how to call it; every call through the doors below lands in the tool-invocation log. The provenance track underneath (Ed25519 signatures, Bitcoin anchors, Zenodo DOIs) is what lets a reader, human or model, verify a note without trusting the site.

## Where the keys live

| Provider | Holder | Screen |
|---|---|---|
| Anthropic (text) | WordPress AI Client | Settings › Connectors (Core's `ai_provider` card) |
| Cloudflare Workers AI (embeddings) | this plugin's keyring | S&N › Connections › Credentials (`workers_ai_token`) |
| TypeSafe (Jev) | Connector for TypeSafe Jev | Settings › Connectors (`typesafe`, type `ai_decision`) |

The connector deliberately does not register as an `ai_provider`: Core validates those keys against the generative AI Client on save and clears what it cannot verify, and Jev is not generative. Its `CLAUDE.md` records that decision and the others that look like bugs until you know why they are there.

## What is deliberately not built

- No model rewrites a note. Suggestions land in a field the author sees; the click is the author's.
- No model call on the front end, in a cron a reader could trigger, or in a comment form (the guardrail is the connector's module, off by default, and its worst verdict is spam, never trash).
- No second key path. Each provider's key has one holder; the plugin's own TypeSafe row was removed in 16.5.2 the day the connector existed.
- No third-party agent skills in the repos (ADR-0001). The TypeSafe API as a service is fine; a skill file that tells an agent how to work here is not.
- No "which note answers this query best" pass yet. Jev does not count, and a cross-note question is a second pass held until the first has a month of readings.

## Reading the numbers

Every threshold in the Jev readings was set by reading a live pass and moving a line, and each move is a fix release with the pass's figures in the changelog: the 0..2 scale read off a raw answer (16.3.2), the 0.9 confidence floor dropped because it hid 68 of 69 readings (16.3.4), the collision warning raised to 0.6 after 19 pairs at 0.5 read as vocabulary (16.4.1), the fit floor lowered to 5 impressions on a 469-impression month (16.5.1). Expect the next line to move the same way.
