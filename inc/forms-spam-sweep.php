<?php
/**
 * The forms spam sweep: read what the rules would catch among entries
 * already in the inbox, and each form's own defences; then, on a separate
 * write, mark the listed entries spam (through Forms' own status setter, so
 * "Not spam" undoes it) and switch on the free defences a form has off.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The defences switched on when asked: honeypot, a 3 s time trap, 5 an hour. */
const SNT_FS_DEFAULTS = array( 'honeypot' => true, 'timeTrap' => 3, 'rateLimit' => 5 );

/**
 * Is Forms here, with the functions the sweep relies on?
 *
 * @return bool
 */
function snt_fs_available() {
	return defined( 'ALLTFO_ENTRY_TYPE' ) && defined( 'ALLTFO_FORM_TYPE' ) && defined( 'ALLTFO_META_VALUES' ) && defined( 'ALLTFO_META_FORM' )
		&& defined( 'ALLTFO_STATUS_UNREAD' ) && defined( 'ALLTFO_STATUS_READ' ) && defined( 'ALLTFO_STATUS_SPAM' )
		&& function_exists( 'alltfo_get_form_schema' ) && function_exists( 'alltfo_set_entry_status' );
}

/**
 * Entries in the inbox (read or unread) the rules would mark spam, with each
 * form's spam settings.
 *
 * @return array
 */
function snt_fs_scan() {
	if ( ! snt_fs_available() ) {
		return array( 'available' => false, 'forms' => array(), 'flagged' => array(), 'scanned' => 0 );
	}
	$forms   = array();
	$schemas = array();
	foreach ( get_posts( array( 'post_type' => ALLTFO_FORM_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'no_found_rows' => true ) ) as $f ) {
		$schema              = (array) alltfo_get_form_schema( $f );
		$schemas[ $f->ID ]   = $schema;
		$spam                = (array) ( $schema['settings']['spam'] ?? array() );
		$forms[]             = array(
			'id'        => $f->ID,
			'title'     => $f->post_title,
			'honeypot'  => ! empty( $spam['honeypot'] ),
			'timeTrap'  => (int) ( $spam['timeTrap'] ?? 0 ),
			'rateLimit' => (int) ( $spam['rateLimit'] ?? 0 ),
			'blocklist' => '' !== trim( (string) ( $spam['blocklist'] ?? '' ) ),
			'rule_case' => snt_fs_fix_rule_case( $schema )['changes'],
		);
	}
	$ids     = get_posts( array( 'post_type' => ALLTFO_ENTRY_TYPE, 'post_status' => array( ALLTFO_STATUS_UNREAD, ALLTFO_STATUS_READ ), 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true ) );
	$flagged = array();
	foreach ( $ids as $id ) {
		$form   = (int) get_post_meta( $id, ALLTFO_META_FORM, true );
		$reason = snt_fs_reason( snt_fs_signals( snt_fs_entry_values( get_post_meta( $id, ALLTFO_META_VALUES, true ) ), $schemas[ $form ] ?? array() ) );
		if ( '' !== $reason ) {
			$flagged[] = array( 'id' => (int) $id, 'form' => $form, 'title' => get_the_title( $id ), 'date' => get_post_time( 'c', true, $id ), 'reason' => $reason );
		}
	}
	return array( 'available' => true, 'forms' => $forms, 'flagged' => $flagged, 'scanned' => count( $ids ), 'spam_folder' => snt_fs_spam_folder_check( $schemas ) );
}

/**
 * Report-only: of the entries already in Spam, which would the content
 * rules have caught on their own? The measure of the rules against real
 * spam; nothing here writes.
 *
 * @param array $schemas form id => schema.
 * @return array{total:int,caught:int,missed:array,caught_rows:array}
 */
