<?php
/**
 * S&N Dashboard: AI › Agent tools, the MCP doors.
 *
 * Every door is an open section of the leaf: its endpoint, one finding built
 * from the live allowlist, and the whole tool list as a spec-sheet band (one
 * facts row per ability category, the tools as chips). 2026-10-11: until then
 * the four doors sat in one fold and each tool list in a fold inside it (owner
 * rule: no collapsible sections). Moved from ai-mcp-connect-parts.php, which
 * still reads the allowlists (mcp_connect_door_slugs()).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A door's slugs by ability category label, largest first. A slug whose
 * ability does not answer goes under '' (not readable), last, never a guess.
 *
 * @param string[] $slugs Allowlisted slugs.
 * @return array<string,string[]>
 */
function agent_tools_door_groups( array $slugs ) {
	$groups = array();
	foreach ( $slugs as $slug ) {
		$ability = function_exists( 'sn_mcp_get_ability' ) ? sn_mcp_get_ability( (string) $slug ) : null;
		$cat     = ( is_object( $ability ) && method_exists( $ability, 'get_category' ) ) ? (string) $ability->get_category() : '';
		if ( '' !== $cat && function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $cat ) ) {
			$cat = (string) wp_get_ability_category( $cat )->get_label();
		}
		$groups[ $cat ][] = (string) $slug;
	}
	uasort( $groups, static function ( $a, $b ) { return count( $b ) <=> count( $a ); } );
	if ( isset( $groups[''] ) ) {
		$unread = $groups[''];
		unset( $groups[''] );
		$groups[''] = $unread;
	}
	return $groups;
}

/**
 * One door's finding and band: the count and its largest category, then a
 * facts row per category whose value is the tools as chips.
 *
 * @param string[]|null $slugs Allowlist, or null when its reader is not loaded.
 * @param string        $kind  'read-only' or 'read-write'.
 * @return string
 */
function agent_tools_door_band( $slugs, $kind ) {
	$n      = count( (array) $slugs );
	$groups = agent_tools_door_groups( (array) $slugs );
	$known  = array_diff_key( $groups, array( '' => true ) );
	$top    = (string) array_key_first( $known );
	if ( 0 === $n ) {
		/* translators: %s: read-only or read-write. */
		return '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'This %s door exposes no tools.', 'signal-and-noise-tools' ), $kind ) ) . '</p>';
	} elseif ( empty( $known ) ) {
		/* translators: 1: tool count, 2: read-only or read-write. */
		$finding = sprintf( __( '%1$s %2$s tools; their categories are not readable here, because the Abilities API did not answer for them.', 'signal-and-noise-tools' ), number_format_i18n( $n ), $kind );
	} elseif ( 1 === count( $known ) ) {
		/* translators: 1: tool count, 2: read-only or read-write, 3: category. */
		$finding = sprintf( _n( '%1$s %2$s tool, in %3$s.', '%1$s %2$s tools, all in %3$s.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ), $kind, $top );
	} else {
		/* translators: 1: tool count, 2: read-only or read-write, 3: category count, 4: largest category, 5: its tool count. */
		$finding = sprintf( __( '%1$s %2$s tools across %3$s categories; %4$s holds %5$s of them.', 'signal-and-noise-tools' ), number_format_i18n( $n ), $kind, number_format_i18n( count( $known ) ), $top, number_format_i18n( count( $known[ $top ] ) ) );
	}
	$rows = array();
	foreach ( $groups as $label => $group ) {
		$chips  = implode( '', array_map( static function ( $slug ) { return \snt_kit_chip( 0 === strpos( $slug, 'signal-noise/' ) ? substr( $slug, 13 ) : $slug ); }, $group ) );
		$rows[] = array(
			/* translators: 1: ability category, 2: its tool count. */
			'label' => sprintf( __( '%1$s (%2$s)', 'signal-and-noise-tools' ), '' === (string) $label ? __( 'Category not readable', 'signal-and-noise-tools' ) : (string) $label, number_format_i18n( count( $group ) ) ),
			'value' => \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $chips ),
			'html'  => true,
		);
	}
	$prefixed = array_filter( (array) $slugs, static function ( $slug ) { return 0 === strpos( (string) $slug, 'signal-noise/' ); } );
	return '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>' . \snt_kit_kv( $rows )
		. ( empty( $prefixed ) ? '' : '<p class="snt-hint">' . \snt_kit_esc( __( 'The chips drop the signal-noise/ prefix of each ability name.', 'signal-and-noise-tools' ) ) . '</p>' );
}

/**
 * Door 1, the native read-only MCP server.
 *
 * @param string[]|null $slugs The read allowlist.
 * @return string
 */
function agent_tools_door_native_html( $slugs ) {
	$url  = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$body = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and the tool list below is the whole surface: nothing outside it is reachable, even by request.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'ok', __( 'read-only', 'signal-and-noise-tools' ) ) . '</p>'
		. ( null === $slugs ? '<p class="snt-prose">' . \snt_kit_esc( __( 'The read-door allowlist is not readable here (its reader is not loaded), so this door’s tools are not listed.', 'signal-and-noise-tools' ) ) . '</p>' : agent_tools_door_band( $slugs, __( 'read-only', 'signal-and-noise-tools' ) ) )
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'This door never mutates anything, by construction: the write door carries the actions.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'Read door: the native MCP server', 'signal-and-noise-tools' ), $body );
}

