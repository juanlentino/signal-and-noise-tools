# Admin Tabs IA Reorganization Implementation Plan (v3.8.0)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor the SN Tools plugin admin page from 12 flat top-level tabs into 6 hierarchical tabs with internal-TOC sub-sections, preserving every legacy URL via 301 redirects.

**Architecture:** Single-file refactor of `inc/admin-page.php`. Module hook contracts (`do_action('sn_admin_<slug>_tab')`) stay unchanged — each module file is untouched; only the parent dispatcher changes. New helpers (`sn_admin_render_toc()`, `sn_admin_render_section()`) provide consistent sub-section chrome. Legacy URL redirect map preserves all 12 old `?tab=…` and `?page=sn-…` URLs as 301 redirects to canonical `?tab=<category>#sn-sec-<sub>` destinations.

**Tech Stack:** PHP 8.0+, WordPress block theme + plugin admin UI, no test framework (manual smoke-test verification in wp-admin), no JavaScript changes, no CSS changes.

**Reference spec:** [`docs/superpowers/specs/2026-05-25-admin-tabs-ia-reorganization-design.md`](../specs/2026-05-25-admin-tabs-ia-reorganization-design.md) (commit `26d6532`).

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `inc/admin-page.php` | **Modify** (~+150/-50 LOC, net +100) | Add helpers + new `sn_admin_top_tabs()` data structure + new dispatch arms + legacy redirect map + remove old dispatch arms + update PRG flash redirects |
| `signal-and-noise-tools.php` | **Modify** (1 line) | Bump `Version: 3.7.6` → `3.8.0` |
| `CHANGELOG.md` | **Modify** (~35 lines) | Add v3.8.0 entry at top |

**No other files changed.** Module files (`inc/cloudflare-purge.php`, `inc/plausible-admin.php`, `inc/insights-admin.php`, `inc/admin-tab-dashboard.php`, etc.) stay completely untouched — their hooks fire identically; just dispatched from a different parent.

---

## Verification Approach

This is WordPress admin UI code with no automated test framework. Verification is **manual smoke testing in wp-admin** against the 8 gates from spec Section 4:

| Gate | Manual check |
|---|---|
| G1 | Visit each old URL (12 variants per pattern × 2 patterns = up to 24); confirm 301 redirect lands on canonical tab + correct anchor scroll |
| G2 | Click through each of 6 new top tabs; verify page renders content (TOC + at least one sub-section visible) |
| G3 | Click each TOC anchor in each multi-section tab; verify scroll lands on correct `<section>` |
| G4 | Save Identity form, Login slug form, Cloudflare token form, Plausible token; verify flash notice on right tab |
| G5 | Click each of 12 WP sidebar entries; verify each lands on canonical URL via 301 |
| G6 | Verify Site → Cloudflare sub-section renders CF UI (proves `do_action('sn_admin_cloudflare_tab')` still fires) |
| G7 | Active top-tab visually highlighted; TOC active-section highlight deferred to v3.8.1 |
| G8 | Save Identity → verify "Identity settings saved" flash appears on Site tab after redirect (PRG preservation) |

Each task includes verification steps relevant to that task. The full gate sweep is its own task at the end.

---

## Commit Strategy

**Default: single atomic commit at the end** (`v3.8.0: admin tabs IA reorg — 12 flat → 6 hierarchical`). Reasoning: the refactor needs atomic state — partial state breaks the admin UI between tasks if shipped incrementally.

**Working pattern during development:** complete all tasks locally (multiple `Edit` operations), run the verification gate sweep, then ONE commit + push + tag at the end (Task 6).

**Fallback (only if diff feels unreviewable):** ship per-tab as separate commits within the same feature branch. Each commit individually verifiable. Each individually reversible. PR groups them. NOT the default.

---

## Task 1: Add helpers + new top-tab structure + sidebar entries

**Why this task first:** establishes the new data structures and helpers without breaking the existing behavior. Both old and new tab slugs continue working until Task 3 wires the redirect map.

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php:53-138` (helper section near top)

### Step 1.1: Add `sn_admin_top_tabs()` function

Add this new function in `inc/admin-page.php` immediately after the existing `sn_admin_pages()` function (around line 73, after the closing `}` of `sn_admin_pages()`):

```php
/**
 * The 6 top-level tabs of the SN admin UI (v3.8.0+ IA).
 *
 * Each entry has a `sub_sections` array (may be empty for landing pages
 * with no in-page TOC). Sub-section ordering = display order in the
 * in-page TOC. Slugs are stable URL fragments (`?tab=<top>#sn-sec-<sub>`).
 *
 * @since 3.8.0
 * @return array<int,array<string,mixed>>
 */
function sn_admin_top_tabs() {
    return array(
        array(
            'slug'         => 'sn-theme-options',
            'tab'          => 'dashboard',
            'label'        => 'Dashboard',
            'title'        => 'Signal & Noise — Dashboard',
            'subtitle'     => 'Status overview and maintenance actions.',
            'sub_sections' => array(),
        ),
        array(
            'slug'         => 'sn-site',
            'tab'          => 'site',
            'label'        => 'Site',
            'title'        => 'Signal & Noise — Site',
            'subtitle'     => 'Site identity, social profiles, Open Graph, SEO copy, Cloudflare.',
            'sub_sections' => array(
                'identity'   => array( 'label' => 'Identity' ),
                'social'     => array( 'label' => 'Social' ),
                'open-graph' => array( 'label' => 'Open Graph' ),
                'seo-copy'   => array( 'label' => 'SEO Copy' ),
                'cloudflare' => array( 'label' => 'Cloudflare' ),
            ),
        ),
        array(
            'slug'         => 'sn-security',
            'tab'          => 'security',
            'label'        => 'Security',
            'title'        => 'Signal & Noise — Security',
            'subtitle'     => 'Custom login URL.',
            'sub_sections' => array(
                'login' => array( 'label' => 'Login URL' ),
            ),
        ),
        array(
            'slug'         => 'sn-automation',
            'tab'          => 'automation',
            'label'        => 'Automation',
            'title'        => 'Signal & Noise — Automation',
            'subtitle'     => 'Webhooks and scheduled jobs.',
            'sub_sections' => array(
                'webhooks' => array( 'label' => 'Webhooks' ),
                'cron'     => array( 'label' => 'Cron' ),
            ),
        ),
        array(
            'slug'         => 'sn-monitoring',
            'tab'          => 'monitoring',
            'label'        => 'Monitoring',
            'title'        => 'Signal & Noise — Monitoring',
            'subtitle'     => 'Insights, content health, analytics, RSS subscribers.',
            'sub_sections' => array(
                'insights'  => array( 'label' => 'Insights' ),
                'health'    => array( 'label' => 'Health' ),
                'plausible' => array( 'label' => 'Plausible' ),
                'rss'       => array( 'label' => 'RSS' ),
            ),
        ),
        array(
            'slug'         => 'sn-tools',
            'tab'          => 'tools',
            'label'        => 'Tools',
            'title'        => 'Signal & Noise — Tools',
            'subtitle'     => 'Utility surfaces and external shortcuts.',
            'sub_sections' => array(
                'reading-time' => array( 'label' => 'Reading Time' ),
                'links'        => array( 'label' => 'Links' ),
            ),
        ),
    );
}
```

### Step 1.2: Add `sn_admin_render_toc()` helper

Add this function immediately after `sn_admin_top_tabs()`:

```php
/**
 * Render the in-page TOC for a multi-section top tab. Reads sub-sections
 * from sn_admin_top_tabs() — single source of truth for both display order
 * and anchor labels.
 *
 * Generates: <nav class="sn-toc" aria-label="..."><a href="#sn-sec-X">…</a></nav>
 *
 * No-op for top tabs with no sub-sections (Dashboard).
 *
 * @since 3.8.0
 * @param string $tab_slug The top-tab slug (e.g., 'site', 'security').
 */
