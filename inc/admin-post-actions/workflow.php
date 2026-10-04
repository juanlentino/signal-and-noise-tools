<?php
/**
 * Signal & Noise: admin POST handler for Content > Workflow (/workflow).
 *
 * Both editors (the classic form, inc/admin-forms/workflow-page.php, and the
 * native leaf, apps/sn-dashboard/parts/leaves/content-workflow.php) post the
 * same `workflow[...]` fields with action sn_workflow_save.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * workflow_save: normalize the posted document, store it, regenerate the
 * Page from its PUBLIC view, purge /workflow when the live page changed.
 *
 * @param array $post Raw $_POST (slashed).
 * @return string Flash key.
 */
function sn_handle_workflow_save( $post ) {
	if ( ! function_exists( 'sn_workflow_page_save' ) || ! function_exists( 'sn_workflow_sync_page' ) ) {
		return 'workflow_failed';
	}
	$changed = sn_workflow_page_save( sn_workflow_normalize( $post['workflow'] ?? array() ) );
	$result  = sn_workflow_sync_page();
	if ( 'published' === $result || 'withdrawn' === $result ) {
		sn_content_route_purge( '/' . SN_WORKFLOW_SLUG );
	}
	if ( 'published' === $result ) {
		return $changed ? 'workflow_saved' : 'workflow_resynced';
	}
	if ( 'withdrawn' === $result ) {
		return 'workflow_withdrawn';
	}
	if ( 'offline' === $result ) {
		return 'workflow_offline';
	}
	return 'failed' === $result ? 'workflow_failed' : 'workflow_nothing';
}
