# Security

The most important rule of Campanella Lite: **the command language changes data, never code.** No command can run PHP, a shell command or a template, or write outside the project directory. Everything else builds on that.

## Public website

| Measure | Details |
| --- | --- |
| Static pages | Visitors mostly get stored HTML files; PHP only runs for pages not yet generated. |
| Requests | The front controller answers only `GET` and `HEAD`; paths with `..`, `//`, backslashes or control characters get 404. |
| Errors | Visitors never see error details; a broken project gives a plain `503`. |
| Escaping | Twig escapes every value; raw HTML in Markdown content is escaped and unsafe links (`javascript:` and similar) are removed. |
| Templates | Themes are files in the code base, not editable through commands. |
| Drafts | Never shown to visitors; only the admin preview shows them. |
| Web root | Only `public/` is exposed. `app/`, `config/`, `project/` and `var/` are outside it. Hidden files and directory listings are refused by `.htaccess`. |
| Generated files | Tracked in a manifest; the generator never overwrites or deletes a file it did not create. |

## Admin

| Measure | Details |
| --- | --- |
| Passwords | Stored only as `password_hash` hashes in `config/users.yaml` (file mode 0600), rehashed when PHP's default algorithm changes; at least 10 characters. |
| Login throttling | After 5 failed logins from one address within 15 minutes, that address is blocked until the oldest failure is 15 minutes old (`429` with `Retry-After`). Addresses are stored hashed. |
| User enumeration | Unknown users and wrong passwords get the same answer; for unknown users a dummy hash is checked so the timing is similar. |
| Session cookie | Own name, `path=/admin`, `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS; strict mode (unknown session ids are refused); no session ids in URLs. |
| Session lifetime | A new session id after login (against session fixation); sessions end after 30 minutes without activity and after 12 hours in any case. |
| CSRF | Every state-changing request (login, logout, running commands) needs the session's random token, compared in constant time. Commands are sent as JSON with an `X-CSRF-Token` header. |
| Headers | `Content-Security-Policy` allowing only same-origin resources and no inline scripts, `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `Referrer-Policy: same-origin`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`. |
| Logging | Logins, failed and blocked logins and logouts go to `var/log/auth.log` with time, user name and address. |

## Files and commands

| Measure | Details |
| --- | --- |
| Paths | Every file access goes through `FileStore`, which accepts only relative paths of safe segments: no `..`, no absolute paths, no hidden files. Entity names are restricted to `[a-z0-9-]`. |
| Atomic writes | Files are written to a temporary file and renamed into place. |
| Locking | One exclusive lock serialises every write to the project and to the page cache. |
| YAML | Only plain data is accepted: PHP objects, constants and custom tags are refused. |
| All-or-nothing batches | A batch is validated as a whole before anything is written. |

## Planned

- Command log and undo snapshots (milestone 6).
- Multiple users with restricted, role-based command permissions.
- The AI module will submit commands through the same interpreter, with human approval.
