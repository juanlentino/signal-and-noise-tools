<?php
/**
 * S&N Dashboard, Content > Resume Page: the résumé variant controls.
 *
 * The classic twin is inc/admin-forms/resume-variants.php. Every row gets
 * "Shows in" (both, music only, operations only); a role lists its saved
 * bullets with the same choice; "What I've Built" and "Operations résumé" are
 * two more sections; PDF only gains the music PDF's one switch; and the
 * operations setup is its own one-button form. Same names, labels and
 * placeholders as the classic, so the carriers (content-resume-edit.php) post
 * every one of them back when another section is edited.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * "Shows in" for one row, or one bullet when `$hint` names it.
 *
 * @param string $name  Field name.
 * @param mixed  $value '', 'music' or 'operations'.
 * @param string $hint  The bullet's text, '' for a row.
 * @return string
 */
function resume_variant( $name, $value, $hint = '' ) {
	return \snt_kit_field(
		'select',
		$name,
		__( 'Shows in', 'signal-and-noise-tools' ),
		is_scalar( $value ) ? (string) $value : '',
		array(
			'options' => array(
				''           => __( 'Both résumés', 'signal-and-noise-tools' ),
				'music'      => __( 'Music only', 'signal-and-noise-tools' ),
				'operations' => __( 'Operations only', 'signal-and-noise-tools' ),
			),
			'hint'    => '' !== $hint ? $hint : null,
		)
	);
}

/**
 * A role's saved bullets, each with its "Shows in", sn_rsm_bullet_variants().
 * A bullet typed since the last save shows in both until it is saved and
 * appears here; a bullet whose text changes goes back to both.
 *
 * @param string $prefix The role's field prefix.
 * @param array  $role   {bullets[], bullet_variants{}}.
 * @return string
 */
function resume_bullet_variants( $prefix, array $role ) {
	$bullets = (array) ( $role['bullets'] ?? array() );
	if ( ! $bullets ) {
		return '';
	}
	$flags    = (array) ( $role['bullet_variants'] ?? array() );
	$receipts = (array) ( $role['bullet_receipts'] ?? array() );
	$out      = '<p class="snt-prose">' . \snt_kit_esc( __( 'Each bullet: which résumé it shows in, and its receipt. Editing a bullet\'s text resets its choice to both résumés and drops its receipt.', 'signal-and-noise-tools' ) ) . '</p>';
	foreach ( $bullets as $bullet ) {
		$key  = \sn_resume_bullet_key( $bullet );
		$out .= resume_variant( $prefix . '[bullet_variants][' . $key . ']', $flags[ $key ] ?? '', resume_clip( \wp_strip_all_tags( (string) $bullet ), 90 ) )
			. resume_receipt( $prefix . '[bullet_receipts][' . $key . ']', (array) ( $receipts[ $key ] ?? array() ) );
	}
	return $out;
}

/**
 * A receipt's URL and type, sn_rsm_receipt() (inc/resume-receipts.php has the rules).
 *
 * @param string $prefix Field prefix (an entry, or a bullet's slot in bullet_receipts).
 * @param array  $row    {receipt_url?, receipt_type?}.
 * @return string
 */
function resume_receipt( $prefix, array $row ) {
	return resume_pair(
		resume_text( $prefix . '[receipt_url]', __( 'Receipt URL', 'signal-and-noise-tools' ), $row['receipt_url'] ?? '', 'https://…' ),
		\snt_kit_field(
			'select',
			$prefix . '[receipt_type]',
			__( 'Receipt type', 'signal-and-noise-tools' ),
			(string) ( $row['receipt_type'] ?? 'verification' ),
			array(
				'options' => array(
					'verification' => __( 'Verification (mark: verify)', 'signal-and-noise-tools' ),
					'publication'  => __( 'Publication (mark: paper)', 'signal-and-noise-tools' ),
					'repository'   => __( 'Repository (mark: code)', 'signal-and-noise-tools' ),
					'membership'   => __( 'Membership (mark: verify)', 'signal-and-noise-tools' ),
				),
			)
		)
	);
}

/** One "What I've Built" project. @param string $prefix @param array $item {title,url,text,variant?} @return string */
function resume_built_row( $prefix, array $item ) {
	return resume_card(
		$prefix,
		resume_text( $prefix . '[title]', __( 'Title', 'signal-and-noise-tools' ), $item['title'] ?? '', __( 'Project name', 'signal-and-noise-tools' ), 'title' )
		. resume_text( $prefix . '[url]', __( 'Link', 'signal-and-noise-tools' ), $item['url'] ?? '', 'https://github.com/…' )
		. \snt_kit_field( 'textarea', $prefix . '[text]', __( 'What it does: one or two sentences', 'signal-and-noise-tools' ), (string) ( $item['text'] ?? '' ), array( 'rows' => 2 ) )
		. resume_variant( $prefix . '[variant]', $item['variant'] ?? '' ),
		true
	);
}