function snt_fs_spam_folder_check( array $schemas ) {
	$ids    = get_posts( array( 'post_type' => ALLTFO_ENTRY_TYPE, 'post_status' => array( ALLTFO_STATUS_SPAM ), 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true ) );
	$caught = array();
	$missed = array();
	foreach ( $ids as $id ) {
		$form   = (int) get_post_meta( $id, ALLTFO_META_FORM, true );
		$reason = snt_fs_reason( snt_fs_signals( snt_fs_entry_values( get_post_meta( $id, ALLTFO_META_VALUES, true ) ), $schemas[ $form ] ?? array() ) );
		$row    = array( 'id' => (int) $id, 'title' => get_the_title( $id ), 'date' => get_post_time( 'c', true, $id ) );
		if ( '' === $reason ) {
			$missed[] = $row;
		} else {
			$caught[] = $row + array( 'reason' => $reason );
		}
	}
	return array( 'total' => count( $ids ), 'caught' => count( $caught ), 'missed' => $missed, 'caught_rows' => $caught );
}

/**
 * Mark the given entries spam, but only those the rules flag right now (an
 * id the scan would not list is refused, never trusted), and optionally
 * switch on the defaults each form has off.
 *
 * @param array $input {entry_ids:int[], enable_defaults:bool}.
 * @return array
 */
function snt_fs_apply( $input ) {
	if ( ! snt_fs_available() ) {
		return array( 'ok' => false, 'error' => 'forms_unavailable' );
	}
	$scan    = snt_fs_scan();
	$allowed = array_column( $scan['flagged'], 'id' );
	$marked  = array();
	$refused = array();
	foreach ( array_map( 'intval', (array) ( $input['entry_ids'] ?? array() ) ) as $id ) {
		if ( ! in_array( $id, $allowed, true ) ) {
			$refused[] = $id;
			continue;
		}
		$res = alltfo_set_entry_status( $id, ALLTFO_STATUS_SPAM );
		is_wp_error( $res ) ? $refused[] = $id : $marked[] = $id;
	}
	$forms = array();
	if ( ! empty( $input['enable_defaults'] ) && function_exists( 'alltfo_save_form_schema' ) ) {
		foreach ( $scan['forms'] as $f ) {
			$schema = (array) alltfo_get_form_schema( get_post( $f['id'] ) );
			$spam   = (array) ( $schema['settings']['spam'] ?? array() );
			$next   = $spam;
			$next['honeypot']  = true;
			$next['timeTrap']  = max( (int) ( $spam['timeTrap'] ?? 0 ), SNT_FS_DEFAULTS['timeTrap'] );
			$next['rateLimit'] = (int) ( $spam['rateLimit'] ?? 0 ) > 0 ? (int) $spam['rateLimit'] : SNT_FS_DEFAULTS['rateLimit'];
			if ( $next !== $spam ) {
				$schema['settings']['spam'] = $next;
				$saved                      = alltfo_save_form_schema( $f['id'], $schema );
				$forms[]                    = array( 'id' => $f['id'], 'saved' => ! is_wp_error( $saved ) && false !== $saved );
			}
		}
	}
	$rule_fixes = array();
	if ( ! empty( $input['fix_rule_case'] ) && function_exists( 'alltfo_save_form_schema' ) ) {
		foreach ( $scan['forms'] as $f ) {
			$res = snt_fs_fix_rule_case( (array) alltfo_get_form_schema( get_post( $f['id'] ) ) );
			if ( $res['changes'] ) {
				alltfo_save_form_schema( $f['id'], $res['schema'] );
				$after        = snt_fs_fix_rule_case( (array) alltfo_get_form_schema( get_post( $f['id'] ) ) );
				$rule_fixes[] = array( 'id' => $f['id'], 'changes' => $res['changes'], 'verified' => array() === $after['changes'] );
			}
		}
	}
	return array( 'ok' => true, 'marked' => $marked, 'refused' => $refused, 'forms_updated' => $forms, 'rule_case_fixed' => $rule_fixes );
}

require_once __DIR__ . '/forms-rule-case.php';
require_once __DIR__ . '/abilities-forms-spam.php';
