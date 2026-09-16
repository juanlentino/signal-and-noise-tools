# Session — 2026-09-15: the key that was not a key

Eleven cuts in one day, 15.0.0 to 15.3.3, and every one of them was the same
question wearing a different coat: *which key is this, and who holds the other
half?* The day started with a Cloudflare grant hunt and ended with a ledger of
sixteen credentials that names, for each, what it is and what it is not. This is
the record of how the second became necessary.

## Three fixes chased a grant that never existed

The Cloudflare monitor's firewall reading had been refused since 14.9.0 with
`zone '…' does not have access to the path`. I read it as a permission and named
a grant: Firewall Services Read (14.9.1), then Logs Read (14.9.2), then "Account
Analytics Read as a candidate" (15.0.0). The owner added each one. Each time the
refusal read the same.

What settled it was an overshoot the owner ran on purpose: a token carrying every
zone read grant Cloudflare offers, forty-four of them. Still refused. At that
point no grant could be the answer, and the sentence had said so from the start:
its subject is the **zone**, not the token. Cloudflare's own docs put it plainly
once I went and read them instead of guessing: the grouped firewall dataset is not
on Free zones, and the raw `firewallEventsAdaptive` is open to every plan. 15.0.1
reads the raw one and groups it here, weighing each row by its `sampleInterval`
because the raw dataset is adaptively sampled and a row stands for that many
events under load.

Two lessons, both already in memory under other names and both paid for again. A
refusal has a subject, and the subject names the layer that refused. And a
documentation page beats three guesses, even when each guess sounded like the
last thing left.

## The tag that was typed before the cut

15.0.0 shipped under the tag `v14.10.0`. The chain script had the tag name
hardcoded; `tools/cut-release.sh release` did what the versioning rule says and
rolled 14.9 to 15.0 because Y would have reached 10. Header and changelog said
15.0.0, the public tag and release said 14.10.0, on the same commit. The owner
deleted the release by hand and the chain now reads the version from the header
the cut wrote and refuses to tag unless the two agree. A version is an output of
the cut, never an input to it.

## The event log, and a witness from the other side

Once the raw dataset answered, the plugin was holding Cloudflare's whole security
event log: per event, the action, the rule, the path, the country. That is the
one thing the origin can never see, what the edge stopped before WordPress ran.
15.1.0 reads one page a day and puts it to two uses. The Cloudflare leaf shows
the top paths and countries acted on. And the abilities WAF check, which since
14.0.4 could only pass through a Better Stack monitor sending a header from
outside, now reads the log first: a custom-rule block on an abilities path in the
last day is the rule firing, measured from Cloudflare's side. An empty log proves
nothing and the outside witness decides as before.

The same release rearranged the Cloudflare leaf by the question a reader brings
(Credentials and the token's health left; Cache, Edge, Firewall right, one Refresh
footer) and folded the fifth purge door into the one chain the other four already
ran. The Cloudflare-only purge had been emptying the edge while Varnish still held
the stale copy the edge then refilled from.

## The tile that said "unreachable" for two different reasons

Then the Machine Readers tile went dark. Its first reading was `http_502`: the
sensor accepted the plugin's token and Cloudflare refused the sensor's Analytics
Engine query, because the token behind the worker's `SN_MR_SQL_TOKEN` had lost
Account Analytics Read in the day's token editing. An hour later, `http_401`: the
sensor refused the plugin's token before asking Cloudflare anything. The worker
had not been deployed since the 8th. What had changed was on this side, and my
best reading is that JLSignal, "the ONE Cloudflare token", had been pasted into
the Machine Readers read-token field, because nothing on that tab said the field
was a different kind of key.

The tile had the code all along and flattened every code but one into "Sensor
unreachable". 15.1.1 gave it one map: 502 names the grant and the secret, 401
says the two sides differ, and the tab's pill reads the same sentence.

## Two kinds of key

That is the fact the whole day turned on, and it deserves its own paragraph
because it was never written down anywhere the owner could read it while editing
a field.

