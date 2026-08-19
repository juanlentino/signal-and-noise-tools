<?php
/**
 * Signal & Noise — admin POST action handlers.
 *
 * One small function per form action, each fn( array $post ): string that
 * performs the action's side effects (option writes, filter dispatch, module
 * calls) and returns a ?sn_flash=… code. Dispatched by sn_handle_admin_post()
 * (inc/admin-post-handler.php) via the sn_admin_post_handlers() map. Extracted
 * verbatim from the 270-line if/elseif in inc/admin-page.php in v4.5.4.
 *
 * Handlers receive the RAW $_POST and unslash per-field exactly as the original
 * arms did. save_identity still passes the raw array straight to
 * sn_settings_save(), which now wp_unslash()es the whole payload itself
 * (v9.36.1 — the old pass-through-without-unslash behavior added one
 * backslash layer to every apostrophe per save).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_clear_overrides( $post ) {
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return 'cleared_' . $count;
}

function sn_handle_purge_caches( $post ) {
	// v8.7.0: verified=true routes the theme's CF leg through the blocking variant
	// and writes the per-leg sn_last_purge_report. This is the deliberate, watched
	// manual purge, so the extra second on the CF confirmation is acceptable.
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false, 'verified' => true ) );
	return 'purged';
}

function sn_handle_full_reset( $post ) {
	// v4.1.1 (D-07): pass explicit template_overrides=true rather than an
	// empty args array. "Full reset" semantically includes template overrides;
	// being explicit prevents drift if the theme tightens its filter contract.
	// v8.7.0: verified=true (see sn_handle_purge_caches) for the confirmed report.
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true, 'verified' => true ) );
	return 'reset_' . $count;
}

function sn_handle_save_identity( $post ) {
	$saved = sn_settings_save( $post );
	return $saved ? 'identity_saved' : 'identity_unchanged';
}

function sn_handle_save_login( $post ) {
	$slug = isset( $post['login_slug'] ) ? sanitize_title( wp_unslash( $post['login_slug'] ) ) : '';
	if ( ! $slug ) {
		return 'login_empty';
	}
	// v4.2.0 (D-06): write via sn_setting_update() so the per-request static
	// cache is busted — any sn_setting() call later in this request sees the
	// new slug.
	$ok = sn_setting_update( 'login.slug', $slug );
	return $ok ? 'login_saved' : 'login_failed';
}

function sn_handle_cf_save( $post ) {
	$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

	if ( ! $token_const ) {
		$new_token = isset( $post['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( SN_CF_TOKEN_OPT );
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
		}
	}
	if ( ! $zone_const ) {
		$new_zone = isset( $post['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_zone'] ) ) : '';
		if ( 'clear' === $new_zone ) {
			delete_option( SN_CF_ZONE_OPT );
		} elseif ( '' !== $new_zone ) {
			update_option( SN_CF_ZONE_OPT, $new_zone, true );
		}
	}
	return 'cf_saved';
}

function sn_handle_cf_purge_now( $post ) {
	return sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
}


function sn_handle_health_scan( $post ) {
	// v3.5.1: route through the central dispatcher per the established pattern.
	// The impl module owns the work; this handler just dispatches + sets flash.
	if ( function_exists( 'sn_health_run_scan' ) ) {
		$scan = sn_health_run_scan();
		// v8.0.1: findings-aware flash. The runner returns the fresh scan, so
		// the count is free here — a clean run must not promise "findings below".
		if ( is_array( $scan ) && function_exists( 'sn_health_finding_total' ) && 0 === sn_health_finding_total( $scan ) ) {
			return 'health_scanned_clean';
		}
	}
	return 'health_scanned';
}

function sn_handle_webhook_add( $post ) {
	if ( function_exists( 'sn_webhook_create' ) ) {
		$result = sn_webhook_create( wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_invalid';
		}
		// Encode new id in the flash so the renderer can show the secret once.
		return 'wh_added_' . $result['id'];
	}
	return 'wh_invalid';
}

function sn_handle_webhook_update( $post ) {
	if ( function_exists( 'sn_webhook_update' ) ) {
		$id     = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		$rotate = ! empty( $post['rotate_secret'] );
		$result = sn_webhook_update( $id, wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_not_found';
		}
		return $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
	}
	return 'wh_not_found';
}

function sn_handle_webhook_delete( $post ) {
	if ( function_exists( 'sn_webhook_delete' ) ) {
		$id = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		sn_webhook_delete( $id );
	}
	return 'wh_deleted';
}

function sn_handle_insights_run( $post ) {
	if ( ! function_exists( 'snt_insights_run_scan' ) ) {
		return 'insights_failed';
	}
	$force  = ! empty( $post['force'] );
	$result = snt_insights_run_scan( $force );

	if ( is_wp_error( $result ) ) {
		// v7.0.1: record the REAL error so the admin notice can report it. The
		// old handler collapsed EVERY WP_Error to the blanket "configure an AI
		// provider" copy, so a parse error, a transport timeout, or an empty
		// response all read as "you haven't set up AI" even when AI is
		// configured + billing (the weekly digest, same transport, works).
		if ( function_exists( 'snt_insights_store_last_error' ) ) {
			snt_insights_store_last_error( $result );
		}
		// Only a genuine "no AI provider configured" failure earns the
		// configure-AI copy; every other (insights-specific) failure surfaces
		// its real code + message via the 'insights_failed' live-data notice.
		return 'snt_insights_ai_unavailable' === $result->get_error_code()
			? 'insights_ai_unavailable'
			: 'insights_failed';
	}

	// Success: drop any stale diagnostic from a prior failed run.
	if ( function_exists( 'snt_insights_clear_last_error' ) ) {
		snt_insights_clear_last_error();
	}
	return 'insights_scanned';
}

function sn_handle_insights_dismiss( $post ) {
	if ( function_exists( 'snt_insights_dismiss' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_dismiss( $id );
	}
	return 'insights_dismissed';
}

function sn_handle_insights_snooze( $post ) {
	if ( function_exists( 'snt_insights_snooze' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_snooze( $id );
	}
	return 'insights_snoozed';
}

function sn_handle_insights_mark_done( $post ) {
	if ( function_exists( 'snt_insights_mark_done' ) ) {
		$id = isset( $post['rec_id'] ) ? sanitize_text_field( wp_unslash( $post['rec_id'] ) ) : '';
		snt_insights_mark_done( $id );
	}
	return 'insights_done';
}

function sn_handle_save_insights_settings( $post ) {
	// v4.2.0 (D-06): write via sn_setting_update() — busts the per-request
	// cache so the cron sync below reads back the new value.
	$enabled = ! empty( $post['insights_weekly_cron'] );
	sn_setting_update( 'insights.weekly_cron_enabled', $enabled );

	// Sync the cron schedule with the new setting.
	if ( $enabled ) {
		if ( function_exists( 'snt_insights_maybe_schedule_weekly_cron' ) ) {
			snt_insights_maybe_schedule_weekly_cron();
		}
	} else {
		if ( function_exists( 'snt_insights_unschedule_weekly_cron' ) ) {
			snt_insights_unschedule_weekly_cron();
		}
	}

	return 'insights_settings_saved';
}

function sn_handle_audit_save_retention( $post ) {
	$raw  = isset( $post['audit_retention_days'] ) ? (int) $post['audit_retention_days'] : 90;
	$days = max( 7, min( 365, $raw ) );
	$ok   = sn_setting_update( 'audit.retention_days', $days );
	return $ok ? 'audit_retention_saved' : 'audit_retention_unchanged';
}

/**
 * v7.2.0: save the weekly security-digest opt-in (Security → Login defense), or
 * send a test digest when the test button submitted. Single-key write via
 * sn_setting_update() (no whole-subtree replace), then immediate cron sync so
 * the toggle takes effect without waiting for the next init.
 */
