<?php
/**
 * Signal & Noise Tools — Measurement → Search Console (the credential leaf).
 *
 * R6b step 0. One form, one job: give the service-account JSON somewhere to
 * land. The client that spends it (JWT grant → searchanalytics) is the next
 * slice; this leaf exists first so the owner's long-lead action — create the
 * service account, grant it the property — is not blocked on a build.
 *
 * WHY THE TEXTAREA IS ALWAYS EMPTY. The analytics token field echoes
 * sn_mask_secret() back into its input and the save handler ignores anything
 * starting with '••••'. That works for a 40-char token. A service-account key
 * is a multi-line ~1.7KB blob containing a PEM private key, and echoing even a
 * masked form of it into a textarea invites a partial edit that would corrupt
 * it silently. So nothing is ever echoed: when a credential is stored the
 * screen shows a NON-SECRET identity card (who the account is, which key, is
 * signing available) and the textarea stays empty with a replace affordance.
 * An empty submit means "leave it alone" — the only way to change a stored
 * credential is to paste a whole new one, and the only way to remove it is the
 * explicit 'clear' sentinel the rest of the plugin already uses.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Search Console leaf on the Measurement tab.
 *
 * Inserted after 'analytics': Measurement orders RECORDING surfaces before the
 * ones that interpret them (v10.47.0), and Search Console records what Google
 * saw. No 'wide' key — this is a single form and wants the wrapper's default
 * capped card; 'wide' STRIPS the card and would leave it chrome-less.
 *
 * @param array $tabs Existing Measurement sub-tabs.
 * @return array
 */
function snt_gsc_admin_register( $tabs ) {
	$tabs = (array) $tabs;
	$leaf = array(
		'label'  => 'Search Console',
		'render' => 'snt_gsc_render_settings_section',
	);
	$out = array();
	foreach ( $tabs as $key => $value ) {
		$out[ $key ] = $value;
		if ( 'analytics' === $key ) {
			$out['search-console'] = $leaf;
		}
	}
	if ( ! isset( $out['search-console'] ) ) {
		$out['search-console'] = $leaf;
	}
	return $out;
}

/**
 * The credential form.
 *
 * @since 11.18.0
 */
