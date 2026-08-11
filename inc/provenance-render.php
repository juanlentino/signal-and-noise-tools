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
	// Resolve once: both the ledger links below and the verify link need it.
	// Both guards, deliberately. sn_prov_subject_kind() may not be loaded in a
	// partial include, and get_post() does not exist in the standalone test
	// harnesses this module is driven from — five separate suites fatalled on
	// exactly that shape today. In WordPress both always exist, so production
	// behaviour is identical; this only keeps the module testable in isolation.
	$subject_kind = ( function_exists( 'sn_prov_subject_kind' ) && function_exists( 'get_post' ) )
		? sn_prov_subject_kind( get_post( (int) $post_id ) )
		: 'note';
	if ( '' === $subject_kind ) {
		$subject_kind = 'note';
	}
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
		// v10.86.0: the ledger directory follows the subject kind. A signed page
		// lives under pages/, so a panel that linked into notes/ would point a
		// reader at a 404 on the one surface whose whole job is checkability.
		'ledger_url'      => sn_prov_ledger_note_url( (string) $uid, $subject_kind ),
		'ots_url'         => sn_prov_ledger_note_url( (string) $uid, $subject_kind ) . '/v' . (int) ( $latest['version'] ?? 0 ) . '.ots',
		// v10.66.1: was '/provenance/verify', which 404s live. This is the
		// "Verify it yourself" link in the byline panel of EVERY Note — the
		// literal invitation to check the proof, pointing at nothing, on the
		// surface whose entire job is trustworthiness. The docket is /verify
		// (sn_prov_verify_is_request() is the authority, and its docblock
		// explicitly excludes "the unrelated /provenance/verify Page"). The
		// site's OWN 404 log had already flagged this as a genuine broken link;
		// nothing connected that signal back to the emitter. Pinned in
		// tests/provenance-render.php against the matcher.
		// v10.86.0: carry the kind so /verify fetches from the right ledger
		// directory. Omitted for notes, which keeps every existing link and its
		// pinned matcher byte-identical.
		'verify_url'      => 'note' === $subject_kind
			? home_url( '/verify' )
			: home_url( '/verify' ) . '?kind=' . rawurlencode( $subject_kind ),
	);
	return apply_filters( 'sn_note_provenance', $vm, (int) $post_id );
}

/**
 * Public HTML URL of a Note's ledger directory (filterable).
 *
 * @param string $uid Per-Note ledger UUID.
 * @return string
 */