function sn_handle_security_digest_save( $post ) {
	if ( isset( $post['sn_digest_test'] ) ) {
		return snt_security_digest_send( true ) ? 'digest_test_sent' : 'digest_test_failed';
	}
	sn_setting_update( 'audit.digest_email_enabled', isset( $post['sn_digest_enabled'] ) );
	if ( function_exists( 'snt_security_digest_maybe_schedule_cron' ) ) {
		snt_security_digest_maybe_schedule_cron();
	}
	return 'digest_saved';
}

/** Save/test the Operations brief, or explicitly move the drift baseline. */
function sn_handle_morning_brief_save( $post ) {
	if ( isset( $post['snt_morning_brief_test'] ) ) {
		return snt_morning_brief_send( true ) ? 'morning_brief_test_sent' : 'morning_brief_test_failed';
	}
	if ( isset( $post['snt_config_drift_acknowledge'] ) && function_exists( 'snt_config_drift_acknowledge' ) ) {
		snt_config_drift_acknowledge();
		return 'config_drift_acknowledged';
	}
	sn_setting_update( 'operations.morning_brief_enabled', isset( $post['snt_morning_brief_enabled'] ) );
	if ( function_exists( 'snt_morning_brief_maybe_schedule_cron' ) ) {
		snt_morning_brief_maybe_schedule_cron();
	}
	return 'morning_brief_saved';
}

/** Save the scheduled read-only runs toggle, or run the fixed list now. */
function sn_handle_scheduled_reads_save( $post ) {
	if ( isset( $post['snt_scheduled_reads_now'] ) ) {
		return null !== snt_scheduled_reads_run() ? 'scheduled_reads_ran' : 'scheduled_reads_run_failed';
	}
	sn_setting_update( 'operations.scheduled_reads_enabled', isset( $post['snt_scheduled_reads_enabled'] ) );
	if ( function_exists( 'snt_scheduled_reads_maybe_schedule_cron' ) ) {
		snt_scheduled_reads_maybe_schedule_cron();
	}
	return 'scheduled_reads_saved';
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

function sn_handle_pattern_adoption_scan( $post ) {
	// v4.3.0: routes through the central dispatcher per the health_scan pattern.
	if ( function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		snt_pattern_adoption_run_scan();
	}
	return 'pattern_adoption_scanned';
}

function sn_handle_block_migrations_scan( $post ) {
	// v4.5.0: mirrors the pattern_adoption_scan dispatcher.
	if ( function_exists( 'snt_block_migrations_run_scan' ) ) {
		snt_block_migrations_run_scan();
	}
	return 'block_migrations_scanned';
}

/**
 * v4.9.0 (T4): save the uptime heartbeat settings from the Webhooks tab
 * (Better Stack heartbeat or Uptime Kuma push — provider-neutral copy
 * since v8.1.6; the uptime_kuma_* field/key names are historical, kept).
 * Writes through sn_setting_update('monitoring.*', …) then reconciles the
 * cron schedule immediately so toggling on/off takes effect without waiting
 * for the next init.
 */
function sn_handle_monitoring_save( $post ) {
	// v8.2.0: Better Stack API token (status panel). Handled FIRST and
	// independently of the push-URL https gate so a rejected URL never eats
	// a freshly pasted token. Cloudflare-token contract: obscured round-trip
	// and empty field keep the stored value; only the literal 'clear'
	// removes it. Constant-locked installs never reach this (the field is
	// disabled and unnamed). Snapshot transient dropped on change so the
	// panel never serves a stale token's data.
	if ( ! defined( 'SN_BETTERSTACK_API_TOKEN' ) || ! SN_BETTERSTACK_API_TOKEN ) {
		$token_opt = defined( 'SN_UPTIME_STATUS_TOKEN_OPT' ) ? SN_UPTIME_STATUS_TOKEN_OPT : 'sn_betterstack_api_token';
		$new_token = isset( $post['sn_betterstack_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_betterstack_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( $token_opt );
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'sn_uptime_status_snapshot' );
				delete_transient( 'sn_uptime_availability' ); // v8.3.0 map
			}
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( $token_opt, $new_token, false ); // not autoloaded
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'sn_uptime_status_snapshot' );
				delete_transient( 'sn_uptime_availability' ); // v8.3.0 map
			}
		}
	}

	// v10.75.0: the Spend-watch credentials ride the same monitoring form,
	// each on the identical masked/'clear' contract (module owns the logic).
	if ( function_exists( 'sn_spend_watch_handle_save' ) ) {
		sn_spend_watch_handle_save( $post );
	}

	$enabled = ! empty( $post['uptime_kuma_enabled'] );
	$url     = isset( $post['uptime_kuma_push_url'] )
		? esc_url_raw( trim( (string) wp_unslash( $post['uptime_kuma_push_url'] ) ) )
		: '';

	// T4 (Fix C): enforce https on the push URL, matching the UI's "Must be
	// https://" hint. esc_url_raw permits http/ftp/etc.; an http push URL would
	// leak the monitor token over the wire. Reject + clear + flash an error.
	$had = ( '' !== $url );
	if ( $had && 0 !== stripos( $url, 'https://' ) ) {
		$url = '';
		sn_setting_update( 'monitoring.uptime_kuma_enabled', $enabled );
		sn_setting_update( 'monitoring.uptime_kuma_push_url', $url );
		if ( function_exists( 'sn_uptime_heartbeat_schedule' ) ) {
			sn_uptime_heartbeat_schedule();
		}
		return 'monitoring_url_not_https';
	}

	sn_setting_update( 'monitoring.uptime_kuma_enabled', $enabled );
	sn_setting_update( 'monitoring.uptime_kuma_push_url', $url );

	// Apply the schedule change now (the init-time reconciler already ran).
	if ( function_exists( 'sn_uptime_heartbeat_schedule' ) ) {
		sn_uptime_heartbeat_schedule();
	}

	return 'monitoring_saved';
}

/**
 * v4.10.0 (T6): save the Speculation Rules toggle from the Site → Performance
 * sub-tab. Writes the boolean through sn_setting_update('perf.speculative_loading',
 * …); the wp_speculation_rules_configuration filter reads it on the next page load.
 */
function sn_handle_perf_save( $post ) {
	$enabled = ! empty( $post['speculative_loading'] );
	sn_setting_update( 'perf.speculative_loading', $enabled );
	return 'perf_saved';
}

