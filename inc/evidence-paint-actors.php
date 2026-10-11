<?php
/**
 * Signal & Noise Tools: who presented which identity. Section painters for
 * control tokens, "is this crawler real" and unnamed agents, host-agnostic.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows the unnamed table shows before its "+N more" line. */
const SN_EV_UNNAMED_ROWS = 20;

/** A ranked network row: name, a single-tone bar (share of the whole), the count. */
function snt_ev_network_row( $name, $hits, $whole ) {
	$w = rtrim( rtrim( number_format( 100 * (int) $hits / max( 1, (int) $whole ), 3, '.', '' ), '0' ), '.' );
	return '<li class="snt-ev-rank"><span class="snt-ev-rank__name">' . esc_html( (string) $name ) . '</span>'
		. '<span class="snt-ev-bar" aria-hidden="true"><span style="--w:' . esc_attr( $w ) . '%"></span></span>'
		. '<span class="snt-ev-rank__n">' . esc_html( number_format_i18n( (int) $hits ) ) . '</span></li>';
}

/**
 * Control tokens credited to no one: a warning verdict (this IS a finding),
 * then one card per token with its networks ranked.
 *
 * @param array  $imp     snt_mr_impersonations() entries.
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_control_tokens( array $imp, $surface = 'kit' ) {
	$title = __( 'Control tokens credited to no one', 'signal-and-noise-tools' );
	if ( array() === $imp ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'No robots.txt control token was presented as a user agent in this window.', 'signal-and-noise-tools' ) ) );
	}
	$verdict = esc_html( sprintf(
		/* translators: 1: reads, 2: the tokens. */
		__( '%1$s reads presented %2$s, a robots.txt token with no crawler of its own. Credited to no vendor.', 'signal-and-noise-tools' ),
		number_format_i18n( array_sum( array_column( $imp, 'hits' ) ) ),
		implode( ', ', array_column( $imp, 'token' ) )
	) );
	$cards = array();
	foreach ( $imp as $i ) {
		$list = '';
		foreach ( (array) $i['networks'] as $n ) {
			$list .= snt_ev_network_row( $n['network'], $n['hits'], $i['hits'] );
		}
		/* translators: 1: token, 2: reads. */
		$cards[] = snt_ev_box( $surface, sprintf( __( '%1$s: %2$s reads', 'signal-and-noise-tools' ), $i['token'], number_format_i18n( (int) $i['hits'] ) ), snt_basis_chip( 'claimed', 'classic' === $surface ) . ( '' === $list ? snt_ev_lead( $surface, __( 'No network recorded.', 'signal-and-noise-tools' ) ) : '<ol class="snt-ev-ranks">' . $list . '</ol>' ) );
	}
	$grid = 2 === count( $cards ) ? snt_ev_pair( $surface, $cards[0], $cards[1] ) : implode( '', $cards );
	return snt_ev_box( $surface, $title, snt_ev_verdict( $surface, $verdict ) . $grid );
}

/**
 * Is this crawler real: the agents with reads Cloudflare did not verify.
 *
 * @param array  $ex      snt_mr_agent_exceptions().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_agents( array $ex, $surface = 'kit' ) {
	$rows = array();
	foreach ( $ex['rows'] as $a ) {
		$rows[] = array( 'agent' => $a['agent'], 'hits' => number_format_i18n( (int) $a['hits'] ), 'verified' => number_format_i18n( (int) $a['verified'] ), 'network' => $a['network'], 'strip' => snt_ev_strip_cell( $a['split'] ) );
	}
	$cols = array( 'agent' => __( 'Agent', 'signal-and-noise-tools' ), 'hits' => __( 'Reads (claimed)', 'signal-and-noise-tools' ), 'verified' => __( 'Verified', 'signal-and-noise-tools' ), 'network' => __( 'Top network (share)', 'signal-and-noise-tools' ), 'strip' => __( 'Evidence', 'signal-and-noise-tools' ) );
	return snt_ev_box( $surface, __( 'Is this crawler real', 'signal-and-noise-tools' ), snt_ev_lead( $surface, $ex['lead'] ) . ( array() === $rows ? '' : snt_ev_table( $surface, $cols, $rows, $ex['more'], __( 'Is this crawler real', 'signal-and-noise-tools' ) ) ) );
}

/** A short name for a sampled user agent: its bot token, or its first word. */
function snt_ev_ua_name( $ua ) {
	return 1 === preg_match( '/([A-Za-z][\w-]*(?:bot|Bot|BOT|spider|Spider|crawler|Crawler|rowler)[\w-]*)/', (string) $ua, $m ) ? $m[1] : (string) strtok( (string) $ua, ' /' );
}

/**
 * Unnamed user agents: the samples no taxonomy entry names, with who was
 * verified and from where ("not measured" until the edge splits the list).
 *
 * @param array|null $unnamed snt_mr_unnamed_fold(), or null when unread.
 * @param string     $surface kit|classic.
 * @return string
 */
function snt_ev_paint_unnamed( $unnamed, $surface = 'kit' ) {
	$title = __( 'Unnamed user agents', 'signal-and-noise-tools' );
	if ( null === $unnamed ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'The unnamed list could not be read: not measured.', 'signal-and-noise-tools' ) ) );
	}
	if ( array() === $unnamed ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'Every machine read in this window matched the taxonomy.', 'signal-and-noise-tools' ) ) );
	}
	$lead = sprintf(
		/* translators: 1: samples, 2: the three busiest. */
		_n( '%1$d user agent matches no taxonomy entry: %2$s.', '%1$d user agents match no taxonomy entry; %2$s lead.', count( $unnamed ), 'signal-and-noise-tools' ),
		count( $unnamed ),
		implode( ', ', array_map( static fn( $u ) => snt_ev_ua_name( $u['ua_sample'] ), array_slice( $unnamed, 0, 3 ) ) )
	);
	$rows = array();
	foreach ( array_slice( $unnamed, 0, SN_EV_UNNAMED_ROWS ) as $u ) {
		$rows[] = array( 'agent' => $u['ua_sample'], 'hits' => number_format_i18n( (int) $u['hits'] ), 'verified' => snt_mr_basis_count( $u['verified_hits'] ?? null ), 'network' => snt_mr_basis_networks( array_merge( $u, array( 'networks' => array_slice( (array) ( $u['networks'] ?? array() ), 0, 1 ) ) ) ) );
	}
	$cols = array( 'agent' => __( 'User agent (sampled)', 'signal-and-noise-tools' ), 'hits' => __( 'Reads (claimed)', 'signal-and-noise-tools' ), 'verified' => __( 'Verified', 'signal-and-noise-tools' ), 'network' => __( 'Top network', 'signal-and-noise-tools' ) );
	return snt_ev_box( $surface, $title, snt_ev_lead( $surface, $lead ) . snt_ev_table( $surface, $cols, $rows, max( 0, count( $unnamed ) - SN_EV_UNNAMED_ROWS ), $title ) );
}