function sn_admin_render_toc( $tab_slug ) {
    foreach ( sn_admin_top_tabs() as $top ) {
        if ( $top['tab'] !== $tab_slug ) {
            continue;
        }
        if ( empty( $top['sub_sections'] ) ) {
            return;
        }
        echo '<nav class="sn-toc" aria-label="' . esc_attr( $top['label'] . ' sections' ) . '">';
        echo '<span class="sn-toc-label">Jump to</span>';
        foreach ( $top['sub_sections'] as $sub_slug => $sub ) {
            echo '<a href="#sn-sec-' . esc_attr( $sub_slug ) . '">' . esc_html( $sub['label'] ) . '</a>';
        }
        echo '</nav>';
        return;
    }
}
```

### Step 1.3: Add `sn_admin_render_section()` helper

Add this function immediately after `sn_admin_render_toc()`:

```php
/**
 * Render a sub-section wrapper with anchor target. The callback emits
 * the section's actual content (form fields, hook invocation, etc.).
 *
 * Wraps with .sn-fieldset (matching the existing Identity tab pattern)
 * so existing CSS at admin.css applies without changes. The anchor ID
 * is the structural commitment for the TOC links.
 *
 * For module-hook sub-sections (e.g., Cloudflare), the callback should
 * just `do_action('sn_admin_<slug>_tab')` — the hook listener will
 * emit its own heading + form inside this wrapper.
 *
 * @since 3.8.0
 * @param string   $section_slug Anchor target (e.g., 'identity', 'cloudflare').
 * @param callable $callback     Emits the section body.
 */
function sn_admin_render_section( $section_slug, $callback ) {
    echo '<div class="sn-fieldset" id="sn-sec-' . esc_attr( $section_slug ) . '">';
    call_user_func( $callback );
    echo '</div>';
}
```

### Step 1.4: Update `sn_admin_page_valid_tabs()` to use new structure

Find the existing `sn_admin_page_valid_tabs()` function (line 97-99 of admin-page.php today):

```php
function sn_admin_page_valid_tabs() {
    return array_column( sn_admin_pages(), 'tab' );
}
```

Replace with:

```php
function sn_admin_page_valid_tabs() {
    return array_column( sn_admin_top_tabs(), 'tab' );
}
```

### Step 1.5: Update `sn_admin_page_tab_labels()` to use new structure

Find the existing `sn_admin_page_tab_labels()` function (line 106-108):

```php
function sn_admin_page_tab_labels() {
    return array_column( sn_admin_pages(), 'label', 'tab' );
}
```

Replace with:

```php
function sn_admin_page_tab_labels() {
    return array_column( sn_admin_top_tabs(), 'label', 'tab' );
}
```

### Step 1.6: Verify file parses (no syntax errors)

Run:

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
```

Expected output:
```
No syntax errors detected in /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
```

If any error: read the error line, fix the syntax, re-run until clean.

### Step 1.7: Verify admin page still loads in wp-admin

Visit `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options` (or any current SN admin URL). The page should render without PHP fatal errors. The existing 12 tabs may or may not still be visible (we haven't removed them yet) — the goal of this step is just "no fatal error from the new code being added."

If the page shows a PHP error: read the error message, fix the issue in `inc/admin-page.php`, re-verify.

**No commit yet. Task 1's changes are additive scaffolding for the next tasks.**

---

## Task 2: Add the legacy-to-canonical redirect map + early redirect handler

**Why this task:** Once Task 3+ remove the old top-level tab dispatch arms, those old URLs would 404 (no dispatch arm matches). The redirect map intercepts them BEFORE dispatch and 301s to the canonical destination. This must land BEFORE the dispatch arms change.

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php` (add new function + early-redirect block at top of `sn_theme_options_page()`)

### Step 2.1: Add `sn_admin_legacy_redirect_map()` function

Add this function immediately after `sn_admin_render_section()` (from Task 1):

```php
/**
 * Map of legacy tab slugs (and equivalent page slugs) to their canonical
 * v3.8.0+ destinations: a top tab + anchor.
 *
 * Used by sn_admin_maybe_redirect_legacy() to 301 old URLs to canonical
 * `?tab=<top>#sn-sec-<sub>` destinations.
 *
 * The dashboard slug maps to itself (already canonical) — explicit entry
 * for completeness so the absence-vs-present check is uniform.
 *
 * @since 3.8.0
 * @return array<string,array{tab:string,anchor:?string}>
 */
function sn_admin_legacy_redirect_map() {
    return array(
        'dashboard'    => array( 'tab' => 'dashboard',  'anchor' => null ),
        'identity'     => array( 'tab' => 'site',       'anchor' => 'identity' ),
        'cloudflare'   => array( 'tab' => 'site',       'anchor' => 'cloudflare' ),
        'login'        => array( 'tab' => 'security',   'anchor' => 'login' ),
        'webhooks'     => array( 'tab' => 'automation', 'anchor' => 'webhooks' ),
        'cron'         => array( 'tab' => 'automation', 'anchor' => 'cron' ),
        'insights'     => array( 'tab' => 'monitoring', 'anchor' => 'insights' ),
        'health'       => array( 'tab' => 'monitoring', 'anchor' => 'health' ),
        'plausible'    => array( 'tab' => 'monitoring', 'anchor' => 'plausible' ),
        'rss'          => array( 'tab' => 'monitoring', 'anchor' => 'rss' ),
        'reading-time' => array( 'tab' => 'tools',      'anchor' => 'reading-time' ),
        'links'        => array( 'tab' => 'tools',      'anchor' => 'links' ),
    );
}
```

### Step 2.2: Add `sn_admin_maybe_redirect_legacy()` function

Add this function immediately after `sn_admin_legacy_redirect_map()`:

```php
/**
 * If the current request has a legacy ?tab=<slug> OR is on a legacy
 * ?page=sn-<slug> URL whose tab is no longer top-level, 301-redirect
 * to the canonical destination + URL fragment.
 *
 * Called early in sn_theme_options_page() before any output. Uses raw
 * header() + exit because wp_safe_redirect() strips URL fragments — the
 * fragment is the part that scrolls the page to the right sub-section.
 *
 * Same-host admin URLs are trusted; the redirect destination is always
 * constructed from a fixed allow-listed top-tab whitelist, never from
 * user input.
 *
 * @since 3.8.0
 */
function sn_admin_maybe_redirect_legacy() {
    $top_tabs = array_column( sn_admin_top_tabs(), 'tab' );
    $map      = sn_admin_legacy_redirect_map();

    // Source 1: explicit ?tab=<slug>
    $requested_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

    // Source 2: derive from ?page=sn-<slug>
    if ( ! $requested_tab && isset( $_GET['page'] ) ) {
        $current_slug = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        // sn_admin_page_tab_for_slug() returns the tab name for a given
        // legacy page slug; falls back to 'dashboard' for unknowns.
        $requested_tab = sn_admin_page_tab_for_slug( $current_slug );
    }

    if ( ! $requested_tab ) {
        return;  // No tab in URL; default dispatcher will use 'dashboard'.
    }

    // If the requested tab is already a NEW top tab, nothing to redirect.
    if ( in_array( $requested_tab, $top_tabs, true ) ) {
        return;
    }

    // If it's a legacy slug, look up canonical destination.
    if ( ! isset( $map[ $requested_tab ] ) ) {
        return;  // Unknown slug; let the dispatcher fall through to dashboard.
    }

    $canonical = $map[ $requested_tab ];
    $url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $canonical['tab'] ) );
    if ( $canonical['anchor'] ) {
        $url .= '#sn-sec-' . rawurlencode( $canonical['anchor'] );
    }

    // Raw header() because wp_safe_redirect() strips the fragment.
    // Same-host admin URL, no user input in destination → safe.
    header( 'Location: ' . $url, true, 301 );
    exit;
}
```

### Step 2.3: Wire the early redirect into `sn_theme_options_page()`

Find the existing `sn_theme_options_page()` function (line 414 of admin-page.php). It currently starts with:

```php
function sn_theme_options_page() {
    // Defense-in-depth capability check...
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
    }

    $theme         = wp_get_theme( 'signal-and-noise' );
    ...