A **shared** secret is a password this site and a worker agree on: the sensor's
read token, the analytics server token, the login-guard bridge token, the
provenance HMAC. Both sides must carry the same bytes, and they can all be the
same bytes: one site secret, per row, when the owner says so. An **issued** token
is one another party mints: Cloudflare, Better Stack, Spotify, GitHub, Cloudways.
It can never be shared with anything, and it can never become a shared secret.
Confuse the two and you get exactly this morning: a 401 with nothing in the
interface able to say which side was wrong.

## The keyring

15.2.0 built the one place. A registry of sixteen rows, each naming its constant,
its home, what it feeds, what it is NOT, and for a shared secret the worker's
secret name and the `wrangler secret put` that sets the other half. One leaf, one
save handler, and Verify all: one probe per row, each verdict a sentence that
names the side to fix. The four feature tabs that used to carry credential fields
lost them and point here. The module is called `keyring` because the owner's
tooling refuses any file named `credentials*`; the tab says Credentials.

The first paint of that leaf was sixteen stacked inputs in one column with the
other half of the window empty. The owner sent screenshots and called it a wall,
and he was right: the registry had leaked into the form, sixteen rows of data
becoming sixteen inputs on screen, and the one row that mattered sat between
fourteen nobody edits. 15.2.1 makes it a ledger: one table per group, the refused
row shouting from a notice on top, one small form in the rail for the one value
being changed, and the four worker commands in one place. A ledger is read far
more often than it is written; a rotation touches one row.

The same fix deleted the four handlers and two field helpers the keyring had made
unreachable, and carried over the one thing they still did that the keyring did
not: dropping the caches a rotated key would otherwise serve stale.

## The ledger caught it twice more

The first Verify all on the ledger read four rows ending in the same four
characters: the site secret, the sensor's read token, the Cloudflare API token
and the analytics override, one value in four places. The Cloudflare token had
been pasted as the site secret and as the sensor's password, the very confusion
the leaf existed to prevent, and the leaf had accepted every paste without a
word. Then the owner rolled the Cloudflare token and the sensor's verdict moved
from "differ" to "the worker's SQL token lost Analytics Engine": the worker held
a copy of the rolled token, and nothing on the leaf had said the token had a
second home.

15.2.2 closes both. `keyring_save` refuses an issued token pasted into the site
secret or a worker row, by name, and refuses any value another row already
holds; the analytics override verifies with its own bytes, so a dead override
reads refused instead of "no probe"; and the Cloudflare row prints
`wrangler secret put SN_MR_SQL_TOKEN` under Worker secrets, the fifth command.

The same fix bounded the firewall window on both ends. The raw dataset's first
live read answered "cannot request a time range wider than 1d, but your query
time range spans 1d1s620ms": the query sent only a start, Cloudflare closed the
window at its own clock a second past mine, and the Free plan's cap for that
dataset is exactly one day. The verdict machinery paid for itself there: the raw
dataset's sentence was kept verbatim beside the grouped one, so the overrun, one
second and change, was on the owner's screen instead of a generic refusal.

The pattern under all three: a form that accepts a value it could have
recognised is a form that will accept the wrong one. The keyring knows every
issued token it holds; matching a paste against them costs nothing and would
have saved the afternoon.

## A reading belongs where its question is asked

With the firewall finally answering (3,015 events in a day, 1,843 skipped and
1,172 blocked, the abilities route the top blocked path at 487), the owner
looked at the Cloudflare leaf and said Edge and Firewall could be elsewhere. He
was right for the same reason the ledger was right: Connections is where you
wire a thing up, and these two are readings. 15.3.0 puts the seven-day edge
figures above the Analytics hub, next to the analytics they belong with, and
gives the firewall log its own leaf under Security, beside Login defense, where
"what is being kept out" is already the question. Connections › Cloudflare is
back to wiring and cache: Credentials, Token with the monitor's Refresh, Cache.
One stored record feeds all three leaves; the cron and the ability did not move.

## The sweep, and three screens

