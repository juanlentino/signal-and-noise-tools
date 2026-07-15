<?php
/**
 * Token-contract tests for the v8.5.0 crisp-console-on-postbox treatment
 * (the admin-polish-v647 idiom applied to the Analytics page): the panel
 * shell reads from tokens, KPI numerals match the v8.0.3 glance contract,
 * and uptime-status.css no longer duplicates frame chrome the primitive owns.
 *
 * Run: php tests/analytics-tokens.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$an  = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-admin.css' );
$uw  = (string) file_get_contents( __DIR__ . '/../assets/uptime-status.css' );
$tok = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-tokens.css' );
$wg  = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-widget.css' );

echo "analytics-tokens suite - plugin v8.5.0\n";

echo "\nTest: the sn-an-postbox shell treatment\n";
ok( false !== strpos( $tok, '--sn-an-hairline: #dcdcde' ), 'hairline token defined in the shared tokens file (settings pages keep their #c3c4c7 contract untouched)' );
ok( false !== strpos( $an, '.sn-an-postbox' ), 'shell treatment targets the primitive marker class' );
ok( false !== strpos( $an, 'border: 1px solid var(--sn-an-hairline' ), 'shell border reads the token' );
ok( false !== strpos( $an, 'border-radius: var(--sn-an-radius' ), 'shell radius reads the D4 token (--sn-an-radius, 4px)' );

echo "\nTest: KPI numerals — the v8.0.3 glance contract\n";
ok( false !== strpos( $an, '.sn-an-postbox .sn-kpi-value' ), 'KPI override is scoped to primitive panels' );
$kpi_block = substr( $an, strpos( $an, '.sn-an-postbox .sn-kpi-value' ) );
$kpi_block = substr( $kpi_block, 0, strpos( $kpi_block, '}' ) );
ok( false !== strpos( $kpi_block, 'font-size: 21.6px' ), 'glance value size 21.6px (converged from 1.35rem — deterministic 16px-root parity, same computed size)' );
ok( false !== strpos( $kpi_block, 'font-weight: 600' ), 'glance value weight 600' );
ok( false !== strpos( $an, 'font-variant-numeric: tabular-nums' ), 'tabular numerals in the vocabulary' );

echo "\nTest: uptime-status.css frame duplication folded into the primitive\n";
ok( false === strpos( $uw, '#c3c4c7' ), 'no hardcoded frame border remains (the panel shell owns the frame)' );
ok( false !== strpos( $uw, '.sn-uw-table' ), 'table row styling stays (rows are the file\'s job, frames are not)' );

echo "\nTest: D1 modern-native block (v9.37.0)\n";
ok( false !== strpos( $an, '.sn-an-headline' ), 'D1: headline band styled' );
ok( false === strpos( $an, '.sn-an-insights{' ) && false === strpos( $an, '.sn-an-insights {' ), 'D1: dead .sn-an-insights band rules pruned' );
ok( false !== strpos( $an, '.sn-an-view-tabs .nav-tab' ), 'D1: underline tab treatment present' );
ok( preg_match( '/\.sn-an-view-tabs \.nav-tab-active\s*\{[^}]*border-bottom:\s*2px solid #2271b1/s', $an ) === 1, 'D1: active tab = 2px accent underline' );
ok( preg_match( '/\.postbox\.sn-overview \.sn-kpi-promoted \.sn-kpi-value\s*\{[^}]*font-size:\s*22px/s', $an ) === 1, 'D1: promoted KPI 22px (order-independent specificity — survives D4 reorders)' );
ok( preg_match( '/\.postbox\.sn-overview \.sn-kpi-value\s*\{[^}]*font-size:\s*13px/s', $an ) === 1, 'D1: secondary KPI 13px' );
ok( preg_match( '/\.sn-an-view-tabs\s*\{[^}]*padding-top:\s*0/s', $an ) === 1, 'D1: core nav-tab-wrapper 9px padding-top zeroed (8px rhythm)' );
ok( false !== strpos( $an, '.sn-an-sep-meta' ), 'D1: toolbar separation meta styled (muted inline)' );
ok( false !== strpos( $an, '.sn-an-uptime' ), 'D1: merged uptime details card styled' );
ok( preg_match( '/\.sn-overview \.sn-an-note\s*\{[^}]*border-left:\s*3px solid #2271b1/s', $an ) === 1, 'D1: Overview annotation = accent-bar callout (the .sn-an-note idiom, reused)' );
$d1 = substr( $an, (int) strpos( $an, 'v9.37.0 D1' ) );
ok( false !== strpos( $d1, 'box-shadow: none' ) && preg_match( '/box-shadow:\s*0/', $d1 ) === 0, 'D1 block: panels stay flat (box-shadow: none) and any shadow must ride the token, never a literal' );
ok( false === strpos( $d1, '#2563eb' ) && false === strpos( $d1, '#6b7280' ) && false === strpos( $d1, '#e2e4e7' ), 'D1 block: native palette only' );

echo "\nTest: D4 — the namespaced token layer (now shared via analytics-tokens.css)\n";
ok( false !== strpos( $tok, '--sn-an-accent:' ) && false !== strpos( $tok, '#2271b1' ), 'accent token defined in the shared tokens file' );
ok( false !== strpos( $tok, '--sn-an-up:' ) && false !== strpos( $tok, '--sn-an-down:' ), 'delta tokens defined in the shared tokens file' );
ok( false !== strpos( $tok, '--sn-an-elev-radius:' ) && false !== strpos( $tok, '--sn-an-shadow:' ), 'hero elevation tokens defined in the shared tokens file' );
ok( false === strpos( $an, ':root {' ), 'admin.css no longer declares :root tokens (moved to analytics-tokens.css)' );
ok( false === strpos( $an, '#006b18' ) && false === strpos( $an, '#b32d2e' ) && false === strpos( $an, '#2563eb' ), 'retired hexes gone (#006b18 / #b32d2e / #2563eb)' );
ok( substr_count( $an, '#0a7c2f' ) === 0, 'no raw #0a7c2f in admin.css (green lives only in the shared token file)' );
ok( false !== strpos( $tok, '#0a7c2f' ), 'the ONE green defined in the shared token file' );
ok( false === (bool) preg_match( '/#00a32a/', $an ), 'no raw delta-green outside tokens' );
ok( false !== strpos( $an, '.sn-an-rec-brief' ) && false !== strpos( $an, 'var(--sn-an-accent)' ), 'rec-brief reads the accent token' );
ok( false === strpos( $an, 'var(--sn-accent' ) && false === strpos( $an, 'var(--sn-surface-2' ), 'orphan undefined-token refs gone' );
ok( false !== strpos( $an, '.sn-an-headline' ) && false !== strpos( $an, 'var(--sn-an-elev-radius)' ) && false !== strpos( $an, 'var(--sn-an-shadow)' ), 'headline band carries the hero treatment' );
ok( false !== strpos( $an, '.sn-an-postbox.sn-overview, .sn-an-postbox.sn-an-rail-tile' ), 'D1 KPI specificity block intact' );
ok( false === (bool) preg_match( '/border-radius:\s*(2|3|5|6)px/', $an ), 'radius vocabulary consolidated (only token / 4px-in-token / 999px / 0 remain)' );
ok( preg_match( '/details\.sn-an-empty-fold summary\s*\{[^}]*color:\s*var\(--sn-an-muted\)/', $an ) === 1, 'D4 §4: fold-details summary reads the muted token (matches the plain line color)' );

echo "\nTest: S2 §6 — the modern settings leaf (hero pipeline strip + token cards)\n";
/**
 * Pulls the declaration block for the FIRST selector matching $selector,
 * from its opening `{` to the matching `}` (no nesting in this file).
 */
