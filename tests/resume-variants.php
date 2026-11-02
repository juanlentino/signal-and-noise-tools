<?php
/**
 * Guard: résumé variants (inc/resume-variants.php, owner brief 2026-11-01).
 *
 * Music and operations come from one stored document. The music view is what
 * every existing reader gets, in the shape it always read, and the music PDF's
 * HTML is unchanged by anything the operations résumé adds.
 *
 * Run: php tests/resume-variants.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }
$GLOBALS['opt'] = null;
function get_option( $k, $d = false ) { return 'sn_resume_doc' === $k ? $GLOBALS['opt'] : $d; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-operations-setup.php';

$seed = sn_resume_doc_normalize( sn_resume_seed_doc() );
$e0   = $seed['experience'][0];
$r0   = $e0['roles'][0];
$b0   = $r0['bullets'][0];

echo "Group: the stored document\n";
ok( ! array_key_exists( 'built', $seed ) && ! array_key_exists( 'variants', $seed ), 'a document without variants normalizes as before: no built, no variants key' );
$doc                                  = $seed;
$doc['experience'][0]['variant']      = 'operations';
$doc['experience'][0]['roles'][0]['variant'] = 'bogus';
$doc['experience'][0]['roles'][0]['bullet_variants'] = array( sn_resume_bullet_key( $b0 ) => 'music', 'deadbeef0000' => 'music' );
$doc['skills'][0]['variant']          = 'music';
$n                                    = sn_resume_doc_normalize( $doc );
ok( 'operations' === $n['experience'][0]['variant'] && ! isset( $n['experience'][0]['roles'][0]['variant'] ), 'a known flag is kept; an unknown one is dropped (both)' );
ok( array( sn_resume_bullet_key( $b0 ) => 'music' ) === $n['experience'][0]['roles'][0]['bullet_variants'], 'a bullet flag is kept only for a bullet the role still has' );
ok( 'variant' === array_key_last( $n['experience'][0] ), 'the flag is appended last, so the view restores the exact old key order' );

echo "\nGroup: the views\n";
$music = sn_resume_doc_view( $n, 'music' );
$ops   = sn_resume_doc_view( $n, 'operations' );
ok( count( $music['experience'] ) === count( $seed['experience'] ) - 1 && $music['experience'][0]['org'] !== $e0['org'], 'music leaves out an operations-only entry' );
ok( $ops['experience'][0]['org'] === $e0['org'] && ! in_array( $b0, $ops['experience'][0]['roles'][0]['bullets'], true ), 'operations keeps it, without its music-only bullet' );
ok( count( $ops['skills'] ) === count( $seed['skills'] ) - 1, 'operations leaves out a music-only skills row' );
$flat = json_encode( array( $music, $ops ) );
ok( false === strpos( $flat, '"variant"' ) && false === strpos( $flat, 'bullet_variants' ), 'no view carries a variant key' );
ok( sn_resume_doc_view( $seed, 'music' ) === $seed, 'with no flags, the music view is the document itself' );
$GLOBALS['opt'] = $n;
ok( sn_resume_doc_get() === sn_resume_doc_view( sn_resume_doc_get_raw(), 'music' ) && isset( sn_resume_doc_get_raw()['experience'][0]['variant'] ), 'every reader gets the music view; the editor reads the stored document' );
$GLOBALS['opt'] = null;
$one              = $seed;
$one['experience'][0]['roles'] = array( array( 'title' => 'Only', 'bullets' => array( 'x' ), 'variant' => 'music' ) );
ok( count( sn_resume_doc_view( sn_resume_doc_normalize( $one ), 'operations' )['experience'] ) === count( $seed['experience'] ) - 1, 'an entry whose every role is the other variant\'s is left out' );

echo "\nGroup: the operations setup\n";
$live = $seed;
$live['experience'][0]['roles'][0]['bullets'][] = 'Guide artists and label teams on release strategy, sonic direction.';
$live['affiliations'][]                        = array( 'title' => 'Voting Member, The Recording Academy', 'lines' => array( 'September 2025 - Present' ) );
$live['affiliations'][]                        = array( 'title' => 'Google Project Management Certificate', 'lines' => array( 'May 2025' ) );
$set  = sn_resume_operations_setup( sn_resume_doc_normalize( $live ) );
$after = sn_resume_doc_normalize( $set['doc'] );
ok( null !== $after && sn_resume_doc_view( $after, 'music' ) === sn_resume_doc_view( sn_resume_doc_normalize( $live ), 'music' ), 'the music résumé is exactly what it was before the setup' );
ok( in_array( 'bullet: Supervised full recording workflows end to end', $set['missing'], true ) && ! in_array( 'bullet: Guide artists and label teams on release strategy', $set['missing'], true ), 'what it cannot find is reported, by the text it looked for' );
$opsv = sn_resume_doc_view( $after, 'operations' );
ok( 'Voting Member, The Recording Academy and The Latin Recording Academy' === $opsv['affiliations'][0]['title'] && 'Team Captain, Recording Academy Music Advocacy Day' === $opsv['affiliations'][1]['title'], 'the two operations affiliations open the operations list' );
ok( ! in_array( 'Voting Member, The Recording Academy', array_column( $opsv['affiliations'], 'title' ), true ), 'the music Voting Member line is music-only, and the merged line is not caught by its prefix' );
ok( array( 'Operations', 'Partnerships', 'AI and tools', 'Languages' ) === array_column( $opsv['skills'], 'category' ), 'operations shows its own four skills rows, the existing rows are music-only' );
ok( sn_resume_doc_normalize( sn_resume_operations_setup( $after )['doc'] ) === $after, 'running the setup twice changes nothing more' );

echo "\nGroup: the PDFs\n";
$font  = __DIR__ . '/../lib/pdf/fonts';
$html0 = sn_resume_pdf_html( sn_resume_doc_view( sn_resume_doc_normalize( $live ), 'music' ), 'Juan Lentino', $font, false, 'https://juanlentino.com/' );
$html1 = sn_resume_pdf_html( sn_resume_doc_view( $after, 'music' ), 'Juan Lentino', $font, false, 'https://juanlentino.com/', sn_resume_variant_config( $after, 'music' ) + array( 'built' => sn_resume_built_for( $after, 'music' ) ) );
ok( $html0 === $html1, 'the music PDF\'s HTML is identical before and after the operations setup' );
ok( false === strpos( $html1, "WHAT I'VE BUILT" ) && false === strpos( $html1, 'Team Captain' ) && false === stripos( $html1, 'github.com/juanlentino' ), 'nothing operations-only reaches the music PDF' );
$cfg           = sn_resume_variant_config( $after, 'operations' );
$cfg['built']  = sn_resume_built_for( $after, 'operations' );
$phone         = $opsv;
$phone['pdf']['phone']        = '407-555-0123';
$phone['pdf']['phone_public'] = true;
$ohtml = sn_resume_pdf_html( $phone, 'Juan Lentino', $font, ! empty( $cfg['phone'] ), 'https://juanlentino.com/', $cfg );
ok( false === $cfg['phone'] && false === strpos( $ohtml, '555-0123' ), 'the operations PDF never prints the phone, whatever the switch says' );
$order = array( 'PROFESSIONAL SUMMARY', "WHAT I'VE BUILT", 'PROFESSIONAL EXPERIENCE', 'EDUCATION', 'AFFILIATIONS', 'SKILLS' );
$pos   = array_map( static fn( $h ) => strpos( $ohtml, '<h2>' . $h ), $order );
ok( ! in_array( false, $pos, true ) && $pos === array_values( array_unique( $pos ) ) && $pos == ( static function ( $p ) { sort( $p ); return $p; } )( $pos ), 'operations sections run in the appendix order' );
ok( false === strpos( $ohtml, 'class="stats"' ) && false === strpos( $ohtml, 'CORE COMPETENCIES' ) && false === strpos( $ohtml, 'TECHNICAL TOOLKIT' ) && false === strpos( $ohtml, 'PUBLICATIONS' ), 'no key figures, competencies, toolkit or publications in operations' );
ok( false !== strpos( $ohtml, 'FOUNDER AND OPERATOR' ) && false !== strpos( $ohtml, 'Orlando, FL (open to relocation)' ) && false !== strpos( $ohtml, '>github.com/juanlentino</a>' ), 'the operations headline, location and GitHub link' );
ok( 'Obras Metálicas S.A.' === sn_resume_pdf_soft_case( 'OBRAS METÁLICAS S.A.' ) && 'Remote' === sn_resume_pdf_soft_case( 'Remote' ), 'an all-caps name reads in sentence case, a dotted word kept as written' );
$ord = sn_resume_pdf_order_entries( array( array( 'org' => 'A' ), array( 'org' => 'B' ), array( 'org' => 'C' ) ), array( 'c', 'a' ) );
ok( array( 'C', 'A', 'B' ) === array_column( $ord, 'org' ), 'experience order: named entries first, by name, the rest as listed' );

echo "\nGroup: held to main, not to itself (review of #2344)\n";
// sha1 of main's template over main's normalize of the seed, computed with main's own code
// (6c620798). A deliberate change to the music PDF updates this hash in the same commit.
$main_sha = '222935a0924c3134587474ad4ddb9c40c5dabb90';
$seedn    = sn_resume_doc_normalize( sn_resume_seed_doc() );
ok( $main_sha === sha1( sn_resume_pdf_html( sn_resume_doc_view( $seedn, 'music' ), 'Juan Lentino', '/fonts', false, 'https://juanlentino.com/', sn_resume_pdf_music_config( $seedn ) ) ), 'the music PDF HTML for the seed is main\'s, byte for byte' );
$seeds    = sn_resume_doc_normalize( sn_resume_operations_setup( $seedn )['doc'] );
ok( $main_sha === sha1( sn_resume_pdf_html( sn_resume_doc_view( $seeds, 'music' ), 'Juan Lentino', '/fonts', false, 'https://juanlentino.com/', sn_resume_pdf_music_config( $seeds ) ) ), 'and still main\'s after the operations setup' );
ok( array( 'summary', 'stats', 'competencies', 'built', 'experience', 'publications', 'education', 'affiliations', 'toolkit' ) === sn_resume_variant_config( array( 'variants' => array( 'music' => array( 'show_built' => true ) ) ), 'music' )['sections'], 'switched on, What I\'ve Built sits after the competencies in the music PDF' );
$mb = sn_resume_pdf_music_config( array( 'built' => array( 'intro' => '', 'items' => array( array( 'title' => 'Ops only', 'url' => '', 'text' => 't', 'variant' => 'operations' ), array( 'title' => 'Both', 'url' => '', 'text' => 't' ) ) ) ) );
ok( array( 'Both' ) === array_column( $mb['built']['items'], 'title' ), 'the music PDF takes What I\'ve Built items for music, never operations-only ones' );
ok( SN_RESUME_PDF_MUSIC_SECTIONS === sn_resume_variant_config( sn_resume_doc_normalize( array( 'experience' => $seed['experience'], 'variants' => array( 'operations' => array( 'headline' => 'Only a headline' ) ) ) ), 'operations' )['sections'], 'an operations résumé with no section list falls back to the music order, as blank fields do' );

echo "\nGroup: a second setup run keeps the owner's edits\n";
$ed                                       = $after;
$ed['variants']['operations']['summary']  = 'My own summary.';
$ed['built']['items'][]                   = array( 'title' => 'Mine', 'url' => '', 'text' => 'x' );
$ed['skills'][]                           = array( 'category' => 'Added later', 'items' => 'a, b' );
$re                                       = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $ed ) )['doc'] );
ok( 'My own summary.' === $re['variants']['operations']['summary'] && 'Mine' === end( $re['built']['items'] )['title'], 'an edited summary and an added project survive' );
ok( ! isset( end( $re['skills'] )['variant'] ), 'a skills row added after the first run keeps the variant its author set (both)' );

echo "\nGroup: the production call sites\n";
$ops_src = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/operations.php' );
$gen_src = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/generate.php' );
$tpl_src = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/template.php' );
$dr_src  = (string) file_get_contents( __DIR__ . '/../inc/resume-draft.php' );
$act_src = (string) file_get_contents( __DIR__ . '/../inc/admin-post-actions/resume-draft.php' );
ok( false !== strpos( $ops_src, "sn_resume_pdf_render( sn_resume_doc_view( \$raw, 'operations' ), \$name, \$loc['dir'] . '/.font-cache', false, home_url( '/' ), \$config );" ), 'the operations PDF renders the operations view, phone off' );
ok( false !== strpos( $tpl_src, "\$include_phone = \$include_phone && false !== ( \$config['phone'] ?? null );" ), 'and the template refuses the phone whenever the variant says none' );
$no_phone = sn_resume_pdf_html( $phone, 'Juan Lentino', $font, true, 'https://juanlentino.com/', $cfg );
ok( false === strpos( $no_phone, '555-0123' ), 'even when a caller asks for the phone' );
ok( false !== strpos( $ops_src, "sn_resume_pdf_check( \$result['bytes'], \$result['pages'], 2," ) && false !== strpos( $ops_src, "delete_option( SN_RESUME_OPS_PDF_BYTES );" ), 'the operations PDF is checked at two pages, and one with a phone is never served' );
ok( 1 === preg_match( "/0 !== strpos\\( \\\$path, '\\/resume\\/' \\) \\|\\| '\\.pdf' !== substr\\( \\\$path, -4 \\)\\s*\\) \\{\\s*return;/", $ops_src ) && strpos( $ops_src, "'.pdf' !== substr" ) < (int) strpos( $ops_src, '$config = sn_resume_ops_config();' ), 'the address is matched before the document is read' );
ok( false !== strpos( $gen_src, '$config = sn_resume_pdf_music_config(' ), 'Generate renders the music layout' );
ok( 2 === substr_count( $dr_src, 'sn_resume_doc_same( $draft, sn_resume_doc_get_raw() )' ), 'the draft status and Publish compare the stored documents, flags included' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/admin-post-actions/resume-draft.php' ), "sn_resume_page_body( \$draft )" ) && false !== strpos( $gen_src, "sn_resume_doc_view( sn_resume_draft_get(), 'music' )" ), 'the page preview shows the draft as the page would be (one lane or two); the PDF preview shows its music résumé' );
$setup_fn = substr( $act_src, (int) strpos( $act_src, 'function sn_handle_resume_operations_setup' ) );
ok( false !== strpos( $setup_fn, 'sn_resume_draft_save( $set[\'doc\'] )' ) && false === strpos( $setup_fn, 'sn_resume_doc_save' ), 'the setup writes the draft only, never the published résumé' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