The owner asked for a sweep: where else does a credential sit beside numbers?
Three leaves. AI › Models & budget kept the Workers AI token inside the budget
form; Connections › Discography kept the Spotify pair inside the sync form; Search
Console keeps a service-account JSON, which is a file and stays in its own box.
15.3.1 moves the first two to the keyring (seventeen rows now) and the leaves read
the sources like the others. The same cut balanced three leaves the owner sent
screens of: the new Firewall leaf was one narrow card over an empty half and is
two columns now, what happened left and to what right, with the rules under a
heading they never had; the Edge band on Analytics moved into the left column
above the five folds it had been leaving over nothing; and Insights' Scan status
went under the Run Analysis button whose result it is. Two stale pointers that
still sent the zone id to Connections › Cloudflare now say Credentials.

Rule for the day, three times over: a column that is a single card over a
screen of nothing is a layout that has not been looked at painted.

## The Home, last

The owner ended the day on S&N Home with three screens and "some problems". I
read them wrong twice: first the Caches card's "Checking…" and three workers
"warming…", which he pointed out do resolve (the warming is a one-load lag after
a deploy, the Caches placeholder a real bug in the native port, fixed in the
same cut); then a list of four other candidates. What he meant was the Detail
box under Operations, where Recent deploys and API limits sat beside Top pages,
Top sources and Top queries. Audience numbers under the ops heading: the day's
rule one more time, and the last place I had not applied it. 15.3.2 gives each
panel a group; the audience three paint under Site pulse, the ops two stay under
Operations, and Top queries names its column, so six zeros read as clicks over
a month rather than as a broken list.

Then he zoomed the window out and sent the whole Home at once, and what 15.3.2
had painted was plain at that size: the three audience panels were shaded cards
inside a section inside the pulse, a box in a box beside flat tiles, in a
two-column grid that left Top queries alone with an empty cell, under a section
heading where every sibling label is a small-caps eyebrow. 15.3.3 makes both
details an eyebrow over flat columns, one column per panel from the painter's
own count, a rule between and no card. The lesson is the one from the ledger
again, at a different scale: a layout is not looked at until it is looked at
whole, and a zoomed-out screenshot is the cheapest whole there is.

Two misreads before the right one is the note to keep: when the owner says a
leaf has problems, the cheapest first move is to ask which box, not to list
what I would fix.

## What the verify said, first run

Cloudflare `ok`, an account token. Better Stack `ok`, four monitors. Spotify
`ok`. GitHub `ok`. The sensor `refused`: this value and the worker's
`SN_MR_READ_TOKEN` differ. One sentence, the right side named, and the command to
fix it printed under it. That is what the day was for.

The rail, the full-height gradient with the nav at its foot, I asked about twice;
the owner's answer is that it is the design, and it is recorded as such.

## The next morning, in someone else's repo

Two small things on the 16th, both about whose work a thing is. The Forms
maintainer wrote that he loved PR #56 and that it had errors. I found jsdom's
missing `CSS.escape` in the test I had added and wrote a stub, and only then
saw that his agent had already pushed the fix to the same branch while I was
writing mine. The rebase conflicted and the owner said stop. I aborted, reset
the fork to his branch, and pushed nothing. What is still red there, the
committed `builder.min.js` bundle, is his. Before touching a branch someone
else has advanced, fetch and read their head; it is usually the fix.

The other direction: the owner had me invite AllTerrainDeveloper as a
collaborator here, as he had just done for us on Forms. He wrote #751, the
v12.4.0 WP Explorer integration. Write access, main still behind the rulesets,
pending until he accepts.

## The reference, and the one thing it caught

The owner asked whether we had used developer.wordpress.org and then whether
anything in the plugin or the theme "needs some rightness" by it. Not a
question to answer from memory. I swept both repos for the points the
reference is authoritative on and code drifts from silently: deprecated
functions, string autoloads, translation calls before `init`, REST routes
without a permission callback, abilities without annotations, `theme.json`
and `block.json` versions, cron scheduled and never cleared. The first pass
returned zeros everywhere, which was the shell: zsh does not split a variable
of grep flags into words, so every grep got one bogus argument. Under bash the
sweep read clean on every point but one.

