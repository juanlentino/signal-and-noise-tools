<?php
/**
 * Native window leaf: Connections → Discography
 * (apps/sn-dashboard/parts/leaves/connections-music.php + -parts.php).
 *
 * The oracle is the classic leaf: both kit forms must carry the same field
 * names (the hidden `tab=content` / `sub=music` pair included) and the same
 * two sn_actions, in the editable and the constant-locked state. The Muso.AI
 * profile ID left for Settings › Accounts and connections in 2026-11: the
 * leaf keeps one line saying so, and the pane's Muso.AI box is checked here
 * in both states, since this suite sets the constant that locks it. Every
 * readout — the four status-box states, the masked credentials, the rail's
 * Status facts, the featured helper — must survive; a hostile stored value
 * must be escaped; and none of wp-admin's markup may remain. The readers are
 * the REAL ones (store, Spotify config, Muso profile, featured record, mask),
 * fed through the harness's get_option().
 *
 * Run: php tests/os-leaf-connections-music.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's readers, real: they only need get_option() (the harness's) at paint time.
require SNT_PATH . 'inc/settings.php';          // sn_mask_secret()
require SNT_PATH . 'inc/discography-store.php'; // sn_discography_get()
require SNT_PATH . 'inc/muso-api.php';          // sn_muso_profile_id()
require SNT_PATH . 'inc/spotify-api.php';       // sn_spotify_config()
require SNT_PATH . 'inc/music-featured.php';    // sn_music_featured_get()
require SNT_PATH . 'inc/admin-shell.php';
require SNT_PATH . 'inc/admin-legacy-redirect.php'; // sn_admin_post_redirect_target(): where the stale hidden pair lands.
require SNT_PATH . 'inc/admin-forms/music.php';
require SNT_PATH . 'inc/admin-forms/settings-accounts.php'; // the line saying where the profile ID went, and the pane's Muso.AI box.
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-music.php';

/** The pane's Muso.AI box, classic then native: where the profile ID lives since 2026-11. */
function music_muso_boxes() { return array( snt_leaf_classic_html( 'snt_accounts_render_muso_box' ), \SignalNoise\OpenStationHost\Dashboard\Leaves\accounts_muso_html() ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Seed the store + options. Defaults: 2 releases, synced an hour ago, no
 * error, both Spotify credentials stored, a manual featured album, the
 * default Muso profile.
 */
function music_fixture( array $over = array() ) {
	$store = array_merge( array( 'last_synced' => time() - 3600, 'count' => 2, 'last_error' => '' ), $over['store'] ?? array() );
	$GLOBALS['__options'] = array_merge(
		array(
			SN_DISCOGRAPHY_OPTION   => $store,
			SN_SPOTIFY_ID_OPT       => 'abc123clientid',
			SN_SPOTIFY_SECRET_OPT   => 'topsecretvalue9999',
			SN_MUSIC_FEATURED_OPT   => array( 'type' => 'album', 'id' => '4aawyAB9vmqN3uQ7FjRGTy' ),
		),
		$over['options'] ?? array()
	);
}

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['connections/music'] ), 'the painter is registered under connections/music' );

