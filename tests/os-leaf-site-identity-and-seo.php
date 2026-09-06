<?php
/**
 * Native window leaf: Site → Identity & SEO (apps/sn-dashboard/parts/leaves/site-identity-and-seo.php).
 *
 * The oracle is the classic composite leaf: the section strip the dispatcher
 * paints (sn_admin_render_section_tabs) plus sn_admin_render_identity_and_seo_form().
 * The kit form must carry the same fields with the same values, the one
 * sn_action, the four sections as a tab strip + panels, every helper line,
 * and none of wp-admin's markup. The plugin's OWN reader and writer
 * (inc/settings.php) run under the harness, so the last pin is a round trip:
 * the kit's payload, expanded the way the host expands a dispatch, through
 * sn_settings_save(), back out through sn_setting().
 *
 * Run: php tests/os-leaf-site-identity-and-seo.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// What inc/settings.php needs beyond the harness.
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'name' === $k ? 'Example Site' : ( 'description' === $k ? 'Just another site' : '' ); } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $s ) ), '-' ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }

require SNT_PATH . 'inc/settings.php';                   // sn_setting(), sn_settings_save(), sn_setting_reset_cache().
require SNT_PATH . 'inc/admin-tabs.php';                 // sn_admin_render_section(), sn_admin_render_section_tabs().
require SNT_PATH . 'inc/openstation-host-pipelines.php'; // snt_os_host_expand(): the wire bag as PHP's $_POST.
require SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-identity-and-seo.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** Store a settings fixture and drop the reader's per-request memo. */
function fixture( array $stored ) { $GLOBALS['__options']['sn_settings'] = $stored; sn_setting_reset_cache(); }

/** `foo[3]` reads as `foo[]`: the spelling PHP's body parser folds together. */
function names_folded( array $names ) { $n = array_values( array_unique( array_map( static function ( $x ) { return preg_replace( '/\[\d+\]$/', '[]', $x ); }, $names ) ) ); sort( $n ); return $n; }

/**
 * Every field's value, from either markup. $last_wins mirrors os-form's
 * getValues() (a map keyed by the literal name); otherwise a repeated name
 * accumulates, which is what FormData does for a native form.
 */
function field_values( $html, $last_wins ) {
	preg_match_all( '/<(input|textarea|os-text-field|os-textarea|os-number-field)\b([^>]*)>(?:([^<]*)<\/textarea>)?/', (string) $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $t ) {
		if ( ! preg_match( '/\sname="([^"]+)"/', $t[2], $n ) ) { continue; }
		$name  = html_entity_decode( $n[1], ENT_QUOTES );
		$value = 'textarea' === $t[1] ? $t[3] : ( preg_match( '/\svalue="([^"]*)"/', $t[2], $v ) ? $v[1] : '' );
		$value = html_entity_decode( $value, ENT_QUOTES );
		if ( $last_wins || ! array_key_exists( $name, $out ) ) { $out[ $name ] = $value; }
		elseif ( is_array( $out[ $name ] ) ) { $out[ $name ][] = $value; }
		else { $out[ $name ] = array( $out[ $name ], $value ); }
	}
	return $out;
}

/** The map with `social_same_as[]` / `social_same_as[N]` folded into one ordered array. */
function folded_values( array $values ) {
	$out = array( 'social_same_as' => array() );
	foreach ( $values as $name => $value ) {
		if ( preg_match( '/^social_same_as\[(\d*)\]$/', $name ) ) { foreach ( (array) $value as $v ) { $out['social_same_as'][] = $v; } }
		else { $out[ $name ] = $value; }
	}
	ksort( $out );
	return $out;
}

/**
 * One field's label + control attributes, read from its own attribute
 * string (never from a global substring search) so a value can only pass
 * by actually sitting on the right field.
 *
 * @param string $label Raw (unescaped) label text, or ''.
 * @param string $type  Resolved control type (text|url|number|textarea|…).
 * @param string $attrs The control tag's own attribute string.
 * @return array{label:string,type:string,placeholder:?string,min:?string,max:?string,rows:?string}
 */
