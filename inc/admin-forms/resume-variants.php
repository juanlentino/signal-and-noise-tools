<?php
/**
 * Signal & Noise Tools: the résumé editor's variant controls (classic form;
 * the native twin is apps/sn-dashboard/parts/leaves/content-resume-variants.php).
 *
 * Every résumé row gets "On /resume: Shown / Hidden / Shown, not on the music
 * PDF" (stored as '', 'music', 'operations' since the two lanes; the one
 * résumé, 2026-11-12, reads them as shown or hidden); a role
 * lists its saved bullets with the same choice; two sections hold the
 * operations résumé's settings and "What I've Built". The row helpers these
 * hook into are shared with the Workflow editor, so every control here renders
 * only for a `resume[...]` field name.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The choices, value => label. A stored 'operations' (from when the music PDF
 * was built, "Shown, not on the music PDF") shows as Shown and saves as Shown:
 * with no music PDF it meant the same (2026-11-12).
 */
const SN_RSM_VARIANT_CHOICES = array(
	''      => 'Shown',
	'music' => 'Hidden',
);

/**
 * Whether a field name belongs to the résumé (not the Workflow editor).
 *
 * @param string $name
 * @return bool
 */
function sn_rsm_is_resume( $name ) {
	return 0 === strpos( (string) $name, 'resume[' );
}

/**
 * "Shows in" for one row.
 *
 * @param string $name  Field name.
 * @param string $value '', 'music' or 'operations'.
 */
