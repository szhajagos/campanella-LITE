# Developer documentation

This directory holds the developer documentation of Campanella Lite. It grows together with the code: a document is added when the part it describes is implemented.

## Documents

| Document | Describes |
| --- | --- |
| [architecture.md](architecture.md) | Components, write path and read path, implementation status |
| [data-model.md](data-model.md) | Entities, file formats, identifiers, validation rules and violation codes |
| [development.md](development.md) | Setup, checks, CI, conventions |

## Planned documents

| Document | Describes | Added with |
| --- | --- | --- |
| `command-language.md` | Syntax, grammar, command reference, responses, error codes | M2–M3 |
| `generator.md` | Rendering, caching and invalidation, redirects, web server setup | M4 |
| `security.md` | Authentication, file access, logging, undo | M5–M6 |

## User guide

A user guide will live in `docs/guide/` once the MVP is usable.

## Conventions

- Documentation is written in English, in Markdown.
- Decisions go to [ROADMAP.md](../ROADMAP.md); released changes go to [CHANGELOG.md](../CHANGELOG.md).
