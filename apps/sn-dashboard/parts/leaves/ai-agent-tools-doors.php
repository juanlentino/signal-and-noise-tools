<?php
/**
 * S&N Dashboard: AI › Agent tools, the MCP doors.
 *
 * Every door is its endpoint, one finding built from the live allowlist, and
 * the whole tool list as a spec-sheet band (one facts row per ability
 * category, the tools as chips carrying their calls). 2026-10-12 audit: each
 * door is an earned fold now (reference; the closed summary states the count
 * and the calls; open when a tool there cannot be projected), and the remote
 * door is listed with the read and write doors. ai-mcp-connect-parts.php
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
 * facts row per category whose value is the tools as chips. With the call log
 * readable, each chip carries its calls through a door in the window, or why
 * it has none (2026-10-12 audit: the counts and the verdicts used to be a
 * ledger and a second section repeating these slugs).
 *
 * @param string[]|null $slugs Allowlist, or null when its reader is not loaded.
 * @param string        $kind  'read-only' or 'read-write'.
 * @param array|null    $usage From sn_mcp_telemetry_usage().
 * @return array{html:string,summary:string,bad:int}
 */
function agent_tools_door_band( $slugs, $kind, $usage = null ) {
	$n      = count( (array) $slugs );
	$groups = agent_tools_door_groups( (array) $slugs );
	$known  = array_diff_key( $groups, array( '' => true ) );
	$top    = (string) array_key_first( $known );
	if ( 0 === $n ) {
		/* translators: %s: read-only or read-write. */
		$none = sprintf( __( 'This %s door exposes no tools.', 'signal-and-noise-tools' ), $kind );
		return array( 'html' => '<p class="snt-prose">' . \snt_kit_esc( $none ) . '</p>', 'summary' => $none, 'bad' => 0 );
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
	$rows    = array();
	$tally   = array( 'called' => 0, 'none' => 0, 'bad' => 0, 'unmeasured' => 0 );
	$reasons = array();
	foreach ( $groups as $label => $group ) {
		$chips = '';
		foreach ( $group as $slug ) {
			$short   = 0 === strpos( $slug, 'signal-noise/' ) ? substr( $slug, 13 ) : $slug;
			$reading = function_exists( 'sn_admin_mcp_tool_reading' ) ? sn_admin_mcp_tool_reading( $slug, $usage ) : null;
			$chips  .= null === $reading ? \snt_kit_chip( $short ) : \snt_kit_chip( $short . ' · ' . $reading['text'], $reading['kind'] );
			if ( null !== $reading && '' !== (string) ( $reading['reason'] ?? '' ) ) {
				++$tally['unmeasured'];
				$reasons[ (string) $reading['reason'] ] = true;
			} elseif ( null !== $reading ) {
				++$tally[ '' === $reading['kind'] ? 'called' : ( 'err' === $reading['kind'] ? 'bad' : 'none' ) ];
			}
		}
		$rows[] = array(
			/* translators: 1: ability category, 2: its tool count. */
			'label' => sprintf( __( '%1$s (%2$s)', 'signal-and-noise-tools' ), '' === (string) $label ? __( 'Category not readable', 'signal-and-noise-tools' ) : (string) $label, number_format_i18n( count( $group ) ) ),
			'value' => \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $chips ),
			'html'  => true,
		);
	}
	$summary = $finding;
	if ( is_array( $usage ) && null !== ( $usage['measured_since'] ?? null ) ) {
		/* translators: 1: tools called, 2: tools with no door call (a tool behind a closed door is counted apart). */
		$summary .= ' ' . sprintf( __( 'In this window %1$s called through a door, %2$s not.', 'signal-and-noise-tools' ), number_format_i18n( $tally['called'] ), number_format_i18n( $tally['none'] + $tally['bad'] ) )
			/* translators: %s: tool count. */
			. ( $tally['bad'] > 0 ? ' ' . sprintf( _n( '%s cannot be projected, a bug.', '%s cannot be projected, a bug.', $tally['bad'], 'signal-and-noise-tools' ), number_format_i18n( $tally['bad'] ) ) : '' );
		// 2026-10-12 ruling: behind a closed door a zero is unknown, said as such.
		if ( $tally['unmeasured'] > 0 ) {
			/* translators: 1: why, e.g. "the remote door is off", 2: tool count. */
			$summary .= ' ' . sprintf( _n( 'Not measured: %1$s (%2$s tool).', 'Not measured: %1$s (%2$s tools).', $tally['unmeasured'], 'signal-and-noise-tools' ), implode( '; ', array_keys( $reasons ) ), number_format_i18n( $tally['unmeasured'] ) );
		}
	}
	$prefixed = array_filter( (array) $slugs, static function ( $slug ) { return 0 === strpos( (string) $slug, 'signal-noise/' ); } );
	$hint     = empty( $prefixed ) ? '' : __( 'The chips drop the signal-noise/ prefix of each ability name.', 'signal-and-noise-tools' );
	if ( '' !== $hint && $summary !== $finding ) {
		$hint .= ' ' . __( 'Each carries its calls through a door in this window, or why it has none.', 'signal-and-noise-tools' );
	}
	return array(
		'html'    => '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>' . \snt_kit_kv( $rows ) . ( '' === $hint ? '' : '<p class="snt-hint">' . \snt_kit_esc( $hint ) . '</p>' ),
		'summary' => $summary,
		'bad'     => $tally['bad'],
	);
}