/**
 * v4.12.0: the AI text-generation model allowlist (single source for the
 * Front-End form's <select> AND the save handler's validation). Keys are the
 * model ids passed to the snt_ai_model_preference filter; values are UI labels.
 *
 * Ids are the alias form (no date suffix), verified Active against the
 * claude-api model catalog: Sonnet 5 (default), Opus 4.8 (most capable),
 * Haiku 4.5 (fastest/cheapest). v6.52.0: this stays a small
 * hand-maintained list rather than a live enumeration. The WP AI Client exposes
 * no public model-list helper (only an SDK-internal registry path that hits the
 * network on admin render and is untestable in CI), so a curated allowlist keeps
 * the picker priced, predictable, and testable. Loaded unconditionally at
 * bootstrap, so it is available on the front end too (sn_tf_ai_model() calls it
 * during AI requests).
 *
 * @return array<string,string>
 */
function sn_theme_ai_models() {
	return array(
		'claude-sonnet-5'   => 'Claude Sonnet 5 (balanced, default)',
		'claude-opus-4-8'   => 'Claude Opus 4.8 (most capable)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (fastest, cheapest)',
	);
}

/**
 * Curated vision-capable model allowlist for the alt-text route (v7.3.0).
 * Same contract as sn_theme_ai_models(): keys are wp-ai-client model ids
 * (Gemini ids resolve live from the provider), values are UI labels. The
 * default pin matches the ai-bootstrap alt-text route.
 *
 * @return array<string,string>
 */
function sn_theme_ai_vision_models() {
	return array(
		'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (default: fast, cheap vision)',
		'gemini-2.5-flash'      => 'Gemini 2.5 Flash (stronger vision)',
		'gemini-2.5-pro'        => 'Gemini 2.5 Pro (strongest: slower, pricier)',
	);
}

/**
 * v4.12.0: persist the Front-End settings form (Site → Front-End sub-tab).
 *
 * Sparse writes via sn_setting_update() so the sibling sn_settings subtrees are
 * never clobbered (same whole-option-replace hazard the audit/monitoring/perf
 * handlers avoid). Ints are clamped to the same bounds the theme-filter
 * callbacks enforce; the model select is VALIDATED against the allowlist
 * (validation > sanitization) and falls back to the current value (then the
 * first allowlisted id) when an off-list id is posted.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_save_theme( $post ) {
	$ok  = sn_setting_update( 'theme.related_count', max( 1, min( 12, (int) ( $post['theme_related_count'] ?? 3 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_recent_count', max( 0, min( 20, (int) ( $post['theme_palette_recent_count'] ?? 8 ) ) ) );
	$ok &= sn_setting_update( 'theme.palette_enabled', ! empty( $post['theme_palette_enabled'] ) );
	$ok &= sn_setting_update( 'theme.json_feed_items', max( 1, min( 50, (int) ( $post['theme_json_feed_items'] ?? 20 ) ) ) );
	$ok &= sn_setting_update( 'theme.updated_threshold_days', max( 1, min( 90, (int) ( $post['theme_updated_threshold_days'] ?? 14 ) ) ) );
	$ok &= sn_setting_update( 'theme.reading_wpm', max( 100, min( 400, (int) ( $post['theme_reading_wpm'] ?? 225 ) ) ) );
	$ok &= sn_setting_update( 'theme.notes_per_page', max( 1, min( 100, (int) ( $post['theme_notes_per_page'] ?? 20 ) ) ) );

	// v10.46.0: theme.ai_model / theme.ai_alt_model / theme.ai_monthly_budget
	// moved to sn_handle_ai_settings_save() below. They MUST NOT be read here any
	// more — this handler now runs against a form that no longer posts them, so a
	// leftover read would resolve to `?? 0` / '' on every front-end save and
	// silently reset the budget to zero on each one.
	return $ok ? 'theme_saved' : 'theme_unchanged';
}

/**
 * v10.46.0: save the three AI settings, split out of sn_handle_save_theme()
 * when the AI tab was created.
 *
 * WHY SPLITTING THIS FORM IS SAFE. Splitting one settings form into two is the
 * classic subtree-clobber bug in this codebase: a handler that writes a whole
 * settings subtree at once blanks every sibling key the smaller form no longer
 * posts. That is not the shape here — both handlers write through PER-KEY
 * sn_setting_update() calls, so each touches only the keys it names and neither
 * can erase the other's. Checked against sn_handle_save_theme() before the
 * split; if that handler is ever converted to a subtree write, this pairing has
 * to be revisited.
 *
 * Validation, not sanitization, on the two model ids: an off-list id keeps the
 * currently stored value (then the first allow-listed id), so a tampered POST
 * can never park an unknown model id in settings. Carried over verbatim from
 * the v7.3.0 / v9.26.0 originals.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
/**
 * v10.46.0: save the analytics collector endpoint, moved to Measurement →
 * Analytics from Content → RSS.
 *
 * MERGE, NEVER REPLACE. The value lives inside the RSS tracker's settings option
 * (SN_RSS_TRACKER_SETTINGS_OPT) alongside enabled / event_name /
 * log_retention_days. That option's own save branch in inc/rss-feed-tracker.php
 * rebuilds the whole array from $_POST, which is fine for the form that posts
 * every key — but this handler must NOT do that, or saving the collector would
 * blank the other three. It reads the current settings, replaces one key, and
 * writes back.
 *
 * The key stays in the RSS option rather than moving to `analytics.*`: relocating
 * it needs a settings migration, and inc/worker-version.php reads it from there
 * to derive the /_sn/version probe base. Where a value is EDITED and where it is
 * STORED are separate questions; only the first one moved.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_analytics_collector_save( $post ) {
	if ( ! function_exists( 'sn_rss_tracker_settings' ) ) {
		return 'analytics_collector_failed';
	}
	$url = isset( $post['sn_an_collector_url'] ) ? esc_url_raw( wp_unslash( $post['sn_an_collector_url'] ) ) : '';
	if ( '' === $url ) {
		return 'analytics_collector_invalid';
	}
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
		return 'analytics_collector_invalid';
	}

	$current = (array) sn_rss_tracker_settings();
	if ( ( $current['collector_url'] ?? '' ) === $url ) {
		return 'analytics_collector_unchanged';
	}
	$current['collector_url'] = $url;
	update_option( SN_RSS_TRACKER_SETTINGS_OPT, $current );

	return 'analytics_collector_saved';
}

function sn_handle_ai_settings_save( $post ) {
	$allowed = array_keys( sn_theme_ai_models() );
	$model   = isset( $post['theme_ai_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_model'] ) ) : '';
	$ok      = sn_setting_update( 'theme.ai_model', in_array( $model, $allowed, true ) ? $model : (string) sn_setting( 'theme.ai_model', $allowed[0] ) );

	// v7.3.0: vision (alt-text) model — same validate-against-allowlist pattern;
	// an off-list id keeps the current value (then the pinned default).
	$vision_allowed = array_keys( sn_theme_ai_vision_models() );
	$vision         = isset( $post['theme_ai_alt_model'] ) ? sanitize_text_field( wp_unslash( $post['theme_ai_alt_model'] ) ) : '';
	$ok            &= sn_setting_update( 'theme.ai_alt_model', in_array( $vision, $vision_allowed, true ) ? $vision : (string) sn_setting( 'theme.ai_alt_model', $vision_allowed[0] ) );

	// v9.26.0: monthly AI budget in USD. Clamp to >= 0 at cents precision; 0 = off.
	$ok &= sn_setting_update( 'theme.ai_monthly_budget', round( max( 0, (float) ( $post['theme_ai_monthly_budget'] ?? 0 ) ), 2 ) );

	// item 8: the Workers AI token. Masked field, so an un-edited '••••…'
	// placeholder must never be written back over the real value — the same
	// round-trip the analytics token already uses. 'clear' removes it.
	if ( isset( $post['sn_ml_embeddings_token'] ) ) {
		$embed = sanitize_text_field( wp_unslash( $post['sn_ml_embeddings_token'] ) );
		if ( 'clear' === strtolower( $embed ) ) {
			$ok &= sn_setting_update( 'ml.embeddings_token', '' );
		} elseif ( '' !== $embed && 0 !== strpos( $embed, '••••' ) ) {
			$ok &= sn_setting_update( 'ml.embeddings_token', $embed );
		}
	}

	return $ok ? 'ai_settings_saved' : 'ai_settings_unchanged';
}


/**
 * v4.13.0 (Music Identity, T6): save ONE masked, constant-lockable credential.
 *
 * Shared by the Spotify client id + secret. Mirrors the cf_save per-field
 * pattern (locked fields skip; 'clear' deletes) BUT fixes the masked-skip check:
 * the obscured value is "••••" + last 4 chars, so the placeholder is detected
 * with 0 === strpos($v, '••••'), NOT substr($v, 0, 4) (a bullet is 3 bytes, so
 * substr cuts mid-character and the comparison never matches — which would
 * persist the literal placeholder). Returns the running $changed flag, OR'd with
 * whether THIS field actually changed (update_option returns false when the
 * value is identical, so an unedited save reports music_unchanged).
 *
 * @param array  $post    Raw $_POST.
 * @param string $field   POST field name.
 * @param string $opt     Option key.
 * @param string $const   wp-config constant name that locks this field.
 * @param bool   $changed Running changed flag.
 * @return bool Updated changed flag.
 */
