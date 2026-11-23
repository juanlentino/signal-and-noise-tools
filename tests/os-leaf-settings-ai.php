<?php
/**
 * Settings › AI, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-ai.php) and the classic section
 * (inc/admin-forms/settings-ai.php). The third pane of the Settings tab.
 *
 * Nine settings moved here as FIELDS; no leaf moved whole, because each of
 * the three leaves they came from keeps readings: AI › Models & Budget (the
 * spend, the platform bill, the Jev meter, the embeddings comparison), AI ›
 * MCP Clients (the four doors' states, how to connect) and Connections › Cron
 * (the ledger, the last-run tally, Run now). So sn_admin_subtab_moves() has no
 * line for them and each keeps one line saying where its settings went.
 *
 * This suite: the registry, the pane's shape, the models and budget form, the
 * scheduled reads switch, the old screens' pointers and the repointed
 * sentences. The three MCP door controls are tests/os-leaf-settings-ai-doors.php.
 *
 * Run: php tests/os-leaf-settings-ai.php
 */
require_once __DIR__ . '/lib/settings-ai-fixture.php';

$classic = ai_classic();
$kit     = ai_kit();

// ── 1) The registry: a third pane (a fourth, Front end, follows it), and no leaf left its tab.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );
ok( array( 'general', 'notifications', 'security', 'ai' ) === array_slice( array_keys( $panes ), 0, 4 ) && 'AI' === ( $panes['ai']['label'] ?? '' ) && 'sn_admin_render_settings_ai_section' === ( $panes['ai']['render'] ?? '' ) && ! empty( $panes['ai']['wide'] ), 'ai ("AI") is the fourth pane (the third until General took first place), after security, wide: it paints its own boxes' );
ok( array( 'models-budget', 'mcp-connect', 'agent-tools' ) === array_keys( $by_tab['ai']['sub_tabs'] ) && isset( $by_tab['connections']['sub_tabs']['cron'] ), 'the AI tab keeps its three leaves and Connections keeps Cron: fields moved, no leaf did' );
ok( false !== strpos( (string) $by_tab['settings']['subtitle'], 'AI:' ) && false === stripos( (string) $by_tab['ai']['subtitle'], 'what they may cost' ), 'the subtitles follow: Settings names the pane, the AI tab no longer promises the budget setting' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/ai'] ) && array( 'general' => 'General', 'notifications' => 'Notifications and reports', 'security' => 'Security', 'ai' => 'AI', 'front-end' => 'Front end', 'accounts' => 'Accounts and connections', 'analytics' => 'Analytics' ) === \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'settings' ), 'native: the painter is registered under settings/ai and the frame lists the seven panes' );
$here = array( 'tab' => 'settings', 'sub' => 'ai', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'ai' ) && $here === sn_admin_post_redirect_target( 'settings', 'ai' ), 'classic: settings/ai is canonical and a save posted from it returns to it' );
foreach ( array( array( 'ai', 'models-budget' ), array( 'ai', 'mcp-connect' ), array( 'connections', 'cron' ) ) as $old ) {
	ok( null === sn_admin_canonical_destination( $old[0], $old[1] ), "$old[0]/$old[1] is NOT redirected: fields left it, the leaf stayed" );
}

