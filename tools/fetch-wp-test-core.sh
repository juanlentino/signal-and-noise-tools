#!/usr/bin/env bash
#
# Fetch the WordPress core files the standalone sweep runs on, into one dir.
#
# Some suites run core's own code rather than a stub: the HTML API
# (WP_HTML_Tag_Processor) for the host rewrite pins, kses.php for the notice
# and active-content pins, formatting.php for the emoji fingerprint,
# robots-template.php and link-template.php for the head pins
# (tests/seo-head-on-core.php), class-wp.php and post.php for the feed
# conditional GET (tests/feed-conditional-get.php). A
# standalone sweep has no WordPress, so this pulls exactly those files from
# the wordpress-develop mirror at the version the sandbox runs. CI calls it in
# both sweep jobs; run it by hand the same way.
#
# Usage:  bash tools/fetch-wp-test-core.sh <dir>
#         SNT_WP_HTML_API=<dir>/wp-includes/html-api bash tests/run.sh
#
# WP_TEST_CORE_REF overrides the wordpress-develop branch or tag (default 7.1).
#
set -euo pipefail

dir="${1:-}"
if [ -z "$dir" ]; then
	echo "usage: bash tools/fetch-wp-test-core.sh <dir>" >&2
	exit 2
fi

ref="${WP_TEST_CORE_REF:-7.1}"
base="https://raw.githubusercontent.com/WordPress/wordpress-develop/$ref/src/wp-includes"
dest="$dir/wp-includes"
mkdir -p "$dest/html-api"

for f in class-wp-html-tag-processor.php class-wp-html-attribute-token.php class-wp-html-span.php class-wp-html-text-replacement.php class-wp-html-decoder.php html5-named-character-references.php; do
	curl -sSfL "$base/html-api/$f" -o "$dest/html-api/$f"
done
for f in class-wp-token-map.php kses.php formatting.php robots-template.php link-template.php class-wp.php post.php; do
	curl -sSfL "$base/$f" -o "$dest/$f"
done

echo "SNT_WP_HTML_API=$dest/html-api"