/** "What I've Built": the intro line and one card per project. @param array $built {intro,items[]} @return string */
function resume_built( array $built ) {
	return resume_text( 'resume[built][intro]', __( 'Intro line', 'signal-and-noise-tools' ), $built['intro'] ?? '', __( 'How these were built', 'signal-and-noise-tools' ) )
		. resume_list( (array) ( $built['items'] ?? array() ), __NAMESPACE__ . '\resume_built_row', 'resume[built][items]', '__B__', __( '+ Add project', 'signal-and-noise-tools' ), __( 'project', 'signal-and-noise-tools' ), array(), 'title' );
}

/**
 * The operations résumé's settings. Blank fields fall back to the music PDF's.
 *
 * @param array $o The variants.operations block.
 * @return string
 */
function resume_operations( array $o ) {
	$p       = 'resume[variants][operations]';
	$missing = array_map( 'strval', (array) get_option( 'sn_resume_ops_setup_missing', array() ) );
	/* translators: %s: the rows the last setup did not find, semicolon-separated */
	$note = $missing ? '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'Not found by the last setup, so still in both résumés: %s.', 'signal-and-noise-tools' ), implode( '; ', $missing ) ) ) . '</p>' : '';
	return resume_text( $p . '[headline]', __( 'Headline', 'signal-and-noise-tools' ), $o['headline'] ?? '', 'Founder and Operator' )
		. resume_text( $p . '[tagline]', __( 'Subhead', 'signal-and-noise-tools' ), $o['tagline'] ?? '', 'AI-Native Business Operations | Partnerships' )
		. resume_text( $p . '[location]', __( 'Location', 'signal-and-noise-tools' ), $o['location'] ?? '', 'Orlando, FL (open to relocation)' )
		. resume_lines( $p . '[links]', $o['links'] ?? array(), __( 'Extra contact links: one per line, after the website', 'signal-and-noise-tools' ), 'https://github.com/…', 2 )
		. \snt_kit_field( 'textarea', $p . '[summary]', __( 'Summary', 'signal-and-noise-tools' ), (string) ( $o['summary'] ?? '' ), array( 'rows' => 4 ) )
		. resume_lines(
			$p . '[sections]',
			$o['sections'] ?? array(),
			/* translators: %s: the section keys, comma-separated */
			sprintf( __( 'Sections, in order: one per line (%s)', 'signal-and-noise-tools' ), implode( ', ', \SN_RESUME_PDF_SECTION_KEYS ) ),
			'summary',
			6,
			__( 'A section missing from the list is missing from the PDF: leave out "stats" and "competencies" for no key-figures row and no competencies grid.', 'signal-and-noise-tools' )
		)
		. resume_lines( $p . '[experience_order]', $o['experience_order'] ?? array(), __( 'Experience order: one organization per line (the rest follow as listed)', 'signal-and-noise-tools' ), 'Independent Practice', 3 )
		// A CHECKBOX, never os-switch (content-resume.php, the phone).
		. \snt_kit_field( 'checkbox', $p . '[earlier_compact]', __( 'Earlier career: one line per role, no bullets', 'signal-and-noise-tools' ), ! empty( $o['earlier_compact'] ), array( 'value' => '1' ) )
		. \snt_kit_field( 'text', $p . '[pdf_file]', __( 'PDF file name', 'signal-and-noise-tools' ), (string) ( $o['pdf_file'] ?? '' ), array( 'placeholder' => defined( 'SN_RESUME_OPERATIONS_PDF_FILE' ) ? \SN_RESUME_OPERATIONS_PDF_FILE : 'Resume_Operations.pdf', 'hint' => __( 'The phone is never printed in this PDF.', 'signal-and-noise-tools' ) ) )
		. $note;
}

/**
 * The music PDF's one variant setting, painted inside PDF only.
 *
 * @param bool $on Whether the music PDF shows "What I've Built".
 * @return string
 */
function resume_music_built_toggle( $on ) {
	return \snt_kit_field( 'checkbox', 'resume[variants][music][show_built]', __( 'Show "What I\'ve Built" in the music PDF', 'signal-and-noise-tools' ), (bool) $on, array( 'value' => '1' ) );
}

/**
 * The operations setup: its own action and nonce, never the résumé document.
 *
 * @return string
 */
function resume_operations_setup() {
	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Writes the operations résumé\'s starting content into the draft: its settings, What I\'ve Built, its skills rows and two affiliations, and "Music only" on what it leaves out. Existing wording is never changed. Review the draft, then publish. Running it again never rewords anything or adds a row twice; it marks "Music only" again on what the operations résumé leaves out.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section(
		__( 'Operations résumé setup', 'signal-and-noise-tools' ),
		\snt_kit_form( 'resume_operations_setup', $intro, array( 'submit' => __( 'Set up the operations résumé', 'signal-and-noise-tools' ) ) )
	);
}
