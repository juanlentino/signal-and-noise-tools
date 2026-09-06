<?php
/**
 * Native window leaf: Connections → Discography
 * (apps/sn-dashboard/parts/leaves/connections-music.php + -parts.php).
 *
 * The oracle is the classic leaf: both kit forms must carry the same field
 * names (the hidden `tab=content` / `sub=music` pair included) and the same
 * two sn_actions, in the editable and the constant-locked state; every
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
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/connections-music.php';

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
ok( 2 === substr_count( $kit, '<os-form' ) && 2 === substr_count( $kit, 'os-action="post"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'both forms are os-forms dispatching post through the shared handler table (the classic forms post to the current admin URL)' );
ok( false !== strpos( $kit, 'submit-label="Save settings"' ) && false !== strpos( $kit, 'submit-label="Sync now"' ), 'the submits are labelled Save settings and Sync now, as the classic buttons are' );
ok( 2 === substr_count( $kit, 'name="tab" value="content"' ) && 2 === substr_count( $kit, 'name="sub" value="music"' ) && 2 === substr_count( $classic, 'name="sub" value="music"' ), 'both forms carry the classic hidden tab=content / sub=music pair (the classic carries it twice too)' );
$target = sn_admin_post_redirect_target( 'content', 'music' );
ok( 'connections' === $target['tab'] && 'music' === $target['sub'], 'the stale pair still lands home: the shared pipeline resolves content/music to ' . $target['tab'] . '/' . $target['sub'] );
ok( false !== strpos( $kit, '<os-text-field name="sn_spotify_id" type="text" value="••••ntid"' ) && false !== strpos( $kit, '<os-text-field name="sn_spotify_secret" type="text" value="••••9999"' ) && false !== strpos( $kit, 'autocomplete="off"' ), 'the credentials are kit text fields carrying the masked values' );
ok( false === strpos( $kit, 'topsecretvalue9999' ) && false === strpos( $kit, 'abc123clientid' ), 'neither raw credential reaches the markup' );
ok( false !== strpos( $kit, '<os-text-field name="sn_muso_profile" type="text" value="' . SN_MUSO_DEFAULT_PROFILE . '"' ), 'the Muso profile field carries the default profile id' );
ok( false !== strpos( $kit, '<os-text-field name="sn_music_featured" type="text" value="https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy" placeholder="https://open.spotify.com/album/…"' ) && false !== strpos( $kit, 'Currently featuring a <strong>album</strong>.' ), 'the featured field carries the stored open URL and the helper names the type' );
ok( false !== strpos( $kit, 'tone="success"' ) && false !== strpos( $kit, '<b>Synced</b> <os-badge tone="success">Synced</os-badge><br>2 release(s) cached. The /music timeline + MusicAlbum schema are live.' ), 'the synced state paints a success notice: Synced / 2 release(s) cached / pill Synced' );
ok( false !== strpos( $classic, 'sn-pill--ok' ) && false !== strpos( $classic, '2 release(s) cached' ), 'the classic oracle really paints the ok pill and the count (the control is not vacuous)' );
ok( false !== strpos( $kit, 'heading="Spotify (optional)"' ) && false !== strpos( $kit, 'heading="Muso.AI profile"' ) && false !== strpos( $kit, 'heading="Featured release"' ) && false !== strpos( $kit, 'heading="Status"' ) && false !== strpos( $kit, 'heading="Sync now"' ), 'the three fieldsets and the two rail cards are sections with the classic headings' );
ok( false !== strpos( $kit, '<os-code>MusicAlbum</os-code>' ) && false !== strpos( $kit, '<em>developer.spotify.com</em>' ) && false !== strpos( $kit, '<os-code>SN_SPOTIFY_CLIENT_ID</os-code> / <os-code>SN_SPOTIFY_CLIENT_SECRET</os-code>' ) && false !== strpos( $kit, 'Type <os-code>clear</os-code> to revert to the default profile.' ) && false !== strpos( $kit, '<os-code>[sn_music_featured]</os-code>' ), 'the intros and helpers keep their inline code and emphasis' );
ok( false !== strpos( $kit, '<dt class="snt-kv__k">Last sync</dt><dd class="snt-kv__v">1 hour ago</dd>' ) && false !== strpos( $kit, '<dt class="snt-kv__k">Releases cached</dt><dd class="snt-kv__v">2</dd>' ), 'the Status facts: last sync ago, releases cached' );
ok( false !== strpos( $kit, 'Muso.AI profile <os-code>' . SN_MUSO_DEFAULT_PROFILE . '</os-code> <os-badge tone="success">no credential</os-badge>' ), 'the Data source fact names the profile as code and the credential-free pill' );
ok( false !== strpos( $kit, '<os-badge tone="success">Configured</os-badge> — album embeds + artwork enrichment active' ) && false === strpos( $kit, 'Last error' ), 'Spotify media reads Configured; no Last error row when there is none' );
ok( false !== strpos( $kit, 'description="Runs the full Muso → Spotify → store pass immediately. Keeps the last-good discography if a source fails."' ), 'the Sync now helper survives as the section description' );
ok( 2 === substr_count( $kit, 'class="snt-col"' ) && false !== strpos( $kit, '<div class="snt-cols">' ) && false !== strpos( $kit, 'aria-label="Sync status"' ), 'the two-column shell becomes the app column grid: form column, then the rail with its landmark name' );

// ── Stale state: releases cached but the last sync failed.
music_fixture( array( 'store' => array( 'last_error' => 'Muso: HTTP 502' ) ) );
$kit = snt_leaf_paint( 'connections', 'music' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Showing last-good data</b> <os-badge tone="warning">Stale</os-badge><br>2 release(s) still cached' ), 'stale: a warning notice — Showing last-good data / pill Stale' );
ok( false !== strpos( $kit, '<dt class="snt-kv__k">Last error</dt><dd class="snt-kv__v"><os-code>Muso: HTTP 502</os-code></dd>' ), 'stale: the Last error fact appears as code' );

// ── Failed state: an error and nothing cached.
music_fixture( array( 'store' => array( 'count' => 0, 'last_synced' => 0, 'last_error' => 'Muso: HTTP 502' ) ) );
$kit = snt_leaf_paint( 'connections', 'music' );
ok( false !== strpos( $kit, 'tone="danger"' ) && false !== strpos( $kit, '<b>Sync failed — no data yet</b> <os-badge tone="danger">Failed</os-badge>' ), 'failed: a danger notice — Sync failed / pill Failed' );

// ── Pending state: nothing stored at all, no credentials, no featured pick.
$GLOBALS['__options'] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_music_section' );
$kit     = snt_leaf_paint( 'connections', 'music' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && array( 'music_save', 'music_sync' ) === snt_leaf_actions( $kit ), 'pending: field names and both actions still match the classic forms' );
ok( false !== strpos( $kit, 'tone="warning"' ) && false !== strpos( $kit, '<b>Not yet synced</b> <os-badge tone="warning">Pending</os-badge><br>Hit “Sync now”' ), 'pending: a warning notice — Not yet synced / pill Pending' );
ok( false !== strpos( $kit, '<dd class="snt-kv__v"><em>never</em></dd>' ) && false !== strpos( $kit, '<dt class="snt-kv__k">Releases cached</dt><dd class="snt-kv__v">0</dd>' ), 'pending: last sync never, 0 releases cached' );
ok( false !== strpos( $kit, '<os-badge tone="warning">Not configured</os-badge> — Muso artwork only, no embeds (optional)' ), 'pending: Spotify media reads Not configured' );
ok( false !== strpos( $kit, 'name="sn_spotify_id" type="text" value=""' ) && false !== strpos( $kit, 'name="sn_music_featured" type="text" value=""' ) && false !== strpos( $kit, 'No manual pick — <os-code>/music</os-code> auto-features your newest release.' ), 'pending: empty credential and featured fields, the auto-feature helper' );

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
ok( 2 === substr_count( $kit, '<os-text-field type="text" value="••••" disabled>' ) && false !== strpos( $kit, '<os-text-field type="text" value="pinned-profile" disabled>' ), 'locked: the credentials show •••• and the profile its constant, all disabled and nameless' );
ok( 3 === substr_count( $kit, '<strong>Locked</strong> by <os-code>' ) && false !== strpos( $kit, '<os-code>SN_MUSO_PROFILE_ID</os-code>.' ), 'locked: each lock is explained by its constant' );
ok( false === strpos( $kit, 'pinned-secret' ) && false === strpos( $kit, 'pinned-id' ), 'locked: the constants\' values never reach the markup' );
ok( array( 'music_save', 'music_sync' ) === snt_leaf_actions( $kit ), 'locked: both actions are still offered (the featured field stays editable)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
