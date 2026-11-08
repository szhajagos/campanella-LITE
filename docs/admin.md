# Admin dashboard

The admin dashboard at `/admin` is where the website is managed: a command box, the system's responses, the command history, the page list and a status bar. It talks to the same command interpreter as the developer CLI.

## First user

While there is no admin user, `/admin` shows the first-time setup form instead of the login form: setup key (from `config/setup-key.txt` on the server), website name, starter content and the first admin user. See [installation.md](installation.md#3-set-it-up).

On the server, users can also be created with the CLI; the password is read from standard input and must be at least 10 characters long:

```
bin/campanella --add-user=admin
```

Running it again for an existing user sets a new password. Users are stored in `config/users.yaml` with hashed passwords (PHP's `password_hash`), outside the web root and outside the project.

## Using the dashboard

| Part | What it does |
| --- | --- |
| Commands | A multi-line box for a batch of commands. **Ctrl+Enter** (⌘+Enter) runs it; **Alt+↑ / Alt+↓** step through the history. After a batch has run, the box is emptied, so pressing Run again cannot repeat it by accident; after a dry run or an error the text stays, to be run for real or corrected. |
| Files | Drop files on the command box, or use **Attach files**; they appear below the box and can be removed with ×. **Run** uploads them first, then runs the commands, if any. The response shows each file's id and a reference to copy into content (`![](file:12)`). Uploading is not affected by the dry-run switch. |
| Dry run | Shows what the batch would change without writing anything (same as `@dry-run`). |
| Response | One line per command (`Ok.`, `Error:`, skipped), tables from `list`, values from `get`, the rule violations that blocked a batch, and the summary. |
| History | The last 50 different batches of this user, newest first; click one to put it back into the command box. |
| Pages | Every page with its address and status, a link to the public page and a **Preview** link, which also works for drafts. |
| Status bar | Number of pages (published and drafts), generated pages, languages, time of the last change. |

### Language of the messages

Messages are in English by default. Each admin chooses their own language with:

```
user set locale hu
```

Available languages: English (`en`) and Hungarian (`hu`). A language is available when `app/translations/messages.{locale}.yaml` exists; any text missing from it is shown in English.

### Preview

`/admin/preview/{id}` shows a page as visitors would see it, in the default language; `/admin/preview/{id}/{language}` in another language. Drafts can be previewed; nothing is stored in the page cache.

## Endpoints

| Method and path | Purpose |
| --- | --- |
| `GET /admin` | Login form, or the dashboard when logged in |
| `POST /admin/login` | Log in: `username`, `password`, `_csrf` (form) |
| `POST /admin/logout` | Log out: `_csrf` (form) |
| `POST /admin/run` | Run a batch: JSON `{"commands": "…", "dry_run": false}`, header `X-CSRF-Token` |
| `GET /admin/preview/{id}[/{language}]` | Preview a page |
| `POST /admin/upload` | Add files: multipart field `files[]`, header `X-CSRF-Token`; JSON with `files` (id, filename, reference) and the response HTML |

`POST /admin/run` answers with JSON: `ok`, `outcome`, `text` (the plain-text response), and the server-rendered HTML of the response, status bar, page list and history (`html`, `status_html`, `pages_html`, `history_html`). Without a valid session it answers `401`, without a valid CSRF token `403`.

## Files

```
public/admin/index.php        entry point
public/assets/admin/          admin.css, admin.js (no framework)
app/admin/templates/          Twig templates of the dashboard
config/users.yaml             admin users (not in git)
var/admin/history/{user}.json command history
var/admin/login-failures.json recent failed logins (hashed addresses)
var/log/auth.log              logins, failed logins, logouts
```

See [security.md](security.md) for how the admin is protected.
