<?php
/**
 * Signal & Noise — admin POST handlers: editable content surfaces: Now, Uses, Resume, and their row/text helpers.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: now_save, uses_save, resume_save
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v8.0.1: dispatch a targeted CF edge purge for a virtual content route.
 *
 * The /now + /uses editors persist the option, but the live HTML is edge-cached
 * (Cache Everything) — logged-out visitors kept the stale page until TTL while
 * the owner, riding the logged-in cache bypass, saw fresh content. Purge both
 * slash variants: the theme's route matcher accepts /now and /now/, so either
 * form may sit in the edge cache. Fire-and-forget via sn_cf_purge_urls, which
 * already no-ops when Cloudflare is unconfigured. The function_exists guard
 * covers isolated test bootstraps that load this module without
 * inc/cloudflare-purge.php.
 *
 * @param string $path Route path, e.g. '/now' or '/about/uses'.
 * @return bool Whether a purge request was dispatched.
 */
function sn_content_route_purge( $path ) {
	if ( ! function_exists( 'sn_cf_purge_urls' ) ) {
		return false;
	}
	$path = '/' . trim( (string) $path, '/' );
	return (bool) sn_cf_purge_urls( array( home_url( $path ), home_url( $path . '/' ) ) );
}

/**
 * v10.41.0: one structured field → one clean text token. Unslash-then-sanitize
 * (update_option does NOT unslash — the apostrophe-backslash trap), then
 * collapse any embedded line break: in the `## Label` document every value is
 * one LINE, and a leaked newline would split an item or forge a header.
 * sanitize_text_field collapses breaks in real WP already — the explicit
 * \R pass keeps the guarantee independent of that implementation detail.
 *
 * @param mixed $value Posted (slashed) scalar.
 * @return string
 */
function sn_content_row_field( $value ) {
	$clean = sanitize_text_field( (string) wp_unslash( is_scalar( $value ) ? $value : '' ) );
	return trim( (string) preg_replace( '/\R+/u', ' ', $clean ) );
}

/**
 * v10.41.0: serialize the Now form's posted group rows back into the
 * canonical `## Label` / `- item` document (the stored format is unchanged —
 * it just became an internal detail nobody types).
 *
 * Discipline mirrors the parser's:
 *   - fully blank rows are pruned (never refused);
 *   - items under a BLANK label refuse the whole save (null): in text form
 *     they would silently merge into the previous section or vanish;
 *   - a label with NO items refuses too (review-caught, v10.41.0): emitted
 *     bare beside a valid group the document still parses, the flash says
 *     saved — and the parser drops the bare header, so the section the owner
 *     just typed silently vanishes. Refused, never mis-filed;
 *   - every item gets the `- ` prefix, which shields `#`-leading items from
 *     the header regex on the next parse.
 *
 * @param array $groups Posted now[groups] rows (slashed, untrusted).
 * @return string|null Serialized document ('' when every row is blank), or
 *                     null when the rows cannot survive the text format.
 */
function sn_now_rows_to_text( $groups ) {
	$out = array();
	foreach ( (array) $groups as $group ) {
		$group = is_array( $group ) ? $group : array();
		$label = sn_content_row_field( $group['label'] ?? '' );
		$items = array();
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$item = sn_content_row_field( $item );
			if ( '' !== $item ) {
				$items[] = '- ' . $item;
			}
		}
		if ( '' === $label ) {
			if ( ! empty( $items ) ) {
				return null; // Orphan items — refuse rather than mis-file them.
			}
			continue; // Fully blank row — pruned.
		}
		if ( empty( $items ) ) {
			return null; // Label with no items — the parser would drop it silently.
		}
		$out[] = '## ' . $label;
		foreach ( $items as $line ) {
			$out[] = $line;
		}
		$out[] = '';
	}
	return trim( implode( "\n", $out ) );
}

/**
 * v10.41.0: serialize the Uses form's posted group rows (name/note pairs)
 * back into the canonical `## Label` / `- name | note` document.
 *
 * Same discipline as sn_now_rows_to_text, plus the pipe rule: `|` is the
 * FORMAT's name/note separator, so it is stripped from names (a piped name
 * cannot round-trip — the parser would split at it) and preserved in notes
 * (the parser splits on the FIRST pipe only). A note with no name is refused
 * (null): the parser drops name-less lines, so a silent save would lose it.
 *
 * @param array $groups Posted uses[groups] rows (slashed, untrusted).
 * @return string|null Serialized document ('' when every row is blank), or
 *                     null when the rows cannot survive the text format.
 */
