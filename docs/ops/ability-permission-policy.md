# Ability permission policy

**Status:** adopted v11.21.0 (2026-08-18). Governs the `permission_callback` of every
`wp_register_ability()` call in `inc/abilities-*.php`.

## The problem this fixes

Every Signal & Noise ability was gated at `manage_options` — **including pure reads**.
An agent asked to run `sn-scan` therefore had to be an Administrator, which is the
capability to install plugins and edit users. Least privilege was not merely
inconvenient, it was **unavailable**: there was no role between "cannot call the tool"
and "can take over the site."

The diagnostic that surfaced it: theme abilities (laxer permissions) succeeded while
plugin abilities failed for the same Editor-role agent, and the partial success read
as flakiness rather than as a permission split.

## The rule

A `readonly` ability drops below `manage_options` only when **all three** hold:

1. **It is genuinely read-only.** The `readonly` annotation is a CLAIM; the execute
   callback is the evidence. Verify it writes nothing, including options and meta.
2. **It exposes no PII.** Usernames, login events, IP addresses, visitor-level data.
3. **It exposes no secrets or infrastructure configuration.** Tokens, credentials,
   endpoint URLs, deploy targets, cron internals.

Everything else stays at `manage_options`, and the reason is recorded below rather
than left to be re-derived.

## Why `edit_others_posts`, not `edit_posts`

The scaffold for this arc proposed `edit_posts`. That is too loose. The corpus
abilities read across **publish, future, draft, pending and private** statuses, and
`edit_posts` is held by the **Author** role — who must not read other people's
unpublished work.

`edit_others_posts` is held by **Editor** and above and by neither Author nor
Contributor. It says precisely what these abilities need: *may read other people's
unpublished content*. It meets the goal that opened this arc — an Editor-role agent
can run the read tools — without granting it to a role that should not have it.

## Tier A — content reads (`snt_ability_perm_read_corpus`, `edit_others_posts`)

Twelve abilities. Each reads post bodies, titles or corpus statistics and writes
nothing.

| ability | reads |
|---|---|
| `sn-posts` | post list + content hashes |
| `sn-scan` | corpus-wide candidate scans |
| `sn-validate` | validation of proposed content against the corpus |
| `get-post-content` | one post's body |
| `list-posts` | post list |
| `duplicate-body-scan` | byte-exact body duplicates |
| `draft-echoes` | draft/published overlap |
| `near-duplicate-scan` | TF-IDF cosine pairs |
| `keyword-candidates` | per-post TF-IDF unigrams/bigrams |
| `link-candidates` | internal-link opportunities |
| `topic-clusters` | corpus clustering |
| `cadence-flags` | publishing-cadence signals |

## Tier B — stays `manage_options`, with the reason

| ability | why it stays |
|---|---|
| `get-audit-log`, `export-audit-log` | **PII.** Records usernames and login events; the module carries an explicit `$include_pii` redaction path, which is the tell that this data is sensitive by design |
| `get-analytics-events`, `get-analytics-summary` | visitor analytics — aggregate, but audience data is an owner concern, not an editorial one |
| `get-collector-status`, `ai-cache-probe-status` | infrastructure + provider configuration state |
| `sn-site-facts` | spans settings drift keys and tool telemetry |
| `list-cron-events`, `get-cron-history` | scheduler internals |
| `get-deploy-status`, `list-template-overrides` | deploy and theme infrastructure |
| `get-health-scan`, `get-insights`, `get-narration`, `get-rss-stats`, `get-machine-readers-summary` | operational readouts about the site as a system |
| `anchor-status` | provenance chain state (in-flight transaction ids) |

**A keyword grep is not a classification.** Scanning the candidates for
`token|secret|credential|api_key` produced ten hits, and **every one was a false
positive** — `px_token_set` (a boolean presence flag), LLM "token figures",
CSS `design_tokens`, and validation "check tokens". The PII in the audit log, by
contrast, matched none of those patterns. Read the callback.

## Tier B exception — one note, at `edit_post` (v13.100.0)

| ability | why it sits below `manage_options` |
|---|---|
| `note-dossier` | `edit_post` on the note, deliberately below `manage_options`: the dossier is the editor's own view of one post they may edit. It exposes, for that post only, per-path views and visits, Search Console impressions and clicks, the edge-freshness verdict and the site-wide machine-read total: audience and operational data that stays at `manage_options` when asked site-wide (`get-analytics-summary`, `get-machine-readers-summary`). Scope, not sensitivity, is what changed. The callback is `snt_ability_perm_edit_post`, which checks the capability against the `post_id` in the input, so an Author reads their own notes and nobody else's. |

The app's two post sections (Notes, Pages) scope their unpublished half to its author for a user without the type's `edit_others_*` capability, with two mechanisms because WordPress has two: `'perm' => 'readable'` narrows PRIVATE posts to what the user may read, and a `posts_where` clause (`post_status = 'publish' OR post_author = <me>`) narrows draft, pending and scheduled, which `readable` leaves unrestricted. So an Author browsing either section sees everyone's published posts and only their own unpublished ones.

## Adding an ability

Choose a tier deliberately. `tests/ability-permission-policy.php` enumerates every
registered ability and its callback, so a new one fails the suite until it is
classified here — the same discipline the health surface map uses. Defaulting to
`manage_options` is always safe; defaulting silently is not.
