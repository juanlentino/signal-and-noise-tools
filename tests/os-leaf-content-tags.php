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
$GLOBALS['__untagged']  = array();
$GLOBALS['__unused']    = array();
function sn_tag_find_duplicate_clusters() { return $GLOBALS['__clusters']; }
// Input-aware like the real one: an empty/invalid $from is a WP_Error, so the
// params parse is exercised (a blind stub would hide an array-vs-string slip).
function sn_tag_merge_preview( $f, $i ) { return ( is_array( $f ) && $f && $i ) ? $GLOBALS['__preview'] : new WP_Error(); }
function sn_tag_find_unused() { return $GLOBALS['__unused']; }
function sn_tag_untagged_notes( $l = 20 ) { return $GLOBALS['__untagged']; }
function snt_ai_is_available() { return $GLOBALS['__ai']; }
function get_terms( $args = array() ) {
	if ( isset( $args['fields'] ) && 'count' === $args['fields'] ) { return (string) count( $GLOBALS['__alltags'] ); }
	return $GLOBALS['__alltags'];
}
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }

require_once SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/tag-consolidation-admin.php';
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
ok( array( 'tag_ai_suggest', 'tag_prune_unused' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the list view offers tag_ai_suggest and tag_prune_unused, as the classic leaf does: ' . implode( ',', snt_leaf_actions( $kit ) ) );
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

// AI: available with one untagged Note -> the suggest form.
ok( false !== strpos( $kit, 'heading="AI: suggest tags for untagged Notes"' ) && false !== strpos( $kit, '1 untagged Note. Runs on demand on your AI key; up to 20 per click.' ) && false !== strpos( $kit, 'submit-label="Suggest tags"' ), 'AI: the untagged count and the Suggest tags form' );

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
$GLOBALS['__clusters'] = array(); $GLOBALS['__unused'] = array(); $GLOBALS['__ai'] = false; $GLOBALS['__options']['sn_tag_merge_history'] = array();
$classic = classic_tags();
$kit     = kit_tags();
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'empty view: field names match (the picker alone): ' . names_line( $classic, $kit ) );
ok( array() === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'empty view: no write is offered' );
ok( false !== strpos( $kit, 'heading="Duplicate tags"' ) && false !== strpos( $kit, 'heading="No duplicate tags detected."' ), 'empty view: No duplicate tags detected' );
ok( false !== strpos( $kit, 'heading="No unused tags."' ), 'empty view: No unused tags' );
ok( false !== strpos( $kit, 'Connect an AI provider (Settings &gt; Connectors) to suggest tags.' ), 'empty view: the dormant AI note' );
ok( false === strpos( $kit, 'Recent tag operations' ), 'empty view: no history, no Recent section' );
ok( false !== strpos( $kit, 'caption="clean"' ) && false === strpos( $kit, 'swatch' ), 'empty view: the glance pills read clean with no swatch' );

// ── AI available, every Note tagged.
$GLOBALS['__ai'] = true; $GLOBALS['__untagged'] = array();
$kit = kit_tags();
ok( false !== strpos( $kit, 'heading="Every published Note has at least one tag. Nothing to suggest."' ), 'AI: nothing to suggest when every Note is tagged' );

// ── AI suggestions pending: the review form with per-post checkboxes.
$GLOBALS['__transient'] = array( array( 'post_id' => 7, 'title' => 'Untagged Note', 'suggested' => array( array( 'term_id' => 2, 'name' => 'Jazz', 'slug' => 'jazz' ), array( 'term_id' => 5, 'name' => 'Blues', 'slug' => 'blues' ) ) ) );
$classic = classic_tags();
$kit     = kit_tags();
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && in_array( 'assign[7][]', snt_leaf_names( $kit ), true ), 'AI review: field names match, assign[7][] included: ' . names_line( $classic, $kit ) );
ok( array( 'tag_ai_apply' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'AI review: the one write is tag_ai_apply' );
ok( false !== strpos( $kit, '<strong>Untagged Note</strong>' ) && false !== strpos( $kit, 'name="assign[7][]" value="2" checked> Jazz' ) && false !== strpos( $kit, 'name="assign[7][]" value="5" checked> Blues' ) && false !== strpos( $kit, '>Apply selected</button>' ) && false !== strpos( $kit, 'Review the AI suggestions' ), 'AI review: the Note, both suggested tags checked, Apply selected' );
$GLOBALS['__transient'] = false;

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