```

Insert the redirect call AFTER the capability check but BEFORE any output. Modify to:

```php
function sn_theme_options_page() {
    // Defense-in-depth capability check...
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
    }

    // v3.8.0+: 301-redirect legacy tab/page slugs to canonical destinations.
    // Must run BEFORE any output so headers can still be sent.
    sn_admin_maybe_redirect_legacy();

    $theme         = wp_get_theme( 'signal-and-noise' );
    ...
```

### Step 2.4: Verify file parses

Run:

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
```

Expected: `No syntax errors detected`.

### Step 2.5: Manual verification — legacy URL redirects work

Visit `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=login` in your browser.

**Expected:**
- URL bar updates to `…&tab=security#sn-sec-login` (the 301 fires; browser follows)
- Page renders SOMETHING (may be a confusing state since the new dispatch arms don't exist yet — that's OK at this stage)

**Don't worry if the page content looks broken** — Task 3 fixes the dispatch. We're only verifying the redirect header itself fires.

Also try `https://juanlentino.com/wp-admin/admin.php?page=sn-login` (per-slug URL).

Expected: same — URL bar updates to canonical destination.

If redirect doesn't fire: check `sn_admin_maybe_redirect_legacy()` is called in `sn_theme_options_page()`; check `php -l` is clean; check there's no leading output before the header() call (PHP would warn "headers already sent").

**No commit yet.**

---

## Task 3: Rewrite tab dispatch — all 6 new tabs in one diff