function sn_uses_rows_to_text( $groups ) {
	$out = array();
	foreach ( (array) $groups as $group ) {
		$group = is_array( $group ) ? $group : array();
		$label = sn_content_row_field( $group['label'] ?? '' );
		$items = array();
		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$item = is_array( $item ) ? $item : array();
			$name = trim( str_replace( '|', '', sn_content_row_field( $item['name'] ?? '' ) ) );
			$note = sn_content_row_field( $item['note'] ?? '' );
			if ( '' === $name && '' === $note ) {
				continue; // Blank pair — pruned.
			}
			if ( '' === $name ) {
				return null; // Note without a name cannot survive the format.
			}
			$items[] = '- ' . $name . ( '' !== $note ? ' | ' . $note : '' );
		}
		if ( '' === $label ) {
			if ( ! empty( $items ) ) {
				return null; // Orphan pairs — refuse rather than mis-file them.
			}
			continue; // Fully blank row — pruned.
		}
		if ( empty( $items ) ) {
			return null; // Label with no rows — the parser would drop it silently.
		}
		$out[] = '## ' . $label;
		foreach ( $items as $line ) {
			$out[] = $line;
		}
		$out[] = '';
	}
	return trim( implode( "\n", $out ) );
}

/**
 * v7.5.0: save (or clear) the /now page content (Content → Now Page).
 * Whitespace-only input clears the override — /now reverts to the theme's
 * built-in file content. sanitize_textarea_field per line keeps the document
 * plain text (the theme escapes every item at the render sink anyway).
 * v8.0.1: every mutation that changes the live page (save or clear) purges
 * the route from the edge; refused/unchanged inputs do not.
 * v10.41.0: the structured form (inc/admin-forms/now-page.php) posts
 * now[groups] rows; they serialize back into the SAME text document and ride
 * the same guards below. The now_content string path stays for the flash
 * contract and any non-form caller.
 */
/**
 * Normalize a group's `items` from either shape into the array the row
 * serializers expect. (v10.48.0)
 *
 * The Now / Uses editors stopped rendering one <input> per item and now render
 * ONE TEXTAREA per section, items separated by newlines. That is not just less
 * chrome — it is closer to the truth, because the STORED artifact has always
 * been a text document whose items are lines. The old form was a nested
 * repeatable pretending the storage was a tree.
 *
 * The change is confined to this boundary on purpose: sn_now_rows_to_text() and
 * sn_uses_rows_to_text() keep their array contract and their tests untouched.
 * Both shapes are accepted, so a stale form (a tab left open across the update,
 * a cached page) still saves correctly instead of silently posting nothing.
 *
 * Blank lines are dropped rather than becoming empty items — an empty item would
 * make the serializer refuse the whole save, turning one stray newline into an
 * unexplained "could not parse".
 *
 * @since 10.48.0
 * @param mixed $items Either an array of item strings or a newline-separated string.
 * @return array<int,string>
 */