The plugin armed thirty-eight cron hooks, twenty-four recurring and fourteen
single, and had no deactivation hook. The Plugin Handbook's cron page says a
plugin unschedules its events on deactivation, because the events outlive it
in the `cron` option and WP-Cron keeps firing them into a callback that is
gone. 15.3.4 does it the documented way and no other: one list, one
`register_deactivation_hook( __FILE__, ... )` beside the activation hook that
was already there, `wp_unschedule_hook()` on each, chosen over
`wp_clear_scheduled_hook()` because the single events carry arguments and only
the first ignores them. The suite derives the scheduled set from source and
pins the list both ways; on its first run it named two hooks my hand list had
missed. A list is only as good as the census that checks it.

The owner's rule from this, recorded: where the reference names THE way to
do a thing, do it that way, no hand-rolled twin, no doubt.

## The same sweep, on the theme

"Also do the same sweep on the theme repo." Same points, the Theme Handbook's
and the theme.json reference's this time, under bash, the sibling worktrees
excluded from the grep. Clean on most of it: pattern headers and their
category, template part areas, custom template post types, fonts through
`fontFace`, the JS blocks on `block.json`, the variation, the screenshot,
hook timings. Three drifts.

The text domain was `signal-noise` against a slug of `signal-and-noise`, the
mismatch the plugin fixed in 8.7.2 and Theme Check flags. Sixty-four calls,
the header, three manifests, the updater's upload identity constant and the
phpcs property. The same string is also the pattern category, the block
category and the block namespace, none of them a text domain, so the census
that pins it scans the domain argument of each i18n call with balanced
parentheses rather than the literal. The nine PHP-only furniture blocks from
13.1.0 registered from PHP argument arrays; they have `block.json` and
`render.php` now, the Block Editor Handbook's canonical form, and the registry
is a loop over the directories.

The third I got wrong before I got it right. I read `settings.viewport` as a
key theme.json does not know, from memory of the 7.0 schema, and deleted it.
The theme's own suite, pinned to the 7.1 dev note on configurable viewports,
went red, and it was right: the key is 7.1's, the file's `$schema` still said
7.0, and the drift was the pointer. I fetched both schemas and counted the
key before touching it again. A `$schema` line is a version claim, and it now
has to equal `Tested up to`. Theme 13.2.3 carries all three, pinned by
derivation in `tests/reference-conformance.php`.

## What the edge is set to

"Since we have a firewall now, should we have something else for security
that comes from Cloudflare?" The honest inventory: the plugin read two
endpoints, the firewall log and the token verify, and the headers probe read
the edge from outside. What none of them could see is what the edge is set
to. A Flexible SSL mode looks identical to Full (strict) from the origin,
because the edge terminates TLS either way; Development Mode left on after a
debugging session bypasses the cache for three hours with nothing in
wp-admin saying so. Cloudflare's own docs name a read for each: zone
settings, DNSSEC, and the custom ruleset, each on the Free plan, each with a
scope the API reference names.

15.4.0 reads the three daily on the monitor's hook and on Refresh now, one
option, never at request time. The owner asked that the readings be placed
"with some taste and sense", so the Firewall leaf keeps its two columns and
the posture sits under Acted on: the five judged rows as a dotted list in
words (Full (strict), TLS 1.2, on, off, active), the plain readings as one
quiet line, the custom rules by name with their state, and a refused read as
one notice naming the scope while the rest still paints. The Top rules list
on the left names its rules now instead of showing ids. Health check 27 turns
a drift into a finding, and a refused read into a finding too, never a pass.

The witness for the abilities rule gained its best source. The ruleset read
says whether the rule exists and is enabled, from the side that enforces it,
on a quiet day with no blocks. It reads first; the log second; Better Stack
third. A disabled rule reads open even with a fresh block in the log,
because that block was yesterday's rule. The order encodes what each source
can prove: configuration from the enforcer, then behaviour from the enforcer,
then behaviour from outside.

One thing nearly went wrong, and is recorded. The background gate for the
morning's docs PR ended by detaching this worktree to origin/main, and it
fired while the posture module sat uncommitted on a feature branch. Git
carried the edits across, so nothing was lost, but the release chain that
followed would have swept anything written into the same worktree into the
cut. This section was written from a second worktree for that reason. A
gate merges and stops; it never decides where HEAD is.

