# MCP server

Campanella Lite is an [MCP](https://modelcontextprotocol.io) server. An AI client that speaks MCP (a Claude custom connector, Claude Code, or another MCP client) can read the website, try changes, send them and upload files, all as an [AI user](ai-users.md). The AI user's rights and approval settings apply just as they do in the JSON API: by default every change waits for an administrator.

## Endpoint

```
POST https://example.com/mcp
Authorization: Bearer cl_1a2b3c4d_…
```

- Transport: Streamable HTTP. Every request is one JSON-RPC message in a POST, and every answer is a JSON body (no event streams, no sessions). Other methods get `405`.
- Protocol versions: **2026-07-28** (stateless: `server/discover`, `tools/list`, `tools/call`, with the version in every request) and, for older clients, **2025-11-25, 2025-06-18, 2025-03-26** (`initialize`, `notifications/initialized`, `ping`). A request without any version is taken as 2025-03-26.
- In 2026-07-28 the headers `MCP-Protocol-Version`, `Mcp-Method` and (for `tools/call`) `Mcp-Name` must match the body; otherwise the answer is `400` with error `-32020`. An unknown version gets `400` with error `-32022` and the list of supported versions.
- Authentication: the token of an AI user (`user token create`). A missing or wrong token gets `401` with `WWW-Authenticate: Bearer`; failed attempts are throttled per address (`429`) and logged, as for the API.
- A request with an `Origin` header from another site gets `403` (protection against DNS rebinding in browsers).
- Requests may be up to 16 MB (for uploads); PHP's `post_max_size` has to allow that.

## Tools

| Tool | Does | Changes anything |
| --- | --- | --- |
| `campanella_me` | The AI user, its rights and approval settings | no |
| `campanella_help` | The command index, or the help of one command (`command`) | no |
| `campanella_read` | Runs read-only commands (`commands`): `list`, `get`, `check`, `log`, `help`, `list files`. Commands that change something are refused. | no |
| `campanella_dry_run` | Shows what a batch would change, including broken rules | no |
| `campanella_submit` | Runs a batch (`commands`, optional `session`). If it needs approval, it becomes a proposal and the answer gives its id. | yes, or proposes |
| `campanella_proposals` | The AI user's latest 20 proposals, or one (`id`), with status and the admin's reason | no |
| `campanella_upload_file` | Adds a file (`filename`, `content_base64`, at most 10 MB) to the file repository; returns its id and `file:ID` reference | adds a file only |

Every result has a text part (the same text the dashboard shows, in the AI user's language) and `structuredContent` with the JSON form of the result. A batch that was rejected or broke a rule, and a refused call, come back with `isError: true`, so the AI sees the error and can correct itself. The tools are annotated (`readOnlyHint`, `destructiveHint`), so clients can ask before the changing ones.

The server also sends short instructions that describe the workflow: help first, read, dry run, submit, then check the proposals.

## Connecting Claude

### Before you start

1. **The site must be reachable from the internet over HTTPS.** Claude connects to custom connectors from Anthropic's servers, not from your computer, so `http://localhost:8082` does not work. Either use the public website, or, for testing a local installation, open a tunnel, for example with Cloudflare's free quick tunnel:

   ```
   cloudflared tunnel --url http://localhost:8082
   ```

   It prints an address like `https://random-words.trycloudflare.com`; the MCP endpoint is then `https://random-words.trycloudflare.com/mcp`. The address changes on every start; stop the tunnel when you are done.

2. **Create an AI user and a token** in the dashboard:

   ```
   user ai create claude
   user token create claude --label=Claude --days=30
   ```

   Copy the token (`cl_…`); it is shown only once.

### Claude (web and desktop app)

1. Open *Settings → Connectors* and choose *Add custom connector*.
2. Name: `Campanella`, URL: `https://example.com/mcp`.
3. Under the advanced settings, add a request header: `Authorization` with the value `Bearer cl_…`. (The connector does not use OAuth; the token is the whole sign-in.)
4. Save, then enable the connector in a conversation (the tools menu) and ask, for example: "Which pages does my website have?"

When Claude submits a change and approval is on, it tells you the proposal id; approve or reject it in the dashboard's *AI proposals* panel. Ask Claude afterwards to check the proposal's status.

### Claude Code

```
claude mcp add --transport http campanella https://example.com/mcp --header "Authorization: Bearer cl_…"
```

### Other clients

Any client that supports Streamable HTTP and custom headers works the same way: the URL of `/mcp` and the `Authorization: Bearer` header. To try the server by hand:

```
curl https://example.com/mcp \
  -H "Authorization: Bearer cl_…" -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2026-07-28" -H "Mcp-Method: tools/call" -H "Mcp-Name: campanella_read" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"campanella_read","arguments":{"commands":"list pages"},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
```

## Web server

`public/.htaccess` sends `/mcp` to `public/mcp/index.php` and passes the `Authorization` header to PHP; keep both rules when you customise the file. The development router (`app/dev-router.php`) does the same.

## Security notes

- Everything in [ai-users.md](ai-users.md#security-notes) applies: text the AI reads can try to mislead it, so keep the rights narrow and approval on for anything that matters.
- A tunnel makes the whole local site public, the dashboard included, for as long as it runs. Use a strong admin password, and stop the tunnel when you are done.
- Give each client its own token with an expiry date; revoke it (`user token revoke claude 1a2b3c4d`) when the client is no longer used.
