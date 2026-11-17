#!/usr/bin/env bash
#
# Two local WordPress sites, side by side, to judge a core release candidate:
# the version the live site runs and the new one, same theme (GitHub main) and
# this plugin checkout, on SQLite, outbound HTTP blocked. Only differences
# between the two count. See tools/wp-boot/README.md.
#
# Usage: bash tools/wp-boot/setup.sh <workdir> <old> <new>   e.g. ... /tmp/b 7.1.4 7.2-RC1
# Then:  bash tools/wp-boot/serve.sh <workdir> <old> <new>, and compare.sh.
#
set -euo pipefail
W="${1:?workdir}"; OLD="${2:?old version}"; NEW="${3:?new version}"
PHP="${PHP:-/opt/homebrew/opt/php@8.3/bin/php}"   # live runs 8.4; 8.3 is the nearest local build
PLUGIN="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$(cd "$(dirname "$0")" && pwd)"
WPCLI="$(command -v wp)"
mkdir -p "$W/dl"
[ -d "$W/theme" ] || git clone -q --depth 1 https://github.com/juanlentino/signal-and-noise.git "$W/theme"  # never the local checkout: it can be stale
S="$W/dl/sqlite.zip"
[ -f "$S" ] || curl -sSfLo "$S" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
port=8071
for v in "$OLD" "$NEW"; do
	z="$W/dl/wordpress-$v.zip"; d="$W/$v"
	[ -f "$z" ] || curl -sSfLo "$z" "https://downloads.wordpress.org/release/wordpress-$v.zip"
	[ -d "$d/wordpress" ] || { mkdir -p "$d"; unzip -q "$z" -d "$d"; }
	r="$d/wordpress"
	[ -d "$r/wp-content/plugins/sqlite-database-integration" ] || unzip -q "$S" -d "$r/wp-content/plugins"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$r/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$r/wp-content/plugins/sqlite-database-integration/db.copy" > "$r/wp-content/db.php"
	ln -sfn "$W/theme" "$r/wp-content/themes/signal-and-noise"
	ln -sfn "$PLUGIN" "$r/wp-content/plugins/signal-and-noise-tools"
	mkdir -p "$r/wp-content/mu-plugins"; cp "$HERE/boot-login.php" "$r/wp-content/mu-plugins/"
	cat > "$r/wp-config.php" <<CFG
<?php
define( 'DB_NAME', 'boot' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'boot.sqlite' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );   // nothing leaves the machine
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'SN_WP_BOOT', true );               // tools/wp-boot/boot-login.php only acts under this
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true );
\$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT' ) as \$k ) { define( \$k, 'boot-' . \$k ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
	wp() { "$PHP" -d error_reporting=0 "$WPCLI" --path="$r" "$@"; }
	wp core is-installed 2>/dev/null || wp core install --url="http://127.0.0.1:$port" --title="Boot $v" --admin_user=boot --admin_password="boot-$RANDOM$RANDOM" --admin_email=boot@example.test --skip-email >/dev/null
	wp rewrite structure '/%postname%/' >/dev/null
	wp theme activate signal-and-noise >/dev/null
	wp plugin activate signal-and-noise-tools >/dev/null
	"$PHP" -d error_reporting=0 "$WPCLI" --path="$r" eval-file "$HERE/seed.php" >/dev/null
	echo "$v: $(wp core version) theme=$(wp theme list --status=active --field=name) plugin=$(wp plugin list --status=active --field=name | tr '\n' ',') port=$port"
	port=$((port + 1))
done
