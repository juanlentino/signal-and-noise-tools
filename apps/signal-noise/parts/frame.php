<?php
/**
 * Signal & Noise app — the shared frame.
 *
 * Paints any section the same way: a toolbar (search, count), a list of rows
 * on the left, the selected item's dossier on the right, a pager. A section
 * never writes markup for the list; it hands the frame data (see the
 * contract in inc/openstation-app.php) and only a dossier's `blocks` carry
 * section-authored HTML, already escaped by the section.
 *
 * Everything from a post, an option or a plugin goes through text(): decoded
 * once, escaped once, never printed as a literal entity.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App\Os;
use OpenStation\App\State;
use function OpenStation\App\Html\esc;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Escape an entity-bearing string for a text node or an attribute.
 *
 * @param mixed $value Anything stringable.
 * @return string
 */
function text( $value ) {
	return esc( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * A section descriptor by id, or null.
 *
 * @param string $id Section id.
 * @return array<string,mixed>|null
 */
function section_by_id( $id ) {
	foreach ( function_exists( 'snt_os_app_sections' ) ? \snt_os_app_sections() : array() as $section ) {
		if ( (string) $section['id'] === (string) $id ) {
			return $section;
		}
	}
	return null;
}

/**
 * The dossier a section builds for one item, or null.
 *
 * @param array<string,mixed> $section Descriptor.
 * @param string              $id      Item id.
 * @return array<string,mixed>|null
 */
function dossier_of( array $section, $id ) {
	if ( '' === (string) $id || empty( $section['dossier'] ) || ! is_callable( $section['dossier'] ) ) {
		return null;
	}
	$dossier = call_user_func( $section['dossier'], (string) $id );
	return is_array( $dossier ) ? $dossier : null;
}

/**
 * One <os-chip>.
 *
 * @param array{label:string,tone?:string} $chip Chip.
 * @return string
 */
function chip( array $chip ) {
	return '<os-chip size="compact" tone="' . esc( (string) ( $chip['tone'] ?? 'neutral' ) ) . '">' . text( $chip['label'] ?? '' ) . '</os-chip>';
}

/**
 * The whole body: the section switcher, then the active section's frame.
 *
 * Sections come from the registry NOW, gated on the user dispatching this
 * render -- see the note in signal-noise.os.php on why not at load time.
 *
 * @param State $state Session state.
 * @param Os    $os    Host.
 * @return string
 */
function app_view( State $state, Os $os ) {
	$sections = function_exists( 'snt_os_app_sections' ) ? \snt_os_app_sections() : array();
	if ( array() === $sections ) {
		return '<os-empty-state icon="dashicons-megaphone" heading="' . esc( __( 'Nothing to show', 'signal-and-noise-tools' ) ) . '" description="' . esc( __( 'No section is available to this account.', 'signal-and-noise-tools' ) ) . '"></os-empty-state>';
	}
	$wanted = (string) $state->get( 'section' );
	$active = null;
	foreach ( $sections as $section ) {
		if ( (string) $section['id'] === $wanted ) {
			$active = $section;
		}
	}
	if ( null === $active ) {
		$active = $sections[0];
	}
	$out = '';
	if ( count( $sections ) > 1 ) {
		$out .= '<div class="snt-os__tabs" role="tablist">';
		foreach ( $sections as $section ) {
			$is = $section['id'] === $active['id'];
			$out .= '<os-button role="tab" variant="' . ( $is ? 'primary' : 'ghost' ) . '" aria-selected="' . ( $is ? 'true' : 'false' ) . '" os-action="section" os-arg-to="' . esc( (string) $section['id'] ) . '"><os-icon icon="' . esc( (string) ( $section['icon'] ?? '' ) ) . '"></os-icon> ' . text( $section['label'] ) . '</os-button>';
		}
		$out .= '</div>';
	}
	return $out . frame_view( $active, $state, $os );
}

/**
 * The body for one section and a state.
 *
 * @param array<string,mixed> $section Descriptor.
 * @param State               $state   Session state.
 * @param Os                  $os      Host.
 * @return string
 */
function frame_view( array $section, State $state, Os $os ) {
	$page    = call_user_func( $section['rows'], $state, $os );
	$items   = is_array( $page['items'] ?? null ) ? $page['items'] : array();
	$total   = (int) ( $page['total'] ?? count( $items ) );
	$current = max( 1, (int) ( $page['page'] ?? 1 ) );
	$per     = max( 1, (int) ( $page['per_page'] ?? max( 1, count( $items ) ) ) );
	$pages   = max( 1, (int) ceil( $total / $per ) );
	$item_id = (string) $state->get( 'item' );
	$dossier = dossier_of( $section, $item_id );
	$sid     = esc( (string) $section['id'] );

	$out  = '<div class="snt-os" data-section="' . $sid . '"' . ( $dossier ? ' data-open="1"' : '' ) . '>';
	$out .= '<div class="snt-os__toolbar">';
	$out .= '<os-text-field class="snt-os__search" placeholder="' . esc( sprintf( /* translators: %s: section label. */ __( 'Search %s', 'signal-and-noise-tools' ), (string) $section['label'] ) ) . '" value="' . esc( (string) $state->get( 'query' ) ) . '" os-bind="query" os-action="search" os-debounce="300"></os-text-field>';
	$out .= '<span class="snt-os__count">' . esc( sprintf( /* translators: %s: a number. */ _n( '%s item', '%s items', $total, 'signal-and-noise-tools' ), number_format_i18n( $total ) ) ) . '</span>';
	$out .= '</div>';

	$out .= '<div class="snt-os__panes">';
	$out .= '<div class="snt-os__list" role="list">';
	if ( array() === $items ) {
		$empty = (array) ( $section['empty'] ?? array() );
		$out  .= '<os-empty-state icon="' . esc( (string) ( $section['icon'] ?? 'dashicons-megaphone' ) ) . '" heading="' . text( $empty['heading'] ?? __( 'Nothing here yet', 'signal-and-noise-tools' ) ) . '" description="' . text( $empty['description'] ?? '' ) . '"></os-empty-state>';
	}
	foreach ( $items as $item ) {
		$id      = (string) ( $item['id'] ?? '' );
		$is_open = '' !== $id && $id === $item_id;
		$out    .= '<button type="button" class="snt-os__row' . ( $is_open ? ' is-open' : '' ) . '" role="listitem" os-key="' . esc( $id ) . '" os-action="open" os-arg-item="' . esc( $id ) . '"' . ( $is_open ? ' aria-current="true"' : '' ) . '>';
		if ( ! empty( $item['thumbnail'] ) ) {
			$out .= '<img class="snt-os__thumb" src="' . esc( (string) $item['thumbnail'] ) . '" alt="" loading="lazy">';
		} else {
			$out .= '<os-icon class="snt-os__thumb snt-os__thumb--icon" icon="' . esc( (string) ( $item['icon'] ?? $section['icon'] ?? 'dashicons-media-default' ) ) . '"></os-icon>';
		}
		$out .= '<span class="snt-os__row-text"><span class="snt-os__title">' . text( $item['title'] ?? '' ) . '</span>';
		if ( ! empty( $item['subtitle'] ) ) {
			$out .= '<span class="snt-os__subtitle">' . text( $item['subtitle'] ) . '</span>';
		}
		$out .= '</span>';
		if ( ! empty( $item['chip'] ) && is_array( $item['chip'] ) ) {
			$out .= chip( $item['chip'] );
		} elseif ( ! empty( $item['meta'] ) ) {
			$out .= '<span class="snt-os__meta">' . text( $item['meta'] ) . '</span>';
		}
		$out .= '</button>';
	}
	$out .= '</div>';

	$out .= '<aside class="snt-os__dossier"' . ( $dossier ? '' : ' data-empty="1"' ) . '>';
	$out .= $dossier ? dossier_view( $section, $dossier, $item_id ) : '<os-empty-state icon="dashicons-visibility" heading="' . esc( __( 'Select an item', 'signal-and-noise-tools' ) ) . '" description="' . esc( __( 'Its details show here.', 'signal-and-noise-tools' ) ) . '"></os-empty-state>';
	$out .= '</aside>';
	$out .= '</div>';

	if ( $pages > 1 ) {
		$out .= '<div class="snt-os__pager">';
		$out .= '<os-button variant="ghost" os-action="page" os-arg-to="' . ( $current - 1 ) . '"' . ( $current <= 1 ? ' disabled' : '' ) . '>' . esc( __( 'Previous', 'signal-and-noise-tools' ) ) . '</os-button>';
		$out .= '<span class="snt-os__pager-meta">' . esc( sprintf( /* translators: 1: page, 2: pages. */ __( 'Page %1$s of %2$s', 'signal-and-noise-tools' ), number_format_i18n( $current ), number_format_i18n( $pages ) ) ) . '</span>';
		$out .= '<os-button variant="ghost" os-action="page" os-arg-to="' . ( $current + 1 ) . '"' . ( $current >= $pages ? ' disabled' : '' ) . '>' . esc( __( 'Next', 'signal-and-noise-tools' ) ) . '</os-button>';
		$out .= '</div>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * The dossier pane.
 *
 * @param array<string,mixed> $section Descriptor.
 * @param array<string,mixed> $dossier From the section.
 * @param string              $item_id Selected id.
 * @return string
 */
function dossier_view( array $section, array $dossier, $item_id ) {
	$out  = '<div class="snt-os__dossier-head">';
	$out .= '<os-button variant="ghost" class="snt-os__back" os-action="back">' . esc( __( 'Back', 'signal-and-noise-tools' ) ) . '</os-button>';
	if ( ! empty( $dossier['thumbnail'] ) ) {
		$out .= '<img class="snt-os__cover" src="' . esc( (string) $dossier['thumbnail'] ) . '" alt="">';
	}
	$out .= '<h2 class="snt-os__dossier-title">' . text( $dossier['title'] ?? '' ) . '</h2>';
	if ( ! empty( $dossier['subtitle'] ) ) {
		$out .= '<p class="snt-os__dossier-subtitle">' . text( $dossier['subtitle'] ) . '</p>';
	}
	if ( ! empty( $dossier['chips'] ) ) {
		$out .= '<os-cluster class="snt-os__chips">';
		foreach ( (array) $dossier['chips'] as $c ) {
			$out .= is_array( $c ) ? chip( $c ) : '';
		}
		$out .= '</os-cluster>';
	}
	$out .= '</div>';

	foreach ( (array) ( $dossier['blocks'] ?? array() ) as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$out .= '<section class="snt-os__block">';
		if ( ! empty( $block['heading'] ) ) {
			$out .= '<h3 class="snt-os__block-heading">' . text( $block['heading'] ) . '</h3>';
		}
		$out .= (string) ( $block['html'] ?? '' ); // Section-authored, section-escaped.
		$out .= '</section>';
	}

	$links = (array) ( $dossier['links'] ?? array() );
	$edit  = (array) ( $dossier['edit'] ?? array() );
	if ( $links || ! empty( $edit['url'] ) ) {
		$out .= '<os-cluster class="snt-os__actions">';
		if ( ! empty( $edit['url'] ) ) {
			$out .= '<os-button variant="primary" os-action="open-edit" os-arg-section="' . esc( (string) $section['id'] ) . '" os-arg-item="' . esc( $item_id ) . '">' . text( $edit['label'] ?? __( 'Edit', 'signal-and-noise-tools' ) ) . '</os-button>';
		}
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) ) {
				continue;
			}
			$out .= '<a class="snt-os__link" href="' . esc( (string) $link['url'] ) . '" target="_blank" rel="noopener">' . text( $link['label'] ?? $link['url'] ) . '</a>';
		}
		$out .= '</os-cluster>';
	}
	return $out;
}