function tok_block( $css, $selector ) {
	$at = strpos( $css, $selector );
	if ( false === $at ) {
		return '';
	}
	$open = strpos( $css, '{', $at );
	$close = strpos( $css, '}', $open );
	return false === $open || false === $close ? '' : substr( $css, $open, $close - $open + 1 );
}

ok( false !== strpos( $an, '.sn-an-settings-leaf' ), 'leaf wrapper class is a real CSS scope, not just markup' );

// The hero: the pipeline strip is the leaf's ONE elevated surface.
$pipe = tok_block( $an, '.sn-an-pipeline {' );
ok( '' !== $pipe, 'pipeline strip rule found' );
ok( false !== strpos( $pipe, 'var(--sn-an-surface)' ), 'pipeline hero: surface token' );
ok( false !== strpos( $pipe, 'var(--sn-an-hairline)' ), 'pipeline hero: hairline token' );
ok( false !== strpos( $pipe, 'var(--sn-an-elev-radius)' ), 'pipeline hero: elev-radius token (the hero radius, not the flat one)' );
ok( false !== strpos( $pipe, 'var(--sn-an-shadow)' ), 'pipeline hero: shadow token' );
ok( false === strpos( $pipe, '#' ), 'pipeline hero: no raw hex — tokens only' );

// The token cards: the two operate/reference columns, flat (not elevated).
$card = tok_block( $an, '.sn-an-settings-leaf .sn-2up > .sn-fieldset {' );
ok( '' !== $card, 'leaf-scoped card rule found' );
ok( false !== strpos( $card, 'var(--sn-an-surface)' ), 'card: surface token' );
ok( false !== strpos( $card, 'var(--sn-an-hairline)' ), 'card: hairline token' );
ok( false !== strpos( $card, 'var(--sn-an-radius)' ) && false === strpos( $card, 'var(--sn-an-elev-radius)' ), 'card: the FLAT radius token, never the hero one' );
ok( false === strpos( $card, 'box-shadow' ), 'card: no shadow — the pipeline strip is the only elevated surface' );
ok( false === strpos( $card, '#' ), 'card: no raw hex — tokens only' );

