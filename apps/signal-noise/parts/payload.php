<?php
/**
 * Signal & Noise app — the data payload, one function.
 *
 * What the client view renders from, computed after every server action:
 * the sections the current user may use (with counts, for the root's folder
 * tiles) and, inside a section, every item it holds with its dossier
 * inline -- so opening an item is a local action, never a round trip. The
 * payload is bounded by SN_OS_APP_ITEM_CAP per section.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App\Os;
use OpenStation\App\State;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param State $state Session state.
 * @param Os    $os    Host.
 * @return array<string,mixed>
 */
function payload( State $state, Os $os ) {
	$sections = \snt_os_app_sections();
	$wanted   = (string) $state->get( 'section' );
	$current  = null;
	$out      = array(
		'siteName' => function_exists( 'get_bloginfo' ) ? html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
		'sections' => array(),
		'section'  => null,
		'items'    => array(),
		'cap'      => (int) SN_OS_APP_ITEM_CAP,
		'verdict'  => (array) $state->get( 'verdict', array() ),
	);
	foreach ( $sections as $section ) {
		$is_current = (string) $section['id'] === $wanted;
		$items      = null;
		if ( $is_current || empty( $section['count'] ) || ! is_callable( $section['count'] ) ) {
			$items = array_values( (array) call_user_func( $section['items'] ) );
		}
		$count = null !== $items ? count( $items ) : (int) call_user_func( $section['count'] );

		$out['sections'][] = array(
			'id'    => (string) $section['id'],
			'label' => (string) $section['label'],
			'icon'  => (string) ( $section['icon'] ?? 'dashicons-portfolio' ),
			'kind'  => (string) ( $section['kind'] ?? 'entry' ),
			'count' => $count,
		);
		if ( $is_current ) {
			$current = $section;
			$out['items'] = array_slice( $items, 0, (int) SN_OS_APP_ITEM_CAP );
		}
	}
	if ( $current ) {
		$statuses = array();
		foreach ( (array) ( $current['statuses'] ?? array() ) as $s ) {
			if ( is_array( $s ) && isset( $s['value'], $s['label'] ) ) {
				$statuses[] = array( 'value' => (string) $s['value'], 'label' => (string) $s['label'] );
			}
		}
		$out['section'] = array(
			'id'            => (string) $current['id'],
			'label'         => (string) $current['label'],
			'icon'          => (string) ( $current['icon'] ?? 'dashicons-portfolio' ),
			'kind'          => (string) ( $current['kind'] ?? 'entry' ),
			'statuses'      => $statuses,
			'defaultStatus' => (string) ( $current['default_status'] ?? '' ),
			'canEdit'       => ! empty( $current['edit_url'] ),
			'columns'       => array_values( (array) ( $current['columns'] ?? array() ) ),
		);
	}
	return $out;
}
