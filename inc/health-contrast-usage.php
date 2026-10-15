<?php
/**
 * Signal & Noise Tools -- Content Health: the contrast USAGE tier, report only.
 *
 * The second half of what contrast_tokens promises. That check scores every
 * unordered token PAIR and says so in its own coverage sentence: "which pairs
 * ARE rendered together is not measured here". This module measures a large
 * part of that — the pairings actually DECLARED in stylesheets — and hands the
 * result back to the same check, as a second block in the same report. It is
 * deliberately NOT a nineteenth health check: the panel already exists, the
 * owner already reads it, and the honest fix for a check that declares itself
 * incomplete is to complete it rather than to sit a rival beside it.
 *
 * WHY THIS TIER EXISTS AT ALL (theme v11.7.0, 2026-08-11). The theme's own
 * suite reported 20 passed / 0 failed while the live site carried real AA
 * failures, because it scored the palette in theme.json and THE SITE SERVES A
 * STYLE VARIATION. blood-on-asphalt is 4.60:1 at root and 3.80:1 under High
 * Contrast. Every number was right; the palette was the wrong one. So this
 * scan scores under EVERY palette the site can present, and names them.
 *
 * WHAT IT COVERS, precisely, because a coverage sentence that overclaims is
 * worse than no coverage sentence:
 *   - Pairings DECLARED in CSS: a rule setting `color`, resolved against the
 *     background of the nearest enclosing rule that sets one.
 *   - Both token references (`var(--wp--preset--color--rust)`) AND literal
 *     hexes. The literal half is not an afterthought: the plugin's own
 *     provenance chip is entirely hardcoded, so a token-only scan — which is
 *     what the theme-side original was — would not see the component this
 *     module was written to catch.
 *   - Token references CARRYING A FALLBACK (`var(--wp--preset--color--void,
 *     #fff)`), scored as the token. Before that the regex demanded a
 *     bare `var(--token)`, which made the safe form and the scoreable form
 *     mutually exclusive: a component could paint reliably when the preset was
 *     missing, or be visible to this scan, never both. The fallback itself is
 *     deliberately NOT scored — every sheet listed by
 *     sn_health_contrast_usage_sources() loads in theme context, where the
 *     presets are defined, so the fallback is the branch no reader takes.
 *   - Declarations inside AT-RULES (`@media`, `@supports`, `@layer`), scored
 *     with their context carried rather than discarded. Two consequences:
 *     `@media print` blocks are dropped entirely, because this tier measures
 *     what a reader meets on SCREEN and a print colour over a screen surface is
 *     a failure nobody can encounter; and a colour is never anchored to a
 *     surface declared under a DIFFERENT at-rule, because
 *     `@media (max-width:600px)` and `@media (min-width:601px)` never hold at
 *     once. An unconditional surface still anchors a conditional colour — it
 *     applies at every width.
 * WHAT IT DOES NOT COVER:
 *   - WHETHER TWO AT-RULE CONDITIONS ACTUALLY OVERLAP. Context matching is
 *     literal string equality. Two differently-written queries that do overlap
 *     (`(min-width:600px)` and `(min-width:37.5em)`) are treated as
 *     incompatible, so their pairing falls back to the document background
 *     rather than to the real surface — a less specific answer, never a wrong
 *     one. Deciding it properly means evaluating media queries, which this scan
 *     does not do, and guessing is how a report starts inventing defects.
 *   - INDIRECTION through a non-preset custom property. This plugin's own
 *     sheets do `--sn-signal: var(--wp--preset--color--signal,#ff4c47)` and then
 *     `color: var(--sn-signal)`; the scan sees a definition it does not score
 *     and a reference it cannot resolve, so the pairing is invisible rather than
 *     wrong. Resolving it means following the cascade, which is the render
 *     tier's job. A non-preset var is never guessed from its fallback: when the
 *     property IS defined, the fallback is not what renders, and inventing a
 *     colour is the failure mode this whole module exists to avoid.
 *   - Colours inlined in BLOCK MARKUP (`has-blood-color` on a paragraph).
 *     Those live in templates, not stylesheets. Still the render tier's job.
 *   - The computed cascade: specificity, overrides, inherited `color`.
 *   - Non-resting states. See the note on sn_health_contrast_usage_surfaces().
 *
 * @package SignalNoiseTools
 * @since 10.90.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cap on reported pairings, mirroring SN_HEALTH_CONTRAST_MAX_ROWS. Worst-first,
 * so the tail is the safe end.
 */
const SN_HEALTH_CONTRAST_USAGE_MAX_ROWS = 25;

// The rest of the module lives in four parts files (house line cap, 2026-10-15),
// loaded in the order it always ran.
require_once __DIR__ . '/health-contrast-usage-sources-parts.php';
require_once __DIR__ . '/health-contrast-usage-rules-parts.php';
require_once __DIR__ . '/health-contrast-usage-pairings-parts.php';
require_once __DIR__ . '/health-contrast-usage-report-parts.php';