function sn_music_save_cred( $post, $field, $opt, $const, $changed ) {
	if ( defined( $const ) && constant( $const ) ) {
		return $changed; // locked by wp-config — admin edits are ignored.
	}
	$value = isset( $post[ $field ] ) ? sanitize_text_field( wp_unslash( $post[ $field ] ) ) : '';
	if ( 'clear' === $value ) {
		delete_option( $opt );
		return true;
	}
	// Skip the masked placeholder (leaves the stored value untouched). A real
	// pasted value never begins with the bullet run.
	if ( '' !== $value && 0 !== strpos( $value, '••••' ) && update_option( $opt, $value, false ) ) {
		return true;
	}
	return $changed;
}

/**
 * v4.13.0 (Music Identity, T6): save the Connections → Discography credentials.
 *
 * Spotify client id + secret (masked, non-autoloaded, constant-lockable via
 * SN_SPOTIFY_CLIENT_ID / SN_SPOTIFY_CLIENT_SECRET) + the Muso profile id (not
 * secret — it's in the public Muso URL — but still constant-lockable via
 * SN_MUSO_PROFILE_ID). No Muso credential exists: the data source is the
 * unauthenticated public endpoint. Drops the cached Spotify token on any change
 * so the next sync re-authenticates.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_save( $post ) {
	// v4.14.0: featured-release URL — validate BEFORE any write so a bad paste
	// errors cleanly instead of partially saving the other fields.
	$raw_featured    = isset( $post['sn_music_featured'] ) ? trim( (string) wp_unslash( $post['sn_music_featured'] ) ) : '';
	$featured_parsed = null;
	if ( '' !== $raw_featured && 'clear' !== $raw_featured ) {
		$featured_parsed = function_exists( 'sn_music_featured_parse' ) ? sn_music_featured_parse( $raw_featured ) : null;
		if ( ! $featured_parsed ) {
			return 'music_featured_invalid';
		}
	}

	$changed = false;
	$changed = sn_music_save_cred( $post, 'sn_spotify_id', SN_SPOTIFY_ID_OPT, 'SN_SPOTIFY_CLIENT_ID', $changed );
	$changed = sn_music_save_cred( $post, 'sn_spotify_secret', SN_SPOTIFY_SECRET_OPT, 'SN_SPOTIFY_CLIENT_SECRET', $changed );

	// Muso profile id — plain (no mask), constant-lockable.
	if ( ! ( defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID ) ) {
		$pid = isset( $post['sn_muso_profile'] ) ? sanitize_text_field( wp_unslash( $post['sn_muso_profile'] ) ) : '';
		if ( 'clear' === $pid ) {
			delete_option( SN_MUSO_PROFILE_OPT );
			$changed = true;
		} elseif ( '' !== $pid && update_option( SN_MUSO_PROFILE_OPT, $pid, false ) ) {
			$changed = true;
		}
	}

	// Featured release — apply (validated above).
	if ( defined( 'SN_MUSIC_FEATURED_OPT' ) ) {
		if ( 'clear' === $raw_featured ) {
			delete_option( SN_MUSIC_FEATURED_OPT );
			$changed = true;
		} elseif ( is_array( $featured_parsed ) && update_option( SN_MUSIC_FEATURED_OPT, $featured_parsed, false ) ) {
			$changed = true;
		}
	}

	if ( $changed && function_exists( 'sn_spotify_invalidate_token' ) ) {
		sn_spotify_invalidate_token(); // creds changed → force re-auth next sync.
	}
	return $changed ? 'music_saved' : 'music_unchanged';
}

/**
 * v4.13.0 (Music Identity, T6): run a discography sync on demand ("Sync now").
 * Routes through the central orchestrator; a false return means the source
 * failed and the last-good store was preserved (page never blanks).
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_music_sync( $post ) {
	if ( ! function_exists( 'sn_discography_run_sync' ) ) {
		return 'music_sync_failed';
	}
	return sn_discography_run_sync() ? 'music_synced' : 'music_sync_failed';
}

/**
 * Commit a tag merge (POSTed from the Content > Tags confirm panel). The central
 * dispatcher already verified the nonce + manage_options. Returns a ?sn_flash code.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_tag_merge( $post ) {
	$from = array_filter( array_map( 'intval', explode( ',', isset( $post['sn_tag_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_tag_from'] ) ) : '' ) ) );
	$into = isset( $post['sn_tag_into'] ) ? (int) $post['sn_tag_into'] : 0;
	if ( ! $from || ! $into || ! function_exists( 'sn_tag_merge' ) ) {
		return 'tag_merge_error';
	}
	$res = sn_tag_merge( $from, $into );
	return is_wp_error( $res ) ? 'tag_merge_error' : 'tag_merge_ok';
}

/**
 * Run the AI tag-suggestion pass over untagged Notes; store the results in a
 * per-user transient for review. Returns a flash code.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_suggest( $post ) {
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return 'tag_ai_unavailable';
	}
	if ( ! function_exists( 'sn_tag_untagged_notes' ) || ! function_exists( 'snt_ai_tag_suggest_impl' ) ) {
		return 'tag_ai_none';
	}
	$results = array();
	foreach ( sn_tag_untagged_notes( 20 ) as $note ) {
		$out = snt_ai_tag_suggest_impl( (int) $note['id'] );
		if ( ! is_wp_error( $out ) && ! empty( $out['suggested'] ) ) {
			$out['title'] = (string) $note['title'];
			$results[]    = $out;
		}
	}
	if ( ! $results ) {
		return 'tag_ai_none';
	}
	set_transient( 'sn_tag_ai_suggestions_' . get_current_user_id(), $results, HOUR_IN_SECONDS );
	return 'tag_ai_suggested';
}

/**
 * Apply the AI tag suggestions the owner checked. Reads assign[post_id][] = term_id.
 *
 * SECURITY (v6.39.2): the POSTed assign map is fully attacker-controllable, so
 * it is NOT trusted directly. The cached suggestion transient written by
 * sn_handle_tag_ai_suggest() is the authoritative allow-list — a (post,term)
 * pair is applied ONLY when:
 *   1. SN proposed that exact term for that exact post in this user's last scan,
 *   2. the post is an editable Note (post_type 'post' — the only type the
 *      suggester scans; never a page/CPT/attachment), and
 *   3. the current user can edit_post that specific post (per-resource cap, not
 *      a blanket manage_options — the dispatcher already checked the nonce).
 * Submitted term ids are intersected with the suggested set for that post, so a
 * forged term riding alongside a legitimate one is dropped, not applied.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_apply( $post ) {
	$assign = isset( $post['assign'] ) && is_array( $post['assign'] ) ? wp_unslash( $post['assign'] ) : array();

	// Build the allow-list: post_id => set of suggested term_ids.
	$cache   = get_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	$allowed = array();
	if ( is_array( $cache ) ) {
		foreach ( $cache as $row ) {
			if ( ! is_array( $row ) || empty( $row['suggested'] ) || ! is_array( $row['suggested'] ) ) {
				continue;
			}
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			foreach ( $row['suggested'] as $s ) {
				$tid = (int) ( is_array( $s ) ? ( $s['term_id'] ?? 0 ) : 0 );
				if ( $tid > 0 ) {
					$allowed[ $pid ][ $tid ] = true;
				}
			}
		}
	}

	foreach ( $assign as $pid => $term_ids ) {
		$pid = (int) $pid;
		if ( $pid <= 0 || empty( $allowed[ $pid ] ) ) {
			continue; // never suggested for this post.
		}
		if ( 'post' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			continue; // not an editable Note for this user.
		}
		$ids = array();
		foreach ( (array) $term_ids as $tid ) {
			$tid = (int) $tid;
			if ( $tid > 0 && isset( $allowed[ $pid ][ $tid ] ) ) {
				$ids[ $tid ] = $tid; // intersect with the suggested set; dedupe.
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $pid, array_values( $ids ), 'post_tag', true );
		}
	}

	delete_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	return 'tag_ai_applied';
}

/**
 * Delete the selected unused (count-0) tags. Reads sn_tag_unused[] = term_id.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_prune_unused( $post ) {
	$ids = isset( $post['sn_tag_unused'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $post['sn_tag_unused'] ) ) ) : array();
	if ( ! $ids || ! function_exists( 'sn_tag_delete_unused' ) ) {
		return 'tag_prune_error';
	}
	$res = sn_tag_delete_unused( $ids );
	return is_wp_error( $res ) ? 'tag_prune_error' : 'tag_pruned';
}

/**
 * v5.1.0: save the IndexNow enable toggle. Enabling mints a key on first use
 * (so /<key>.txt resolves immediately). The key lives in its own non-autoloaded
 * option; the toggle in sn_settings.indexnow.enabled.
 */
