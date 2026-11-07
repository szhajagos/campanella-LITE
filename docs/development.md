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

## Directory layout

```
app/src/          # Campanella\Lite\ – application code (PSR-4)
app/translations/ # Message catalogues (messages.{locale}.yaml)
bin/campanella    # Developer CLI
app/vendor/       # Composer dependencies (not committed)
tests/            # Campanella\Lite\Tests\ – PHPUnit tests
tests/Fixtures/   # Sample project directories used by tests
docs/             # Developer documentation
var/              # Caches, logs, locks (not committed)
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