/**
 * Door 1b, the native write door: the band, then the abilities withheld from
 * it as a table (the classic leaf's own data, so the two cannot disagree).
 *
 * @param string[]|null $slugs      The rw allowlist.
 * @param string[]|null $read_slugs The read allowlist, for the "not repeated" count.
 * @return string
 */
function agent_tools_door_native_write_html( $slugs, $read_slugs ) {
	$url  = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' ) : '';
	$body = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'The exact same JSON-RPC 2.0 server, the same %s capability, and the same Application Password as the read door: there is no separate write-only credential. What changes is the surface: most tools here can modify your content (posts, postmeta, taxonomy terms, scheduled cron events, cached files) or spend the AI budget configured on this site; a couple are read-only login-audit exports carrying plaintext usernames, gated behind this higher bar instead of the read door.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'warn', __( 'read-write', 'signal-and-noise-tools' ) ) . '</p>'
		. ( null === $slugs ? '<p class="snt-prose">' . \snt_kit_esc( __( 'The write-door allowlist is not readable here (its reader is not loaded), so this door’s tools are not listed.', 'signal-and-noise-tools' ) ) . '</p>' : agent_tools_door_band( $slugs, __( 'read-write', 'signal-and-noise-tools' ) ) )
		. ( null === $read_slugs ? '' : '<p class="snt-hint">' . sprintf( /* translators: %d: live read-door tool count. */ \snt_kit_esc( __( 'This door’s tools/list does not repeat the read door’s %d read-only tools: if you only want to look, connect to the read door instead.', 'signal-and-noise-tools' ) ), count( $read_slugs ) ) . '</p>' )
		. agent_tools_withheld_html();
	return \snt_kit_section( __( 'Write door: the same server, read-write', 'signal-and-noise-tools' ), $body );
}

/**
 * The abilities withheld from the write door, one permanently, the rest
 * pending an explicit opt-in: a finding counted from the data, then a table.
 *
 * @return string
 */
function agent_tools_withheld_html() {
	if ( ! function_exists( 'sn_admin_mcp_withheld_slug_data' ) ) {
		return '';
	}
	$rows  = array();
	$never = 0;
	foreach ( sn_admin_mcp_withheld_slug_data() as $slug => $info ) {
		$permanent = 'signal-noise/run-cron-event' === (string) $slug;
		$never    += $permanent ? 1 : 0;
		$badge     = (string) ( $info['badge'] ?? '' );
		$rows[]    = array( '_key' => (string) $slug, 'slug' => (string) $slug, 'badge' => array( 'html' => \snt_kit_badge( $permanent ? 'err' : 'warn', $badge ), 'text' => $badge ), 'reason' => (string) ( $info['reason'] ?? '' ) );
	}
	$n       = count( $rows );
	/* translators: 1: withheld count, 2: withheld permanently, 3: held pending an opt-in. */
	$finding = sprintf( _n( '%1$s ability never reaches this door: %2$s permanently, %3$s pending an explicit future opt-in.', '%1$s abilities never reach this door: %2$s permanently, %3$s pending an explicit future opt-in.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ), number_format_i18n( $never ), number_format_i18n( $n - $never ) );
	$columns = array(
		array( 'key' => 'slug', 'label' => __( 'Ability', 'signal-and-noise-tools' ) ),
		array( 'key' => 'badge', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
		array( 'key' => 'reason', 'label' => __( 'Why', 'signal-and-noise-tools' ) ),
	);
	return \snt_kit_ledger( $finding, $columns, $rows, max( 1, $n ) );
}
