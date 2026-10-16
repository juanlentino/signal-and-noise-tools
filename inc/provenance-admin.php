<?php
/**
 * Signal & Noise Tools — Notes provenance: admin (Tools tab + live status).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/provenance-admin-glance.php';

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/provenance-admin-status-parts.php';
require_once __DIR__ . '/provenance-admin-render-parts.php';
require_once __DIR__ . '/provenance-admin-fieldsets-parts.php';
