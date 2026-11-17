#!/usr/bin/env bash
# Prints the two php -S commands (old on 8071, new on 8072). Run them in their
# own terminals, or let .claude/launch.json start them (wp-boot-old, wp-boot-new).
W="${1:?workdir}"; OLD="${2:?old}"; NEW="${3:?new}"; PHP="${PHP:-/opt/homebrew/opt/php@8.3/bin/php}"
H="$(cd "$(dirname "$0")" && pwd)"
echo "$PHP -S 127.0.0.1:8071 -t $W/$OLD/wordpress $H/router.php"
echo "$PHP -S 127.0.0.1:8072 -t $W/$NEW/wordpress $H/router.php"
