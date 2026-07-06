<?php
/**
 * PHPStan bootstrap — core WordPress constants for static analysis ONLY.
 *
 * php-stubs/wordpress-stubs ships function/class signatures but almost no
 * runtime constants (ABSPATH, *_IN_SECONDS, ARRAY_A, EP_*, …) — WordPress
 * define()s those in wp-settings.php / default-constants.php at boot, which the
 * stub generator does not capture. We used to inherit them from
 * szepeviktor/phpstan-wordpress' bundled bootstrap.php; that extension is now
 * abandoned (and capped stubs at <7.0), so this file replaces it. Referenced by
 * phpstan.neon `bootstrapFiles`; NEVER loaded by WordPress at runtime.
 *
 * defined()-guarded so it is a harmless no-op in the (impossible) event it is
 * ever included in a live WP process — it will never redefine a real constant.
 * Excluded from the WP-handbook phpcs ruleset (dev-only tooling, not shipped code).
 *
 * @package Signal_And_Noise_Tools
 */

// Directory + debug + misc core constants.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
defined( 'WPMU_PLUGIN_DIR' ) || define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
defined( 'WP_LANG_DIR' ) || define( 'WP_LANG_DIR', WP_CONTENT_DIR . '/languages' );
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', true );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
defined( 'SCRIPT_DEBUG' ) || define( 'SCRIPT_DEBUG', false );
defined( 'EMPTY_TRASH_DAYS' ) || define( 'EMPTY_TRASH_DAYS', 30 );
defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', '' );
defined( 'WP_DEFAULT_THEME' ) || define( 'WP_DEFAULT_THEME', 'twentytwentyfive' );

// Human-readable time intervals.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// Human-readable byte sizes.
defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
defined( 'GB_IN_BYTES' ) || define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
defined( 'TB_IN_BYTES' ) || define( 'TB_IN_BYTES', 1024 * GB_IN_BYTES );
defined( 'PB_IN_BYTES' ) || define( 'PB_IN_BYTES', 1024 * TB_IN_BYTES );

// wpdb::get_results() output types.
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

// WP_Filesystem defaults.
defined( 'FS_CONNECT_TIMEOUT' ) || define( 'FS_CONNECT_TIMEOUT', 30 );
defined( 'FS_TIMEOUT' ) || define( 'FS_TIMEOUT', 30 );
defined( 'FS_CHMOD_DIR' ) || define( 'FS_CHMOD_DIR', 0755 );
defined( 'FS_CHMOD_FILE' ) || define( 'FS_CHMOD_FILE', 0644 );

// Rewrite API endpoint masks.
defined( 'EP_NONE' ) || define( 'EP_NONE', 0 );
defined( 'EP_PERMALINK' ) || define( 'EP_PERMALINK', 1 );
defined( 'EP_ATTACHMENT' ) || define( 'EP_ATTACHMENT', 2 );
defined( 'EP_DATE' ) || define( 'EP_DATE', 4 );
defined( 'EP_YEAR' ) || define( 'EP_YEAR', 8 );
defined( 'EP_MONTH' ) || define( 'EP_MONTH', 16 );
defined( 'EP_DAY' ) || define( 'EP_DAY', 32 );
defined( 'EP_ROOT' ) || define( 'EP_ROOT', 64 );
defined( 'EP_COMMENTS' ) || define( 'EP_COMMENTS', 128 );
defined( 'EP_SEARCH' ) || define( 'EP_SEARCH', 256 );
defined( 'EP_CATEGORIES' ) || define( 'EP_CATEGORIES', 512 );
defined( 'EP_TAGS' ) || define( 'EP_TAGS', 1024 );
defined( 'EP_AUTHORS' ) || define( 'EP_AUTHORS', 2048 );
defined( 'EP_PAGES' ) || define( 'EP_PAGES', 4096 );
defined( 'EP_ALL_ARCHIVES' ) || define( 'EP_ALL_ARCHIVES', EP_DATE | EP_YEAR | EP_MONTH | EP_DAY | EP_CATEGORIES | EP_TAGS | EP_AUTHORS );
defined( 'EP_ALL' ) || define( 'EP_ALL', EP_PERMALINK | EP_ATTACHMENT | EP_ROOT | EP_COMMENTS | EP_SEARCH | EP_PAGES | EP_ALL_ARCHIVES );

// Runtime "context" constants — defined so references resolve; marked dynamic in
// phpstan.neon (dynamicConstantNames) so PHPStan does not fold them to a fixed value.
defined( 'DOING_CRON' ) || define( 'DOING_CRON', false );
defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', false );
defined( 'DOING_AUTOSAVE' ) || define( 'DOING_AUTOSAVE', false );
defined( 'WP_CLI' ) || define( 'WP_CLI', false );
defined( 'REST_REQUEST' ) || define( 'REST_REQUEST', false );

// This plugin's own constants — defined at runtime in signal-and-noise-tools.php
// (SNT_PATH/SNT_URL via plugin_dir_path()/plugin_dir_url()). PHPStan does not trace
// the require() chain that defines them before the inc/ files use them, so declare
// them here (values are placeholders; marked dynamic in phpstan.neon). A typo'd
// variant (e.g. SNT_PTH) is absent here and still fails — the gate stays honest.
defined( 'SNT_PATH' ) || define( 'SNT_PATH', '/' );
defined( 'SNT_URL' ) || define( 'SNT_URL', 'https://example.test/' );
defined( 'SNT_VERSION' ) || define( 'SNT_VERSION', '0.0.0' );
