# Architecture

Campanella Lite has two paths through the system. The **write path** changes the website description; the **read path** serves it to visitors. They meet only in the project files and in the cache of generated pages.

```
 WRITE PATH                                       READ PATH

 Admin dashboard ─┐                               Visitor
 Developer CLI  ──┼─► Command interpreter            │
 AI module      ──┘   (parse, validate,              ▼
                       execute, log)             Web server ── HTML exists? ── yes ──► public/*.html
                            │                        │ no
                            ▼                        ▼
                    project/ (YAML, Markdown)    public/index.php
                            │                        │
                            └──────► Generator ◄─────┘
                                     (Twig)
```

The rule that holds the design together: **the command interpreter is the only component that writes to `project/`.** The admin dashboard, the developer CLI and the future AI module all submit commands; none of them touches files directly. The generator only reads.

## Implementation status

| Component | Namespace | Milestone | Status |
| --- | --- | --- | --- |
| Installation paths | `Campanella\Lite\Paths` | M1 | Done |
| File storage | `Campanella\Lite\Storage` | M1 | Done |
| Data model | `Campanella\Lite\Model` | M1 | Done |
| Project loading and saving | `Campanella\Lite\Project` | M1 | Done |
| Validation | `Campanella\Lite\Validation` | M1 | Done |
| Command parser, interpreter, `get` / `set` / `list` / `help` | `Campanella\Lite\Command` | M2 | Done |
| Message catalogue | `Campanella\Lite\I18n` | M2 | Done |
| Developer CLI (`bin/campanella`) | `Campanella\Lite\Cli` | M2 | Done |
| Entity commands (`page`, `content`, `layout`, `menu`, `language`, `check`) | `Campanella\Lite\Command\Handler` | M3 | Done |
| Routing and redirects | `Campanella\Lite\Routing` | M4 | Done |
| Renderer, theme, page cache, build | `Campanella\Lite\Render` | M4 | Done |
| Front controller | `Campanella\Lite\Http` | M4 | Done |
| Admin dashboard, users, login security | `Campanella\Lite\Admin` | M5 | Done |
| Command log, `log` | `Campanella\Lite\Command\Log` | M6 | Done |
| Undo snapshots, `undo` | `Campanella\Lite\Project\UndoHistory` | M6 | Done |
| Installer, starters, web setup | `Campanella\Lite\Install` | M6 | Done |
| File repository, uploads, `file` commands | `Campanella\Lite\Files` | M7 | Done |
| Files on the website, image sizes, file panel, asset versions | `Campanella\Lite\Files`, `Campanella\Lite\Render\Markdown` | M8 | Done |

## Layers built in M1

### Storage (`Campanella\Lite\Storage`)

- **`FileStore`** reads and writes files below one base directory. Paths are relative and checked segment by segment: no `..`, no absolute paths, no backslashes, no hidden files. Writes go to a temporary file in the same directory, which is then renamed over the target, so a reader never sees a half-written file.
- **`WriteLock`** is an exclusive `flock` lock on `var/lock/write.lock`. A command batch will hold it from validation until the last file is written, so two admin requests cannot interleave. Nested use in the same process does not block.
- **`YamlCodec`** wraps symfony/yaml. It accepts plain data only: PHP objects, constants and custom tags are rejected, so a project file can never instantiate code. Output uses two-space indentation, block style for the outer levels and inline style for short, deeply nested values.
- **`FrontMatterCodec`** splits a Markdown file into its YAML front matter and its body, and joins them again. Windows line endings and a byte order mark are accepted on input.

### Model (`Campanella\Lite\Model`)

Immutable value objects for the site, layouts (regions and columns), pages, content (one translation per language) and menus. Each knows how to build itself from decoded YAML (`fromArray`) and how to turn itself back into it (`toArray`), and a round trip gives the same data.

`Project` is an immutable snapshot of the whole website description. The command interpreter works on a changed copy and validates it before anything is written.

### Project (`Campanella\Lite\Project`)

`ProjectRepository` maps the model to files: it loads the whole project directory and saves or deletes single entities. It checks that file names match the entities inside them (`pages/2.yaml` must contain `id: 2`).

### Validation (`Campanella\Lite\Validation`)

