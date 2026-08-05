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
			'bitcoin_txid'  => (string) ( $c['bitcoin_txid'] ?? '' ),
			'confirmations' => isset( $c['confirmations'] ) ? (int) $c['confirmations'] : null,
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
		'bitcoin_block'   => isset( $latest['bitcoin_block'] ) ? (int) $latest['bitcoin_block'] : 0,
		'bitcoin_txid'    => (string) ( $latest['bitcoin_txid'] ?? '' ),
		'confirmations'   => isset( $latest['confirmations'] ) ? (int) $latest['confirmations'] : null,
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
 * Public block-explorer URL for a Bitcoin transaction id (filterable). Lets a
 * reader watch a still-pending anchor confirm on mempool.space without knowing
 * any cryptography. Return '' (or filter away) to disable linking.
 *
 * @param string $txid 64-hex Bitcoin transaction id.
 * @return string
 */
function sn_prov_tx_explorer_url( $txid ) {
	$txid = strtolower( trim( (string) $txid ) );
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $txid ) ) {
		return '';
	}
	return (string) apply_filters( 'sn_prov_tx_explorer', 'https://mempool.space/tx/' . $txid, $txid );
}

/**
 * The Note's single most reader-facing on-chain target: its confirmed Bitcoin
 * block, or the still-in-flight transaction — resolved exactly as the byline
 * chip resolves its own link, so the chip and the panel's plain-language ledger
 * link always point at the same place. Returns array{href:string,kind:string}
 * where kind is 'block' | 'tx' | '' ('' = no public target yet: a genesis root
 * still anchoring, or a pending Note not yet in a transaction). href is '' when
 * kind is ''.
 *
 * @param array $vm   View-model (needs status, is_genesis_only, bitcoin_block, bitcoin_txid).
 * @param array $root Genesis root state from sn_prov_genesis_root_state() (status, bitcoin_block).
 * @return array{href:string,kind:string}
 */
function sn_prov_primary_explorer( $vm, $root ) {
	$pres = sn_prov_present_status( (string) ( $vm['status'] ?? '' ), (string) ( $root['status'] ?? '' ) );
	if ( 'confirmed' === $pres['state'] ) {
		$block = ! empty( $vm['is_genesis_only'] ) ? (int) $root['bitcoin_block'] : (int) $vm['bitcoin_block'];
		$url   = sn_prov_block_explorer_url( $block );
		return array( 'href' => $url, 'kind' => '' === $url ? '' : 'block' );
	}
	if ( 'pending' === $pres['state'] ) {
		$url = sn_prov_tx_explorer_url( (string) ( $vm['bitcoin_txid'] ?? '' ) );
		return array( 'href' => $url, 'kind' => '' === $url ? '' : 'tx' );
	}
	return array( 'href' => '', 'kind' => '' );
}

/**
 * Plain-language call-to-action for the primary on-chain link, written for a
 * reader who has never heard of mempool.space or a block explorer: a confirmed
 * anchor is there to "see"; a still-pending one is there to "watch confirm".
 * Deliberately terse to sit in the panel's quiet register beside its siblings.
 *
 * @param string $kind 'tx' (pending) | anything else (confirmed block).
 * @return string
 */
