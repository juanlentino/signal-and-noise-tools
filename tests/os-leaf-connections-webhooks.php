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
require SNT_PATH . 'inc/keyring.php'; // 15.2.0: the monitoring block reads sources from the keyring
require SNT_PATH . 'inc/keyring-admin.php'; // 2026-10-12: and says them in the keyring's words
require SNT_PATH . 'inc/webhooks-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-webhooks.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * 2026-10-16 (leaf anatomy): the only folds are the earned ones, the Setup
 * card's two (Uptime monitoring, Payload reference) and How this works; a
 * delivery log, an editor or the status is never folded.
 *
 * @param string $html A paint.
 * @return bool
 */
function wh_only_earned_folds( $html ) {
	preg_match_all( '/<os-disclosure heading="([^"]*)"/', (string) $html, $m );
	$h = $m[1];
	sort( $h );
	return array( 'How this works', 'Payload reference', 'Uptime monitoring' ) === $h && false === stripos( (string) $html, '<details' );
}

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
ok( array( 'webhook_add', 'webhook_delete', 'webhook_update' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '15.2.0: three actions, as on the classic leaf; monitoring_save is gone with its fields: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 2 === substr_count( $kit, '<os-form class="snt-form" os-action="post"' ), 'two os-forms dispatch post: two webhooks x delete (update and add are native forms; 15.2.0: no monitoring form)' );
ok( 3 === substr_count( $kit, '<form class="snt-form snt-form--native snt-form--stack" method="post" os-action="post">' ), 'three native forms dispatch post: two webhook_update editors plus webhook_add' );
$app_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' );
ok( 1 === preg_match( '/\.snt-app \.snt-form--stack \{\s*display: grid;\s*gap: 12px;/', $app_css ) && 1 === preg_match( '/\.snt-app \.snt-form--stack > \.snt-submit \{\s*justify-self: end;/', $app_css ), 'the stack class is styled: its fields stack and its button sits at the end, as an os-form footer does (PR #2013 review: the class alone pinned nothing)' );
ok( false !== strpos( $kit, '<os-field-row label="Signing secret"><os-code copy>' ) && false === strpos( $kit, 'snt-field-static' ), '#1600: the reveal-once secret is an os-field-row around the copyable os-code, the hint its sibling paragraph; no hand-rolled .snt-field-static div' );
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
// 2026-10-11 (owner rule, no folds): the log is an open section on both paints.
// The count the fold's heading carried, and the failures it hid, are the finding.
ok( wh_only_earned_folds( $kit ), 'editing: the only folds are the Setup card\'s two and How this works' );
ok( false !== strpos( $kit, '<os-section heading="Recent deliveries" description="The log keeps the newest 20 deliveries per webhook." stack><p class="snt-prose">2 deliveries logged, 1 failed; the last failure was ' . gmdate( 'Y-m-d H:i:s', 1756800000 ) . ', HTTP 500.</p><os-table' ), 'the delivery log is an open section: the store cap as context, then the finding (2 logged, 1 failed, when and how), then the kit table' );
$log_region = substr( $kit, strpos( $kit, 'heading="Recent deliveries"' ) );
$log_region = substr( $log_region, 0, strpos( $log_region, '</os-section>' ) );
// #1607: the live refresh is the runtime's os-poll (Experimental, OpenStation
// 1.1.10), gated on `sn_watch` because the editors and the add form carry text
// fields the morph resets on every tick unless focused. Editing (the default
// paint): no poll, no ghost Refresh, a Watch live go carrying sn_watch=1.
ok( '' !== $log_region && false === strpos( $log_region, '<os-button' ) && false === strpos( $kit, 'os-action="refresh"' ) && false === strpos( $kit, '>Refresh</os-button>' ), 'the delivery log section carries no ghost Refresh, and nothing on the leaf dispatches refresh' );
ok( false === strpos( $kit, 'os-poll' ), 'the editing paint polls nothing: a tick would reset an unfocused typed field to the server value' );
ok( false !== strpos( $kit, '<div class="snt-watch"><span class="snt-hint">Watch the delivery log refresh every 30 seconds; the forms fold while it does.</span><os-button variant="ghost" os-action="go" os-arg-sub="webhooks" os-arg-sn_watch="1">Watch live</os-button></div>' ), 'the watch bar offers a Watch live go that lands back on this leaf with sn_watch=1' );
$watch = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_added_wh_beta', 'params' => array( 'sn_watch' => '1' ) ) );
ok( '' !== $watch && 1 === substr_count( $watch, '<span os-action="poll" os-poll="30000" hidden></span>' ) && false === strpos( $watch, 'os-action="refresh"' ), 'watching: one hidden os-poll trigger on the no-op poll action, every 30 s, never on refresh' );
ok( false === strpos( $watch, '<form' ) && false === strpos( $watch, '<os-form' ) && array() === snt_leaf_actions( $watch ) && false === strpos( $watch, 'name="name"' ), 'watching: every form is folded away (no editor, no delete, no add), so no typed field exists for a tick to reset' );
ok( wh_only_earned_folds( $watch ) && false !== strpos( $watch, '<os-section heading="Recent deliveries" description="The log keeps the newest 20 deliveries per webhook." stack><p class="snt-prose">2 deliveries logged, 1 failed;' ), 'watching: the delivery log is the same open section with the same finding, so a repaint has no open state to lose' );
$wh_watch_log = array_values( array_filter( snt_leaf_tables( $watch ), static function ( $t ) { return in_array( 'Fired at', array_column( $t['columns'], 'label' ), true ); } ) );
ok( 1 === count( $wh_watch_log ) && 'fail' === ( $wh_watch_log[0]['data'][1]['status']['text'] ?? '' ), 'watching: the same table, the failed row still reads fail' );
ok( false !== strpos( $watch, 'heading="Alpha flow"' ) && false !== strpos( $watch, 'heading="Beta flow"' ) && false !== strpos( $watch, '<os-code>wh_alpha</os-code>: created ' ), 'watching: each webhook is still its own section with its id line' );
ok( false === strpos( $watch, 'BETASECRET0000000000abcd' ) && false !== strpos( $watch, 'heading="Uptime monitoring"' ) && false !== strpos( $watch, 'heading="Payload reference"' ), 'watching: the secret rides the editor, so it is not on this paint; the read-only sections stay' );
ok( false !== strpos( $watch, '<os-button variant="ghost" os-action="go" os-arg-sub="webhooks">Stop watching</os-button>' ) && false !== strpos( $watch, 'The delivery log refreshes every 30 seconds. The forms are folded while it does; stop watching to edit.' ), 'watching: a Stop watching go without sn_watch unfolds the forms and stops the poll' );
// The status is a slot cell now: a failed row carries a toned badge, an ok row plain text.
$wh_log = array_values( array_filter( snt_leaf_tables( $kit ), static function ( $t ) { return in_array( 'Fired at', array_column( $t['columns'], 'label' ), true ); } ) );
$wh_rows = $wh_log[0]['data'] ?? array();
ok(
	2 === count( $wh_rows )
	&& gmdate( 'Y-m-d H:i:s', 1756800300 ) === $wh_rows[0]['fired_at'] && '2' === $wh_rows[0]['attempt'] && '200' === $wh_rows[0]['http'] && 'ok' === ( $wh_rows[0]['status']['text'] ?? '' ) && '{"ok":true}' === $wh_rows[0]['response']
	&& '500' === $wh_rows[1]['http'] && 'fail' === ( $wh_rows[1]['status']['text'] ?? '' ) && 'upstream timeout' === $wh_rows[1]['response'],
	'log rows carry fired-at, attempt, HTTP, ok/fail and the response, newest first'
);
ok( '<os-badge tone="warning">fail</os-badge>' === snt_leaf_cell_html( $wh_log[0], $wh_rows[1]['status'] ?? null ) && 'ok' === snt_leaf_cell_html( $wh_log[0], $wh_rows[0]['status'] ?? null ), 'a failed delivery is toned (a warning badge, as the classic pill); an ok one is plain' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'two deliveries are under the cap: no "+N more" line' );
// 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): one Status card leads
// the leaf with a status line; a problem keeps its notice, an active leaf says
// its sentence as a hint. The intro is its How this works fold.
ok( 1 === preg_match( '#^<os-section heading="Status" stack><div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success" title="1 enabled, 1 disabled\. [^"]*">Active</os-badge><os-chip>2 webhooks configured</os-chip><os-chip>1 enabled, 1 disabled</os-chip><os-badge tone="success">Better Stack API token: Saved</os-badge></os-cluster></div>#', $kit ), 'the leaf leads with one status line: the verdict, the webhooks configured, how many enabled, the Better Stack token' );
ok( false === strpos( $kit, '<os-notice' ) && false !== strpos( $kit, '<p class="snt-hint">1 enabled, 1 disabled. Deliveries fire on each webhook’s subscribed post/page events' ), 'active: no notice; the status sentence is a hint under the line' );
ok( 1 === preg_match( '#<os-disclosure heading="How this works" hint="[^"]+"[^>]*><os-stack gap="12"><p class="snt-prose">POST a signed JSON payload#', $kit ) && 1 === substr_count( $kit, 'POST a signed JSON payload to your own endpoints' ), 'the intro is the Status card\'s How this works fold, painted once' );
ok( 1 === preg_match( '#<aside col="4" aria-label="Status &amp; reference"><os-stack gap="12"><os-section heading="Better Stack status" stack>.*</os-section><os-section heading="Setup" description="[^"]*" stack><os-disclosure heading="Uptime monitoring" hint="Better Stack API token: Saved"[^>]*data-snt-open="0">.*<os-disclosure heading="Payload reference" hint="[^"]+"#s', $kit ), 'the rail: the Better Stack status open, then one Setup card whose folds are Uptime monitoring (summary: the token\'s source) and the Payload reference' );
ok( false === strpos( $kit, 'name="sn_betterstack_token"' ) && false === strpos( $kit, 'name="sn_spend_gh_token"' ) && false === strpos( $kit, 'ABCD7890' ) && false === strpos( $kit, '••••7890' ) && false !== strpos( $kit, 'label="Better Stack API token"' ) && false !== strpos( $kit, '>Saved</span></os-fact>' ) && false !== strpos( $kit, 'Connections › Credentials' ), '15.2.0: no token field and no value; the source reads Saved (the keyring\'s word) and the section points to the keyring' );
// 2026-10-12 audit: the Uptime box listed the two spend credentials (not
// uptime) under names the keyring does not use, and pointed at "the status
// rail"; it names the Better Stack status box now, on both surfaces.
ok( false === strpos( $kit, 'GitHub billing token' ) && false === strpos( $kit, 'Anthropic admin key' ) && false === strpos( $classic, 'GitHub billing token' ) && false === strpos( $classic, 'Anthropic admin key' ), 'the spend credentials are not on the Uptime box, on either surface' );
ok( false === strpos( $kit, 'status rail' ) && false === strpos( $classic, 'status rail' ) && false !== strpos( $kit, 'powers the Better Stack status box on this tab' ) && false !== strpos( $classic, 'powers the Better Stack status box on this tab' ) && false !== strpos( $classic, '<th scope="row">Better Stack API token</th><td>Saved</td>' ), 'both surfaces name the box the token powers, with the keyring\'s words' );
ok( false === strpos( $kit, 'Tick "Rotate" below' ) && false !== strpos( $kit, 'Tick &quot;Rotate secret on save&quot; and save' ) && false === strpos( $classic, '"Rotate" below' ) && false !== strpos( $classic, 'Tick "Rotate secret on save" and save' ), 'the rotate hint names the real checkbox, on both surfaces' );
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
		// 2026-10-11 (no folds): the classic fold's summary "Recent deliveries
		// (N)" has no twin by design. The kit heading carries no figure and the
		// count is the finding sentence, pinned above ("2 deliveries logged").
		if ( 1 === preg_match( '/^Recent deliveries \(\d+\)$/', $chunk ) ) {
			continue;
		}
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
ok( false !== strpos( $kit, '<os-code copy>ALPHASECRET1234567890wxyz</os-code>' ) && false !== strpos( $kit, '<os-code>••••abcd</os-code>' ) && wh_only_earned_folds( $kit ), 'a rotate flash reveals that webhook\'s new secret and masks the other; no fold' );

// ── Escaping: a hostile name and a hostile log excerpt never reach the markup raw.
fixture( array(
	'sn_webhooks'             => array( array( 'id' => 'wh_x', 'name' => '"><script>alert(1)</script>', 'url' => 'https://h.example.test/"onmouseover="x', 'secret' => 's3cr3t"><b>', 'enabled' => true, 'created_at' => 1 ) ),
	'sn_webhook_log_wh_x'     => array( array( 'attempt' => 1, 'fired_at' => 1, 'response_code' => 200, 'response_excerpt' => '<img src=x onerror=alert(1)>', 'success' => true ) ),
) );
$kit = snt_leaf_paint( 'connections', 'webhooks', array( 'flash' => 'wh_added_wh_x' ) );
ok( false === strpos( $kit, '<script>' ) && false === strpos( $kit, '<img' ) && false === strpos( $kit, '"onmouseover' ) && false !== strpos( $kit, '&lt;script&gt;' ) && false !== strpos( $kit, '&lt;img src=x' ) && false !== strpos( $kit, 's3cr3t&quot;&gt;&lt;b&gt;' ), 'hostile name, URL, secret and log excerpt are escaped' );
ok( wh_only_earned_folds( $kit ), 'hostile: no fold' );

// ── Empty state: no webhooks, no per-webhook forms, the rail says so.
fixture( array( 'sn_webhooks' => array() ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
$kit     = snt_leaf_paint( 'connections', 'webhooks' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'webhook_add' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'empty: names and the one remaining action match the classic leaf' );
ok( false !== strpos( $kit, '<os-notice tone="warning" not-dismissible><b>No webhooks configured</b> <os-badge tone="warning">Inactive</os-badge>' ) && wh_only_earned_folds( $kit ), 'empty: the rail paints the warning status box with an Inactive pill; no fold' );
// PR #2013 review: watching hides Add a webhook, so the empty notice names the
// step before it rather than pointing at a box that is not there.
$kitWE = snt_leaf_paint( 'connections', 'webhooks', array( 'params' => array( 'sn_watch' => '1' ) ) );
ok( false !== strpos( $kit, 'Add one under Add a webhook' ) && false !== strpos( $kitWE, 'Stop watching, then add one under Add a webhook' ) && false === strpos( $kitWE, 'heading="Add a webhook"' ), 'empty: the notice points at Add a webhook only when it is on the page' );

// ── All disabled: configured but inactive.
fixture( array( 'sn_webhooks' => array( array( 'id' => 'wh_off', 'name' => 'Off', 'url' => 'https://h.example.test/off', 'secret' => 'S', 'enabled' => false, 'created_at' => 1 ) ) ) );
$kit = snt_leaf_paint( 'connections', 'webhooks' );
ok( false !== strpos( $kit, '<b>1 webhook configured</b> <os-badge tone="warning">Inactive</os-badge><br>0 enabled, 1 disabled.' ) && false === strpos( $kit, 'heading="Recent deliveries"' ) && wh_only_earned_folds( $kit ), 'all disabled: the status box warns, and a webhook without deliveries has no log' );

// ── No Better Stack token: no panel, an empty token field.
fixture( array( 'sn_betterstack_api_token' => '' ) );
$kit = snt_leaf_paint( 'connections', 'webhooks' );
ok( false === strpos( $kit, 'data-sn-uptime-status' ) && false !== strpos( $kit, '>Not set</span></os-fact>' ), 'unconfigured: no Better Stack panel, the token source reads Not set' );
ok( 1 === preg_match( '#<os-disclosure heading="Uptime monitoring" hint="Better Stack API token: Not set"[^>]*\sopen[\s=>]#', $kit ) && false !== strpos( $kit, '<os-badge tone="warning">Better Stack API token: Not set</os-badge>' ), 'unconfigured: the Uptime monitoring fold opens itself, and the status line says the token is not set' );
ok( wh_only_earned_folds( $kit ), 'unconfigured: no fold' );

// ── Constant-locked token: no name on the field, the lock is explained, the panel is up.
define( 'SN_BETTERSTACK_API_TOKEN', 'wp-config-token' );
fixture();
$classic = snt_leaf_classic_html( 'sn_admin_render_webhooks_section' );
$kit     = snt_leaf_paint( 'connections', 'webhooks' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && ! in_array( 'sn_betterstack_token', snt_leaf_names( $kit ), true ), 'locked: neither form carries sn_betterstack_token: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( false !== strpos( $kit, '>wp-config (SN_BETTERSTACK_API_TOKEN)</span></os-fact>' ) && false !== strpos( $kit, 'data-sn-uptime-status' ), 'locked: the source names its constant as Credentials does, and the Better Stack panel is painted' );
ok( wh_only_earned_folds( $kit ), 'locked: no fold' );
webhooks_test_prose_oracle( $classic, $kit, 'constant-locked fixture' );

// ── The cap and the live shape: three webhooks, 20 + 20 + 10 deliveries (the
// store keeps 20 per webhook, SN_WEBHOOK_LOG_CAP), 3 failed in all.
/** @return array<int,array<string,mixed>> $n log entries, oldest first, failing at the given indexes. */
define( 'WH_T0', time() - 20 * 5400 ); // the live fixture ends near now: one delivery every 90 minutes.
function webhooks_test_log( $n, array $failed_at = array(), $base = null ) {
	$base = null === $base ? WH_T0 : (int) $base;
	$log  = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$fail  = in_array( $i, $failed_at, true );
		$log[] = array( 'delivery_id' => 'del_' . $base . '_' . $i, 'attempt' => $fail ? 1 : 1 + ( $i % 2 ), 'fired_at' => $base + $i * 5400, 'response_code' => $fail ? 503 : 200, 'response_excerpt' => $fail ? 'Service Unavailable' : '{"ok":true}', 'success' => ! $fail );
	}
	return $log;
}
fixture( array( 'sn_webhook_log_wh_alpha' => webhooks_test_log( 10 ) ) );
$kit = snt_leaf_paint( 'connections', 'webhooks' );
ok( false !== strpos( $kit, '10 deliveries logged, none failed; the newest was ' . gmdate( 'Y-m-d H:i:s', WH_T0 + 9 * 5400 ) . '.' ) && false === strpos( $kit, 'snt-ledger-more' ), 'exactly 10 deliveries, none failed: the finding says so, every row painted, no "+N more"' );
fixture(
	array(
		'sn_webhooks'              => array(
			array( 'id' => 'wh_n8n', 'name' => 'n8n publish flow', 'url' => 'https://n8n.example.test/webhook/sn', 'secret' => 'N8NSECRET000000000000aaaa', 'enabled' => true, 'events' => array( 'post.published', 'post.updated' ), 'created_at' => 1756684800 ),
			array( 'id' => 'wh_zap', 'name' => 'Zapier digest', 'url' => 'https://hooks.zapier.example.test/abc', 'secret' => 'ZAPSECRET000000000000bbbb', 'enabled' => true, 'events' => array( 'post.published' ), 'created_at' => 1757684800 ),
			array( 'id' => 'wh_arc', 'name' => 'Archive mirror', 'url' => 'https://archive.example.test/in', 'secret' => 'ARCSECRET000000000000cccc', 'enabled' => true, 'events' => array( 'post.published', 'post.deleted' ), 'created_at' => 1758684800 ),
		),
		'sn_webhook_log_wh_n8n'    => webhooks_test_log( 20, array( 4, 17 ) ),
		'sn_webhook_log_wh_zap'    => webhooks_test_log( 20, array( 2 ), WH_T0 + 3000 ),
		'sn_webhook_log_wh_arc'    => webhooks_test_log( 10, array(), WH_T0 + 6000 ),
		'sn_webhook_log_wh_alpha'  => array(),
	)
);
$kitL = snt_leaf_paint( 'connections', 'webhooks' );
ok( false !== strpos( $kitL, '20 deliveries logged, 2 failed; the last failure was ' . gmdate( 'Y-m-d H:i:s', WH_T0 + 17 * 5400 ) . ', HTTP 503.' ) && false !== strpos( $kitL, '20 deliveries logged, 1 failed;' ) && false !== strpos( $kitL, '10 deliveries logged, none failed;' ), 'live-shaped: each webhook\'s finding counts its log and its failures (2 + 1 + 0 = 3 of 50)' );
ok( 2 === substr_count( $kitL, '<p class="snt-hint snt-ledger-more">+10 more deliveries; the list is capped, not complete. Newest first, so the tail is the oldest end.</p>' ) && 3 === count( array_filter( snt_leaf_tables( $kitL ), static function ( $t ) { return 10 === count( $t['data'] ); } ) ), 'live-shaped: each log paints its newest 10, and the two 20-entry logs say "+10 more"' );
ok( wh_only_earned_folds( $kitL ), 'live-shaped: no fold' );
snt_leaf_preview( 'connections-webhooks', $kitL );
$kitW = snt_leaf_paint( 'connections', 'webhooks', array( 'params' => array( 'sn_watch' => '1' ) ) );
ok( wh_only_earned_folds( $kitW ) && 3 === substr_count( $kitW, 'heading="Recent deliveries"' ) && 1 === substr_count( $kitW, 'os-poll=' ), 'live-shaped, watching: the three logs stay open sections under one poll' );
snt_leaf_preview( 'connections-webhooks-watching', $kitW );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