**Why one diff for all 6 tabs:** the existing dispatch (lines 567-983 of admin-page.php) is a single `if/elseif` chain. Removing old arms and adding new arms must happen atomically — otherwise some tabs work, some don't.

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php:567-983` (the entire dispatch section + Identity inline render + Login inline render + Links inline render)

### Step 3.1: Read the current dispatch + inline renders

Before editing, re-read the current dispatch logic to ensure you understand what's being moved:

```bash
sed -n '567,983p' /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php > /tmp/old-dispatch.txt
wc -l /tmp/old-dispatch.txt
```

Expected: ~417 lines. Open `/tmp/old-dispatch.txt` and skim — you should see:
- 12 `} elseif ( '<slug>' === $active_tab ) {` arms
- 3 with inline rendering (Identity ~180 lines, Login ~85 lines, Links ~40 lines)
- 9 that just call `do_action('sn_admin_<slug>_tab')`

### Step 3.2: Replace the entire dispatch block

Find the line `// TAB: DASHBOARD` (around line 567) — that's the start of the dispatch. Find the line `} // ifelse on active_tab chain end` — that's the end. (If no such comment exists, look for the `echo '</div>'; // wrap` line at the very end of `sn_theme_options_page()`, around line 982.)

Replace EVERYTHING between (and including) the first `if ( 'dashboard' === $active_tab ) {` and the corresponding final `}` of the chain (just before `echo '</div>'; // wrap`) with:

```php
        // ════════════════════════════════════════
        // TAB: DASHBOARD (landing — no sub-sections)
        // ════════════════════════════════════════
        if ( 'dashboard' === $active_tab ) {

            do_action( 'sn_admin_dashboard_extras' );

        // ════════════════════════════════════════
        // TAB: SITE
        // Sub-sections: identity, social, open-graph, seo-copy, cloudflare
        // ════════════════════════════════════════
        } elseif ( 'site' === $active_tab ) {

            sn_admin_render_toc( 'site' );

            // The Identity form wraps 4 sub-sections (one Save button saves
            // all 4 fieldsets — preserves the existing single-form UX).
            // Cloudflare is rendered AFTER the form close (it has its own
            // form inside its hook).
            echo '<form method="post" class="sn-identity-form">';
            wp_nonce_field( 'sn_theme_options_nonce' );
            echo '<input type="hidden" name="sn_action" value="save_identity">';

            sn_admin_render_section( 'identity', function() {
                echo '<h2 class="sn-fieldset-h">Identity</h2>';
                echo '<p class="sn-fieldset-intro">Site-wide name, description, and locale.</p>';

                echo '<div class="sn-field sn-field-w-md">';
                echo '<label class="sn-field-label" for="sn_identity_site_name">Site name</label>';
                echo '<input type="text" id="sn_identity_site_name" name="identity_site_name" value="' . esc_attr( sn_setting( 'identity.site_name', '' ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_identity_site_description">Site description</label>';
                echo '<textarea id="sn_identity_site_description" name="identity_site_description" rows="2">' . esc_textarea( (string) sn_setting( 'identity.site_description', '' ) ) . '</textarea>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-md">';
                echo '<label class="sn-field-label" for="sn_identity_person_name">Person name (schema author)</label>';
                echo '<input type="text" id="sn_identity_person_name" name="identity_person_name" value="' . esc_attr( sn_setting( 'identity.person_name', '' ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-md">';
                echo '<label class="sn-field-label" for="sn_identity_job_title">Job title</label>';
                echo '<input type="text" id="sn_identity_job_title" name="identity_job_title" value="' . esc_attr( sn_setting( 'identity.job_title', 'Music Producer' ) ) . '" placeholder="Music Producer">';
                echo '<p class="sn-field-helper">Emitted as <code>jobTitle</code> on the Person schema. Single short phrase.</p>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_identity_knows_about">Knows about</label>';
                $knows_about_value = (array) sn_setting(
                    'identity.knows_about',
                    array( 'Music Production', 'Audio Engineering', 'Provenance', 'Music Industry' )
                );
                echo '<textarea id="sn_identity_knows_about" name="identity_knows_about" rows="4">' . esc_textarea( implode( "\n", $knows_about_value ) ) . '</textarea>';
                echo '<p class="sn-field-helper">One topic per line. Emitted as the <code>knowsAbout</code> array on the Person schema — domain expertise areas that signal to search engines what this person is about. Leave a line blank to omit the entry.</p>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xs">';
                echo '<label class="sn-field-label" for="sn_identity_locale">Locale</label>';
                echo '<input type="text" id="sn_identity_locale" name="identity_locale" value="' . esc_attr( sn_setting( 'identity.locale', 'en_US' ) ) . '" placeholder="en_US">';
                echo '<p class="sn-field-helper">WP locale code (e.g. <code>en_US</code>). Used for og:locale and schema inLanguage.</p>';
                echo '</div>';
            } );

            sn_admin_render_section( 'social', function() {
                echo '<h2 class="sn-fieldset-h">Social</h2>';
                echo '<p class="sn-fieldset-intro">Twitter / X handle and profile URLs (emitted as schema sameAs).</p>';

                echo '<div class="sn-field sn-field-w-sm">';
                echo '<label class="sn-field-label" for="sn_social_twitter_handle">Twitter / X handle</label>';
                echo '<input type="text" id="sn_social_twitter_handle" name="social_twitter_handle" value="' . esc_attr( sn_setting( 'social.twitter_handle', '' ) ) . '" placeholder="@username">';
                echo '<p class="sn-field-helper">Used as twitter:site and twitter:creator. Include the @ prefix.</p>';
                echo '</div>';

                $same_as = (array) sn_setting( 'social.same_as', array() );
                echo '<div class="sn-field">';
                echo '<label class="sn-field-label">Profile URLs (sameAs)</label>';
                echo '<div class="sn-sameas">';
                foreach ( $same_as as $url ) {
                    echo '<input type="url" name="social_same_as[]" value="' . esc_attr( (string) $url ) . '" placeholder="https://...">';
                }
                echo '<button type="button" class="sn-add-row-btn" aria-label="Add another profile URL row">Add another profile URL</button>';
                echo '<noscript>';
                echo '<input type="url" name="social_same_as[]" value="" placeholder="https://..." class="sn-sameas-extra">';
                echo '</noscript>';
                echo '</div>'; // .sn-sameas
                echo '<p class="sn-field-helper">Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.</p>';
                echo '</div>';
            } );

            sn_admin_render_section( 'open-graph', function() {
                echo '<h2 class="sn-fieldset-h">Open Graph</h2>';
                echo '<p class="sn-fieldset-intro">Fallback OG image and card dimensions for social shares.</p>';

                echo '<div class="sn-field sn-field-w-lg">';
                echo '<label class="sn-field-label" for="sn_og_default_image_url">Default OG image URL</label>';
                echo '<input type="url" id="sn_og_default_image_url" name="og_default_image_url" value="' . esc_attr( (string) sn_setting( 'og.default_image_url', '' ) ) . '">';
                echo '<p class="sn-field-helper">Fallback image used when no per-post OG card exists.</p>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xs">';
                echo '<label class="sn-field-label" for="sn_og_card_width">Card width (px)</label>';
                echo '<input type="number" min="1" id="sn_og_card_width" name="og_card_width" value="' . esc_attr( (string) sn_setting( 'og.card_width', 1200 ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xs">';
                echo '<label class="sn-field-label" for="sn_og_card_height">Card height (px)</label>';
                echo '<input type="number" min="1" id="sn_og_card_height" name="og_card_height" value="' . esc_attr( (string) sn_setting( 'og.card_height', 630 ) ) . '">';
                echo '</div>';
            } );

            sn_admin_render_section( 'seo-copy', function() {
                echo '<h2 class="sn-fieldset-h">SEO Copy</h2>';
                echo '<p class="sn-fieldset-intro">Per-route title + description for the home, /notes, and /provenance pages.</p>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_home_title">Home title</label>';
                echo '<input type="text" id="sn_seo_home_title" name="seo_home_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.home_title', '' ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_home_description">Home description</label>';
                echo '<textarea id="sn_seo_home_description" name="seo_home_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.home_description', '' ) ) . '</textarea>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_notes_title">/notes title</label>';
                echo '<input type="text" id="sn_seo_notes_title" name="seo_notes_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.notes_title', '' ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_notes_description">/notes description</label>';
                echo '<textarea id="sn_seo_notes_description" name="seo_notes_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.notes_description', '' ) ) . '</textarea>';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_provenance_title">/provenance title</label>';
                echo '<input type="text" id="sn_seo_provenance_title" name="seo_provenance_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.provenance_title', '' ) ) . '">';
                echo '</div>';

                echo '<div class="sn-field sn-field-w-xl">';
                echo '<label class="sn-field-label" for="sn_seo_provenance_description">/provenance description</label>';
                echo '<textarea id="sn_seo_provenance_description" name="seo_provenance_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.provenance_description', '' ) ) . '</textarea>';
                echo '</div>';
            } );

            // Sticky save bar — saves Identity / Social / OG / SEO Copy (the 4 above).
            // Cloudflare's save is separate (its own form, its own hook).
            echo '<div class="sn-savebar">';
            echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
            echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
            echo '</div>';
            echo '</form>';

            // Cloudflare sub-section — module-owned (inc/cloudflare-purge.php)
            sn_admin_render_section( 'cloudflare', function() {
                do_action( 'sn_admin_cloudflare_tab' );
            } );

        // ════════════════════════════════════════
        // TAB: SECURITY
        // Sub-sections: login (audit-log added in v3.8.1)
        // ════════════════════════════════════════
        } elseif ( 'security' === $active_tab ) {

            sn_admin_render_toc( 'security' );

            sn_admin_render_section( 'login', function() {
                // Detect module state. Three possibilities:
                //   1. ACTIVE: our login-hide.php is firing (no wps-hide-login,
                //      no SN_LOGIN_BYPASS)
                //   2. DORMANT (conflict): wps-hide-login is still active so
                //      our module stood down
                //   3. DORMANT (bypass): SN_LOGIN_BYPASS constant is set
                if ( ! function_exists( 'is_plugin_active' ) ) {
                    include_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $wps_basename = 'wps-hide-login/wps-hide-login.php';
                $wps_active   = is_plugin_active( $wps_basename ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
                $bypassed     = defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS;
                $slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' );
                $slug_const   = defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG;
                $login_url    = home_url( '/' . $slug );

                echo '<p class="sn-prose">Custom login URL module — replaces <code>/wp-login.php</code> with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).</p>';

                // Status box
                if ( $bypassed ) {
                    echo '<div class="sn-status-box sn-status-box--warn">';
                    echo '<div>';
                    echo '<p class="sn-status-box-title">Module bypassed</p>';
                    echo '<p class="sn-status-box-body">The <code>SN_LOGIN_BYPASS</code> constant is set in <code>wp-config.php</code>. Default <code>/wp-login.php</code> behavior is restored. Remove the constant to re-enable.</p>';
                    echo '</div>';
                    echo '<span class="sn-pill sn-pill--warn">Bypassed</span>';
                    echo '</div>';
                } elseif ( $wps_active ) {
                    echo '<div class="sn-status-box sn-status-box--warn">';
                    echo '<div>';
                    echo '<p class="sn-status-box-title">Module dormant — conflict with wps-hide-login</p>';
                    echo '<p class="sn-status-box-body">The <code>wps-hide-login</code> plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.</p>';
                    echo '</div>';
                    echo '<span class="sn-pill sn-pill--warn">Dormant</span>';
                    echo '</div>';
                } else {
                    echo '<div class="sn-status-box">';
                    echo '<div>';
                    echo '<p class="sn-status-box-title">Module active</p>';
                    echo '<p class="sn-status-box-body">Direct visits to <code>/wp-login.php</code> and unauthenticated <code>/wp-admin</code> return 404. Login form reachable at the custom URL below.</p>';
                    echo '</div>';
                    echo '<span class="sn-pill sn-pill--ok">Active</span>';
                    echo '</div>';
                }

                echo '<form method="post">';
                wp_nonce_field( 'sn_theme_options_nonce' );
                echo '<input type="hidden" name="sn_action" value="save_login">';

                echo '<h2 class="sn-fieldset-h">Custom login slug</h2>';
                echo '<p class="sn-fieldset-intro">The path segment used in place of <code>wp-login.php</code>.</p>';

                echo '<div class="sn-field sn-field-w-sm">';
                echo '<label class="sn-field-label" for="sn_login_slug">Slug</label>';
                if ( $slug_const ) {
                    echo '<input type="text" id="sn_login_slug" value="' . esc_attr( $slug ) . '" disabled>';
                    echo '<p class="sn-field-helper"><strong>Locked.</strong> The <code>SN_LOGIN_SLUG</code> constant in <code>wp-config.php</code> is overriding this field. Remove the constant to edit here.</p>';
                } else {
                    echo '<input type="text" id="sn_login_slug" name="login_slug" value="' . esc_attr( $slug ) . '" placeholder="sn-login">';
                    echo '<p class="sn-field-helper">Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).</p>';
                }
                echo '</div>';

                echo '<div class="sn-field">';
                echo '<label class="sn-field-label">Current login URL</label>';
                echo '<a class="sn-url-preview" href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>';
                echo '<p class="sn-field-helper">Bookmark this URL. The default <code>/wp-login.php</code> 404s for unauthenticated visitors.</p>';
                echo '</div>';

                echo '<div class="sn-fieldset-actions">';
                if ( $slug_const ) {
                    echo '<p class="sn-fieldset-actions-hint">Slug locked by <code>SN_LOGIN_SLUG</code> constant.</p>';
                }
                echo '<button type="submit" class="button button-primary"' . ( $slug_const ? ' disabled' : '' ) . '>Save</button>';
                echo '</div>';

                echo '</form>';

                // Emergency unlock docs (out-of-form, no submission)
                echo '<div class="sn-callout">';
                echo '<p class="sn-callout-h">Emergency unlock</p>';
                echo '<p>If you ever lock yourself out (forgot the slug, can\'t reach the login form), add either of these constants to <code>wp-config.php</code> via SSH or your host\'s file manager:</p>';
                echo '<pre>// Option 1 — pin the slug. Reachable at /&lt;slug-here&gt;.
define( \'SN_LOGIN_SLUG\', \'your-fallback-slug\' );

// Option 2 — disable the module entirely. Restores /wp-login.php.
define( \'SN_LOGIN_BYPASS\', true );</pre>';
                echo '<p>The constants take priority over the setting and persist across plugin updates. Remove them once you\'ve regained access.</p>';
                echo '</div>';
            } );

        // ════════════════════════════════════════
        // TAB: AUTOMATION
        // Sub-sections: webhooks, cron
        // ════════════════════════════════════════
        } elseif ( 'automation' === $active_tab ) {

            sn_admin_render_toc( 'automation' );

            sn_admin_render_section( 'webhooks', function() {
                do_action( 'sn_admin_webhooks_tab' );
            } );

            sn_admin_render_section( 'cron', function() {
                do_action( 'sn_admin_cron_tab' );
            } );

        // ════════════════════════════════════════
        // TAB: MONITORING
        // Sub-sections: insights, health, plausible, rss
        // ════════════════════════════════════════
        } elseif ( 'monitoring' === $active_tab ) {

            sn_admin_render_toc( 'monitoring' );

            sn_admin_render_section( 'insights', function() {
                do_action( 'sn_admin_insights_tab' );
            } );

            sn_admin_render_section( 'health', function() {
                do_action( 'sn_admin_health_tab' );
            } );

            sn_admin_render_section( 'plausible', function() {
                do_action( 'sn_admin_plausible_tab' );
            } );

            sn_admin_render_section( 'rss', function() {
                if ( has_action( 'sn_admin_rss_tab' ) ) {
                    do_action( 'sn_admin_rss_tab' );
                } else {
                    echo '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS subscriber tracker not installed.</strong></p>';
                    echo '<p>Copy <code>mu-plugins/rss-plausible-tracker.php</code> from the theme repo to <code>wp-content/mu-plugins/</code> on this host. MU plugins activate automatically — no further action needed.</p></div>';
                }
            } );

        // ════════════════════════════════════════
        // TAB: TOOLS
        // Sub-sections: reading-time, links
        // ════════════════════════════════════════
        } elseif ( 'tools' === $active_tab ) {

            sn_admin_render_toc( 'tools' );

            sn_admin_render_section( 'reading-time', function() {
                do_action( 'sn_admin_reading_time_tab' );
            } );

            sn_admin_render_section( 'links', function() {
                $link_groups = array(
                    array(
                        'label' => 'Source code',
                        'links' => array(
                            array( 'title' => 'Theme repo',  'href' => 'https://github.com/juanlentino/signal-and-noise' ),
                            array( 'title' => 'Plugin repo', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools' ),
                        ),
                    ),
                    array(
                        'label' => 'Releases',
                        'links' => array(
                            array( 'title' => 'Theme releases',  'href' => 'https://github.com/juanlentino/signal-and-noise/releases' ),
                            array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/releases' ),
                        ),
                    ),
                    array(
                        'label' => 'Infrastructure',
                        'links' => array(
                            array( 'title' => 'Cloudflare dashboard', 'href' => 'https://dash.cloudflare.com' ),
                            array( 'title' => 'Cloudways platform',   'href' => 'https://platform.cloudways.com' ),
                        ),
                    ),
                );
                echo '<div class="sn-link-grid">';
                foreach ( $link_groups as $group ) {
                    foreach ( $group['links'] as $link ) {
                        $host = (string) wp_parse_url( $link['href'], PHP_URL_HOST );
                        echo '<div class="sn-link-card">';
                        echo '<span class="sn-link-card__label">' . esc_html( $group['label'] ) . '</span>';
                        echo '<span class="sn-link-card__title">' . esc_html( $link['title'] ) . '</span>';
                        echo '<span class="sn-link-card__host">' . esc_html( $host ) . ' &#x2197;</span>';
                        echo '<a class="sn-link-card__link" href="' . esc_url( $link['href'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['title'] ) . '</a>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            } );

        }
```

### Step 3.3: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
```

Expected: `No syntax errors detected`. If error: read the line, fix syntax, re-run.

### Step 3.4: Manual verification — every new tab renders

Visit each of the 6 new top tabs in wp-admin and verify content renders:

1. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=dashboard` — dashboard renders
2. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=site` — TOC visible, 5 sub-sections present (Identity, Social, Open Graph, SEO Copy, Cloudflare)
3. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=security` — TOC visible, Login URL section present
4. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=automation` — TOC visible, Webhooks + Cron sections present
5. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=monitoring` — TOC visible, Insights + Health + Plausible + RSS sections present
6. `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=tools` — TOC visible, Reading Time + Links sections present

For each tab, click each TOC anchor link and verify the page scrolls to the correct section.

If any tab renders empty or with a PHP warning: read the warning, fix the issue, re-verify.

### Step 3.5: Manual verification — old URLs redirect correctly

Visit each legacy URL and verify it 301-redirects + lands on correct sub-section:

| Old URL | Should land on |
|---|---|
| `?tab=identity` | `?tab=site#sn-sec-identity` |
| `?tab=cloudflare` | `?tab=site#sn-sec-cloudflare` |
| `?tab=login` | `?tab=security#sn-sec-login` |
| `?tab=webhooks` | `?tab=automation#sn-sec-webhooks` |
| `?tab=cron` | `?tab=automation#sn-sec-cron` |
| `?tab=insights` | `?tab=monitoring#sn-sec-insights` |
| `?tab=health` | `?tab=monitoring#sn-sec-health` |
| `?tab=plausible` | `?tab=monitoring#sn-sec-plausible` |
| `?tab=rss` | `?tab=monitoring#sn-sec-rss` |
| `?tab=reading-time` | `?tab=tools#sn-sec-reading-time` |
| `?tab=links` | `?tab=tools#sn-sec-links` |

Same for `?page=sn-<slug>` URLs.

If any redirect lands on the wrong section: check the redirect map in `sn_admin_legacy_redirect_map()` (Task 2.1) — anchor field should match the sub-section slug.

**No commit yet. Task 4 fixes flash redirects.**

---

## Task 4: Update flash-message PRG redirects to new canonical destinations

**Why:** After a form save, `sn_handle_admin_post()` issues a `wp_safe_redirect()` back to the admin page so the flash notice renders on GET. Today it preserves `$_REQUEST['tab']` — but if that's an OLD slug (e.g., `login`), the redirect will go to the OLD URL → which then 301-redirects to canonical (so it works), but it's a wasted round-trip and the URL the user sees in the address bar momentarily shows the old slug. Cleaner: redirect directly to the new canonical.

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php:393-411` (the PRG redirect at end of `sn_handle_admin_post()`)

### Step 4.1: Locate the PRG redirect block

Find the end of `sn_handle_admin_post()` function in `inc/admin-page.php`. The relevant block is around lines 393-411:

```php
    $redirect_args = array(
        'page'     => $current_page,
        'sn_flash' => $flash,
    );
    // Preserve v1.8.x-style ?tab=… so legacy bookmarks survive PRG.
    if ( isset( $_REQUEST['tab'] ) ) {
        $redirect_args['tab'] = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
    }
    $redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
    wp_safe_redirect( $redirect_url );
    exit;
```

### Step 4.2: Replace the PRG redirect block

Replace the block from Step 4.1 with:

```php
    $redirect_args = array(
        'page'     => $current_page,
        'sn_flash' => $flash,
    );

    // v3.8.0+: redirect to canonical top-tab + anchor (instead of legacy
    // tab slug). The legacy tab slug from the form POST is mapped to its
    // canonical destination via sn_admin_legacy_redirect_map() — if it's
    // a known legacy slug, the canonical top-tab replaces it. Anchor goes
    // in the URL fragment (preserved by raw header() — wp_safe_redirect()
    // would strip it).
    $anchor = '';
    if ( isset( $_REQUEST['tab'] ) ) {
        $requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
        $map           = sn_admin_legacy_redirect_map();
        $top_tabs      = array_column( sn_admin_top_tabs(), 'tab' );

        if ( in_array( $requested_tab, $top_tabs, true ) ) {
            // Already a canonical top tab; pass through.
            $redirect_args['tab'] = $requested_tab;
        } elseif ( isset( $map[ $requested_tab ] ) ) {
            // Legacy slug; rewrite to canonical destination.
            $redirect_args['tab'] = $map[ $requested_tab ]['tab'];
            if ( $map[ $requested_tab ]['anchor'] ) {
                $anchor = '#sn-sec-' . rawurlencode( $map[ $requested_tab ]['anchor'] );
            }
        } else {
            // Unknown slug; fall back to dashboard.
            $redirect_args['tab'] = 'dashboard';
        }
    }

    $redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

    // Raw header() because wp_safe_redirect() strips URL fragments.
    // Destination is admin_url() (same-host, trusted) with sanitized
    // top-tab name from a fixed allowlist — safe.
    header( 'Location: ' . $redirect_url, true, 302 );  // 302 not 301: this is a transient post-save redirect, not a "moved permanently" signal.
    exit;
```

### Step 4.3: Verify file parses

```bash
php -l /Users/juanlentino/projects/signal-and-noise-tools/inc/admin-page.php
```

Expected: `No syntax errors detected`.

### Step 4.4: Manual verification — form saves redirect cleanly

In wp-admin:

1. Navigate to `?tab=site` → scroll to Identity section → change "Site name" → click "Save Identity Settings"
2. **Expected:** URL bar after save shows `…&tab=site#sn-sec-identity` (or similar canonical form); green success notice "Identity settings saved." appears at top of page.

3. Navigate to `?tab=security` → scroll to Login URL → change slug → click Save
4. **Expected:** URL bar shows `…&tab=security#sn-sec-login`; green "Login slug saved. New URL: …" notice appears.

5. Navigate to `?tab=site#sn-sec-cloudflare` → scroll to Cloudflare → "Save Cloudflare Settings"
6. **Expected:** URL bar shows `…&tab=site#sn-sec-cloudflare`; "Cloudflare settings saved." notice appears.

If any save lands on wrong tab or shows wrong notice: trace through `sn_handle_admin_post()` — the `$current_page` variable + the new redirect logic should match expectations.

**No commit yet. Task 5 is the verification sweep.**

---

## Task 5: Run full verification gate sweep

**Why:** before bumping the version + tagging, run through every gate explicitly per spec Section 4. Document any anomalies; if any fail, fix-forward in Task 5.X before proceeding to Task 6.

**Files:**
- No file changes. Verification only.

### Step 5.1: Gate G1 — all 12 legacy URLs 301-redirect

Visit each in your browser:

```
?tab=identity        → expect 301 to ?tab=site#sn-sec-identity
?tab=cloudflare      → expect 301 to ?tab=site#sn-sec-cloudflare
?tab=login           → expect 301 to ?tab=security#sn-sec-login
?tab=webhooks        → expect 301 to ?tab=automation#sn-sec-webhooks
?tab=cron            → expect 301 to ?tab=automation#sn-sec-cron
?tab=insights        → expect 301 to ?tab=monitoring#sn-sec-insights
?tab=health          → expect 301 to ?tab=monitoring#sn-sec-health
?tab=plausible       → expect 301 to ?tab=monitoring#sn-sec-plausible
?tab=rss             → expect 301 to ?tab=monitoring#sn-sec-rss
?tab=reading-time    → expect 301 to ?tab=tools#sn-sec-reading-time
?tab=links           → expect 301 to ?tab=tools#sn-sec-links
?tab=dashboard       → no redirect (already canonical)
```

And the equivalents with `?page=sn-<slug>`:

```
?page=sn-identity    → expect 301 to ?page=sn-theme-options&tab=site#sn-sec-identity
?page=sn-login       → expect 301 to ?page=sn-theme-options&tab=security#sn-sec-login
...etc
```

**Pass criterion:** every legacy URL lands on its canonical destination + browser scrolls to the correct sub-section.

### Step 5.2: Gate G2 — all 6 new tabs render content

Visit each:

```
?tab=dashboard       → dashboard content visible
?tab=site            → TOC + 5 sub-sections visible
?tab=security        → TOC + Login URL section visible
?tab=automation      → TOC + Webhooks + Cron sub-sections visible
?tab=monitoring      → TOC + Insights + Health + Plausible + RSS sub-sections visible
?tab=tools           → TOC + Reading Time + Links sub-sections visible
```

**Pass criterion:** each tab renders content (no blank pages, no PHP warnings/errors).

### Step 5.3: Gate G3 — all 14 TOC anchors scroll correctly

On each multi-section tab, click each TOC anchor:

- Site: identity, social, open-graph, seo-copy, cloudflare (5)
- Security: login (1)
- Automation: webhooks, cron (2)
- Monitoring: insights, health, plausible, rss (4)
- Tools: reading-time, links (2)

Total: 14 anchor clicks.

**Pass criterion:** each click scrolls the page to the correct sub-section heading.

### Step 5.4: Gate G4 — settings saves work

Save these forms; verify flash notices appear correctly:

- Site → Identity section → change Site name → Save → "Identity settings saved." flash
- Security → Login URL → change slug → Save → "Login slug saved." flash
- Site → Cloudflare → change a setting → Save → "Cloudflare settings saved." flash
- Monitoring → Plausible → save token → "Stats API key saved." flash

**Pass criterion:** every save fires its corresponding flash on the correct landing tab.

### Step 5.5: Gate G5 — WP sidebar entries route correctly

Click each of the 12 SN entries in the WP admin left sidebar. Verify each lands on its canonical sub-section.

**Pass criterion:** every sidebar click ends up on the correct canonical URL via 301.

### Step 5.6: Gate G6 — module hooks still fire

Specifically verify Site → Cloudflare renders the Cloudflare form (proves `do_action('sn_admin_cloudflare_tab')` still fires from the new dispatch parent).

Also verify Monitoring → Insights renders the Insights UI (proves `sn_admin_insights_tab` still fires).

**Pass criterion:** module-hook-rendered sub-sections show their content.

### Step 5.7: Gate G7 — active-tab visual state

On each of the 6 top tabs, the corresponding top-tab in the `.nav-tab-wrapper` should be highlighted as active.

**Pass criterion:** correct top-tab is visually `.nav-tab-active`. (TOC anchor active-state is OUT OF SCOPE for v3.8.0; defer to v3.8.1+.)

### Step 5.8: Gate G8 — PRG flash preservation across redirect

Save Identity → URL changes to `?tab=site#sn-sec-identity` AND the green "Identity settings saved." notice appears at top of page.

**Pass criterion:** flash notice appears on the right tab after the redirect.

### Step 5.9: Fix-forward any gate failures

If any gate fails: identify the failing case, fix the issue inline in `inc/admin-page.php`, re-verify just that gate. Don't proceed to Task 6 until all 8 gates pass.

---

## Task 6: Bump version, write CHANGELOG, commit, tag, push

**Why:** atomic ship per CLAUDE.md project workflow. Single commit captures the entire v3.8.0 refactor.

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php:6` (Version header)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/CHANGELOG.md` (new entry at top)

### Step 6.1: Bump the Version header

Edit `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php` line 6:

```
 * Version:     3.7.6
```

to:

```
 * Version:     3.8.0
```

### Step 6.2: Write CHANGELOG entry

Edit `/Users/juanlentino/projects/signal-and-noise-tools/CHANGELOG.md` — insert this new entry IMMEDIATELY AFTER the existing `All notable changes…` line and BEFORE `## [3.7.6]`:

```markdown
## [3.8.0] - 2026-05-25

### Changed — Admin tabs reorganized from 12 flat → 6 hierarchical

Major IA refactor of the SN Tools admin page. 12 flat top-level tabs (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Cron, Webhooks, Insights, Health, Links) consolidate into **6 hierarchical tabs**:

```
Dashboard │ Site │ Security │ Automation │ Monitoring │ Tools
```

Each multi-section tab uses the internal-TOC anchor pattern (already proven on the Identity tab) for sub-section navigation. **14 sub-sections** distributed across the 6 top tabs. Within the 5-7 magic number for top-level nav.

**Architectural property: module hook contracts unchanged.** Each module's `do_action('sn_admin_<slug>_tab')` still fires identically; only the parent dispatcher changes. Refactor contained entirely to `inc/admin-page.php` — zero LOC in any module file.

**Backward compatibility:** all 12 legacy `?tab=<slug>` and `?page=sn-<slug>` URLs 301-redirect to canonical `?tab=<category>#sn-sec-<sub>` destinations. WP sidebar keeps all 12 entries as direct-jump shortcuts (different optimization surface than in-page tabs). Existing bookmarks survive; browsers update them over time per 301 semantics.

**Two new helpers added:**
- `sn_admin_render_toc( $tab_slug )` — generates the in-page anchor nav for multi-section tabs
- `sn_admin_render_section( $section_slug, $callback )` — wraps content in anchor target

**Patch cap status:** plugin v3.7.x hit 7/7 patches; this is the cap-rollover minor bump.

### What's NOT in this release
- **No new functionality.** Refactor only.
- **No CSS changes.** Existing `assets/admin.css` already supports the patterns used.
- **No JavaScript changes.** Anchor scroll is browser-native.
- **No data-schema changes.** `sn_settings` schema unchanged.

### Coming in v3.8.1
- Login hardening: counter-based audit log under Security → Audit log sub-section. Designed but not implemented (paused brainstorm Section 1).

```

### Step 6.3: Verify Version header + CHANGELOG syntax

```bash
grep -E "^[[:space:]]*\*?[[:space:]]*Version:" /Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php
```

Expected: ` * Version:     3.8.0`.

```bash
head -10 /Users/juanlentino/projects/signal-and-noise-tools/CHANGELOG.md
```

Expected: shows the new `## [3.8.0] - 2026-05-25` entry before `## [3.7.6]`.

### Step 6.4: Verify git status is clean except for intended changes

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools
git status
```

Expected: only 3 modified files:
- `inc/admin-page.php`
- `signal-and-noise-tools.php`
- `CHANGELOG.md`

No other modified files. If others appear: investigate; you may have accidentally edited something.

### Step 6.5: Verify remote URL is clean (no PAT)

```bash
git -C /Users/juanlentino/projects/signal-and-noise-tools remote -v | head -2
```

Expected: `origin  https://github.com/juanlentino/signal-and-noise-tools.git` for both fetch + push, no token in URL.

### Step 6.6: Commit, push, tag, push tag

Run all in one chained command:

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools && \
git add inc/admin-page.php signal-and-noise-tools.php CHANGELOG.md && \
git commit -m "$(cat <<'EOF'
v3.8.0: admin tabs IA reorg — 12 flat → 6 hierarchical

Refactor of the SN Tools admin page from 12 flat top-level tabs to 6
hierarchical tabs with internal-TOC sub-sections. Dashboard, Site,
Security, Automation, Monitoring, Tools. 14 sub-sections distributed
across the 6 top tabs.

Architecturally clean: module hook contracts (do_action('sn_admin_*_tab'))
are unchanged. Each module file is completely untouched; only the
parent dispatcher in inc/admin-page.php changes. Zero LOC in any
module file.

Two new helpers (sn_admin_render_toc, sn_admin_render_section) keep
the dispatch readable across the 5 multi-section tabs. The internal-TOC
pattern was already proven on the Identity tab; now applied
consistently.

All 12 legacy URLs (?tab=<slug> and ?page=sn-<slug>) 301-redirect to
canonical ?tab=<category>#sn-sec-<sub> destinations. WP sidebar keeps
all 12 entries as direct-jump shortcuts. Existing bookmarks survive;
browsers update them via 301 semantics.

Patch cap: v3.7.x hit 7/7. This is the mandatory minor bump.

Spec: docs/superpowers/specs/2026-05-25-admin-tabs-ia-reorganization-design.md
Plan: docs/superpowers/plans/2026-05-25-admin-tabs-ia-reorganization-v3.8.0.md

Verification: all 8 gates per spec Section 4 passed manually in
wp-admin before commit.
EOF
)" && \
git push origin HEAD:main && \
git tag -a v3.8.0 -m "v3.8.0 — admin tabs IA reorg (12 flat → 6 hierarchical)" && \
git push origin v3.8.0
```

Expected output: commit succeeds, push to main succeeds, tag creation succeeds, tag push succeeds.

If commit fails (e.g., pre-commit hook): read the hook output, fix the issue, retry.

### Step 6.7: Deploy via wp-admin Updates UI (canonical path)

Per CLAUDE.md, the canonical deploy path for plugin v1.10.1+ is via wp-admin:

1. Navigate to `https://juanlentino.com/wp-admin/update-core.php`
2. Find "Signal & Noise Tools" in the plugin updates section
3. Click "Update Plugins"
4. Wait for completion

