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
 * Run: php tests/os-leaf-content-resume.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's one reader, answering from a fixture so every state — the
// stored document, the seed, and NEITHER — is reachable.
$GLOBALS['__resume_doc'] = null;
function sn_resume_doc_get() { return $GLOBALS['__resume_doc']; }

require SNT_PATH . 'inc/admin-forms/resume-page.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-resume.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$seed = json_decode( (string) file_get_contents( SNT_PATH . 'inc/seed-content/resume-data.json' ), true );
ok( is_array( $seed ) && isset( $seed['experience'][1]['roles'][1] ), 'the shipped seed is the rich fixture (two employers, a second role)' );
$seed['updated'] = '';

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/resume'] ), 'the painter is registered under content/resume' );

// ── The seed, unsaved: the same names, the same action.
$GLOBALS['__resume_doc'] = $seed;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
ok( '' !== $kit, 'the kit leaf paints' );
// The phone switch (owner, 2026-09-22: "the toggle for the phone isn't
// working"). os-form reads only checkboxes as booleans; any other tag submits
// its static value, so an os-switch posts '1' in BOTH positions and would
// publish the phone on every save. The leaf must use a real checkbox.
$kit_now = $kit; // the first full paint, before later groups swap the stored document.
$kit_dec = html_entity_decode( $kit_now, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
ok( 1 === preg_match( '/<os-checkbox-label[^>]*name="resume\\[pdf\\]\\[phone_public\\]"[^>]*value="1"|<os-checkbox-label[^>]*value="1"[^>]*name="resume\\[pdf\\]\\[phone_public\\]"/', $kit_dec ), 'the phone switch is an os-checkbox-label posting 1 only when checked' );
ok( '' !== $kit_now && false === strpos( $kit_now, '<os-switch' ), 'the resume leaf carries no os-switch at all (os-form cannot read one as a boolean)' );
ok( false !== strpos( $kit_dec, 'name="resume[pdf][website]"' ), 'the Website field is on the leaf' );
ok( false !== strpos( $kit_dec, 'name="resume[pdf][summary]"' ), 'the Professional summary field is on the leaf' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form (' . count( snt_leaf_names( $kit ) ) . ' names): ' . implode( ',', array_diff( snt_leaf_names( $classic ), snt_leaf_names( $kit ) ) ) . ' missing; ' . implode( ',', array_diff( snt_leaf_names( $kit ), snt_leaf_names( $classic ) ) ) . ' extra' );
// Resume PDF (docs/RESUME-PDF.md): a second, SEPARATE form generates the PDF
// from the saved document; it posts no resume fields. The editor itself still
// has exactly one action.
ok( array( 'resume_pdf_generate', 'resume_pdf_private', 'resume_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the actions are resume_save plus the separate resume_pdf_generate and resume_pdf_private, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( ! preg_match( '/\sstyle="/', $kit ), 'no inline style= survives' );
ok( (bool) preg_match( '/\sstyle="/', $kit . '<p style="x">' ), 'the inline-style guard above discriminates (fails on a planted style=)' );
ok( 3 === substr_count( $kit, '<os-form' ) && false !== strpos( $kit, 'submit-label="Generate PDF"' ) && false !== strpos( $kit, 'submit-label="Download private copy (with phone)"' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'submit-label="Save resume"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'three os-forms (Save resume, Generate PDF, private copy) dispatching post through the admin-post pipeline, submit "Save resume"' );
ok( false !== strpos( $kit, 'name="resume[experience][1][roles][1][title]"' ) && false !== strpos( $kit, 'name="resume[earlier][entries][1][roles][1][title]"' ), 'nested role names survive two levels down in both Experience and Earlier career' );
ok( false !== strpos( $kit, 'name="resume[experience][__E__][roles][__R__][title]"' ) && false !== strpos( $kit, 'name="resume[experience][0][roles][__R__][bullets]"' ), 'the template token keys the classic bakes (__E__, __R__) are the blank rows\' names' );

// ── Every classic field LABEL and PLACEHOLDER survives. snt_leaf_names() only
// sees `name="…"` attributes, so a renamed <span class="sn-rsm-label"> or a
// blanked placeholder is invisible to the pins above — these two are not.
$c_lab = function ( $h ) {
	preg_match_all( '/<span class="sn-rsm-label">([^<]*)<\/span>|<label class="sn-field-label"[^>]*>([^<]*)<\/label>/', $h, $m );
	$o = array_values( array_unique( array_filter( array_merge( $m[1], $m[2] ) ) ) );
	sort( $o );
	return $o;
};
$k_lab = function ( $h ) {
	preg_match_all( '/\slabel="([^"]*)"/', $h, $m );
	$o = array_values( array_unique( $m[1] ) );
	sort( $o );
	return $o;
};
$ph = function ( $h ) {
	preg_match_all( '/placeholder="([^"]*)"/', $h, $m );
	$o = array_values( array_unique( array_filter( $m[1] ) ) );
	sort( $o );
	return $o;
};
ok( $c_lab( $classic ) === $k_lab( $kit ), 'every classic field label (' . count( $c_lab( $classic ) ) . ') is a kit label; missing: ' . implode( ' | ', array_diff( $c_lab( $classic ), $k_lab( $kit ) ) ) );
ok( $ph( $classic ) === $ph( $kit ), 'every classic placeholder survives; missing: ' . implode( ' | ', array_diff( $ph( $classic ), $ph( $kit ) ) ) );

// ── Round trip: the token-keyed blank rows sit in inert <template>s since
// #1598 and never post, but a row the owner adds and leaves blank does, and
// the name regex below reads through the templates, so this post carries
// every __TOKEN__ field. It is safe only because the real
// sn_resume_doc_normalize() prunes title/org-less rows back to the painted
// document. Run the REAL data-layer file in its own process (inc/resume-page.php
// unconditionally declares sn_resume_doc_get(), which collides with this
// suite's own fixture stub of the same name) so this pin exercises the actual
// production pruning, not a reimplementation of it.
require_once SNT_PATH . 'inc/openstation-host-pipelines.php';
$values = array();
preg_match_all( '/<(?:os-text-field|os-textarea|input)\b[^>]*>/', $kit, $tags );
foreach ( $tags[0] as $tag ) {
	if ( ! preg_match( '/\sname="([^"]+)"/', $tag, $n ) ) {
		continue;
	}
	preg_match( '/\svalue="([^"]*)"/', $tag, $v );
	$values[ html_entity_decode( $n[1], ENT_QUOTES ) ] = html_entity_decode( $v[1] ?? '', ENT_QUOTES );
}
$post          = snt_os_host_expand( $values );
$resume_posted = $post['resume'] ?? array();
$boot          = 'define(\'ABSPATH\',\'/\');'
	// sn_resume_string_list() delegates to sn_content_items_normalize() (the
	// admin layer's newline-splitter for a repeatable-of-plain-strings) when
	// it is loaded, and degrades to an array-only cast otherwise — load it so
	// the textarea-posted chips/bullets/lines split the same way a real
	// request's bootstrap would have them split.
	. 'require ' . var_export( SNT_PATH . 'inc/admin-post-actions/content.php', true ) . ';'
	. 'require ' . var_export( SNT_PATH . 'inc/resume-page.php', true ) . ';'
	. '$in=json_decode(file_get_contents("php://stdin"),true);'
	. 'echo json_encode(sn_resume_doc_normalize($in));';
// One child process per boot: the JSON on stdin is the posted `resume`
// array, the JSON on stdout is whatever the boot echoes.
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
$back  = $spawn( $boot, $resume_posted );
$want = $seed;
unset( $want['updated'] );
ok( is_array( $back ) && $back === $want, 'round trip: the live blank template rows the kit now posts (__S__/__E__/__R__/__X__/__Y__/__D__/__A__/__P__/__K__) are pruned by the real sn_resume_doc_normalize() — the saved document is byte-identical to the painted one' );

// ── Every section, in order, closed, with its count and its helper line.
$headings = array( 'Hero', 'Stats', 'Experience', 'Earlier career (collapsed fold)', 'Education', 'Affiliations & Certifications', 'Publications', 'Skills' );
$pos = -1; $ordered = true;
foreach ( $headings as $h ) {
	$at = strpos( $kit, '<os-disclosure heading="' . snt_kit_esc( $h ) . '"' );
	$ordered = $ordered && false !== $at && $at > $pos;
	$pos = false !== $at ? $at : $pos;
}
ok( $ordered, 'the eight sections paint as folds in the classic order' );
ok( false === strpos( $kit, '<os-disclosure open' ) && ! preg_match( '/<os-disclosure[^>]* open[ >]/', $kit ), 'every fold is closed by default, as the classic <details>' );
ok( false !== strpos( $kit, 'heading="Stats" hint="4"' ) && false !== strpos( $kit, 'heading="Experience" hint="2"' ) && false !== strpos( $kit, 'heading="Earlier career (collapsed fold)" hint="' . count( $seed['earlier']['entries'] ) . '"' ) && false !== strpos( $kit, 'heading="Education" hint="2"' ) && false !== strpos( $kit, 'heading="Affiliations &amp; Certifications" hint="4"' ) && false !== strpos( $kit, 'heading="Publications" hint="2"' ) && false !== strpos( $kit, 'heading="Skills" hint="6"' ), 'the row-count badges are the folds\' hints (4 / 2 / ' . count( $seed['earlier']['entries'] ) . ' / 2 / 4 / 2 / 6)' );
ok( preg_match( '/<os-disclosure heading="Hero"[^>]*>/', $kit, $m ) && false === strpos( $m[0], 'hint=' ), 'Hero carries no count, as the classic summary' );
foreach ( array( 'The opening band: summary, credential chips, contact line, and the PDF download.', 'The numbers strip under the hero.', 'Bullets may use &lt;strong&gt;, &lt;em&gt;, and links.', 'Rendered inside a collapsed &quot;details&quot; fold at the end of Experience.', 'A new paper is one row: venue line, title, and link.', 'One table row per category; items is the comma-separated cell.' ) as $hint ) {
	ok( false !== strpos( $kit, '<p class="snt-hint">' . $hint ) || false !== strpos( $kit, $hint . '</p>' ), 'helper line survives: ' . substr( $hint, 0, 40 ) );
}
// ── The lists are the kit's <os-repeater> (#1598), one per classic add
// button, reorderable, its rows slotted under their index and named in
// os-prop-keys, the classic <template> inert inside it. The hand-rolled
// chrome of 17.4.0 (sn-rsm-up / sn-rsm-down kit buttons with sr-only names,
// the data-rsm-row mark, the closed "+ Add" fold and its "blank a row" hint)
// is gone: the repeater paints "Move <row> up", "Remove <row>" and Add itself.
$classic_live = preg_replace( '#<template\b.*?</template>#s', '', $classic );
$kit_live     = preg_replace( '#<template\b.*?</template>#s', '', $kit );
$n_rows       = substr_count( $classic_live, 'sn-rsm-up' );
$adds         = array( '+ Add stat' => 'stat', '+ Add role' => 'role', '+ Add employer' => 'employer', '+ Add earlier employer' => 'employer', '+ Add education' => 'education entry', '+ Add affiliation' => 'affiliation', '+ Add publication' => 'publication', '+ Add skills row' => 'skills row' );
preg_match_all( '/<os-repeater [^>]*>/', $kit, $reps );
ok( $n_rows > 20 && count( $reps[0] ) === substr_count( $classic, 'sn-rsm-add' ) && count( $reps[0] ) === count( preg_grep( '/ reorderable /', $reps[0] ) ) && count( $reps[0] ) === count( preg_grep( '/ os-prop-keys="/', $reps[0] ) ), 'one reorderable <os-repeater> with os-prop-keys per classic add button (' . count( $reps[0] ) . ')' );
foreach ( $adds as $add => $noun ) {
	ok( count( preg_grep( '/ add-label="' . preg_quote( $add, '/' ) . '" row-label="' . preg_quote( $noun, '/' ) . '" /', $reps[0] ) ) > 0, 'the classic add button is the repeater\'s add-label, the row noun its row-label: ' . $add . ' / ' . $noun );
}
ok( false !== strpos( $kit, '<os-repeater os-key="resume[stats]" reorderable add-label="+ Add stat" row-label="stat" empty-text="No rows yet." os-prop-keys="[&quot;0&quot;,&quot;1&quot;,&quot;2&quot;,&quot;3&quot;]">' ), 'the Stats repeater names its four rows 0..3 in os-prop-keys (the runtime assigns keys after every render)' );
ok( preg_match( '/<os-repeater os-key="resume\[experience\]\[0\]\[roles\]" [^>]*row-label="role"[^>]*os-prop-keys="\[&quot;0&quot;(,&quot;1&quot;)?\]"/', $kit ) === 1, 'the roles under an employer are a nested repeater keyed on the employer prefix' );
ok( $n_rows === substr_count( $kit_live, ' slot="row-' ) && 0 === substr_count( $kit_live, ' slot="row-__' ) && $n_rows === preg_match_all( '/<os-card os-key="([^"]*\[(\d+)\])" slot="row-\2"/', $kit_live ), 'every live classic row is a kit card slotted under its own index, slot row-N matching the os-key\'s last segment, no token slot outside a template (' . $n_rows . ')' );
ok( preg_match_all( '/<template data-rsm-tpl data-rsm-token="(__[A-Z]__)">(?:(?!<\/template>).)*? slot="row-\1"/s', $kit ) === count( $reps[0] ) && substr_count( $kit, '<template data-rsm-tpl data-rsm-token="__R__">' ) === count( $seed['experience'] ) + 1, 'each repeater ends in the classic template, its blank row slotted under the token, one __R__ template per employer plus the one inside the __E__ employer template' );
ok( count( $reps[0] ) === substr_count( $kit, '</template></os-repeater>' ), 'the template is the repeater\'s LAST child, so an added row inserted before it keeps DOM order equal to display order' );
foreach ( array( 'sn-rsm-up', 'sn-rsm-down', 'snt-sr-only', 'data-rsm-row', 'Blank a row and save', 'rows keep the order shown', 'snt-rsm-list', 'Move up', 'Move down', '<os-disclosure heading="+ Add' ) as $gone ) {
	ok( false === strpos( $kit, $gone ), 'the hand-rolled chrome is gone: ' . $gone );
}
ok( array( 'resume_pdf_generate', 'resume_pdf_private', 'resume_save' ) === snt_leaf_actions( $kit ), 'the repeater posts nothing: add, remove and move are DOM operations, the one action is still resume_save' );
// Negative control: the action pin at the top can fail. A planted per-row
// server action would be a second action.
ok( array( 'resume_move', 'resume_pdf_generate', 'resume_pdf_private', 'resume_save' ) === snt_leaf_actions( $kit . '<os-button os-action="post" os-arg-action="sn_resume_move">Up</os-button>' ), 'the action pin discriminates: a planted resume_move os-button reads as a second action' );
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
// (no move logic, no ksort) saves the order posted.
$moved = array();
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
$handler_boot = 'define(\'ABSPATH\',\'/\');'
	. 'function wp_unslash($v){return $v;} function get_option($k){return false;} function update_option($k,$v){$GLOBALS[\'saved\']=$v;return true;}'
	. 'require ' . var_export( SNT_PATH . 'inc/admin-post-actions/content.php', true ) . ';'
	. 'require ' . var_export( SNT_PATH . 'inc/resume-page.php', true ) . ';'
	. '$in=json_decode(file_get_contents("php://stdin"),true);'
	. 'echo json_encode(array(sn_handle_resume_save($in),$GLOBALS[\'saved\']??null));';
$saved = $spawn( $handler_boot, array( 'resume' => snt_os_host_expand( $moved )['resume'] ?? array() ) );
$doc   = is_array( $saved ) ? (array) ( $saved[1] ?? array() ) : array();
unset( $doc['updated'] );
$want_moved = $want;
$want_moved['stats'] = array( $want['stats'][1], $want['stats'][0], $want['stats'][2], $want['stats'][3] );
ok( is_array( $saved ) && 'resume_saved' === $saved[0] && $doc === $want_moved, 'a moved row posts through the real handler and saves in the order shown: stats 1 and 0 swapped, every other section byte-identical' );
$control = $spawn( $handler_boot, array( 'resume' => $resume_posted ) );
$cdoc    = is_array( $control ) ? (array) ( $control[1] ?? array() ) : array();
unset( $cdoc['updated'] );
ok( is_array( $control ) && 'resume_saved' === $control[0] && $cdoc === $want, 'control: the unmoved post saves in the painted order through the same handler' );

// ── The seed prefills the kit fields.
ok( false !== strpos( $kit, 'name="resume[experience][0][org]" type="text" value="INDEPENDENT PRACTICE"' ), 'seed org prefilled' );
ok( false !== strpos( $kit, 'name="resume[stats][0][n]" type="text" value="20+"' ) && false !== strpos( $kit, 'value="Years in the industry"' ), 'seed stat prefilled' );
ok( preg_match( '/name="resume\[experience\]\[0\]\[roles\]\[0\]\[bullets\]" value="[^"]*roughly 110 releases/', $kit ), 'seed bullets prefilled as one textarea, one per line' );
ok( false !== strpos( $kit, 'name="resume[hero][chips]" value="' . snt_kit_esc( implode( "\n", $seed['hero']['chips'] ) ) . '"' ), 'seed chips prefilled one per line' );
ok( false !== strpos( $kit, 'name="resume[publications][0][url]" type="text" value="https://ssrn.com/abstract=6402298"' ), 'seed publication URL prefilled' );
ok( false !== strpos( $kit, 'name="resume[skills][5][items]" value="' ), 'sixth skills row prefilled' );
ok( false !== strpos( $kit, 'name="resume[earlier][label]" type="text" value="' . snt_kit_esc( $seed['earlier']['label'] ) . '"' ), 'earlier fold label prefilled' );
ok( false !== strpos( $kit, 'placeholder="Role · Jan 2020 - Present"' ) && false !== strpos( $kit, 'placeholder="https://ssrn.com/abstract=…"' ), 'the classic placeholders survive' );

// ── Intro, unsaved: the /resume link and the first-save takeover.
ok( false !== strpos( $kit, 'prefilled from the current published content' ) && false !== strpos( $kit, 'os-arg-url="https://example.test/resume"' ) && false === strpos( $kit, 'Last saved' ), 'unsaved intro explains the first-save takeover and links /resume (as a door, 14.7.5)' );
ok( false !== strpos( $kit, '<os-section heading="Resume page"' ), 'the leaf is one Resume page section' );

// ── Intro, saved.
$GLOBALS['__resume_doc'] = array( 'updated' => '2026-08-03' ) + $seed;
$kit = snt_leaf_paint( 'content', 'resume' );
ok( false !== strpos( $kit, 'Saving regenerates it.' ) && false !== strpos( $kit, 'Last saved: <os-code>2026-08-03</os-code>.' ) && false === strpos( $kit, 'prefilled from' ), 'saved intro names the stamp as kit code' );

// ── Escaping: a hostile summary never reaches the markup raw.
$hostile = $seed;
$hostile['hero']['summary'] = '"><script>x</script>';
$GLOBALS['__resume_doc'] = $hostile;
$kit = snt_leaf_paint( 'content', 'resume' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile summary is escaped' );

// ── Bare document: empty lists still paint their fold with the blank row, and names still match.
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
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'bare: field names still match (' . count( snt_leaf_names( $kit ) ) . ' names)' );
ok( false !== strpos( $kit, 'heading="Stats" hint="0"' ) && false !== strpos( $kit, 'name="resume[stats][__S__][n]"' ), 'bare: an empty list says 0 and still carries its template' );
ok( false !== strpos( $kit, '<os-repeater os-key="resume[stats]" reorderable add-label="+ Add stat" row-label="stat" empty-text="No rows yet." os-prop-keys="[]">' ), 'bare: an empty list paints os-prop-keys="[]", never a phantom "0" row around nothing' );

// ── The hard failure state: no document, no seed — no form.
$GLOBALS['__resume_doc'] = null;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'The resume editor is unavailable: no stored document and no readable seed.' ), 'unavailable: the classic message paints as an empty state' );
ok( false === strpos( $kit, '<os-form' ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ) && array() === snt_leaf_names( $kit ), 'unavailable: no form, no action, no field — as the classic early return' );


echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
