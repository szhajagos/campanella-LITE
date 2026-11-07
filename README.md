# Campanella Lite

**A lightweight, file-based, command-driven CMS that publishes static websites.**

Campanella Lite is managed through a small, documented command language instead of forms and a database. Every change, whether typed by an administrator or (later) proposed by an AI agent, goes through the same command interpreter, which validates it, applies it atomically and logs it. Visitors get plain static HTML.

> **Status: pre-alpha.** Milestone 1 (storage, data model, validation) is done; there is no usable release yet. The concepts below describe the planned MVP and may still change. See [ROADMAP.md](ROADMAP.md) for decisions and progress.

---

## Table of contents

- [Why Campanella Lite?](#why-campanella-lite)
- [Key ideas](#key-ideas)
- [How it works](#how-it-works)
- [The command language](#the-command-language)
- [Data model](#data-model)
- [Requirements](#requirements)
- [Installation](#installation)
- [Directory structure](#directory-structure)
- [Documentation](#documentation)
- [Relationship to Campanella CMS](#relationship-to-campanella-cms)
- [Contributing](#contributing)
- [License](#license)

---

## Why Campanella Lite?

Most content management systems are built around a database, a large admin UI and a dynamic front end that runs on every request. That is a lot of machinery for a small business website, a portfolio or a documentation site.

Campanella Lite takes a different route:

- **The website is described in plain files.** Pages, layouts, menus and content live in YAML and Markdown files that you can read, diff and keep in Git.
- **The website is changed with commands.** A command line in the admin dashboard is the single way to change the site. The same interface can be used by scripts and by an AI agent, which can never bypass the rules of the CMS.
- **The website is served as static HTML.** Pages are generated once and then served by the web server directly, so the public site is fast, cheap to host and has a minimal attack surface.

## Key ideas

| Idea | What it means |
| --- | --- |
| No database | Configuration and content are stored in files (YAML and Markdown). Installing means copying files. |
| Command-driven | A single, documented command language is the only write path into the system. |
| Static output | Pages are generated on the first request and then served as static files until something changes. |
| Grid layouts | Layouts consist of regions and columns on the Bootstrap 5 twelve-column grid. |
| AI-ready, AI-optional | A future AI module will turn natural-language requests into commands. The CMS works fully without it. |
| Multilingual by design | Language-dependent fields are stored per language from day one; admin messages are translatable. |
| Permissive licensing | Campanella Lite is MIT-licensed and only uses permissively licensed dependencies. |

## How it works

```
 WRITE PATH                                            READ PATH

 Admin dashboard ─┐                                    Visitor
 Developer CLI  ──┼─► Command interpreter                 │
 AI module      ──┘   parse · validate · execute          ▼
 (planned)            · log · snapshot               Web server
                            │                          │       │
                            ▼                     HTML exists?  │ no
                  project/ (YAML + Markdown)           │ yes   ▼
                            │                          │   index.php
                            ▼                          │ (front controller)
                     Generator (Twig) ◄────────────────┼───────┘
                            │                          │
                            └──────► public/*.html ────┘
```

1. The administrator enters one or more commands in the dashboard.
2. The command interpreter parses and validates the whole batch. If any line fails, nothing is changed.
3. A valid batch is written to the `project/` files atomically, logged, and a snapshot is kept for `undo`.
4. Generated pages affected by the change are removed from the cache.
5. The next visitor request for a missing page is routed to the front controller, which renders it with Twig, saves it to `public/` and serves it. Every later visitor gets the static file directly from the web server.

## The command language

> The syntax is a draft and will be finalised during the MVP.

One line is one command, in the form `<noun> <verb> [arguments] [--option=value ...]`. Everything typed into the command box is executed as a single all-or-nothing batch.

```
# Create content and a page that shows it
content create intro --title="Introduction" <<<
Our small team has been building **custom** websites since 2015.
>>>
page create --title="About us" --alias=/about --layout=default
page place /about body.main intro
menu item add main --label="About" --page=/about
set layout.default.body.main.width 9
get page./about
```

Example response:

```
[1] Ok. Created content: intro
[2] Ok. Created page 2 (/about)
[3] Error: unknown column: body.content (available: body.main, body.sidebar)
→ Batch not executed, nothing was changed.
```

Highlights:

- English keywords; responses in English by default, translatable per admin user (`user set locale hu`).
- Multi-line text between `<<<` and `>>>`, comments after `#`.
- `@dry-run` as the first line shows what would change without changing anything.
- Generic `get`, `set` and `list` commands address any value by a dotted path (`page.2.title`, `layout.default.body.main.width`).
- `check`, `build`, `cache clear`, `undo` (up to 50 batches), `log` and `help` for maintenance.
- Every response is also available as JSON, which is the interface the future AI module will use.

## Data model

| Entity | Stored in | Identifier |
| --- | --- | --- |
| Site settings (languages, front page, …) | `project/site.yaml` | – |
| Layout (regions → columns) | `project/layouts/<name>.yaml` | machine name |
| Page | `project/pages/<id>.yaml` | numeric id assigned by the system |
| Content | `project/content/<name>/<language>.md` | machine name |
| Menu | `project/menus/<name>.yaml` | machine name |

- A page without an alias is available at `/node/<id>`. An optional, ASCII-only alias such as `/about` gives it a readable URL; `/node/<id>` and any previous aliases then redirect to it.
- A region is a Bootstrap `row`, a column is a `col-md-N`; content is placed into columns, either as a layout default (header, footer) or per page.
- Language-dependent fields are stored as language maps, even on single-language sites:

```yaml
# project/pages/2.yaml
id: 2
alias: { en: /about }
title: { en: About us }
description: { en: Meet our team. }
layout: default
status: published
placements:
  body.main: [intro, team]
```

## Requirements

Planned minimum requirements for the MVP:

- PHP 8.3 or newer
- Composer
- Apache with `mod_rewrite` (other web servers are planned for later)
- Write permission for the `project/`, `var/` and `public/` directories

No database and no AI model are required.

## Installation

Not available yet. Installation instructions will be added with the first release.

## Directory structure

Planned layout of an installation:

```
campanella-lite/
├── app/          # PHP source code, translations, Composer dependencies
├── project/      # the website description: YAML and Markdown files
├── var/          # logs, locks, undo snapshots
└── public/       # web root: front controller, admin entry, assets, generated HTML
```

Only `public/` should be exposed by the web server.

## Documentation

- [ROADMAP.md](ROADMAP.md): decisions made so far and planned work
- [CHANGELOG.md](CHANGELOG.md): changes in each release
- [docs/](docs/): developer documentation (grows with the code), including the [architecture](docs/architecture.md), the [data model](docs/data-model.md) and the [development guide](docs/development.md)

A user guide will be added once there is something to guide through.

## Relationship to Campanella CMS

Campanella Lite is an independent sibling of the Campanella CMS, a capability-based, database-backed system with a broad feature set. Campanella Lite has its own code base and does not aim for compatibility with it. Its focus is the opposite end: simple, fast, easy to install, command-driven and AI-friendly.

## Contributing

The project is in an early stage. Issues with questions, ideas and feedback are welcome. To work on the code, see the [development guide](docs/development.md): `composer install`, then `composer check` runs the static analysis and the tests. Contribution guidelines will follow when the code base takes shape.

## License

Campanella Lite is released under the [MIT License](LICENSE).
