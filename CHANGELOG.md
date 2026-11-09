# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each release lists its changes under these headings, as needed:
Added, Changed, Deprecated, Removed, Fixed, Security.

## [Unreleased]

### Added

- AI users: `user ai create`, tokens (`user token create`, `user token revoke`), rights per command (`user allow`, `user deny`), approval settings (`user approval`), `user list`, `user delete`.
- JSON API for AI users at `/api/v1` (`me`, `run`, `proposals`, `files`), authenticated with a bearer token.
- Proposals: by default an AI user's changes wait for an administrator; the dashboard's *AI proposals* panel shows each batch with its dry run, to approve or reject. The command log names the approving admin.
- MCP server at `/mcp` (Streamable HTTP; MCP 2026-07-28 and the 2025 versions) with the tools `campanella_me`, `campanella_help`, `campanella_read`, `campanella_dry_run`, `campanella_submit`, `campanella_proposals` and `campanella_upload_file`, authenticated with an AI user's token. Claude can connect to it as a custom connector.
- Developer documentation: `docs/ai-users.md`, `docs/mcp.md` (including how to connect Claude).

### Changed

- The path segments `api`, `files` and `mcp` are reserved and cannot start an alias.

### Security

- API tokens are stored only as SHA-256 hashes, expire after 90 days by default and can be revoked; failed token sign-ins are throttled and logged. AI users cannot sign in to the dashboard and can never run `user` commands.

## [0.2.0] - 2026-11-08

Files: images, documents, audio and video in one repository, uploaded in the dashboard and shown on the website.

Upgrading from 0.1.0: replace the code as described in the installation guide, then run `cache clear`. The PHP extensions `gd` and `zip` are recommended, and PHP's upload limits (`upload_max_filesize`, `post_max_size`) should allow the files you want to upload.

### Added

- File repository for images, documents, audio and video: every file gets a permanent numeric id and a manifest (`project/files/manifest/{id}.yaml`); the file itself is stored under a random name.
- Upload in the admin dashboard: drop files on the command box or use *Attach files*; *Run* uploads them and shows each file's id and a reference to use in content.
- `bin/campanella --upload=<path>` adds files on the command line.
- Commands `list files` (with `--type`, `--name`, `--by`, `--since`, `--search`, `--archived`), `file describe`, `file rename`, `file delete` (archives), `file restore`, `file purge`; `get` and `set` for `file.{id}` paths.
- Content can refer to files as `![](file:12)` and `[text](file:13)`; `check` reports references to files that do not exist.
- Uploads and purges are written to the command log.
- Developer documentation: `docs/files.md`.
- Files on the website: `![](file:12)` shows an image in widths of 480, 960 and 1600 pixels with `srcset`, plus WebP copies when the server can make them; with a title and alone in a paragraph it becomes a figure with a caption; audio and video get players; `[text](file:13)` links to the file. The alt text defaults to the file's description in the page's language.
- Published files have readable ASCII addresses (`/files/12-conference-hall-960.jpg`), are made on their first request and then served by the web server; outdated addresses redirect to the current one.
- File panel in the dashboard with thumbnails of the newest files; a click inserts the file's reference into the command box.
- Asset addresses carry a version (`theme.css?v=…`), so browsers load updated CSS and JavaScript after an update without a forced reload.

### Changed

- `cache clear` also removes published files; archiving, purging or renaming a file removes its outdated published copies.
- The admin command box is emptied after a batch has run, so a second click on Run cannot repeat it. After a dry run or an error the text stays; Alt+↑ brings back an emptied batch.

### Removed

- The `Claude outputs/` folder (copies of the CI workflow files, added by mistake) is no longer part of the repository and the release package.

### Security

- Uploaded files are recognised by their content; only types that cannot carry scripts or macros are accepted. PDFs with JavaScript, launch actions, embedded files or XFA forms and Office documents with macros or ActiveX controls are refused; images are decoded once and limited to 10000 px per side.
- Images are published only as re-encoded copies without EXIF, GPS or camera data; uploaded originals are never served.

## [0.1.0] - 2026-11-08

The first release: the complete MVP.

### Added