Alternative (emergency manual):

```bash
gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref v3.8.0
gh run watch --repo juanlentino/signal-and-noise-tools $(gh run list --workflow=deploy.yml --repo juanlentino/signal-and-noise-tools --limit 1 --json databaseId --jq '.[0].databaseId')
```

Expected: deploy completes, plugin updates to v3.8.0 on production, cache purges fire.

### Step 6.8: Post-deploy smoke verification

After deploy completes, visit `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options&tab=site` on production. Verify:
- The new Site tab renders with 5 sub-sections
- TOC anchors scroll correctly
- An old URL like `?tab=login` redirects to `?tab=security#sn-sec-login`

If everything looks correct on production: v3.8.0 is shipped.

---

## Rollback Plan

If v3.8.0 ships and a critical regression appears in production:

### Quick rollback (single revert commit)

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools

# Revert the v3.8.0 commit (creates a new commit that undoes everything)
git revert HEAD --no-edit

# Bump to v3.8.1 (rolling back via revert is still a release; needs a version bump)
# Edit signal-and-noise-tools.php Version to 3.8.1
# Edit CHANGELOG.md to add a "## [3.8.1] - YYYY-MM-DD\n### Reverted v3.8.0 due to <reason>" entry

git add signal-and-noise-tools.php CHANGELOG.md
git commit --amend --no-edit  # fold version bump into the revert commit