`ProjectValidator` checks a whole `Project` and returns every `Violation` it finds instead of stopping at the first. See [data-model.md](data-model.md#validation) for the rules and the violation codes.

## Two kinds of errors

| Kind | Raised by | Example | Result |
| --- | --- | --- | --- |
| Structural | Loading (`Fields`, codecs, repository) | Broken YAML, `width: wide`, unknown key | `InvalidDataException` naming the file and field; nothing is loaded |
| Semantic | `ProjectValidator` | A page uses a layout that does not exist | A list of `Violation` objects; the batch is rejected |

Structural errors normally only appear when someone edits files by hand. Commands produce well-formed data, so the errors they can cause are semantic and are reported as violations, all at once.

## Layers built in M2

### Command (`Campanella\Lite\Command`)

- **`Parser\BatchParser`** turns the command box text into `ParsedCommand`s (words, options, multi-line text) and `SyntaxError`s with line numbers.
- **`CommandRegistry`** matches the longest run of leading words to a command (`layout region add default hero` → `layout region add` + arguments) and checks the invocation against the command's `Signature`: argument count, known options, option values, text input. Handlers never see an invalid invocation.
- **`CommandHandler`**s implement the commands. They change the project only through the **`Workspace`**, an in-memory copy that remembers which entities were touched, and report failure by throwing `CommandFailed` with a translatable message.
- **`ProjectPaths`** reads any value by dotted path and writes a fixed set of fields, by changing the stored array form of an entity and rebuilding it, so every write passes the same structural checks as loading.
- **`Interpreter`** runs a batch all or nothing (below) and returns a `BatchResult`.
- **`Format\TextFormatter`** and **`Format\JsonFormatter`** render results for people and programs.

### Entity commands (M3)

The handlers in `Command\Handler\{Page,Content,Layout,Menu,Language}` build on two helpers in `Command\Handler\Support`:

- **`Lookup`** finds entities and checks input (names, languages, positions, required options), failing with translatable messages.
- **`References`** finds what refers to an entity ("menu main item 2", "page 3 (body.main)") so that deleting something in use fails with a precise list, and removes those references when the user adds `--force`.

Model objects offer typed `with…()` methods, so a handler changes an entity by building a new one; the workspace keeps track of what changed.

### Public website (M4)

- **`Routing\Urls`** builds the canonical address of a page per language; **`Routing\Router`** resolves a requested path to a page, a redirect or "not found"; **`Routing\RedirectMaintainer`** turns changed aliases into redirects at the end of each batch.
- **`Render\PageRenderer`** renders pages with Twig and CommonMark; **`Render\PageCache`** stores generated pages in the web root and records them in a manifest; **`Render\SiteBuilder`** generates pages ahead of time.
- **`Http\FrontController`** answers requests the web server could not serve from a file.

Commands that touch files outside the project (`build`, `cache clear`) return deferred work, which the interpreter runs only after the batch has been written. See [generator.md](generator.md).

### Admin (M5)

`Admin\AdminController` handles everything under `/admin` (login, logout, running batches, previews) on top of a small `Http\Request`/`Http\Response` pair and a `Session` interface, so it is tested without a web server. Users live in `config/users.yaml` (`Admin\UserRepository`); `LoginThrottle`, `AuthLog` and `History` keep their data in `var/`. The dashboard is server-rendered Twig with a small script (`public/assets/admin/admin.js`). The interpreter receives the logged-in user's name, for commands about the user's own settings. See [admin.md](admin.md) and [security.md](security.md).

### Command log and undo (M6)

- **`UndoHistory`** keeps one snapshot per applied batch in `var/undo/{id}.json`: the earlier contents of every file the batch changed (`null` for a file that did not exist) and a SHA-1 hash of each file as the batch left it. The interpreter captures the files just before it writes and records the snapshot right after, under the write lock. Only the latest 50 are kept.
- **`undo`** is run by the interpreter itself instead of on the workspace: it checks that the files are still as the batch left them (otherwise it refuses, unless `--force`), restores them, deletes the snapshot and clears the page cache. Its signature is marked `standalone`, so a batch containing `undo` and anything else is rejected.
- **`CommandLog`** appends one JSON line per applied batch or undo to `var/log/commands.log`; the batch id is shared with the undo snapshot. `log` reads the latest entries.

### Installer (`Campanella\Lite\Install`)

`Installer` creates `config/`, `var/` and `project/` (each with a `Require all denied` `.htaccess`), copies a starter from `app/starter/{language}/` and names the website, creates the first admin user, and manages the setup key. It is the only code besides the interpreter that writes to `project/`, and only into an empty one. `AdminController` shows the setup form while there is no admin user; the CLI offers `--init`.

### Files (`Campanella\Lite\Files`, M7)

- **`FileRepository::add()`** is the single way into the file repository, used by the admin upload, the CLI and later the AI module. Under the write lock it stores the file with a random name, writes its manifest and hands out the next id. This is the one write to `project/` outside the interpreter; it only adds a file nothing refers to yet, so it cannot change the website.
- **`FileInspector`** detects the type from the file's signature and refuses anything not on the list of `FileTypes` or carrying active content.
- Manifests are entities like pages (`EntityRef::file`, `Workspace::putFile`), so the `file` commands, validation, the log and undo work as for everything else. `file purge` deletes manifests and stored files after the batch, like `build`.

### Files on the website (M8)

- **`PublicFiles`** knows every address a file is published at (`/files/{id}-{slug}[-{width}].{ext}`), makes the file behind an address on its first request (with `ImageResizer`, GD) and stores it through a second `PageCache` with its own manifest. `keeper()` tells which published copies are still current; the interpreter's commit hook prunes the others when a file changes.
- **`FileEmbedder`** is a league/commonmark event listener: after a document is parsed, it replaces `file:` images with `EmbeddedFile`/`FileFigure` nodes carrying escaped HTML (pictures, players, links) and points `file:` links to the published address.
- The front controller hands `/files/` addresses to `PublicFiles` before routing pages.

### I18n (`Campanella\Lite\I18n`)

A `Message` is a key plus parameters; the `Translator` turns it into text from `app/translations/messages.{locale}.yaml`, falling back to English. Validation violations are translated through the `violation.` key prefix. A test checks that every key used in the code has an English text.

### CLI (`Campanella\Lite\Cli`)

`bin/campanella` reads a batch from `-e`, a file or standard input and prints the result as text or JSON.

### Application

`Campanella\Lite\Application` wires the services of an installation together explicitly, without a container.

## Write sequence

1. Parse the whole batch. A syntax error rejects it.
2. Acquire the write lock and load the project.
3. Apply each command to the workspace. The first failing command rejects the batch.
4. Validate the result. Violations that were not there before reject the batch.
5. *(M6)* Store an undo snapshot of the files about to change.
6. Write the changed entities (or, on a dry run, only report them), clear the generated pages, and run deferred work such as `build`. *(M6)* Append to the command log.
7. Release the lock and return the responses.
