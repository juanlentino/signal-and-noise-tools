<?php
/**
 * Signal & Noise Tools — Notes provenance: public surfaces.
 *
 * View-model + render helpers exposed to the theme via `sn_note_provenance`,
 * plus the /provenance verify content. Public panel is static-per-render.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the public provenance view-model for a Note, or null if it has no
 * chain. Filterable via `sn_note_provenance`.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function sn_prov_view_data( $post_id ) {
	$chain = sn_prov_get_chain( $post_id );
	if ( ! $chain ) {
		return null;
	}
	$uid      = get_post_meta( (int) $post_id, SN_PROV_UID_META, true );
	$latest   = end( $chain );
	$versions = array();
	foreach ( $chain as $c ) {
		$versions[] = array(
			'version'       => (int) ( $c['version'] ?? 0 ),
			'status'        => (string) ( $c['status'] ?? 'unanchored' ),
			'content_hash'  => (string) ( $c['content_hash'] ?? '' ),
			'bitcoin_block' => isset( $c['bitcoin_block'] ) ? (int) $c['bitcoin_block'] : null,
			'genesis'       => ! empty( $c['genesis'] ),
			'committed_at'  => (string) ( $c['committed_at'] ?? '' ),
		);
	}
	$genesis_only = ( 0 === (int) ( $latest['version'] ?? 0 ) );
	$vm           = array(
		'note_uid'        => (string) $uid,
		'status'          => (string) ( $latest['status'] ?? 'unanchored' ),
		'current_hash'    => (string) ( $latest['content_hash'] ?? '' ),
		'version'         => (int) ( $latest['version'] ?? 0 ),
		'versions'        => $versions,
		'is_genesis_only' => $genesis_only,
		'genesis_caveat'  => $genesis_only,
		'ledger_url'      => sn_prov_ledger_note_url( (string) $uid ),
		'ots_url'         => sn_prov_ledger_note_url( (string) $uid ) . '/v' . (int) ( $latest['version'] ?? 0 ) . '.ots',
		'verify_url'      => home_url( '/provenance/verify' ),
	);
	return apply_filters( 'sn_note_provenance', $vm, (int) $post_id );
}

/**
 * Public HTML URL of a Note's ledger directory (filterable).
 *
 * @param string $uid Per-Note ledger UUID.
 * @return string
 */
function sn_prov_ledger_note_url( $uid ) {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	return "https://github.com/{$owner}/{$repo}/tree/main/notes/" . rawurlencode( $uid );
}

/**
 * Status → human label.
 *
 * @param string $status Commit status.
 * @return string
 */
function sn_prov_status_label( $status ) {
	$map = array(
		'confirmed'  => 'Verified',
		'pending'    => 'Pending',
		'genesis'    => 'Genesis',
		'unanchored' => 'Recording',
	);
	return $map[ $status ] ?? 'Recording';
}

/**
 * The byline chip. Empty string when the Note has no chain.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function sn_prov_render_chip( $post_id ) {
	$vm = sn_prov_view_data( $post_id );
	if ( null === $vm ) {
		return '';
	}
	return sprintf(
		'<span class="sn-prov-chip sn-prov-%s" data-status="%s">%s%s</span>',
		esc_attr( $vm['status'] ),
		esc_attr( $vm['status'] ),
		esc_html( sn_prov_status_label( $vm['status'] ) ),
		$vm['is_genesis_only'] ? '' : ' &middot; v' . (int) $vm['version']
	);
}

/**
 * The expandable record. Empty string when the Note has no chain.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function sn_prov_render_panel( $post_id ) {
	$vm = sn_prov_view_data( $post_id );
	if ( null === $vm ) {
		return '';
	}
	$rows = '';
	foreach ( array_reverse( $vm['versions'] ) as $v ) {
		$meta = 'genesis' === $v['status']
			? 'genesis snapshot'
			: ( $v['bitcoin_block'] ? 'block ' . number_format_i18n( $v['bitcoin_block'] ) : sn_prov_status_label( $v['status'] ) );
		$rows .= sprintf(
			'<li class="sn-prov-ver sn-prov-%s"><span class="sn-prov-v">v%d</span> <code>%s</code> <span class="sn-prov-meta">%s</span></li>',
			esc_attr( $v['status'] ),
			(int) $v['version'],
			esc_html( substr( $v['content_hash'], 0, 12 ) ),
			esc_html( $meta )
		);
	}
	$caveat = $vm['genesis_caveat']
		? '<p class="sn-prov-caveat">Attested in the genesis snapshot; original date claimed, not independently proven.</p>'
		: '';
	return sprintf(
		'<section class="sn-prov-panel" aria-label="Provenance record">
			<ol class="sn-prov-chain">%s</ol>%s
			<p class="sn-prov-links"><a href="%s" rel="nofollow">Download proof (.ots)</a>
			<a href="%s" rel="nofollow">Git ledger</a>
			<a href="%s">Verify it yourself</a></p>
		</section>',
		$rows,
		$caveat,
		esc_url( $vm['ots_url'] ),
		esc_url( $vm['ledger_url'] ),
		esc_url( $vm['verify_url'] )
	);
}

/**
 * Enqueue default front-end styling on single Note views only. The theme may
 * dequeue or override `sn-provenance-front`.
 */
function sn_prov_enqueue_front() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	if ( function_exists( 'sn_prov_is_note' ) && ! sn_prov_is_note( get_the_ID() ) ) {
		return;
	}
	wp_enqueue_style(
		'sn-provenance-front',
		plugins_url( 'assets/provenance-front.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'sn_prov_enqueue_front' );

/**
 * Renders the "verify it yourself" instructions + the published public key.
 *
 * @param array $atts Shortcode attributes (unused).
 * @return string
 */
function sn_prov_verify_shortcode( $atts ) {
	$pub   = function_exists( 'sn_prov_pubkey_b64' ) ? sn_prov_pubkey_b64() : '';
	$steps = array(
		'Fetch the Note\'s <code>vN.json</code> from the git ledger.',
		'Recompute <code>sn-normalize-v1</code> on the published text, rebuild the canonical JSON, and SHA-256 it — it must equal <code>content_hash</code>.',
		'Verify the Ed25519 <code>signature</code> against the public key below.',
		'Run <code>ots verify vN.ots</code> — Bitcoin attests the timestamp; no need to trust this site.',
	);
	$list = '';
	foreach ( $steps as $s ) {
		$list .= '<li>' . wp_kses( $s, array( 'code' => array() ) ) . '</li>';
	}
	return sprintf(
		'<div class="sn-prov-verify"><ol>%s</ol><p class="sn-prov-key"><strong>Public key (Ed25519, base64):</strong> <code>%s</code></p></div>',
		$list,
		esc_html( $pub )
	);
}
add_shortcode( 'sn_provenance_verify', 'sn_prov_verify_shortcode' );
