<?php
/**
 * Native window leaf: Connections → Webhooks (apps/sn-dashboard/parts/leaves/connections-webhooks.php).
 *
 * The oracle is the classic leaf (inc/webhooks-admin.php): the kit forms must
 * carry the same field names and the same four sn_actions, every readout the
 * classic prints must be printed, a hostile value must never reach the markup
 * raw, and none of wp-admin's markup may survive — across the rich, empty,
 * all-disabled, unconfigured and constant-locked states.
 *
 * Run: php tests/os-leaf-connections-webhooks.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The classic readers load whole under the harness; these are the writes they reference at run time only.
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } } }

require SNT_PATH . 'inc/settings.php';
require SNT_PATH . 'inc/webhooks.php';
require SNT_PATH . 'inc/uptime-status.php';
require SNT_PATH . 'inc/spend-watch.php';
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-render-sections.php';
require SNT_PATH . 'inc/webhooks-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-webhooks.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** The rich fixture: two webhooks (one enabled with a log, one disabled), a Better Stack token, one spend-watch token. */
function fixture( array $overrides = array() ) {
	$GLOBALS['__options'] = array_merge(
		array(
			'sn_webhooks'              => array(
				array( 'id' => 'wh_alpha', 'name' => 'Alpha flow', 'url' => 'https://hooks.example.test/alpha', 'secret' => 'ALPHASECRET1234567890wxyz', 'enabled' => true, 'events' => array( 'post.published', 'post.deleted' ), 'created_at' => 1756684800 ),
				array( 'id' => 'wh_beta', 'name' => 'Beta flow', 'url' => 'https://hooks.example.test/beta', 'secret' => 'BETASECRET0000000000abcd', 'enabled' => false, 'created_at' => 1756771200 ),
			),
			'sn_webhook_log_wh_alpha'  => array(
				array( 'delivery_id' => 'del_1', 'attempt' => 1, 'fired_at' => 1756800000, 'response_code' => 500, 'response_excerpt' => 'upstream timeout', 'success' => false ),
				array( 'delivery_id' => 'del_2', 'attempt' => 2, 'fired_at' => 1756800300, 'response_code' => 200, 'response_excerpt' => '{"ok":true}', 'success' => true ),
			),
			'sn_betterstack_api_token' => 'bs_token_ABCD7890',
			'sn_spend_gh_token'        => 'ghp_1234',
		),
		$overrides
	);
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/webhooks'] ), 'the painter is registered under connections/webhooks' );

// ── Rich state: two webhooks, the second just added (its secret shown once).
fixture();
$_GET['new_id'] = 'wh_beta';
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
unset( $_GET['new_id'] );
$kit = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_added_wh_beta' ) );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'monitoring_save', 'webhook_add', 'webhook_delete', 'webhook_update' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the four actions are offered, as on the classic leaf: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 3 === substr_count( $kit, '<os-form class="snt-form" os-action="post"' ), 'three os-forms dispatch post: two webhooks x delete, plus monitoring (update and add are native forms)' );
ok( 3 === substr_count( $kit, '<form class="snt-form snt-form--native" method="post" os-action="post">' ), 'three native forms dispatch post: two webhook_update editors plus webhook_add' );
ok( false !== strpos( $kit, 'heading="Alpha flow"' ) && false !== strpos( $kit, 'heading="Beta flow"' ), 'each webhook is a section headed by its name' );
ok( false !== strpos( $kit, '<os-code>wh_alpha</os-code>: created ' . gmdate( 'Y-m-d', 1756684800 ) ), 'the id and creation date are shown' );
ok( false !== strpos( $kit, '<os-text-field name="url" type="url" value="https://hooks.example.test/alpha"' ), 'the endpoint URL is a kit url field carrying the current URL' );
ok( false !== strpos( $kit, 'name="events[]" value="post.deleted" checked' ) && false !== strpos( $kit, 'name="events[]" value="post.updated"' ) && false === strpos( $kit, 'name="events[]" value="post.updated" checked' ), 'subscribed events are ticked and the others are not' );
ok( 3 === substr_count( $kit, 'name="events[]" value="post.published" checked' ), 'post.published is ticked on both editors and pre-ticked on the add form' );

// ── Guard: the events[]/enabled/rotate_secret group cannot ride an <os-form>
// (os-form.ts getValues() is last-wins and reads every checkbox as a boolean —
// see content-tags-parts.php's tags_form() for the identical mechanic on
// another leaf). Extract each <os-form>…</os-form> region and assert no
// bracketed name appears inside one; then positively pin that all four event
// keys survive as distinct NATIVE checkbox values, and that enabled/
// rotate_secret ride native inputs too.
preg_match_all( '/<os-form\b[^>]*>.*?<\/os-form>/s', $kit, $os_form_matches );
$os_form_regions = implode( '', $os_form_matches[0] );
ok( 0 === preg_match_all( '/name="[^"]*\[\]"/', $os_form_regions ), 'no bracketed name rides an os-form: os-form getValues() is last-wins and reads a checkbox as a boolean' );
ok( 12 === substr_count( $kit, 'type="checkbox" name="events[]"' ), 'all four event keys survive as distinct native checkboxes in each of the three forms (2 editors + add) — would collapse to one boolean through an os-form' );
ok( 3 === substr_count( $kit, 'type="checkbox" name="enabled"' ), 'enabled rides a native checkbox in each of the two editors and the add form' );
ok( 1 === substr_count( $kit, 'type="checkbox" name="rotate_secret"' ), 'rotate_secret rides a native checkbox' );
ok( false !== strpos( $kit, '<os-code>••••wxyz</os-code>' ) && 1 === substr_count( $kit, 'name="rotate_secret"' ) && 1 === substr_count( $classic, 'name="rotate_secret"' ), 'the existing webhook shows a masked secret with one rotate checkbox, as the classic does' );
ok( false !== strpos( $kit, '<os-code copy>BETASECRET0000000000abcd</os-code>' ) && false !== strpos( $kit, 'Copy this now' ), 'the just-added webhook reveals its secret once, copyable' );
ok( false !== strpos( $kit, 'name="webhook_id" value="wh_alpha"' ) && false !== strpos( $kit, 'os-confirm-title="Delete this webhook?"' ) && false !== strpos( $kit, 'os-confirm="Pending retries will be dropped. This cannot be undone."' ) && false !== strpos( $kit, 'os-confirm-danger' ), 'delete carries the webhook id and the classic confirm (question, title, danger)' );
ok( false !== strpos( $kit, 'heading="Recent deliveries (2)"' ) && false !== strpos( $kit, '<os-table' ), 'the delivery log is a disclosure around a kit table' );
$log_region = substr( $kit, strpos( $kit, 'heading="Recent deliveries (2)"' ) );
$log_region = substr( $log_region, 0, strpos( $log_region, '</os-disclosure>' ) );
ok( false !== strpos( $log_region, '<os-button' ) && false !== strpos( $log_region, 'os-action="refresh"' ) && false !== strpos( $log_region, '>Refresh</os-button>' ), 'the delivery log disclosure carries a refresh trigger — the Heartbeat live-refresh has no equivalent selector once this is an os-table' );
$newest = strpos( $kit, '&quot;fired_at&quot;:&quot;' . gmdate( 'Y-m-d H:i:s', 1756800300 ) . '&quot;,&quot;attempt&quot;:&quot;2&quot;,&quot;http&quot;:&quot;200&quot;,&quot;status&quot;:&quot;ok&quot;,&quot;response&quot;:&quot;{\&quot;ok\&quot;:true}&quot;' );
$oldest = strpos( $kit, '&quot;http&quot;:&quot;500&quot;,&quot;status&quot;:&quot;fail&quot;,&quot;response&quot;:&quot;upstream timeout&quot;' );
ok( false !== $newest && false !== $oldest && $newest < $oldest, 'log rows carry fired-at, attempt, HTTP, ok/fail and the response, newest first' );
ok( false !== strpos( $kit, '<b>2 webhooks configured</b> <os-badge tone="success">Active</os-badge><br>1 enabled, 1 disabled.' ), 'the status box counts configured and enabled webhooks with an Active pill' );
ok( false !== strpos( $kit, '<os-text-field name="sn_betterstack_token" type="text" value="••••7890"' ) && false !== strpos( $kit, 'name="sn_spend_gh_token" type="text" value="••••••••"' ) && false !== strpos( $kit, 'name="sn_spend_ai_admin_key" type="text" value=""' ), 'the monitoring form carries the obscured Better Stack and spend-watch tokens' );
ok( false !== strpos( $kit, 'heading="Better Stack status"' ) && false !== strpos( $kit, 'data-sn-uptime-status' ), 'with a token, the Better Stack mount is painted for the shell script to fill' );
ok( false !== strpos( $kit, 'SignalNoiseTools/' . SNT_VERSION . ' webhook' ) && false !== strpos( $kit, '&quot;site&quot;: &quot;https://example.test/&quot;' ) && false !== strpos( $kit, 'POST &lt;your URL&gt; HTTP/1.1' ), 'the payload reference names the version, the site and the request line' );
ok( false !== strpos( $kit, '<os-row gap="16"><os-stack col="8" gap="12">' ) && false !== strpos( $kit, '<aside col="4" aria-label="Status &amp; reference">' ), 'the two-column shell is an os-row: the work, then the status and reference rail' );

/**
 * The suite otherwise pins numbers/statuses/tables/confirms but has no oracle
 * for PROSE — the intro, the field helpers, the token hints, the section
 * descriptions, the payload prose. Delete any of them and every assertion
 * above still passes. Generic oracle: every TEXT NODE of the classic render
 * (labels, headings, hints, prose — the classic prints these as HTML text)
 * must appear SOMEWHERE in the normalised kit HTML. The kit prints the same
 * strings as component attributes (`heading="…"`, `label="…"`, `hint="…"`),
 * never as text nodes — checked against the whole kit string, not split.
 *
 * @param string $html Rendered HTML.
 * @return string[] Distinct text-node chunks longer than 3 chars.
 */
function webhooks_test_prose_chunks( $html ) {
	$text_nodes = preg_split( '/<[^>]+>/', $html );
	$chunks     = array();
	foreach ( (array) $text_nodes as $chunk ) {
		$chunk = trim( preg_replace( '/\s+/', ' ', html_entity_decode( (string) $chunk, ENT_QUOTES ) ) );
		if ( strlen( $chunk ) > 3 ) {
			$chunks[ $chunk ] = true;
		}
	}
	return array_keys( $chunks );
}

/**
 * @param string $classic_html Classic render.
 * @param string $kit_html     Kit render.
 * @param string $label        Message label (which fixture).
 */
function webhooks_test_prose_oracle( $classic_html, $kit_html, $label ) {
	// str_replace('\"', '"', …): the delivery log's response excerpt rides
	// inside a JSON-encoded table-data attribute, so a literal `"` in the
	// classic's <code> text survives there as a backslash-escaped `\"` —
	// same content, one extra layer of encoding this oracle should see through.
	$normalised_kit = trim( preg_replace( '/\s+/', ' ', str_replace( '\\"', '"', html_entity_decode( $kit_html, ENT_QUOTES ) ) ) );
	$misses         = array();
	foreach ( webhooks_test_prose_chunks( $classic_html ) as $chunk ) {
		if ( false === strpos( $normalised_kit, $chunk ) ) {
			$misses[] = $chunk;
		}
	}
	ok( empty( $misses ), $label . ': every classic text-node chunk appears in the kit output' . ( empty( $misses ) ? '' : ' — missing: ' . implode( ' | ', array_slice( $misses, 0, 10 ) ) ) );
}

fixture();
$_GET['new_id'] = 'wh_beta';
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
unset( $_GET['new_id'] );
$kit = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_added_wh_beta' ) );
webhooks_test_prose_oracle( $classic, $kit, 'rich fixture' );

// ── Rotated: the rotated webhook's secret is the one revealed.
$kit = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_rotated_wh_alpha' ) );
ok( false !== strpos( $kit, '<os-code copy>ALPHASECRET1234567890wxyz</os-code>' ) && false !== strpos( $kit, '<os-code>••••abcd</os-code>' ), 'a rotate flash reveals that webhook\'s new secret and masks the other' );

// ── Escaping: a hostile name and a hostile log excerpt never reach the markup raw.
fixture( array(
	'sn_webhooks'             => array( array( 'id' => 'wh_x', 'name' => '"><script>alert(1)</script>', 'url' => 'https://h.example.test/"onmouseover="x', 'secret' => 's3cr3t"><b>', 'enabled' => true, 'created_at' => 1 ) ),
	'sn_webhook_log_wh_x'     => array( array( 'attempt' => 1, 'fired_at' => 1, 'response_code' => 200, 'response_excerpt' => '<img src=x onerror=alert(1)>', 'success' => true ) ),
) );
$kit = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_added_wh_x' ) );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, '<img' ) && false === strpos( $kit, '"onmouseover' ) && false !== strpos( $kit, '&lt;script&gt;' ) && false !== strpos( $kit, '&lt;img src=x' ) && false !== strpos( $kit, 's3cr3t&quot;&gt;&lt;b&gt;' ), 'hostile name, URL, secret and log excerpt are escaped' );

// ── Empty state: no webhooks, no per-webhook forms, the rail says so.
fixture( array( 'sn_webhooks' => array() ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
$kit     = snt_leaf_paint( 'connections', 'webhooks' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'monitoring_save', 'webhook_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'empty: names and the two remaining actions match the classic leaf' );
ok( false !== strpos( $kit, '<os-notice tone="warning" not-dismissible><b>No webhooks configured</b> <os-badge tone="warning">Inactive</os-badge>' ), 'empty: the rail paints the warning status box with an Inactive pill' );

// ── All disabled: configured but inactive.
fixture( array( 'sn_webhooks' => array( array( 'id' => 'wh_off', 'name' => 'Off', 'url' => 'https://h.example.test/off', 'secret' => 'S', 'enabled' => false, 'created_at' => 1 ) ) ) );
$kit = snt_leaf_paint( 'connections', 'webhooks' );
ok( false !== strpos( $kit, '<b>1 webhook configured</b> <os-badge tone="warning">Inactive</os-badge><br>0 enabled, 1 disabled.' ) && false === strpos( $kit, '<os-disclosure' ), 'all disabled: the status box warns, and a webhook without deliveries has no log' );

// ── No Better Stack token: no panel, an empty token field.
fixture( array( 'sn_betterstack_api_token' => '' ) );
$kit = snt_leaf_paint( 'connections', 'webhooks' );
ok( false === strpos( $kit, 'data-sn-uptime-status' ) && false !== strpos( $kit, '<os-text-field name="sn_betterstack_token" type="text" value=""' ), 'unconfigured: no Better Stack panel, the token field is empty' );

// ── Constant-locked token: no name on the field, the lock is explained, the panel is up.
define( 'SN_BETTERSTACK_API_TOKEN', 'wp-config-token' );
fixture();
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
$kit     = snt_leaf_paint( 'connections', 'webhooks' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && ! in_array( 'sn_betterstack_token', snt_leaf_names( $kit ), true ), 'locked: neither form carries sn_betterstack_token: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( false !== strpos( $kit, '<b>Locked.</b> Set via <os-code>SN_BETTERSTACK_API_TOKEN</os-code> in <os-code>wp-config.php</os-code>.' ) && false !== strpos( $kit, 'data-sn-uptime-status' ), 'locked: the constant is named and the Better Stack panel is painted' );
webhooks_test_prose_oracle( $classic, $kit, 'constant-locked fixture' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