git push origin HEAD:main
git tag -a v3.8.1 -m "v3.8.1 — revert v3.8.0 (admin tabs IA reorg) due to <reason>"
git push origin v3.8.1
```

Then deploy v3.8.1 via wp-admin Updates UI (as in Step 6.7).

### What rollback restores

Reverting the v3.8.0 commit restores:
- The 12 flat top-level tabs
- The original dispatch logic
- The original PRG flash redirect behavior
- All inline Identity / Login / Links renders in their original locations
- Original `sn_admin_pages()` function (with 12 entries)

**It does NOT restore:** any work the user did in wp-admin BETWEEN v3.8.0 shipping and the rollback (since those changes are in the DB, not in code). Settings saves still work; UI just falls back to 12-tab layout.

### Subsequent re-attempt

After investigating the regression, re-attempt as v3.8.2 with the fix. The v3.8.0 commit history is preserved in git for reference.

---

## Self-Review

**1. Spec coverage** — every section of the spec maps to at least one task:

- Spec Section 1 (Final tab structure) → Task 1.1 (`sn_admin_top_tabs()`) + Task 3 (dispatch arms)
- Spec Section 2 (URL backward compat) → Task 2 (redirect map + handler) + Task 4 (PRG redirect rewrite)
- Spec Section 3 (Implementation: refactor order + hook contract) → Task 1 (helpers + top tabs) + Task 3 (dispatch rewrite)
- Spec Section 4 (Testing + edge cases) → Task 5 (gate sweep) + Task 6.8 (post-deploy smoke)
- Spec Out of Scope → respected (no audit log, no CSS, no JS, no module file changes)

**2. Placeholder scan** — no TBD/TODO/"implement later" markers. All code is concrete. All commands are exact. Manual verification steps describe what to look for, not "verify it works."

**3. Type consistency** — function names used throughout the plan: `sn_admin_top_tabs()`, `sn_admin_render_toc()`, `sn_admin_render_section()`, `sn_admin_legacy_redirect_map()`, `sn_admin_maybe_redirect_legacy()`. Each is defined exactly once and called consistently. The existing `sn_admin_page_tab_for_slug()` is used by name (already exists in code; not re-defined). The existing `sn_admin_page_valid_tabs()` + `sn_admin_page_tab_labels()` are MODIFIED to use the new `sn_admin_top_tabs()` (not renamed; callers unchanged).

**4. Module hook list audit** — verified against `grep "add_action.*sn_admin"`:
- `sn_admin_dashboard_extras` (admin-tab-dashboard.php)
- `sn_admin_cloudflare_tab` (cloudflare-purge.php)
- `sn_admin_plausible_tab` (plausible-admin.php)
- `sn_admin_insights_tab` (insights-admin.php)
- `sn_admin_health_tab` (health-checks-admin.php — assumed; verify at implementation)
- `sn_admin_cron_tab` (cron-dashboard-admin.php — assumed)
- `sn_admin_webhooks_tab` (webhooks-admin.php — assumed)
- `sn_admin_reading_time_tab` (reading-time.php — assumed)
- `sn_admin_rss_tab` (mu-plugins/rss-plausible-tracker.php — has the conditional `has_action()` check)

If any of the "assumed" hook names differ at implementation: read the corresponding file's `add_action()` registration and update the `do_action()` call in Task 3.2 to match.

**No issues found; plan is internally consistent and complete.**

---

**Status:** plan written and ready for execution.
