<?php
/**
 * Tests for inc/seo-schema.php JSON-LD @graph enrichments (v4.8.1, T2–T5).
 *
 * Covers the four structured-data additions:
 *   - T2 Article: wordCount / timeRequired / keywords / articleSection
 *   - T3 Person:  image (ImageObject, og → site-icon fallback)
 *   - T4 CollectionPage: mainEntity ItemList of recent posts
 *   - T5 WebSite:  potentialAction SearchAction (/notes/?s=)
 *
 * Pure-PHP CLI harness. seo-schema.php is require_once'd against inline WP
 * stubs whose return values are driven by mutable $GLOBALS so each test can
 * set up its own fixture (singular vs not, terms present vs absent, og vs
 * site-icon, N recent posts, etc.).
 *
 * @since plugin v4.8.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Mutable test state ───────────────────────────────────────────────
$GLOBALS['__ss'] = array(
	'is_singular_post'   => false,
	'queried'            => null,   // WP_Post-ish stdClass or null
	'settings'           => array(),
	'terms'              => array(), // [ "{$id}|{$tax}" => [names] ] or WP_Error
	'reading_time'       => 4,
	'recent_posts'       => array(), // array of post objects for get_posts()
	'site_icon'          => '',
	'og_filter'          => null,    // callable mutating sn_og_image_url
);

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		// Article/WebPage emitters call is_singular('post'); collection uses none.
		return (bool) $GLOBALS['__ss']['is_singular_post'];
	}
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home() {
		return (bool) ( $GLOBALS['__ss']['is_home'] ?? false );
	}
}
if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ) {
		return (bool) ( $GLOBALS['__ss']['is_page'] ?? false );
	}
}
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['__ss']['is_front_page'] ?? false );
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['__ss']['queried'];
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = null ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.com/notes/post-' . $id . '/';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = null ) {
		if ( is_object( $post ) && isset( $post->post_title ) ) {
			return $post->post_title;
		}
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'Title ' . $id;
	}
}
if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( $format, $gmt = false, $post = null ) {
		return '2026-06-01T00:00:00+00:00';
	}
}
if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( $format, $gmt = false, $post = null ) {
		return '2026-06-02T00:00:00+00:00';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what ) {
		return 'Example';
	}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = '' ) {
		return array_key_exists( $key, $GLOBALS['__ss']['settings'] )
			? $GLOBALS['__ss']['settings'][ $key ]
			: $default;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( 'sn_og_image_url' === $hook && is_callable( $GLOBALS['__ss']['og_filter'] ) ) {
			return call_user_func( $GLOBALS['__ss']['og_filter'], $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $s ) {
		return preg_replace( '/\[[^\]]*\]/', '', (string) $s );
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		$key = $post_id . '|' . $taxonomy;
		return $GLOBALS['__ss']['terms'][ $key ] ?? array();
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error_Stub );
	}
}
if ( ! function_exists( 'sn_get_reading_time' ) ) {
	function sn_get_reading_time( $post = null ) {
		return (int) $GLOBALS['__ss']['reading_time'];
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return $GLOBALS['__ss']['recent_posts'];
	}
}
if ( ! function_exists( 'get_site_icon_url' ) ) {
	function get_site_icon_url( $size = 512 ) {
		return (string) $GLOBALS['__ss']['site_icon'];
	}
}
if ( ! function_exists( 'sn_post_settings_get_description' ) ) {
	function sn_post_settings_get_description( $id ) {
		return '';
	}
}
if ( ! function_exists( 'get_post_ancestors' ) ) {
	function get_post_ancestors( $post ) {
		return array();
	}
}

class WP_Error_Stub {}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

require_once __DIR__ . '/../inc/seo-schema.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ss_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ss_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "seo-schema.php JSON-LD enrichment suite — plugin v4.8.1\n";

// ─── T2: Article enrichment ──────────────────────────────────────────
echo "\nT2: Article enrichment (wordCount / timeRequired / keywords / articleSection)\n";

$post           = new stdClass();
$post->ID       = 7;
$post->post_title   = 'My Post';
$post->post_content = 'Hello there [shortcode] world this is some body text.';
$GLOBALS['__ss']['is_singular_post'] = true;
$GLOBALS['__ss']['queried']          = $post;
$GLOBALS['__ss']['reading_time']     = 4;
$GLOBALS['__ss']['terms']            = array(
	'7|post_tag'  => array( 'foo', 'bar' ),
	'7|category'  => array( 'Music', 'Audio' ),
);

$article = sn_schema_article();
ss_true( is_array( $article ), 'Article schema is built on a singular post' );
ss_true( isset( $article['wordCount'] ) && is_int( $article['wordCount'] ) && $article['wordCount'] > 0, 'wordCount is a positive int' );
ss_eq( 'PT4M', $article['timeRequired'] ?? null, 'timeRequired === PT4M' );
ss_eq( 'foo, bar', $article['keywords'] ?? null, 'keywords === "foo, bar"' );
ss_eq( 'Music', $article['articleSection'] ?? null, 'articleSection === first category name' );

// T2 negative: no terms → keys absent.
echo "\nT2 negative: no terms → keywords/articleSection absent\n";
$GLOBALS['__ss']['terms'] = array(); // empty for all
$article2 = sn_schema_article();
ss_true( ! isset( $article2['keywords'] ), 'keywords absent when no tags' );
ss_true( ! isset( $article2['articleSection'] ), 'articleSection absent when no categories' );
// wordCount + timeRequired still present (content + reading-time fn exist).
ss_true( isset( $article2['wordCount'] ), 'wordCount still present (content non-empty)' );
ss_true( isset( $article2['timeRequired'] ), 'timeRequired still present (reading-time fn exists)' );

// T2 WP_Error guard: wp_get_post_terms returns WP_Error → keys absent, no fatal.
echo "\nT2 WP_Error guard: term lookup error → keys absent\n";
$GLOBALS['__ss']['terms'] = array(
	'7|post_tag' => new WP_Error_Stub(),
	'7|category' => new WP_Error_Stub(),
);
$article3 = sn_schema_article();
ss_true( ! isset( $article3['keywords'] ), 'keywords absent on WP_Error' );
ss_true( ! isset( $article3['articleSection'] ), 'articleSection absent on WP_Error' );

// ─── T3: Person ImageObject ──────────────────────────────────────────
echo "\nT3: Person image (og url → site-icon fallback → absent)\n";
$GLOBALS['__ss']['og_filter'] = null;
$GLOBALS['__ss']['settings']  = array( 'og.default_image_url' => 'https://example.com/og.png' );
$GLOBALS['__ss']['site_icon'] = '';
$person = sn_schema_person();
ss_eq( 'ImageObject', $person['image']['@type'] ?? null, 'Person.image.@type === ImageObject (og set)' );
ss_eq( 'https://example.com/og.png', $person['image']['url'] ?? null, 'Person.image.url === og url' );
ss_eq( 'https://example.com/#/schema/PersonImage', $person['image']['@id'] ?? null, 'Person.image.@id === PersonImage ref' );

// og empty + site-icon set → url is the icon.
$GLOBALS['__ss']['settings']  = array( 'og.default_image_url' => '' );
$GLOBALS['__ss']['site_icon'] = 'https://example.com/icon-512.png';
$person2 = sn_schema_person();
ss_eq( 'https://example.com/icon-512.png', $person2['image']['url'] ?? null, 'Person.image.url falls back to site-icon' );

// both empty → image key absent.
$GLOBALS['__ss']['settings']  = array( 'og.default_image_url' => '' );
$GLOBALS['__ss']['site_icon'] = '';
$person3 = sn_schema_person();
ss_true( ! isset( $person3['image'] ), 'Person.image absent when og + site-icon both empty' );
// sameAs/jobTitle/knowsAbout untouched.
ss_true( isset( $person3['sameAs'] ) && isset( $person3['jobTitle'] ) && isset( $person3['knowsAbout'] ), 'sameAs/jobTitle/knowsAbout untouched' );

// REGRESSION (v4.8.1 adversarial fix 1): Person is the cross-URL-stable
// author+publisher entity. Its image must NOT vary per-post. The
// sn_og_image_url filter is per-post by design (og-card-generator returns the
// article's featured image/card on singular views), so Person.image must read
// the configured value DIRECTLY and bypass that filter. Register a hostile
// per-post filter and assert the stable configured value still wins.
echo "\nT3 regression: Person.image bypasses per-post sn_og_image_url filter\n";
$GLOBALS['__ss']['settings']  = array( 'og.default_image_url' => 'https://example.com/og.png' );
$GLOBALS['__ss']['site_icon'] = '';
$GLOBALS['__ss']['og_filter'] = function ( $value ) {
	return 'https://x/post-99.png';
};
$person4 = sn_schema_person();
ss_eq( 'https://example.com/og.png', $person4['image']['url'] ?? null, 'Person.image.url stays stable (configured value), NOT the per-post filtered url' );
$GLOBALS['__ss']['og_filter'] = null;

// ─── T4: CollectionPage ItemList ─────────────────────────────────────
echo "\nT4: CollectionPage mainEntity ItemList\n";
$GLOBALS['__ss']['is_home'] = true;
$p1 = new stdClass(); $p1->ID = 11; $p1->post_title = 'First';
$p2 = new stdClass(); $p2->ID = 12; $p2->post_title = 'Second';
$p3 = new stdClass(); $p3->ID = 13; $p3->post_title = 'Third';
$GLOBALS['__ss']['recent_posts'] = array( $p1, $p2, $p3 );
$coll = sn_schema_collection_page();
ss_eq( 'ItemList', $coll['mainEntity']['@type'] ?? null, 'mainEntity.@type === ItemList' );
ss_eq( 3, isset( $coll['mainEntity']['itemListElement'] ) ? count( $coll['mainEntity']['itemListElement'] ) : 0, 'itemListElement has 3 entries' );
ss_eq( 1, $coll['mainEntity']['itemListElement'][0]['position'] ?? null, 'first position === 1' );
ss_eq( 3, $coll['mainEntity']['itemListElement'][2]['position'] ?? null, 'third position === 3' );
// v6.24.0: each ListItem wraps an Article node carrying the SAME @id the
// single-note page mints (<permalink>#article), so Google reconciles the list
// with the full Article entities across pages instead of reading a bare URL list.
$elem0 = $coll['mainEntity']['itemListElement'][0] ?? array();
$item0 = $elem0['item'] ?? array();
ss_eq( 'Article', $item0['@type'] ?? null, 'each ListItem.item is an Article node' );
ss_true( false !== strpos( (string) ( $item0['@id'] ?? '' ), '#article' ), 'item.@id carries the #article anchor (reconciles with the single-page Article)' );
ss_true( ! empty( $item0['url'] ) && ! empty( $item0['headline'] ), 'item has url + headline' );
ss_eq( 'First', $item0['headline'] ?? null, 'first headline === stripped title' );
ss_true( ! empty( $item0['datePublished'] ), 'item carries datePublished' );

// empty get_posts → mainEntity absent.
$GLOBALS['__ss']['recent_posts'] = array();
$coll2 = sn_schema_collection_page();
ss_true( ! isset( $coll2['mainEntity'] ), 'mainEntity absent when no recent posts' );

// ─── T5: WebSite SearchAction ────────────────────────────────────────
echo "\nT5: WebSite potentialAction SearchAction\n";
$site = sn_schema_website();
ss_eq( 'SearchAction', $site['potentialAction']['@type'] ?? null, 'potentialAction.@type === SearchAction' );
ss_eq(
	'https://example.com/notes/?s={search_term_string}',
	$site['potentialAction']['target']['urlTemplate'] ?? null,
	'urlTemplate === <home>/notes/?s={search_term_string} (exact)'
);
ss_eq( 'EntryPoint', $site['potentialAction']['target']['@type'] ?? null, 'target.@type === EntryPoint' );
ss_eq( 'required name=search_term_string', $site['potentialAction']['query-input'] ?? null, 'query-input === required name=search_term_string' );
// {search_term_string} token survives JSON encoding.
$json = wp_json_encode_test( $site );
ss_true( false !== strpos( $json, '{search_term_string}' ), '{search_term_string} survives JSON_UNESCAPED_SLASHES encoding' );

// v4.14.2: JSON_HEX_TAG prevents a </script> breakout from any string field
// (e.g. an admin-set identity field). Behaviorally transparent to JSON-LD.
$breakout = wp_json_encode_test( array( 'name' => 'Evil</script><script>alert(1)</script>' ) );
ss_true( false === strpos( $breakout, '<' ), 'JSON_HEX_TAG escapes < so a string field cannot break out of <script>' );
ss_true( false === strpos( $breakout, '</script>' ), 'no literal </script> survives in the encoded JSON-LD' );
ss_eq( 'Evil</script><script>alert(1)</script>', json_decode( $breakout, true )['name'] ?? null, 'the escaped value round-trips intact via json_decode (transparent to JSON-LD consumers)' );

function wp_json_encode_test( $data ) {
	// Mirrors inc/seo-schema.php's JSON-LD emitter (v4.14.2 added JSON_HEX_TAG).
	return json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

// ─── T6: Person credentials (occupation / education / awards / memberships / worksFor) ───
echo "\nT6: Person credentials graph\n";
$GLOBALS['__ss']['settings'] = array();
$pc          = sn_schema_person();
$occupations = array_map( function ( $o ) { return $o['name'] ?? ''; }, (array) ( $pc['hasOccupation'] ?? array() ) );
ss_true( in_array( 'Music Producer', $occupations, true ), 'hasOccupation includes Music Producer' );
ss_true( in_array( 'Audio Engineer', $occupations, true ), 'hasOccupation includes Audio Engineer' );
ss_eq( 'CollegeOrUniversity', $pc['alumniOf'][0]['@type'] ?? null, 'alumniOf entries are CollegeOrUniversity' );
ss_eq( 'Full Sail University', $pc['alumniOf'][0]['name'] ?? null, 'alumniOf includes Full Sail University' );
ss_eq( 'Westcliff University', $pc['alumniOf'][1]['name'] ?? null, 'alumniOf includes Westcliff University' );
ss_true( in_array( 'Valedictorian, Full Sail University', (array) ( $pc['award'] ?? array() ), true ), 'award includes Full Sail valedictorian' );
ss_eq( 'The Recording Academy', $pc['memberOf'][0]['name'] ?? null, 'memberOf includes The Recording Academy' );
ss_eq( 'The Latin Recording Academy', $pc['memberOf'][1]['name'] ?? null, 'memberOf includes The Latin Recording Academy' );
ss_eq( 'Panacea', $pc['worksFor']['name'] ?? null, 'worksFor === Panacea' );
ss_eq( '2016', $pc['worksFor']['foundingDate'] ?? null, 'worksFor.foundingDate === 2016' );

// ─── T7: ProfilePage on identity pages (mainEntity → Person) ───
echo "\nT7: ProfilePage on identity pages\n";
$GLOBALS['__ss']['is_singular_post'] = true;
$GLOBALS['__ss']['is_page']          = true;
$GLOBALS['__ss']['queried']          = (object) array( 'ID' => 9, 'post_title' => 'About', 'post_name' => 'about' );
$pp = sn_schema_webpage();
ss_eq( 'ProfilePage', $pp['@type'] ?? null, 'about → @type ProfilePage' );
ss_eq( 'https://example.com/#/schema/Person', $pp['mainEntity']['@id'] ?? null, 'ProfilePage.mainEntity → Person @id' );
$GLOBALS['__ss']['queried'] = (object) array( 'ID' => 10, 'post_title' => 'Random', 'post_name' => 'random-page' );
$wpg = sn_schema_webpage();
ss_eq( 'WebPage', $wpg['@type'] ?? null, 'non-identity page stays WebPage' );
ss_true( ! isset( $wpg['mainEntity'] ), 'non-identity page has no mainEntity' );

// ─── T8: ProfessionalService + OfferCatalog on /services ───
echo "\nT8: ProfessionalService + OfferCatalog on /services\n";
$GLOBALS['__ss']['is_page'] = true; // is_page('services') stub honors this flag
$GLOBALS['__ss']['queried'] = (object) array( 'ID' => 11, 'post_title' => 'Services', 'post_name' => 'services' );
$svc = sn_schema_professional_service();
ss_eq( 'ProfessionalService', $svc['@type'] ?? null, '@type === ProfessionalService' );
ss_eq( 'https://example.com/#/schema/Person', $svc['provider']['@id'] ?? null, 'provider → Person @id' );
ss_eq( 'OfferCatalog', $svc['hasOfferCatalog']['@type'] ?? null, 'hasOfferCatalog.@type === OfferCatalog' );
ss_true( count( (array) ( $svc['hasOfferCatalog']['itemListElement'] ?? array() ) ) === 6, 'catalog lists all 6 offerings' );
ss_eq( 'Production', $svc['hasOfferCatalog']['itemListElement'][0]['itemOffered']['name'] ?? null, 'first offer === Production' );
ss_eq( 'Service', $svc['hasOfferCatalog']['itemListElement'][0]['itemOffered']['@type'] ?? null, 'offer.itemOffered.@type === Service' );
$GLOBALS['__ss']['is_page'] = false;
ss_eq( null, sn_schema_professional_service(), 'not on /services → null (no ProfessionalService node)' );

// ─── v6.24.0: theme virtual-route WebPage + breadcrumb (sn_seo_route_meta) ──
$GLOBALS['__ss']['is_singular_post'] = false;
$GLOBALS['__ss']['is_home']          = false;
$GLOBALS['__ss']['is_page']          = false;
$GLOBALS['__ss']['is_front_page']    = false;
$GLOBALS['__route_meta'] = null;
$GLOBALS['__img_dims']   = null;
if ( ! function_exists( 'sn_seo_route_meta' ) ) {
	function sn_seo_route_meta() { return $GLOBALS['__route_meta']; }
}
if ( ! function_exists( 'sn_seo_image_dimensions' ) ) {
	function sn_seo_image_dimensions( $url ) { return $GLOBALS['__img_dims']; }
}

$GLOBALS['__route_meta'] = array(
	'title'       => 'Uses — Juan Lentino',
	'description' => 'The hardware and software behind the work.',
	'url'         => 'https://example.com/about/uses',
	'breadcrumb'  => array(
		array( 'name' => 'About', 'url' => 'https://example.com/about/' ),
		array( 'name' => 'Uses',  'url' => 'https://example.com/about/uses' ),
	),
);
$rw = sn_schema_webpage();
ss_eq( 'WebPage', $rw['@type'] ?? null, 'route: WebPage built from sn_seo_route_meta' );
ss_eq( 'https://example.com/about/uses', $rw['@id'] ?? null, 'route: WebPage @id === route url' );
ss_eq( 'https://example.com/#/schema/WebSite', $rw['isPartOf']['@id'] ?? null, 'route: WebPage isPartOf → WebSite @id (connected graph)' );
ss_eq( 'The hardware and software behind the work.', $rw['description'] ?? null, 'route: WebPage carries the route description' );
$rb = sn_schema_breadcrumb_list();
ss_eq( 'BreadcrumbList', $rb['@type'] ?? null, 'route: BreadcrumbList built from route trail' );
ss_eq( 3, count( (array) ( $rb['itemListElement'] ?? array() ) ), 'route: trail is Home → About → Uses (3 items)' );
ss_eq( 'Uses', $rb['itemListElement'][2]['name'] ?? null, 'route: last crumb name === Uses' );
ss_eq( 'https://example.com/about/uses#breadcrumb', $rb['@id'] ?? null, 'route: breadcrumb @id anchors on the route url' );
$GLOBALS['__route_meta'] = null;
ss_eq( null, sn_schema_webpage(), 'no route + not singular → WebPage null (unchanged)' );

// ─── v6.24.0: Person.image declares real dimensions when measurable ──
$GLOBALS['__ss']['settings']['og.default_image_url'] = 'https://example.com/wp-content/uploads/logo.png';
$GLOBALS['__img_dims'] = array( 512, 512 );
$pimg = sn_schema_person();
ss_eq( 512, $pimg['image']['width'] ?? null, 'Person.image width set when measurable' );
ss_eq( 512, $pimg['image']['height'] ?? null, 'Person.image height set when measurable' );
$GLOBALS['__img_dims'] = null;
$pimg2 = sn_schema_person();
ss_true( isset( $pimg2['image'] ) && ! isset( $pimg2['image']['width'] ), 'Person.image omits dimensions when size unknown (never guesses)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
