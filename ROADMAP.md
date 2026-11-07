# Roadmap

This file records the decisions made about Campanella Lite and the work planned for the future. It is updated whenever a decision is made, not only at releases. What has actually shipped is recorded in [CHANGELOG.md](CHANGELOG.md).

## Decisions

Status: **Accepted** = agreed and binding; **Proposed** = current plan, open to change until implemented.

| Date | Area | Decision | Status |
| --- | --- | --- | --- |
| 2026-11-07 | Language | The project language is English: code, documentation, command keywords and admin messages by default. | Accepted |
| 2026-11-07 | Runtime | PHP and Composer dependencies, admin and public site on the same server. | Accepted |
| 2026-11-07 | Storage | No database. Configuration and content are stored in files. | Accepted |
| 2026-11-07 | Storage | YAML for configuration, one file per entity (not PHP arrays, which are executable code). | Proposed |
| 2026-11-07 | Content | Markdown with YAML front matter in the MVP. HTML input later; Markdown stays the default. | Accepted |
| 2026-11-07 | Presentation | Bootstrap 5 (12-column grid) and Twig templates. One simple built-in theme in the first version. | Accepted |
| 2026-11-07 | Architecture | The command interpreter is the only write path, for the admin UI, the CLI and the AI module alike. | Accepted |
| 2026-11-07 | Commands | `<noun> <verb>` syntax with English keywords and `--key=value` options. | Proposed |
| 2026-11-07 | Commands | Batches are all-or-nothing: every line is validated before anything is written. | Proposed |
| 2026-11-07 | Publishing | On-demand static generation: the first request renders and stores a page, later requests get the static file. | Accepted |
| 2026-11-07 | URLs | URLs are never generated automatically. Every page gets a numeric id and is available at `/node/<id>`; an optional ASCII-only alias (e.g. `/about`) can be set by the admin. | Accepted |
| 2026-11-07 | Multilingual | Admin messages are translatable (each admin chooses a locale, English is the fallback) and websites can be multilingual. Language-dependent fields are stored per language from the MVP on; details come later. | Accepted |
| 2026-11-07 | Web server | Apache (`.htaccess`) only in the MVP; other servers later. | Accepted |
| 2026-11-07 | Undo | The last 50 batches can be undone. | Accepted |
| 2026-11-07 | Licensing | MIT for Campanella Lite; only permissively licensed dependencies (MIT, Apache 2.0, BSD). An AI model is a separate download or service and does not change the project licence. | Accepted |
| 2026-11-07 | Repository | Public GitHub repository; commits are made by the maintainer. | Accepted |
| 2026-11-07 | Runtime | Minimum PHP version is 8.3 (security support until 2027-12-31); to be reviewed before the first stable release. | Accepted |
| 2026-11-07 | URLs | If a page has an alias, `/node/<id>` answers with an HTTP 301 redirect to the alias. | Accepted |
| 2026-11-07 | URLs | When an alias changes, the old alias redirects (301) to the page's current alias, until another page takes the old alias. Redirects point to page ids, so renaming twice never creates a chain. | Accepted |

## MVP (0.1.0)

Built from the inside out: storage and command interpreter first, user interface last. From milestone 2 on, a developer CLI (`bin/campanella`) runs the same interpreter, so everything can be tested without a browser.

- [ ] **M1 – Skeleton and storage:** Composer project, directory layout, YAML/Markdown read and write (atomic, locked), entity classes, validator, PHPUnit tests, CI.
- [ ] **M2 – Command interpreter:** lexer and parser, command registry, `get` / `set` / `list` / `help`, batch transactions, `@dry-run`, text and JSON responses, message catalogue (English).
- [ ] **M3 – Entity commands:** `page`, `content`, `layout`, `menu` commands and `check`.
- [ ] **M4 – Generator:** default Bootstrap 5 theme in Twig, front controller, `.htaccess`, cache manifest, alias redirects, `build`, `cache clear`.
- [ ] **M5 – Admin dashboard:** login, command box, response box, command history, status bar.
- [ ] **M6 – Finishing:** command log, `undo`, installer, starter project with example pages, command language reference.

## After the MVP

Not yet scheduled; order to be decided.

- **AI module:** model adapter with exchangeable models (local or remote), natural language to command batches, approval workflow, maintenance suggestions. Preferred models are openly available under MIT or Apache 2.0.
- **Multilingual websites:** language URL prefixes, translation workflow, language switcher.
- **Admin message translations:** Hungarian first.
- **HTML content** alongside Markdown.
- **Multiple users** with restricted, role-based command permissions.
- **Images and media management.**
- **More web servers:** Nginx configuration.
- **Themes:** exchangeable theme directories.
- **Smarter cache invalidation:** remove only the pages affected by a change.
- **User guide** in `docs/guide/`.

## Open questions

None at the moment.