function sn_handle_indexnow_save( $post ) {
	$enabled = ! empty( $post['indexnow_enabled'] );
	sn_setting_update( 'indexnow.enabled', $enabled );
	if ( $enabled ) {
		sn_indexnow_ensure_key();
	}
	return 'indexnow_saved';
}

/** v5.1.0: regenerate the IndexNow key (invalidates the old /<key>.txt). */
function sn_handle_indexnow_regenerate( $post ) {
	sn_indexnow_regenerate_key();
	return 'indexnow_key_regenerated';
}

/**
 * v5.1.0: one-shot backfill — submit the most-recent published posts so
 * IndexNow learns about content that predates enabling. Bounded to 100.
 */
function sn_handle_indexnow_ping_now( $post ) {
	if ( ! sn_indexnow_is_enabled() || '' === sn_indexnow_get_key() ) {
		return 'indexnow_disabled';
	}
	$ids = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$urls = array_map( 'get_permalink', $ids );
	$urls[] = home_url( '/notes/' );
	sn_indexnow_enqueue( $urls );
	return 'indexnow_pinged';
}

/**
 * S2 (P2 analytics data layer): save the Cloudflare Analytics Engine credentials
 * from the Analytics settings form.
 *
 * Two fields:
 *   sn_cf_account_id       — plain identifier (not a secret), change-detected.
 *   sn_cf_analytics_token  — secret token; masked field; a '••••…' placeholder
 *                             means "no edit" and is silently skipped so the stored
 *                             value is never clobbered by the placeholder text.
 *
 * Both are constant-lockable: when SN_CF_ANALYTICS_TOKEN AND SN_CF_ACCOUNT_ID are
 * both defined and non-empty in wp-config.php, admin edits are rejected entirely.
 * When only one is locked, that field is skipped and the other may still be saved.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_saved' | 'analytics_unchanged' | 'analytics_locked'.
 */
function sn_handle_analytics_save( $post ) {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	if ( $token_locked && $acct_locked ) {
		return 'analytics_locked';
	}

	$changed = false;

	// Account ID — identifier, not a secret: plain text, change-detected.
	if ( ! $acct_locked && isset( $post['sn_cf_account_id'] ) ) {
		$acct = sanitize_text_field( wp_unslash( $post['sn_cf_account_id'] ) );
		if ( 'clear' === $acct ) {
			if ( '' !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
				delete_option( SN_CF_ACCOUNT_ID_OPT );
				$changed = true;
			}
		} elseif ( '' !== $acct && $acct !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
			update_option( SN_CF_ACCOUNT_ID_OPT, $acct, false );
			$changed = true;
		}
	}

	// Token — secret: masked field, ignore an un-edited '••••…' placeholder.
	if ( ! $token_locked ) {
		$new_token = isset( $post['sn_cf_analytics_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_analytics_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			if ( '' !== (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' ) ) {
				delete_option( SN_CF_ANALYTICS_TOKEN_OPT );
				$changed = true;
			}
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( SN_CF_ANALYTICS_TOKEN_OPT, $new_token, false );
			$changed = true;
		}
	}

	return $changed ? 'analytics_saved' : 'analytics_unchanged';
}

