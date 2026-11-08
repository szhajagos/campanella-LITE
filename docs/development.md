# Development

## Requirements

- PHP 8.3 or newer with the `mbstring` extension, plus `dom`, `xml` and `xmlwriter` for PHPUnit
- Composer 2

## Setup

```
composer install
```

Dependencies are installed into `app/vendor/`, which keeps everything except `public/` out of the web root.

## Checks

| Command | What it runs |
| --- | --- |
| `composer test` | PHPUnit test suite |
| `composer analyse` | PHPStan static analysis at level 8 |
| `composer check` | Both of the above |

The same checks run on GitHub Actions for every push to `main` and every pull request, on PHP 8.3, 8.4 and 8.5 (`.github/workflows/ci.yml`). Dependencies are resolved for PHP 8.3 (`config.platform` in `composer.json`), so every PHP version in the matrix tests the same package versions.

## Trying commands

`bin/campanella` runs a batch against an installation (by default the repository root, which needs a `project/` directory). The test fixture is a complete sample project:

```
mkdir -p /tmp/demo && cp -r tests/Fixtures/valid-project /tmp/demo/project
bin/campanella --root=/tmp/demo -e "list pages"
bin/campanella --root=/tmp/demo --dry-run -e "set site.name \"New name\""
```

See [command-language.md](command-language.md) for the commands.

## Viewing the website

PHP's built-in web server with the development router behaves like the Apache configuration:

```
php -S localhost:8000 -t public app/dev-router.php
```

The admin is at `/admin`; create a user first with `bin/campanella --add-user=admin` (stored in `config/`, ignored by git). It needs a `project/` directory in the repository root (ignored by git). The sample project is a good start: `cp -r tests/Fixtures/valid-project project`. Generated pages appear in `public/` and are ignored by git as well. See [generator.md](generator.md).

## Directory layout

```
app/src/          # Campanella\Lite\ – application code (PSR-4)
app/translations/ # Message catalogues (messages.{locale}.yaml)
app/themes/       # Built-in themes (Twig templates)
app/dev-router.php# Router for PHP's built-in web server
app/admin/        # Twig templates of the admin dashboard
app/starter/      # Starter websites copied by the installer (en, hu)
public/           # Web root: front controller, admin entry, .htaccess, assets, generated pages
config/           # Admin users and the setup key (not committed)
bin/campanella    # Developer CLI
app/vendor/       # Composer dependencies (not committed)
tests/            # Campanella\Lite\Tests\ – PHPUnit tests
tests/Fixtures/   # Sample project directories used by tests
docs/             # Developer documentation
var/              # Caches, logs, locks, undo snapshots (not committed)
```

## Conventions

- Every PHP file starts with `declare(strict_types=1);`.
- Classes are `final` unless they are designed for extension.
- Model objects are immutable: changes produce a new object.
- Errors in data are reported precisely: structural errors as `InvalidDataException` with file and field, semantic errors as validator violations with a stable code.
- Code style follows the [PER Coding Style](https://www.php-fig.org/per/coding-style/).
- Documentation, identifiers and messages are written in English.
- Every behaviour change comes with tests; `composer check` must pass before a commit.

## Dependencies

Only permissively licensed packages are used (MIT, BSD, Apache 2.0). Check before adding one:

```
composer licenses
```

## composer.lock

`composer.lock` is committed, so CI and the release package use exactly the tested versions. Without a local PHP it is created on GitHub:

1. Actions → **Update dependencies** → *Run workflow*. It resolves the dependencies for PHP 8.3 (the platform set in `composer.json`), runs the checks and lists the versions.
2. Download the `composer-lock` artifact from the run, put `composer.lock` into the repository root and commit it.

Run it again to update the dependencies. With a lock file, CI runs `composer install` and checks that the lock matches `composer.json`; without one it falls back to `composer update`.

## Releases

1. Set `Application::VERSION` and move the `[Unreleased]` changes in `CHANGELOG.md` under a new version heading.
2. Commit, then tag and push: `git tag -a v0.1.0 -m "Campanella Lite 0.1.0"` and `git push origin v0.1.0`.
3. The **Release** workflow checks that the tag, `Application::VERSION` and the changelog agree, runs the checks, builds `campanella-lite-<version>.zip` (the repository without the `export-ignore` files of `.gitattributes`, plus `app/vendor/` without development packages) with a SHA-256 checksum, and publishes a GitHub release with the changelog section as notes.
