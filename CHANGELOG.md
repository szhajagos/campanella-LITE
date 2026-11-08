# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each release lists its changes under these headings, as needed:
Added, Changed, Deprecated, Removed, Fixed, Security.

## [Unreleased]

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
- Hungarian message catalogue (`messages.hu.yaml`) for command responses, help, validation rules, the admin dashboard and the public theme; texts missing from a catalogue are shown in English.

### Security

- The front controller answers only `GET` and `HEAD`, refuses paths with `..`, `//`, backslashes or control characters, and never shows error details to visitors.
- Admin passwords are stored as `password_hash` hashes (at least 10 characters); failed logins are throttled per address (5 per 15 minutes) and logged to `var/log/auth.log`.
- Admin sessions: dedicated `HttpOnly`, `SameSite=Strict` cookie limited to `/admin` (`Secure` on HTTPS), new session id at login, 30-minute idle timeout and 12-hour maximum.
- CSRF tokens on every state-changing admin request; strict Content Security Policy, no framing, `no-store` caching for the admin.
