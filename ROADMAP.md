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
| 2026-11-07 | Commands | `<noun> <verb>` syntax with English keywords and `--key=value` options. | Accepted |
| 2026-11-07 | Commands | Batches are all-or-nothing: every line is validated before anything is written. | Accepted |
| 2026-11-07 | Publishing | On-demand static generation: the first request renders and stores a page, later requests get the static file. | Accepted |
| 2026-11-07 | URLs | URLs are never generated automatically. Every page gets a numeric id and is available at `/node/<id>`; an optional ASCII-only alias (e.g. `/about`) can be set by the admin. | Accepted |
| 2026-11-07 | Multilingual | Admin messages are translatable (each admin chooses a locale, English is the fallback) and websites can be multilingual. Language-dependent fields are stored per language from the MVP on; details come later. | Accepted |
| 2026-11-07 | Web server | Apache (`.htaccess`) only in the MVP; other servers later. | Accepted |
| 2026-11-07 | Undo | The last 50 batches can be undone. | Accepted |
| 2026-11-07 | Licensing | MIT for Campanella Lite; only permissively licensed dependencies (MIT, Apache 2.0, BSD). An AI model is a separate download or service and does not change the project licence. | Accepted |
| 2026-11-07 | Repository | Public GitHub repository; commits are made by the maintainer. | Accepted |
| 2026-11-07 | Runtime | Minimum PHP version is 8.3 (security support until 2027-12-31); to be reviewed before the first stable release. | Accepted |
| 2026-11-07 | URLs | If a page has an alias, `/node/<id>` answers with an HTTP 301 redirect to the alias. | Accepted |
| 2026-11-07 | URLs | The page served at `/` is set with `front_page` in `site.yaml`; an alias cannot be `/`. | Accepted |
| 2026-11-07 | URLs | Aliases may not start with `admin`, `assets`, `node` or an enabled language code, which are kept free for language prefixes. Aliases are unique per language. | Accepted |
| 2026-11-07 | Data model | Region names `name` and `title` and the column name `container` are reserved, so that paths such as `layout.default.body.main.width` stay unambiguous. | Accepted |
| 2026-11-07 | Commands | A batch is rejected only by violations it introduces; problems that already existed (e.g. from hand-edited files) never block unrelated changes. | Accepted |
| 2026-11-07 | Commands | Responses carry a stable message key and parameters; the text comes from a per-locale catalogue with English fallback. JSON output exposes both, for the dashboard and the AI module. | Accepted |
| 2026-11-07 | Commands | A page column shows the layout's default content until the page places or removes something there; `page place`/`page unplace` start from what the page shows, `page reset` returns to the defaults. | Accepted |
| 2026-11-07 | Commands | Deleting an entity that is still used fails and lists the references; `--force` removes the references too. Layouts in use can never be deleted, because pages cannot be left without one. | Accepted |
| 2026-11-07 | Commands | Rules are checked at the end of a batch, so a batch may pass through intermediate states (e.g. narrow a column, then add one) as long as the result is valid. | Accepted |
| 2026-11-08 | URLs | The default language has no address prefix; every other language is served under `/{code}/` (`/hu/rolunk`, `/hu/node/7`, `/hu/` for the front page). Each page has one canonical address per language; all other ways to reach it redirect (301). | Accepted |
| 2026-11-08 | Publishing | Drafts are never shown to visitors (404); menu items pointing to drafts are left out. | Accepted |
| 2026-11-08 | Publishing | Every committed batch clears all generated pages (MVP); pages are generated again on their next visit or with `build`. | Accepted |
| 2026-11-08 | Publishing | Generated files are tracked in a manifest; the generator never deletes or overwrites files it did not create. | Accepted |
| 2026-11-08 | Themes | Built-in themes ship with the code (`app/themes/{name}/templates`, assets in `public/assets/themes/{name}`); Bootstrap is bundled, not loaded from a CDN. | Accepted |
| 2026-11-08 | Admin | Admin users live in `config/users.yaml`, outside the web root and the project; the first user is created with `bin/campanella --add-user`, a web setup comes with the installer. | Accepted |
| 2026-11-08 | Admin | The dashboard is server-rendered (Twig) with a small framework-free script; commands run through the same interpreter and JSON endpoint the AI module will use. | Accepted |
| 2026-11-08 | Admin | History recall uses Alt+↑/↓, because plain arrow keys move the cursor in the multi-line command box. | Accepted |
| 2026-11-08 | Languages | Hungarian is the first translation of the messages. Any text missing from a catalogue is shown in English, key by key. Catalogues must use the English keys and placeholders (checked by tests). | Accepted |
| 2026-11-08 | Undo | `undo` restores the files kept before the last applied batch (`var/undo/`, 50 batches). It is a batch of its own, and it refuses when a file has changed since, unless `--force` is given. There is no redo in 0.1.0. | Accepted |
| 2026-11-08 | Log | Every batch that changes the website is logged as one JSON line in `var/log/commands.log`, including the batch itself; the file is rotated at 2 MB. | Accepted |
| 2026-11-08 | Installer | The web setup runs on `/admin` while there is no admin user and needs a setup key from `config/setup-key.txt`. The command line offers the same with `--init`. Starters exist in English and Hungarian. | Accepted |
| 2026-11-08 | Hosting | Where the document root cannot be `public/`, a root `.htaccess` passes every request to `public/` and refuses everything without `mod_rewrite`. | Accepted |
| 2026-11-08 | Releases | A version tag (`v0.1.0`) builds a zip with the production dependencies; `composer.lock` is created by a manually run workflow and committed, because the maintainer works without local PHP. | Accepted |
| 2026-11-07 | URLs | When an alias changes, the old alias redirects (301) to the page's current alias, until another page takes the old alias. Redirects point to page ids, so renaming twice never creates a chain. | Accepted |

