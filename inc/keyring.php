<?php
/**
 * Signal & Noise Tools — the keyring: every credential the plugin holds, in
 * one list, with where it comes from and what it feeds.
 *
 * 15.2.0. Until now about twenty credentials lived across eight tabs, each
 * with its own field, handler and hint, and none said which of the others it
 * was NOT: on 2026-09-15 a Cloudflare token was pasted over a worker's shared
 * secret and the sensor answered 401 for an hour. The keyring is the one
 * place: Connections › Credentials paints it, one handler saves it, one
 * "Verify all" probes it (inc/keyring-verify.php), and every feature tab
 * that used to carry a field points here instead.
 *
 * Resolution never changes: a wp-config constant wins, then the saved value.
 * New in 15.2.0 is the SITE SECRET: the one value every plugin↔worker
 * handshake can derive from (rows with `derive: site`), so rotating means one
 * paste here and the `wrangler secret put` each row prints. A row derives
 * only when the owner said so for that row (`sn_keyring_site_rows`); saved
 * values keep working untouched until then.
 *
 * (The module is named keyring, not credentials: the owner's tooling refuses
 * to open any file whose name starts with "credentials".)
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SITE_SECRET_OPT   = 'sn_site_secret';
const SN_KEYRING_SITE_ROWS = 'sn_keyring_site_rows';

/**
 * The rows. `constant` and `option` name where the value lives (`setting`
 * for the settings blob); `derive: site` marks a handshake the site secret
 * can serve; `other_half` names the worker secret that must carry the same
 * value, with the repo the command runs in; `probe` names a verify routine;
 * `flush` (transient keys) and `flush_fn` (a function) drop what a rotated
 * value would otherwise serve stale (15.2.1).
 *
 * @return array<string,array<string,mixed>>
 */