function sn_rsm_variant( $name, $value ) {
	if ( ! sn_rsm_is_resume( $name ) ) {
		return;
	}
	echo '<label class="sn-rsm-field sn-rsm-variant"><span class="sn-rsm-label">On /resume</span><select name="' . esc_attr( $name ) . '">';
	$value = 'music' === (string) $value ? 'music' : '';
	foreach ( SN_RSM_VARIANT_CHOICES as $v => $label ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $value, $v, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></label>';
}

/**
 * Per-bullet "Shows in" for a role's saved bullets. A bullet typed since the
 * last save shows in both until the draft is saved and it appears here; a
 * bullet whose text changes goes back to both.
 *
 * @param string $prefix  The role's field prefix.
 * @param array  $role    The role (title, bullets, bullet_variants).
 */
function sn_rsm_bullet_variants( $prefix, $role ) {
	$bullets = (array) ( $role['bullets'] ?? array() );
	if ( ! sn_rsm_is_resume( $prefix ) || ! $bullets ) {
		return;
	}
	$flags = (array) ( $role['bullet_variants'] ?? array() );
	$receipts = (array) ( $role['bullet_receipts'] ?? array() );
	echo '<details class="sn-rsm-bullet-variants"><summary>Each bullet: which résumé, and its receipt</summary>';
	echo '<p class="description">Editing a bullet\'s text resets its choice to both résumés and drops its receipt.</p>';
	foreach ( $bullets as $bullet ) {
		$key  = sn_resume_bullet_key( $bullet );
		$text = wp_strip_all_tags( (string) $bullet );
		sn_rsm_variant( $prefix . '[bullet_variants][' . $key . ']', (string) ( $flags[ $key ] ?? '' ) );
		echo '<p class="description">' . esc_html( mb_strlen( $text ) > 90 ? mb_substr( $text, 0, 90 ) . '…' : $text ) . '</p>';
		function_exists( 'sn_rsm_receipt' ) && sn_rsm_receipt( $prefix . '[bullet_receipts][' . $key . ']', (array) ( $receipts[ $key ] ?? array() ) );
	}
	echo '</details>';
}

/**
 * "What I've Built": an intro line and one card per project.
 *
 * @param array $doc The document being edited.
 */
function sn_admin_render_resume_built_section( $doc ) {
	$built = is_array( $doc['built'] ?? null ) ? $doc['built'] : array( 'intro' => '', 'items' => array() );
	$card  = static function ( $p, $item ) {
		echo '<div class="sn-rsm-row sn-rsm-card" data-rsm-row><div class="sn-rsm-card-head">';
		sn_rsm_input( $p . '[title]', (string) ( $item['title'] ?? '' ), 'Title', 'Project name' );
		sn_rsm_controls();
		echo '</div>';
		sn_rsm_input( $p . '[url]', (string) ( $item['url'] ?? '' ), 'Link', 'https://github.com/…' );
		echo '<label class="sn-rsm-field"><span class="sn-rsm-label">What it does: one or two sentences</span><textarea rows="2" class="large-text" name="' . esc_attr( $p . '[text]' ) . '">' . esc_textarea( (string) ( $item['text'] ?? '' ) ) . '</textarea></label>';
		sn_rsm_variant( $p . '[variant]', (string) ( $item['variant'] ?? '' ) );
		echo '</div>';
	};
	sn_rsm_section_open( "What I've Built", '/resume and its PDF show this section while "built" is in its Sections list (a blank list takes the music order, which has none).', count( (array) ( $built['items'] ?? array() ) ) );
	sn_rsm_input( 'resume[built][intro]', (string) ( $built['intro'] ?? '' ), 'Intro line', 'How these were built' );
	echo '<div class="sn-rsm-list" data-rsm-list="built">';
	foreach ( array_values( (array) ( $built['items'] ?? array() ) ) as $i => $item ) {
		$card( 'resume[built][items][' . $i . ']', $item );
	}
	echo '</div><template data-rsm-tpl="built" data-rsm-token="__B__">';
	$card( 'resume[built][items][__B__]', array() );
	echo '</template><button type="button" class="button sn-rsm-add" data-rsm-add="built">+ Add project</button></details>';
}

/**
 * The résumé settings (variants.operations). Blank fields fall back to the
 * document's pdf.* values, which no editor shows any more.
 *
 * @param array $doc The document being edited.
 */
function sn_admin_render_resume_operations_section( $doc ) {
	$o = (array) ( $doc['variants']['operations'] ?? array() );
	sn_rsm_section_open( 'Résumé settings', 'What /resume shows, and its PDF, built by Generate: /resume\'s Download links this PDF once it is generated. Each row\'s "On /resume" decides whether it shows: Shown or Hidden.' );
	sn_rsm_input( 'resume[variants][operations][kicker]', (string) ( $o['kicker'] ?? '' ), 'Kicker: a short line that introduces the headline', 'Founder & Operator · Music, Rights & AI' );
	sn_rsm_input( 'resume[variants][operations][headline]', (string) ( $o['headline'] ?? '' ), 'Headline', 'Founder and Operator' );
	sn_rsm_input( 'resume[variants][operations][tagline]', (string) ( $o['tagline'] ?? '' ), 'Subhead', 'AI-Native Business Operations | Partnerships' );
	sn_rsm_input( 'resume[variants][operations][location]', (string) ( $o['location'] ?? '' ), 'Location', 'Orlando, FL (open to relocation)' );
	sn_rsm_lines( 'resume[variants][operations][links]', (array) ( $o['links'] ?? array() ), 'Extra contact links: one per line, after the website', 'https://github.com/…', 2 );
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Summary</span><textarea rows="4" class="large-text" name="resume[variants][operations][summary]">' . esc_textarea( (string) ( $o['summary'] ?? '' ) ) . '</textarea></label>';
	sn_rsm_lines( 'resume[variants][operations][sections]', (array) ( $o['sections'] ?? array() ), 'Sections, in order: one per line (' . implode( ', ', SN_RESUME_PDF_SECTION_KEYS ) . ')', 'summary', 6 );
	echo '<p class="description">A section missing from the list is missing from /resume and its PDF: leave out "stats" and "competencies" for no key-figures row and no competencies grid.</p>';
	sn_rsm_lines( 'resume[variants][operations][experience_order]', (array) ( $o['experience_order'] ?? array() ), 'Experience order: one organization per line (the rest follow as listed)', 'Independent Practice', 3 );
	echo '<label class="sn-rsm-field"><span class="sn-rsm-label">Earlier career on /resume: one line per role, no bullets (the PDF always is)</span><input type="checkbox" name="resume[variants][operations][earlier_compact]" value="1"' . checked( ! empty( $o['earlier_compact'] ), true, false ) . '></label>';
	sn_rsm_input( 'resume[variants][operations][pdf_file]', (string) ( $o['pdf_file'] ?? '' ), 'PDF file name', defined( 'SN_RESUME_OPERATIONS_PDF_FILE' ) ? SN_RESUME_OPERATIONS_PDF_FILE : 'Resume_Operations.pdf' );
	echo '<p class="description">The phone is never printed in this PDF.</p>';
	echo '</details>';
}
