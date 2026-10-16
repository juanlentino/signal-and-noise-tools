<?php
/**
 * Signal & Noise Tools: Notes provenance commit-chain core, the subjects and the hooks (the page sign meta, the subject kind, the ledger folder, the subject post types, the author, the wp_after_insert_post hook, the twin's content_signed filter).
 *
 * Split from inc/provenance-core.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page opt-in meta. A page is signed ONLY when this is set.
 *
 * @since 10.84.0
 */
const SN_PROV_SIGN_META = '_sn_prov_sign';

/**
 * The provenance subject kind for a post: 'note', 'page', or '' for "not a
 * subject". The single place that decides what gets signed.
 *
 * WHY PAGES ARE OPT-IN, AND MUST STAY THAT WAY. The ledger is public,
 * append-only and Bitcoin-anchored: every signed version is permanent. Signing
 * pages wholesale would ledger /verify, /stats and the maturity pages —
 * surfaces whose text changes because a number moved, not because anyone wrote
 * anything. Each of those changes would mint a new anchored version of a
 * document nobody intended to publish as a record, forever. A note is an
 * editorial artifact; a page is often a rendering. Only the author knows which
 * pages are the first kind, so only the author turns them on.
 *
 * NOTE THE SECOND GATE, which is easy to miss and was the real reason the
 * post-type line alone could never have extended this: sn_prov_is_note() asks
 * has_term( 'notes', 'category', … ), and `category` is a POST-ONLY taxonomy in
 * WordPress. A page can never satisfy it. Widening the post_type check without
 * this resolver would have looked like shipping the feature and changed
 * nothing at all.
 *
 * @param WP_Post|object $post
 * @return string 'note' | 'page' | ''
 *
 * @since 10.84.0
 */
function sn_prov_subject_kind( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$type    = (string) ( $post->post_type ?? '' );
	$post_id = (int) ( $post->ID ?? 0 );

	if ( 'post' === $type ) {
		return sn_prov_is_note( $post_id ) ? 'note' : '';
	}

	if ( 'page' === $type ) {
		$opted_in = (bool) get_post_meta( $post_id, SN_PROV_SIGN_META, true );
		/**
		 * Whether this page is a provenance subject. Default is the per-page
		 * opt-in meta; the filter exists for programmatic control, never to
		 * turn the whole post type on by returning a bare true.
		 */
		return apply_filters( 'sn_prov_sign_page', $opted_in, $post ) ? 'page' : '';
	}

	return '';
}

/**
 * The public ledger directory for a subject kind.
 *
 * Mirrors the Worker's SUBJECT_KINDS map (src/index.mjs), which is the thing
 * that actually writes these paths. Kept as a map from a validated kind to a
 * FIXED literal for the same reason the Worker does it that way: the directory
 * must never be built from a caller's string.
 *
 * RETURNS '' FOR AN UNKNOWN KIND, AND CALLERS MUST REFUSE RATHER THAN GUESS.
 * There is deliberately no 'notes' default. Defaulting is what filed the About
 * page's v2 under notes/ on 2026-08-19 (fixed in v12.6.5) — in an append-only,
 * Bitcoin-anchored ledger a guessed directory is not a recoverable mistake.
 *
 * @since 12.6.6
 * @param string $kind 'note' | 'page'
 * @return string 'notes' | 'pages' | '' when the kind is not a subject kind.
 */
function sn_prov_ledger_dir( $kind ) {
	$map = array(
		'note' => 'notes',
		'page' => 'pages',
	);
	$kind = (string) $kind;
	return isset( $map[ $kind ] ) ? $map[ $kind ] : '';
}

/**
 * Every post type that can hold a provenance subject.
 *
 * Used by the UID resolver and the reconcile sweep so a widened subject set
 * cannot leave either of them looking at half the corpus. ONE UUID namespace
 * spans them: the ledger path carries the kind, so the resolver stays total.
 *
 * @return string[]
 *
 * @since 10.84.0
 */
function sn_prov_subject_post_types() {
	return (array) apply_filters( 'sn_prov_subject_post_types', array( 'post', 'page' ) );
}

/**
 * The provenance author string for a post (the post author's display name,
 * filterable).
 *
 * @param WP_Post|object $post
 * @return string
 */
function sn_prov_author( $post ) {
	$author = get_the_author_meta( 'display_name', (int) $post->post_author );
	return (string) apply_filters( 'sn_prov_author', $author, $post );
}

/**
 * wp_after_insert_post handler: record a provenance commit when a Note
 * reaches/updates the 'publish' state. Fires once for classic + block
 * editors, after terms + meta are saved. Keeps to update_post_meta only
 * (never re-saves the post), so it cannot re-trigger itself.
 *
 * @param int          $post_id
 * @param WP_Post       $post
 * @param bool          $update
 * @param WP_Post|null  $post_before
 */
function sn_prov_on_after_insert( $post_id, $post, $update, $post_before ) {
	if ( ! sn_prov_active() ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	// v10.84.0: one resolver decides what is a subject and what kind it is.
	// Empty means "not a subject" — a post outside the notes category, or a
	// page the author has not opted in. See sn_prov_subject_kind().
	if ( '' === sn_prov_subject_kind( $post ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	// v9.88.0: a password-protected post IS status=publish, and the commit
	// payload carries the entire normalized post_content to a PUBLIC,
	// append-only ledger (irreversible: git history + a Bitcoin anchor).
	// sn_prov_credential() already gates on this; the recording leg did not.
	if ( '' !== (string) ( $post->post_password ?? '' ) ) {
		return;
	}
	// (The notes-category check now lives inside sn_prov_subject_kind() above,
	// which runs before the status and password gates. Keeping a second copy
	// here would silently re-exclude pages, which have no category at all.)
	sn_prov_record( $post, sn_prov_author( $post ) );
}
add_action( 'wp_after_insert_post', 'sn_prov_on_after_insert', 20, 4 );

/**
 * The twin's `content_signed`: the subject's prose exactly as signing sees it.
 *
 * v14.7.1. The theme's .json twin publishes content_text from RENDERED
 * the_content, while the payload signs sn_prov_normalize_v2( post_content ),
 * the RAW body. For a Note the two agree (no shortcodes, no dynamic blocks).
 * A signed Page carries `[sn_reading_time]` and a core/post-date block, so
 * the twin says "5 min read · May 7, 2026" where the payload says
 * "[sn_reading_time]" and nothing: every signed Page read as twin_drift the
 * moment it was minted (three on 2026-09-14), and /verify called each one
 * "edited since signing". Neither side was wrong; they measured different
 * texts. This field lets both comparers measure the same one: the twin is
 * still built live from the stored body, so an edit since signing still
 * shows, and a rendered shortcode no longer does.
 *
 * Added only for a provenance subject; other documents keep their shape.
 *
 * @param array<string,mixed> $doc  The twin document.
 * @param WP_Post|object      $post
 * @return array<string,mixed>
 */
function sn_prov_twin_content_signed( $doc, $post ) {
	if ( ! is_array( $doc ) || ! is_object( $post ) || '' === sn_prov_subject_kind( $post ) ) {
		return $doc;
	}
	$doc['content_signed'] = sn_prov_normalize_v2( (string) ( $post->post_content ?? '' ) );
	return $doc;
}
add_filter( 'sn_content_json_document', 'sn_prov_twin_content_signed', 10, 2 );