$heading = tok_block( $an, '.sn-an-settings-leaf .sn-fieldset-h {' );
ok( '' !== $heading, 'leaf-scoped heading rule found' );
ok( false !== strpos( $heading, 'font-size: 13px' ) && false !== strpos( $heading, 'font-weight: 600' ), 'heading: 13px/600 dashboard treatment' );
ok( false !== strpos( $heading, 'var(--sn-an-text)' ) && false === strpos( $heading, '#' ), 'heading: text token, no raw hex' );

$help = tok_block( $an, '.sn-an-settings-leaf .sn-an-settings-help {' );
ok( '' !== $help, 'leaf-scoped help-text rule found' );
ok( false !== strpos( $help, 'var(--sn-an-muted)' ) && false === strpos( $help, '#' ), 'help text: muted token, no raw hex' );

// Exactly one hero consumer among the LEAF-scoped additions (the pipeline
// strip) + the dashboard's own hero (.sn-an-headline) is untouched — so the
// file-wide count of each hero token is exactly 2 (headline + pipeline).
ok( substr_count( $an, 'var(--sn-an-elev-radius)' ) === 2, 'exactly two elev-radius consumers file-wide: dashboard headline + leaf pipeline hero' );
ok( substr_count( $an, 'var(--sn-an-shadow)' ) === 2, 'exactly two shadow consumers file-wide: dashboard headline + leaf pipeline hero' );

echo "\nTest: analytics-tokens.css — the shared D4 token file (widget tokenization)\n";
ok( is_file( __DIR__ . '/../assets/analytics/analytics-tokens.css' ), 'asset: assets/analytics/analytics-tokens.css exists' );
ok( false !== strpos( $tok, ':root {' ), 'tokens file declares :root' );
foreach ( array(
	'--sn-an-accent'                   => '#2271b1',
	'--sn-an-up'                       => '#0a7c2f',
	'--sn-an-down'                     => '#d63638',
	'--sn-an-hairline'                 => '#dcdcde',
	'--sn-an-muted'                    => '#646970',
	'--sn-an-text'                     => '#1d2327',
	'--sn-an-surface'                  => '#fff',
	'--sn-an-surface-2'                => '#f6f7f7',
	'--sn-an-radius'                   => '4px',
	'--sn-an-elev-radius'              => '8px',
	'--sn-an-tier-predictive-border'   => '#9ec2e6',
	'--sn-an-tier-prescriptive'        => '#7c3aed',
	'--sn-an-tier-prescriptive-border' => '#c4b5fd',
	'--sn-an-tier-diagnostic'          => '#996800',
	'--sn-an-tier-diagnostic-border'   => '#e5cf8c',
	'--sn-an-ok-bg'                    => '#e5f3ea',
	'--sn-an-warn-bg'                  => '#fcf0d6',
	'--sn-an-warn-text'                => '#996800',
	'--sn-an-warn-border'              => '#e5cf8c',
) as $name => $value ) {
	ok( false !== strpos( $tok, "$name: $value" ), "tokens file: $name declared verbatim as $value" );
}
ok( false !== strpos( $tok, '--sn-an-shadow: 0 1px 2px rgba(0,0,0,.04)' ), 'tokens file: shadow value verbatim' );

