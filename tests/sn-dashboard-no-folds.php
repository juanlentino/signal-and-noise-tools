<?php
/**
 * Folds on S&N Home are earned, one by one (owner, 2026-10-11: no collapsible
 * sections; 2026-10-12: "The collapsible options should be thought. Some
 * places earn it, other don't.").
 *
 * A source-level guard over every painter of the dashboard app
 * (apps/sn-dashboard/**.php) and the North star form it paints
 * (inc/north-star-settings.php): no raw `os-disclosure`, `<details` or
 * `sn-disclosure` in CODE, and every fold call is in $earned below with the
 * reason it earns the fold. A fold call is `snt_kit_fold(` or one of the leaf
 * anatomy helpers that paint folds (`snt_kit_setup_card(`, `snt_kit_how_fold(`,
 * inc/openstation-kit-anatomy.php), so a leaf cannot fold through a helper
 * unlisted; the helper file itself is read too (inc/openstation-kit-fold.php has the
 * three tests: reference or setup, a summary that answers, open when it needs
 * you). Comments are stripped first (PHP tokenizer), so a docblock may still
 * name the fold it replaced.
 *
 * Run: php tests/sn-dashboard-no-folds.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0; $fail = 0;

// file => the reason the fold is earned. One call per file listed.
$earned = array(
	'apps/sn-dashboard/parts/leaves/ai-agent-tools-doors.php' => 'The read, write and remote doors (one call, agent_tools_door_fold()): reference, every tool a door exposes as a chip with its calls (the whole page measured 2,943px live with every list open, 2026-10-12 audit); the summary states the count, the largest category and the calls; open when a tool there cannot be projected',
	'apps/sn-dashboard/parts/leaves/ai-mcp-connect-steps.php' => 'Connect a client: setup, done once per client; the summary names the clients; open while the user has no Application Password',
	'apps/sn-dashboard/parts/leaves/tools-lanes.php'          => 'Integrity › Lanes: the reason split and the last 14 days are detail behind a counted summary (pairs, days stored), and how a lane is decided is reference; the by-lane tables, the invalid-token line and the notes paint open',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-setup.php' => 'Measurement › Analytics (one call, snt_kit_setup_card()): the Setup card\'s settings (credentials, collector, roles kept out, tuning, funnels, the Worker and salt, configured elsewhere) are setup or reference, changed once in a while; each summary is the setting\'s current state; credentials open while the pipeline is incomplete, the collector while unset, the Worker while its version or salt was not read. Warnings stay open on the Pipeline card',
	'apps/sn-dashboard/parts/leaves/monitoring-analytics-owner.php' => 'Your devices\' How this works (snt_kit_how_fold()): reference, the marker explained and what Revoke all leaves to do; its summary states the mechanism; Revoke all\'s confirm repeats the hint before anything is revoked',
	'apps/sn-dashboard/parts/leaves/content-scan-parts.php' => 'Content › Block migrations and Pattern adoption, How this works (snt_kit_how_fold(), 2026-10-16 anatomy): what the scanner looks for, reference that was a box of its own beside the status; its summary states the mechanism (structural, no AI, reviewed per row). The count, the scan button, when it ran, the queue and how a row is worked (the queue\'s description, beside Dismiss) stay open',
	'apps/sn-dashboard/parts/leaves/ai-models-budget-status.php' => 'AI › Models & budget, How this works (snt_kit_how_fold(), 2026-10-16 anatomy): the leaf\'s intro paragraph, reference; its summary states the rule (one budget, Copilot and agents outside it). The cap notice and the billing notice stay open on their boxes, and the status line repeats them',
	'apps/sn-dashboard/parts/leaves/ai-models-budget-setup.php' => 'AI › Models & budget, Setup (snt_kit_setup_card(), 2026-10-16 anatomy): the whole ai_settings_save form (models, budget, Jev credit) is setup, changed once in a while; its summary states the models and the cap. The Workers AI token fold (a keyring row this leaf only reads) opens while it is not set',
	'apps/sn-dashboard/parts/leaves/monitoring-insights.php' => 'Measurement › Insights\' How this works (snt_kit_how_fold()): reference, what one scan reads and returns; its summary states it in a sentence; the no-scan warning, the AI-unavailable notice and the scan status stay open in their cards',
	'apps/sn-dashboard/parts/leaves/monitoring-insights-setup.php' => 'Measurement › Insights\' Setup card (snt_kit_setup_card(), one fold): the weekly scan is a setting changed once in a while; its summary is its value, On or Off',
	'apps/sn-dashboard/parts/leaves/monitoring-rss-setup.php' => 'Measurement › RSS\' Setup card (snt_kit_setup_card()): Settings and Maintenance are setup, changed once in a while; Settings\' summary is tracking, event and retention, Maintenance\'s the daily prune; Settings opens while its required event name is empty. Tracking off is a warning chip on the open status line',
	'apps/sn-dashboard/parts/leaves/monitoring-machine-readers-setup.php' => 'Measurement › Machine readers\' Setup card (snt_kit_setup_card()): the worker readout is reference and the sensor settings setup; summaries are the worker version and the token state; each opens while unread or tokenless. Every pill and its warning stay open on the status line',
	'apps/sn-dashboard/parts/leaves/monitoring-search-console-setup.php' => 'Measurement › Search Console\'s Setup card (snt_kit_setup_card(), one fold): the credential form is used once per key; its summary is the credential\'s state; open until a key parses. The onboarding steps and the "no longer parses" warning stay open in the readout box',
	'inc/openstation-kit-anatomy.php' => 'The leaf anatomy helpers (2 calls: snt_kit_setup_card() folds each setting, snt_kit_how_fold() folds explanatory prose): they only paint folds a leaf asks for, and every leaf that calls one is listed here on its own line',
	'apps/sn-dashboard/parts/leaves/security-login.php'       => 'Security › Login URL (2 calls): How this works folds the module explained (reference; the state is the status line, a dormant or bypassed module keeps its open warning), and the Setup card\'s Emergency unlock folds the wp-config.php constants (reference for a lockout; its summary names the constant that is set, open while one is)',
	'apps/sn-dashboard/parts/leaves/site-performance.php'     => 'Site › Performance (2 calls): How this works folds the Speculation Rules intro; the Setup card folds the setting (summary On or Off, changed once in a while) and the Profile reference (summary its mode). The state is the status line; failed outbound calls stay an open warning on the ledger',
	'apps/sn-dashboard/parts/leaves/security-audit-log.php'   => 'Security › Audit log: the Setup card\'s Retention folds the one setting (summary the window in days, changed once in a while); the glance, the counter timeline, the logins ledger, lockouts and Prune now stay open',
	'apps/sn-dashboard/parts/leaves/connections-cron.php'     => 'Scheduled events: a reference list (91 events, 5,473px open); the summary counts events, overdue and unhandled; open when one is overdue or unhandled, when overdue cannot be read, or while a filter is typed',
);
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root  = dirname( __DIR__ );
$files = array( $root . '/inc/north-star-settings.php', $root . '/inc/openstation-kit-anatomy.php' );
// How many fold calls a file may make: one, unless named here.
$calls = array(
	'inc/openstation-kit-anatomy.php'                     => 2,
	'apps/sn-dashboard/parts/leaves/security-login.php'   => 2,
	'apps/sn-dashboard/parts/leaves/site-performance.php' => 2,
);
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/apps/sn-dashboard', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );
ok( count( $files ) > 60, 'the guard reads the whole app: ' . count( $files ) . ' files' );

$offenders = array();
$folds     = array();
foreach ( $files as $path ) {
	$code = '';
	foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	$n = 0;
	foreach ( array( 'snt_kit_fold(', 'snt_kit_setup_card(', 'snt_kit_how_fold(' ) as $call ) {
		$n += substr_count( $code, $call );
	}
	// The anatomy file's declarations are not calls.
	$n -= preg_match_all( '/function\s+(?:snt_kit_fold|snt_kit_setup_card|snt_kit_how_fold)\(/', $code );
	if ( $n > 0 ) {
		$folds[ str_replace( $root . '/', '', $path ) ] = $n;
	}
	foreach ( array( 'os-disclosure', '<details', 'sn-disclosure' ) as $marker ) {
		$n = substr_count( strtolower( $code ), $marker );
		if ( $n > 0 ) {
			$offenders[] = str_replace( $root . '/', '', $path ) . " ($marker x$n)";
		}
	}
}
ok( array() === $offenders, 'no raw fold markup anywhere on S&N Home (a fold goes through snt_kit_fold): ' . ( $offenders ? implode( ', ', $offenders ) : 'none' ) );
$unlisted = array_diff_key( $folds, $earned );
ok( array() === $unlisted, 'every fold on S&N Home is listed with the reason it is earned: ' . ( $unlisted ? implode( ', ', array_keys( $unlisted ) ) : 'none unlisted' ) );
$stale = array_diff_key( $earned, $folds );
ok( array() === $stale && array() === array_filter( $folds, static fn( $n, $f ) => ( $calls[ $f ] ?? 1 ) !== $n, ARRAY_FILTER_USE_BOTH ), 'each listed fold is painted, once (the anatomy helpers twice, one call each): ' . json_encode( $folds ) );

// The helper's own contract: no summary, no fold; open only when asked.
define( 'ABSPATH', $root . '/' );
require_once $root . '/inc/openstation-kit.php';
require_once $root . '/inc/openstation-kit-display.php';
$closed = snt_kit_fold( 'Setup', 'Two steps, both done', '<p>x</p>' );
ok( 0 === strpos( $closed, '<os-disclosure heading="Setup" hint="Two steps, both done"' ) && 0 === preg_match( '/\sopen[\s=>]/', $closed ), 'a fold paints its summary and starts closed' );
ok( false !== strpos( $closed, 'data-snt-fold="setup"' ) && false !== strpos( snt_kit_fold( 'Connect a client', 'x', 'y' ), 'data-snt-fold="connect-a-client"' ), 'a fold carries the key the host remembers the reader\'s toggle under (tests/os-host-fold-memory.cjs)' );
ok( 0 === strpos( snt_kit_fold_key( 'Подключение' ), 'f-' ) && 12 === strlen( snt_kit_fold_key( 'Подключение' ) ) && false !== strpos( $closed, 'data-snt-open="0"' ) && false !== strpos( snt_kit_fold( 'Setup', 'x', 'y', true ), 'data-snt-open="1"' ), 'a heading with no Latin letters still gets a key, and the fold carries the page\'s verdict the host compares against' );
ok( false !== strpos( $closed, '<os-stack gap="12"><p>x</p></os-stack></os-disclosure>' ), 'a fold stacks its content with the gap an open section gives it' );
ok( 1 === preg_match( '/<os-disclosure[^>]*\sopen[\s=>]/', snt_kit_fold( 'Setup', 'One step left', '<p>x</p>', true ) ), 'a fold that needs the reader paints open' );
ok( false === strpos( snt_kit_fold( 'Setup', ' ', '<p>x</p>' ), 'os-disclosure' ), 'a fold with no summary paints its content open, as a section: closing may hide detail, never the only statement of a fact' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
