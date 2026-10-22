<?php
/**
 * Signal & Noise Tools: where a morning brief line points. An admin leaf as
 * an absolute link (the classic page and S&N Home share the address), a
 * watch's screen by id, and a watch's `read` split so tool slugs stay out of
 * the human lines and go to a small "For agents" line instead. Pure apart
 * from admin_url().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mail-send.php';

/** An absolute link to one admin leaf; the classic page and S&N Home share the address. */
function snt_morning_brief_screen_url( $tab, $sub ) {
	return admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $tab ) . '&sub=' . rawurlencode( $sub ) );
}

/**
 * The admin screen a ripe watch is read on, by watch id. Only watches whose
 * screen exists; the rest keep their own words.
 *
 * @param array $w A ripe watch.
 * @return array{label:string,url:string}|null
 */
function snt_morning_brief_watch_screen( array $w ) {
	$screens = array(
		'index_gap'                   => array( 'Integrity › Reports', 'tools', 'reports' ),
		'secrets_api_keyring_storage' => array( 'Connections › Credentials', 'connections', 'credentials' ),
		'mcp_adapter_read_door'       => array( 'AI › MCP Clients', 'ai', 'mcp-connect' ),
		'dompdf_bump'                 => array( 'Content › Resume Page', 'content', 'resume' ),
	);
	$s = $screens[ (string) ( $w['id'] ?? '' ) ] ?? null;
	return null === $s ? null : array( 'label' => $s[0], 'url' => snt_morning_brief_screen_url( $s[1], $s[2] ) );
}

/**
 * A watch's `read` split for people and for agents: tool slugs, option names
 * and CLI reads go to a "For agents" line, never into the human one.
 *
 * @param string $read The watch's read field.
 * @return array{human:string,agents:string}
 */
function snt_morning_brief_read_split( $read ) {
	$human  = array();
	$agents = array();
	foreach ( array_filter( array_map( 'trim', explode( ';', (string) $read ) ), 'strlen' ) as $part ) {
		$is_agent = preg_match( '~\{|^(signal-noise/|sn-[a-z-]+\b|option |post meta |wp )~', $part );
		if ( $is_agent ) {
			$agents[] = $part;
		} else {
			$human[] = $part;
		}
	}
	return array( 'human' => implode( '; ', $human ), 'agents' => implode( '; ', $agents ) );
}

/** A watch's screen as "Screen: URL" on its own line, then the agents' line. */
function snt_morning_brief_pointers_text( array $t ) {
	return ( null !== $t['screen'] ? "\n" . $t['screen']['label'] . ': ' . $t['screen']['url'] : '' )
		. ( '' !== $t['agents'] ? "\nFor agents: " . $t['agents'] : '' );
}

/** The same pointers in HTML: a link named by the screen, the agents' line small. */
function snt_morning_brief_pointers_html( array $t ) {
	return ( null !== $t['screen'] ? '<p>' . sn_mail_link_html( $t['screen']['label'], $t['screen']['url'] ) . '</p>' : '' )
		. ( '' !== $t['agents'] ? '<p style="font-size:13px;color:#555;">For agents: <code>' . esc_html( $t['agents'] ) . '</code></p>' : '' );
}
