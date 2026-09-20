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
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic form (' . count( snt_leaf_names( $kit ) ) . ' names): ' . implode( ',', array_diff( snt_leaf_names( $classic ), snt_leaf_names( $kit ) ) ) . ' missing; ' . implode( ',', array_diff( snt_leaf_names( $kit ), snt_leaf_names( $classic ) ) ) . ' extra' );
ok( array( 'resume_save' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is resume_save, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( ! preg_match( '/\sstyle="/', $kit ), 'no inline style= survives' );
ok( (bool) preg_match( '/\sstyle="/', $kit . '<p style="x">' ), 'the inline-style guard above discriminates (fails on a planted style=)' );
ok( 1 === substr_count( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'submit-label="Save resume"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'one os-form dispatching post through the shared sn_action table, submit "Save resume"' );
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

// ── Round trip: the port paints its blank template rows LIVE (inside a real
// <os-disclosure>, not an inert <template>), so every __TOKEN__ field posts
// on save where the classic never did. It is safe only because the real
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
foreach ( array( '+ Add stat', '+ Add role', '+ Add employer', '+ Add earlier employer', '+ Add education', '+ Add affiliation', '+ Add publication', '+ Add skills row' ) as $add ) {
	ok( false !== strpos( $kit, '<os-disclosure heading="' . $add . '"' ), 'the classic add button survives as a fold: ' . $add );
}
ok( false !== strpos( $kit, 'rows keep the order shown' ) && false !== strpos( $kit, 'the arrows reorder' ), 'the add-fold hint says the arrows reorder and rows keep the order shown' );

// ── Reorder (#1560): the classic arrows, verbatim. The classic page reorders
// in the DOM (assets/resume-admin.js moves the [data-rsm-row] element, the
// whole document posts afterwards, the handler has no move logic), and that
// script is already in the window's script list. So the twin is the classic
// mark and class names on kit buttons, and one selector in the script that
// also matches an <os-button> host (a click inside its shadow root retargets
// to the host at the document listener). The classic bakes its controls into
// inert <template>s the kit never clones, so the oracle is the classic page
// with its templates stripped: every LIVE classic row has the arrows, every
// kit row that is not a fold's blank row has them.
$classic_live = preg_replace( '#<template\b.*?</template>#s', '', $classic );
$n_up         = substr_count( $classic_live, 'sn-rsm-up' );
ok( $n_up > 20 && $n_up === substr_count( $kit, 'class="sn-rsm-up"' ) && $n_up === substr_count( $kit, 'class="sn-rsm-down"' ), 'every live classic row\'s Move up / Move down is a kit button carrying the classic class name (' . $n_up . ' rows)' );
ok( $n_up === substr_count( $kit, 'data-rsm-row' ) && $n_up === substr_count( $classic_live, 'data-rsm-row' ), 'every kit row with arrows carries the classic data-rsm-row mark the script walks to (' . $n_up . ')' );
ok( preg_match_all( '/<os-button [^>]*class="sn-rsm-(?:up|down)"[^>]*>/', $kit, $arrows ) === 2 * $n_up && ! preg_grep( '/os-action|\sname=|os-arg-/', $arrows[0] ) && count( preg_grep( '/\btype="button"/', $arrows[0] ) ) === 2 * $n_up, 'the arrows are os-buttons with no action, no name and no os-arg, type=button' );
// The name: the kit does not forward a host aria-label to the inner button
// (os-button.ts props: variant/disabled/type/busy/fill-cell), so the classic
// "Move up" / "Move down" is slotted hidden text, and a host aria-label (the
// inert spelling) fails.
ok( $n_up === substr_count( $kit, '<span aria-hidden="true">&uarr;</span><span class="snt-sr-only">Move up</span></os-button>' ) && $n_up === substr_count( $kit, '<span aria-hidden="true">&darr;</span><span class="snt-sr-only">Move down</span></os-button>' ) && false === strpos( $kit, 'aria-label="Move' ), 'every arrow is named "Move up" / "Move down" through slotted hidden text, the glyph hidden, never through a host aria-label the kit drops' );
ok( preg_match( '/\.snt-sr-only\s*\{[^}]*position:\s*absolute;[^}]*clip-path:\s*inset\( 50% \)/', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) ) === 1, 'the dashboard stylesheet hides .snt-sr-only text visually and keeps it readable' );
preg_match_all( '/<os-card [^>]*os-key="([^"]*)"[^>]*>/', $kit, $cards );
$blank_marked = 0; $indexed_unmarked = 0;
foreach ( $cards[0] as $i => $card ) {
	$marked = false !== strpos( $card, 'data-rsm-row' );
	if ( false !== strpos( $cards[1][ $i ], '__' ) ) {
		$blank_marked += $marked ? 1 : 0;
	} else {
		$indexed_unmarked += $marked ? 0 : 1;
	}
}
ok( count( $cards[0] ) > $n_up && 0 === $blank_marked && 0 === $indexed_unmarked, 'a fold\'s blank row (a __TOKEN__ key) has no data-rsm-row and no arrows; every indexed row has both' );
ok( substr_count( $kit, '<div class="snt-rsm-list">' ) === substr_count( $kit, '+ Add ' ), 'each list\'s rows sit in one wrapper, the add fold outside it, so the arrows\' sibling walk meets rows only' );
ok( preg_match( '/<div class="snt-rsm-list">\s*<\/div>/', snt_leaf_paint( 'content', 'resume' ) ) === 0, 'on the seed no list wrapper is empty' );
// Negative control: the action pin at the top can fail. A planted per-row
// server action would be a second action.
ok( array( 'resume_move', 'resume_save' ) === snt_leaf_actions( $kit . '<os-button os-action="post" os-arg-action="resume_move">Up</os-button>' ), 'the action pin discriminates: a planted resume_move os-button reads as a second action' );
// The script side: the one-token widening and the markup contract it walks.
$js = (string) file_get_contents( SNT_PATH . 'assets/resume-admin.js' );
ok( false !== strpos( $js, "closest( 'button, os-button' )" ), 'resume-admin.js finds the clicked control by button OR os-button (the shadow host the click retargets to)' );
ok( false !== strpos( $js, "closest( '[data-rsm-row]' )" ) && false !== strpos( $js, "'sn-rsm-up'" ) && false !== strpos( $js, "'sn-rsm-down'" ) && false !== strpos( $js, 'previousElementSibling' ), 'resume-admin.js still walks data-rsm-row and the sn-rsm-up / sn-rsm-down classes the kit rows carry' );
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
ok( false !== strpos( $kit, 'heading="Stats" hint="0"' ) && false !== strpos( $kit, 'name="resume[stats][__S__][n]"' ), 'bare: an empty list says 0 and still offers its blank row' );

// ── The hard failure state: no document, no seed — no form.
$GLOBALS['__resume_doc'] = null;
$classic = snt_leaf_classic_html( 'sn_admin_render_resume_section' );
$kit     = snt_leaf_paint( 'content', 'resume' );
ok( false !== strpos( $kit, '<os-empty-state' ) && false !== strpos( $kit, 'The resume editor is unavailable: no stored document and no readable seed.' ), 'unavailable: the classic message paints as an empty state' );
ok( false === strpos( $kit, '<os-form' ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_actions( $classic ) && array() === snt_leaf_names( $kit ), 'unavailable: no form, no action, no field — as the classic early return' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
