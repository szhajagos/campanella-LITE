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
| Generator and front controller | `Campanella\Lite\Generator` | M4 | Planned |
| Admin dashboard | `Campanella\Lite\Admin` | M5 | Planned |

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
6. Write the changed entities (or, on a dry run, only report them). *(M4–M6)* Append to the command log, clear affected cached pages.
7. Release the lock and return the responses.
