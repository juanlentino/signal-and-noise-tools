<?php
/**
 * Settings › Accounts and connections with the IndexNow and Search Console
 * modules absent: the Search engines row paints no heading over nothing, on
 * either twin, and Records and profiles still paints.
 *
 * Run: php tests/os-leaf-settings-accounts-rows-absent.php
 *
 * @package SignalNoiseTools
 */
$GLOBALS['__acc_skip'] = array( 'inc/indexnow.php', 'inc/admin-forms/indexnow.php', 'inc/admin-post-actions/indexnow.php', 'apps/sn-dashboard/parts/leaves/connections-indexnow.php', 'inc/search-console-credential.php', 'inc/search-console-admin.php', 'inc/admin-post-actions/gsc.php' );
require_once __DIR__ . '/lib/settings-accounts-fixture.php';

$classic = acc_classic();
$kit     = acc_kit();
ok( '' !== $classic && '' !== $kit, 'both twins paint' );
ok( ! function_exists( 'sn_indexnow_is_enabled' ) && ! function_exists( 'snt_gsc_copy' ), 'the two modules really are absent' );
ok( false === strpos( $classic, '>Search engines</h2>' ) && false === strpos( $kit, '>Search engines</h3>' ), 'no Search engines heading over an empty row, on either twin' );
ok( false !== strpos( $classic, '>Records and profiles</h2>' ) && false !== strpos( $kit, '>Records and profiles</h3>' ), 'Records and profiles still paints on both twins' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
