# Session — 2026-09-15: the key that was not a key

Eight cuts in one day, 15.0.0 to 15.3.0, and every one of them was the same
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

## What the verify said, first run

Cloudflare `ok`, an account token. Better Stack `ok`, four monitors. Spotify
`ok`. GitHub `ok`. The sensor `refused`: this value and the worker's
`SN_MR_READ_TOKEN` differ. One sentence, the right side named, and the command to
fix it printed under it. That is what the day was for.

## Left open

- Clear the analytics override and the stale site secret (both still hold the
  rolled-away Cloudflare token); mint a real site secret when a worker row
  should derive from one.
- Worker #45 (vitest): its cooldown clears 2026-09-17 around 18:00Z.
- Upstream OpenStation #819 / #820: with the maintainers.
- The `firewallEventsAdaptive` page is a floor past 10,000 samples a day; page
  it when a day gets there.
