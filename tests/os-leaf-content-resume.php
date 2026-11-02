<?php
/**
 * Native window leaf: Content → Resume Page (apps/sn-dashboard/parts/leaves/content-resume.php).
 *
 * The oracle is the classic structured editor: the kit form must carry the
 * same field names — every indexed row AND every template token key — the
 * same one sn_action, every section with its count and helper line, the
 * seed prefill, both intro states, the hard failure state, and none of
 * wp-admin's markup.
 *
 * 2026-10-11 (owner rule: no collapsible sections). The eleven sections are
 * ONE table, one row per section with a visible Edit button; `sn_edit=<key>`
 * paints exactly one edit region below it, that section's fields and
 * repeater in the resume_draft_save form. The handler rebuilds the WHOLE
 * document from the post, so the form carries every other section as hidden
 * inputs under the classic names. The names oracle runs across the eleven
 * editing paints, and the data-safety pins post each paint through the real
 * handler and require the stored document back unchanged.
 *
 * 2026-11-01 (résumé variants): every row carries "Shows in", a role's saved
 * bullets carry one each, What I've Built and Operations résumé are two more
 * sections (after Skills, before PDF only), PDF only gains the music PDF's
 * What I've Built box, and the operations setup is a third, separate form.
 * The classic renders all of it here (inc/admin-forms/resume-variants.php is
 * loaded), so the names, labels and placeholders oracles cover it.
 *
 * Run: php tests/os-leaf-content-resume.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's one reader, answering from a fixture so every state — the
// stored document, the seed, and NEITHER — is reachable.
$GLOBALS['__resume_doc'] = null;
function sn_resume_doc_get() { return $GLOBALS['__resume_doc']; }
function sn_resume_doc_get_raw() { return $GLOBALS['__resume_doc']; } // the editor reads the stored document (variants, 2026-11-01).
// The draft readers (inc/resume-draft.php), answering from fixtures too: no
// draft and no previous version unless a group below sets one.
$GLOBALS['__resume_draft'] = null;
$GLOBALS['__resume_prev']  = null;
function sn_resume_draft_get() { return $GLOBALS['__resume_draft']; }
function sn_resume_prev_get() { return $GLOBALS['__resume_prev']; }
// A stored draft normalize refuses reads null above but still EXISTS.
$GLOBALS['__resume_draft_unreadable'] = false;
function sn_resume_draft_exists() { return null !== $GLOBALS['__resume_draft'] || $GLOBALS['__resume_draft_unreadable']; }
function sn_resume_draft_status() { return null === $GLOBALS['__resume_draft'] ? 'No draft; showing the live resume.' : 'Draft saved 2026-09-30; differs from live.'; }
// The generated PDF's readers (inc/resume-pdf/generate.php).
function sn_resume_pdf_when( $meta ) { return 'October 11, 2026 at 6:30 pm'; }
function sn_resume_pdf_link( $v ) { return 'https://example.test/wp-content/uploads/resume.pdf'; }

// The classic's bullet labels (sn_rsm_bullet_variants()) and the kit's hints strip markup.
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
// The variant layer: sn_resume_bullet_key() and the PDF section keys. It
// declares no reader, so it loads beside the fixture stubs above.
require SNT_PATH . 'inc/resume-variants.php';
require SNT_PATH . 'inc/admin-forms/resume-page.php';
require SNT_PATH . 'inc/admin-forms/resume-variants.php'; // the classic "Shows in" rows, What I've Built, Operations résumé, the setup form.
require SNT_PATH . 'inc/admin-forms/resume-receipts.php'; // the classic receipt URL + type on entries and bullets.
require SNT_PATH . 'inc/admin-forms/resume-draft.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-resume.php';
require_once SNT_PATH . 'inc/openstation-host-pipelines.php'; // snt_os_host_expand(): the host's own expansion of a posted bag.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$seed = json_decode( (string) file_get_contents( SNT_PATH . 'inc/seed-content/resume-data.json' ), true );
ok( is_array( $seed ) && isset( $seed['experience'][1]['roles'][1] ), 'the shipped seed is the rich fixture (two employers, a second role)' );
$seed['updated'] = '';

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/resume'] ), 'the painter is registered under content/resume' );

// ── Helpers. The eleven section keys and titles in the classic order, the
// editing paint of one section, and the eleven of them.
$keys       = array( 'hero', 'stats', 'experience', 'earlier', 'education', 'affiliations', 'publications', 'skills', 'built', 'variants', 'pdf' );
$titles     = array( 'Hero', 'Stats', 'Experience', 'Earlier career', 'Education', 'Affiliations & Certifications', 'Publications', 'Skills', "What I've Built", 'Operations résumé', 'PDF only' );
// The editor's three forms. The classic paints the same three.
$forms      = array( 'resume_draft_save', 'resume_operations_setup', 'resume_pdf_generate' );
$paint_edit = static function ( $key ) {
	return snt_leaf_paint( 'content', 'resume', array( 'params' => array( 'sn_edit' => $key ) ) );
};
$paint_all  = static function () use ( $keys, $paint_edit ) {
	$out = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = $paint_edit( $k );
	}
	return $out;
};
// Template content is not in the document: drop every <template>, innermost first.
$strip_tpl = static function ( $html ) {
	do {
		$html = preg_replace( '#<template\b(?:(?!<template\b).)*?</template>#s', '', (string) $html, -1, $n );
	} while ( $n > 0 );
	return $html;
};
// What os-form posts (os-form.ts getValues(): every [name] in the light DOM,
// a checkbox as its boolean), expanded as the host expands it (true '1',
// false ''). Independent of the leaf's carrier code on purpose.
$posted = static function ( $html ) use ( $strip_tpl ) {
	preg_match_all( '/<(os-[a-z-]+|input)\b[^>]*>/', $strip_tpl( $html ), $tags, PREG_SET_ORDER );
	$values = array();
	foreach ( $tags as $tag ) {
		if ( ! preg_match( '/\sname="([^"]*)"/', $tag[0], $n ) ) {
			continue;
		}
		$name = html_entity_decode( $n[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( in_array( $tag[1], array( 'os-checkbox-label', 'os-checkbox', 'os-switch' ), true ) ) {
			$values[ $name ] = 1 === preg_match( '/\schecked\b/', preg_replace( '/="[^"]*"/', '', $tag[0] ) );
			continue;
		}
		$values[ $name ] = preg_match( '/\svalue="([^"]*)"/', $tag[0], $v ) ? html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}
	return snt_os_host_expand( $values );
};
$folds_in = static function ( array $paints ) {
	$found = array();
	foreach ( $paints as $state => $html ) {
		if ( array() !== snt_leaf_folds( $html ) ) {
			$found[] = $state . ':' . implode( ',', snt_leaf_folds( $html ) );
		}
	}
	return $found;
};
// One child process per boot: the JSON on stdin is the posted `resume`
// array, the JSON on stdout is whatever the boot echoes. The REAL data
// layer runs there (inc/resume-page.php unconditionally declares
// sn_resume_doc_get(), which collides with this suite's own fixture stub).
$spawn = function ( $boot, $in ) {
	$spec  = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$pipes = array();
	$proc  = @proc_open( array( PHP_BINARY ?: 'php', '-r', $boot ), $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		return null;
	}
	fwrite( $pipes[0], (string) json_encode( $in ) );
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	return json_decode( (string) $out, true );
};
$boot = 'define(\'ABSPATH\',\'/\');'
	// sn_resume_string_list() delegates to sn_content_items_normalize() (the
	// admin layer's newline-splitter for a repeatable-of-plain-strings) when
	// it is loaded, and degrades to an array-only cast otherwise; load it so
	// the textarea-posted chips/bullets/lines split the same way a real
	// request's bootstrap would have them split.
	. 'require ' . var_export( SNT_PATH . 'inc/admin-post-actions/content.php', true ) . ';'
	. 'require ' . var_export( SNT_PATH . 'inc/resume-page.php', true ) . ';'
	. '$in=json_decode(file_get_contents("php://stdin"),true);'
	. 'echo json_encode(sn_resume_doc_normalize($in));';
// The form's action is resume_draft_save: the REAL handler, which writes the DRAFT slot.
$handler_boot = 'define(\'ABSPATH\',\'/\');'
	. 'function wp_unslash($v){return $v;} function get_option($k){return false;} function update_option($k,$v){$GLOBALS[\'saved\'][$k]=$v;return true;}'
	. 'require ' . var_export( SNT_PATH . 'inc/admin-post-actions/content.php', true ) . ';' // sn_content_items_normalize(): the textarea lists
	. 'require ' . var_export( SNT_PATH . 'inc/admin-post-actions/resume-draft.php', true ) . ';'
	. 'require ' . var_export( SNT_PATH . 'inc/resume-page.php', true ) . ';'
	. 'require ' . var_export( SNT_PATH . 'inc/resume-draft.php', true ) . ';'
	. '$in=json_decode(file_get_contents("php://stdin"),true);'
	. 'echo json_encode(array(sn_handle_resume_draft_save($in),$GLOBALS[\'saved\'][\'sn_resume_draft\']??null,array_keys($GLOBALS[\'saved\']??array())));';

// ── The seed, unsaved: the ledger, and one edit region per section.
$GLOBALS['__resume_doc'] = $seed;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
$edits   = $paint_all();
$kit_all = implode( '', $edits );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === $folds_in( array( 'default' => $kit ) + $edits ), 'no fold on the default paint or on any of the eleven editing paints: ' . implode( ' ', $folds_in( array( 'default' => $kit ) + $edits ) ) );

// The ledger: ONE table of the eleven sections, in the classic order.
$tables = snt_leaf_tables( $kit );
$t      = $tables[0] ?? array( 'columns' => array(), 'data' => array(), 'slots' => array() );
ok( 1 === count( $tables ) && $titles === array_column( $t['data'], 'section' ), 'the default paint is ONE table of the eleven sections in the classic order: ' . implode( ' / ', array_column( $t['data'], 'section' ) ) );
ok( count( $t['columns'] ) === count( array_filter( array_column( $t['columns'], 'label' ) ) ), 'every column carries a header label' );
$entries      = count( $t['data'] ) === count( $keys ) ? array_combine( $keys, array_column( $t['data'], 'entries' ) ) : array();
$want_entries = array( 'hero' => '', 'stats' => '4', 'experience' => '2', 'earlier' => (string) count( $seed['earlier']['entries'] ), 'education' => '2', 'affiliations' => '4', 'publications' => '2', 'skills' => '6', 'built' => '0', 'variants' => '', 'pdf' => '' );
ok( $want_entries === $entries, 'the fold counts are the Entries cells (4 / 2 / ' . count( $seed['earlier']['entries'] ) . ' / 2 / 4 / 2 / 6 / 0); Hero, Operations résumé and PDF only carry none, as their classic summaries: ' . json_encode( $entries ) );
ok( 'INDEPENDENT PRACTICE' === ( $t['data'][2]['first'] ?? '' ) && '15+ Years in the industry' === ( $t['data'][1]['first'] ?? '' ), 'each row names what the section opens with (Experience: the first employer; Stats: the first stat)' );
$edit_ok = count( $keys ) === count( $t['data'] );
foreach ( $t['data'] as $i => $row ) {
	$edit_ok = $edit_ok && 1 === preg_match( '#<os-button[^>]* os-action="go"[^>]* os-arg-sub="resume" os-arg-sn_edit="' . $keys[ $i ] . '"[^>]*>Edit</os-button>#', snt_leaf_cell_html( $t, $row['edit'] ?? null ) );
}
ok( $edit_ok, 'every row carries a visible Edit button: a go to this leaf with sn_edit=<section>' );
$n_entries = count( $seed['stats'] ) + count( $seed['experience'] ) + count( $seed['earlier']['entries'] ) + count( $seed['education'] ) + count( $seed['affiliations'] ) + count( $seed['publications'] ) + count( $seed['skills'] );
$n_roles   = count( $seed['experience'][0]['roles'] ) + count( $seed['experience'][1]['roles'] );
ok( false !== strpos( $kit, '<p class="snt-prose">' . $n_entries . ' entries across 8 lists; Experience holds 2 employers with ' . $n_roles . ' roles.</p>' ), 'the finding over the table is built from the document (' . $n_entries . ' entries, ' . $n_roles . ' roles)' );
ok( false === strpos( $kit, 'snt-ledger-more' ), 'eleven sections, never capped: no "+N more" line' );
// No section is being edited: no repeater, no editor form, no field.
ok( false === strpos( $kit, '<os-repeater' ) && array() === snt_leaf_names( $kit ) && array( 'resume_operations_setup', 'resume_pdf_generate' ) === snt_leaf_actions( $kit ), 'with no section being edited, the table is the whole editor: no repeater, no field, no Save draft (the separate PDF and setup forms stay)' );
ok( snt_leaf_paint( 'content', 'resume', array( 'params' => array( 'sn_edit' => 'bogus' ) ) ) === $kit, 'an unknown sn_edit paints the default view' );

// Exactly ONE region per editing paint, below the table, named for its
// section, its own fields only, the rest riding along as hidden inputs.
$region_bad = array();
foreach ( $keys as $i => $k ) {
	$h       = $edits[ $k ];
	$heading = '<os-section heading="' . snt_kit_esc( $titles[ $i ] ) . '"';
	preg_match_all( '/<(?:os-text-field|os-textarea|os-checkbox-label|os-select)\b[^>]*\sname="([^"]*)"/', $strip_tpl( $h ), $vis );
	preg_match_all( '/<input type="hidden" name="(resume\[[^"]*)"/', $h, $hid );
	// A section owns its key, except the two variant blocks: PDF only holds
	// the music PDF's box, Operations résumé the operations settings.
	$prefixes = array( 'pdf' => array( 'resume[pdf]', 'resume[variants][music]' ), 'variants' => array( 'resume[variants][operations]' ) )[ $k ] ?? array( 'resume[' . $k . ']' );
	$own      = static function ( $n ) use ( $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( html_entity_decode( $n ), $prefix ) ) {
				return true;
			}
		}
		return false;
	};
	$fine = 1 === substr_count( $h, $heading ) && strpos( $h, $heading ) > strpos( $h, '</os-table>' )
		&& 1 === substr_count( $h, 'submit-label="Save draft"' )
		&& count( $vis[1] ) > 0 && count( $vis[1] ) === count( array_filter( $vis[1], $own ) )
		&& count( $hid[1] ) > 0 && 0 === count( array_filter( $hid[1], $own ) )
		&& 1 === preg_match( '#<span slot="footer-leading"><os-button variant="ghost" os-action="go" os-arg-sub="resume" os-arg-anchor="snt-row-' . substr( md5( $k ), 0, 12 ) . '">Cancel</os-button></span>#', $h );
	if ( ! $fine ) {
		$region_bad[] = $k;
	}
}
ok( array() === $region_bad, 'each editing paint: ONE region below the table, headed with the section, its own fields visible, every other section hidden, Save draft and a Cancel (a go that clears sn_edit) in one action row: ' . implode( ',', $region_bad ) );

// The phone switch (owner, 2026-09-22: "the toggle for the phone isn't
// working"). os-form reads only checkboxes as booleans; any other tag submits
// its static value, so an os-switch posts '1' in BOTH positions and would
// publish the phone on every save. The leaf must use a real checkbox.
$kit_dec = html_entity_decode( $edits['pdf'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
ok( 1 === preg_match( '/<os-checkbox-label[^>]*name="resume\\[pdf\\]\\[phone_public\\]"[^>]*value="1"|<os-checkbox-label[^>]*value="1"[^>]*name="resume\\[pdf\\]\\[phone_public\\]"/', $kit_dec ), 'the phone switch is an os-checkbox-label posting 1 only when checked' );
ok( '' !== $kit_all && false === strpos( $kit . $kit_all, '<os-switch' ), 'the resume leaf carries no os-switch at all (os-form cannot read one as a boolean)' );
ok( false !== strpos( $kit_dec, 'name="resume[pdf][website]"' ), 'the Website field is on the leaf' );
ok( false !== strpos( $kit_dec, 'name="resume[pdf][summary]"' ), 'the Professional summary field is on the leaf' );
// Realness: the classic really renders the variant controls, so the parity
// pins here cover them and cannot pass vacuously.
$classic_names = snt_leaf_names( $classic );
$has_name      = static function ( array $names, $needle ) { return array() !== array_filter( $names, static function ( $n ) use ( $needle ) { return false !== strpos( $n, $needle ); } ); };
ok( $has_name( $classic_names, '[variant]' ) && $has_name( $classic_names, '[bullet_variants][' ) && in_array( 'resume[built][items][__B__][title]', $classic_names, true ) && in_array( 'resume[variants][operations][headline]', $classic_names, true ) && in_array( 'resume[variants][music][show_built]', $classic_names, true ), 'the classic form renders the variant controls (Shows in, per-bullet, What I\'ve Built, Operations résumé, the music box), so the names oracle below is a real check' );
foreach ( array( 'resume[experience][0][variant]', 'resume[experience][0][roles][0][variant]', 'resume[earlier][entries][0][variant]', 'resume[earlier][entries][0][roles][0][variant]', 'resume[education][0][variant]', 'resume[affiliations][0][variant]', 'resume[publications][0][variant]', 'resume[skills][0][variant]', 'resume[built][items][__B__][variant]', 'resume[variants][operations][earlier_compact]', 'resume[variants][operations][pdf_file]' ) as $vn ) {
	ok( in_array( $vn, $classic_names, true ) && in_array( $vn, snt_leaf_names( $kit_all ), true ), 'both surfaces paint ' . $vn );
}
$bullet_names = array_values( preg_grep( '/^resume\[experience\]\[0\]\[roles\]\[0\]\[bullet_variants\]\[[0-9a-f]{12}\]$/', snt_leaf_names( $kit_all ) ) );
ok( count( $seed['experience'][0]['roles'][0]['bullets'] ) === count( $bullet_names ) && in_array( 'resume[experience][0][roles][0][bullet_variants][' . sn_resume_bullet_key( $seed['experience'][0]['roles'][0]['bullets'][0] ) . ']', $bullet_names, true ), 'one per-bullet select per saved bullet, keyed by sn_resume_bullet_key() of its text (' . count( $bullet_names ) . ')' );
$clip90 = static function ( $bullet ) { // the bullet's text, markup out, cut at 90 characters
	$text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $bullet ) ) );
	return mb_strlen( $text ) > 90 ? rtrim( mb_substr( $text, 0, 89 ) ) . '…' : $text;
};
ok( 1 === preg_match( '#<os-field-row label="Shows in" hint="' . preg_quote( snt_kit_esc( $clip90( $seed['experience'][0]['roles'][0]['bullets'][0] ) ), '#' ) . '"><os-select name="resume\[experience\]\[0\]\[roles\]\[0\]\[bullet_variants\]\[#', $edits['experience'] ) && false === strpos( $edits['experience'], '<os-disclosure' ), 'a bullet\'s select is labelled "Shows in", as the classic, and names its bullet in the hint; no fold around them' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit_all ), 'field names across the eleven editing paints match the classic form (' . count( snt_leaf_names( $kit_all ) ) . ' names): ' . implode( ',', array_diff( snt_leaf_names( $classic ), snt_leaf_names( $kit_all ) ) ) . ' missing; ' . implode( ',', array_diff( snt_leaf_names( $kit_all ), snt_leaf_names( $classic ) ) ) . ' extra' );
// The seed's three boxes (the phone, the music PDF's What I've Built, the
// compact earlier career) are unchecked, which a browser never posts; a
// carrier mirrors the browser and leaves them out. So every editing paint
// carries the live classic names exactly, less each box its section is not.
$classic_live_names = snt_leaf_names( $strip_tpl( $classic ) );
$boxes              = array( 'resume[pdf][phone_public]' => 'pdf', 'resume[variants][music][show_built]' => 'pdf', 'resume[variants][operations][earlier_compact]' => 'variants' );
$short              = array();
foreach ( $edits as $k => $h ) {
	$want_names = array_values( array_diff( $classic_live_names, array_keys( array_diff( $boxes, array( $k ) ) ) ) );
	if ( snt_leaf_names( $strip_tpl( $h ) ) !== $want_names ) {
		$short[] = $k;
	}
}
ok( array() === $short, 'EVERY editing paint carries every live classic name (its section as fields, the other ten as hidden inputs; an unchecked box posts nothing, as in a browser), so no save can drop a section: ' . implode( ',', $short ) );
// Resume PDF (docs/RESUME-PDF.md): a second, SEPARATE form generates the PDF
// from the saved document; it posts no resume fields. The editor itself still
// has exactly one action, and since drafts that action saves a DRAFT: with no
// draft and no previous version, no publish/discard/revert control paints.
$act_bad = array();
foreach ( $edits as $k => $h ) {
	if ( $forms !== snt_leaf_actions( $h ) ) {
		$act_bad[] = $k;
	}
}
ok( array() === $act_bad && snt_leaf_actions( $classic ) === $forms, 'every editing paint posts resume_draft_save plus the separate resume_pdf_generate and resume_operations_setup, as on the classic leaf (the private copy is a door or link, not a form): ' . implode( ',', $act_bad ) );
ok( false === strpos( $kit . $kit_all . $classic, 'resume_save"' ) && false === strpos( $kit . $kit_all . $classic, 'nonce-sn_resume_save' ), 'neither surface posts the old direct-publish resume_save any more' );
ok( false !== strpos( $kit, 'No draft; showing the live resume.' ) && false !== strpos( $classic, 'No draft; showing the live resume.' ), 'no draft: both surfaces say the form shows the live resume' );
// 2026-10-12 audit: the status line sits in the Draft box, which is always
// painted and shares a row with Resume PDF (both stacked full width; the
// status was nine rows from Publish).
ok( 1 === preg_match( '#' . preg_quote( snt_leaf_row( 3 ), '#' ) . '<os-section heading="Draft" stack><p class="snt-prose">No draft; showing the live resume\.</p><p class="snt-prose">Save draft stores a draft; its previews, Publish and Discard then appear here\.</p></os-section><os-section heading="Resume PDF" stack>.*</os-grid>$#s', $kit ) && 1 === substr_count( $kit, 'No draft; showing the live resume.' ), 'no draft: the Draft box holds the status and what makes a draft, and pairs with Resume PDF; the status is not in the editor lead' );
ok( 1 === preg_match( '#<os-section heading="Resume PDF" stack>.*</os-section><os-section heading="Operations résumé setup" stack><os-form [^>]*submit-label="Set up the operations résumé"[^>]*><p class="snt-prose">Writes the operations résumé&\#039;s starting content into the draft: .*?</p><input type="hidden" name="action" value="sn_resume_operations_setup"><input type="hidden" name="_wpnonce" value="nonce-sn_resume_operations_setup"></os-form></os-section></os-grid>$#s', $kit ) && false !== strpos( $classic, '<h2 class="sn-fieldset-h">Operations résumé setup</h2>' ), 'the operations setup is its own kit form (its action, its nonce, no résumé field), the third box beside Draft and Resume PDF, as the classic\'s own fieldset' );
ok( 1 === preg_match( '#<h2 class="sn-fieldset-h">Draft</h2><p class="sn-fieldset-intro">No draft; showing the live resume\.</p><p class="description">Save draft stores a draft; its previews, Publish and Discard then appear here\.</p></div>#', $classic ) && 1 === substr_count( $classic, 'No draft; showing the live resume.' ), 'no draft: the classic Draft box is painted too, the status in it' );
ok( false === strpos( $classic, 'collapsed fold)' ) && false === strpos( $classic, 'set above' ) && false === strpos( $classic, 'copy below' ) && false !== strpos( $classic, 'still uses the PDF URL. Generating' ) && false !== strpos( $classic, 'only the private copy carries it.' ), 'the classic says what the window says: no positional words, "Earlier career" without "(collapsed fold)"' );
$GLOBALS['__options']['sn_resume_pdf'] = array( 'url' => 'https://example.test/wp-content/uploads/resume.pdf', 'pages' => 1, 'bytes' => 2048, 'sha256' => 'abcdef0123456789abcdef', 'generated' => '2026-10-11T18:30:00Z' );
$pdf_kit     = snt_leaf_paint( 'content', 'resume' );
$pdf_classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
ok( false !== strpos( $pdf_kit, 'Generated October 11, 2026 at 6:30 pm: 1 page, 2048 B, SHA-256 <os-code>abcdef012345</os-code>. ' ) && false !== strpos( $pdf_kit, '>Open the PDF</' ) && false !== strpos( $pdf_kit, '. The /resume Download link points here.</p>' ), 'a generated PDF: the window states its size, its SHA-256 prefix and where the Download link points, one page singular' );
ok( false !== strpos( $pdf_classic, 'Generated October 11, 2026 at 6:30 pm: 1 page, 2048 B, SHA-256 <code>abcdef012345</code>. ' ) && false !== strpos( $pdf_classic, 'The /resume Download link points here.' ), 'a generated PDF: the classic says the same' );
unset( $GLOBALS['__options']['sn_resume_pdf'] );
ok( array() === snt_leaf_classic_markers( $kit . $kit_all ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit . $kit_all ) ) );
// The kit grid's own track count (snt_kit_grid(): style="--snt-grid-items:N") is the kit's, not a hand-rolled style.
ok( ! preg_match( '/\sstyle="/', preg_replace( '/ style="--snt-grid-items:\d+"/', '', $kit . $kit_all ) ), 'no inline style= survives (the kit grid\'s own track count aside)' );
ok( (bool) preg_match( '/\sstyle="/', $kit . '<p style="x">' ), 'the inline-style guard above discriminates (fails on a planted style=)' );
ok( 2 === substr_count( $kit, '<os-form' ) && 3 === substr_count( $edits['stats'], '<os-form' ) && false !== strpos( $kit, 'submit-label="Generate PDF"' ) && false !== strpos( $edits['stats'], 'os-action="post"' ) && false !== strpos( $edits['stats'], 'submit-label="Save draft"' ) && false === strpos( $kit_all, 'os-arg-pipeline' ), 'editing: three os-forms (Save draft, Generate PDF, the operations setup) dispatching post through the admin-post pipeline; the default paint holds the last two' );
// The private copy streams a PDF and exits; a window replays a form's handler
// inside a dispatch (snt_os_host_intercept catches only redirects and dies),
// so the bytes never reached the reader. It is a door now, and on the classic
// screen the same URL in a new tab.
$pv_url = 'admin-post.php?action=sn_resume_pdf_private&page=sn-content&tab=content&sub=resume&_wpnonce=nonce-sn_resume_pdf_private';
ok( false === strpos( $kit, 'submit-label="Download private copy (with phone)"' ) && 1 === preg_match( '/<os-button[^>]*os-action="door"[^>]*' . preg_quote( htmlspecialchars( $pv_url, ENT_QUOTES ), '/' ) . '[^>]*>Download private copy \(with phone\)/', $kit ) && false !== strpos( html_entity_decode( $classic ), 'href="https://example.test/wp-admin/' . $pv_url . '">Download private copy (with phone)</a>' ), 'the private copy is a door (kit) and a new-tab link (classic) with its nonce, never a form' );
ok( false !== strpos( $edits['experience'], 'name="resume[experience][1][roles][1][title]"' ) && false !== strpos( $edits['earlier'], 'name="resume[earlier][entries][1][roles][1][title]"' ), 'nested role names survive two levels down in both Experience and Earlier career' );
ok( false !== strpos( $edits['experience'], 'name="resume[experience][__E__][roles][__R__][title]"' ) && false !== strpos( $edits['experience'], 'name="resume[experience][0][roles][__R__][bullets]"' ), 'the template token keys the classic bakes (__E__, __R__) are the blank rows\' names' );

// ── Every classic field LABEL and PLACEHOLDER survives. snt_leaf_names() only
// sees `name="…"` attributes, so a renamed <span class="sn-rsm-label"> or a
// blanked placeholder is invisible to the pins above — these two are not.
// Across the eleven editing paints, since each paints its own section's
// fields. Decoded on both sides: a classic label echoes its quotes raw, the
// kit's label attribute carries them escaped.
$c_lab = function ( $h ) {
	preg_match_all( '/<span class="sn-rsm-label">([^<]*)<\/span>|<label class="sn-field-label"[^>]*>([^<]*)<\/label>/', $h, $m );
	$o = array_values( array_unique( array_filter( array_map( static function ( $l ) { return html_entity_decode( $l, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }, array_merge( $m[1], $m[2] ) ) ) ) );
	sort( $o );
	return $o;
};
$k_lab = function ( $h ) {
	preg_match_all( '/\slabel="([^"]*)"/', $h, $m );
	$o = array_values( array_unique( array_map( static function ( $l ) { return html_entity_decode( $l, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }, $m[1] ) ) );
	sort( $o );
	return $o;
};
$ph = function ( $h ) {
	preg_match_all( '/placeholder="([^"]*)"/', $h, $m );
	$o = array_values( array_unique( array_filter( $m[1] ) ) );
	sort( $o );
	return $o;
};
ok( $c_lab( $classic ) === $k_lab( $kit_all ), 'every classic field label (' . count( $c_lab( $classic ) ) . ') is a kit label; missing: ' . implode( ' | ', array_diff( $c_lab( $classic ), $k_lab( $kit_all ) ) ) );
ok( $ph( $classic ) === $ph( $kit_all ), 'every classic placeholder survives; missing: ' . implode( ' | ', array_diff( $ph( $classic ), $ph( $kit_all ) ) ) );

// ── Round trip: the token-keyed blank rows sit in inert <template>s since
// #1598 and never post, but a row the owner adds and leaves blank does, and
// the name regex below reads through the templates, so this post carries
// every __TOKEN__ field. It is safe only because the real
// sn_resume_doc_normalize() prunes title/org-less rows back to the painted
// document. Read across the eleven editing paints (later-wins, as PHP parses a
// body: a hidden carrier holds the same value as its field).
$scrape = static function ( $html ) {
	$values = array();
	preg_match_all( '/<(?:os-text-field|os-textarea|input)\b[^>]*>/', $html, $tags );
	foreach ( $tags[0] as $tag ) {
		if ( ! preg_match( '/\sname="([^"]+)"/', $tag, $n ) ) {
			continue;
		}
		preg_match( '/\svalue="([^"]*)"/', $tag, $v );
		$values[ html_entity_decode( $n[1], ENT_QUOTES ) ] = html_entity_decode( $v[1] ?? '', ENT_QUOTES );
	}
	return $values;
};
$values        = $scrape( $kit_all );
$post          = snt_os_host_expand( $values );
$resume_posted = $post['resume'] ?? array();
$back  = $spawn( $boot, $resume_posted );
$want = $seed;
unset( $want['updated'] );
ok( is_array( $back ) && $back === $want, 'round trip: the live blank template rows the kit now posts (__S__/__E__/__R__/__X__/__Y__/__D__/__A__/__P__/__K__) are pruned by the real sn_resume_doc_normalize() — the saved document is byte-identical to the painted one' );

// ── Every section, in order, with its count (the Entries cells above) and
// its helper line, which is now the edit region's description.
foreach ( array( 'hero' => 'The opening band: summary, credential chips, contact line, and the PDF download.', 'stats' => 'The numbers strip under the hero.', 'experience' => 'Bullets may use &lt;strong&gt;, &lt;em&gt;, and links.', 'earlier' => 'Rendered inside a collapsed &quot;details&quot; fold at the end of Experience.', 'publications' => 'A new paper is one row: venue line, title, and link.', 'skills' => 'One table row per category; items is the comma-separated cell.' ) as $k => $hint ) {
	ok( 1 === preg_match( '/<os-section heading="[^"]*" description="[^"]*' . preg_quote( $hint, '/' ) . '/', $edits[ $k ] ), 'helper line survives as the ' . $k . ' region\'s description: ' . substr( $hint, 0, 40 ) );
}
ok( false === strpos( $kit, 'Earlier career (collapsed fold)' ) && false !== strpos( $edits['earlier'], '<os-section heading="Earlier career" description="Rendered inside a collapsed &quot;details&quot; fold' ), 'Earlier career is named plainly; the fold it names is on /resume, and its helper line says so' );
ok( 1 === preg_match( '/<os-section heading="Hero" description="[^"]*"/', $edits['hero'] ) && '' === $entries['hero'] && '' === $entries['variants'] && '' === $entries['pdf'], 'Hero, Operations résumé and PDF only carry no count, as the classic summaries' );

// ── The lists are the kit's <os-repeater> (#1598), one per classic add
// button, reorderable, its rows slotted under their index and named in
// os-prop-keys, the classic <template> inert inside it. The hand-rolled
// chrome of 17.4.0 (sn-rsm-up / sn-rsm-down kit buttons with sr-only names,
// the data-rsm-row mark, the closed "+ Add" fold and its "blank a row" hint)
// is gone: the repeater paints "Move <row> up", "Remove <row>" and Add itself.
// Each repeater paints once, in its section's editing paint.
$classic_live = preg_replace( '#<template\b.*?</template>#s', '', $classic );
$kit_live     = preg_replace( '#<template\b.*?</template>#s', '', $kit_all );
$n_rows       = substr_count( $classic_live, 'sn-rsm-up' );
$adds         = array( '+ Add stat' => 'stat', '+ Add role' => 'role', '+ Add employer' => 'employer', '+ Add earlier employer' => 'employer', '+ Add education' => 'education entry', '+ Add affiliation' => 'affiliation', '+ Add publication' => 'publication', '+ Add skills row' => 'skills row', '+ Add project' => 'project' );
preg_match_all( '/<os-repeater [^>]*>/', $kit_all, $reps );
ok( $n_rows > 20 && count( $reps[0] ) === substr_count( $classic, 'sn-rsm-add' ) && count( $reps[0] ) === count( preg_grep( '/ reorderable /', $reps[0] ) ) && count( $reps[0] ) === count( preg_grep( '/ os-prop-keys="/', $reps[0] ) ), 'one reorderable <os-repeater> with os-prop-keys per classic add button (' . count( $reps[0] ) . ')' );
foreach ( $adds as $add => $noun ) {
	ok( count( preg_grep( '/ add-label="' . preg_quote( $add, '/' ) . '" row-label="' . preg_quote( $noun, '/' ) . '" /', $reps[0] ) ) > 0, 'the classic add button is the repeater\'s add-label, the row noun its row-label: ' . $add . ' / ' . $noun );
}
ok( false !== strpos( $edits['stats'], '<os-repeater os-key="resume[stats]" reorderable add-label="+ Add stat" row-label="stat" row-label-from="label" empty-text="No rows yet." os-prop-keys="[&quot;0&quot;,&quot;1&quot;,&quot;2&quot;,&quot;3&quot;]">' ), 'the Stats repeater names its four rows 0..3 in os-prop-keys (the runtime assigns keys after every render)' );
ok( preg_match( '/<os-repeater os-key="resume\[experience\]\[0\]\[roles\]" [^>]*row-label="role"[^>]*os-prop-keys="\[&quot;0&quot;(,&quot;1&quot;)?\]"/', $edits['experience'] ) === 1, 'the roles under an employer are a nested repeater keyed on the employer prefix' );
// OpenStation 1.1.15 (#946/#976): `row-label-from` names a row's buttons from a
// field's value ("Remove <its value> (1 of 3)"). The repeater matches name,
// data-field or id by EXACT value, and every classic name carries its row index
// (resume[stats][0][label] vs [1][label]), so a name could only ever match one
// row; each list's labeling field carries a shared data-field instead, in the
// live rows and the template alike. row-label stays the fallback for an empty field.
$from = array( 'resume[stats]' => 'label', 'resume[experience]' => 'org', 'resume[earlier][entries]' => 'org', 'resume[education]' => 'title', 'resume[affiliations]' => 'title', 'resume[publications]' => 'title', 'resume[skills]' => 'category', 'resume[built][items]' => 'title' );
ok( count( $reps[0] ) === count( preg_grep( '/ row-label="[^"]+" row-label-from="(label|org|title|category)" /', $reps[0] ) ), 'every repeater names its rows from a field (row-label-from) and keeps row-label as the fallback (' . count( $reps[0] ) . ')' );
foreach ( $from as $base => $field ) {
	ok( 1 === preg_match( '/<os-repeater os-key="' . preg_quote( $base, '/' ) . '" [^>]*row-label-from="' . $field . '"/', $kit_all ), "$base names its rows from data-field=\"$field\"" );
	$named = preg_match_all( '/<os-text-field data-field="' . $field . '" name="' . preg_quote( $base, '/' ) . '\[([^\]]+)\]\[' . $field . '\]"/', $kit_all, $nm );
	$rows  = preg_match_all( '/<os-card os-key="' . preg_quote( $base, '/' ) . '\[([^\]]+)\]"/', $kit_all );
	ok( $rows > 0 && $named === $rows && in_array( '__' , array_map( static fn( $k ) => substr( $k, 0, 2 ), $nm[1] ), true ), "$base: every row, the template's blank row included, carries data-field=\"$field\" on its $field field ($named of $rows)" );
}
ok( preg_match_all( '/<os-repeater os-key="resume\[(?:experience|earlier\]\[entries)\]\[[^\]]+\]\[roles\]" [^>]*row-label-from="title"/', $kit_all ) === substr_count( $kit_all, '[roles]" reorderable' ) && preg_match_all( '/<os-text-field data-field="title" name="[^"]*\[roles\]\[[^\]]+\]\[title\]"/', $kit_all ) === preg_match_all( '/<os-card os-key="[^"]*\[roles\]\[[^\]]+\]"/', $kit_all ), 'every roles repeater names a role by its title, and every role row carries data-field="title"' );
ok( false === strpos( $kit_all, 'data-field="title" name="resume[experience][0][org]"' ) && 1 === preg_match( '/<os-text-field data-field="org" name="resume\[experience\]\[0\]\[org\]"/', $kit_all ), 'an employer card carries data-field="org" on its org field, so the outer repeater never names an employer from a nested role\'s title' );
ok( $n_rows === substr_count( $kit_live, ' slot="row-' ) && 0 === substr_count( $kit_live, ' slot="row-__' ) && $n_rows === preg_match_all( '/<os-card os-key="([^"]*\[(\d+)\])" slot="row-\2"/', $kit_live ), 'every live classic row is a kit card slotted under its own index, slot row-N matching the os-key\'s last segment, no token slot outside a template (' . $n_rows . ')' );
ok( preg_match_all( '/<template data-rsm-tpl data-rsm-token="(__[A-Z]__)">(?:(?!<\/template>).)*? slot="row-\1"/s', $kit_all ) === count( $reps[0] ) && substr_count( $kit_all, '<template data-rsm-tpl data-rsm-token="__R__">' ) === count( $seed['experience'] ) + 1, 'each repeater ends in the classic template, its blank row slotted under the token, one __R__ template per employer plus the one inside the __E__ employer template' );
ok( count( $reps[0] ) === substr_count( $kit_all, '</template></os-repeater>' ), 'the template is the repeater\'s LAST child, so an added row inserted before it keeps DOM order equal to display order' );
foreach ( array( 'sn-rsm-up', 'sn-rsm-down', 'snt-sr-only', 'data-rsm-row', 'Blank a row and save', 'rows keep the order shown', 'snt-rsm-list', 'Move up', 'Move down', '<os-disclosure heading="+ Add' ) as $gone ) {
	ok( false === strpos( $kit . $kit_all, $gone ), 'the hand-rolled chrome is gone: ' . $gone );
}
ok( $forms === snt_leaf_actions( $edits['experience'] ), 'the repeater posts nothing: add, remove and move are DOM operations, the one action is still resume_draft_save' );
// Negative control: the action pin at the top can fail. A planted per-row
// server action would be a second action.
ok( array( 'resume_draft_save', 'resume_move', 'resume_operations_setup', 'resume_pdf_generate' ) === snt_leaf_actions( $edits['experience'] . '<os-button os-action="post" os-arg-action="sn_resume_move">Up</os-button>' ), 'the action pin discriminates: a planted resume_move os-button reads as a second action' );
// The script side: the three repeater events, and what each does to the DOM.
$js = (string) file_get_contents( SNT_PATH . 'assets/resume-admin.js' );
ok( false !== strpos( $js, "document.addEventListener( 'click'" ) && false !== strpos( $js, "'data-rsm-add'" ) && false !== strpos( $js, "'sn-rsm-up'" ), 'resume-admin.js keeps the classic page\'s click listener (data-rsm-add, sn-rsm-up)' );
foreach ( array( 'os-repeater-add', 'os-repeater-remove', 'os-repeater-move' ) as $ev ) {
	ok( false !== strpos( $js, "document.addEventListener( '" . $ev . "'" ), 'resume-admin.js listens for ' . $ev );
}
ok( preg_match( '/os-repeater-move.*?rep\.insertBefore\( row, /s', $js ) === 1, 'a move MOVES the slotted node (os-form collects fields in light-DOM order)' );
ok( preg_match( '/os-repeater-add.*?rep\.insertBefore\( tpl\.content\.cloneNode\( true \), tpl \)/s', $js ) === 1 && preg_match( '/os-repeater-add.*?rewriteTokens\( row, tpl\.getAttribute\( \'data-rsm-token\' \), key \)/s', $js ) === 1, 'an add clones the list\'s <template> before it and rewrites the token to the new key' );
ok( preg_match( '/os-repeater-remove.*?rep\.removeChild\( row \)/s', $js ) === 1, 'a remove drops the slotted node' );
ok( preg_match( '/function setKeys[^}]*rep\.keys = keys;[^}]*setAttribute\( \'os-prop-keys\', JSON\.stringify\( keys \) \)[^}]*setAttribute\( \'os-key\'/s', $js ) === 1, 'every change writes keys, the os-prop-keys attribute and a fresh os-key (applyProps skips an unchanged prop string; a fresh key makes the next render replace the element)' );
ok( false !== strpos( $js, "'slot', 'os-key' ]" ), 'the clone-time token rewrite reaches slot and os-key, so a cloned row is slotted under its new key' );
// Focus after a move and an add lands on the field's shadow INPUT, never the
// host: os-text-field / os-textarea (OpenStation 1.1.10) have no
// delegatesFocus and no focus() override, so host.focus() is a no-op and a
// keyboard reorder dropped focus to body (a second Alt+Arrow did nothing).
ok( preg_match( '/os-repeater-move.*?var inner\s*=\s*active && active\.shadowRoot \? active\.shadowRoot\.activeElement : null;.*?rep\.insertBefore\( row, /s', $js ) === 1, 'a move reads the host\'s shadowRoot.activeElement BEFORE the insertBefore blurs it' );
ok( preg_match( '/os-repeater-move.*?\( inner \|\| active \)\.focus\(\)/s', $js ) === 1 && 0 === preg_match( '/os-repeater-move.*?\bactive\.focus\(\)/s', $js ), 'a move focuses the shadow input it read, falling back to the host; never the host alone' );
ok( preg_match( '/function focusField\( host \)[^}]*host\.shadowRoot\.querySelector\( \'input, textarea\' \)[^}]*\.focus\(\)/s', $js ) === 1, 'focusField() reaches into the host\'s shadow root for the input or textarea (the health-suggest focusButton shape)' );
ok( preg_match( '/os-repeater-add.*?queueMicrotask\( function \(\) \{\s*focusField\( first \);/s', $js ) === 1 && 0 === preg_match( '/\'os-repeater-add\'.*?first\.focus\(\).*?\'os-repeater-remove\'/s', $js ), 'an add focuses the clone\'s field through focusField on a microtask, behind the runtime\'s first render of the clone; never first.focus() on the host (the classic light-DOM path above keeps its own)' );
// The round trip through the HANDLER: a DOM move changes element order, never
// a name, so the post arrives with stats[1] before stats[0] and the handler
// (no move logic, no ksort) saves the order posted. The post is the Stats
// editing paint's: its rows as fields, every other section as carriers.
$values = $scrape( $edits['stats'] );
$moved  = array();
foreach ( $values as $k => $v ) {
	if ( 0 === strpos( $k, 'resume[stats][1]' ) ) {
		$moved[ $k ] = $v;
	}
}
foreach ( $values as $k => $v ) {
	if ( 0 !== strpos( $k, 'resume[stats][1]' ) ) {
		$moved[ $k ] = $v;
	}
}
$saved = $spawn( $handler_boot, array( 'resume' => snt_os_host_expand( $moved )['resume'] ?? array() ) );
$doc   = is_array( $saved ) ? (array) ( $saved[1] ?? array() ) : array();
unset( $doc['updated'] );
$want_moved = $want;
$want_moved['stats'] = array( $want['stats'][1], $want['stats'][0], $want['stats'][2], $want['stats'][3] );
ok( is_array( $saved ) && 'resume_draft_saved' === $saved[0] && array( 'sn_resume_draft' ) === $saved[2] && $doc === $want_moved, 'a moved row posts through the real handler and saves the DRAFT (and only the draft) in the order shown: stats 1 and 0 swapped, every other section byte-identical' );
$control = $spawn( $handler_boot, array( 'resume' => snt_os_host_expand( $values )['resume'] ?? array() ) );
$cdoc    = is_array( $control ) ? (array) ( $control[1] ?? array() ) : array();
unset( $cdoc['updated'] );
ok( is_array( $control ) && 'resume_draft_saved' === $control[0] && $cdoc === $want, 'control: the unmoved post saves in the painted order through the same handler' );

// ── The seed prefills the kit fields (as a field when its section is
// edited, as a hidden carrier when another is).
ok( false !== strpos( $edits['experience'], 'name="resume[experience][0][org]" type="text" value="INDEPENDENT PRACTICE"' ) && false !== strpos( $edits['stats'], '<input type="hidden" name="resume[experience][0][org]" value="INDEPENDENT PRACTICE">' ), 'seed org prefilled, and carried while Stats is edited' );
ok( false !== strpos( $edits['stats'], 'name="resume[stats][0][n]" type="text" value="15+"' ) && false !== strpos( $edits['stats'], 'value="Years in the industry"' ), 'seed stat prefilled' );
ok( preg_match( '/name="resume\[experience\]\[0\]\[roles\]\[0\]\[bullets\]" value="[^"]*roughly 110 tracks/', $edits['experience'] ), 'seed bullets prefilled as one textarea, one per line' );
ok( false !== strpos( $edits['hero'], '<os-textarea name="resume[hero][chips]" value="' . snt_kit_esc( implode( "\n", $seed['hero']['chips'] ) ) . '"' ) && false !== strpos( $edits['skills'], '<input type="hidden" name="resume[hero][chips]" value="' . snt_kit_esc( implode( "\n", $seed['hero']['chips'] ) ) . '">' ), 'seed chips prefilled one per line, and carried with the newlines intact' );
ok( false !== strpos( $edits['publications'], 'name="resume[publications][0][url]" type="text" value="https://ssrn.com/abstract=6402298"' ), 'seed publication URL prefilled' );
ok( false !== strpos( $edits['skills'], 'name="resume[skills][5][items]" value="' ), 'sixth skills row prefilled' );
ok( false !== strpos( $edits['earlier'], 'name="resume[earlier][label]" type="text" value="' . snt_kit_esc( $seed['earlier']['label'] ) . '"' ), 'earlier fold label prefilled' );
ok( false !== strpos( $kit_all, 'placeholder="Role · Jan 2020 - Present"' ) && false !== strpos( $kit_all, 'placeholder="https://ssrn.com/abstract=…"' ), 'the classic placeholders survive' );
ok( false === strpos( $edits['stats'], 'name="resume[pdf][phone_public]"' ), 'phone_public off: no carrier at all, as a browser posts an unchecked box' );

// ── Intro, unsaved: the /resume link and the first-save takeover.
ok( false !== strpos( $kit, 'prefilled from the current published content' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/resume"' ) && false === strpos( $kit, 'Last published' ), 'unsaved intro explains the first-save takeover and links /resume (as a door, 14.7.5)' );
ok( false !== strpos( $kit, '<os-section heading="Resume page"' ), 'the leaf opens with one Resume page section' );

// ── Intro, saved.
$GLOBALS['__resume_doc'] = array( 'updated' => '2026-08-03' ) + $seed;
$kit = snt_leaf_paint( 'content', 'resume' );
ok( false !== strpos( $kit, 'Saving stores a draft; nothing goes live until you publish it.' ) && false !== strpos( $kit, 'Last published: <os-code>2026-08-03</os-code>.' ) && false === strpos( $kit, 'prefilled from' ), 'saved intro names the stamp as kit code' );

// ── A draft exists (and a previous version): the form paints the DRAFT, the
// status line says so, and Preview page / Preview PDF / Publish / Discard /
// Revert appear on BOTH surfaces with the same actions.
$live_doc  = array( 'updated' => '2026-08-03' ) + $seed;
$draft_doc = $live_doc;
$draft_doc['updated']                 = '2026-09-30';
$draft_doc['experience'][0]['org']    = 'DRAFT PRACTICE';
$GLOBALS['__resume_doc']   = $live_doc;
$GLOBALS['__resume_draft'] = $draft_doc;
$GLOBALS['__resume_prev']  = $live_doc;
$classic  = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit      = snt_leaf_paint( 'content', 'resume' );
$kit_edit = $paint_edit( 'experience' );
$want_actions = array( 'resume_discard', 'resume_draft_save', 'resume_operations_setup', 'resume_pdf_generate', 'resume_publish', 'resume_revert' );
ok( $want_actions === snt_leaf_actions( $kit_edit ) && $want_actions === snt_leaf_actions( $classic ) && array_values( array_diff( $want_actions, array( 'resume_draft_save' ) ) ) === snt_leaf_actions( $kit ), 'draft + previous: publish, discard and revert join the editor and PDF actions on both surfaces (kit, editing: ' . implode( ',', snt_leaf_actions( $kit_edit ) ) . '; the default paint has all but Save draft)' );
ok( false !== strpos( $kit_edit, 'value="DRAFT PRACTICE"' ) && false !== strpos( $classic, 'value="DRAFT PRACTICE"' ) && false === strpos( $kit_edit, 'value="INDEPENDENT PRACTICE"' ) && 'DRAFT PRACTICE' === ( snt_leaf_tables( $kit )[0]['data'][2]['first'] ?? '' ), 'with a draft, both forms paint the draft, not the live document, and the table names it' );
ok( false !== strpos( $kit, 'Draft saved 2026-09-30; differs from live.' ) && false !== strpos( $classic, 'Draft saved 2026-09-30; differs from live.' ), 'the status line reports the draft on both surfaces' );
$kit_dec = html_entity_decode( $kit, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
foreach ( array( 'resume_preview_page', 'resume_preview_pdf', 'resume_pdf_private' ) as $pv ) {
	$url = 'admin-post.php?action=sn_' . $pv . '&page=sn-content&tab=content&sub=resume&_wpnonce=nonce-sn_' . $pv;
	ok( false !== strpos( $kit_dec, 'os-action="door"' ) && false !== strpos( $kit_dec, $url ) && false !== strpos( html_entity_decode( $classic ), 'target="_blank" rel="noopener" href="https://example.test/wp-admin/' . $url ), $pv . ': a door (kit) and a new-tab link (classic) to admin-post.php with its own nonce and the Content page named' );
}
ok( 6 === substr_count( $kit_edit, '<os-form' ) && false !== strpos( $kit_edit, 'submit-label="Publish"' ) && false !== strpos( $kit_edit, 'os-confirm=' ), 'kit: Publish, Discard, Revert are one-button os-forms behind a confirm (6 os-forms with Save draft, Generate PDF and the operations setup)' );
// 2026-10-16 leaf anatomy: three lone buttons took a full row each; they are
// compact inline forms side by side in one os-cluster, Discard a danger outline.
ok( 1 === preg_match( '#<os-cluster gap="8"><os-form class="snt-form snt-form--inline"[^>]*submit-label="Publish" show-reset="false" columns="auto" os-confirm="Publish the draft to the live /resume page\?">.*?</os-form><os-form class="snt-form snt-form--inline"[^>]*submit-label="Discard draft"[^>]*os-confirm-danger>.*?</os-form><os-form class="snt-form snt-form--inline"[^>]*submit-label="Revert to previous"[^>]*>.*?</os-form></os-cluster></os-section>#s', $kit ) && 3 === substr_count( $kit, 'snt-form--inline' ), 'kit: Publish, Discard and Revert sit side by side as inline forms in one cluster, closing the Draft box' );
$GLOBALS['__resume_draft'] = null;
$kit = snt_leaf_paint( 'content', 'resume' );
ok( array( 'resume_draft_save', 'resume_operations_setup', 'resume_pdf_generate', 'resume_revert' ) === snt_leaf_actions( $paint_edit( 'hero' ) ) && array( 'resume_operations_setup', 'resume_pdf_generate', 'resume_revert' ) === snt_leaf_actions( $kit ) && false === strpos( $kit, 'resume_preview_page' ), 'previous version but no draft: only Revert joins; no preview, publish or discard' );
$GLOBALS['__resume_prev'] = null;
// An UNREADABLE stored draft: Discard paints on both surfaces so it can be
// cleared; Publish and the previews stay hidden.
$GLOBALS['__resume_draft_unreadable'] = true;
$classic  = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit      = snt_leaf_paint( 'content', 'resume' );
$kit_edit = $paint_edit( 'skills' );
ok( array( 'resume_discard', 'resume_draft_save', 'resume_operations_setup', 'resume_pdf_generate' ) === snt_leaf_actions( $kit_edit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit_edit ) && false === strpos( $kit . $kit_edit . $classic, 'resume_preview_page' ), 'unreadable draft: only Discard joins, on both surfaces; no publish, no preview' );
$GLOBALS['__resume_draft_unreadable'] = false;

// ── Escaping: a hostile summary never reaches the markup raw.
$hostile = $seed;
$hostile['hero']['summary'] = '"><script>x</script>';
$GLOBALS['__resume_doc'] = $hostile;
$kit      = snt_leaf_paint( 'content', 'resume' );
$kit_edit = $paint_edit( 'hero' ) . $paint_edit( 'stats' );
ok( false === strpos( $kit . $kit_edit, '<script>' ) && false !== strpos( $kit_edit, '&lt;script&gt;' ), 'a hostile summary is escaped in the table, in its field and in its carrier' );

// ── Bare document: empty lists still paint their table row and, when
// edited, the blank row; names still match.
$bare = array(
	'updated'      => '',
	'hero'         => array( 'summary' => '', 'chips' => array(), 'contact_line' => '', 'linkedin' => '', 'pdf_url' => '', 'pdf_label' => '' ),
	'stats'        => array(),
	'experience'   => array( array( 'org' => 'ONE', 'dates' => '', 'location' => '', 'roles' => array( array( 'title' => 'Role', 'bullets' => array() ) ) ) ),
	'earlier'      => array( 'label' => '', 'entries' => array() ),
	'education'    => array(),
	'affiliations' => array(),
	'publications' => array(),
	'skills'       => array(),
);
$GLOBALS['__resume_doc'] = $bare;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
$edits   = $paint_all();
ok( snt_leaf_names( $classic ) === snt_leaf_names( implode( '', $edits ) ), 'bare: field names across the editing paints still match (' . count( snt_leaf_names( implode( '', $edits ) ) ) . ' names)' );
ok( '0' === ( snt_leaf_tables( $kit )[0]['data'][1]['entries'] ?? '' ) && false !== strpos( $edits['stats'], 'name="resume[stats][__S__][n]"' ), 'bare: an empty list says 0 (a stored count, measured) and, edited, still carries its template' );
ok( false !== strpos( $edits['stats'], '<os-repeater os-key="resume[stats]" reorderable add-label="+ Add stat" row-label="stat" row-label-from="label" empty-text="No rows yet." os-prop-keys="[]">' ), 'bare: an empty list paints os-prop-keys="[]", never a phantom "0" row around nothing' );
ok( array() === $folds_in( array( 'default' => $kit ) + $edits ), 'bare: fold-free on every paint' );

// ── The hard failure state: no document, no seed — no form.
$GLOBALS['__resume_doc'] = null;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'The resume editor is unavailable: no stored document and no readable seed.' ) && $paint_edit( 'experience' ) === $kit, 'unavailable: the classic message paints as an empty state, editing or not' );
ok( false === strpos( $kit, '<os-form' ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ) && array() === snt_leaf_names( $kit ), 'unavailable: no form, no action, no field — as the classic early return' );

// ── Data safety. Every editing paint posts ONE section as fields and the
// other ten as hidden inputs; the handler rebuilds the WHOLE document from
// that post. So each of the eleven posts goes through the REAL
// sn_handle_resume_draft_save() and must store the document unchanged. The
// fixture is the seed made harder: the phone published (a true boolean that
// must ride along), every PDF-only field set, an empty list, values with
// quotes, ampersands, angle brackets and newlines, and since 2026-11-01 a
// "Shows in" on every kind of row, two per-bullet flags, What I've Built,
// and both variant blocks with their boxes checked.
$rich = $seed;
unset( $rich['updated'] );
$rich['pdf']       = array( 'headline' => 'Design Engineering Lead', 'tagline' => 'Systems | Research', 'summary' => "PDF summary, line one.\nLine two.", 'phone' => '(407) 555-0100', 'phone_public' => true, 'email' => 'name@example.com', 'location' => 'Orlando, FL', 'website' => 'https://example.org', 'competencies' => array( 'Design systems', 'Research ops' ), 'toolkit' => array( 'Figma', 'PHP' ) );
$rich['education'] = array();
$rich['hero']['chips'][] = 'A chip with "quotes" & <angle> brackets';
$rich['experience'][0]['roles'][0]['bullets'][] = 'A bullet with <strong>markup</strong> & an ampersand';
$rich['experience'][0]['variant']                     = 'music';
$rich['experience'][1]['roles'][1]['variant']         = 'operations';
$rich['earlier']['entries'][0]['variant']             = 'music';
$rich['earlier']['entries'][0]['roles'][0]['variant'] = 'operations';
$rich['affiliations'][0]['variant']                   = 'operations';
$rich['publications'][1]['variant']                   = 'music';
$rich['skills'][0]['variant']                         = 'operations';
$rich['built']    = array(
	'intro' => 'Built with "care" & a plan',
	'items' => array(
		array( 'title' => 'Signal & Noise Tools', 'url' => 'https://github.com/juanlentino/signal-and-noise-tools', 'text' => 'A WordPress plugin with <angle> brackets.', 'variant' => 'operations' ),
		array( 'title' => 'OpenStation', 'url' => '', 'text' => '' ),
	),
);
$rich['variants'] = array(
	'music'      => array( 'show_built' => true ),
	'operations' => array( 'headline' => 'Founder and Operator', 'tagline' => 'AI-Native Business Operations | Partnerships', 'location' => 'Orlando, FL', 'links' => array( 'https://github.com/juanlentino', 'https://example.org/ops' ), 'summary' => "Ops summary, line one.\nLine two.", 'sections' => array( 'summary', 'built', 'experience', 'skills' ), 'experience_order' => array( 'PANACEA STUDIO', 'INDEPENDENT PRACTICE' ), 'earlier_compact' => true, 'pdf_file' => 'Ops_Resume.pdf' ),
);
$rich = $spawn( $boot, $rich );
// The bullet flags key on the NORMALIZED bullet, so they go on after one pass.
$b0 = (array) ( $rich['experience'][0]['roles'][0]['bullets'] ?? array() );
$rich['experience'][0]['roles'][0]['bullet_variants'] = array( sn_resume_bullet_key( (string) reset( $b0 ) ) => 'operations', sn_resume_bullet_key( (string) end( $b0 ) ) => 'music' );
$rich = $spawn( $boot, $rich );
ok( is_array( $rich ) && true === $rich['pdf']['phone_public'] && array() === $rich['education'] && 2 === count( $rich['pdf']['toolkit'] ), 'the rich fixture normalizes (phone public, Education empty, PDF lists set)' );
ok(
	is_array( $rich ) && 'music' === ( $rich['experience'][0]['variant'] ?? '' ) && 'operations' === ( $rich['experience'][1]['roles'][1]['variant'] ?? '' )
	&& 'music' === ( $rich['earlier']['entries'][0]['variant'] ?? '' ) && 'operations' === ( $rich['earlier']['entries'][0]['roles'][0]['variant'] ?? '' )
	&& 'operations' === ( $rich['affiliations'][0]['variant'] ?? '' ) && 'music' === ( $rich['publications'][1]['variant'] ?? '' ) && 'operations' === ( $rich['skills'][0]['variant'] ?? '' )
	&& 2 === count( $rich['experience'][0]['roles'][0]['bullet_variants'] ?? array() ) && 2 === count( $rich['built']['items'] ?? array() ) && 'operations' === ( $rich['built']['items'][0]['variant'] ?? '' )
	&& true === ( $rich['variants']['music']['show_built'] ?? null ) && true === ( $rich['variants']['operations']['earlier_compact'] ?? null ) && 'Ops_Resume.pdf' === ( $rich['variants']['operations']['pdf_file'] ?? '' ) && 4 === count( $rich['variants']['operations']['sections'] ?? array() ),
	'the rich fixture keeps every variant flag through the real normalize (rows, roles, two bullets, What I\'ve Built, both variant blocks), so the round trip below is not vacuous'
);
$GLOBALS['__resume_doc'] = array( 'updated' => '' ) + (array) $rich;
$edits = $paint_all();
$lost  = array();
$kept  = array();
foreach ( $edits as $k => $h ) {
	$saved = $spawn( $handler_boot, array( 'resume' => $posted( $h )['resume'] ?? array() ) );
	$doc   = is_array( $saved ) ? (array) ( $saved[1] ?? array() ) : array();
	unset( $doc['updated'] );
	$kept[ $k ] = $doc;
	if ( ! is_array( $saved ) || 'resume_draft_saved' !== $saved[0] || $doc !== $rich ) {
		$lost[] = $k;
	}
}
ok( array() === $lost, 'data safety: the post from each of the eleven editing paints saves the document unchanged through the real handler (nothing lost, nothing changed): ' . implode( ',', $lost ) );
ok( ( $rich['variants'] ?? null ) === ( $kept['stats']['variants'] ?? false ) && ( $rich['built'] ?? null ) === ( $kept['skills']['built'] ?? false ) && ( $rich['experience'] ?? null ) === ( $kept['variants']['experience'] ?? false ), 'the saved draft carries back the variant blocks (Stats edited), What I\'ve Built (Skills edited) and every row and bullet flag (Operations résumé edited)' );
// The kit paints each flag on its control, and carries it while another section is edited.
foreach ( array( 'experience' => 'resume[experience][0][variant]" value="music"', 'earlier' => 'resume[earlier][entries][0][roles][0][variant]" value="operations"', 'affiliations' => 'resume[affiliations][0][variant]" value="operations"', 'publications' => 'resume[publications][1][variant]" value="music"', 'skills' => 'resume[skills][0][variant]" value="operations"', 'built' => 'resume[built][items][0][variant]" value="operations"' ) as $k => $frag ) {
	ok( false !== strpos( $edits[ $k ], '<os-select name="' . $frag ) && false !== strpos( $edits['hero'], '<input type="hidden" name="' . $frag . '>' ), 'a flag paints on its os-select and rides along as a carrier: ' . $frag );
}
$bk = sn_resume_bullet_key( (string) reset( $b0 ) );
ok( false !== strpos( $edits['experience'], '<os-select name="resume[experience][0][roles][0][bullet_variants][' . $bk . ']" value="operations"' ) && false !== strpos( $edits['stats'], '<input type="hidden" name="resume[experience][0][roles][0][bullet_variants][' . $bk . ']" value="operations">' ), 'a bullet flag paints on its own select and rides along as a carrier' );
ok( false !== strpos( $edits['stats'], '<input type="hidden" name="resume[variants][music][show_built]" value="1">' ) && false !== strpos( $edits['stats'], '<input type="hidden" name="resume[variants][operations][earlier_compact]" value="1">' ) && 1 === preg_match( '/<os-checkbox-label name="resume\[variants\]\[music\]\[show_built\]"[^>]* checked/', $edits['pdf'] ) && 1 === preg_match( '/<os-checkbox-label name="resume\[variants\]\[operations\]\[earlier_compact\]"[^>]* checked/', $edits['variants'] ), 'both variant boxes are checkboxes, checked when on, carried as 1 while another section is edited' );
// The guard can fail: the Stats post without the variant carriers loses every flag.
$cut   = preg_replace( '#<input type="hidden" name="resume\[(?:built|variants)\][^>]*>|<input type="hidden" name="[^"]*\[(?:variant|bullet_variants)\][^"]*"[^>]*>#', '', $edits['stats'] );
$saved = $spawn( $handler_boot, array( 'resume' => $posted( $cut )['resume'] ?? array() ) );
$doc   = is_array( $saved ) ? (array) ( $saved[1] ?? array() ) : array();
ok( is_array( $saved ) && 'resume_draft_saved' === $saved[0] && ! isset( $doc['built'] ) && ! isset( $doc['variants'] ) && ! isset( $doc['experience'][0]['variant'] ) && ! isset( $doc['experience'][0]['roles'][0]['bullet_variants'] ) && ! isset( $doc['skills'][0]['variant'] ) && $doc['stats'] === $rich['stats'], 'negative control: without the variant carriers, saving Stats would drop What I\'ve Built, both variant blocks and every row and bullet flag (the round trip above discriminates)' );
ok( false !== strpos( $edits['stats'], '<input type="hidden" name="resume[pdf][phone_public]" value="1">' ), 'phone_public on: carried as 1 while another section is edited' );
// The guard can fail: the Stats post without the Hero and PDF carriers.
$cut   = preg_replace( '#<input type="hidden" name="resume\[(?:hero|pdf)\][^>]*>#', '', $edits['stats'] );
$saved = $spawn( $handler_boot, array( 'resume' => $posted( $cut )['resume'] ?? array() ) );
$doc   = is_array( $saved ) ? (array) ( $saved[1] ?? array() ) : array();
ok( isset( $doc['hero'] ) && '' === $doc['hero']['summary'] && false === $doc['pdf']['phone_public'] && $doc['stats'] === $rich['stats'], 'negative control: without its carriers, saving Stats would blank the Hero and turn the phone off (the round trip above discriminates)' );

// ── Live-shaped preview: eleven sections, 41 entries, a senior design and
// engineering résumé.
$orgs = array( 'NORTHWIND LABS' => 2, 'FIELDNOTE STUDIO' => 2, 'MERIDIAN SYSTEMS' => 1, 'PARALLAX HEALTH' => 2, 'ORBIT MEDIA GROUP' => 1, 'KITE & KEY AGENCY' => 1 );
$live = array(
	'hero'         => array( 'summary' => 'Design engineer and systems lead with 18 years across product, research and front-end architecture. I build the tools teams design with, then measure whether they help.', 'chips' => array( 'IxDA member', 'MS · Human-Computer Interaction', 'WCAG 2.2 practitioner', 'Speaker, Config 2025', 'Open-source maintainer' ), 'contact_line' => 'Orlando, FL', 'linkedin' => 'https://www.linkedin.com/in/example', 'pdf_url' => 'https://example.test/resume.pdf' ),
	'stats'        => array( array( 'n' => '18', 'label' => 'Years in product design' ), array( 'n' => '40+', 'label' => 'Shipped products' ), array( 'n' => '12', 'label' => 'Design systems built' ), array( 'n' => '9', 'label' => 'Patents and papers' ) ),
	'experience'   => array(),
	'earlier'      => array( 'label' => 'Earlier career · 2004 - 2012', 'entries' => array() ),
	'education'    => array( array( 'title' => 'MS, Human-Computer Interaction', 'lines' => array( 'Georgia Tech · Atlanta · 2012' ) ), array( 'title' => 'BFA, Graphic Design', 'lines' => array( 'Ringling College · Sarasota · 2004' ) ), array( 'title' => 'Certificate, Applied Machine Learning', 'lines' => array( 'Online · 2024' ) ) ),
	'affiliations' => array(),
	'publications' => array(),
	'skills'       => array(),
	'pdf'          => array( 'headline' => 'Principal Design Engineer', 'tagline' => 'Design systems | Research | Front-end architecture', 'summary' => '', 'phone' => '(407) 555-0100', 'phone_public' => false, 'email' => 'name@example.com', 'location' => 'Orlando, FL', 'website' => '', 'competencies' => array( 'Design systems', 'Accessibility', 'Prototyping' ), 'toolkit' => array( 'Figma', 'TypeScript', 'PHP' ) ),
);
foreach ( $orgs as $org => $n_role ) {
	$roles = array();
	for ( $r = 0; $r < $n_role; $r++ ) {
		$roles[] = array( 'title' => ( 0 === $r ? 'Principal Design Engineer' : 'Senior Product Designer' ) . ' · ' . ( 2022 - 2 * count( $live['experience'] ) - $r ) . ' - ' . ( 0 === $r ? 'Present' : '2022' ), 'bullets' => array( 'Led the design system used by 14 product teams.', 'Cut handoff defects by a third with a token pipeline.', 'Mentored six designers into lead roles.' ) );
	}
	$live['experience'][] = array( 'org' => $org, 'dates' => '2012 - Present', 'location' => 'Remote', 'roles' => $roles );
}
foreach ( array( 'BRIGHTLINE PRINT', 'HARBOR TYPE CO', 'ATLAS WEB WORKS', 'SUNDIAL INTERACTIVE', 'PIXEL & PRESS' ) as $org ) {
	$live['earlier']['entries'][] = array( 'org' => $org, 'roles' => array( array( 'title' => 'Designer · 2006 - 2010', 'bullets' => array( 'Print and early web work.' ) ) ) );
}
foreach ( array( 'IxDA, member', 'AIGA, member', 'W3C Community Group, participant', 'Design Systems Coalition, founder', 'ACM SIGCHI, member', 'Open UI, contributor' ) as $a ) {
	$live['affiliations'][] = array( 'title' => $a, 'lines' => array( 'Since 2015' ) );
}
for ( $p = 1; $p <= 8; $p++ ) {
	$live['publications'][] = array( 'meta' => 'Conference paper · 20' . ( 16 + $p ), 'title' => 'Measuring design-system adoption, part ' . $p, 'url' => 'https://example.org/papers/' . $p );
}
foreach ( array( 'Design' => 'Systems, interaction, visual', 'Research' => 'Interviews, diary studies, analytics', 'Front end' => 'TypeScript, Web Components, CSS', 'Back end' => 'PHP, Node, Postgres', 'Accessibility' => 'WCAG 2.2, screen-reader testing', 'Tooling' => 'Figma, Storybook, Playwright', 'Data' => 'SQL, dashboards, experiment design', 'Leadership' => 'Hiring, mentoring, roadmaps', 'Writing' => 'Specs, papers, talks' ) as $cat => $items ) {
	$live['skills'][] = array( 'category' => $cat, 'items' => $items );
}
$live = $spawn( $boot, $live );
$counts = is_array( $live ) ? count( $live['stats'] ) + count( $live['experience'] ) + count( $live['earlier']['entries'] ) + count( $live['education'] ) + count( $live['affiliations'] ) + count( $live['publications'] ) + count( $live['skills'] ) : 0;
$GLOBALS['__resume_doc'] = array( 'updated' => '2026-10-02' ) + (array) $live;
$kit      = snt_leaf_paint( 'content', 'resume' );
$kit_edit = $paint_edit( 'experience' );
ok( 41 === $counts && false !== strpos( $kit, '<p class="snt-prose">41 entries across 8 lists; Experience holds 6 employers with 9 roles.</p>' ), 'live-shaped: 41 entries across the eight lists (What I\'ve Built empty), read into the finding' );
ok( array() === $folds_in( array( 'default' => $kit, 'experience' => $kit_edit ) ) && false === strpos( $kit . $kit_edit, 'snt-ledger-more' ) && 11 === count( snt_leaf_tables( $kit )[0]['data'] ?? array() ), 'live-shaped: fold-free, eleven rows, never capped (no "+N more")' );
ok( 6 === preg_match_all( '/<os-card os-key="resume\[experience\]\[\d+\]" slot="row-\d+"/', $kit_edit ) && false !== strpos( $kit_edit, '<input type="hidden" name="resume[skills][8][items]" value="Specs, papers, talks">' ), 'live-shaped: editing Experience paints its six employers, the ninth skills row rides along hidden' );
snt_leaf_preview( 'content-resume', $kit );
snt_leaf_preview( 'content-resume-editing', $kit_edit );
$focus_problems = snt_leaf_edit_focus_problems( $kit, $kit_edit );
ok( array() === $focus_problems, 'a11y: Edit lands the keyboard on the one region, Cancel lands it back on the list: ' . implode( '; ', $focus_problems ) );

// ── 2026-10-12 (owner: "it's weird to click in 'edit' and something else
// opens"): the editor opens UNDER its row, not below the whole table.
$ip_def   = $kit;
$ip_ed    = $kit_edit;
$ip_table = ( snt_leaf_tables( $kit )[0] ?? array( 'data' => array(), 'attrs' => '' ) );
$ip_keys  = array_map( static function ( $v ) { return is_array( $v ) ? (string) ( $v['text'] ?? '' ) : (string) $v; }, array_column( $ip_table['data'], 'section' ) );
$ip_at    = array_search( 'Experience', $ip_keys, true );
$ip_split = snt_leaf_split_problems( $ip_ed, 'section', 'Experience', false === $ip_at ? null : ( $ip_keys[ $ip_at + 1 ] ?? null ) );
ok( false !== $ip_at && array() === $ip_split, 'edit in place: the editor opens under its row, the row marked selected, the table resuming with the next row after it: ' . implode( '; ', $ip_split ) );
ok( false !== strpos( $ip_ed, '>Editing</os-badge>' ), 'edit in place: the open row says Editing in words, not only by its accent bar' );
ok( 1 === preg_match( '/os-arg-anchor="(snt-row-[0-9a-f]{12})"[^>]*>Cancel/', $ip_ed, $ip_m ) && false !== strpos( $ip_def, 'id="' . $ip_m[1] . '" data-snt-focus' ), 'edit in place: Cancel lands back on the open row\'s own button' );
ok( false !== strpos( $ip_table['attrs'], 'class="snt-table-full"' ), 'edit in place: the list is not boxed in an inner scroller (the app caps os-table at 440px)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
