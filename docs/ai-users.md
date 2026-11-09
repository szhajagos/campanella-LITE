# AI users

An AI works on the website the way an administrator does: through the command language, the same interpreter, the same validation, log and undo. It signs in as an **AI user**, which has narrower rights than an administrator, and by default its changes are **proposals** that an administrator approves in the dashboard.

How the AI connects is a separate question. Milestone 9 provides the JSON API described here; milestone 10 adds an MCP server on top of it, so that AI assistants such as a Claude custom connector can use Campanella directly.

## Setting up an AI user

In the dashboard (or with `bin/campanella -e "…"`):

```
user ai create assistant
user token create assistant --label=Claude
```

The second command shows the token once: `cl_1a2b3c4d_…`. Copy it into the AI client; only a SHA-256 hash is stored, so a lost token cannot be shown again. Revoke it and create a new one instead:

```
user token revoke assistant 1a2b3c4d
```

Tokens are valid for 90 days unless `--days` says otherwise (`--days=0`: no expiry). `user list` shows every user with role, rights, approval settings and tokens. `user delete assistant` removes the AI user and its tokens.

AI users have no password and cannot sign in to the dashboard. The user commands change `config/users.yaml`, not the website, so they write after the batch (nothing on a dry run) and are not part of undo.

## Rights

A right names a command or its first words: `content` covers every content command, `page publish` only that one.

| Always allowed | Read-only commands: `help`, `list`, `get`, `check`, `log`, … |
| --- | --- |
| By default | `content`, `page`, `menu`, `file describe`, `file rename`, `file delete`, `file restore`, `file upload`, `build` |
| Never | `user …` commands |

Everything else (`set`, `layout`, `language`, `redirect remove`, `cache clear`, `file purge`, `undo`) only with an explicit right:

```
user allow assistant layout
user allow assistant "file purge"
user deny assistant "page delete"     # only if "page delete" was given as such
user deny assistant page              # takes away every page right
```

A batch with a command the AI user may not run is rejected as a whole, with `command.not_allowed`.

## Approval

By default every batch of an AI user that would change the website waits for an administrator:

1. The AI submits a batch. Campanella runs it as a dry run first. A batch that would be rejected or would break a rule is not proposed; the AI gets the result and can correct it.
2. Otherwise the batch becomes a proposal (stored in `var/proposals/`) and the AI gets its id.
3. In the dashboard, the **AI proposals** panel shows the batch and what the dry run found; the status bar shows how many are waiting.
4. **Approve** runs the batch now, in the AI user's name and within its rights. If the website has changed in the meantime and the batch no longer fits, it fails and nothing is written. **Reject** discards it.
5. The command log names both: `applied, approved by admin`.

Read-only batches and dry runs never wait. Approval can be switched off, entirely or for some commands:

```
user approval assistant off --for="content edit"   # content edits run at once
user approval assistant off                        # everything runs at once
user approval assistant on                         # everything waits again
```

## The JSON API

All requests carry the token: `Authorization: Bearer cl_…`. Responses are JSON; failed sign-ins are throttled per address (5 per 15 minutes) and logged in `var/log/auth.log`.

| Method and path | Purpose |
| --- | --- |
| `GET /api/v1/me` | The AI user: name, locale, rights, approval settings |
| `POST /api/v1/run` | `{"commands": "…", "dry_run": false, "session": "…"}` – runs the batch (`200`, `"status": "done"`) or proposes it (`202`, `"status": "pending"` with the proposal and the dry run) |
| `GET /api/v1/proposals` | The AI user's proposals, newest first |
| `GET /api/v1/proposals/{id}` | One proposal with its status (`pending`, `applied`, `failed`, `rejected`) and result |
| `POST /api/v1/files` | Multipart `files[]`: adds files to the file repository (needs the `file upload` right; never waits for approval, because a new file changes nothing on the website) |

`session` groups proposals that belong together ("New spring offer"); without it the token's label and the date are used. Messages are in the AI user's locale.

Example:

```
curl -H "Authorization: Bearer cl_…" -H "Content-Type: application/json" \
     -d '{"commands": "page create --title=News\npage publish 3"}' \
     https://example.com/api/v1/run
```

## Security notes

- Text an AI reads (content, uploaded documents, web pages) can contain instructions meant to mislead it. Narrow rights and approval limit what such an instruction can achieve; keep approval on for anything that matters.
- Give each AI client its own token, with an expiry date, and revoke tokens that are no longer used.
- Apache passes the `Authorization` header to PHP only with the rule in `public/.htaccess`; keep it when you customise that file.