function sn_prov_ledger_note_url( $uid, $kind = 'note' ) {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	// v10.84.0: the ledger directory follows the subject kind, mirroring
	// SUBJECT_KINDS in the provenance Worker. A MAP to a fixed literal, never
	// "{$kind}/" — this string becomes a URL, and an unrecognised kind falls
	// back to notes/ rather than inventing a directory. The default keeps every
	// existing caller (all of which pass a Note) byte-identical.
	$roots = array( 'note' => 'notes', 'page' => 'pages' );
	$root  = isset( $roots[ $kind ] ) ? $roots[ $kind ] : 'notes';
	return "https://github.com/{$owner}/{$repo}/tree/main/{$root}/" . rawurlencode( $uid );
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
	// ONE CHIP PER SUBJECT PER REQUEST — the same invariant v10.87.1 gave the
	// panel, and for the same reason, ahead of the same failure rather than
	// after it.
	//
	// The panel doubled because two callers appeared for one surface and neither
	// could see the other: a template slot and a content-filter append. The chip
	// now has exactly that shape — the theme's byline shortcode and (since the
	// page badge) this file's own append. Today only one fires per subject: a
	// page has no byline, a note does not take the append. That is a property of
	// the CURRENT templates, not of the code, and it is precisely the assumption
	// that stopped being true last time.
	//
	// Safe because a chip repeated for ONE subject is never right, while a chip
	// per subject in a list is untouched — the guard is keyed by post_id, so an
	// archive rendering twenty chips renders twenty.
	static $rendered = array();
	$post_id = (int) $post_id;
	if ( empty( $GLOBALS['SN_PROV_RENDER_GUARD_OFF'] ) && isset( $rendered[ $post_id ] ) ) {
		return '';
	}

	$vm = sn_prov_view_data( $post_id );
	if ( null === $vm ) {
		return ''; // No chain: nothing rendered, so NOT marked.
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

	// Mark here: both remaining paths emit markup, and a caller that got '' back
	// must not consume the one chip this subject gets. Skipped when the seam is
	// off, because then the process is not one request.
	if ( empty( $GLOBALS['SN_PROV_RENDER_GUARD_OFF'] ) ) {
		$rendered[ $post_id ] = true;
	}

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
	// ONE PANEL PER SUBJECT PER REQUEST (v10.87.1).
	//
	// Found live: /about/ was rendering TWO complete provenance records, back to
	// back, on the surface whose entire job is trustworthiness. Two independent
	// fixes for "a signed page shows nothing" had landed at once — a theme slot
	// (theme v11.6.0) and a plugin auto-append (v10.87.0) — and neither could see
	// the other. The auto-append's guard inspects `the_content`, but a template
	// slot renders OUTSIDE the content filter, so it was invisible to exactly the
	// check meant to catch it.
	//
	// Guarding here instead of in either caller is what makes them compose:
	// whoever asks first renders, everyone after gets ''. Two panels for one
	// subject is never the right answer, so this needs no escape hatch — and it
	// holds for any future caller too, including one nobody has written yet.
	// The seam exists because "once per REQUEST" has no meaning in a test
	// harness that renders many simulated requests inside one PHP process.
	// Named rather than implicit, and never consulted in production: nothing in
	// the plugin ever sets it.
	static $rendered = array();
	$post_id = (int) $post_id;
	if ( empty( $GLOBALS['SN_PROV_RENDER_GUARD_OFF'] ) && isset( $rendered[ $post_id ] ) ) {
		return '';
	}

	$vm = sn_prov_view_data( $post_id );
	if ( null === $vm ) {
		return ''; // No chain: not rendered, so NOT marked — a later call may succeed.
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
	// Mark only now, on the path that actually emits markup: a caller that got
	// '' back must not consume the one render this subject gets. Skipped when
	// the seam is off, because then the process is not one request and recording
	// would leak state between simulated ones.
	if ( empty( $GLOBALS['SN_PROV_RENDER_GUARD_OFF'] ) ) {
		$rendered[ $post_id ] = true;
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
 * Enqueue default front-end styling on any single PROVENANCE SUBJECT view. The
 * theme may dequeue or override `sn-provenance-front`.
 *
 * v10.86.0: was `is_singular( 'post' )` plus a notes-category check, which is
 * the same pair of gates the signing path had — and the same reason a signed
 * page rendered nothing. That was not merely cosmetic: the ledger's
 * build-index.mjs discovers a record by fetching the SITE and reading the UID
 * out of the rendered page. A signed page with no panel carries no UID, so it
 * could never be indexed however the index script was widened. The render is
 * the PREREQUISITE for the ledger tooling, not a nicety beside it.
 */
function sn_prov_enqueue_front() {
	if ( ! is_singular( sn_prov_subject_post_types() ) ) {
		return;
	}
	if ( function_exists( 'sn_prov_subject_kind' ) && function_exists( 'get_post' )
		&& '' === sn_prov_subject_kind( get_post( get_the_ID() ) ) ) {
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
/**
 * Append the provenance panel to a signed PAGE.
 *
 * WHY THIS BREAKS THE USUAL DIVISION, DELIBERATELY. Everywhere else the plugin
 * owns the markup and the THEME owns placement — `[sn_prov_panel]` sits in the
 * single-note template, which is why every Note shows its proof. Pages have no
 * such convention: a page template is whatever the author built, so a signed
 * page had markup available and nothing anywhere asking for it. v10.86.0 taught
 * the renderer about pages and the About page still showed nothing, because
 * teaching the renderer was necessary and not sufficient.
 *
 * That left signing and showing as two independent acts: opt a page in, forget
 * the shortcode, and it signs silently and stays invisible — including to the
 * ledger's build-index, which discovers a record by reading the uid out of the
 * rendered page. A proof nobody can see is not a proof anyone can check.
 *
 * So the default is "a signed page shows its proof", and the filter is the way
 * out for a theme that wants to place it itself. Notes are untouched: this only
 * ever fires on `is_singular( 'page' )`.
 *
 * @param string $content Post content.
 * @return string
 *
 * @since 10.87.0
 */
function sn_prov_append_page_panel( $content ) {
	if ( ! is_singular( 'page' ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	if ( ! function_exists( 'sn_prov_subject_kind' ) || ! function_exists( 'get_post' ) ) {
		return $content;
	}
	if ( 'page' !== sn_prov_subject_kind( get_post( get_the_ID() ) ) ) {
		return $content;
	}
	/**
	 * Whether the plugin places the panel on this signed page. Return false to
	 * take placement over in a theme template.
	 */
	if ( ! apply_filters( 'sn_prov_auto_append_page_panel', true, get_the_ID() ) ) {
		return $content;
	}
	// Already placed by hand — the shortcode expands at priority 11, so by the
	// time this runs its markup is in $content. Rendering again would show the
	// same proof twice, which reads as two records rather than one.
	if ( false !== strpos( $content, 'sn-prov-' ) ) {
		return $content;
	}
	// Owner direction (2026-08-11, after seeing the v10.88.0-framed panel
	// standalone on the first signed page): a PAGE shows a BADGE, not the
	// full record block — "maybe just a badge for pages instead of that
	// block". The chip is the same proof compressed: status color, the
	// anchor a click away. NOTES keep their panels — the full record
	// belongs in the post-closing furniture the theme places it in.
	$badge = sn_prov_render_chip( get_the_ID() );
	return '' === $badge ? $content : $content . '<p class="sn-prov-page-badge">' . $badge . '</p>';
}
add_filter( 'the_content', 'sn_prov_append_page_panel', 20 );

add_shortcode( 'sn_provenance_verify', 'sn_prov_verify_shortcode' );
