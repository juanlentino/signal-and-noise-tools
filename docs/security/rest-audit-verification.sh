#!/usr/bin/env bash
#
# REST authorization + TDM-header verification for signal-and-noise-tools.
# Companion to docs/security/rest-audit-2026-08-03.md (Phase 2.3).
#
# Proves, against a LIVE site:
#   1. Write routes reject anonymous callers        (expect 401/403)
#   2. Write routes reject a Subscriber              (expect 403)
#   3. Write routes succeed for the MCP admin app-pw (expect 200)
#   4. /wp/v2/users is unavailable unauthenticated   (expect 401, route removed)
#   5. TDM headers are present on /wp/v2/posts       (expect all three)
#
# This script is NOT run in CI and touches production — run it by hand.
# It never writes: the "write" probe is a JSON-RPC tools/call to a READ tool
# through the write door, so a 200 proves the auth path without mutating content.
#
# Usage:
#   SITE=https://juanlentino.com \
#   ADMIN_APP_PW='user:xxxx xxxx xxxx xxxx xxxx xxxx' \
#   SUB_APP_PW='subuser:yyyy yyyy yyyy yyyy yyyy yyyy' \
#   bash docs/security/rest-audit-verification.sh
#
# ADMIN_APP_PW  — an application password for the manage_options user bound to
#                 the MCP write door (sn_mcp_rw_app_password_uuid).
# SUB_APP_PW    — an application password for a Subscriber-role user (optional;
#                 step 2 is skipped if unset).

set -u
SITE="${SITE:-https://juanlentino.com}"
NS="signal-noise/v1"
RW="$SITE/wp-json/$NS/mcp-rw"
PASS=0; FAIL=0

check() { # $1 expected-substring in status, $2 actual-status, $3 label
  if [[ "$2" == $1 ]]; then echo "PASS: $3 (got $2)"; PASS=$((PASS+1));
  else echo "FAIL: $3 (expected $1, got $2)"; FAIL=$((FAIL+1)); fi
}

# A harmless JSON-RPC probe: tools/list (no mutation, no arguments).
BODY='{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

echo "== 1. Write door rejects anonymous =="
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RW" \
  -H 'Content-Type: application/json' -d "$BODY")
check '40[13]' "$code" "anon → mcp-rw is 401/403"

echo "== 2. Write door rejects a Subscriber =="
if [[ -n "${SUB_APP_PW:-}" ]]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RW" \
    -u "$SUB_APP_PW" -H 'Content-Type: application/json' -d "$BODY")
  check '403' "$code" "subscriber → mcp-rw is 403"
else
  echo "SKIP: SUB_APP_PW unset (create a Subscriber app password to run this)"
fi

echo "== 3. Write door succeeds for the MCP admin credential =="
if [[ -n "${ADMIN_APP_PW:-}" ]]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RW" \
    -u "$ADMIN_APP_PW" -H 'Content-Type: application/json' -d "$BODY")
  check '200' "$code" "admin app-pw → mcp-rw is 200 (auth path intact)"
else
  echo "SKIP: ADMIN_APP_PW unset"
fi

echo "== 4. /wp/v2/users unavailable unauthenticated =="
code=$(curl -s -o /dev/null -w '%{http_code}' "$SITE/wp-json/wp/v2/users")
check '40[13]' "$code" "anon → /wp/v2/users removed (401/403)"

echo "== 5. TDM headers on /wp/v2/posts =="
hdrs=$(curl -s -D - -o /dev/null "$SITE/wp-json/wp/v2/posts")
for h in "TDM-Reservation: 1" "TDM-Policy: " "Content-Signal: search=yes, ai-train=no, ai-input=yes"; do
  if echo "$hdrs" | grep -iq "^${h%%:*}:"; then echo "PASS: header present — ${h%%:*}"; PASS=$((PASS+1));
  else echo "FAIL: header missing — ${h%%:*}"; FAIL=$((FAIL+1)); fi
done
# Confirm Cloudflare/Breeze did not strip them: they must survive the edge.
echo "   (If a header is missing here but present on a direct-origin request,"
echo "    the CDN is stripping it — check Cloudflare Transform Rules / Breeze.)"

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