- Project documentation: README, ROADMAP, CHANGELOG and MIT LICENSE.
- `docs/` directory for developer documentation.
- Repository settings: `.editorconfig`, `.gitattributes`, `.gitignore`.
- Composer project with PSR-4 autoloading (`Campanella\Lite\` in `app/src/`); dependencies in `app/vendor/`.
- File storage: atomic writes, path safety checks and an exclusive write lock.
- YAML and Markdown front matter codecs that accept plain data only.
- Data model for the site, layouts (regions and columns), pages, content and menus, with language-dependent fields stored per language.
- `front_page` setting in `site.yaml` for the page served at `/`.
- Project repository that loads the project directory and saves single entities.
- Project validator with stable violation codes for names, references, layout widths, aliases and languages.
- PHPUnit test suite, PHPStan analysis at level 8, and a GitHub Actions workflow for PHP 8.3, 8.4 and 8.5.
- Developer documentation: architecture, data model, development guide.
- Command language parser: quoted words with escapes, `--options`, multi-line `<<<` … `>>>` text, comments, the `@dry-run` directive, and syntax errors with line numbers.
- Command interpreter that runs a batch all or nothing under the write lock, validates the result, and writes only the entities that really changed. Problems that existed before a batch do not block it.
- Commands `get`, `set`, `list` and `help`, with dotted paths for every entity (`page./about.title.hu`, `layout.default.body.main.width`, `menu.main.items.2.label`).
- Translatable message catalogue (`app/translations/messages.en.yaml`) with English texts for every response and validation rule.
- Text and JSON output; JSON responses carry stable message keys and parameters next to the text.
- Developer CLI `bin/campanella` (`-e`, file or standard input, `--dry-run`, `--json`, `--locale`, `--root`).
- Command language reference: `docs/command-language.md`.
- Page commands: `page create`, `page delete`, `page alias`, `page publish`, `page unpublish`, `page place`, `page unplace`, `page reset`.
- Content commands: `content create`, `content edit`, `content translate`, `content delete` (whole content or one language version).
- Layout commands: `layout create` (empty or `--from` a copy), `layout delete`, `layout region add|remove`, `layout column add|remove`, `layout place|unplace` for default content.
- Menu commands: `menu create`, `menu delete`, `menu item add` (to a page or URL, nested with `--parent`), `menu item remove`.
- Language commands: `language add`, `language remove`, `language default`.
- `check` lists every validation problem of the website.
- Deleting something that is still used fails with a list of what uses it; `--force` also removes those references.
- Public website: front controller (`public/index.php`) that renders a page on its first visit and stores it as static HTML in the web root; Apache configuration (`public/.htaccess`) that serves stored pages without PHP.
- Addresses per language: the default language without a prefix, other languages under `/{code}/`; `/node/{id}` for pages without an alias; 301 redirects to the canonical address.
- Automatic redirects from old aliases (`project/redirects.yaml`), maintained at the end of every batch; `list redirects` and `redirect remove`.
- Default theme with Twig templates and Bootstrap 5.3.8: regions and columns on the grid, navbar menus with dropdowns, language switcher, `hreflang` and canonical links, accessible 404 page.
- Markdown rendering with league/commonmark; raw HTML in content is escaped and unsafe links are removed.
- Page cache with a manifest, so only generated files are ever deleted; every committed change clears it.
- `build` and `cache clear` commands, run after the batch has been written.
- Development router for PHP's built-in web server (`app/dev-router.php`).
- Developer documentation of the public website: `docs/generator.md`.
- Admin dashboard at `/admin`: command box (Ctrl+Enter to run, Alt+↑/↓ for history), dry-run switch, response box, per-user history of the last 50 batches, page list with public and preview links, status bar.
- Page preview for admins, drafts included: `/admin/preview/{id}[/{language}]`.
- Admin users in `config/users.yaml`, created with `bin/campanella --add-user=<name>`.
- `user set locale` command: each admin chooses the language of their messages.
- Developer documentation: `docs/admin.md`, `docs/security.md`.
- Admin dashboard layout checked in a real browser (Chrome via Playwright) on desktop and mobile widths.
- Hungarian message catalogue (`messages.hu.yaml`) for command responses, help, validation rules, the admin dashboard and the public theme; texts missing from a catalogue are shown in English.
- Command log: every batch that changes the website is appended to `var/log/commands.log` (time, user, changed files, the batch itself); the `log [--last=N]` command shows the latest ones.
- `undo` takes back the most recent change; the last 50 changes can be undone one by one. It refuses to overwrite files changed afterwards unless `--force` is given, and must be the only command in its batch.
- Starter websites in English and Hungarian (`app/starter/`): home, about and contact pages, a menu and a footer.
- First-time setup in the browser: while there is no admin user, `/admin` shows a setup form that creates the website from a starter and the first admin user.
- `bin/campanella --init` (with `--starter`, `--site-name`, `--base-url`) sets up an installation on the command line; `--version` shows the version.
- Root `.htaccess` for hosting where the document root cannot be set to `public/`.
- The public website answers `503` with a hint to `/admin` until it has been set up.
- Release workflow that builds an installable zip package with the dependencies on every version tag, and a manually run workflow that creates `composer.lock`.
- Installation guide: `docs/installation.md`.

### Security

- The front controller answers only `GET` and `HEAD`, refuses paths with `..`, `//`, backslashes or control characters, and never shows error details to visitors.
- Admin passwords are stored as `password_hash` hashes (at least 10 characters); failed logins are throttled per address (5 per 15 minutes) and logged to `var/log/auth.log`.
- Admin sessions: dedicated `HttpOnly`, `SameSite=Strict` cookie limited to `/admin` (`Secure` on HTTPS), new session id at login, 30-minute idle timeout and 12-hour maximum.
- CSRF tokens on every state-changing admin request; strict Content Security Policy, no framing, `no-store` caching for the admin.
- The web setup needs a random setup key that can only be read on the server (`config/setup-key.txt`, mode 0600); wrong keys are throttled and logged, and the key is deleted after the setup.
- The setup puts `Require all denied` `.htaccess` files into `config/`, `project/` and `var/`.

[Unreleased]: https://github.com/szhajagos/campanella-LITE/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/szhajagos/campanella-LITE/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/szhajagos/campanella-LITE/releases/tag/v0.1.0
