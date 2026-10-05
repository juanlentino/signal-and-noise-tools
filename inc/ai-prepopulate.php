<?php
/**
 * Signal & Noise Tools: the "auto-generated at publish" sentinels.
 *
 * Until 2026-10-05 this file also held the publish-time auto-fill: on a move
 * into published or scheduled, a cron event wrote an empty meta description,
 * excerpt and OG card title with no review step, flagging each with a
 * sentinel. That generator is removed (owner decision 2026-10-05), so every
 * model output now reaches a published field only through a human click: the
 * editor's Suggest and Apply buttons. What stays is the sentinel state, so a
 * field the old job already filled keeps its "unreviewed" notice until a
 * human saves or dismisses it. The notice and dismiss surface lives in
 * inc/ai-prepopulate-notice.php. When no sentinel is left on the site, this
 * file and the notice can go too.
 *
 * @package SignalNoiseTools
 * @since 4.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of sentinel meta key → human label for the notice. Single source of
 * truth for which fields the retired auto-fill flagged; consumed by the notice.
 *
 * @return array<string,string>
 */
function sn_prepop_fields() {
	return array(
		'_sn_autogen_meta_description' => 'meta description',
		'_sn_autogen_excerpt'          => 'excerpt',
		'_sn_autogen_og_card_title'    => 'OG card title',
	);
}

/**
 * Clear all prepop sentinels for a post (the notice shows once, then clears on
 * the next editor save or an explicit dismiss). Called from
 * sn_post_settings_save() and the dismiss REST route.
 *
 * @param int $post_id
 */
function sn_prepop_clear_sentinels( $post_id ) {
	foreach ( array_keys( sn_prepop_fields() ) as $sentinel ) {
		delete_post_meta( (int) $post_id, $sentinel );
	}
}