echo "\nTest: warn/ok state tokens are a SEPARATE pair from the tier-diagnostic tokens\n";
// FIX3: the admin pipeline pill's warn palette (#fcf0d6/#996800/#e5cf8c)
// happens to share values with the maturity tier-diagnostic tokens above —
// same native-admin amber, different semantic (a warn STATE vs. a tier
// BADGE). Both pairs must stay independently named so one can change
// without silently reskinning the other.
ok( substr_count( $tok, '--sn-an-warn-text:' ) === 1 && substr_count( $tok, '--sn-an-tier-diagnostic:' ) === 1, 'warn-text and tier-diagnostic each declared exactly once, as distinct tokens (not aliases)' );
ok( substr_count( $tok, '--sn-an-warn-border:' ) === 1 && substr_count( $tok, '--sn-an-tier-diagnostic-border:' ) === 1, 'warn-border and tier-diagnostic-border each declared exactly once, as distinct tokens (not aliases)' );
ok( false !== stripos( $tok, 'different semantic' ) || false !== stripos( $tok, 'not a shared meaning' ) || false !== stripos( $tok, 'coincidence' ), 'tokens file: a comment documents the value coincidence as deliberate, not drift' );

echo "\nTest: shared tier-variant tokens reconciled in analytics-admin.css\n";
$pred_a = tok_block( $an, '.sn-an-tier--predictive' );
ok( '' !== $pred_a && false !== strpos( $pred_a, 'var(--sn-an-accent)' ) && false !== strpos( $pred_a, 'var(--sn-an-tier-predictive-border)' ) && false === strpos( $pred_a, '#' ), 'admin: predictive tier variant reads tokens, no raw hex' );
$presc_a = tok_block( $an, '.sn-an-tier--prescriptive' );
ok( '' !== $presc_a && false !== strpos( $presc_a, 'var(--sn-an-tier-prescriptive)' ) && false !== strpos( $presc_a, 'var(--sn-an-tier-prescriptive-border)' ) && false === strpos( $presc_a, '#' ), 'admin: prescriptive tier variant reads tokens, no raw hex' );
$diag_a = tok_block( $an, '.sn-an-tier--diagnostic' );
ok( '' !== $diag_a && false !== strpos( $diag_a, 'var(--sn-an-tier-diagnostic)' ) && false !== strpos( $diag_a, 'var(--sn-an-tier-diagnostic-border)' ) && false === strpos( $diag_a, '#' ), 'admin: diagnostic tier variant reads tokens, no raw hex' );

echo "\nTest: analytics-widget.css — tokenized hex list gone from rules (comments exempt)\n";
$wg_no_comments = (string) preg_replace( '/\/\*.*?\*\//s', '', $wg );
foreach ( array( '#646970', '#1d2327', '#0a7c2f', '#f6f7f7', '#d63638', '#2271b1', '#dcdcde', '#c4b5fd', '#9ec2e6', '#7c3aed', '#b32d2e', '#00a32a', '#8a6100', '#fcf0d6', '#e5f3ea' ) as $hex ) {
	ok( false === stripos( $wg_no_comments, $hex ), "widget: no raw $hex outside comments (tokenized)" );
}
ok( substr_count( $wg, 'var(--sn-an-muted)' ) === 12, 'widget: all 12 #646970 rule-occurrences read the muted token' );
ok( substr_count( $wg, 'var(--sn-an-text)' ) === 5, 'widget: all 5 #1d2327 rule-occurrences read the text token' );
ok( substr_count( $wg, 'var(--sn-an-up)' ) === 4, 'widget: all 4 up-token rule-occurrences read the up token (mover-up now included — FIX2)' );
ok( substr_count( $wg, 'var(--sn-an-surface-2)' ) === 3, 'widget: all 3 #f6f7f7 rule-occurrences read the surface-2 token' );
ok( substr_count( $wg, 'var(--sn-an-down)' ) === 3, 'widget: all 3 down-token rule-occurrences read the down token (delta-down now included — FIX2)' );
ok( substr_count( $wg, 'var(--sn-an-accent)' ) === 2, 'widget: all 2 #2271b1 rule-occurrences read the accent token' );
ok( substr_count( $wg, 'var(--sn-an-hairline)' ) === 2, 'widget: all 2 #dcdcde rule-occurrences read the hairline token' );
ok( substr_count( $wg, 'var(--sn-an-tier-predictive-border)' ) === 1, 'widget: predictive border reads its token' );
ok( substr_count( $wg, 'var(--sn-an-tier-prescriptive-border)' ) === 1, 'widget: prescriptive border reads its token' );
ok( substr_count( $wg, 'var(--sn-an-tier-prescriptive)' ) === 1, 'widget: prescriptive color reads its token' );

