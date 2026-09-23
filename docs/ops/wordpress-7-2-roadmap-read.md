# WordPress 7.2: the roadmap read against this plugin and theme

Read 2026-09-18 from [Roadmap to 7.2](https://make.wordpress.org/core/2026/09/18/roadmap-to-7-2/), the [Secrets API proposal](https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/), the AI-track issues it links and the Trac tickets it names, against plugin 16.6.0 and theme 13.3.x. Beta 1 is 20 to 22 October, RC 1 17 to 19 November, final 8 to 10 December 2026. The post's own caveat holds: pursued, not promised.

The question for every item was the same: do we carry something that core will now carry, and if so, which half is ours to keep.

## Drop or fold into core when it lands

### Secrets API, the keyring's storage half

`wp_set_secret( $name, $value )`, `wp_get_secret( $name, $version )`, `wp_delete_secret`, `wp_import_option_as_secret( $option, $name )`. Encrypted at rest in the options table with libsodium (`autoload=no`, hidden from `options.php` and the REST settings endpoint), master-key envelope from `WP_SECRET_KEY` or the salts, a `WP_Secret` value that masks itself in logs and gives up its value only through `->reveal()`, CURRENT and PREVIOUS slots for rotation, a change hook carrying fingerprints, `manage_secrets`. No admin screen in 7.2.

Ours: `inc/keyring.php`, twenty rows, seventeen of them plain options read through `sn_keyring_stored()`. What core does not ship is the part worth keeping: the registry of which rows exist and what each feeds, the probes, Verify all, the verdict ledger. What core replaces is the storage.

Plan: at Beta 1, storage goes through `wp_get_secret` / `wp_set_secret` behind `function_exists`, `wp_import_option_as_secret` runs once per option-backed row on upgrade, and the proposal's "imported, flagged for rotation" state becomes a verdict in the ledger. The `secrets.php` drop-in is the later door to a Cloudflare-held ciphertext store.

### The MCP adapter and the read door

`WordPress/mcp-adapter` is being readied for the plugin directory (#178), adding the 2026-07-28 revision beside 2025-11-25 (#313); the AI plugin will expose MCP with minimal setup (ai#992). We hand-roll the transport in `inc/mcp/`, dual era included. The 2026-08 consolidation findings were right that there was no adapter to migrate to then; by 7.2 there is.

Ours to keep whatever the transport: the abilities, the read and write allowlists as policy, the rw audit, the telemetry. Duplicate once the adapter is installed: JSON-RPC routing, version negotiation, resources and prompts plumbing.

Plan: a watch, not a rewrite. `mcp_adapter_read_door` ripens when the adapter is active on the site at 0.7.0 or later (17.8.1: 0.7.0 is its first release as an installable plugin; read from `McpAdapter::VERSION`, so a plugin, a bundle and a future core copy count the same); then register the abilities with it and retire `/mcp` (read) first, `/mcp-rw` only once the adapter's per-door hardening matches `mcp-rw-guard`. The connect leaf already reports `adapter_active`.

### Trac #65551, live today

Since 16.5.2 the TypeSafe key is Core's connector's (`sn_jev_key()` reads `get_api_key()`), and Core's settings save deletes a stored key whenever validation is not strictly `true`, including `null` for "the provider did not answer". A Connectors-screen save during a TypeSafe blip erases the key silently. The fix is unmerged. Until the ticket closes: do not re-save Connectors while TypeSafe is down. The watch `connector_key_wipe_65551` ripens on WordPress 7.2, which is when to confirm the fix landed. #65523 (activate and deactivate from the Connectors screen) and #65215 (preloaded REST on that screen) improve a surface we already stand on; nothing to change.

## Adopt the mechanism, keep the surface

### HTML processing on the HTML API

The theme's `sn_strip_generator_meta` is a `preg_replace` output-buffer callback; `preg_replace` returns null on a PCRE limit, and a buffer callback that returns null sends an empty body with a 200. That is the 358-byte `/provenance/` Cloudflare cached twice. Theme 13.3.1 guards the null; the follow-up is `WP_HTML_Tag_Processor` there and in `inc/seo.php`'s head rewrites, which never returns null and never backtracks.

### Knowledge and Guidelines

`wp_knowledge` shipped in 7.1 with Guideline, Memory and Note types; Skills and Plans are deferred (gutenberg#77230). Our house rules live where core cannot see them: the theme's `inc/editorial-conventions.php` registry (read by `sn-validate` and `sn-site-facts`) and the `provenance-voice` skill. Publishing the registry's rows and the voice rules as Guideline records lets any agent that honours Knowledge read what our own tools read. Additive; after Beta 1, when the type taxonomy is settled.

### Agent identity (ai#923)

Core is moving toward agents as a user type with their own audit trail and revocation. `sn_mcp_telemetry_agent_actor` already labels actors from a user id; the Machine Readers token is a credential separate from any human. When agent users land, the actor column reads them natively and the read token becomes an agent user's application password. Watch; nothing to build.

## No overlap

- **Script and style concatenation (#57548)** retires wp-admin's `load-scripts.php` and `load-styles.php`. The theme's front-end `asset-combine.php` is untouched. The ticket's argument (HTTP/2 makes it moot) is a reason to re-measure our combine one day; that is a measurement, not this roadmap.
- **Application-password email on creation (#63927, committed)** complements the MCP bind audit.
- **SameSite (#37000)**: we set no cookies. **Local/HTTPS detection (#57388)**, **`default_role` (#46744)**, **sudo mode**: nothing of ours.
- **WebMCP (ai#448)** is an experiment in the AI plugin against a draft whose API halved since March; bridge v2 stays, arc two stays gated.
- **Embeddings (ai#962)** arrive through connectors, and there is no Workers AI connector; `bge-base` on Workers AI stays until one exists.
- **Core abilities (ai#40)**: `core/read-content`, `core/read-settings`, media, taxonomy and comments next. Ours carry site-specific signals and are not duplicates; the consolidation rule applies if a core ability ever covers a plain read we expose.
- On This Day widget, dashicons to SVG, the DataForm inspector (already on the Beta 1 test list as our one inspector extension), Ipsum, table of contents and description list blocks, revisions, paper cuts, Interactivity `data-wp-html`, axe-core, client-side media: read; nothing of ours competes.

## The order

1. 16.6.1: the two watches (this document's companion). Done.
2. Beta 1, 20 to 22 October: keyring storage over the Secrets API; the tag processor for the generator strip and the head rewrites; Guideline records from the conventions registry; the DataForm inspector test; the phpstan gate to the 7.2 stubs when they publish.
3. On the watches: the read door into the adapter; #65551 verified on 7.2.