// ── Synced state: the same fields, the same two actions, every readout.
music_fixture();
$classic = snt_leaf_classic_html( 'sn_admin_render_music_section' );
$kit     = snt_leaf_paint( 'connections', 'music' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'music_save', 'music_sync' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the two actions are music_save and music_sync, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 2 === substr_count( $kit, '<os-form' ) && 2 === substr_count( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the two forms (featured release, Sync now) are os-forms dispatching post through the admin-post pipeline (no pipeline declared: an action field is admin-post); the profile form left for Settings › Accounts and connections' );
ok( 1 === substr_count( $kit, 'submit-label="Save settings"' ) && false !== strpos( $kit, 'submit-label="Sync now"' ), 'the submits are labelled Save settings and Sync now, as the classic buttons are' );
ok( 2 === substr_count( $kit, 'name="tab" value="content"' ) && 2 === substr_count( $kit, 'name="sub" value="music"' ) && 2 === substr_count( $classic, 'name="sub" value="music"' ), 'every form left on the leaf carries the classic hidden tab=content / sub=music pair' );
$target = sn_admin_post_redirect_target( 'content', 'music' );
ok( 'connections' === $target['tab'] && 'music' === $target['sub'], 'the stale pair still lands home: the shared pipeline resolves content/music to ' . $target['tab'] . '/' . $target['sub'] );
ok( false === strpos( $kit, 'name="sn_spotify_id"' ) && false === strpos( $kit, 'name="sn_spotify_secret"' ) && false === strpos( $kit, '••••ntid' ) && false === strpos( $kit, '••••9999' ) && false !== strpos( $kit, 'label="Client ID"' ) && false !== strpos( $kit, '>Saved</span></os-fact>' ), '15.3.1: no credential field and no masked value; the Spotify block reads the sources as facts' );
ok( false === strpos( $kit, 'topsecretvalue9999' ) && false === strpos( $kit, 'abc123clientid' ), 'neither raw credential reaches the markup' );
list( $box_c, $box_k ) = music_muso_boxes();
ok( false === strpos( $kit . $classic, 'sn_muso_profile' ) && false !== strpos( $box_k, '<os-text-field name="sn_muso_profile" type="text" value="' . SN_MUSO_DEFAULT_PROFILE . '"' ) && false !== strpos( $box_c, '<input type="text" id="sn_muso_profile" name="sn_muso_profile" value="' . SN_MUSO_DEFAULT_PROFILE . '" class="sn-mono">' ), '2026-11: the Muso profile field is not on the leaf; the pane\'s Muso.AI box carries it with the default profile id, on both twins' );
ok( 1 === preg_match( '#<p class="snt-hint">The Muso.AI profile ID moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="accounts"[^>]*>Settings › Accounts and connections</os-button>\.</p>#', $kit ) && 1 === substr_count( $kit, 'moved to' ) && 1 === substr_count( $classic, '<p class="sn-field-helper">The Muso.AI profile ID moved to <a href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=accounts">Settings › Accounts and connections</a>.</p>' ), 'one line on each twin says where the profile ID went, with a link to the pane' );
ok( false !== strpos( $kit, '<os-text-field name="sn_music_featured" type="text" value="https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy" placeholder="https://open.spotify.com/album/…"' ) && false !== strpos( $kit, 'Currently featuring a <strong>album</strong>.' ), 'the featured field carries the stored open URL and the helper names the type' );
// 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): the Status card leads
// with ONE status line; a synced leaf paints no notice (the sentence is the
// chip's title and a hint), a problem keeps its notice (the states below).
ok( 1 === preg_match( '#<os-section heading="Status" stack role="complementary" aria-label="Sync status"><div class="snt-status-line"><os-cluster gap="8"><os-badge tone="success" title="2 releases cached. The /music timeline \+ MusicAlbum schema are live.">Synced</os-badge><os-chip>2 releases cached</os-chip><os-badge tone="success">Spotify configured</os-badge></os-cluster></div>#', $kit ), 'the Status card leads with one status line: the sync state, the releases cached, Spotify' );
ok( false === strpos( $kit, '<os-notice' ) && false !== strpos( $kit, '<p class="snt-hint">2 releases cached. The /music timeline + MusicAlbum schema are live.</p>' ), 'synced: no notice, the state\'s sentence is a hint under the line' );
ok( 1 === preg_match( '#<os-form class="snt-form snt-form--inline"[^>]*submit-label="Sync now"#', $kit ), 'Sync now is the compact inline form, not a full-width row' );
ok( 1 === preg_match( '#<os-section heading="Setup" description="[^"]*" stack><os-disclosure heading="Spotify \(optional\)" hint="Configured"#s', $kit ) && false === strpos( $kit, 'heading="Muso.AI profile"' ), 'one Setup card: Spotify is a fold whose closed summary says it is configured; the Muso.AI profile fold left with its field' );
ok( 1 === preg_match( '#<os-disclosure heading="How this works" hint="[^"]+"[^>]*><os-stack gap="12"><p class="snt-prose">A zero-touch on-site discography#', $kit ) && 1 === substr_count( $kit, '<p class="snt-prose">A zero-touch' ), 'the long intro is the Status card\'s How this works fold, painted once' );
ok( false !== strpos( $classic, 'sn-pill--ok' ) && false !== strpos( $classic, '2 releases cached. The /music timeline' ), 'the classic oracle paints the ok pill and the same sentence (the control is not vacuous)' );
ok( false !== strpos( $kit, 'heading="Spotify (optional)"' ) && false !== strpos( $kit, 'heading="Featured release"' ) && false !== strpos( $kit, 'heading="Status"' ) && false === strpos( $kit, 'heading="Sync now"' ), 'the two fieldsets left and Status are sections with the classic headings; Sync now rides Status (2026-10-12 audit: an empty box with one button)' );
ok( false !== strpos( $kit, '<os-code>MusicAlbum</os-code>' ) && false !== strpos( $kit, '<em>developer.spotify.com</em>' ) && false !== strpos( $kit, 'Settings › Accounts and connections' ) && false !== strpos( $box_k, 'Type <os-code>clear</os-code> to revert to the default profile.' ) && false !== strpos( $box_c, 'Type <code>clear</code> to revert to the default profile.' ) && false !== strpos( $box_k, 'Lockable via <os-code>SN_MUSO_PROFILE_ID</os-code>.' ), 'the intros keep their inline code and emphasis; the Spotify block points to the keyring; the profile\'s helper and intro went with it to the pane' );
ok( false !== strpos( $kit, '<os-fact label="Last sync"><span class="snt-kv__v"><os-relative-time datetime="' ) && false !== strpos( $kit, '>1 hour ago</os-relative-time></span></os-fact>' ) && false !== strpos( $kit, '<os-fact label="Releases cached"><span class="snt-kv__v">2</span></os-fact>' ), 'the Status facts: last sync ago, releases cached' );
ok( false !== strpos( $kit, 'Muso.AI profile <os-code>' . SN_MUSO_DEFAULT_PROFILE . '</os-code> <os-badge tone="success">no credential</os-badge>' ), 'the Data source fact names the profile as code and the credential-free pill' );
ok( false !== strpos( $kit, '<os-badge tone="success">Configured</os-badge>: album embeds + artwork enrichment active' ) && false === strpos( $kit, 'Last error' ), 'Spotify media reads Configured; no Last error row when there is none' );
ok( false !== strpos( $kit, '<p class="snt-hint">Sync now runs the full Muso → Spotify → store pass immediately. It keeps the last-good discography if a source fails.</p>' ) && false !== strpos( $classic, 'Sync now runs the full Muso &rarr; Spotify &rarr; store pass immediately.' ), 'the Sync now helper says what the button does, on both surfaces' );
// 2026-10-16: the Status card (Sync now at its foot) beside the Featured
// release, the working pair; then the Setup card. Release notes, when loaded,
// shares the Setup card's row.
$row1 = strpos( $kit, '<os-grid min-item-width="290" gap="18" style="--snt-grid-items:2"><os-section heading="Status" stack role="complementary" aria-label="Sync status">' );
ok( false !== $row1 && 1 === substr_count( $kit, '<os-grid' ) && 0 === substr_count( $kit, '<os-card' ), 'one row of paired boxes; the "Sync status" landmark is the Status box alone, not the row that also holds the Featured release form (PR #2025 review)' );
$order = array( $row1, strpos( $kit, 'class="snt-status-line"' ), strpos( $kit, 'value="sn_music_sync"' ), strpos( $kit, 'heading="How this works"' ), strpos( $kit, 'heading="Featured release"' ), strpos( $kit, 'heading="Setup"' ), strpos( $kit, 'heading="Spotify (optional)"' ) );
$sorted = $order;
sort( $sorted );
ok( ! in_array( false, $order, true ) && $order === $sorted, 'the order: Status (line, Sync now, How this works) beside the featured release, then Setup with Spotify: ' . implode( ' < ', $order ) );
ok( 1 === preg_match( '#<os-form[^>]*>(?:(?!</os-form>).)*name="sn_muso_profile"(?:(?!</os-form>).)*</os-form>#s', $box_k, $mf ) && false === strpos( $mf[0], 'sn_music_featured' ) && false === strpos( $mf[0], 'name="tab"' ) && false !== strpos( $mf[0], 'value="sn_music_save"' ) && false === strpos( $box_c, 'sn_music_featured' ) && false === strpos( $box_c, 'name="tab"' ) && false !== strpos( $box_c, 'name="action" value="sn_music_save"' ), 'on the pane the profile saves on its own music_save form, without the featured field and without a hidden tab or sub, on both twins' );
ok( false === strpos( $kit, '<os-section heading="Spotify (optional)" stack><os-form' ) && 1 === preg_match( '#heading="Spotify \(optional\)"(?:(?!</os-section>).)*</os-section>#s', $kit, $sp ) && false === strpos( $sp[0], '<os-form' ), 'the read-only Spotify facts are no longer inside a form' );
snt_leaf_preview( 'connections-music', $kit );

// ── Stale state: releases cached but the last sync failed.
music_fixture( array( 'store' => array( 'last_error' => 'Muso: HTTP 502' ) ) );
$kit = snt_leaf_paint( 'connections', 'music' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Showing last-good data</b> <os-badge tone="warning">Stale</os-badge><br>2 releases still cached, but the last sync failed. The page never blanks: check the error, then press Sync now.' ) && false === strpos( $kit, 'Sync status row' ), 'stale: a warning notice, Showing last-good data / pill Stale, naming the button, not a position' );
ok( false !== strpos( $kit, '<os-fact label="Last error"><span class="snt-kv__v"><os-code>Muso: HTTP 502</os-code></span></os-fact>' ), 'stale: the Last error fact appears as code' );

// ── Failed state: an error and nothing cached.
music_fixture( array( 'store' => array( 'count' => 0, 'last_synced' => 0, 'last_error' => 'Muso: HTTP 502' ) ) );
$kit = snt_leaf_paint( 'connections', 'music' );
ok( false !== strpos( $kit, 'tone="danger"' ) && false !== strpos( $kit, '<b>Sync failed: no data yet</b> <os-badge tone="danger">Failed</os-badge>' ), 'failed: a danger notice, Sync failed / pill Failed, no em dash' );
ok( 1 === preg_match( '#<div class="snt-status-line"><os-cluster gap="8"><os-badge tone="danger" title="[^"]*">Failed</os-badge>#', $kit ), 'failed: the status line\'s first chip is Failed, in the danger tone; the notice stays open beside it' );

// ── Pending state: nothing stored at all, no credentials, no featured pick.
$GLOBALS['__options'] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_music_section' );
$kit     = snt_leaf_paint( 'connections', 'music' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'music_save', 'music_sync' ) === snt_leaf_actions( $kit ), 'pending: field names and both actions still match the classic forms' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Not yet synced</b> <os-badge tone="warning">Pending</os-badge><br>Press Sync now to populate the discography.' ), 'pending: a warning notice, Not yet synced / pill Pending' );
ok( false === strpos( $kit, 'on the right' ) && false === strpos( $kit, 'below' ) && false === strpos( $classic, 'on the right' ) && false !== strpos( $classic, 'Press Sync now to populate the discography.' ), 'neither surface points at a position; both name the button' );
ok( false !== strpos( $kit, '<span class="snt-kv__v"><em>never</em></span></os-fact>' ) && false !== strpos( $kit, '<os-fact label="Releases cached"><span class="snt-kv__v">0</span></os-fact>' ), 'pending: last sync never, 0 releases cached' );
ok( false !== strpos( $kit, '<os-badge tone="warning">Not configured</os-badge>: Muso artwork only, no embeds (optional)' ), 'pending: Spotify media reads Not configured' );
ok( false === strpos( $kit, '—' ) && false === strpos( $classic, '&mdash;' ), 'no em dash in either surface\'s text' );
ok( 1 === preg_match( '#<os-disclosure heading="Spotify \(optional\)" hint="Not configured"#', $kit ) && false !== strpos( $kit, '<os-chip>Spotify not configured</os-chip>' ), 'pending: the Spotify fold\'s summary and the status line say Not configured' );
ok( false !== strpos( $kit, '>Not set</span></os-fact>' ) && false !== strpos( $kit, 'name="sn_music_featured" type="text" value=""' ) && false !== strpos( $kit, 'No manual pick: <os-code>/music</os-code> auto-features your newest release.' ), 'pending: the Spotify sources read Not set, the featured field is empty, the auto-feature helper paints' );

// ── Escaping: hostile stored values never reach the markup raw.
music_fixture( array( 'store' => array( 'last_error' => '<script>alert(1)</script>' ), 'options' => array( SN_MUSO_PROFILE_OPT => '"><script>x</script>', SN_MUSIC_FEATURED_OPT => array( 'type' => '<script>t</script>', 'id' => 'x' ) ) ) );
$kit = snt_leaf_paint( 'connections', 'music' );
ok( false === strpos( $kit, '<script>' ) && 4 <= substr_count( $kit, '&lt;script&gt;' ), 'hostile last_error, profile id and featured type are escaped everywhere they are painted' );
ok( array() === snt_leaf_classic_markers( $kit ), 'hostile: still no classic markers' );

// ── Locked state: the three constants override the fields; no editable
// credential or profile is offered, and the forms still carry the same names.
music_fixture();
define( 'SN_SPOTIFY_CLIENT_ID', 'pinned-id' );
define( 'SN_SPOTIFY_CLIENT_SECRET', 'pinned-secret' );
define( 'SN_MUSO_PROFILE_ID', 'pinned-profile' );
$classic = snt_leaf_classic_html( 'sn_admin_render_music_section' );
$kit     = snt_leaf_paint( 'connections', 'music' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'locked: field names match the classic forms: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( ! in_array( 'sn_spotify_id', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_spotify_secret', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_muso_profile', snt_leaf_names( $kit ), true ), 'locked: neither form names a credential or the profile' );
list( $box_c, $box_k ) = music_muso_boxes();
ok( false !== strpos( $kit, '>wp-config (SN_SPOTIFY_CLIENT_ID)</span></os-fact>' ) && false !== strpos( $kit, '>wp-config (SN_SPOTIFY_CLIENT_SECRET)</span></os-fact>' ) && false !== strpos( $box_k, '<os-text-field type="text" value="pinned-profile" disabled>' ) && false !== strpos( $box_c, '<input type="text" value="pinned-profile" disabled class="sn-mono">' ), 'locked: the Spotify sources name their constants; on the pane the profile shows its constant, disabled and nameless, on both twins' );
ok( false === strpos( $box_k, '<os-form' ) && false === strpos( $box_c, '<form' ) && false === strpos( $box_k . $box_c, 'sn_music_save' ) && false === strpos( $box_k . $box_c, 'name="sn_muso_profile"' ) && false === strpos( $box_c, '<button' ), 'locked: the pane\'s Muso.AI box has no form, no name and no Save on either twin: a constant decides the profile' );
ok( 1 === substr_count( $box_k, '<strong>Locked</strong> by <os-code>' ) && false !== strpos( $box_k, '<os-code>SN_MUSO_PROFILE_ID</os-code>.' ) && false !== strpos( $box_c, '<strong>Locked</strong> by <code>SN_MUSO_PROFILE_ID</code>.' ) && false === strpos( $kit, '<strong>Locked</strong>' ), 'locked: on the pane the profile lock is explained by its constant (the Spotify locks read in the leaf\'s facts)' );
ok( false === strpos( $kit, 'pinned-secret' ) && false === strpos( $kit, 'pinned-id' ), 'locked: the constants\' values never reach the markup' );
ok( array( 'music_save', 'music_sync' ) === snt_leaf_actions( $kit ), 'locked: both actions are still offered (the featured field stays editable)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