## MVP (0.1.0)

Built from the inside out: storage and command interpreter first, user interface last. From milestone 2 on, a developer CLI (`bin/campanella`) runs the same interpreter, so everything can be tested without a browser.

- [x] **M1 – Skeleton and storage:** Composer project, directory layout, YAML/Markdown read and write (atomic, locked), entity classes, validator, PHPUnit tests, CI.
- [x] **M2 – Command interpreter:** lexer and parser, command registry, `get` / `set` / `list` / `help`, batch transactions, `@dry-run`, text and JSON responses, message catalogue (English).
- [x] **M3 – Entity commands:** `page`, `content`, `layout`, `menu` and `language` commands and `check`.
- [x] **M4 – Generator:** default Bootstrap 5 theme in Twig, front controller, `.htaccess`, cache manifest, alias redirects, `build`, `cache clear`.
- [x] **M5 – Admin dashboard:** login, command box, response box, command history, status bar.
- [x] **M6 – Finishing:** command log, `undo`, installer, starter project with example pages, command language reference, `composer.lock` and release workflows.

## After the MVP

Not yet scheduled; order to be decided.

- **AI module:** model adapter with exchangeable models (local or remote), natural language to command batches, approval workflow, maintenance suggestions. Preferred models are openly available under MIT or Apache 2.0.
- **Multilingual websites:** language URL prefixes, translation workflow, language switcher.
- **More message translations** besides English and Hungarian.
- **HTML content** alongside Markdown.
- **Multiple users** with restricted, role-based command permissions.
- **Images and media management.**
- **More web servers:** Nginx configuration.
- **Themes:** exchangeable theme directories.
- **Smarter cache invalidation:** remove only the pages affected by a change.
- **Installing into a subdirectory** of a domain (`example.com/site/`).
- **Redo** after `undo`.
- **Browser tests** with Playwright (login, running commands, the public pages) in CI; the same setup can produce the screenshots of the user guide.
- **User guide** in `docs/guide/`.

## Open questions

None at the moment.