echo "\nTest: widget-only literals stay hex (genuinely unrelated to this token vocabulary)\n";
// #b32d2e / #00a32a were drift, not a deliberate choice — see the FIX2 block
// below. Only the truly unrelated literals stay hex: the list-row hairline
// and the settings-page hairline contract (a DIFFERENT, untouched token).
ok( false !== strpos( $wg, '#f0f0f1' ) && false !== strpos( $wg, '#c3c4c7' ), 'widget: lighter-hairline + settings-page-contract literals untouched' );

echo "\nTest: FIX2 — mover/delta drift reconciled onto the shared up/down tokens\n";
// .sn-aw-delta--down and .sn-aw-mv-up used to carry their own one-off hexes
// (#b32d2e / #00a32a) even though their partners (.sn-aw-delta--up,
// .sn-aw-mv-down) already read the shared tokens, and the file comment
// claimed this was "this file's own movers up/down pair" — a deliberate
// different shade. It wasn't: it's the same up/down semantic the rest of the
// dashboard already tokenizes. DELIBERATE VISUAL CHANGE: the mover green
// darkens #00a32a -> #0a7c2f and the delta-down red shifts #b32d2e -> #d63638
// (now matching the Analytics pages).
$delta_down_block = tok_block( $wg, '.sn-aw-delta--down{' );
ok( '' !== $delta_down_block && false !== strpos( $delta_down_block, 'var(--sn-an-down)' ) && false === strpos( $delta_down_block, '#' ), '.sn-aw-delta--down reads the shared down token (was raw #b32d2e)' );
$mv_up_block = tok_block( $wg, '.sn-aw-mv-up {' );
ok( '' !== $mv_up_block && false !== strpos( $mv_up_block, 'var(--sn-an-up)' ) && false === strpos( $mv_up_block, '#' ), '.sn-aw-mv-up reads the shared up token (was raw #00a32a)' );
ok( false === strpos( $wg, "own movers up/down pair" ), 'widget: the false "own movers up/down pair" comment claim is gone (they now share the real tokens)' );
ok( false === strpos( $wg, "warn/ok state colors) or belong to a different, untouched token" ), 'widget: the false "warn/ok state colors are a different, untouched contract" comment claim is gone' );
$header_comment = substr( $wg, 0, (int) strpos( $wg, '*/' ) );
ok( false !== stripos( $header_comment, 'mover' ) && false !== stripos( $header_comment, 'ok/warn' ), 'widget: header comment still documents the mover + ok/warn tokens, just correctly now (not silently deleted)' );

echo "\nTest: FIX3 — warn/ok state-color unification (S&N Health widget + admin pill)\n";
$ok_ico_block = tok_block( $wg, '.sn-hw-head--ok .sn-hw-ico{' );
ok( '' !== $ok_ico_block && false !== strpos( $ok_ico_block, 'var(--sn-an-ok-bg)' ) && false === strpos( $ok_ico_block, '#' ), 'widget: ok-state icon background reads --sn-an-ok-bg (was raw #e5f3ea)' );
$warn_ico_block = tok_block( $wg, '.sn-hw-head--warn .sn-hw-ico{' );
ok( '' !== $warn_ico_block && false !== strpos( $warn_ico_block, 'var(--sn-an-warn-bg)' ) && false !== strpos( $warn_ico_block, 'var(--sn-an-warn-text)' ) && false === strpos( $warn_ico_block, '#' ), 'widget: warn-state icon background AND text read the shared warn tokens (bg was raw #fcf0d6, text was raw #8a6100)' );
// DELIBERATE VISUAL CHANGE: the widget's warn amber darkens #8a6100 -> #996800
// (var(--sn-an-warn-text)) to match the dashboard pill's warn color.
ok( false === stripos( $wg, '8a6100' ), 'widget: the darker pre-unification warn amber (#8a6100) is fully gone' );