/**
 * R6b: choose which Search Console property to read.
 *
 * Validated against what the credential can ACTUALLY see rather than accepted
 * as typed — a property string that looks right but was never granted fails
 * later as a 403, at a distance from the mistake.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_gsc_property_save( $post ) {
	$want = isset( $post['sn_gsc_property'] ) ? trim( (string) wp_unslash( $post['sn_gsc_property'] ) ) : '';
	if ( '' === $want ) {
		return 'gsc_property_unchanged';
	}
	$sites = snt_gsc_list_sites();
	if ( is_wp_error( $sites ) ) {
		return 'gsc_test_failed';
	}
	$allowed = wp_list_pluck( $sites, 'siteUrl' );
	if ( ! in_array( $want, $allowed, true ) ) {
		return 'gsc_property_unknown';
	}
	sn_setting_update( 'search_console.property', $want );
	return 'gsc_property_saved';
}

/**
 * R6b: fetch the current window and store it.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_gsc_sync( $post ) {
	unset( $post );
	$res = snt_gsc_sync();
	if ( is_wp_error( $res ) ) {
		set_transient( 'snt_gsc_last_test', array(
			'ok'    => false,
			'code'  => $res->get_error_code(),
			'error' => $res->get_error_message(),
			'when'  => time(),
		), 10 * MINUTE_IN_SECONDS );
		return 'gsc_sync_failed';
	}
	return 'gsc_sync_ok';
}

/**
 * R6b: exercise the stored credential end to end and cache what it can read.
 *
 * Deliberately mints a FRESH token (force=true) rather than reusing a cached
 * one: the button's whole job is to answer "does this credential work RIGHT
 * NOW", and a cached token would answer "did it work up to an hour ago".
 *
 * The result is stashed in a transient so the render can show it once after the
 * redirect. A flash code alone cannot carry a list of properties.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_gsc_test( $post ) {
	unset( $post );
	if ( ! snt_gsc_credential_is_configured() ) {
		return 'gsc_test_not_configured';
	}
	$sites = snt_gsc_list_sites( true );
	if ( is_wp_error( $sites ) ) {
		set_transient( 'snt_gsc_last_test', array(
			'ok'    => false,
			'code'  => $sites->get_error_code(),
			'error' => $sites->get_error_message(),
			'when'  => time(),
		), 10 * MINUTE_IN_SECONDS );
		return 'gsc_test_failed';
	}
	set_transient( 'snt_gsc_last_test', array(
		'ok'    => true,
		'sites' => $sites,
		'when'  => time(),
	), 10 * MINUTE_IN_SECONDS );
	return empty( $sites ) ? 'gsc_test_no_properties' : 'gsc_test_ok';
}

/**
 * R6b: save the Google Search Console service-account credential.
 *
 * The textarea is ALWAYS submitted empty unless the owner pasted something, so
 * an empty value means "leave the stored credential alone" — never "clear it".
 * Removal is the explicit `clear` sentinel the analytics token already uses.
 *
 * A paste that fails validation is REFUSED and the stored value is untouched.
 * Storing an unusable credential would trade a clear error at the moment of the
 * mistake for an opaque token failure later, with the screen still showing
 * "configured".
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_gsc_credential_save( $post ) {
	if ( ! isset( $post['sn_gsc_credential'] ) ) {
		return 'gsc_credential_unchanged';
	}
	// NOT sanitize_textarea_field(): it strips and re-encodes, and a PEM block
	// plus JSON escaping must survive byte-exact or the key stops parsing.
	$raw = trim( (string) wp_unslash( $post['sn_gsc_credential'] ) );

	if ( '' === $raw ) {
		return 'gsc_credential_unchanged';
	}
	if ( 'clear' === strtolower( $raw ) ) {
		if ( '' === snt_gsc_credential_raw() ) {
			return 'gsc_credential_unchanged';
		}
		sn_setting_update( SNT_GSC_CREDENTIAL_PATH, '' );
		return 'gsc_credential_cleared';
	}
	$check = snt_gsc_credential_validate( $raw );
	if ( ! $check['ok'] ) {
		// Distinct codes for the two mistakes that change what the owner does
		// next; everything else (a missing field, a mangled PEM) collapses into
		// one message, because the fix is the same: re-download and re-paste.
		// A flash code is all that survives the redirect, so the reason has to
		// BE the code — a global would be gone by the time the notice renders.
		if ( 'not_json' === $check['error'] ) {
			return 'gsc_credential_not_json';
		}
		if ( 'not_service_account' === $check['error'] ) {
			return 'gsc_credential_not_service_account';
		}
		return 'gsc_credential_rejected';
	}
	if ( $raw === snt_gsc_credential_raw() ) {
		return 'gsc_credential_unchanged';
	}
	sn_setting_update( SNT_GSC_CREDENTIAL_PATH, $raw );
	return 'gsc_credential_saved';
}

/**
 * v9.85.0 (Session 3): save the Machine Readers sensor settings (worker URL
 * override + write-only read token) under the machine_readers subtree. The
 * pure, subtree-preserving merge lives in snt_mr_settings_save()
 * (inc/machine-readers-admin.php); this wrapper owns unslash/sanitize,
 * persistence, and the sn_setting cache bust, and drops the tab's display
 * transient so new credentials take effect on the next page load.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'machine_readers_saved'.
 */
function sn_handle_machine_readers_save( $post ) {
	$fields = array(
		'worker_url' => isset( $post['sn_mr_worker_url'] ) ? sanitize_text_field( wp_unslash( $post['sn_mr_worker_url'] ) ) : '',
		'read_token' => isset( $post['sn_mr_read_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_mr_read_token'] ) ) : '',
	);
	$stored = get_option( SN_SETTINGS_OPTION, array() );
	update_option( SN_SETTINGS_OPTION, snt_mr_settings_save( $fields, is_array( $stored ) ? $stored : array() ) );
	sn_setting_reset_cache();
	// The tab's display window; other windows age out on their own short TTL.
	delete_transient( 'sn_mr_rows_30' );
	return 'machine_readers_saved';
}

/**
 * S2 (P2 analytics data layer): test the Cloudflare Analytics Engine credentials
 * via a lightweight probe query (admin "Test connection" button).
 *
 * Dispatches through the sn_analytics_config() / sn_analytics_probe() seam so
 * both functions are replaceable in unit tests without network access.
 *
 * @param array $post Raw $_POST (unused; kept for dispatcher contract).
 * @return string Flash code: 'analytics_test_unconfigured' | 'analytics_test_ok' | 'analytics_test_err'.
 */
function sn_handle_analytics_test( $post ) {
	if ( ! sn_analytics_config() ) {
		return 'analytics_test_unconfigured';
	}
	delete_transient( SN_ANALYTICS_ERR_KEY ); // force-fresh: show THIS test's result, not a stale failure
	return sn_analytics_probe() ? 'analytics_test_ok' : 'analytics_test_err';
}