function sn_keyring() {
	return array(
		'site_secret'           => array( 'group' => 'site', 'label' => __( 'Site secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SITE_SECRET', 'option' => SN_SITE_SECRET_OPT, 'about' => __( 'One value every plugin↔worker handshake in this group can derive from. Rotate it here, then run each derived row\'s command under Worker secrets.', 'signal-and-noise-tools' ), 'feeds' => __( 'the rows marked "from the site secret"', 'signal-and-noise-tools' ) ),
		'mr_read_token'         => array( 'group' => 'site', 'label' => __( 'Machine Readers read token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_MR_READ_TOKEN', 'setting' => 'machine_readers.read_token', 'derive' => 'site', 'about' => __( 'A shared password between this plugin and the rights-signals sensor. Not a Cloudflare token.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Machine Readers tab and tile', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'sn-rights-signals-worker', 'secret' => 'SN_MR_READ_TOKEN' ), 'probe' => 'sensor', 'flush_fn' => 'snt_mr_cache_flush' ),
		'srv_token'             => array( 'group' => 'site', 'label' => __( 'Analytics server token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SRV_TOKEN', 'option' => 'sn_srv_token', 'derive' => 'site', 'about' => __( 'The private token the origin sends the analytics worker so a server-side hit counts as human; also the refresh poke\'s secret.', 'signal-and-noise-tools' ), 'feeds' => __( 'the RSS tracker\'s collector POST; the analytics refresh route', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'signal-and-noise-analytics-worker', 'secret' => 'SN_SRV_TOKEN' ), 'probe' => 'srv' ),
		'bridge_token'          => array( 'group' => 'site', 'label' => __( 'Remote MCP bridge token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BRIDGE_TOKEN', 'about' => __( 'The bearer the remote MCP worker presents on the bridge route (env.SN_BRIDGE_TOKEN in its src/bridge.mjs). wp-config.php only: a value in the database is readable by anything that reaches it, and this one opens the bridge.', 'signal-and-noise-tools' ), 'feeds' => __( 'the MCP bridge route', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'sn-remote-mcp-worker', 'secret' => 'SN_BRIDGE_TOKEN' ) ),
		'prov_hmac_secret'      => array( 'group' => 'site', 'label' => __( 'Provenance webhook HMAC secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_PROV_HMAC_SECRET', 'option' => 'sn_prov_hmac_secret', 'derive' => 'site', 'about' => __( 'Signs the anchor webhook both ways between this site and the provenance worker.', 'signal-and-noise-tools' ), 'feeds' => __( 'the provenance webhook', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'sn-provenance-worker', 'secret' => 'SN_PROV_HMAC_SECRET' ) ),
		// SN Traffic Lanes (2026.10.18; owner decision 2026-10-15: one key). The
		// owner key signs the device marker and the optional agent token; the
		// signed messages stay domain-separated (v1|dev|... vs v1|agent|...).
		// 64 hex, never derived. The owner makes it in Terminal (openssl rand
		// -hex 32) and pastes it (owner request 2026-10-15), or Generate makes
		// one (random_bytes(32)); either rolls the current key to previous,
		// which the Workers accept during the overlap. No screen shows it.
		'owner_key'          => array( 'group' => 'site', 'label' => __( 'Owner key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'option' => 'sn_owner_key', 'generated' => 'owner_key_previous', 'about' => __( 'Setup: in Terminal, run openssl rand -hex 32 and paste it here (exactly 64 hexadecimal characters; anything else is refused). Set the same value on both Workers: cd ~/Projects/signal-and-noise-analytics-worker && npx wrangler secret put SN_OWNER_KEY, then cd ~/Projects/sn-rights-signals-worker && npx wrangler secret put SN_OWNER_KEY. Then enroll your devices under Measurement › Analytics. It signs the owner device marker (the sn_owner_dev cookie) and, optionally, the SN-Owner-Agent header that `wp sn owner-token` mints; nothing requires an agent token. Generate makes a key here instead, but no screen shows it (on the server, wp option get sn_owner_key prints it). Use a token safely: put the output straight into an environment variable (TOKEN=$(wp sn owner-token --agent=script)), never echo it. In Playwright, attach it with page.route limited to https://juanlentino.com/**, never extraHTTPHeaders (that sends it to every host). With curl, never combine it with -L (a redirect would carry it elsewhere).', 'signal-and-noise-tools' ), 'feeds' => __( 'device enrollment (Measurement › Analytics); wp sn owner-token; both Workers\' owner lanes', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'signal-and-noise-analytics-worker', 'also' => 'sn-rights-signals-worker', 'secret' => 'SN_OWNER_KEY' ) ),
		'owner_key_previous' => array( 'group' => 'site', 'label' => __( 'Owner key (previous)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'option' => 'sn_owner_key_previous', 'generated' => '', 'about' => __( 'The owner key a new paste or Generate replaced. A paste here must be 64 hexadecimal characters. It stays valid until you clear it: markers it signed keep verifying until a wp-admin visit re-signs them, and tokens it signed expire within 12 hours. Set it on both Workers only during a rotation; clear it here and on the Workers once every device has renewed.', 'signal-and-noise-tools' ), 'feeds' => __( 'marker and token verification during a rotation', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'signal-and-noise-analytics-worker', 'also' => 'sn-rights-signals-worker', 'secret' => 'SN_OWNER_KEY_PREVIOUS' ) ),
		'cf_token'              => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare API token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CLOUDFLARE_API_TOKEN', 'option' => 'sn_cf_api_token', 'about' => __( 'The ONE Cloudflare token: Zone › Cache Purge › Purge, Zone › Analytics › Read, Account › Account Analytics › Read.', 'signal-and-noise-tools' ), 'feeds' => __( 'edge purge, the monitor, the Edge view, S&N Analytics; the sensor\'s Analytics Engine reads', 'signal-and-noise-tools' ), 'probe' => 'cloudflare', 'other_half' => array( 'repo' => 'sn-rights-signals-worker', 'secret' => 'SN_MR_SQL_TOKEN' ), 'flush_fn' => 'sn_cf_monitor_forget_token' ),
		'cf_zone'               => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare zone ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_CLOUDFLARE_ZONE_ID', 'option' => 'sn_cf_zone_id', 'about' => __( 'Dashboard → site overview → API.', 'signal-and-noise-tools' ), 'feeds' => __( 'every zone call', 'signal-and-noise-tools' ) ),
		'cf_account'            => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare account ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_CF_ACCOUNT_ID', 'option' => 'sn_cf_account_id', 'about' => __( 'Dashboard → account home → the ID in the URL.', 'signal-and-noise-tools' ), 'feeds' => __( 'Analytics Engine reads; the Account-token verify route', 'signal-and-noise-tools' ) ),
		'cf_analytics_override' => array( 'group' => 'cloudflare', 'label' => __( 'Analytics token override', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CF_ANALYTICS_TOKEN', 'option' => 'sn_cf_analytics_token', 'about' => __( 'Optional. Empty means S&N Analytics reads with the Cloudflare API token.', 'signal-and-noise-tools' ), 'feeds' => __( 'S&N Analytics, when set', 'signal-and-noise-tools' ), 'probe' => 'cloudflare_token' ),
		'workers_ai_token'      => array( 'group' => 'cloudflare', 'label' => __( 'Workers AI token (embeddings)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'setting' => 'ml.embeddings_token', 'about' => __( 'A Cloudflare token with Account › Workers AI › Read, used with the Cloudflare account ID. Optional: without it the semantic-embeddings shadow does not run.', 'signal-and-noise-tools' ), 'feeds' => __( 'the TF-IDF vs embeddings comparison (AI › Models & budget)', 'signal-and-noise-tools' ), 'probe' => 'cloudflare_token', 'flush' => array() ),
		'betterstack_token'     => array( 'group' => 'issued', 'label' => __( 'Better Stack API token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BETTERSTACK_API_TOKEN', 'option' => 'sn_betterstack_api_token', 'about' => __( 'Issued by Better Stack (Uptime › API tokens).', 'signal-and-noise-tools' ), 'feeds' => __( 'the Uptime tab and tile; the WAF witness', 'signal-and-noise-tools' ), 'probe' => 'betterstack', 'flush' => array( 'sn_uptime_status_snapshot', 'sn_uptime_availability', 'sn_uptime_availability_90d', 'sn_uptime_availability_90d_warmed' ) ),
		'spotify_client_id'     => array( 'group' => 'issued', 'label' => __( 'Spotify client ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_SPOTIFY_CLIENT_ID', 'option' => 'sn_spotify_client_id', 'about' => __( 'Issued by Spotify for Developers.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Discography sync', 'signal-and-noise-tools' ), 'flush' => array( 'sn_spotify_token' ) ),
		'spotify_client_secret' => array( 'group' => 'issued', 'label' => __( 'Spotify client secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPOTIFY_CLIENT_SECRET', 'option' => 'sn_spotify_client_secret', 'about' => __( 'Issued by Spotify for Developers.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Discography sync', 'signal-and-noise-tools' ), 'probe' => 'spotify', 'flush' => array( 'sn_spotify_token' ) ),
		'github_token'          => array( 'group' => 'issued', 'label' => __( 'GitHub token (spend)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPEND_GH_TOKEN', 'option' => 'sn_spend_gh_token', 'about' => __( 'A GitHub token with billing read.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Actions spend reading', 'signal-and-noise-tools' ), 'probe' => 'github', 'flush' => array( 'sn_spend_gh_usage' ) ),
		'anthropic_admin_key'   => array( 'group' => 'issued', 'label' => __( 'Anthropic admin key (spend)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPEND_AI_ADMIN_KEY', 'option' => 'sn_spend_ai_admin_key', 'about' => __( 'An Anthropic admin key with usage read.', 'signal-and-noise-tools' ), 'feeds' => __( 'the AI spend reading', 'signal-and-noise-tools' ), 'flush' => array( 'sn_spend_ai_cost_v2' ) ),
		'zenodo_token'          => array( 'group' => 'issued', 'label' => __( 'Zenodo token (production)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ZENODO_TOKEN', 'option' => 'sn_zenodo_token', 'about' => __( 'A personal access token from zenodo.org › Applications, scopes deposit:write and deposit:actions.', 'signal-and-noise-tools' ), 'feeds' => __( 'the DOI deposits (Connections › Zenodo)', 'signal-and-noise-tools' ), 'probe' => 'zenodo' ),
		'zenodo_sandbox_token'  => array( 'group' => 'issued', 'label' => __( 'Zenodo token (sandbox)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ZENODO_SANDBOX_TOKEN', 'option' => 'sn_zenodo_sandbox_token', 'about' => __( 'The same, from sandbox.zenodo.org; its DOIs are test DOIs and never reach a public surface.', 'signal-and-noise-tools' ), 'feeds' => __( 'the DOI deposits while the environment is sandbox', 'signal-and-noise-tools' ), 'probe' => 'zenodo' ),
		'bing_webmaster_key'    => array( 'group' => 'issued', 'label' => __( 'Bing Webmaster API key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BING_WEBMASTER_KEY', 'option' => 'sn_bing_webmaster_key', 'about' => __( 'From Bing Webmaster Tools › Settings › API access; one key per user, for every verified site.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Bing search reading (S&N Analytics › Search) and its daily sync', 'signal-and-noise-tools' ), 'probe' => 'bing' ),
		'indexnow_key'          => array( 'group' => 'issued', 'label' => __( 'IndexNow key', 'signal-and-noise-tools' ), 'kind' => 'public', 'option' => 'sn_indexnow_key', 'about' => __( 'Minted here and published at its key URL; not a secret.', 'signal-and-noise-tools' ), 'feeds' => __( 'IndexNow pings', 'signal-and-noise-tools' ), 'probe' => 'indexnow' ),
		'cloudways_api_key'     => array( 'group' => 'issued', 'label' => __( 'Cloudways API key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CLOUDWAYS_API_KEY', 'about' => __( 'Account-wide, wp-config only by decision (with SN_CLOUDWAYS_EMAIL, SERVER_ID, APP_ID).', 'signal-and-noise-tools' ), 'feeds' => __( 'nothing since 2026-10-14: the Cloudways app purge is retired', 'signal-and-noise-tools' ) ),
		'archive_access_key'    => array( 'group' => 'issued', 'label' => __( 'Internet Archive access key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ARCHIVE_ACCESS_KEY', 'about' => __( 'From archive.org/account/s3.php. wp-config only.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Save Page Now push on a note\'s first publish', 'signal-and-noise-tools' ) ),
		'archive_secret_key'    => array( 'group' => 'issued', 'label' => __( 'Internet Archive secret key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ARCHIVE_SECRET_KEY', 'about' => __( 'The other half of the pair, same page. wp-config only.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Save Page Now push on a note\'s first publish', 'signal-and-noise-tools' ) ),
	);
}

/**
 * 15.2.2: the values a SHARED row must never equal: every issued token and id
 * the keyring holds. A Cloudflare token pasted as the site secret was the
 * 2026-09-15 mistake, twice.
 *
 * @return array<string,string> id => value, non-empty issued rows only.
 */
function sn_keyring_issued_values() {
	$out = array();
	foreach ( sn_keyring() as $id => $row ) {
		if ( in_array( (string) $row['group'], array( 'cloudflare', 'issued' ), true ) ) {
			$v = sn_credential( $id );
			if ( '' !== $v ) {
				$out[ $id ] = $v;
			}
		}
	}
	return $out;
}

/**
 * Where a row's value comes from: 'constant' | 'site' | 'option' | ''.
 *
 * @param string $id Row id.
 * @return string
 */
function sn_keyring_source( $id ) {
	$rows = sn_keyring();
	if ( ! isset( $rows[ $id ] ) ) {
		return '';
	}
	$row = $rows[ $id ];
	if ( isset( $row['constant'] ) && defined( $row['constant'] ) && '' !== (string) constant( $row['constant'] ) ) {
		return 'constant';
	}
	if ( 'site' === ( $row['derive'] ?? '' ) && in_array( $id, sn_keyring_site_rows(), true ) && '' !== sn_site_secret() ) {
		return 'site';
	}
	return '' !== sn_keyring_stored( $id ) ? 'option' : '';
}

/**
 * The saved value of a row, ignoring constants and the site secret.
 *
 * @param string $id Row id.
 * @return string
 */
function sn_keyring_stored( $id ) {
	$rows = sn_keyring();
	$row  = $rows[ $id ] ?? array();
	if ( isset( $row['setting'] ) ) {
		return function_exists( 'sn_setting' ) ? (string) sn_setting( $row['setting'], '' ) : '';
	}
	return isset( $row['option'] ) ? (string) get_option( $row['option'], '' ) : '';
}

/**
 * The value a row resolves to: constant, else the site secret when the row
 * derives, else the saved value, else ''.
 *
 * @param string $id Row id.
 * @return string
 */
function sn_credential( $id ) {
	$rows = sn_keyring();
	if ( ! isset( $rows[ $id ] ) ) {
		return '';
	}
	switch ( sn_keyring_source( $id ) ) {
		case 'constant':
			return (string) constant( $rows[ $id ]['constant'] );
		case 'site':
			return sn_site_secret();
		case 'option':
			return sn_keyring_stored( $id );
	}
	return '';
}

/**
 * The site secret: constant, else the option.
 *
 * @return string
 */
function sn_site_secret() {
	if ( defined( 'SN_SITE_SECRET' ) && '' !== (string) SN_SITE_SECRET ) {
		return (string) SN_SITE_SECRET;
	}
	return (string) get_option( SN_SITE_SECRET_OPT, '' );
}

/**
 * The rows the owner switched to the site secret.
 *
 * @return array<int,string>
 */
function sn_keyring_site_rows() {
	$rows = get_option( SN_KEYRING_SITE_ROWS, array() );
	return is_array( $rows ) ? array_values( array_filter( array_map( 'strval', $rows ) ) ) : array();
}

/**
 * The command that sets a row's other half, for the leaf to print.
 *
 * @param array<string,mixed> $row Registry row.
 * @return string '' when the row has no other half.
 */
function sn_keyring_other_half_command( array $row ) {
	if ( empty( $row['other_half']['repo'] ) || empty( $row['other_half']['secret'] ) ) {
		return '';
	}
	$out = array();
	foreach ( array_filter( array( $row['other_half']['repo'], $row['other_half']['also'] ?? '' ) ) as $repo ) {
		$out[] = 'cd ~/Projects/' . (string) $repo . ' && npx wrangler secret put ' . (string) $row['other_half']['secret'];
	}
	// A secret two Workers carry (the owner key) prints both commands.
	return implode( ' ; ', $out );
}

/**
 * The rows Generate can write: each current owner key (a `generated` row that
 * names its previous row). Never a previous row; never any other secret.
 *
 * @return array<string,string> id => label.
 */
function sn_keyring_generate_options() {
	$out = array();
	foreach ( sn_keyring() as $id => $row ) {
		if ( '' !== (string) ( $row['generated'] ?? '' ) ) {
			$out[ $id ] = (string) $row['label'];
		}
	}
	return $out;
}

// The handshake accessors that were constant-only now fall back to the
// keyring, so a row switched to the site secret (or saved here) is honoured
// without each module learning the keyring. Constants still win inside
// sn_credential(); an empty filter input is the only case the fallback fills.
add_filter( 'sn_server_token', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'srv_token' ); } );
add_filter( 'sn_analytics_refresh_secret', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'srv_token' ); } );
// No sn_bridge_secret fallback: the bridge secret is SN_BRIDGE_TOKEN or nothing
// (inc/mcp/mcp-bridge-route.php; docs/audits/2026-10-26-mcp-doors.md, F5).
add_filter( 'sn_prov_hmac_secret', static function ( $secret ) { return '' !== (string) $secret ? $secret : sn_credential( 'prov_hmac_secret' ); } );
add_filter( 'sn_mr_read_token', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'mr_read_token' ); } );