$pill_warn_block = tok_block( $an, '.sn-an-pill--warn {' );
ok( '' !== $pill_warn_block && false !== strpos( $pill_warn_block, 'var(--sn-an-warn-border)' ) && false !== strpos( $pill_warn_block, 'var(--sn-an-warn-bg)' ) && false === strpos( $pill_warn_block, '#' ), 'admin: .sn-an-pill--warn reads the shared warn-bg/warn-border tokens (was raw #fcf0d6/#e5cf8c)' );
$pill_warn_mark_block = tok_block( $an, '.sn-an-pill--warn .sn-an-pill-mark {' );
ok( '' !== $pill_warn_mark_block && false !== strpos( $pill_warn_mark_block, 'var(--sn-an-warn-text)' ) && false === strpos( $pill_warn_mark_block, '#' ), 'admin: .sn-an-pill--warn .sn-an-pill-mark reads the shared warn-text token (was raw #996800)' );
ok( 0 === substr_count( $an, '#fcf0d6' ) && 0 === substr_count( $an, '#e5cf8c' ) && 0 === substr_count( $an, '#996800' ), 'admin: no raw warn hexes remain outside the pill\'s token adoption (value-identical swap)' );

echo "\nTest: the missing diagnostic tier variant — the one deliberate visual change\n";
$diag_w = tok_block( $wg, '.sn-aw-insight .sn-an-tier--diagnostic' );
ok( '' !== $diag_w, 'widget: .sn-aw-insight .sn-an-tier--diagnostic is now defined (was missing — an unstyled insight bug)' );
ok( false !== strpos( $diag_w, 'var(--sn-an-tier-diagnostic)' ) && false !== strpos( $diag_w, 'var(--sn-an-tier-diagnostic-border)' ) && false === strpos( $diag_w, '#' ), 'widget: diagnostic variant reads tokens, no raw hex' );

echo "\nTest: the accent rgba tint stays literal, with a comment naming its token\n";
$rgba_pos = strpos( $wg, 'rgba(34, 113, 177, 0.07)' );
ok( false !== $rgba_pos, 'widget: accent rgba tint present, kept literal (not var()-ified)' );
$rgba_ctx = false !== $rgba_pos ? substr( $wg, max( 0, $rgba_pos - 400 ), 400 ) : '';
ok( false !== strpos( $rgba_ctx, '--sn-an-accent' ), 'widget: rgba tint has a nearby comment naming the accent token it mirrors' );

echo "\nTest: deliberate chip-scale deltas carry an explicit comment\n";
ok( false !== stripos( $wg, 'chip-scale: one step below the dashboard scale' ), 'widget: chip-scale size deltas are flagged explicit, not silent drift' );

echo "\nTest: kicker canon — ONE letter-spacing token for the uppercase-kicker role (.04em)\n";
ok( false !== strpos( $tok, '--sn-an-kicker-track: .04em' ), 'tokens file: --sn-an-kicker-track declared as .04em' );
ok( false !== strpos( $tok, '--sn-an-display-track: -.01em' ), 'tokens file: --sn-an-display-track declared as -.01em (the promoted-KPI-numeral role — different from the kicker)' );
ok( false !== strpos( $tok, '--sn-an-tier-text: #50575e' ), 'tokens file: --sn-an-tier-text declared as #50575e (the shared tier base-text literal)' );

// The 10 admin.css selectors judged to be true uppercase kickers (text-transform:
// uppercase small labels/badges). .sn-an-note-label is EXCLUDED on purpose — see
// the dedicated exclusion test below (it renders "Read" in sentence case, not
// uppercase, so tracking calibrated for all-caps text is a different role).
// Spaced-style rules (this file's dominant convention: "letter-spacing: X").
$admin_kicker_selectors_spaced = array(
	'.sn-control-label {',
	'.sn-kpi-label {',
	'.sn-trend-head .sn-trend-title {',
	'.sn-an-pctl-k {',
	'.sn-an-journeys-label {',
	'.sn-an-pulse-k {',
	'.sn-an-headline-more {',
	'.sn-an-signal-badge {',
	'.postbox.sn-overview .sn-kpi-label {',
);
foreach ( $admin_kicker_selectors_spaced as $sel ) {
	$block = tok_block( $an, $sel );
	ok( '' !== $block, "admin kicker rule found: $sel" );
	ok( false !== strpos( $block, 'letter-spacing: var(--sn-an-kicker-track)' ), "admin kicker reads --sn-an-kicker-track: $sel" );
}
// .sn-an-tier is written condensed (no space after ':') in this file — matches
// its own local convention rather than the surrounding spaced rules.
$tier_admin_kicker = tok_block( $an, '.sn-an-tier{' );
ok( '' !== $tier_admin_kicker, 'admin kicker rule found: .sn-an-tier{' );
ok( false !== strpos( $tier_admin_kicker, 'letter-spacing:var(--sn-an-kicker-track)' ), 'admin kicker reads --sn-an-kicker-track: .sn-an-tier{' );
ok( substr_count( $an, 'letter-spacing: var(--sn-an-kicker-track)' ) + substr_count( $an, 'letter-spacing:var(--sn-an-kicker-track)' ) === 10, 'admin: exactly 10 kicker declarations read the token (no strays, no doubles)' );

