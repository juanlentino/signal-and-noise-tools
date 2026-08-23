# Query juanlentino.com analytics over MCP

Traffic, content-health and deploy status are readable through this site's MCP
server. Read-only: nothing on this door writes.

## Connect

The endpoint and its capabilities are described by the server card:

```
GET https://juanlentino.com/.well-known/mcp/server-card.json
```

Transport is streamable HTTP. **Authentication is required** — HTTP Basic with a
WordPress application password, and the authenticated user needs
`manage_options`. Anonymous requests receive `401`; the card says so up front so
you can decide before connecting.

## What is available

Call `tools/list` after `initialize`. The read door exposes analytics summaries
and event breakdowns, content-health scan results, RSS statistics, uptime and
deploy status. The write door is a separate endpoint and is never advertised
here.

## Validate

```
curl -s https://juanlentino.com/.well-known/mcp/server-card.json | head -20
```

An unauthenticated POST to the endpoint should return `401`, not `404`.
