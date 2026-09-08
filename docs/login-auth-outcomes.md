# Signed Login Guard authentication outcomes

Login Guard 1.13.0 and the paired Signal & Noise Tools change share an optional
origin-response channel. It is reporting only. Existing denylist, throttling,
MFA providers and authentication decisions remain unchanged.

## Configuration

Generate a dedicated random 32-byte secret encoded as **64 lowercase hex characters**.
Store the same ASCII string as the `SN_LG_OUTCOME_KEY` Cloudflare Worker secret
and the `SN_LG_OUTCOME_KEY` constant in WordPress `wp-config.php`, before WordPress
loads. Do not use the public test fixture, a WordPress salt or the bypass token.
Provision through a protected file/stdin or secret manager, never command arguments
or committed configuration. Deploy both components before enabling the key.

`wrangler secret put SN_LG_OUTCOME_KEY < /protected/path/to/key` configures the
Worker. Configure the WordPress constant through the site's existing secure
configuration/deployment process. Rotate both copies together; during a mismatch,
login still works and telemetry reports invalid signatures. Removing either copy
disables trusted outcome reporting without disabling Login Guard.

## Wire protocol

1. The Worker removes incoming `X-SN-Auth-Nonce`, `X-SN-Auth-Outcome`, and
   `X-SN-Auth-Signature`. With a valid key, it generates a fresh random 32-byte
   nonce (64 lowercase hex) and forwards it in `X-SN-Auth-Nonce`.
2. WordPress signs these exact UTF-8 bytes (LF delimiters, no trailing newline):
   `sn-login-outcome-v1\n<nonce>\n<outcome>` using HMAC-SHA256 with the **ASCII
   secret**, not hex-decoded secret bytes. The response carries `X-SN-Auth-Outcome`
   and a lowercase hex `X-SN-Auth-Signature`.
3. The Worker verifies the signature against this request's nonce, strips all three
   private headers, and returns the original streaming body/status/cookies with
   `Cache-Control: private, no-store`. Redirects are returned, never followed.
   An origin failure is never retried. Nonce/signature errors cannot deny a login.

The fresh nonce binds a response to one forwarded request. A copied response or
client-supplied header cannot create a trusted observation on another request.
No new public endpoint, replay store, account identifier, password, assertion,
provider exception, or secret is written to Analytics Engine.

## Telemetry contract

Existing blobs 1–8, doubles and indexes are unchanged. Each edge decision still
writes **one row** to `sn_login_guard`. `blob9` is the verified outcome (otherwise
empty); `blob10` is verification coverage. Existing edge totals must not add
outcome totals, because they describe the same requests.

Outcomes: `none` (verified response, no authentication outcome), `auth_failed`
(general rejection, not necessarily a bad password), `mfa_failed`,
`mfa_throttled`, `mfa_other`, `login_success`, `mfa_success`.

Coverage: `verified`, `missing`, `invalid`, `disabled`, `error`, `origin_error`,
`not_forwarded`. Older rows have empty fields and remain historical/unclassified.
`none` is never success. Missing or invalid observations are never zero measured
failures. A request reports the last captured outcome; these are sampled request
estimates, not an exhaustive authentication event ledger or unique-user count.

WordPress uses the existing audit capture hooks: MFA completion comes from
`two_factor_user_authenticated`, never the initial password-only `wp_login`.
Browser cancellation and MFA revalidation do not become completed logins.
Events outside the guarded login route, responses whose headers were already
sent, or provider flows that do not call these hooks cannot be observed here.

## Verification

Run `npm test` in Login Guard; run `php tests/audit-mfa.php` and the normal plugin
suite in Signal & Noise Tools. Both repositories pin the same non-secret HMAC
fixture. The tests cover forgery, nonce replay, malformed configuration, crypto
failure, cookie/redirect/body preservation, no POST retries and positional AE
compatibility.

After activation, `/_sn/login-guard/status` should report
`config.auth_outcomes_configured: true`. This proves only Worker configuration,
not a matching WordPress key. Open the login form (no failed login needed), then
check Login defense > Authentication outcomes for a verified `none` response
once Analytics Engine has ingested it. Confirm the browser receives none of the
private headers. A completed MFA login is a separate end-to-end verification.