// Every declaration in analytics-widget.css is written condensed (no space
// after ':') — match that file's convention throughout.
$widget_kicker_selectors = array(
	'.sn-aw-subhead{',
	'.sn-aw-insight .sn-an-signal-badge{',
	'.sn-aw-insight .sn-an-tier{',
);
foreach ( $widget_kicker_selectors as $sel ) {
	$block = tok_block( $wg, $sel );
	ok( '' !== $block, "widget kicker rule found: $sel" );
	ok( false !== strpos( $block, 'letter-spacing:var(--sn-an-kicker-track)' ), "widget kicker reads --sn-an-kicker-track: $sel" );
}
ok( substr_count( $wg, 'letter-spacing:var(--sn-an-kicker-track)' ) === 3, 'widget: exactly 3 kicker declarations read the token (chip-scale kickers adopt it too — sizes stay, only tracking calibrates)' );

echo "\nTest: display-numeral track — a DIFFERENT role from the kicker, tokenized separately\n";
$display_selectors = array(
	'.sn-kpi-promoted .sn-kpi-value {'                       => 'promoted KPI numeral (base)',
	'.postbox.sn-overview .sn-kpi-promoted .sn-kpi-value {'  => 'promoted KPI numeral (Overview compound)',
);
foreach ( $display_selectors as $sel => $label ) {
	$block = tok_block( $an, $sel );
	ok( '' !== $block, "admin display-numeral rule found: $label" );
	ok( false !== strpos( $block, 'letter-spacing: var(--sn-an-display-track)' ), "admin display numeral reads --sn-an-display-track: $label" );
}
ok( substr_count( $an, 'letter-spacing: var(--sn-an-display-track)' ) === 2, 'admin: exactly 2 display-numeral declarations read the token' );
ok( 0 === preg_match( '/letter-spacing:\s*-0\.01em/', $an ), 'admin: the raw -0.01em literal is gone everywhere (both display-numeral decls tokenized)' );

echo "\nTest: excluded from kicker canon — .sn-an-note-label is NOT uppercase (judgment call, documented)\n";
$note_label = tok_block( $an, '.sn-an-note-label {' );
ok( '' !== $note_label, 'note-label base rule found' );
ok( false === strpos( $note_label, 'text-transform: uppercase' ), 'note-label: confirmed NOT uppercase — a sentence-case inline annotation prefix ("Read"), not a kicker/badge' );
ok( false !== strpos( $note_label, 'letter-spacing: .02em' ), 'note-label: literal .02em kept as-is (deliberately excluded from the token sweep)' );
$rail_note_label = tok_block( $an, '.sn-an-rail-tile .sn-an-note-label {' );
ok( '' !== $rail_note_label, 'rail-tile note-label override rule found' );
ok( false !== strpos( $rail_note_label, 'letter-spacing: .03em' ), 'rail-tile note-label: literal .03em kept as-is (same exclusion — still not uppercase)' );

echo "\nTest: .sn-an-delta's em font-size stays literal — unprovable context, not guessed\n";
$delta_block = tok_block( $an, '.sn-an-delta {' );
ok( '' !== $delta_block, '.sn-an-delta rule found' );
ok( false !== strpos( $delta_block, 'font-size: 0.72em' ), '.sn-an-delta: 0.72em kept as-is — no ancestor selector in this file pins a font-size for it, so the computed px cannot be proven from the stylesheet' );

echo "\nTest: unit convergence — analytics-widget.css rem font-sizes onto px (16px root; verified no html{font-size} override exists anywhere in this plugin's own CSS)\n";
ok( 0 === preg_match( '/font-size:\s*[0-9.]*rem/', $wg ), 'widget: zero rem font-sizes remain' );
$widget_rem_to_px = array(
	'.sn-aw-subhead{' => '11.2px',
	'.sn-aw-stat-n{'  => '25.6px',
	'.sn-aw-big{'     => '40px',
	'.sn-aw-nt-v{'    => '28.8px',
	'.sn-aw-nt-k{'    => '11.52px',
	'.sn-hw-h{'       => '16.8px',
);
foreach ( $widget_rem_to_px as $sel => $px ) {
	$block = tok_block( $wg, $sel );
	ok( false !== strpos( $block, "font-size:$px" ), "widget: $sel converged to $px (deterministic rem*16 parity, no rendering change)" );
}

