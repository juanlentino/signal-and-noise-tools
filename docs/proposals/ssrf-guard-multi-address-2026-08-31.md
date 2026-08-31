# SSRF guard — the address it validates is not always the address it fetches

> Found during the plugin-wide extraction survey (2026-08-31). Not an extraction: the
> guard is better than every PHP library the survey returned. This is a residual gap in
> our own code, found by testing it rather than reading it.

**Severity: moderate, exploitability low.** The guard's exposed callers take hosts from
config and options (owner-controlled), not from anonymous input. This is defence-in-depth
on a component whose whole job is defence-in-depth.

---

## What holds

`inc/ssrf-guard.php` is sound where it was designed to be. Measured directly against
`filter_var()` on 2026-08-31, every documented bypass class is blocked:

| Input | Verdict |
|---|---|
| `169.254.169.254` | blocked |
| `2852039166` (decimal) · `0xA9FEA9FE` (hex) | blocked |
| `::ffff:169.254.169.254` · `::ffff:a9fe:a9fe` | blocked |
| `0:0:0:0:0:ffff:169.254.169.254` | blocked |
| `64:ff9b::169.254.169.254` (NAT64) | blocked |
| `fe80::1` · `::1` | blocked |
| `100.64.0.1` (CGNAT) | **passes `filter_var`** — caught by the explicit regex |
| `8.8.8.8` | passes (correct) |

The IPv6-mapped and NAT64 forms I expected to leak do not. The CGNAT row confirms the
explicit regex is load-bearing rather than belt-and-braces: PHP's reserved-range flag does
not cover `100.64.0.0/10`, exactly as the comment claims.

---

## The gap

```
gethostbyname("dns.google")  = 8.8.4.4
gethostbynamel("dns.google") = 8.8.4.4, 8.8.8.8
```

**`gethostbyname()` returns ONE address from a multi-address record set**, and which one it
returns is not stable. `sn_ssrf_host_blocked()` therefore validates a single address out of
a set it never enumerates — and the actual request, issued later by cURL, performs its own
independent resolution.

Two consequences, in order of seriousness:

1. **Multi-A rrset.** A host publishing `[203.0.113.10, 169.254.169.254]` can be validated
   against the public record while the request connects to the internal one. No attacker
   timing is required; ordinary rrset rotation suffices.
2. **DNS rebinding (TOCTOU).** Even with a single-address rrset, the check and the connect
   are two separate resolutions. A short-TTL record answering public-then-internal defeats
   any check-then-fetch design. The existing docblock anticipates the *redirect* variant of
   this (`redirection => 0`) but not the resolution variant.

**IPv6-only hosts are also blocked outright** — `gethostbyname()` cannot return AAAA, so it
returns the hostname unchanged, `filter_var` rejects it, and the guard fails closed. That is
the safe direction and currently harmless (no caller needs an IPv6-only host), but it is a
false negative, not a deliberate policy, and should be written down as one.

---

## The fix, in two steps

### Step 1 — validate the whole rrset (small, do this first)

Replace `gethostbyname()` with `gethostbynamel()` and block if **any** returned address is
internal. Fail closed on an empty return. This closes consequence 1 completely and costs a
few lines.

Keep the single-IP fast path (`filter_var($host, FILTER_VALIDATE_IP)`) — a literal address
has no rrset to enumerate.

For AAAA, `dns_get_record($host, DNS_AAAA)` is the counterpart; adding it converts the
IPv6-only case from a silent false negative into a real check. Optional, and only worth it
if a caller ever needs one.

### Step 2 — pin the address at connect time (VERIFIED 2026-08-31; still its own arc)

**Status: Step 1 shipped in v13.50.1. Step 2 is verified reachable and deliberately not built.**

The hook question is settled, and the obvious reading is wrong twice:

- `developer.wordpress.org` names `WP_Http_Curl::request()` as the source of `http_api_curl`.
  That class was **deprecated in 6.4.0** and is no longer on the request path — `WP_Http::request()`
  calls `WpOrg\Requests\Requests::request()` and fires only `http_api_debug`. Reading the docs
  alone concludes, wrongly, that the hook is dead.
- It is nonetheless **live**: `WP_HTTP_Requests_Hooks::dispatch()` bridges Requests' own
  `curl.before_send` — which passes the cURL handle by reference — directly to
  `do_action_ref_array( 'http_api_curl', ... )`. Reading only the deprecation concludes, also
  wrongly, that pinning is impossible.

**Why it still is not built.** That bridge fires only when Requests selects the **cURL
transport**. On a host without the cURL extension, Requests falls back to fsockopen, the hook
never fires, and the request goes out unpinned **with nothing saying so** — a silent fail-open,
precisely the shape this guard exists to prevent. Pinning therefore needs a transport-detection
decision first (refuse the request, or proceed and record the gap), and it changes every
outbound request in the plugin rather than one guard function. That earns its own review round,
the same way v13.50.0's phases 2 and 3 were split on purpose.

Until then, rebinding is **accepted residual risk**, recorded in the guard's own docblock and
justified by owner-controlled inputs — not implied away by Step 1.

---

## Why nothing is being adopted

The survey's PHP results were thin and none is usable:

| Repo | Why not |
|---|---|
| `wkcaj/safecurl` | The known PHP option, effectively unmaintained |
| `j0k3r/httplug-ssrf-plugin` | Active, but requires HTTPlug — an HTTP abstraction WordPress does not use. Adopting it means replacing `wp_remote_get()` estate-wide |

The Go and Python implementations (`cplieger/ssrf`, `jayjza/requests-secure`) confirm the
shape — validate every resolved address, then pin at dial time — which is where their value
ends. Steps 1 and 2 above are that pattern in our own code.

---

## Verification

The suite is `tests/ssrf-guard.php`. Extend it with the table at the top of this document as
literal cases, then:

- **Negative-control Step 1.** A stub resolver returning `[public, 169.254.169.254]` must go
  red before the fix and green after. A multi-address test that passes against the current
  `gethostbyname()` implementation is testing nothing — it would pass either way whenever the
  resolver happens to return the public record first.
- Pin the CGNAT row explicitly. It is the one case carried by our own regex rather than by
  PHP, so a PHP upgrade that widened `FILTER_FLAG_NO_RES_RANGE` would make the regex look
  redundant right up until it wasn't.
- The resolver must be injectable for tests. Today the only I/O is `gethostbyname()` called
  directly; Step 1 is the moment to take it as a parameter defaulting to the real function.
