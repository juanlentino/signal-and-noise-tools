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
 * Confirmation state of the genesis (founding-snapshot) root: its persisted OTS
 * status and the Bitcoin block it anchored in. Founding-snapshot Notes inherit
 * their verifiability from this one root, so the public surface reads it to
 * decide whether a 'genesis' commit reads as verified-via-snapshot or is still
 * anchoring. The option lives in the genesis module; guard the constant so the
 * render layer resolves it without a hard dependency (and so the standalone
 * test harness, which doesn't load that module, resolves it too).
 *
 * @return array{status:string,bitcoin_block:int}
 */
function sn_prov_genesis_root_state() {
	$opt   = defined( 'SN_PROV_GENESIS_OPT' ) ? SN_PROV_GENESIS_OPT : 'sn_prov_genesis';
	$state = get_option( $opt, array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	return array(
		'status'        => (string) ( $state['status'] ?? '' ),
		'bitcoin_block' => (int) ( $state['bitcoin_block'] ?? 0 ),
	);
}

/**
 * Presentation of a commit status, resolving the one context-dependent case: a
 * 'genesis' (founding-snapshot) commit reads as verified ONLY once the genesis
 * root's own OTS proof is Bitcoin-confirmed; until then the snapshot isn't
 * independently proven, so it stays "Genesis". Every other status maps straight
 * through sn_prov_status_label(). The chip keeps a genesis marker and the panel
 * keeps the "founding snapshot" wording, so a verified snapshot Note stays
 * distinct from an individually-anchored one.
 *
 * @param string $status      The commit's own status.
 * @param string $root_status The genesis root option status ('confirmed'|…).
 * @return array{state:string,label:string} CSS state token + human label.
 */
function sn_prov_present_status( $status, $root_status ) {
	if ( 'genesis' === $status ) {
		return 'confirmed' === $root_status
			? array( 'state' => 'confirmed', 'label' => 'Verified' )
			: array( 'state' => 'genesis', 'label' => 'Genesis' );
	}
	return array( 'state' => $status, 'label' => sn_prov_status_label( $status ) );
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
	$root = sn_prov_genesis_root_state();
	$pres = sn_prov_present_status( $vm['status'], $root['status'] );
	// Keep the founding-snapshot marker even once the chip reads verified, so a
	// snapshot Note stays distinguishable from an individually-anchored one (the
	// 'genesis' state already carries the class; add it back when it flips to
	// 'confirmed'). data-genesis exposes the same distinction machine-readably.
	$genesis_class = ( $vm['is_genesis_only'] && 'genesis' !== $pres['state'] ) ? ' sn-prov-genesis' : '';
	$genesis_attr  = $vm['is_genesis_only'] ? ' data-genesis="1"' : '';
	return sprintf(
		'<span class="sn-prov-chip sn-prov-%s%s" data-status="%s"%s>%s%s</span>',
		esc_attr( $pres['state'] ),
		$genesis_class,
		esc_attr( $pres['state'] ),
		$genesis_attr,
		esc_html( $pres['label'] ),
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
	$root           = sn_prov_genesis_root_state();
	$root_confirmed = 'confirmed' === $root['status'];
	$root_block     = $root['bitcoin_block'];
	$rows           = '';
	foreach ( array_reverse( $vm['versions'] ) as $v ) {
		$pres = sn_prov_present_status( $v['status'], $root['status'] );
		if ( 'genesis' === $v['status'] ) {
			// Founding-snapshot leaf: it's only verified once its root is on Bitcoin;
			// then it shows the root's block, staying labelled "founding snapshot".
			if ( $root_confirmed ) {
				$meta = $root_block
					? 'founding snapshot · block ' . number_format_i18n( $root_block )
					: 'founding snapshot · verified';
			} else {
				$meta = 'genesis snapshot';
			}
		} else {
			$meta = $v['bitcoin_block'] ? 'block ' . number_format_i18n( $v['bitcoin_block'] ) : sn_prov_status_label( $v['status'] );
		}
		$rows .= sprintf(
			'<li class="sn-prov-ver sn-prov-%s"><span class="sn-prov-v">v%d</span> <code>%s</code> <span class="sn-prov-meta">%s</span></li>',
			esc_attr( $pres['state'] ),
			(int) $v['version'],
			esc_html( substr( $v['content_hash'], 0, 12 ) ),
			esc_html( $meta )
		);
	}
	// The caveat tracks the root: once the snapshot is Bitcoin-anchored it IS
	// independently proven, so the honest wording flips from "not proven" to
	// verified-via-snapshot (while still noting the original date is a claim).
	if ( ! $vm['genesis_caveat'] ) {
		$caveat = '';
	} elseif ( $root_confirmed ) {
		$anchor = $root_block ? ', anchored in Bitcoin block ' . number_format_i18n( $root_block ) : '';
		$caveat = '<p class="sn-prov-caveat">Verified via the founding snapshot' . $anchor . '. The snapshot proves this Note existed as of the anchor; its original publication date is claimed by the site, not independently timestamped.</p>';
	} else {
		$caveat = '<p class="sn-prov-caveat">Attested in the genesis snapshot; original date claimed, not independently proven.</p>';
	}
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
