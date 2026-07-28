# Unauthenticated REST API hardening

Shipped in v9.83.0. Implementation:
[`inc/rest-hardening.php`](../inc/rest-hardening.php) (WordPress wiring) and
[`inc/rest-hardening-policy.php`](../inc/rest-hardening-policy.php) (pure
decision layer). Tests: [`tests/rest-hardening.php`](../tests/rest-hardening.php).

## Why

Two drivers, neither of them an active incident.

**CVE-2026-63030 (WP2Shell)** chained the REST batch endpoint to a `WP_Query`
SQL injection in core. Core is patched at 7.0.2, so this is defense in depth,
not a fix — it narrows the surface that made the chain reachable rather than
closing the hole, which is already closed.

**The durability assessment** found `/wp/v2/posts` serving full rendered post
content as clean, paginated JSON. Every TDMRep and Content Signals declaration
on the site lives on the HTML surface, so a consumer taking the JSON route got
the entire corpus with no reservation attached. The signal has to travel with
the machine-accessible copy or it is decorative.

This lives in the plugin, not the theme: it is site behavior, not presentation,
and it must survive a theme swap.

## The three controls

### 1. Route removal — `rest_endpoints`

Removed for **anonymous callers only**:

| Route | Why |
| --- | --- |
| `/wp/v2/users`, `/wp/v2/users/(?P<id>[\d]+)` | Username / slug enumeration — free reconnaissance for credential stuffing. |
| `/wp/v2/comments` | Comment bodies plus author metadata. |
| `/batch/v1` | The request multiplexer WP2Shell used to chain calls. Nothing anonymous needs it. |

Matching is by **prefix, not equality**, so listing `/wp/v2/users` also takes
`/wp/v2/users/me` and the application-password subroutes. Equality matching
would leave the sibling routes standing and make the removal cosmetic.

**Why `rest_endpoints` and not a `REQUEST_URI` match.** WordPress serves REST
at two spellings — `/wp-json/wp/v2/users` and `/?rest_route=/wp/v2/users` —
and both converge on the same `WP_REST_Server::dispatch()`. Filtering the route
table catches both by construction. A `REQUEST_URI` string match has to
hand-match every spelling, and a filter that only catches one is not a filter.

**Why gating on `is_user_logged_in()` is safe here.** `rest_endpoints` fires
inside `dispatch()`, which runs *after* `check_authentication()`. The current
user is fully resolved by that point. The block editor, the site editor, and
the REST media flows are all cookie-authenticated, so they never see the
removal — the filter returns the endpoint map untouched on its first line.

### 2. Rendered-field stripping — `rest_prepare_post` / `rest_prepare_page`

`/wp/v2/posts` and `/wp/v2/pages` stay registered. Removing them would break
legitimate discovery for no security gain, since the same content is public
HTML. Instead, for anonymous callers, `content.rendered` and `excerpt.rendered`
are emptied. Everything else — `id`, `slug`, dates, taxonomies, meta, and
`title.rendered` — is untouched.

The keys are **emptied, not unset**. The payload is the leak; the shape is not.
Keeping a well-formed response means schema-validating clients keep working.

`title.rendered` is deliberately preserved: it is metadata a discovery client
legitimately needs, and it is not the corpus.

### 3. TDM headers — `rest_post_dispatch`

Every REST response now carries:

```
TDM-Reservation: 1
TDM-Policy: https://juanlentino.com/tdm-policy/
```

**A correction to the original premise.** These were not "HTML only". Measured
against production on 2026-07-28, before any change:

| Surface | TDM headers present? |
| --- | --- |
| `/` (HTML) | yes |
| `/wp-json/wp/v2/posts` | yes |
| `/?rest_route=/wp/v2/posts` | **no** |
| `/wp-sitemap.xml`, `/feed/`, `/robots.txt` | no |

So the REST surface was already covered at one spelling and bare at the other —
the exact spelling a scraper avoiding a `/wp-json` block would reach for. The
current headers come from an edge rule, not from this codebase; emitting them
from `rest_post_dispatch` makes them origin-owned and spelling-independent.
Headers are set with `replace = true`, so an edge rule setting the same values
produces one header, not two.

Headers go out for authenticated callers too. The reservation is a property of
the content, not of the requester.

## The policy array

Every route decision goes through `snt_rest_hardening_policy()`, filterable as
`snt_rest_hardening_policy`. Nothing in the module hardcodes a route.

```php
add_filter( 'snt_rest_hardening_policy', function ( $policy ) {
    $policy['remove'][] = '/wp/v2/tags';   // harden further
    $policy['strip']    = array( 'post' ); // stop stripping pages
    return $policy;
} );
```

| Key | Meaning |
| --- | --- |
| `remove` | Route prefixes dropped for anonymous callers. |
| `strip` | Post types whose rendered fields are emptied for anonymous callers. |
| `protected` | Namespaces that can never be removed — see below. |
| `headers` | Name → value pairs added to every REST response. |

### The protected veto

`sn-prov/v1` backs the public cryptographic verifier; `signal-noise/v1` backs
the MCP tooling. Both are checked **before** the remove list and always win, so
a future filter — or a future edit to the default array — cannot take them out
by accident. `tests/rest-hardening.php` asserts this against a filter that
explicitly tries.

## Relationship to `inc/security-headers.php`

That module already 401s `/wp/v2/users` for anonymous callers via
`rest_authentication_errors`, matching on `REQUEST_URI`. It stays. The two
controls are independent and fire at different points:
`rest_authentication_errors` runs before dispatch, so on `/wp/v2/users` the
**401 is what you observe** — the route removal never gets a turn. That is the
belt-and-braces order working as intended, and it is why the route index at
`/wp-json/` is the honest observable for the removal, not the status code.

## Verification

Status codes alone do not prove route removal. `GET /batch/v1` returns `404`
both before and after, because the route only accepts `POST` and WordPress
answers a method mismatch on an existing route with `rest_no_route` — the same
404 an absent route produces. Check the route index instead:

```bash
curl -s https://juanlentino.com/wp-json/ | python3 -c "import json,sys; print([r for r in json.load(sys.stdin)['routes'] if 'users' in r or 'comments' in r or 'batch' in r])"
```

Anonymous, post-deploy, that list should contain no `/wp/v2/users`,
`/wp/v2/comments` or `/batch/v1` entries, while `/sn-prov/v1` and
`/signal-noise/v1` remain present.