## What the first read found, and what changed because of it

The posture's first live read had two red dots, and neither was a false one.
DNSSEC was `disabled` at the zone. SSL mode was `full`: the edge encrypted
to the origin but did not check who the origin was. The owner asked whether
to switch to Full (strict), and the right answer needed one measurement
first: a TLS handshake to the Cloudways server with the site's name. It
answered with Cloudways' own `*.cloudwaysapps.com` wildcard, trusted but not
for juanlentino.com; strict would have refused the origin and served 526 to
everyone. The Cloudways record agreed (`lets_encrypt: null`) where the
owner's memory said a certificate was there. A Cloudflare Origin CA
certificate, minted on Cloudflare and pasted into Cloudways as Custom SSL,
put the right name on the origin, valid to 2041; the handshake confirmed it
before the mode was touched; the next read said `strict`.

DNSSEC took twenty minutes. Cloudflare mints the DS record; Namecheap hides
the form for it behind a toggle on the Advanced DNS tab, where a custom-DNS
domain otherwise shows nothing, which is what "I can't add the DS record"
meant. `pending` at 20:03, `active` at 20:21.

Two smaller things the read taught. The DNS Read scope went first onto a
user token, not the account-owned one the plugin holds; the read that had
already succeeded, zone settings, said which token was in use, because the
edited token lacked that scope. And Cloudflare reports TLS 1.3 with 0-RTT as
`zrt`, which the leaf showed raw for a few hours. 15.4.1 carries the word
and flips the three scopes to measured.

Five green dots at the end: Full (strict), TLS 1.2, HTTPS forced, Development
Mode off, DNSSEC active, four custom rules enabled and named. Both were
invisible from wp-admin this morning and both are read daily now.

## The reader's agent gets the site's own arithmetic

The evening went somewhere the morning did not expect. "Is there any very
small model to manage the Notes recommendations?" There already was one:
`bge-base` at the edge, ranking 55 notes at rebuild, the smallest thing that
does the job. The honest answer was that the model is not the interesting
part; the browser is. Since August every page carries a WebMCP bridge that
registers tools with the reader's own agent, and the reader's agent brings
its own model. So the question became: what should the page offer it?

Four tools, in two arcs, with one rule kept from August: a bridge tool reads
public bytes and calls no authenticated door. The owner reversed his own
two-tool decision from the 28th, in his words, and the design note records
both the reversal and the rule. Arc one shipped the same night across three
repos in the order the design demanded: the plugin first (15.5.0: a
related-notes manifest on every note beside the verification one, and
`/notes/index.json`, the machine twin of the whole site, "the sitemap should
have everything"), then the rights-signals worker (1.25.0: `related-notes`,
`get-site-map`, `get-citation` with the owner's author form and ORCID on
every note, and a beacon so tool use is a number rather than a guess), and
the provenance sweep minted the new bridge version on its own.

"I hope no one can abuse these tools at all." The abuse map was short
because the tools are read-only over public bytes; the one write, the
beacon, was shaped to write nothing unless everything about the call is
right, and to answer 204 whatever it did. The per-IP ceiling did not spend
the zone's one rate-limiting rule: the Workers Rate Limiting binding does
the same thing inside the worker, failing closed, and shipped as 1.25.1.

Then the owner wanted the tools where he could see them, "somewhere,
somehow", and then more precisely: under AI, where MCP lives, and with all
the tools there, the MCP ones too. 15.6.0 is that leaf: Agent tools, every
tool an agent can call on this site by door, and whether it does. On the
page, the bridge's five with calls and outcomes, reported by browsers; what
they read, with a dot each; through MCP, the call log open and the four
doors folded, moved out of MCP Clients, which now says only how to connect;
through Copilot, the leaf that used to stand alone. Config, config,
observation.

Two things the build taught, both already in memory by the time this was
written. The Machine Readers normalizer folds an unknown family into
`other-bot` and an unknown surface into `html`, so the beacon's rows had to
be split off before it ran or a tool call would have read as a bot fetching
a page. And the summary ability is a remote-MCP contract twin: one additive
field is a version bump and a worker redeploy, so a figure that reads zero
until agents arrive rides the fetch result and the leaf instead, and joins
the payload when it has a number. Arc two, the semantic search on the
worker, is gated on exactly that number.