function bound_row( $label, $type, $attrs ) {
	$get = static function ( $attr ) use ( $attrs ) {
		return preg_match( '/\s' . $attr . '="([^"]*)"/', (string) $attrs, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : null;
	};
	return array(
		'label'       => html_entity_decode( trim( (string) $label ), ENT_QUOTES ),
		'type'        => (string) $type,
		'placeholder' => $get( 'placeholder' ),
		'min'         => $get( 'min' ),
		'max'         => $get( 'max' ),
		'rows'        => $get( 'rows' ),
	);
}

/**
 * Per-field bound map: name => bound_row(). KIT reads the label off the
 * field's OWN `<os-field-row label>` wrapper and the type off its OWN
 * control tag; CLASSIC reads the label off the `<label for="sn_<name>">`
 * this file always emits (id = "sn_" + name throughout) and the type off
 * that SAME control's own tag — so a value, placeholder, min or label can
 * no longer drift onto the wrong field and still read as present somewhere
 * in the document. Excludes the repeatable sameAs rows: those carry a
 * group label, not a per-row `for`/`os-field-row label` pairing, and are
 * asserted separately (accepted drift, see the parts file's docblock).
 *
 * @param string $html HTML to scan.
 * @param bool   $kit  True for kit markup, false for classic.
 * @return array<string,array>
 */
function bound_attrs( $html, $kit ) {
	$html = (string) $html;
	$out  = array();
	if ( $kit ) {
		preg_match_all( '/<os-field-row\b([^>]*)>(.*?)<\/os-field-row>/s', $html, $rows, PREG_SET_ORDER );
		foreach ( $rows as $row ) {
			if ( ! preg_match( '/<(os-text-field|os-number-field|os-textarea|os-select)\b([^>]*)>/', $row[2], $cm ) ) { continue; }
			if ( ! preg_match( '/\sname="([^"]+)"/', $cm[2], $nm ) ) { continue; }
			$name = html_entity_decode( $nm[1], ENT_QUOTES );
			if ( 0 === strpos( $name, 'social_same_as' ) || in_array( $name, array( 'sn_action', '_wpnonce' ), true ) ) { continue; }
			preg_match( '/\slabel="([^"]*)"/', $row[1], $lm );
			$type = 'os-number-field' === $cm[1] ? 'number' : ( 'os-textarea' === $cm[1] ? 'textarea' : ( preg_match( '/\stype="([^"]*)"/', $cm[2], $tm ) ? $tm[1] : 'text' ) );
			$out[ $name ] = bound_row( $lm[1] ?? '', $type, $cm[2] );
		}
	} else {
		preg_match_all( '/<(input|textarea)\b([^>]*)\bname="([^"]+)"([^>]*)>(?:([^<]*)<\/textarea>)?/', $html, $ctrls, PREG_SET_ORDER );
		foreach ( $ctrls as $c ) {
			$name = html_entity_decode( $c[3], ENT_QUOTES );
			if ( 0 === strpos( $name, 'social_same_as' ) || in_array( $name, array( 'sn_action', '_wpnonce' ), true ) ) { continue; }
			$attrs = $c[2] . ' name="' . $c[3] . '"' . $c[4];
			preg_match( '/<label\b[^>]*\sfor="sn_' . preg_quote( $name, '/' ) . '"[^>]*>([^<]*)<\/label>/', $html, $lm );
			$type = 'textarea' === $c[1] ? 'textarea' : ( preg_match( '/\stype="([^"]*)"/', $attrs, $tm ) ? $tm[1] : 'text' );
			$out[ $name ] = bound_row( $lm[1] ?? '', $type, $attrs );
		}
	}
	return $out;
}

$rich = array(
	'identity' => array( 'site_name' => 'Signal & Noise', 'site_description' => 'Notes on music & provenance', 'person_name' => 'Juan Example', 'job_title' => 'Mixing Engineer', 'availability' => 'Booking Q4 mixes', 'knows_about' => array( 'Mixing', 'Mastering', 'Provenance' ), 'locale' => 'es_AR' ),
	'social'   => array( 'twitter_handle' => '@juan', 'same_as' => array( 'https://a.example/juan', 'https://b.example/juan' ) ),
	'og'       => array( 'default_image_url' => 'https://cdn.example/og.png', 'card_width' => 1600, 'card_height' => 900 ),
	'seo_copy' => array( 'home_title' => 'Home T', 'home_description' => 'Home D', 'notes_title' => 'Notes T', 'notes_description' => 'Notes D', 'provenance_title' => 'Prov T', 'provenance_description' => 'Prov D' ),
);

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/identity-and-seo'] ), 'the painter is registered under site/identity-and-seo' );

// ── The rich fixture: the same fields, the one action, no wp-admin markup.
fixture( $rich );
$classic_strip = snt_leaf_classic_html( static function () { sn_admin_render_section_tabs( 'site', 'identity-and-seo' ); } );
$classic       = snt_leaf_classic_html( 'sn_admin_render_identity_and_seo_form' );
$kit           = snt_leaf_paint( 'site', 'identity-and-seo' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === names_folded( snt_leaf_names( $kit ) ), 'field names match the classic form (sameAs rows indexed, folded): kit ' . implode( ',', snt_leaf_names( $kit ) ) . ' | classic ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( array( 'save_identity' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is save_identity, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<noscript' ) && false === strpos( $kit, 'sn-add-row-btn' ), 'no wp-admin markup, no noscript, no dead add-row button survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( 1 === substr_count( $kit, '<os-form' ) && false !== strpos( $kit, 'os-action="post"' ) && false !== strpos( $kit, 'submit-label="Save Identity Settings"' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'ONE os-form dispatching post, labelled as the classic save bar, on the shared pipeline (the classic form posts to its own URL)' );
ok( false !== strpos( $kit, 'name="sn_action" value="save_identity"' ) && false !== strpos( $kit, 'name="_wpnonce" value="nonce-sn_theme_options_nonce"' ), 'sn_action and the shared nonce ride as hidden fields' );

// ── The section strip: the classic anchors as tabs + panels, first open.
preg_match_all( '/href="#sn-sec-([a-z-]+)"[^>]*>([^<]+)</', $classic_strip, $cs, PREG_SET_ORDER );
$strip_ok = 4 === count( $cs );
foreach ( $cs as $s ) {
	$strip_ok = $strip_ok && false !== strpos( $kit, '<os-tab value="' . $s[1] . '">' . $s[2] . '</os-tab>' ) && false !== strpos( $kit, '<os-tabpanel for="' . $s[1] . '" id="sn-sec-' . $s[1] . '">' );
}
ok( $strip_ok, 'the four classic sections are os-tab + os-tabpanel pairs keyed by the classic #sn-sec-<slug> anchors' );
ok( false !== strpos( $kit, '<os-tabs class="snt-subtabs" value="identity" label="Identity &amp; SEO sections">' ) && false === strpos( $kit, 'os-bind' ), 'the strip opens on Identity (the classic is-active) and is not bound to state (client-side swap)' );
ok( false !== strpos( $kit, '<p slot="footer-leading" class="snt-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>' ), 'the save-bar hint sits beside the submit button' );

// ── Every readout: per-field value parity with the classic, then the prose.
$classic_values = folded_values( field_values( $classic, false ) );
$kit_values     = folded_values( field_values( $kit, true ) );
ok( $classic_values === $kit_values, 'every field carries the classic value (' . count( $kit_values ) . ' fields, sameAs = ' . json_encode( $kit_values['social_same_as'] ) . ')' . ( $classic_values === $kit_values ? '' : ' diff: ' . json_encode( array_diff_assoc( array_map( 'json_encode', $classic_values ), array_map( 'json_encode', $kit_values ) ) ) ) );
ok( array( 'https://a.example/juan', 'https://b.example/juan', '' ) === $kit_values['social_same_as'] && false !== strpos( $kit, 'name="social_same_as[2]" type="url" value=""' ), 'the stored sameAs rows paint plus ONE empty row (the classic noscript shape)' );
$classic_bound = bound_attrs( $classic, false );
$kit_bound     = bound_attrs( $kit, true );
ok(
	count( $kit_bound ) >= 15 && $classic_bound === $kit_bound,
	'every field\'s label, type, placeholder, min/max and rows are bound to THAT field, not just present somewhere (' . count( $kit_bound ) . ' fields); diff: ' . json_encode(
		array_filter(
			array_map(
				static function ( $name ) use ( $classic_bound, $kit_bound ) {
					return ( $classic_bound[ $name ] ?? null ) !== ( $kit_bound[ $name ] ?? null )
						? array( $name => array( 'classic' => $classic_bound[ $name ] ?? null, 'kit' => $kit_bound[ $name ] ?? null ) )
						: null;
				},
				array_unique( array_merge( array_keys( $classic_bound ), array_keys( $kit_bound ) ) )
			)
		)
	)
);
$readouts = array(
	'Signal &amp; Noise', 'Notes on music &amp; provenance', 'Juan Example', 'Mixing Engineer', 'Booking Q4 mixes', "Mixing\nMastering\nProvenance", 'es_AR', '@juan', 'https://cdn.example/og.png', 'value="1600"', 'value="900"', 'Prov D',
	'<os-section heading="Identity" description="Site-wide name, description, and locale.">',
	'<os-section heading="Social" description="Twitter / X handle and profile URLs (emitted as schema sameAs).">',
	'<os-section heading="Open Graph" description="Fallback OG image and card dimensions for social shares.">',
	'<os-section heading="SEO Copy" description="Per-route title + description for the home, /notes, and /provenance pages.">',
	'Emitted as <os-code>jobTitle</os-code> on the Person schema. Single short phrase.',
	'A short status line surfaced in the <os-code>/contact</os-code> and <os-code>/services</os-code> page heroes. Leave empty to hide it.',
	'One topic per line. Emitted as the <os-code>knowsAbout</os-code> array on the Person schema',
	'WP locale code (e.g. <os-code>en_US</os-code>). Used for og:locale and schema inLanguage.',
	'hint="Used as twitter:site and twitter:creator. Include the @ prefix."',
	'hint="Fallback image used when no per-post OG card exists."',
	'Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.',
	'<span class="snt-field-static__k">Profile URLs (sameAs)</span>', 'placeholder="https://..."',
);
$missing = array_values( array_filter( $readouts, static function ( $s ) use ( $kit ) { return false === strpos( $kit, $s ); } ) );
ok( array() === $missing, 'every classic readout (values, headings, intros, helper lines, placeholders) is painted; missing: ' . implode( ' | ', $missing ) );

// ── The round trip: the kit's payload, through the host's expansion and the plugin's own writer.
$wire = field_values( $kit, true );
$post = snt_os_host_expand( $wire );
ok( array( 'https://a.example/juan', 'https://b.example/juan', '' ) === ( $post['social_same_as'] ?? null ) && 'save_identity' === ( $post['sn_action'] ?? '' ), 'indexed sameAs names expand to the social_same_as array the handler reads' );
$control = snt_os_host_expand( field_values( $classic, true ) );
ok( array( '' ) === ( $control['social_same_as'] ?? null ), 'CONTROL: the classic literal social_same_as[] names, collected the way os-form collects (last wins), keep ONE row -- the loss the indexed spelling exists to prevent' );
$post['identity_site_name'] = 'Renamed';
$post['seo_home_title']     = 'Home T2';
sn_settings_save( $post );
sn_setting_reset_cache();
$stored = (array) get_option( 'sn_settings' );
$again  = snt_leaf_paint( 'site', 'identity-and-seo' );
ok( array( 'https://a.example/juan', 'https://b.example/juan' ) === ( $stored['social']['same_as'] ?? null ) && array( 'Mixing', 'Mastering', 'Provenance' ) === ( $stored['identity']['knows_about'] ?? null ) && 1600 === ( $stored['og']['card_width'] ?? null ) && 'Prov D' === ( $stored['seo_copy']['provenance_description'] ?? null ), 'sn_settings_save() stores the kit payload exactly as the classic POST (sameAs, knowsAbout, OG ints, SEO copy)' );
ok( false !== strpos( $again, 'value="Renamed"' ) && false !== strpos( $again, 'value="Home T2"' ) && 3 === count( folded_values( field_values( $again, true ) )['social_same_as'] ), 'the repaint reads the saved values back through sn_setting(), still with one empty sameAs row' );

// ── Escaping: hostile stored values never reach the markup raw.
fixture( array( 'identity' => array( 'site_name' => '"><script>x</script>', 'knows_about' => array( '<b>bold</b>' ) ), 'social' => array( 'same_as' => array( 'javascript:alert(1)"><img src=x>' ) ) ) );
$kit = snt_leaf_paint( 'site', 'identity-and-seo' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&quot;&gt;&lt;script&gt;x&lt;/script&gt;' ) && false === strpos( $kit, '<img' ) && false !== strpos( $kit, '&lt;b&gt;bold&lt;/b&gt;' ), 'hostile site name, sameAs row and knowsAbout line are escaped' );

// ── The defaults state: an empty option paints the classic defaults.
fixture( array() );
$classic = snt_leaf_classic_html( 'sn_admin_render_identity_and_seo_form' );
$kit     = snt_leaf_paint( 'site', 'identity-and-seo' );
ok( folded_values( field_values( $classic, false ) ) === folded_values( field_values( $kit, true ) ), 'defaults: every field carries the classic default value' );
ok( false !== strpos( $kit, 'name="identity_job_title" type="text" value="Music Producer"' ) && false !== strpos( $kit, 'value="en_US"' ) && false !== strpos( $kit, 'value="1200"' ) && false !== strpos( $kit, 'value="630"' ) && false !== strpos( $kit, "Music Production\nAudio Engineering\nProvenance\nMusic Industry" ) && false !== strpos( $kit, 'value="Example Site"' ), 'defaults: Music Producer, en_US, 1200x630, the four knowsAbout topics, the blog name' );
ok( false !== strpos( $kit, 'name="social_same_as[0]" type="url" value=""' ) && false === strpos( $kit, 'social_same_as[1]' ), 'defaults: exactly one (empty) sameAs row' );

// ── The anchor state: a save or a go that named a section opens it (the classic hash).
foreach ( array( 'sn-sec-social' => 'social', 'sn-sec-open-graph' => 'open-graph', 'sn-sec-seo-copy' => 'seo-copy', 'sn-sec-nope' => 'identity', '' => 'identity' ) as $anchor => $expect ) {
	$kit = snt_leaf_paint( 'site', 'identity-and-seo', array( 'anchor' => $anchor ) );
	ok( false !== strpos( $kit, '<os-tabs class="snt-subtabs" value="' . $expect . '"' ), "anchor '$anchor' opens the $expect section" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