// ── 2) The pane holds the nine settings and nothing else, on both twins.
$nine = array( 'sn_mcp_read_uuid', 'sn_mcp_rw_uuid', 'sn_remote_enabled', 'snt_scheduled_reads_enabled', 'theme_ai_alt_model', 'theme_ai_model', 'theme_ai_monthly_budget', 'theme_jev_credit', 'theme_jev_cycle_day' );
$five = array( 'ai_settings_save', 'bind_mcp_read_credential', 'bind_mcp_rw_credential', 'remote_toggle', 'scheduled_reads_save' );
ok( '' !== $kit && $nine === snt_leaf_names( $kit ), 'native: exactly the nine settings: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( '' !== $classic && $nine === array_values( array_diff( snt_leaf_names( $classic ), array( 'submit' ) ) ), 'classic: exactly the nine settings (submit_button()\'s own "submit" aside): ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( $five === snt_leaf_actions( $kit ) && $five === snt_leaf_actions( $classic ), 'the five existing actions, unchanged, on both twins: ' . implode( ',', snt_leaf_actions( $classic ) ) );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ), 'native: no wp-admin markup, and no fold: a setting on a settings pane is open' );
ok( 1 === preg_match( '#^<div class="sn-2up"><form [^>]*class="sn-ai-settings-form">.*<h2 class="sn-fieldset-h">Models and budget</h2>.*</form><div class="sn-fieldset"><h2 class="sn-fieldset-h">MCP doors</h2>.*</div><form [^>]*class="sn-fieldset">.*<h2 class="sn-fieldset-h">Scheduled reads</h2>.*</form></div>$#s', $classic ), 'classic: three headed boxes in .sn-2up: Models and budget, MCP doors, Scheduled reads' );
$at = static fn( $h ) => strpos( $kit, '<os-section heading="' . $h . '"' );
ok( false !== $at( 'Models and budget' ) && $at( 'Models and budget' ) < $at( 'Scheduled reads' ) && $at( 'Scheduled reads' ) < $at( 'Read-door credential (optional)' ) && $at( 'Read-door credential (optional)' ) < $at( 'Write-door credential' ) && $at( 'Write-door credential' ) < $at( 'Remote door' ), 'native: headed boxes by subject: Models and budget, Scheduled reads, then the three doors in door order' );
foreach ( array( 'Spent this month', 'This month, by feature', 'Workers AI token', 'TF-IDF', 'Run now', 'Last run', 'claude_desktop_config.json', 'Jev meter' ) as $reading ) {
	ok( false === strpos( $kit . $classic, $reading ), "no reading or operation came along: the pane does not say \"$reading\"" );
}
ok( false === strpos( $kit . $classic, "\xe2\x80\x94" ), 'no em dash on either twin' );

// ── 3) Models and budget: one form, five fields, the labels and hints they had.
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'ai_settings_save', SNT_AI_SAVE_FIELDS ), "<$tag>: one form posts ai_settings_save and it carries all five fields the handler writes" );
}
ok( -1 === snt_whole_forms( '<form><input name="action" value="sn_ai_settings_save"><input name="theme_ai_model"></form>', 'form', 'ai_settings_save', SNT_AI_SAVE_FIELDS ) && 0 === snt_whole_forms( '<form>sn_other</form>', 'form', 'ai_settings_save', SNT_AI_SAVE_FIELDS ), 'the guard can fail: a form that omits the budget is -1, a form on another action is not counted' );
ok( 1 === preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php\?_wpnonce=nonce-sn_ai_settings_save" class="sn-ai-settings-form"><input type="hidden" name="action" value="sn_ai_settings_save">#', $classic ), 'classic: the form posts ai_settings_save with that action\'s nonce, as it did' );
foreach ( array(
	'<label class="sn-field-label" for="sn_theme_ai_model">Prose model</label><select id="sn_theme_ai_model" name="theme_ai_model">',
	'<p class="sn-field-helper">Used for AI-assisted prose features (drafts, insights, meta descriptions).</p>',
	'<label class="sn-field-label" for="sn_theme_ai_alt_model">Vision model (alt text)</label><select id="sn_theme_ai_alt_model" name="theme_ai_alt_model">',
	'<label class="sn-field-label" for="sn_theme_ai_monthly_budget">Monthly budget (USD)</label><input type="number" min="0" step="0.5" id="sn_theme_ai_monthly_budget" name="theme_ai_monthly_budget" value="50.00"><p class="sn-field-helper">Set 0 to remove the cap.</p>',
	'<label class="sn-field-label" for="sn_theme_jev_credit">Jev monthly credit (USD)</label><input type="number" min="0" step="0.5" id="sn_theme_jev_credit" name="theme_jev_credit" value="5.00">',
	'<label class="sn-field-label" for="sn_theme_jev_cycle_day">Jev credit renews on day</label><input type="number" min="1" max="28" step="1" id="sn_theme_jev_cycle_day" name="theme_jev_cycle_day" value="17">',
	'<p class="sn-fieldset-actions-hint">Model changes apply to the next AI call. The budget is evaluated per calendar month.</p><button type="submit" class="button button-primary">Save AI settings</button>',
) as $piece ) {
	ok( false !== strpos( $classic, $piece ), 'classic keeps: ' . substr( strip_tags( $piece ), 0, 60 ) );
}
ok( 1 === preg_match( '#<os-section heading="Models and budget" description="Model changes apply to the next AI call\. The budget is evaluated per calendar month\." stack><os-form class="snt-form" os-action="post" submit-label="Save AI settings"[^>]*><os-field-row label="Prose model" hint="Used for AI-assisted prose features[^"]*"><os-select name="theme_ai_model" value="claude-sonnet-5">.*<os-field-row label="Vision model \(alt text\)"><os-select name="theme_ai_alt_model".*<os-field-row label="Monthly budget \(USD\)" hint="Set 0 to remove the cap\."><os-number-field name="theme_ai_monthly_budget" value="50\.00" min="0" step="0\.5">.*<os-field-row label="Jev monthly credit \(USD\)"[^>]*><os-number-field name="theme_jev_credit" value="5\.00".*<os-field-row label="Jev credit renews on day"><os-number-field name="theme_jev_cycle_day" value="17" min="1" max="28" step="1">(?:(?!</os-form>).)*value="sn_ai_settings_save"><input type="hidden" name="_wpnonce" value="nonce-sn_ai_settings_save"></os-form></os-section>#s', $kit ), 'native: the form keeps its five labels, hints, bounds, values, action and nonce, open in a box headed Models and budget' );
$models_box = preg_match( '#<os-section heading="Models and budget".*?</os-section>#s', $kit, $mm ) ? $mm[0] : '';
$models_frm = preg_match( '#<form [^>]*class="sn-ai-settings-form">.*?</form>#s', $classic, $mm ) ? $mm[0] : '';
$catalog    = count( sn_theme_ai_models() ) + count( sn_theme_ai_vision_models() );
ok( $catalog > 2 && $catalog === substr_count( $models_box, '<os-option' ) && $catalog === substr_count( $models_frm, '<option ' ), 'both selects offer every model on the catalog\'s two lists, on both twins' );
$GLOBALS['__written'] = array();
sn_handle_ai_settings_save( array() );
ok( 0.0 === (float) $GLOBALS['__written']['theme.ai_monthly_budget'] && 5.0 === (float) $GLOBALS['__written']['theme.jev_credit'] && 17 === $GLOBALS['__written']['theme.jev_cycle_day'], 'why they share a form: a save that omits the fields removes the cap and resets the Jev credit and its day' );
$GLOBALS['__settings']['theme.ai_monthly_budget'] = 50.0;

// ── 4) The old Models & Budget leaf: readings, one pointer, no way to post the save.
$old_c = snt_leaf_classic_html( 'sn_admin_render_ai_settings_form' );
$old_k = snt_leaf_paint( 'ai', 'models-budget' );
ok( array() === snt_leaf_names( $old_c ) && array() === snt_leaf_names( $old_k ) && 0 === snt_whole_forms( $old_c, 'form', 'ai_settings_save', SNT_AI_SAVE_FIELDS ) && 0 === snt_whole_forms( $old_k, 'os-form', 'ai_settings_save', SNT_AI_SAVE_FIELDS ) && false === strpos( $old_c . $old_k, 'ai_settings_save' ), 'AI › Models & Budget holds no setting field and no form that could post ai_settings_save, on both twins' );
ok( false !== strpos( $old_c, 'Spent this month: $0.00 of $50.00' ) && false !== strpos( $old_k, 'Spent this month: $0.00 of $50.00' ) && false !== strpos( $old_k, 'heading="AI budget"' ) && false !== strpos( $old_k, 'heading="This month, by feature"' ) && false !== strpos( $old_c, 'Workers AI token (semantic embeddings)' ) && false !== strpos( $old_k, 'heading="Workers AI token"' ), 'it keeps its readings: the spend against the cap, the by-feature box, the Workers AI token row' );
ok( 1 === substr_count( $old_c, '<p class="sn-field-helper">The models, the monthly budget and the Jev credit moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=ai">Settings › AI</a>.</p>' ), 'classic: one line says where the settings went, with a link to the pane' );
ok( 1 === preg_match( '#<p class="snt-hint">The models, the monthly budget and the Jev credit moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="ai"[^>]*>Settings › AI</os-button>\.</p>#', $old_k ) && 1 === substr_count( $old_k, 'moved to' ), 'native: the same line, once, an in-window link to settings/ai' );

// ── 5) Scheduled reads: the switch on the pane; the tally and Run now stay on Cron.
$GLOBALS['__options'][ SNT_SCHEDULED_READS_HISTORY ] = array( array( 'ran_at' => time() - 3600, 'tools' => array( 'a' => array( 'error' => false ), 'b' => array( 'error' => true ) ) ) );
$classic = ai_classic();
$kit     = ai_kit();
$hint    = 'Read door only: the run goes through the same gate, kill switch, and telemetry as a live caller, and the tool list is fixed in code.';
$label   = 'Run a fixed set of read-door abilities daily and keep a two-week outcome history';
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'scheduled_reads_save', array( 'snt_scheduled_reads_enabled' ), array( 'snt_scheduled_reads_now' ) ) && false === strpos( $html, 'snt_scheduled_reads_now' ), "<$tag>: the pane's one scheduled_reads_save form carries the switch, and no Run now" );
}
ok( 1 === preg_match( '#<form method="post" action="[^"]*" class="sn-fieldset"><input type="hidden" name="action" value="sn_scheduled_reads_save" /><input type="hidden" name="_wpnonce" value="nonce-sn_scheduled_reads_save"><h2 class="sn-fieldset-h">Scheduled reads</h2><label><input type="checkbox" name="snt_scheduled_reads_enabled" value="1"  checked="checked" /> ' . $label . '</label><p class="sn-field-helper">' . preg_quote( $hint, '#' ) . '</p><div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save</button></div></form>#', $classic ), 'classic: the checkbox inside its label, the hint, Save, the action and its nonce' );
ok( 1 === preg_match( '#<os-section heading="Scheduled reads" stack><os-form class="snt-form" os-action="post" submit-label="Save"[^>]*><os-field-row hint="' . preg_quote( $hint, '#' ) . '"><os-checkbox-label name="snt_scheduled_reads_enabled" value="1" checked label="' . $label . '"></os-checkbox-label></os-field-row><input type="hidden" name="action" value="sn_scheduled_reads_save">#', $kit ), 'native: the checkbox with its label, its hint on the field row, Save' );
$cron_c = ai_out( 'snt_scheduled_reads_render_cron_box' );
$cron_k = \SignalNoise\OpenStationHost\Dashboard\Leaves\cron_scheduled_reads_html();
foreach ( array( 'form' => $cron_c, 'os-form' => $cron_k ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'scheduled_reads_save', array( 'snt_scheduled_reads_enabled' ), array( 'snt_scheduled_reads_now' ) ) && false === strpos( $html, 'name="snt_scheduled_reads_enabled"' ) && 1 === preg_match( '#<input type="hidden" name="snt_scheduled_reads_now"#', $html ), "<$tag>: the form Cron keeps is Run now alone, its differentiator a HIDDEN field, so the handler returns before it writes the switch" );
	ok( false !== strpos( $html, 'Scheduled read-only runs' ) && false !== strpos( $html, '1 of 2 reads failed.' ) && false !== strpos( $html, 'Run now' ), "<$tag>: Cron keeps the box, its last-run tally and Run now" );
}
ok( -1 === snt_whole_forms( '<form>sn_scheduled_reads_save<button name="snt_scheduled_reads_now" value="1"></button></form>', 'form', 'scheduled_reads_save', array( 'snt_scheduled_reads_enabled' ), array( 'snt_scheduled_reads_now' ) ), 'the guard can fail: Run now as a BUTTON name (the classic box before the move) is -1, since a plain submit would write the switch off' );
ok( 1 === substr_count( $cron_c, '<p class="sn-field-helper">The daily run switch moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=ai">Settings › AI</a>.</p>' ) && 1 === preg_match( '#<p class="snt-hint">The daily run switch moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="ai"[^>]*>Settings › AI</os-button>\.</p>#', $cron_k ), 'Cron says where the switch went, with a link, on both twins' );
$GLOBALS['__written'] = array();
ok( 'scheduled_reads_run_failed' === sn_handle_scheduled_reads_save( \snt_os_host_expand( array( 'snt_scheduled_reads_now' => '1' ) ) ) && array() === $GLOBALS['__written'], 'Run now returns before the switch is written: it stays ON' );
ok( 'scheduled_reads_saved' === sn_handle_scheduled_reads_save( \snt_os_host_expand( array( 'snt_scheduled_reads_enabled' => false ) ) ) && false === $GLOBALS['__written']['operations.scheduled_reads_enabled'], 'a plain save writes the switch from what the form carries' );
foreach ( array( false, true ) as $want ) {
	$GLOBALS['__settings']['operations.scheduled_reads_enabled'] = $want;
	preg_match( '/<[^>]*name="snt_scheduled_reads_enabled"[^>]*>/', ai_kit(), $t );
	// os-form reads a checkbox-shaped tag's boolean off `checked`; the host expands false to ''.
	$read = isset( $t[0] ) && false !== strpos( $t[0], '<os-checkbox-label' ) ? ( 1 === preg_match( '/\schecked(\s|=|>|$)/', $t[0] ) ) : null;
	ok( $want === $read && $want === ! empty( \snt_os_host_expand( array( 'snt_scheduled_reads_enabled' => $read ) )['snt_scheduled_reads_enabled'] ), 'the switch painted ' . ( $want ? 'ON reads true and expands non-empty' : 'OFF reads false and expands empty, which the handler\'s ! empty() reads as off' ) );
}