/**
 * A door as an earned fold (inc/openstation-kit-fold.php): its tool list is
 * reference, the closed summary states the count, the largest category and
 * the calls, and it opens itself when a tool there cannot be projected (a bug
 * needs the reader). The one fold call for all three doors.
 *
 * @param string $heading The door's heading.
 * @param string $before  What precedes the list (prose, the endpoint).
 * @param array  $band    From agent_tools_door_band(), or array() when the list is not readable.
 * @param string $after   What follows the list.
 * @return string
 */
function agent_tools_door_fold( $heading, $before, array $band, $after = '' ) {
	return \snt_kit_fold( $heading, (string) ( $band['summary'] ?? '' ), $before . (string) ( $band['html'] ?? '' ) . $after, ! empty( $band['bad'] ) );
}

/**
 * The read door, the native read-only MCP server.
 *
 * @param string[]|null $slugs The read allowlist.
 * @param array|null    $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function agent_tools_door_native_html( $slugs, $usage = null ) {
	$url    = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$before = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and this door’s tool list is the whole surface: nothing outside it is reachable, even by request.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'ok', __( 'read-only', 'signal-and-noise-tools' ) ) . '</p>'
		. ( null === $slugs ? '<p class="snt-prose">' . \snt_kit_esc( __( 'The read-door allowlist is not readable here (its reader is not loaded), so this door’s tools are not listed.', 'signal-and-noise-tools' ) ) . '</p>' : '' );
	$after  = '<p class="snt-hint">' . \snt_kit_esc( __( 'This door never mutates anything, by construction: the write door carries the actions.', 'signal-and-noise-tools' ) ) . '</p>';
	return agent_tools_door_fold( __( 'Read door: the native MCP server', 'signal-and-noise-tools' ), $before, null === $slugs ? array() : agent_tools_door_band( $slugs, __( 'read-only', 'signal-and-noise-tools' ), $usage ), $after );
}

/**
 * The write door: the band, then the abilities withheld from it as a table
 * (the classic leaf's own data, so the two cannot disagree).
 *
 * @param string[]|null $slugs      The rw allowlist.
 * @param string[]|null $read_slugs The read allowlist, for the "not repeated" count.
 * @param array|null    $usage      From sn_mcp_telemetry_usage().
 * @return string
 */
function agent_tools_door_native_write_html( $slugs, $read_slugs, $usage = null ) {
	$url    = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' ) : '';
	$before = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'The exact same JSON-RPC 2.0 server, the same %s capability, and the same Application Password as the read door: there is no separate write-only credential. What changes is the surface: most tools here can modify your content (posts, postmeta, taxonomy terms, scheduled cron events, cached files); some spend the AI budget configured on this site, and the Jev passes spend TypeSafe credit.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'warn', __( 'read-write', 'signal-and-noise-tools' ) ) . '</p>'
		. ( null === $slugs ? '<p class="snt-prose">' . \snt_kit_esc( __( 'The write-door allowlist is not readable here (its reader is not loaded), so this door’s tools are not listed.', 'signal-and-noise-tools' ) ) . '</p>' : '' );
	$after  = ( null === $read_slugs ? '' : '<p class="snt-hint">' . sprintf( /* translators: %d: live read-door tool count. */ \snt_kit_esc( __( 'This door’s tools/list does not repeat the read door’s %d read-only tools: if you only want to look, connect to the read door instead.', 'signal-and-noise-tools' ) ), count( $read_slugs ) ) . '</p>' )
		. agent_tools_withheld_html();
	return agent_tools_door_fold( __( 'Write door: the same server, read-write', 'signal-and-noise-tools' ), $before, null === $slugs ? array() : agent_tools_door_band( $slugs, __( 'read-write', 'signal-and-noise-tools' ), $usage ), $after );
}

/**
 * The remote door: the read-only twins the Cloudflare Worker reaches through
 * the bridge (2026-10-12 audit: listed nowhere on this leaf until then, and
 * its calls filed as the plugin's own polling).
 *
 * @param string[]|null $slugs The remote slugs.
 * @param array|null    $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function agent_tools_door_remote_html( $slugs, $usage = null ) {
	$url    = function_exists( 'rest_url' ) ? (string) rest_url( 'signal-noise/v1/bridge' ) : '';
	$before = '<p class="snt-prose">' . \snt_kit_esc( __( 'The Cloudflare Worker’s door: a remote MCP client reaches these read-only twins through the Worker, which signs every call with the bridge secret, and nothing outside this list answers. Each runs under a capability no role holds, granted for that one call.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'ok', __( 'read-only', 'signal-and-noise-tools' ) ) . '</p>'
		. ( null === $slugs ? '<p class="snt-prose">' . \snt_kit_esc( __( 'The remote door’s list is not readable here (its reader is not loaded), so its tools are not listed.', 'signal-and-noise-tools' ) ) . '</p>' : '' );
	$after  = '<p class="snt-hint">' . \snt_kit_esc( __( 'Its switch and its state are under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'MCP Clients › Remote door', 'signal-and-noise-tools' ), array( 'sub' => 'mcp-connect' ) ) . '.</p>';
	return agent_tools_door_fold( __( 'Remote door: through the Cloudflare Worker', 'signal-and-noise-tools' ), $before, null === $slugs ? array() : agent_tools_door_band( $slugs, __( 'read-only', 'signal-and-noise-tools' ), $usage ), $after );
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
