# Security notes

## Incident note

2026-09-29: CVE-2026-87902 (GHSA-7hp8-65ch-5whp), an unauthenticated path
traversal into an include through an encoded `../` in `pagename`. Verified the
site runs WordPress 7.1.2, which carries the fix. Not exposed.

What the check found anyway: the home page and `/wp-login.php` both printed
`wp-emoji-release.min.js?ver=7.1.2`, so anyone matching sites to a CVE could read
the core version off the page. `/feed/` printed no version and no generator.

## Core fingerprint

`inc/core-fingerprint.php`, pinned by `tests/core-fingerprint.php`.

Hidden:

- The core version in `ver` on assets core registers itself (src under
  `/wp-includes/` or `/wp-admin/`, ver equal to `$wp_version`). It is replaced
  by a 10-character salted token, not stripped: core assets are served with
  `cache-control: public, max-age=31536000` (one year, read live 2026-09-29), so
  a stripped ver would leave browsers on the old file after a core update. The
  token changes whenever core does and reveals nothing.
- `the_generator` for every type (html, xhtml, rss2, atom, rdf, comment,
  export), and `wp_generator` off `wp_head`.
- The concat loaders (`load-scripts.php`, `load-styles.php`) that
  `wp-login.php` and wp-admin use: their `ver` comes from `default_version` on
  the WP_Scripts / WP_Styles registries, set to the same token on
  `wp_default_scripts` / `wp_default_styles`. The theme already does this
  (`inc/frontend-filters.php`); the plugin copy survives a theme change.

Kept on purpose:

- Emoji, exactly as stock WordPress ships it (removed in 19.7.0, restored in
  19.7.1). Its script URLs are built in `_print_emoji_detection_script()`
  (`wp-includes/formatting.php`, 7.1: `concatemoji`, plus `wpemoji` and
  `twemoji` under `SCRIPT_DEBUG`) as `js/...?ver=$wp_version` and passed
  through `script_loader_src`, so each carries the token above. The s.w.org
  `emoji_url` and `svgUrl` paths hold the emoji set's version (17.0.2), not
  core's. The test runs core's own function and asserts `7.1.2` is absent.

- Plugin and theme `ver` cache-busters. Our release numbers are public on
  GitHub, and they are what makes a release reach browsers.
- Core assets whose `ver` is not the core version (jQuery 3.7.1 and the like).

The CSP's `*.w.org` allowance stays: emoji images load from s.w.org again.

## readme.html and license.txt

Blocked at the edge by the owner's Cloudflare "readme+licence" custom rule, not
in code (Cloudways ignores `.htaccess`). Verified 2026-09-29:
`curl -s -o /dev/null -w '%{http_code}'` returns 403 for both.

## Draft WAF rule: encoded traversal in `pagename`

For the owner to apply by hand. Not applied by any code.

Block when `pagename` in the query string carries an encoded or raw traversal.
`url_decode()` runs first so that `%252e` (double-encoded) becomes visible,
then `lower()` makes the match case-insensitive:

```
(lower(url_decode(http.request.uri.query)) contains "pagename=" and (
  lower(url_decode(http.request.uri.query)) contains "../" or
  lower(url_decode(http.request.uri.query)) contains "..\\" or
  lower(url_decode(http.request.uri.query)) contains "%2e%2e" or
  lower(url_decode(http.request.uri.query)) contains "%2e." or
  lower(url_decode(http.request.uri.query)) contains ".%2e"
))
or lower(http.request.uri.query) contains "%252e"
```

Action: Block. This is the one to apply.

Fallback, if the Free-plan editor rejects `url_decode()`: match the raw
encodings directly.

```
(lower(http.request.uri.query) contains "pagename=" and (
  lower(http.request.uri.query) contains "%2e%2e" or
  lower(http.request.uri.query) contains "..%2f" or
  lower(http.request.uri.query) contains "..%5c" or
  lower(http.request.uri.query) contains "%252e" or
  lower(http.request.uri.query) contains "../"
))
```

Plan notes:

- `http.request.body.raw` (and every body field except size) requires an
  Enterprise plan, so a POSTed `pagename` cannot be matched on Free. The
  body variant, for reference only: the same tests against
  `lower(url_decode(http.request.body.raw))`.
- Free allows 5 custom rules in the zone. Known in use: the readme+licence
  block (which also covers xmlrpc) and the WAF skip for static assets. The
  verified-bot rule is a transform rule and does not count. If slots are
  short, OR this expression into the readme+licence block rule instead of
  adding a rule. Regex (`matches`) is not available on Free, hence the
  `contains` chain.
- Ordering: custom rules run in the WAF phase, before any Worker route,
  including the login-guard worker on `/wp-login.php`. Place this rule above
  the static-asset skip so the skip cannot exempt it.

## The `core` and `runtime` readings

`get-deploy-status` carries `core` {current, latest, state, offer,
auto_updates, reason} and `runtime` {php, register_argc_argv}
(`inc/deploy-core-status.php`).

- `latest` is the highest version among the offers in the CACHED
  `update_core` site transient whose response is `upgrade` or `autoupdate`.
  Nothing here calls `wp_version_check` or the network. No transient: `unknown`.
- `state` is ok, behind or unknown. `offer` says what is waiting: `point`
  when an offer with response `autoupdate` (a same-branch point release) is
  newer than installed, `major` when only an `upgrade` offer is, empty when
  ok or unknown. Point wins when both exist. WordPress offers carry no
  security flag, so point cannot tell a security release from a maintenance
  one; the reason says "A point release is waiting (WordPress ships security
  fixes as point releases but does not flag them)." The widget and the
  Operations leaf show "behind (point)" or "behind (major)".
- `auto_updates` describes WordPress's own updater (DISALLOW_FILE_MODS,
  AUTOMATIC_UPDATER_DISABLED, WP_AUTO_UPDATE_CORE and the core filters).
  A host that updates core outside it (Cloudways can) is not visible here.
- `runtime` stays on the local door. The remote twin
  (`sn_remote_deploy_status`) runs a wrapper that drops it: PHP version and
  `register_argc_argv` are exploit-selection data (argc/argv on is half of the
  pearcmd include-to-RCE chain, the same class as this CVE), and the remote
  door relays through a Cloudflare worker to devices. Core rides remote
  (contract 13); it is the same class of datum as the theme and plugin
  versions already there.