echo "\nTest: unit convergence — analytics-admin.css's 2 rem font-sizes, same treatment\n";
ok( 0 === preg_match( '/font-size:\s*[0-9.]*rem/', $an ), 'admin: zero rem font-sizes remain' );
$kpi_glance = tok_block( $an, '.sn-an-postbox .sn-kpi-value {' );
ok( false !== strpos( $kpi_glance, 'font-size: 21.6px' ), 'admin: .sn-an-postbox .sn-kpi-value converged 1.35rem -> 21.6px' );
$kpi_glance_promo = tok_block( $an, '.sn-an-postbox .sn-kpi-promoted .sn-kpi-value {' );
ok( false !== strpos( $kpi_glance_promo, 'font-size: 27.2px' ), 'admin: .sn-an-postbox .sn-kpi-promoted .sn-kpi-value converged 1.7rem -> 27.2px' );

echo "\nTest: analytics-widget.css's 9 em font-sizes stay literal (unprovable context, reported not guessed)\n";
$widget_unconverted_em = array(
	'.sn-aw-stat-l{'         => '0.85em',
	'.sn-aw-big-l{'          => '0.85em',
	'.sn-aw-list{'           => '0.875em',
	'.sn-aw-foot{'           => '0.85em',
	'.sn-aw-trend-l{'        => '0.78em',
	'.sn-aw-empty{'          => '0.875em',
	'.sn-aw-err{'            => '0.9em',
	'.sn-aw-config-snippet{' => '0.85em',
	'.sn-hw-sub{'            => '0.85em',
);
foreach ( $widget_unconverted_em as $sel => $em ) {
	$block = tok_block( $wg, $sel );
	ok( false !== strpos( $block, "font-size:$em" ), "widget: $sel kept as $em — no same-file ancestor pins its parent font-size, so px parity can't be proven" );
}
ok( preg_match_all( '/font-size:\s*[0-9.]+em(?!\w)/', $wg ) === 9, 'widget: exactly 9 unprovable em font-sizes remain (none silently converted, none silently dropped)' );

echo "\nTest: token-polish riders — value-identical swaps in the shared .sn-an-tier base block\n";
$tier_admin = tok_block( $an, '.sn-an-tier{' );
ok( false !== strpos( $tier_admin, 'border:1px solid var(--sn-an-hairline)' ), 'admin .sn-an-tier: border reads --sn-an-hairline (was raw #dcdcde)' );
ok( false !== strpos( $tier_admin, 'background:var(--sn-an-surface-2)' ), 'admin .sn-an-tier: background reads --sn-an-surface-2 (was raw #f6f7f7)' );
ok( false !== strpos( $tier_admin, 'color:var(--sn-an-tier-text)' ), 'admin .sn-an-tier: text color reads --sn-an-tier-text (was raw #50575e)' );
ok( false === strpos( $tier_admin, '#dcdcde' ) && false === strpos( $tier_admin, '#f6f7f7' ) && false === strpos( $tier_admin, '#50575e' ), 'admin .sn-an-tier: no raw hex left in the base block' );

$tier_widget = tok_block( $wg, '.sn-aw-insight .sn-an-tier{' );
ok( false !== strpos( $tier_widget, 'color:var(--sn-an-tier-text)' ), 'widget .sn-aw-insight .sn-an-tier: text color reads --sn-an-tier-text (was raw #50575e)' );
ok( false === strpos( $tier_widget, '#50575e' ), 'widget .sn-aw-insight .sn-an-tier: no raw #50575e left' );

// The two OTHER #50575e literals in admin.css (.sn-an-drill .sn-an-subh,
// .sn-an-view-tabs .nav-tab) are a DIFFERENT role from the shared tier badge —
// out of this rider's scope, left untouched on purpose.
ok( substr_count( $an, '#50575e' ) === 2, 'admin: the two non-tier #50575e literals (.sn-an-drill .sn-an-subh, .sn-an-view-tabs .nav-tab) stay untouched — out of rider scope' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