function sn_content_items_normalize( $items ) {
	if ( is_array( $items ) ) {
		return array_values( $items );
	}
	if ( ! is_string( $items ) || '' === trim( $items ) ) {
		return array();
	}
	$lines = preg_split( '/\R/u', $items );
	$out   = array();
	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );
		// A pasted markdown bullet is the obvious thing to type here; accept it
		// rather than emitting "- - thing" on the round trip.
		$line = (string) preg_replace( '/^[-*]\s+/', '', $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

function sn_handle_now_save( $post ) {
	if ( ! function_exists( 'sn_now_page_save' ) ) {
		return 'now_failed';
	}
	if ( isset( $post['now']['groups'] ) && is_array( $post['now']['groups'] ) ) {
		$groups = array();
		foreach ( (array) $post['now']['groups'] as $k => $g ) {
			$g            = is_array( $g ) ? $g : array();
			$g['items']   = sn_content_items_normalize( $g['items'] ?? array() );
			$groups[ $k ] = $g;
		}
		$raw = sn_now_rows_to_text( $groups );
		if ( null === $raw ) {
			return 'now_unparseable';
		}
	} else {
		$raw = isset( $post['now_content'] ) ? (string) wp_unslash( $post['now_content'] ) : '';
		// sanitize_textarea_field would collapse the newlines we parse on — run it
		// per line instead (strips tags/control chars, keeps the line structure).
		$lines = preg_split( '/\R/u', $raw );
		$raw   = implode( "\n", array_map( 'sanitize_textarea_field', is_array( $lines ) ? $lines : array() ) );
	}

	if ( '' === trim( $raw ) ) {
		if ( sn_now_page_save( '' ) ) {
			sn_content_route_purge( '/now' );
		}
		return 'now_cleared';
	}
	if ( empty( sn_now_parse_sections( $raw ) ) ) {
		// Refuse saves that would parse to nothing — the filter guard would
		// keep the live page on theme content anyway, but a silent "saved"
		// here would lie about what /now is rendering.
		return 'now_unparseable';
	}
	if ( sn_now_page_save( $raw ) ) {
		sn_content_route_purge( '/now' );
		return 'now_saved';
	}
	// v10.33.3: unchanged DOCUMENT, but the page-sync ENGINE may have changed
	// since the last save — still re-render (the resume_resynced pattern from
	// v10.33.2, where this exact gap stranded an engine fix). Idempotent and
	// owner-triggered.
	if ( function_exists( 'sn_now_sync_page' ) ) {
		sn_now_sync_page();
		sn_content_route_purge( '/now' );
		return 'now_resynced';
	}
	return 'now_unchanged';
}

/**
 * v7.6.0: save (or clear) the /uses page content (Content → Uses Page).
 * Mirrors sn_handle_now_save — whitespace-only clears (theme file content
 * returns), zero-group content is refused rather than silently saved, and
 * (v8.0.1) live-page mutations purge /about/uses from the edge.
 * v10.41.0: the structured form (inc/admin-forms/uses-page.php) posts
 * uses[groups] pair rows; same serialize-then-ride-the-guards pattern as
 * sn_handle_now_save above.
 */
/**
 * The /uses counterpart of sn_content_items_normalize(): each line is
 * `name | note`, the exact shape the stored document already uses. (v10.48.0)
 *
 * A note with no name is preserved as a note with no name rather than being
 * dropped here — sn_uses_rows_to_text() refuses that case deliberately, and
 * silently discarding it at the boundary would turn an explicit "could not
 * parse" into invisible data loss.
 *
 * @since 10.48.0
 * @param mixed $items Either an array of {name,note} arrays or a newline string.
 * @return array<int,array{name:string,note:string}>
 */
function sn_content_pairs_normalize( $items ) {
	if ( is_array( $items ) ) {
		return array_values( $items );
	}
	$out = array();
	foreach ( sn_content_items_normalize( $items ) as $line ) {
		$parts = explode( '|', $line, 2 );
		$out[]  = array(
			'name' => trim( $parts[0] ),
			'note' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
		);
	}
	return $out;
}

function sn_handle_uses_save( $post ) {
	if ( ! function_exists( 'sn_uses_page_save' ) ) {
		return 'uses_failed';
	}
	if ( isset( $post['uses']['groups'] ) && is_array( $post['uses']['groups'] ) ) {
		$groups = array();
		foreach ( (array) $post['uses']['groups'] as $k => $g ) {
			$g            = is_array( $g ) ? $g : array();
			$g['items']   = sn_content_pairs_normalize( $g['items'] ?? array() );
			$groups[ $k ] = $g;
		}
		$raw = sn_uses_rows_to_text( $groups );
		if ( null === $raw ) {
			return 'uses_unparseable';
		}
	} else {
		$raw   = isset( $post['uses_content'] ) ? (string) wp_unslash( $post['uses_content'] ) : '';
		$lines = preg_split( '/\R/u', $raw );
		$raw   = implode( "\n", array_map( 'sanitize_textarea_field', is_array( $lines ) ? $lines : array() ) );
	}

	if ( '' === trim( $raw ) ) {
		if ( sn_uses_page_save( '' ) ) {
			sn_content_route_purge( '/about/uses' );
		}
		return 'uses_cleared';
	}
	if ( empty( sn_uses_parse_groups( $raw ) ) ) {
		return 'uses_unparseable';
	}
	if ( sn_uses_page_save( $raw ) ) {
		sn_content_route_purge( '/about/uses' );
		return 'uses_saved';
	}
	// v10.33.3: mirror of the now_resynced path above.
	if ( function_exists( 'sn_uses_sync_page' ) ) {
		sn_uses_sync_page();
		sn_content_route_purge( '/about/uses' );
		return 'uses_resynced';
	}
	return 'uses_unchanged';
}

/**
 * v10.33.0: save the /resume structured document (Content → Resume Page).
 * The posted resume[…] arrays mirror the canonical document shape exactly, so
 * after wp_unslash (update_option does NOT unslash — the apostrophe-backslash
 * trap) the array goes straight to the data layer: sn_resume_doc_normalize()
 * owns trimming, blank-row pruning, bullet kses, and URL discipline, and a
 * document with neither experience nor publications is refused rather than
 * saved — so a bad POST can never blank the live page. Unlike Now/Uses there
 * is no "clear" path: the form always posts the full document. A real save
 * regenerates the Page (inside sn_resume_doc_save) and purges the route.
 */
function sn_handle_resume_save( $post ) {
	if ( ! function_exists( 'sn_resume_doc_save' ) || ! function_exists( 'sn_resume_doc_normalize' ) ) {
		return 'resume_failed';
	}
	$resume = isset( $post['resume'] ) && is_array( $post['resume'] ) ? (array) wp_unslash( $post['resume'] ) : array();
	if ( null === sn_resume_doc_normalize( $resume ) ) {
		return 'resume_refused';
	}
	if ( sn_resume_doc_save( $resume ) ) {
		sn_content_route_purge( '/resume' );
		return 'resume_saved';
	}
	// v10.33.2: an unchanged DOCUMENT must still regenerate the PAGE. The
	// renderer changes between releases while the content doesn't (the
	// v10.33.1 real-block layout fix could never reach the live page: the
	// unchanged-content path skipped the sync entirely, so the owner's
	// re-save kept serving the old wp:html body). A Save click is an owner
	// action and the regeneration is idempotent — always re-render.
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
		sn_content_route_purge( '/resume' );
		return 'resume_resynced';
	}
	return 'resume_unchanged';
}
