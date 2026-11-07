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