## Being the agent, and five red lines

With 15.6.0 on the site the owner asked how to test the WebMCP. The honest
way is to be the agent: hand the bridge a fake `navigator.modelContext` that
records what it registers, then call each tool's `execute` over a real note.
The page's CSP forbids `eval`, so the bridge has to be imported as a module
rather than evaluated, which is how it should be. In the clean browser pane,
on the latest note, all five answered: five related notes with scores and
shared tags, the site map with 43 notes and 3 pillars, a BibTeX entry with
the anchored hash and the ledger record, the ODRL policy, and a verdict of
"Authentic, qualified" with the Bitcoin anchor still confirming. Five beacons,
five 204s, the first rows on Agent tools. The owner ran the same three lines
in his own Chrome and got a citation back.

His console also showed five red lines, and they were three different
things. One was ours: the theme's footnote popover captures `pointerleave`
on the document, so the pointer leaving the window fires with the document
itself as the target, which has no `closest()`; 13.2.4 guards the four
handlers through one helper, with a node suite that goes red on the old file.
One was the zone's: Cloudflare Web Analytics injects its beacon at the edge
on every page, and the CSP transform rule blocks it, so it has been a console
error on every visit; the owner's call whether to turn the analytics off or
allow the host. Two were the owner's browser: an extension injecting Ant
Design and DM Sans, present in neither the served HTML nor the clean pane.
The rule, recorded: curl the page, then load it clean, then blame the
extension.

Two more things fell out of asking whether the theme was compatible with
all of this, which is a question answered by curling every page kind and
counting what each carries. Every kind carries the bridge tag with a
matching SRI, and the ledger already held `webmcp-bridge/v2`. But the notes
archive carries ten `Article` nodes, one per listed note, and the bridge's
citation tool took the first Article in the graph, so on the archive it
would have cited the first listed note as the page; worker 1.25.2 matches
the Article to the page's canonical URL instead. And the pillar pages, the
essays, carried no Article at all, so the most citable pages on the site
answered "not a note"; plugin 15.7.0 builds one for them, which search
engines wanted anyway.

## Left open

- Clear the analytics override and the stale site secret (both still hold the
  rolled-away Cloudflare token); mint a real site secret when a worker row
  should derive from one.
- Worker #45 (vitest): its cooldown clears 2026-09-17 around 18:00Z.
- Plugin 15.6.0 (Agent tools) released 22:18Z and installed on the site by 22:40Z;
  the deploy reading says current equals latest for the plugin, the theme and the
  five workers. The two worker versions merged tonight had no tags until then,
  and the deploy reading names `latest` from tags: tagged, and the row agrees.
- Theme 13.2.4 (the footnotes popover's document target) and plugin 15.7.0
  (pillar Articles): cuts in progress; worker 1.25.2 live and tagged.
- Cloudflare Web Analytics vs the CSP: the beacon is blocked on every page;
  turn Web Analytics off, or allow `static.cloudflareinsights.com` in the
  script-src of the transform rule. Owner's call.
- WordPress 7.1.1 is imminent (its schedule was posted 2026-09-02); the site
  takes it through the updater, never by hand. Core's move to Node 24 and
  npm 11 touches nothing here: the plugin and the theme have no Node
  toolchain, and both workers already pin Node 24 in CI.
- WordPress 7.2: Beta 1 on 20 to 22 October, RC 1 on 17 to 19 November,
  final on 8 to 10 December 2026, with a new default theme. Test both repos
  against Beta 1 when it lands (the day the 7.2 stubs publish, the phpstan
  gate can move with them).
- WebMCP bridge v2 arc two (`search-notes` on the worker): gated on a month
  of beacon rows on AI › Agent tools, or the owner saying build it.
- The provenance sweep's `webmcp-bridge/v2` record: confirm in the ledger
  index on the next read.
- Upstream OpenStation #819 / #820: with the maintainers.
- The `firewallEventsAdaptive` page is a floor past 10,000 samples a day; page
  it when a day gets there.