function sn_prov_explorer_cta( $kind ) {
	return 'tx' === $kind
		? 'Watch it confirm on the public Bitcoin ledger'
		: 'See it on the public Bitcoin ledger';
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

	// Link the chip to the on-chain proof so anyone can check it on the public
	// Bitcoin ledger with no crypto knowledge: a confirmed Note → its block; a
	// pending Note → the in-flight transaction, the label carrying a live N/6
	// count. No target (not yet in a tx, or genesis-still-anchoring) → a plain,
	// unlinked chip.
	$explorer = sn_prov_primary_explorer( $vm, $root );
	$href     = $explorer['href'];
	$suffix   = $vm['is_genesis_only'] ? '' : ' &middot; v' . (int) $vm['version'];
	if ( 'tx' === $explorer['kind'] ) {
		$suffix = null === $vm['confirmations'] ? '' : ' &middot; ' . max( 0, (int) $vm['confirmations'] ) . '/6';
	}
	// When linked, the chip announces itself as a way OUT to the ledger: a small
	// ↗ glyph (a non-jargon "opens elsewhere" cue) plus a plain-language title +
	// aria-label — so a reader who's never heard of mempool.space still knows what
	// they're clicking, on hover and to a screen reader alike.
	$ext = '' === $href ? '' : ' <span class="sn-prov-chip-ext" aria-hidden="true">&#8599;</span>';

	$chip = sprintf(
		'<span class="sn-prov-chip sn-prov-%s%s" data-status="%s"%s>%s%s%s</span>',
		esc_attr( $pres['state'] ),
		$genesis_class,
		esc_attr( $pres['state'] ),
		$genesis_attr,
		esc_html( $pres['label'] ),
		$suffix,
		$ext
	);

	// The Verify link is a SIBLING of the chip, never nested inside it: the chip
	// itself may already be wrapped in an <a> (the on-chain explorer link below),
	// and an <a> inside an <a> is invalid HTML. It carries the version only when
	// the chip shows a specific one (not the founding-snapshot/genesis-only case),
	// so it always points at the exact commit the reader is looking at.
	$verify_href = home_url( '/verify?note=' . rawurlencode( (string) $vm['note_uid'] ) );
	if ( ! $vm['is_genesis_only'] ) {
		$verify_href .= '&v=' . (int) $vm['version'];
	}
	$verify_link = ' <a class="sn-prov-chip-verify" href="' . esc_url( $verify_href ) . '">Verify</a>';

	if ( '' === $href ) {
		// Unlinked chip states (pending with no txid) stay a plain span: the
		// shipped contract (tests/provenance-render.php) is ZERO anchors here.
		// The Verify affordance appears once the chip itself is linkable.
		return $chip;
	}
	$cta = sn_prov_explorer_cta( $explorer['kind'] ) . ' (mempool.space)';
	return '<a class="sn-prov-chip-link" href="' . esc_url( $href ) . '" rel="nofollow noopener" target="_blank" title="' . esc_attr( $cta ) . '" aria-label="' . esc_attr( $cta ) . '">' . $chip . '</a>' . $verify_link;
}

/**
 * Public block-explorer URL for a Bitcoin block height (filterable). Default:
 * mempool.space, which resolves a bare height. Filter `sn_prov_block_explorer`
 * to point at another explorer, or return '' to disable linking entirely.
 *
 * @param int $height Bitcoin block height.
 * @return string URL, or '' for a zero/absent height (or if filtered away).
 */
function sn_prov_block_explorer_url( $height ) {
	$height = (int) $height;
	if ( $height <= 0 ) {
		return '';
	}
	return (string) apply_filters( 'sn_prov_block_explorer', 'https://mempool.space/block/' . $height, $height );
}

/**
 * "block N" as a link to that block on a public explorer, so a reader can click
 * through to the on-chain anchor. Fully escaped. Returns '' for a zero/absent
 * block; degrades to plain escaped text if the explorer URL is filtered away.
 *
 * @param int $height Bitcoin block height.
 * @return string Anchor HTML, plain text, or ''.
 */
function sn_prov_block_link( $height ) {
	$height = (int) $height;
	if ( $height <= 0 ) {
		return '';
	}
	$label = 'block ' . number_format_i18n( $height );
	$url   = sn_prov_block_explorer_url( $height );
	if ( '' === $url ) {
		return esc_html( $label );
	}
	return '<a class="sn-prov-block" href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $label ) . '</a>';
}

/**
 * "block N" for a chain row or the caveat, de-duplicated against the panel's
 * lead link: plain (unlinked) text when it points at the SAME block the lead
 * link already links, an anchor otherwise — so the same on-chain target is never
 * linked twice in one panel, while a version anchored in a DIFFERENT block (or a
 * panel with no lead link at all) still links through. Degrades to plain text
 * for a zero/absent block or a filtered-away explorer, exactly like the linked
 * form ('' for height 0).
 *
 * @param int    $height    Bitcoin block height.
 * @param string $lead_href The lead link's URL ('' when the panel has none).
 * @return string Anchor HTML, plain text, or ''.
 */