function snt_gsc_render_settings_section() {
	$identity = function_exists( 'snt_gsc_credential_identity' ) ? snt_gsc_credential_identity() : null;
	$stored   = function_exists( 'snt_gsc_credential_raw' ) ? '' !== snt_gsc_credential_raw() : false;

	echo '<form method="post" class="sn-gsc-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Search Console credential', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'A Google Cloud service-account key, granted read access to the Search Console property. Nothing here queries Google yet — this stores the credential the search reports will use.', 'signal-and-noise-tools' ) . '</p>';

	if ( null !== $identity ) {
		echo '<table class="widefat striped sn-gsc-identity"><tbody>';
		$rows = array(
			__( 'Service account', 'signal-and-noise-tools' ) => $identity['client_email'],
			__( 'Google Cloud project', 'signal-and-noise-tools' ) => $identity['project_id'],
			__( 'Key ID', 'signal-and-noise-tools' )          => $identity['private_key_id'],
			__( 'Key fingerprint', 'signal-and-noise-tools' ) => $identity['key_fingerprint'],
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" class="snt-col-20">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		// Signing readiness is reported HERE rather than discovered at the first
		// token request: the JWT grant is RS256 and this plugin carries no
		// composer dependencies, so openssl is the whole signing story.
		echo '<tr><th scope="row">' . esc_html__( 'Signing (RS256)', 'signal-and-noise-tools' ) . '</th><td>';
		if ( $identity['signing_ready'] ) {
			echo '<span class="sn-pill sn-pill--ok">' . esc_html__( 'openssl available', 'signal-and-noise-tools' ) . '</span>';
		} else {
			echo '<span class="sn-pill sn-pill--warn">' . esc_html__( 'openssl_sign() missing — this credential cannot mint a token on this host', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The private key is never displayed. To change the credential, paste a whole new key file below; to remove it, type clear.', 'signal-and-noise-tools' ) . '</p>';
	} elseif ( $stored ) {
		// Stored but no identity == stored but unparseable. Say so plainly
		// rather than rendering an empty card that reads as "not configured".
		echo '<p><span class="sn-pill sn-pill--warn">' . esc_html__( 'A credential is stored but no longer parses as a service-account key. Paste a fresh one, or type clear to remove it.', 'signal-and-noise-tools' ) . '</span></p>';
	} else {
		echo '<p><span class="sn-pill">' . esc_html__( 'Not configured.', 'signal-and-noise-tools' ) . '</span></p>';
		// Step 1 is FIRST because it is the one that was missing, and its absence
		// is indistinguishable from a permission problem: the credential is valid,
		// the token mints, the account is a user on the property, and the API still
		// answers 403 because it was never switched on in the project. Found the
		// hard way on the first real credential (v11.19.2).
		echo '<ol class="description">';
		echo '<li>' . esc_html__( 'In Google Cloud, ENABLE the "Google Search Console API" for the project. Skipping this returns 403 later even when everything else is correct — the error looks exactly like a missing permission.', 'signal-and-noise-tools' ) . '</li>';
		echo '<li>' . esc_html__( 'In that same project, create a service account and download a JSON key.', 'signal-and-noise-tools' ) . '</li>';
		echo '<li>' . esc_html__( 'In Search Console → Settings → Users and permissions, add that service account\'s email as a user on the property.', 'signal-and-noise-tools' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the whole JSON key file below, then Test connection.', 'signal-and-noise-tools' ) . '</li>';
		echo '</ol>';
	}

	echo '<p><label for="sn_gsc_credential"><strong>' . esc_html__( 'Service-account JSON', 'signal-and-noise-tools' ) . '</strong></label><br>';
	echo '<textarea id="sn_gsc_credential" name="sn_gsc_credential" rows="8" class="large-text code" spellcheck="false" autocomplete="off" placeholder="' . esc_attr( $stored ? __( 'Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one', 'signal-and-noise-tools' ) : __( '{ "type": "service_account", … }', 'signal-and-noise-tools' ) ) . '"></textarea></p>';

	echo '<p><button type="submit" name="sn_action" value="gsc_credential_save" class="button button-primary">' . esc_html__( 'Save credential', 'signal-and-noise-tools' ) . '</button>';
	if ( null !== $identity ) {
		echo ' <button type="submit" name="sn_action" value="gsc_test" class="button">' . esc_html__( 'Test connection', 'signal-and-noise-tools' ) . '</button>';
	}
	echo '</p>';
	echo '</form>';

	if ( null !== $identity ) {
		snt_gsc_render_property_form();
	}
}

/**
 * Property selection + sync, shown once a credential is stored.
 *
 * The property list comes from the last Test connection rather than a fresh API
 * call on every page load: rendering an admin screen should not spend quota, and
 * a stale list is visibly stale (its age is printed) where a silent per-load
 * fetch would be neither visible nor free.
 */
function snt_gsc_render_property_form() {
	$last     = get_transient( 'snt_gsc_last_test' );
	$current  = (string) sn_setting( 'search_console.property', '' );
	$data     = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;

	echo '<form method="post" class="sn-gsc-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Property', 'signal-and-noise-tools' ) . '</h3>';

	if ( is_array( $last ) && empty( $last['ok'] ) ) {
		// The failure text is the client's stage-specific WP_Error message, which
		// is the whole point of having five error codes instead of one.
		echo '<p><span class="sn-pill sn-pill--warn">' . esc_html( (string) ( $last['error'] ?? '' ) ) . '</span></p>';
	}

	$sites = ( is_array( $last ) && ! empty( $last['ok'] ) ) ? (array) ( $last['sites'] ?? array() ) : array();
	if ( empty( $sites ) ) {
		echo '<p class="description">' . esc_html__( 'Run Test connection above to list the properties this service account can read.', 'signal-and-noise-tools' ) . '</p>';
		if ( '' !== $current ) {
			/* translators: %s: the stored property string. */
			echo '<p>' . sprintf( esc_html__( 'Currently reading: %s', 'signal-and-noise-tools' ), '<code>' . esc_html( $current ) . '</code>' ) . '</p>';
		}
	} else {
		echo '<p><label for="sn_gsc_property"><strong>' . esc_html__( 'Read search data for', 'signal-and-noise-tools' ) . '</strong></label><br>';
		echo '<select id="sn_gsc_property" name="sn_gsc_property">';
		echo '<option value="">' . esc_html__( '— choose a property —', 'signal-and-noise-tools' ) . '</option>';
		foreach ( $sites as $site ) {
			$url = (string) ( $site['siteUrl'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			printf(
				'<option value="%1$s"%2$s>%3$s (%4$s)</option>',
				esc_attr( $url ),
				selected( $url, $current, false ),
				esc_html( $url ),
				esc_html( (string) ( $site['permissionLevel'] ?? 'unknown' ) )
			);
		}
		echo '</select></p>';
		echo '<p><button type="submit" name="sn_action" value="gsc_property_save" class="button button-primary">' . esc_html__( 'Use this property', 'signal-and-noise-tools' ) . '</button></p>';
	}

	if ( '' !== $current ) {
		echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Sync', 'signal-and-noise-tools' ) . '</h3>';
		if ( null === $data ) {
			echo '<p><span class="sn-pill">' . esc_html__( 'Never synced.', 'signal-and-noise-tools' ) . '</span></p>';
		} else {
			echo '<p>';
			printf(
				/* translators: 1: start date, 2: end date, 3: human-readable age. */
				esc_html__( 'Window %1$s to %2$s, synced %3$s ago.', 'signal-and-noise-tools' ),
				esc_html( (string) $data['window']['start'] ),
				esc_html( (string) $data['window']['end'] ),
				esc_html( human_time_diff( (int) $data['synced_at'], time() ) )
			);
			echo ' ';
			printf(
				/* translators: 1: page count, 2: query count. */
				esc_html__( '%1$d pages, %2$d queries.', 'signal-and-noise-tools' ),
				count( (array) $data['pages'] ),
				count( (array) $data['queries'] )
			);
			echo '</p>';
		}
		// The scheduled sync's account of itself (v13.9.0). Three honest
		// states: scheduled-and-ran (ok or Google's own error), scheduled-
		// but-never-fired, and not scheduled (integration incomplete).
		$next   = wp_next_scheduled( SNT_GSC_SYNC_HOOK );
		$status = snt_gsc_sync_last_status();
		echo '<p class="description">';
		if ( $next ) {
			printf( esc_html__( 'Scheduled: daily, next in %s.', 'signal-and-noise-tools' ), esc_html( human_time_diff( time(), (int) $next ) ) );
			if ( null === $status ) {
				echo ' ' . esc_html__( 'The scheduled sync has not fired yet.', 'signal-and-noise-tools' );
			} elseif ( ! empty( $status['ok'] ) ) {
				echo ' ' . sprintf( esc_html__( 'Last scheduled run %s ago: ok.', 'signal-and-noise-tools' ), esc_html( human_time_diff( (int) $status['ran_at'], time() ) ) );
			} else {
				echo ' ' . sprintf(
					/* translators: 1: age, 2: error message. */
					esc_html__( 'Last scheduled run %1$s ago FAILED: %2$s', 'signal-and-noise-tools' ),
					esc_html( human_time_diff( (int) $status['ran_at'], time() ) ),
					esc_html( isset( $status['message'] ) ? (string) $status['message'] : (string) ( $status['code'] ?? 'unknown' ) )
				);
			}
		} else {
			echo esc_html__( 'No scheduled sync: it schedules itself once a credential is stored and a property chosen.', 'signal-and-noise-tools' );
		}
		echo '</p>';
		echo '<p><button type="submit" name="sn_action" value="gsc_sync" class="button">' . esc_html__( 'Sync now', 'signal-and-noise-tools' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Data appears in Analytics → Search, and as impressions/position columns beside Top pages.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '</form>';
}
