<?php
/**
 * Native window leaf: Content → Tags (apps/sn-dashboard/parts/leaves/content-tags.php).
 *
 * The oracle is the classic leaf (inc/tag-consolidation-admin.php): the kit
 * leaf must carry the same field names and the same sn_action values in every
 * state — the list view, the AI review, the GET preview's confirm panel — print
 * every readout the classic prints, escape a hostile tag name, and carry none
 * of wp-admin's markup.
 *
 * Run: php tests/os-leaf-content-tags.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// Two stubs the harness already declares, redeclared UNCONDITIONALLY so they
// are bound at compile time (before the harness's guarded ones run) and can be
// driven from a global: the harness's get_transient() always answers false and
// its current_user_can() always answers true, and this leaf has a state on each.
function get_transient( $k ) { return $GLOBALS['__transient'] ?? false; }
function current_user_can( $cap ) { return $GLOBALS['__can'] ?? true; }

// The leaf's own readers.
$GLOBALS['__clusters']  = array();
$GLOBALS['__preview']   = null;
$GLOBALS['__alltags']   = array();
$GLOBALS['__ai']        = false;
$GLOBALS['__transient'] = false;
$GLOBALS['__jev']       = false; // 16.9.0: the connector's key
$GLOBALS['__untagged']  = array();
$GLOBALS['__unused']    = array();
function sn_tag_find_duplicate_clusters() { return $GLOBALS['__clusters']; }
// Input-aware like the real one: an empty/invalid $from is a WP_Error, so the
// params parse is exercised (a blind stub would hide an array-vs-string slip).
function sn_tag_merge_preview( $f, $i ) { return ( is_array( $f ) && $f && $i ) ? $GLOBALS['__preview'] : new WP_Error(); }
function sn_tag_find_unused() { return $GLOBALS['__unused']; }
function sn_tag_untagged_notes( $l = 20 ) { return $GLOBALS['__untagged']; }
function snt_ai_is_available() { return $GLOBALS['__ai']; }
function sn_jev_is_ready() { return $GLOBALS['__jev']; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id ) { return 'https://x.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; } }
function get_terms( $args = array() ) {
	if ( isset( $args['fields'] ) && 'count' === $args['fields'] ) { return (string) count( $GLOBALS['__alltags'] ); }
	return $GLOBALS['__alltags'];
}
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }

require_once SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/tag-consolidation-admin.php';
require_once SNT_PATH . 'inc/jev-tags.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/content-tags.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function tag_obj( $id, $name, $slug, $count ) { return (object) array( 'term_id' => $id, 'name' => $name, 'slug' => $slug, 'count' => $count ); }
function classic_tags() { return snt_leaf_classic_html( 'sn_admin_render_tag_cleanup_section' ); }
function kit_tags( array $state = array() ) { return snt_leaf_paint( 'content', 'tags', $state ); }
function names_line( $classic, $kit ) { return implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')'; }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['content/tags'] ), 'the painter is registered under content/tags' );

// ── The rich list view: one cluster, three tags, AI available with an untagged Note, one unused tag, history.
$GLOBALS['__alltags']  = array( tag_obj( 5, 'Jazz', 'jazz', 4 ), tag_obj( 10, 'AI-Generated Music', 'ai-generated-music', 5 ), tag_obj( 11, 'AI Generated Music', 'ai-generated-music-2', 2 ) );
$GLOBALS['__clusters'] = array( array(
	'key'       => 'ai generated music',
	'terms'     => array(
		array( 'term_id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ),
		array( 'term_id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2 ),
	),
	'suggested' => 10,
) );
$GLOBALS['__ai']       = true;
$GLOBALS['__jev']      = true;
$GLOBALS['__untagged'] = array( array( 'id' => 7, 'title' => 'Untagged Note' ) );
$GLOBALS['__unused']   = array( array( 'term_id' => 9, 'name' => 'Empty', 'slug' => 'empty', 'count' => 0 ) );
$GLOBALS['__options']['sn_tag_merge_history'] = array(
	array( 'from' => array( 'ai-generated-music-2', 'ai-generated-music-3' ), 'into' => 'music', 'posts' => 3, 'user' => 1, 'ts' => 100 ),
	array( 'op' => 'prune', 'from' => array( 'stale-tag' ), 'user' => 1, 'ts' => 90 ),
);
$classic = classic_tags();
$kit     = kit_tags();
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'field names match the classic forms: ' . names_line( $classic, $kit ) );
ok( array( 'tag_fit_run', 'tag_prune_unused' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '16.9.0: the list view offers tag_fit_run and tag_prune_unused, as the classic leaf does: ' . implode( ',', snt_leaf_actions( $kit ) ) );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// Glance: the same three cards, pill text as caption, warn kind as a swatch.
ok( false !== strpos( $kit, 'label="Tags total"' ) && false !== strpos( $kit, 'value="3" label="Tags total"' ), 'glance: Tags total reads 3' );
ok( false !== strpos( $kit, 'value="1" label="Duplicate clusters" caption="review" swatch data-tone="warning"' ), 'glance: Duplicate clusters reads 1, pill "review", warning swatch' );
ok( false !== strpos( $kit, 'value="1" label="Unused tags" caption="prune" swatch data-tone="warning"' ), 'glance: Unused tags reads 1, pill "prune", warning swatch' );

// Cluster: a native GET form dispatching go, radio on the suggested term, checkbox on the other.
ok( false !== strpos( $kit, '<form class="snt-form snt-form--native" method="get" os-action="go">' ), 'cluster: a native GET form dispatches go (the window\'s reading of the classic GET navigation)' );
ok( false !== strpos( $kit, 'name="page" value="sn-content"' ) && false !== strpos( $kit, 'name="tab" value="content"' ) && false !== strpos( $kit, 'name="sub" value="tags"' ) && false !== strpos( $kit, 'name="sn_tag_preview" value="1"' ), 'cluster: the four hidden GET fields survive' );
ok( false !== strpos( $kit, '<input type="radio" name="sn_tag_into" value="10" checked aria-label="Canonical">' ), 'cluster: the suggested term is the checked canonical radio' );
ok( false !== strpos( $kit, '<input type="checkbox" name="sn_tag_from[]" value="10" aria-label="Merge?">' ) && false !== strpos( $kit, '<input type="checkbox" name="sn_tag_from[]" value="11" checked aria-label="Merge?">' ), 'cluster: the non-suggested term is checked to fold in, the suggested one is not' );
ok( false !== strpos( $kit, '<strong>AI-Generated Music</strong> <os-code>ai-generated-music</os-code>' ) && false !== strpos( $kit, '<strong>AI Generated Music</strong> <os-code>ai-generated-music-2</os-code>' ), 'cluster: both members with their slugs as kit code' );
ok( false !== strpos( $kit, '<span class="snt-list__value">5</span>' ) && false !== strpos( $kit, '<span class="snt-list__value">2</span>' ), 'cluster: the post counts' );
ok( false !== strpos( $kit, 'heading="Possible duplicates"' ) && false !== strpos( $kit, '>Preview merge</button>' ) && false !== strpos( $kit, '<li class="snt-list__row"><span class="snt-list__value">Canonical</span><span class="snt-list__value">Merge?</span><span class="snt-list__label">Tag</span><span class="snt-list__value">Posts</span></li>' ) && false !== strpos( $kit, 'Pick the canonical tag (radio) and which dupes to fold in (checkbox).' ), 'cluster: heading, the four column headers over their controls (styled cells, not invented ones), Preview merge and the hint' );
ok( strpos( $kit, '>Preview merge</button>' ) < strpos( $kit, 'Pick the canonical tag (radio)' ), 'cluster: the hint follows the submit button, as the classic markup prints it' );

// Picker: an os-form dispatching go with the two selects.
ok( false !== strpos( $kit, 'heading="Merge any two tags"' ) && false !== strpos( $kit, '<os-form class="snt-form" os-action="go" submit-label="Preview merge"' ), 'picker: a kit form dispatches go' );
ok( false !== strpos( $kit, '<os-select name="sn_tag_from[]"' ) && false !== strpos( $kit, '<os-select name="sn_tag_into"' ) && substr_count( $kit, '<os-option value="5">Jazz (4)</os-option>' ) === 2, 'picker: Fold/into selects list every tag with its count' );
ok( false !== strpos( $kit, 'label="Fold"' ) && false !== strpos( $kit, 'label="into"' ), 'picker: the Fold/into labels the classic prints around the selects survive' );

// Jev ready, no pass yet: the section explains the pass and offers Read tags now.
ok( false !== strpos( $kit, 'heading="Jev: tag fit"' ) && false !== strpos( $kit, 'About seventy requests; under a cent.' ) && false !== strpos( $kit, 'submit-label="Read tags now"' ), '16.9.0: Jev ready, no pass: the explainer and the Read tags now form' );

// Unused: a native POST form with the checked term, confirmed and marked dangerous as the classic onsubmit confirm.
ok( false !== strpos( $kit, 'os-action="post" os-confirm="Delete the selected unused tags?" os-confirm-danger>' ), 'unused: the prune form confirms with the classic question, marked dangerous' );
ok( false !== strpos( $kit, '<input type="checkbox" name="sn_tag_unused[]" value="9" checked> <strong>Empty</strong> <os-code>empty</os-code>' ) && false !== strpos( $kit, '>Delete selected</button>' ), 'unused: the count-0 term is checked, with its slug, and Delete selected' );

// Recent operations: a merge line and a prune line.
ok( false !== strpos( $kit, 'heading="Recent tag operations"' ) && false !== strpos( $kit, '<li>ai-generated-music-2, ai-generated-music-3 into &quot;music&quot; (3 posts)</li>' ) && false !== strpos( $kit, '<li>deleted unused: stale-tag</li>' ), 'recent: the merge and the prune lines' );

// ── Escaping: a hostile tag name never reaches the markup raw (cluster, picker, unused).
$hostile = '"><script>x</script>';
$GLOBALS['__alltags'][]  = tag_obj( 66, $hostile, 'x', 0 );
$GLOBALS['__clusters'][0]['terms'][] = array( 'term_id' => 66, 'name' => $hostile, 'slug' => $hostile, 'count' => 0 );
$GLOBALS['__unused'][]   = array( 'term_id' => 66, 'name' => $hostile, 'slug' => 'x', 'count' => 0 );
$kit = kit_tags();
ok( false === strpos( $kit, '<script>' ) && substr_count( $kit, '&lt;script&gt;' ) >= 4, 'a hostile tag name is escaped everywhere it is printed' );
array_pop( $GLOBALS['__alltags'] ); array_pop( $GLOBALS['__clusters'][0]['terms'] ); array_pop( $GLOBALS['__unused'] );

// ── The empty list view: no clusters, no unused, no AI provider, no history.
$GLOBALS['__clusters'] = array(); $GLOBALS['__unused'] = array(); $GLOBALS['__ai'] = false; $GLOBALS['__jev'] = false; $GLOBALS['__options']['sn_tag_merge_history'] = array();
$classic = classic_tags();
$kit     = kit_tags();
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty view: field names match (the picker alone): ' . names_line( $classic, $kit ) );
ok( array() === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'empty view: no write is offered' );
ok( false !== strpos( $kit, 'heading="Duplicate tags"' ) && false !== strpos( $kit, 'heading="No duplicate tags detected."' ), 'empty view: No duplicate tags detected' );
ok( false !== strpos( $kit, 'heading="No unused tags."' ), 'empty view: No unused tags' );
ok( false !== strpos( $kit, 'Install Connector for TypeSafe Jev and add the key under Settings › Connectors.' ), '16.9.0: empty view, no key: the section names the connector' );
ok( false === strpos( $kit, 'Recent tag operations' ), 'empty view: no history, no Recent section' );
ok( false !== strpos( $kit, 'caption="clean"' ) && false === strpos( $kit, 'swatch' ), 'empty view: the glance pills read clean with no swatch' );

// ── Jev ready, a pass with nothing flagged.
$GLOBALS['__ai'] = true; $GLOBALS['__jev'] = true; $GLOBALS['__untagged'] = array();
$GLOBALS['__options'][ SN_JEV_TAGS_OPTION ] = array( 'synced_at' => 1, 'tags' => 3, 'notes' => array( 7 => array( 'title' => 'Fine', 'attached' => array( array( 'id' => 2, 'name' => 'Jazz', 'score' => 1.9, 'confidence' => 0.9 ) ), 'missing' => array() ) ), 'usage' => array(), 'last_error' => '' );
$kit = kit_tags();
ok( false !== strpos( $kit, 'Last read 2 hours ago: every tag on every note touches its subject.' ) && false !== strpos( $kit, 'submit-label="Read tags now"' ) && false === strpos( $kit, 'tag_fit_apply' ), '16.9.0: a clean pass says so and still offers Read tags now; no apply form' );

// ── A pass with rows: the review form with per-post Remove checkboxes (16.9.2: remove only).
$GLOBALS['__options'][ SN_JEV_TAGS_OPTION ] = array( 'synced_at' => 1, 'tags' => 3, 'notes' => array(
	7 => array( 'title' => 'Untagged Note', 'attached' => array( array( 'id' => 9, 'name' => 'Empty', 'score' => 0.3, 'confidence' => 0.7 ), array( 'id' => 2, 'name' => 'Jazz', 'score' => 1.8, 'confidence' => 0.9 ) ), 'missing' => array( array( 'id' => 5, 'name' => 'Blues', 'noul' => 0.9 ) ) ),
	8 => array( 'title' => 'Shrug', 'attached' => array( array( 'id' => 9, 'name' => 'Empty', 'score' => 0.3, 'confidence' => 0.6 ) ) ),
), 'usage' => array(), 'last_error' => '' );
$classic = classic_tags();
$kit     = kit_tags();
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && in_array( 'remove[7][]', snt_leaf_names( $kit ), true ) && ! array_filter( snt_leaf_names( $kit ), static fn( $n ) => str_starts_with( $n, 'assign' ) ), '16.9.2 review: field names match, remove[7][] included, no assign field anywhere: ' . names_line( $classic, $kit ) );
ok( array( 'tag_fit_apply', 'tag_fit_run' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '16.9.0 review: the two writes are tag_fit_apply and tag_fit_run, same on both leaves' );
ok( false !== strpos( $kit, '>Untagged Note</a></strong>' ) && false !== strpos( $kit, 'name="remove[7][]" value="9"> Remove &quot;Empty&quot; (attached for reach, 0.30 of 2)' ) && false !== strpos( $kit, '>Apply selected</button>' ) && false !== strpos( $kit, '1 notes, read 2 hours ago.' ), '16.9.0 review: the note links to its editor, the misfit unchecked with its score, Apply selected' );
ok( false === strpos( $kit, 'Add &quot;' ) && false === strpos( $kit, 'name="remove[8][]"' ) && false === strpos( $kit, 'Umbrella' ) && false !== strpos( $kit, 'confidence 0.7 or better' ) && false !== strpos( $kit, 'Jev proposes no tags' ), '16.9.2 review: a stored 16.9.1 missing list paints no Add box, a 0.6-confidence misfit is no row, no umbrella section; the prose names the lines and says Jev proposes nothing' );
// ── 17.1.0: the pass pivoted per tag, on both surfaces, no boxes.
ok( false !== strpos( $kit, 'heading="Jev: by tag"' ) && false !== strpos( $kit, '<strong>Empty: 2 notes, mean 0.30 of 2, 2 only touching it</strong>' ) && false !== strpos( $kit, '<strong>Jazz: 1 notes, mean 1.80 of 2, 0 only touching it</strong>' ), '17.1.0 by tag: Empty (two touching notes) sorts before Jazz (about); the line carries notes, mean and the touching count' );
ok( false !== strpos( $kit, '>Untagged Note</a> (0.30 of 2, confidence 0.70)</li>' ) && false !== strpos( $kit, '>Shrug</a> (0.30 of 2, confidence 0.60)</li>' ) && false !== strpos( $classic, 'Empty: 2 notes, mean 0.30 of 2, 2 only touching it' ) && false !== strpos( $classic, '>Shrug</a> (0.30 of 2, confidence 0.60)</li>' ), '17.1.0 by tag: the touching notes link to their editor with score and confidence, on both surfaces' );
ok( false === strpos( $kit, 'name="bytag' ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), '17.1.0 by tag: a reading, no form, parity holds' );
unset( $GLOBALS['__options'][ SN_JEV_TAGS_OPTION ] );

// ── 16.9.3: no ceiling section on either surface (the rule is the description, the gate nudges).
ok( false === strpos( kit_tags(), 'Notes over' ) && false === strpos( classic_tags(), 'Notes over' ), '16.9.3: no Notes-over-N section on either surface' );

// ── The GET preview -> confirm panel: the classic reads $_GET, the window reads its params state.
$_GET['sn_tag_preview'] = '1'; $_GET['sn_tag_from'] = array( '10', '11' ); $_GET['sn_tag_into'] = '12';
$params = array( 'sn_tag_preview' => '1', 'sn_tag_from' => array( '10', '11' ), 'sn_tag_into' => '12' );
$GLOBALS['__preview'] = array( 'from' => array( array( 'id' => 10, 'name' => 'AI-Generated Music', 'slug' => 'ai-generated-music', 'count' => 5 ), array( 'id' => 11, 'name' => 'AI Generated Music', 'slug' => 'ai-generated-music-2', 'count' => 2 ) ), 'into' => array( 'id' => 12, 'name' => 'Music', 'slug' => 'music' ), 'posts_affected' => 3 );
$classic = classic_tags();
$kit     = kit_tags( array( 'params' => $params ) );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'confirm: field names match the classic confirm form: ' . names_line( $classic, $kit ) );
ok( array( 'tag_merge' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'confirm: the one write is tag_merge' );
ok( false !== strpos( $kit, 'This moves 3 posts from AI-Generated Music, AI Generated Music into &quot;Music&quot;, then deletes the source tags. The old tag archives will 301-redirect to &quot;Music&quot;.' ), 'confirm: the dry-run sentence with the count, the names and the 301-redirect clause' );
ok( false !== strpos( $kit, 'name="sn_tag_from" value="10,11"' ) && false !== strpos( $kit, 'name="sn_tag_into" value="12"' ) && false !== strpos( $kit, 'submit-label="Confirm merge"' ), 'confirm: the ids round-trip as a comma string + the canonical id, under Confirm merge' );
ok( false !== strpos( $kit, 'os-action="go" os-arg-sub="tags">Cancel</os-button>' ), 'confirm: Cancel is an in-window go back to the Tags leaf' );
ok( false === strpos( $kit, 'Tags total' ) && false === strpos( $kit, 'Possible duplicates' ), 'confirm: the panel stays focused — no glance, no clusters' );

// ── The preview that no longer resolves.
$GLOBALS['__preview'] = null;
$kit = kit_tags( array( 'params' => $params ) );
ok( false !== strpos( $kit, 'heading="Nothing to merge (the selected tags are no longer valid)."' ) && false !== strpos( $kit, 'os-action="go" os-arg-sub="tags">Back</os-button>' ) && array() === snt_leaf_actions( $kit ), 'confirm: an invalid selection paints Nothing to merge + Back, with no write offered' );
unset( $_GET['sn_tag_preview'], $_GET['sn_tag_from'], $_GET['sn_tag_into'] );

// ── The capability gate.
$GLOBALS['__can'] = false;
$kit = kit_tags();
ok( false !== strpos( $kit, 'You do not have permission to manage tags.' ) && array() === snt_leaf_names( $kit ), 'a non-manager sees the refusal and no form' );
$GLOBALS['__can'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
