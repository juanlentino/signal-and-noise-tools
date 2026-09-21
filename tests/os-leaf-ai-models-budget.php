<?php
/**
 * Native window leaf: AI → Models & Budget (apps/sn-dashboard/parts/leaves/ai-models-budget.php).
 *
 * The oracle is the classic leaf (inc/admin-forms/ai-settings.php): the kit
 * form must carry the same field names and the same two sn_action values
 * (ai_settings_save, ml_embed_compare — the second given its own os-action
 * per the port map rather than reusing the field name), the same spend /
 * by-feature readout, the same embeddings-token status, and the same
 * TF-IDF-vs-embeddings comparison states, with none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-ai-models-budget.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// get_transient() redeclared UNCONDITIONALLY so it is bound at compile time
// (before the harness's guarded default runs) and can be driven from a global
// — same pattern as tests/os-leaf-content-tags.php.
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }

// ── The leaf's own readers, stubbed the way the harness convention expects.
$GLOBALS['__settings'] = array(
	'theme.ai_model'          => 'claude-sonnet-5',
	'theme.ai_alt_model'      => 'gemini-2.5-flash-lite',
	'theme.ai_monthly_budget' => 0,
);
function sn_setting( $key, $default = '' ) { return array_key_exists( $key, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $key ] : $default; }

$GLOBALS['__spend']        = 0.0;
$GLOBALS['__spend_by_feat'] = array();
function snt_ai_spend_this_month() { return $GLOBALS['__spend']; }
function snt_ai_spend_this_month_by_feature() { return $GLOBALS['__spend_by_feat']; }
// #1597: the Copilot bucket (inc/ai-copilot-spend.php), a reader of its own,
// and the count of turns it could not price.
$GLOBALS['__copilot']          = 0.0;
$GLOBALS['__copilot_unpriced'] = 0;
function snt_ai_copilot_spend_this_month() { return $GLOBALS['__copilot']; }
function snt_ai_copilot_unpriced_this_month() { return $GLOBALS['__copilot_unpriced']; }

$GLOBALS['__embed_token']      = '';
$GLOBALS['__embed_configured'] = false;
$GLOBALS['__embed_account_id'] = '';
function snt_ml_embed_token() { return $GLOBALS['__embed_token']; }
function snt_ml_embed_configured() { return $GLOBALS['__embed_configured']; }
function snt_ml_embed_account_id() { return $GLOBALS['__embed_account_id']; }

// 17.3.x: the platform-reported spend readers (inc/spend-watch.php), driven
// from globals: null = keyring row absent, ok=false = the read failed.
$GLOBALS['__gh'] = null;
$GLOBALS['__ai'] = null;
function sn_spend_gh_usage() { return $GLOBALS['__gh']; }
function sn_spend_ai_cost() { return $GLOBALS['__ai']; }
// The Jev meter, loaded but not connected: paints its heading so the order pin can fail.
function sn_jev_meter_reading() { return array(); }
function sn_jev_is_ready() { return false; }

// sn_mask_secret() is pure PHP (inc/settings.php) — mirrored verbatim rather
// than pulling the 575-line settings file in for one helper.
function sn_mask_secret( $value ) {
	$value = (string) $value;
	if ( '' === $value ) { return ''; }
	return strlen( $value ) <= 8 ? '••••••••' : '••••' . substr( $value, -4 );
}

require SNT_PATH . 'inc/admin-post-actions/theme-ai.php'; // sn_theme_ai_models(), sn_theme_ai_vision_models(), the classic handlers.
require SNT_PATH . 'inc/admin-forms/ai-settings.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-models-budget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['ai/models-budget'] ), 'the painter is registered under ai/models-budget' );

// ── Normal fixture: no budget cap, no spend lane, embeddings unconfigured.
$classic = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$kit     = snt_leaf_paint( 'ai', 'models-budget' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'ai_settings_save' ) === snt_leaf_actions( $kit ) && array( 'ai_settings_save' ) === snt_leaf_actions( $classic ), 'unconfigured: only ai_settings_save is offered (the comparison button is gated off), matching the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ), 'the settings form is an os-form dispatching post' );
ok( false !== strpos( $kit, 'name="theme_ai_model"' ) && false !== strpos( $kit, 'name="theme_ai_alt_model"' ) && false !== strpos( $kit, 'name="theme_ai_monthly_budget"' ) && false === strpos( $kit, 'name="sn_ml_embeddings_token"' ), '15.3.1: three classic fields survive as kit fields; the token field is the keyring\'s now: all four classic fields survive as kit fields' );
ok( false !== strpos( $kit, 'No cap set' ) && false !== strpos( $kit, 'Spent this month: $0.00' ), 'no-cap state reads the same as the classic leaf' );
$model_ids = array_keys( sn_theme_ai_models() );
$vision_ids = array_keys( sn_theme_ai_vision_models() );
ok(
	false !== strpos( $kit, '<os-option value="' . $model_ids[0] . '"' )
	&& substr_count( $kit, '<os-option' ) === count( $model_ids ) + count( $vision_ids ),
	'both model selects paint every option the classic <select> offers'
);
ok( false !== strpos( $kit, 'value="claude-sonnet-5"' ), 'the stored prose-model value lands on its os-select' );
// no-cap fixture: the "Set 0 to remove the cap" hint is absent (classic only prints it when budget > 0).
ok( false === strpos( $kit, 'Set 0 to remove the cap.' ), 'no-cap fixture: the remove-cap hint is absent, mirroring the classic branch' );
// 17.4.1 (#1573): rows of comparable height, no 2up. The form beside the spend
// box and the platform box stacked; nothing configured, the Jev box stands
// alone at full width with the bare status notice under it, not beside it.
ok( 1 === substr_count( $kit, snt_leaf_row() ) && false === strpos( $kit, 'snt-2up' ), '17.4.1: one .snt-cols row when nothing is configured, no .snt-2up' );
ok( false !== strpos( $kit, 'heading="This month, by feature"' ) && strpos( $kit, 'heading="This month, by feature"' ) < strpos( $kit, 'No cap set' ), '17.4.1: the spend line is the lead of the by-feature box, which paints even with no feature rows' );
// The row closes as </os-card></os-grid>.
$row1 = preg_match( '#' . preg_quote( snt_leaf_row(), '#' ) . '.*?</os-card></os-grid>#s', $kit, $m ) ? $m[0] : '';
ok( false !== strpos( $row1, 'heading="Models &amp; budget"' ) && false !== strpos( $row1, 'heading="This month, by feature"' ) && false !== strpos( $row1, 'heading="Platform-reported, this month"' ) && false === strpos( $row1, 'heading="Jev' ) && strpos( $kit, 'heading="Jev' ) > strpos( $kit, $row1 ) + strlen( $row1 ), '17.4.1: row one is the form beside the two spend readouts; Jev is under the row' );
ok( 0 === preg_match( '#<os-card><os-section heading="Jev"#', $kit ) && false !== strpos( $kit, 'first.</os-badge></os-notice>' ) && false === strpos( $kit, 'first.</os-badge></os-notice></os-card>' ) && strpos( $kit, 'first.</os-badge>' ) > strpos( $kit, 'heading="Jev' ), '17.4.1: nothing configured, the Jev box stands alone at full width and the status notice follows it, not beside it' );

// ── Budget set, under cap, with a by-feature breakdown.
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 50.0;
$GLOBALS['__spend']         = 12.5;
$GLOBALS['__spend_by_feat'] = array( 'drafts' => 9.0, 'alt-text' => 3.5 );
$classic = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$kit     = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, 'Spent this month: $12.50 of $50.00 (25%)' ), 'the spend percentage matches the classic arithmetic' );
ok( false !== strpos( $kit, 'drafts' ) && false !== strpos( $kit, '$9.00' ) && false !== strpos( $kit, 'alt-text' ) && false !== strpos( $kit, '$3.50' ), 'the by-feature breakdown lists both features and their costs' );
ok( false === strpos( $kit, 'The cap is reached' ), 'under cap: no cap-reached notice' );
ok( false !== strpos( $kit, '<os-progress-bar value="25" max="100" tone="default"' ), 'the spend meter paints at the classic width' );
ok( false !== strpos( $kit, 'Set 0 to remove the cap.' ) && false !== strpos( $kit, 'This month, by feature' ), 'the capped branch carries the classic hint and the by-feature heading' );

// ── Cap reached.
$GLOBALS['__spend'] = 50.0;
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, 'The cap is reached' ) && false !== strpos( $kit, 'tone="warning"' ), 'cap reached: the pause notice paints as a warning' );
ok( strpos( $kit, 'The cap is reached' ) < strpos( $kit, 'Spent this month: $50.00' ) && strpos( $kit, 'The cap is reached' ) > strpos( $kit, 'heading="This month, by feature"' ), '17.4.1: the cap notice sits on top of its box, above the spend line' );
ok( false !== strpos( $kit, '<os-progress-bar value="100" max="100" tone="danger"' ), 'the meter clamps at 100 and turns danger, as the classic clamped its width' );
$GLOBALS['__spend']         = 0.0;
$GLOBALS['__spend_by_feat'] = array();
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;

// ── Escaping: a hostile feature slug never reaches the markup raw.
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 10.0;
$GLOBALS['__spend']         = 1.0;
$GLOBALS['__spend_by_feat'] = array( '<script>x</script>' => 1.0 );
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;x&lt;/script&gt;' ), 'a hostile by-feature slug is escaped' );
$GLOBALS['__spend']         = 0.0;
$GLOBALS['__spend_by_feat'] = array();
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;

// ── Embeddings configured but not run yet: the comparison section and its
// button appear, and the second action is now offered alongside the first.
$GLOBALS['__embed_token']      = 'a-real-token-value';
$GLOBALS['__embed_configured'] = true;
$classic = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$kit     = snt_leaf_paint( 'ai', 'models-budget' );
ok( array( 'ai_settings_save', 'ml_embed_compare' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'configured: both actions are offered, matching the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( false !== strpos( $kit, 'Not run yet' ) && false !== strpos( $kit, 'Run comparison' ), 'configured, not run yet: the runner prompt and button paint' );
ok( false === strpos( $kit, 'a-real-token-value' ) && false !== strpos( $kit, '>set</os-badge>' ) && false !== strpos( $kit, 'Connections › Credentials' ), '15.3.1: the token is never on the leaf; a set badge and the door to the keyring are' );
ok( false !== strpos( $kit, 'Configured' ), 'the embeddings-token status pill reads Configured' );
ok( strpos( $kit, '>Configured</os-badge>' ) > strpos( $kit, 'heading="TF-IDF vs embeddings"' ) && strpos( $kit, '>Configured</os-badge>' ) < strpos( $kit, 'Not run yet' ), '17.4.1: configured, the status notice is the lead of the comparison box' );
$row2 = substr( $kit, (int) strrpos( $kit, snt_leaf_row() ) );
ok( 2 === substr_count( $kit, snt_leaf_row() ) && 1 === preg_match( '#<os-card><os-section heading="Jev#', $row2 ) && false !== strpos( $row2, 'heading="TF-IDF vs embeddings"' ), '17.4.1: configured with no result, the short comparison box sits beside the Jev box on row two' );

// ── Embeddings NOT configured, and no Cloudflare account ID: the pill must
// tell the operator to set the account id, not claim they are "Not configured."
$GLOBALS['__embed_configured'] = false;
$GLOBALS['__embed_account_id'] = '';
$classic = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$kit     = snt_leaf_paint( 'ai', 'models-budget' );
ok(
	false !== strpos( $kit, 'No Cloudflare account ID' ) && false === strpos( $kit, 'Not configured.' )
	&& false !== strpos( $classic, 'No Cloudflare account ID' ) && false === strpos( $classic, 'Not configured.' ),
	'no account id: the pill asks for the Cloudflare account ID, matching the classic leaf'
);

// ── Embeddings NOT configured, but a Cloudflare account ID IS set: the
// mirror-image branch — "Not configured.", not the account-id nag.
$GLOBALS['__embed_account_id'] = 'acct-123';
$classic = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$kit     = snt_leaf_paint( 'ai', 'models-budget' );
ok(
	false !== strpos( $kit, 'Not configured.' ) && false === strpos( $kit, 'No Cloudflare account ID' )
	&& false !== strpos( $classic, 'Not configured.' ) && false === strpos( $classic, 'No Cloudflare account ID' ),
	'account id present, not configured: the pill reads "Not configured.", matching the classic leaf'
);
$GLOBALS['__embed_configured'] = true;
$GLOBALS['__embed_account_id'] = '';

// ── Comparison run: failure.
$GLOBALS['__transients']['snt_ml_embed_compare'] = array( 'ok' => false, 'error' => 'Cloudflare account ID missing.' );
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, 'Cloudflare account ID missing.' ) && false !== strpos( $kit, 'tone="warning"' ), 'a failed comparison shows its error in a warning notice' );

// ── Comparison run: success, with divergence.
$GLOBALS['__transients']['snt_ml_embed_compare'] = array(
	'ok'     => true,
	'result' => array(
		'recommended' => 'centered',
		'scope'       => array( 'embedded_total' => 40, 'scored_sources' => 35, 'scheduled_in_centroid' => 5 ),
		'variants'    => array(
			'raw'      => array( 'divergence' => 0.2, 'hub' => array( 'hub_share' => 0.1, 'top_count' => 2, 'sources' => 35, 'distinct_targets' => 6 ) ),
			'centered' => array( 'divergence' => 0.82, 'hub' => array( 'hub_share' => 0.42, 'top_count' => 3, 'sources' => 35, 'distinct_targets' => 9 ) ),
		),
		'divergent'   => array(
			array( 'title' => 'Note A', 'only_embedding' => array( array( 'title' => 'Note B' ) ) ),
		),
	),
);
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, '40 notes embedded' ) && false !== strpos( $kit, 'Centred (recommended)' ), 'a successful comparison shows the scope line and marks the recommended variant' );
ok( false === strpos( $kit, '<os-disclosure' ) && false !== strpos( $kit, 'note has a pair TF-IDF does not find' ) && false !== strpos( $kit, 'Found only by embeddings' ) && false === strpos( $kit, 'more, the list is capped' ), '17.4.1: the divergent pairs are a ledger under their count line, no fold, no "+N more" under the cap' );
// 27 divergent notes: the table holds 25, the line says +2 more.
$GLOBALS['__transients']['snt_ml_embed_compare']['result']['divergent'] = array_fill( 0, 27, array( 'title' => 'Note A', 'only_embedding' => array( array( 'title' => 'Note B' ) ) ) );
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, '27 notes have pairs' ) && 25 === substr_count( $kit, '&quot;note&quot;:&quot;Note A&quot;' ) && false !== strpos( $kit, '+2 more, the list is capped.' ), '17.4.1: over the cap, 25 rows paint and the "+2 more" line says so' );
ok( 1 === substr_count( $kit, snt_leaf_row() ) && strpos( $kit, 'heading="TF-IDF vs embeddings"' ) > strrpos( $kit, '</os-card></os-grid>' ) && strpos( $kit, 'heading="TF-IDF vs embeddings"' ) > strpos( $kit, 'heading="Jev' ), '17.4.1: with a result on the leaf the ledger box stands alone at full width under the Jev box' );

// ── Comparison run: success, no divergence.
$GLOBALS['__transients']['snt_ml_embed_compare']['result']['divergent'] = array();
$kit = snt_leaf_paint( 'ai', 'models-budget' );
ok( false !== strpos( $kit, 'No divergence at all' ), 'a successful comparison with nothing divergent says so, and argues against adopting a hosted model' );

// ── 17.3.x: the platform-reported box, under the estimate and before the Jev
// meter, in the leaf's own right column. Read between its heading and the
// next section so the estimate's "$0.00" cannot satisfy or spoil a pin.
function platform_box( $html ) {
	$start = strpos( $html, 'heading="Platform-reported, this month"' );
	if ( false === $start ) { return ''; }
	$end = strpos( $html, '</os-section>', $start );
	return substr( $html, $start, $end - $start );
}
$GLOBALS['__transients'] = array();
$GLOBALS['__embed_configured'] = false;
$GLOBALS['__embed_token']      = '';
// (a) both keyring rows absent: the box paints, both rows say not set, no figure.
$kit = snt_leaf_paint( 'ai', 'models-budget' );
$box = platform_box( $kit );
ok( '' !== $box, 'the platform-reported box paints on the leaf' );
ok( strpos( $kit, 'heading="Platform-reported, this month"' ) > strpos( $kit, 'Spent this month' ) && strpos( $kit, 'heading="Platform-reported, this month"' ) < strpos( $kit, 'heading="Jev' ), 'the box sits under the estimate and before the Jev meter' );
ok( 2 === substr_count( $box, '>not set</dd>' ) && false !== strpos( $box, 'Anthropic bill (month to date)' ) && false !== strpos( $box, 'GitHub Actions minutes (account, month to date)' ), 'both rows read "not set" when their keyring rows are absent' );
ok( false === strpos( $box, '$0.00' ) && ! preg_match( '/\d min\b/', $box ) && false === strpos( $box, '<os-notice' ), 'absent is not zero: no dollar figure, no minute figure, no notice in the box' );
ok( false !== strpos( $box, 'never estimated' ), 'the box says it is platform-reported, against the estimate above' );
// (b) both reads ok: the platform figures, verbatim, with no quota beside the minutes.
$GLOBALS['__gh'] = array( 'ok' => true, 'src' => 'usage', 'used' => 1234, 'billed' => 0.0 );
$GLOBALS['__ai'] = array( 'ok' => true, 'total' => 120.39 );
$box = platform_box( snt_leaf_paint( 'ai', 'models-budget' ) );
ok( false !== strpos( $box, '1,234 min, $0.00 billed' ) && false !== strpos( $box, '>$120.39</dd>' ), 'both figures paint from the fixture: 1,234 min, $0.00 billed; $120.39' );
ok( false === strpos( $box, 'of 3,000' ) && false === strpos( $box, 'of 3000' ) && false === strpos( $box, 'not set' ) && false === strpos( $box, '<os-notice' ), 'no invented quota, no absent wording, no notice when both reads are ok' );
// (c) the GitHub read failed: unknown in words, a warn notice on the box, no minute figure.
$GLOBALS['__gh'] = array( 'ok' => false );
$box = platform_box( snt_leaf_paint( 'ai', 'models-budget' ) );
ok( false !== strpos( $box, '>unknown, the billing read failed</dd>' ) && false !== strpos( $box, '<os-notice tone="warning"' ) && ! preg_match( '/\d min\b/', $box ), 'a failed read says unknown with a warning notice on the box, never a number' );
ok( false !== strpos( $box, '>$120.39</dd>' ), 'the other row still paints its figure' );
ok( false !== strpos( $box, 'the Anthropic key has no probe' ), 'the notice does not send an Anthropic failure to a probe that does not exist' );
// (d) the door to the keyring, from the ai tab to connections/credentials.
ok( false !== strpos( $box, 'Connections › Credentials' ) && false !== strpos( $box, 'data-snt-tab="connections"' ) && false !== strpos( $box, 'data-snt-sub="credentials"' ), 'the hint carries the door to Connections › Credentials' );
$GLOBALS['__gh'] = null;
$GLOBALS['__ai'] = null;

// ── #1597: the Copilot line, inside the by-feature box, beside the cap and
// never in it. Read between the box's heading and its close so the platform
// box's figures cannot satisfy a pin.
function feature_box( $html ) {
	$start = strpos( $html, 'heading="This month, by feature"' );
	if ( false === $start ) { return ''; }
	$end = strpos( $html, '</os-section>', $start );
	return substr( $html, $start, $end - $start );
}
// (a) no turn yet: a recorded $0.00 on its own row, the total untouched.
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 50.0;
$GLOBALS['__spend']         = 12.5;
$GLOBALS['__spend_by_feat'] = array( 'drafts' => 9.0 );
$GLOBALS['__copilot']       = 0.0;
$box = feature_box( snt_leaf_paint( 'ai', 'models-budget' ) );
ok( false !== strpos( $box, 'Copilot (Ask AI), not counted against the cap' ), '#1597: the Copilot row paints inside the by-feature box, marked as outside the cap' );
ok( preg_match( '#Copilot \(Ask AI\), not counted against the cap</span><span class="snt-list__value">\$0\.00</span>#', $box ) === 1, '#1597: before the first turn the row reads a recorded $0.00, not an absent reading' );
ok( false !== strpos( $box, 'at list rates' ), '#1597: the figure says it is priced at list rates' );
ok( false === strpos( $box, 'no list price on file' ), '#1597: with no unpriced turn the box carries no unpriced hint' );
// (a2) turns ran on an id the table does not price: $0.00 stays, and one hint
// separates it from "no Ask AI this month", the Insights leaf's wording.
$GLOBALS['__copilot_unpriced'] = 3;
$box = feature_box( snt_leaf_paint( 'ai', 'models-budget' ) );
ok( false !== strpos( $box, '3 Copilot turn(s) this month reported no usage, or a model with no list price on file, and are not in the dollar figure.' ), '#1597: unpriced turns paint as a count with the Insights wording, so a silent $0.00 cannot pass for no Ask AI' );
ok( preg_match( '#Copilot \(Ask AI\), not counted against the cap</span><span class="snt-list__value">\$0\.00</span>#', $box ) === 1, '#1597: the unpriced count leaves the dollar figure at $0.00' );
$GLOBALS['__copilot_unpriced'] = 0;
// (b) a priced turn: the figure on the Copilot row, the total and the meter exactly as before.
$GLOBALS['__copilot'] = 0.0075;
$kit = snt_leaf_paint( 'ai', 'models-budget' );
$box = feature_box( $kit );
ok( preg_match( '#Copilot \(Ask AI\), not counted against the cap</span><span class="snt-list__value">\$0\.0075</span>#', $box ) === 1, '#1597: a sub-cent Copilot figure paints at four decimals on its own row, the feature rows\' rule' );
$GLOBALS['__copilot'] = 0.0105;
$kit = snt_leaf_paint( 'ai', 'models-budget' );
$box = feature_box( $kit );
ok( preg_match( '#Copilot \(Ask AI\), not counted against the cap</span><span class="snt-list__value">\$0\.01</span>#', $box ) === 1, '#1597: the issue\'s 1000-in 500-out Sonnet turn (0.0105) paints as $0.01' );
ok( false !== strpos( $box, 'Spent this month: $12.50 of $50.00 (25%)' ) && false !== strpos( $kit, '<os-progress-bar value="25" max="100"' ), '#1597: the total and the meter do not move for a Copilot turn' );
ok( false !== strpos( $box, '>drafts</span>' ) && false === strpos( $box, '>Copilot</span>' ), '#1597: the feature rows are the ledger the cap reads; Copilot is not one of them' );
$GLOBALS['__copilot'] = 3.5;
$box = feature_box( snt_leaf_paint( 'ai', 'models-budget' ) );
ok( preg_match( '#Copilot \(Ask AI\), not counted against the cap</span><span class="snt-list__value">\$3\.50</span>#', $box ) === 1, '#1597: a Copilot figure over a cent paints at two decimals' );
$GLOBALS['__copilot']          = 0.0;
$GLOBALS['__copilot_unpriced'] = 0;
$GLOBALS['__spend']            = 0.0;
$GLOBALS['__spend_by_feat']    = array();
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 0;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