function sn_prov_block_meta( $height, $lead_href ) {
	$height = (int) $height;
	if ( $height <= 0 ) {
		return '';
	}
	$url = sn_prov_block_explorer_url( $height );
	if ( '' !== $lead_href && $url === $lead_href ) {
		return esc_html( 'block ' . number_format_i18n( $height ) );
	}
	return sn_prov_block_link( $height );
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
	// The panel's single on-chain link (the plain-language "lead" rendered below).
	// Any chain row or caveat pointing at this SAME target renders as plain text,
	// so the link appears exactly once — a reader never sees the same block/tx
	// linked twice. '' when there's no public target yet (a genesis root still
	// anchoring, or a pending Note not yet in a transaction), in which case the
	// rows keep their own links so an anchored version stays reachable.
	$explorer  = sn_prov_primary_explorer( $vm, $root );
	$lead_href = $explorer['href'];
	$rows      = '';
	foreach ( array_reverse( $vm['versions'] ) as $v ) {
		$pres = sn_prov_present_status( $v['status'], $root['status'] );
		// $meta is emitted as safe HTML (below), so every branch is either an
		// escaped block link (sn_prov_block_link) or explicitly esc_html'd text.
		if ( 'genesis' === $v['status'] ) {
			// Founding-snapshot leaf: it's only verified once its root is on Bitcoin;
			// then it shows the root's block, staying labelled "founding snapshot".
			if ( $root_confirmed ) {
				$meta = $root_block
					? esc_html( 'founding snapshot' ) . ' · ' . sn_prov_block_meta( $root_block, $lead_href )
					: esc_html( 'founding snapshot · verified' );
			} else {
				$meta = esc_html( 'genesis snapshot' );
			}
		} elseif ( $v['bitcoin_block'] ) {
			$meta = sn_prov_block_meta( $v['bitcoin_block'], $lead_href );
		} elseif ( 'pending' === $v['status'] && '' !== sn_prov_tx_explorer_url( $v['bitcoin_txid'] ) ) {
			// Pending but already in a Bitcoin tx: the lead link owns that tx, so the
			// row shows its live N/6 count as plain text; when the lead link points
			// elsewhere (or is absent) the row stays linked so the tx is reachable.
			$turl  = sn_prov_tx_explorer_url( $v['bitcoin_txid'] );
			$label = null === $v['confirmations'] ? 'Pending' : 'Pending &middot; ' . max( 0, (int) $v['confirmations'] ) . '/6';
			$meta  = ( '' !== $lead_href && $turl === $lead_href )
				? $label
				: '<a class="sn-prov-block" href="' . esc_url( $turl ) . '" rel="nofollow noopener" target="_blank">' . $label . '</a>';
		} else {
			$meta = esc_html( sn_prov_status_label( $v['status'] ) );
		}
		$rows .= sprintf(
			'<li class="sn-prov-ver sn-prov-%s"><span class="sn-prov-v">v%d</span> <code>%s</code> <span class="sn-prov-meta">%s</span></li>',
			esc_attr( $pres['state'] ),
			(int) $v['version'],
			esc_html( substr( $v['content_hash'], 0, 12 ) ),
			$meta
		);
	}
	// The caveat tracks the root: once the snapshot is Bitcoin-anchored it IS
	// independently proven, so the honest wording flips from "not proven" to
	// verified-via-snapshot (while still noting the original date is a claim).
	if ( ! $vm['genesis_caveat'] ) {
		$caveat = '';
	} elseif ( $root_confirmed ) {
		$anchor = $root_block ? ', anchored in Bitcoin ' . sn_prov_block_meta( $root_block, $lead_href ) : '';
		$caveat = '<p class="sn-prov-caveat">Verified via the founding snapshot' . $anchor . '. The snapshot proves this Note existed as of the anchor; its original publication date is claimed by the site, not independently timestamped.</p>';
	} else {
		$caveat = '<p class="sn-prov-caveat">Attested in the genesis snapshot; original date claimed, not independently proven.</p>';
	}
	// Lead the panel with that single plainly-worded link, so a reader who doesn't
	// know what a block explorer is still gets an obvious "check this yourself on
	// Bitcoin" entry point. It's the ONLY on-chain link in the panel — the chain
	// rows and caveat render the same target as plain text (see $lead_href above).
	$onchain = '';
	if ( '' !== $lead_href ) {
		$onchain = '<p class="sn-prov-onchain"><a class="sn-prov-onchain-cta" href="' . esc_url( $lead_href ) . '" rel="nofollow noopener" target="_blank">' . esc_html( sn_prov_explorer_cta( $explorer['kind'] ) ) . ' <span class="sn-prov-onchain-host">(mempool.space)</span> &rarr;</a></p>';
	}
	return sprintf(
		'<section class="sn-prov-panel" aria-label="Provenance record">%s
				<ol class="sn-prov-chain">%s</ol>%s
			<p class="sn-prov-links"><a href="%s" rel="nofollow">Download proof (.ots)</a>
			<a href="%s" rel="nofollow">Git ledger</a>
			<a href="%s">Verify it yourself</a></p>
		</section>',
		$onchain,
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
		'Recompute <code>sn-normalize-v1</code> on the published text, rebuild the canonical JSON, and SHA-256 it: it must equal <code>content_hash</code>.',
		'Verify the Ed25519 <code>signature</code> against the public key below.',
		'Run <code>ots verify vN.ots</code>. Bitcoin attests the timestamp; no need to trust this site.',
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
