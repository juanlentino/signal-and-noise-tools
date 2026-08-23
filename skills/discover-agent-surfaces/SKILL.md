# Discover juanlentino.com's agent surfaces

This site publishes its machine-readable entry points at standard well-known
addresses. Start here rather than crawling.

## Documents

| Address | What it is |
| --- | --- |
| `/.well-known/agents.json` | The site's own index of every agent surface, including the ones below |
| `/.well-known/api-catalog` | RFC 9727 linkset over the REST index, the Abilities API and the MCP door |
| `/.well-known/mcp/server-card.json` | SEP-1649 card: MCP transport endpoint, capabilities, and how to authenticate |
| `/.well-known/ai-catalog.json` | ARD capability manifest |
| `/.well-known/agent-card.json` | A2A-style card. Declares an **MCP** transport, not an A2A JSON-RPC binding |
| `/llms.txt` | Human-written orientation for language models |

## Order to read them

`agents.json` first — it names the others, so one fetch tells you what exists.
Use the API catalog when you want callable endpoints, the MCP server card when
you intend to open an MCP session.

## Validate

```
curl -s https://juanlentino.com/.well-known/agents.json | head -20
```