/**
 * v6.23.0: save the "Exclude my own visits" role allow-list (Monitoring →
 * Analytics). Sanitizes the submitted role slugs against the real role list
 * (sn_beacon_sanitize_exclude_roles) and persists them to the analytics subtree.
 * The theme's sn_beacon_enabled filter (inc/beacon-owner-exclusion.php) reads
 * this to suppress the front-end beacon for logged-in users in those roles.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_exclude_saved' | 'analytics_exclude_unchanged'.
 */
function sn_handle_analytics_exclude_save( $post ) {
	$raw = isset( $post['sn_exclude_roles'] ) ? wp_unslash( $post['sn_exclude_roles'] ) : array();
	$new = sn_beacon_sanitize_exclude_roles( $raw );
	sort( $new );

	$prior = (array) sn_setting( 'analytics.exclude_roles', array() );
	sort( $prior );

	if ( $new === $prior ) {
		return 'analytics_exclude_unchanged';
	}
	return sn_setting_update( 'analytics.exclude_roles', $new ) ? 'analytics_exclude_saved' : 'analytics_exclude_unchanged';
}

/**
 * v9.36.0 (settings hub): save the two predictive-engine tuning knobs
 * (Measurement → Analytics → Engine tuning). Baseline is clamped to [14,90]
 * (floor = the engine's SN_ANALYTICS_SIGNAL_FLOOR_DAYS); the sensitivity
 * preset is whitelisted (unknown → 'standard'). Invalid input is corrected,
 * never rejected-with-loss. sn_analytics_signal_opts() reads these on the
 * next dashboard load — no cache to bust.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_tuning_saved' | 'analytics_tuning_unchanged'.
 */
function sn_handle_analytics_tuning_save( $post ) {
	$baseline = isset( $post['sn_signal_baseline_days'] ) ? (int) $post['sn_signal_baseline_days'] : 30;
	$baseline = max( 14, min( 90, $baseline ) );

	$preset = isset( $post['sn_anomaly_sensitivity'] ) ? sanitize_key( wp_unslash( $post['sn_anomaly_sensitivity'] ) ) : 'standard';
	if ( ! in_array( $preset, array( 'relaxed', 'standard', 'strict' ), true ) ) {
		$preset = 'standard';
	}

	$prior_baseline = (int) sn_setting( 'analytics.signal_baseline_days', 30 );
	$prior_preset   = (string) sn_setting( 'analytics.anomaly_sensitivity', 'standard' );
	if ( $baseline === $prior_baseline && $preset === $prior_preset ) {
		return 'analytics_tuning_unchanged';
	}

	$ok = sn_setting_update( 'analytics.signal_baseline_days', $baseline );
	$ok = sn_setting_update( 'analytics.anomaly_sensitivity', $preset ) && $ok;
	return $ok ? 'analytics_tuning_saved' : 'analytics_tuning_unchanged';
}

/**
 * S2 §3 (v9.42.0 arc): save the owner-defined session funnels (Monitoring →
 * Analytics → Session funnels). No inline nonce check — sn_handle_admin_post()
 * (inc/admin-post-handler.php) already runs check_admin_referer() for every
 * action on this dispatcher before any handler is called, same as every other
 * handler in this file.
 *
 * Atomic: a parse error saves NOTHING (the prior analytics.funnels setting is
 * left exactly as it was) and returns an
 * 'analytics_funnels_invalid[_<line>k<kindIndex>[-<line>k<kindIndex>…]]' flash
 * — reason-surfacing task: $kindIndex is now packed in alongside each bad
 * line, mirroring the existing count/id-prefixed flash-code idiom (cleared_12,
 * wh_added_<id>, …) resolved in inc/admin-flash-messages.php. STILL no extra
 * transient plumbing (that was deliberately declined — see
 * sn_analytics_funnels_error_flash_code() below): the parser's structured
 * errors_detail already names both the line AND the machine-stable kind.
 *
 * STRING-SETTING RULE: WP core slashes all of $_POST (wp_magic_quotes()), so
 * the raw textarea payload is wp_unslash()ed BEFORE it reaches the parser —
 * apostrophes in funnel names are the exact recurring hazard (see
 * tests/settings-save-unslash.php / the v9.36.1 fix in sn_settings_save()).
 *
 * @since S2 (v9.42.0 arc); pair-encoded flash code (reason-surfacing task).
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_funnels_saved' | 'analytics_funnels_invalid[_<line>k<kindIndex>[-…]]' | 'analytics_funnels_failed'.
 */
function sn_handle_analytics_funnels_save( $post ) {
	// is_string guard: a crafted sn_funnels[]= array would warn on the string
	// cast (final review); non-string payloads parse as empty → error flash.
	$raw    = isset( $post['sn_funnels'] ) && is_string( $post['sn_funnels'] ) ? wp_unslash( $post['sn_funnels'] ) : '';
	$parsed = sn_analytics_parse_funnels( (string) $raw );

	if ( ! empty( $parsed['errors'] ) ) {
		return sn_analytics_funnels_error_flash_code( $parsed['errors_detail'] );
	}

	$ok = sn_setting_update( 'analytics.funnels', $parsed['funnels'] );
	return $ok ? 'analytics_funnels_saved' : 'analytics_funnels_failed';
}

/**
 * Encode the parser's structured error detail (reason-surfacing task) into
 * the 'analytics_funnels_invalid[_<line>k<kindIndex>[-<line>k<kindIndex>…]]'
 * flash code inc/admin-flash-messages.php decodes back into per-line reason
 * text.
 *
 * $kindIndex is the entry's position in SN_ANALYTICS_FUNNELS_ERR_KINDS
 * (inc/analytics-sessions.php) — NEVER the reason string itself and NEVER
 * anything derived from the owner's textarea content — so nothing beyond
 * digits (plus the fixed 'k'/'-' separators) can ever reach the redirect URL.
 * A detail entry with an out-of-enum kind or a non-positive line (never
 * produced by the real parser — the enum is closed and lines are always
 * >= 1 — but defensive against any other caller) is silently skipped rather
 * than encoded as-is.
 *
 * SOURCE cap (final review, carried over unchanged from the pre-reason-
 * surfacing code): first FIVE bad lines only — an uncapped code from a huge
 * paste can blow the redirect URL past server limits (414).
 *
 * Worst-case length: 5 pairs of "<line up to 4 digits>k<kind 1 digit>"
 * ("9999k5", 6 chars) joined by 4 '-' separators = 5*6 + 4 = 34 chars —
 * comfortably inside the 40-char cap inc/admin-flash-messages.php enforces on
 * decode (unchanged from the pre-reason-surfacing display-truncation constant).
 *
 * @since (reason-surfacing task)
 * @param array $errors_detail List of array{line:int,kind:string,message:string}.
 * @return string
 */
function sn_analytics_funnels_error_flash_code( array $errors_detail ) {
	$kinds = defined( 'SN_ANALYTICS_FUNNELS_ERR_KINDS' ) ? SN_ANALYTICS_FUNNELS_ERR_KINDS : array();
	$pairs = array();
	foreach ( array_slice( $errors_detail, 0, 5 ) as $error ) {
		$line       = isset( $error['line'] ) ? (int) $error['line'] : 0;
		$kind_index = array_search( (string) ( $error['kind'] ?? '' ), $kinds, true );
		if ( $line < 1 || false === $kind_index ) {
			continue; // never emit a malformed pair — the enum is closed, this should not happen.
		}
		$pairs[] = $line . 'k' . $kind_index;
	}
	return $pairs ? ( 'analytics_funnels_invalid_' . implode( '-', $pairs ) ) : 'analytics_funnels_invalid';
}

