# Dashboard widget contract — prep

Written 2026-08-23, measured against **Gutenberg 23.8.0** (released 2026-08-19).
Occasion: the Gutenberg plugin was installed, broke `Settings → AI`, and was
removed again — see the [session finding](#appendix-why-gutenberg-came-off) below.
While it was on, the dashboard widget system was worth reading, because we have
nine widgets that do by hand what it does by convention.

**This is a WATCH document, not a port plan.** Nothing here is actionable yet,
for one reason stated in the next paragraph. Read that before planning work.

## Verdict: the door is closed, and the doc says so

> "The hydration is a deterministic copy, with no filters in between. The
> `widgets/` folder is the single source of widget authorship in this codebase."
> — [dashboard-widgets.md](https://github.com/WordPress/gutenberg/blob/HEAD/docs/explanations/architecture/dashboard-widgets.md)

There is **no plugin-facing registration API**. Widgets are discovered by folder
convention inside the Gutenberg repo, compiled into `build/widgets/registry.php`,
and copied into `WP_Widget_Type_Registry` at `init`. A third-party plugin has no
entry point. The whole system also ships behind the `gutenberg-dashboard-widgets`
experiment, and the doc warns that APIs and file conventions may change.

The watch signal is named in the same doc:

> "A future source, such as a plugin-facing registration API, would target the
> registry without touching the build pipeline."

So: watch `lib/experimental/dashboard-widgets/` for a registration entry point,
not the build pipeline. Add it to [upstream-monitoring.md](upstream-monitoring.md)
when it appears. Until then, `inc/desktop-mode-widgets.php` stays exactly as it is.

## The pipeline, stage by stage

```
widgets/<name>/          folder convention — no registration call
  ↓ @wordpress/build
build/widgets/registry.php    manifest: metadata + which modules were built
  ↓ init
WP_Widget_Type_Registry       WP_Widget_Type per entry, strings localized here
  ↓
GET /wp/v2/widget-modules     { name, render_module, widget_module, presentation,
                                category, title, description, help, icon,
                                actions, keywords }
  ↓ useWidgetTypes( records ) — imports widget_module, merges with record
WidgetType[]
  ↓ <WidgetRender> → host-supplied ResolveWidgetModule → import( render_module )
```

The **host** — `@wordpress/widget-dashboard` today — owns the layout array and its
persistence, the chrome (header, toolbars, **error boundary**, Suspense fallback),
the icon resolver, the field-type registry, and module resolution. A widget owns
its metadata and one React component. That split is the whole point.

## What the contract absorbs from what we hand-roll

| Ours today | Theirs |
|---|---|
| `label`, `description`, `icon` (`dashicons-*`) in the `snt_os_register_widget()` array | `widget.json` `title` / `description` / `icon` — icon is a **registered name** (`collection/icon-name`) resolved client-side through the Icons API |
| — | `help`: `{ content, links }`, surfaced as a header infotip |
| — | `category`, `keywords` — exposed through REST, used by pickers |
| English string literals, no i18n | `title` / `description` / `help` / `actions` / `keywords` localized **at registration** via `textdomain` |
| `min_width`, `min_height`, `default_width`, `default_height` per widget, each a measured judgement call | `presentation` on the record; the **host owns layout and persistence**, with tile spacing exposed as public custom properties (23.8.0) |
| `script` handle + `window.openStationWidgets[id]` mount + the compat prelude in [openstation-compat.php](../inc/openstation-compat.php) | `render_module` — an ES script module, resolved by the host's `ResolveWidgetModule`, `import( id )` on a WordPress page |
| DOM built by hand, `textContent`-only, self-contained inline styles | `render.tsx` default-exporting a React component + `style.module.css` injected by the build |
| no per-widget settings anywhere | `widget.ts` `attributes` — DataViews fields the host feeds into `DataForm`, with `registerFieldType()` for named types |
| each widget catches its own errors | host-owned error boundary and Suspense fallback |
| Quick Actions' three buttons, hand-built | `actions`: declarative verbs with `relevance`; `high`/`medium` route to a persistent footer, the rest to a "More" menu |

That last row is the one that stings: the relevance-routing we built by hand in
`assets/desktop-mode-widget-actions.js` is now a data field.

## The sketch — SN Deploy Status as three files

Modelled on `widgets/activity/` in Gutenberg 23.8.0, so the shapes are copied
from a real widget rather than inferred from prose. Widget names are namespaced
(`core/activity` → `signal-noise/deploy-status`).

**`widget.json`** — build-time input, plain JSON, never executed:

```json
{
	"name": "signal-noise/deploy-status",
	"title": "SN Deploy Status",
	"description": "Theme, plugin, and worker versions with last deploy time.",
	"help": {
		"content": "Version parity across the theme, the plugin, and the five workers, with the time of the last deploy."
	},
	"category": "dashboard",
	"presentation": "content-bleed",
	"textdomain": "signal-and-noise-tools",
	"keywords": [ "deploy", "version", "workers" ],
	"actions": [
		{
			"id": "open-dashboard",
			"label": "Open Dashboard",
			"relevance": "high",
			"href": "admin.php?page=sn-theme-options"
		}
	]
}
```

**`widget.ts`** — the live half of the metadata; optional:

```ts
import { __ } from '@wordpress/i18n';
import type { WidgetAttributeField } from '@wordpress/widget-primitives';

type DeployStatusAttributes = {
	showWorkers?: boolean;
};

export default {
	name: 'signal-noise/deploy-status',
	attributes: [
		{
			id: 'showWorkers',
			type: 'boolean',
			label: __( 'Show worker rows', 'signal-and-noise-tools' ),
		},
	] satisfies WidgetAttributeField< DeployStatusAttributes >[],
};
```

`showWorkers` is not a feature we have — it is here to show where the
`default_height: 310` budget comment in `inc/desktop-mode-widgets.php` would go
under this model. Height stops being ours to guess; the toggle that drives it
becomes a declared attribute the host renders through `DataForm`.

**`render.tsx`** — default-exports the component, receives `attributes`:

```tsx
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

type DeployRow = { label: string; version: string; ok: boolean };

export default function DeployStatus( { attributes } ) {
	const [ rows, setRows ] = useState< DeployRow[] | null >( null );

	useEffect( () => {
		// Real path: the ability run-path used by inc/ability-run-client.php,
		// driving signal-noise/get-deploy-status (inc/abilities-system.php:86).
		apiFetch( { path: SN_DEPLOY_STATUS_PATH } ).then( setRows );
	}, [] );

	if ( ! rows ) {
		return <Spinner />;
	}

	return (
		<Stack>
			{ rows
				.filter( ( r ) => attributes.showWorkers || ! r.isWorker )
				.map( ( r ) => (
					<Row key={ r.label } { ...r } />
				) ) }
		</Stack>
	);
}
```

No error boundary, no loading chrome, no header, no drag geometry — all host
concerns. Compare against what `assets/desktop-mode-widget.js` carries today.

## What it does NOT absorb — five findings

1. **Registration.** Covered above: no plugin door. Everything else is moot until
   that lands.

2. **Quick Actions cannot be declared.** An action has exactly one fulfilment key
   today, `href`, plus optional `download` / `openInNewTab`; `data:` and
   `javascript:` hrefs are rejected at registration. Our three actions are
   ability POSTs — purge caches, clear DB overrides, full reset. They would stay
   as buttons inside `render.tsx` and forfeit the footer/More routing. Arguably
   correct for **Full reset** regardless: a bare link with no confirmation step is
   the wrong shape for an irreversible action.

3. **No capability field in the record.** The REST record carries no capability,
   and our widgets are gated `view_stats || manage_options` at registration time
   (`inc/dash-widget.php`, `inc/desktop-mode-widgets.php`). Under this model,
   gating is a host or render-time concern. **UNVERIFIED** — this is exactly the
   kind of field a plugin-facing registration API would add, so re-check rather
   than designing around its absence.

4. **The cost profile inverts.** `inc/dash-widget.php` is zero-cost-on-render by
   policy — cached options only, never a remote call, because `index.php` renders
   on every admin login. The new model is client-side: the dashboard reads
   `/wp/v2/widget-modules`, then each render module fetches its own data. The
   discipline has to move with it — from "do no work during `index.php`" to
   "cache at the REST layer, because N widgets each fetch on mount."

5. **OpenStation is a host, in their taxonomy.** The doc contemplates hosts
   "outside WordPress" that "skip the import map and resolve modules through
   their own `ResolveWidgetModule`". That is precisely what OpenStation's
   `window.openStationWidgets` server-sync does, minus the shared contract. If
   OpenStation ever consumes `@wordpress/widget-primitives`, our nine widgets
   could target one contract instead of the `wp.desktop` / `wp.os` /
   `desktopModeWidgets` / `openStationWidgets` fan-out that
   `inc/openstation-compat.php` exists to paper over. That is the largest
   potential deletion in this whole document.

## Unverified, deliberately

Do not treat these as known when the door opens:

- The `icon` vocabulary — a registered `collection/icon-name`; our `dashicons-*`
  strings do not map without a lookup.
- `category` values beyond `"dashboard"`, and `presentation` values beyond
  `"content-bleed"`. One example widget is not a vocabulary.
- Whether capability gating gets a field (finding 3).

## Appendix: why Gutenberg came off

The Gutenberg plugin re-registers every `wp-*` script handle, so it *replaces*
core's `@wordpress/components`. PRs 81391 / 81433 / 81434 / 81435 removed
`ValidatedSelectControl`, `ValidatedNumberControl`, `ValidatedRadioControl` and
`ValidatedCheckboxControl` from that package's private APIs, moving them into
`@wordpress/dataviews`. The `ai` plugin bundles a pre-move dataviews that
destructures `ValidatedSelectControl` from `components.privateApis` at module
scope, so it evaluated to `undefined` and every select field on `Settings → AI`
rendered `<undefined/>` — React error #130, whole route dead. Not our plugin, not
OpenStation, not a core bug: core still ships all four symbols. Deactivating
Gutenberg fixed it and is the only fix until `ai` rebuilds.