// ── 6) Every sentence that sent a reader to the old spots names the pane now.
foreach ( array(
	'inc/ai-bootstrap/generate.php'                                  => array( 'Raise it in S&N Home, Settings › AI, or wait for next month.', 'Front-End settings tab, or wait' ),
	'inc/insights-admin.php'                                         => array( 'Set a cap under Settings &rsaquo; AI', 'Set a cap under AI &rsaquo; Models' ),
	'apps/sn-dashboard/parts/leaves/monitoring-insights-parts.php'   => array( "'Settings › AI', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'ai'", "'AI › Models & Budget'" ),
	'inc/analytics-render-settings.php'                              => array( 'tab=settings&sub=ai', 'tab=ai&sub=models-budget' ),
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-parts.php'  => array( "array( 'tab' => 'settings', 'sub' => 'ai'", "'sub' => 'models-budget'" ),
	'inc/admin-forms/mcp-connect.php'                                => array( 'the one bound to this door in Settings › AI', 'Tools -> MCP' ),
	'apps/sn-dashboard/parts/leaves/ai-agent-tools-doors.php'        => array( 'Its switch is in', 'Its switch and its state are under' ),
) as $file => $pair ) {
	$src = (string) file_get_contents( SNT_PATH . $file );
	ok( false !== strpos( $src, $pair[0] ) && false === strpos( $src, $pair[1] ), "$file points at Settings › AI, not at the old spot" );
}

snt_leaf_preview( 'settings-ai', $kit );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