/**
 * v6.1.0: stream a CSV or JSON download of the current analytics range/class.
 *
 * This handler intentionally does NOT return a flash code — it streams a file
 * download and calls exit(), so the dispatcher's PRG redirect never runs.
 *
 * Load-order note: inc/analytics-read.php (sn_analytics_top_paths) and
 * inc/analytics-admin.php (snt_analytics_resolve_range / snt_analytics_resolve_class /
 * snt_analytics_range_dates) are both loaded unconditionally via require_once in
 * signal-and-noise-tools.php before any WordPress hook fires, so they are always
 * available at admin_init. inc/analytics-export.php (the formatters) is a new
 * file not yet in the bootstrap — require_once it here on first use.
 *
 * @param array $post Raw $_POST.
 * @return void (exits after streaming the download)
 */
function sn_handle_analytics_export( $post ) {
	if ( ! function_exists( 'sn_analytics_export_csv' ) ) {
		require_once __DIR__ . '/analytics-export.php';
	}

	$range_raw = isset( $post['sn_range'] ) ? sanitize_text_field( wp_unslash( $post['sn_range'] ) ) : '30';
	$from_raw  = isset( $post['sn_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_from'] ) ) : '';
	$to_raw    = isset( $post['sn_to'] ) ? sanitize_text_field( wp_unslash( $post['sn_to'] ) ) : '';
	$class     = isset( $post['sn_class'] ) ? snt_analytics_resolve_class( sanitize_text_field( wp_unslash( $post['sn_class'] ) ) ) : 'human';
	$fmt       = ( isset( $post['format'] ) && 'json' === $post['format'] ) ? 'json' : 'csv';
	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	$rows  = sn_analytics_top_paths( $from, $to, $class, 500 );
	$fname = 'sn-analytics-' . $from . '_' . $to . '-' . $class . '.' . $fmt;

	if ( 'json' === $fmt ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_json( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	} else {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	}
	exit;
}

// v9.0.0 (D1): sn_handle_analytics_import() (the Plausible-CSV upload handler) was
// removed with the rest of the importer. The analytics_export handler above stays —
// export is a live first-party feature, unrelated to the retired Plausible path.

/**
 * R9 (v9.51.0, lane SEC-C): bind (or unbind) the MCP write-door credential
 * from the Tools → MCP leaf's binding form
 * (inc/admin-forms/mcp-connect.php's sn_admin_render_mcp_rw_binding()).
 *
 * sn_handle_admin_post() (inc/admin-post-handler.php) already ran
 * check_admin_referer() + current_user_can('manage_options') before this
 * handler is ever dispatched — the capability check below is a defensive
 * re-verification (the same "never trust the dispatcher alone" posture every
 * other security-sensitive handler in this file takes for its own per-
 * resource check, e.g. sn_handle_tag_ai_apply()'s edit_post gate), reachable
 * in practice only when this function is called directly (as the unit tests
 * do) rather than through the real POST dispatch path.
 *
 * OWNERSHIP CHECK (the load-bearing part of this handler): manage_options
 * says nothing about which Application Password the submitted UUID names —
 * that value is fully attacker-controlled POST input. Binding it without
 * verifying it belongs to the CURRENT user's own Application Passwords would
 * let anyone who can reach this form point the write door's R1 credential
 * check (inc/mcp/mcp-rw-guard.php) at a UUID for a DIFFERENT application
 * password entirely — an unrelated credential the submitting admin may not
 * even hold. WP_Application_Passwords::get_user_application_passwords() is
 * itself scoped to one user id, so this loop can only ever match a password
 * that already belongs to get_current_user_id(); nothing here trusts the
 * $_POST value beyond that membership test.
 *
 * '' (the form's "— Unbind —" option) always succeeds without an ownership
 * check — an empty string is a legal, explicit clear per
 * sn_mcp_set_rw_bound_uuid()'s own contract, and there is no owner to verify
 * for "nothing bound".
 *
 * is_string guard (project convention — a crafted sn_mcp_rw_uuid[]= array
 * POST would otherwise warn on the string cast): non-string payloads are
 * treated as absent, not cast.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'mcp_rw_bound' | 'mcp_rw_unbound' | 'mcp_rw_bind_invalid'.
 */
function sn_handle_bind_mcp_rw_credential( $post ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'mcp_rw_bind_invalid';
	}
	if ( ! function_exists( 'sn_mcp_set_rw_bound_uuid' ) ) {
		return 'mcp_rw_bind_invalid';
	}

	$raw  = isset( $post['sn_mcp_rw_uuid'] ) && is_string( $post['sn_mcp_rw_uuid'] ) ? $post['sn_mcp_rw_uuid'] : '';
	$uuid = trim( sanitize_text_field( wp_unslash( $raw ) ) );

	if ( '' === $uuid ) {
		return sn_mcp_set_rw_bound_uuid( '' ) ? 'mcp_rw_unbound' : 'mcp_rw_bind_invalid';
	}

	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return 'mcp_rw_bind_invalid';
	}
	$owned = false;
	foreach ( (array) WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $pw ) {
		if ( is_array( $pw ) && ! empty( $pw['uuid'] ) && hash_equals( (string) $pw['uuid'], $uuid ) ) {
			$owned = true;
			break;
		}
	}
	if ( ! $owned ) {
		return 'mcp_rw_bind_invalid'; // never bind a UUID this user doesn't hold.
	}

	return sn_mcp_set_rw_bound_uuid( $uuid ) ? 'mcp_rw_bound' : 'mcp_rw_bind_invalid';
}

/**
 * Toggle the remote analytics door (R3 §3D).
 *
 * THE PHONE-REACHABLE CONTROL. sn_mcp_remote_enabled is absent by default and
 * fails CLOSED, so without this handler the door needs WP-CLI to turn on and
 * WP-CLI to turn off — a terminal in both directions. The "off" half is the one
 * that matters at 2am away from a laptop.
 *
 * SN_MCP_REMOTE_DISABLED WINS UNCONDITIONALLY. A wp-config kill that a web form
 * could undo would be decorative. Same shape as sn_handle_cf_save() refusing to
 * override SN_CLOUDFLARE_API_TOKEN.
 *
 * The secret itself has no UI here, deliberately: an option is readable by
 * anything that reaches the database, while wp-config.php is readable by no web
 * request. Stopping the door is urgent and belongs on the web; rotating the
 * secret is rare and belongs on a laptop.
 *
 * @param array $post Raw $_POST.
 * @return string Flash key.
 */
function sn_handle_remote_toggle( $post ) {
	if ( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ) {
		return 'remote_constant_locked';
	}
	$on = ! empty( $post['sn_remote_enabled'] );
	update_option( 'sn_mcp_remote_enabled', $on, false );
	return $on ? 'remote_enabled' : 'remote_disabled';
}
