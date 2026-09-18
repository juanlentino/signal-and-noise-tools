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
		'site_secret'           => array( 'group' => 'site', 'label' => __( 'Site secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SITE_SECRET', 'option' => SN_SITE_SECRET_OPT, 'about' => __( 'One value every plugin↔worker handshake below can derive from. Rotate it here, then run each command the derived rows print.', 'signal-and-noise-tools' ), 'feeds' => __( 'the rows marked "from the site secret"', 'signal-and-noise-tools' ) ),
		'mr_read_token'         => array( 'group' => 'site', 'label' => __( 'Machine Readers read token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_MR_READ_TOKEN', 'setting' => 'machine_readers.read_token', 'derive' => 'site', 'about' => __( 'A shared password between this plugin and the rights-signals sensor. Not a Cloudflare token.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Machine Readers tab and tile', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'sn-rights-signals-worker', 'secret' => 'SN_MR_READ_TOKEN' ), 'probe' => 'sensor', 'flush_fn' => 'snt_mr_cache_flush' ),
		'srv_token'             => array( 'group' => 'site', 'label' => __( 'Analytics server token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SRV_TOKEN', 'option' => 'sn_srv_token', 'derive' => 'site', 'about' => __( 'The private token the origin sends the analytics worker so a server-side hit counts as human; also the refresh poke\'s secret.', 'signal-and-noise-tools' ), 'feeds' => __( 'the RSS tracker\'s collector POST; the analytics refresh route', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'signal-and-noise-analytics-worker', 'secret' => 'SN_SRV_TOKEN' ) ),
		'bridge_token'          => array( 'group' => 'site', 'label' => __( 'Login guard bridge token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BRIDGE_TOKEN', 'option' => 'sn_bridge_token', 'derive' => 'site', 'about' => __( 'The bearer the login-guard worker presents on the bridge route.', 'signal-and-noise-tools' ), 'feeds' => __( 'the MCP bridge route', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'signal-and-noise-login-guard-worker', 'secret' => 'SN_BRIDGE_TOKEN' ) ),
		'prov_hmac_secret'      => array( 'group' => 'site', 'label' => __( 'Provenance webhook HMAC secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_PROV_HMAC_SECRET', 'option' => 'sn_prov_hmac_secret', 'derive' => 'site', 'about' => __( 'Signs the anchor webhook both ways between this site and the provenance worker.', 'signal-and-noise-tools' ), 'feeds' => __( 'the provenance webhook', 'signal-and-noise-tools' ), 'other_half' => array( 'repo' => 'sn-provenance-worker', 'secret' => 'SN_PROV_HMAC_SECRET' ) ),
		'cf_token'              => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare API token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CLOUDFLARE_API_TOKEN', 'option' => 'sn_cf_api_token', 'about' => __( 'The ONE Cloudflare token: Zone › Cache Purge › Purge, Zone › Analytics › Read, Account › Account Analytics › Read.', 'signal-and-noise-tools' ), 'feeds' => __( 'edge purge, the monitor, the Edge view, S&N Analytics; the sensor\'s Analytics Engine reads', 'signal-and-noise-tools' ), 'probe' => 'cloudflare', 'other_half' => array( 'repo' => 'sn-rights-signals-worker', 'secret' => 'SN_MR_SQL_TOKEN' ) ),
		'cf_zone'               => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare zone ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_CLOUDFLARE_ZONE_ID', 'option' => 'sn_cf_zone_id', 'about' => __( 'Dashboard → site overview → API.', 'signal-and-noise-tools' ), 'feeds' => __( 'every zone call', 'signal-and-noise-tools' ) ),
		'cf_account'            => array( 'group' => 'cloudflare', 'label' => __( 'Cloudflare account ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_CF_ACCOUNT_ID', 'option' => 'sn_cf_account_id', 'about' => __( 'Dashboard → account home → the ID in the URL.', 'signal-and-noise-tools' ), 'feeds' => __( 'Analytics Engine reads; the Account-token verify route', 'signal-and-noise-tools' ) ),
		'cf_analytics_override' => array( 'group' => 'cloudflare', 'label' => __( 'Analytics token override', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CF_ANALYTICS_TOKEN', 'option' => 'sn_cf_analytics_token', 'about' => __( 'Optional. Empty means S&N Analytics reads with the Cloudflare API token above.', 'signal-and-noise-tools' ), 'feeds' => __( 'S&N Analytics, when set', 'signal-and-noise-tools' ), 'probe' => 'cloudflare_token' ),
		'workers_ai_token'      => array( 'group' => 'cloudflare', 'label' => __( 'Workers AI token (embeddings)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'setting' => 'ml.embeddings_token', 'about' => __( 'A Cloudflare token with Account › Workers AI › Read, used with the account ID above. Optional: without it the semantic-embeddings shadow does not run.', 'signal-and-noise-tools' ), 'feeds' => __( 'the TF-IDF vs embeddings comparison (AI › Models & budget)', 'signal-and-noise-tools' ), 'probe' => 'cloudflare_token', 'flush' => array() ),
		'betterstack_token'     => array( 'group' => 'issued', 'label' => __( 'Better Stack API token', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BETTERSTACK_API_TOKEN', 'option' => 'sn_betterstack_api_token', 'about' => __( 'Issued by Better Stack (Uptime › API tokens).', 'signal-and-noise-tools' ), 'feeds' => __( 'the Uptime tab and tile; the WAF witness', 'signal-and-noise-tools' ), 'probe' => 'betterstack', 'flush' => array( 'sn_uptime_status_snapshot', 'sn_uptime_availability' ) ),
		'spotify_client_id'     => array( 'group' => 'issued', 'label' => __( 'Spotify client ID', 'signal-and-noise-tools' ), 'kind' => 'id', 'constant' => 'SN_SPOTIFY_CLIENT_ID', 'option' => 'sn_spotify_client_id', 'about' => __( 'Issued by Spotify for Developers.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Discography sync', 'signal-and-noise-tools' ), 'flush' => array( 'sn_spotify_token' ) ),
		'spotify_client_secret' => array( 'group' => 'issued', 'label' => __( 'Spotify client secret', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPOTIFY_CLIENT_SECRET', 'option' => 'sn_spotify_client_secret', 'about' => __( 'Issued by Spotify for Developers.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Discography sync', 'signal-and-noise-tools' ), 'probe' => 'spotify', 'flush' => array( 'sn_spotify_token' ) ),
		'github_token'          => array( 'group' => 'issued', 'label' => __( 'GitHub token (spend)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPEND_GH_TOKEN', 'option' => 'sn_spend_gh_token', 'about' => __( 'A GitHub token with billing read.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Actions spend reading', 'signal-and-noise-tools' ), 'probe' => 'github', 'flush' => array( 'sn_spend_gh_usage' ) ),
		'anthropic_admin_key'   => array( 'group' => 'issued', 'label' => __( 'Anthropic admin key (spend)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_SPEND_AI_ADMIN_KEY', 'option' => 'sn_spend_ai_admin_key', 'about' => __( 'An Anthropic admin key with usage read.', 'signal-and-noise-tools' ), 'feeds' => __( 'the AI spend reading', 'signal-and-noise-tools' ), 'flush' => array( 'sn_spend_ai_cost_v2' ) ),
		'zenodo_token'          => array( 'group' => 'issued', 'label' => __( 'Zenodo token (production)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ZENODO_TOKEN', 'option' => 'sn_zenodo_token', 'about' => __( 'A personal access token from zenodo.org › Applications, scopes deposit:write and deposit:actions.', 'signal-and-noise-tools' ), 'feeds' => __( 'the DOI deposits (Connections › Zenodo)', 'signal-and-noise-tools' ), 'probe' => 'zenodo' ),
		'zenodo_sandbox_token'  => array( 'group' => 'issued', 'label' => __( 'Zenodo token (sandbox)', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_ZENODO_SANDBOX_TOKEN', 'option' => 'sn_zenodo_sandbox_token', 'about' => __( 'The same, from sandbox.zenodo.org; its DOIs are test DOIs and never reach a public surface.', 'signal-and-noise-tools' ), 'feeds' => __( 'the DOI deposits while the environment is sandbox', 'signal-and-noise-tools' ), 'probe' => 'zenodo' ),
		'bing_webmaster_key'    => array( 'group' => 'issued', 'label' => __( 'Bing Webmaster API key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_BING_WEBMASTER_KEY', 'option' => 'sn_bing_webmaster_key', 'about' => __( 'From Bing Webmaster Tools › Settings › API access; one key per user, for every verified site.', 'signal-and-noise-tools' ), 'feeds' => __( 'the Bing search reading (S&N Analytics › Search) and its daily sync', 'signal-and-noise-tools' ), 'probe' => 'bing' ),
		'indexnow_key'          => array( 'group' => 'issued', 'label' => __( 'IndexNow key', 'signal-and-noise-tools' ), 'kind' => 'public', 'option' => 'sn_indexnow_key', 'about' => __( 'Minted here and published at its key URL; not a secret.', 'signal-and-noise-tools' ), 'feeds' => __( 'IndexNow pings', 'signal-and-noise-tools' ) ),
		'cloudways_api_key'     => array( 'group' => 'issued', 'label' => __( 'Cloudways API key', 'signal-and-noise-tools' ), 'kind' => 'secret', 'constant' => 'SN_CLOUDWAYS_API_KEY', 'about' => __( 'Account-wide, wp-config only by decision (with SN_CLOUDWAYS_EMAIL, SERVER_ID, APP_ID).', 'signal-and-noise-tools' ), 'feeds' => __( 'the Varnish purge leg', 'signal-and-noise-tools' ) ),
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
	return 'cd ~/Projects/' . (string) $row['other_half']['repo'] . ' && npx wrangler secret put ' . (string) $row['other_half']['secret'];
}

// The handshake accessors that were constant-only now fall back to the
// keyring, so a row switched to the site secret (or saved here) is honoured
// without each module learning the keyring. Constants still win inside
// sn_credential(); an empty filter input is the only case the fallback fills.
add_filter( 'sn_server_token', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'srv_token' ); } );
add_filter( 'sn_analytics_refresh_secret', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'srv_token' ); } );
add_filter( 'sn_bridge_secret', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'bridge_token' ); } );
add_filter( 'sn_prov_hmac_secret', static function ( $secret ) { return '' !== (string) $secret ? $secret : sn_credential( 'prov_hmac_secret' ); } );
add_filter( 'sn_mr_read_token', static function ( $token ) { return '' !== (string) $token ? $token : sn_credential( 'mr_read_token' ); } );
