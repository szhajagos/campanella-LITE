# Command language

Everything that changes a Campanella Lite website goes through the command language: the admin dashboard, the developer CLI and the future AI module all submit the same commands to the same interpreter.

This document describes the syntax, how a batch is executed, the responses, and the commands available so far. Commands for creating and deleting pages, content, layouts and menus follow in milestone 3.

## Batches

Everything typed into the command box at once is a **batch**. A batch is executed **all or nothing**:

1. The whole batch is parsed. A syntax error anywhere rejects it before anything runs.
2. The commands run one after another on an in-memory copy of the project. Each command sees the changes of the earlier ones. The first failing command rejects the batch; the remaining lines are skipped.
3. The resulting project is validated. If the batch would introduce a rule violation (a missing layout, an alias collision, a region wider than 12 columns), it is rejected. Problems that existed before the batch do not block it.
4. Only then are the changed files written, while an exclusive lock keeps other batches out.

A batch that only reads (`get`, `list`, `help`) writes nothing.

## Syntax

```
# Comments start with "#" and run to the end of the line
@dry-run                                   # optional, before the first command
set site.name "Example Ltd."               # words, quoted text
page create --title="About us" --force     # options with and without a value
content edit intro <<<                     # multi-line text
The text, **Markdown** included.
>>>
```

| Element | Rules |
| --- | --- |
| Line | One command per line. Empty lines and comment lines are ignored. |
| Words | Separated by spaces or tabs. The leading words name the command, the rest are its arguments. |
| Quotes | `"..."` groups words into one argument. Escapes inside quotes: `\"`, `\\`, `\n`, `\t`; any other backslash stays as it is. `""` is an empty argument. |
| Options | A word starting with `--` (outside quotes) is an option: `--name=value` or `--flag`. Names use lowercase letters, digits and hyphens. A value may be quoted: `--title="About us"`. |
| Comments | `#` at the start of a word, outside quotes, starts a comment. `a#b` is one word. |
| Text | `<<<` as the last word of a line starts a text; it runs until a line containing only `>>>`. The text keeps its line breaks and indentation. |
| Directives | `@dry-run` shows what the batch would change without writing anything. Directives must come before the first command. |

Keywords are English and lowercase. Line breaks may be `\n` or `\r\n`.

### Grammar

```
batch     = { line } ;
line      = [ directive | command ] [ comment ] NEWLINE ;
directive = "@" name ;
command   = word { word | option } [ "<<<" NEWLINE text ">>>" ] ;
option    = "--" name [ "=" value ] ;
word      = { char | quoted } ;
quoted    = '"' { char | escape } '"' ;
escape    = '\"' | '\\' | '\n' | '\t' ;
comment   = "#" { any } ;
name      = lower { lower | digit | "-" } ;
```

## Paths

`get` and `set` address values by a dotted path:

| Path | Value |
| --- | --- |
| `site.name` | A site setting |
| `page.2`, `page./about` | A page, by id or by alias |
| `page.2.title`, `page.2.title.hu` | A language-dependent field; without a language code the default language is meant |
| `layout.default` | A layout, regions and columns by name |
| `layout.default.title` | The layout's title |
| `layout.default.body.container` | A region's container type |
| `layout.default.body.main.width` | A column's width, content or menu |
| `content.intro.en.title` | The title of one language version of a content item |
| `menu.main.items.2.label` | A menu item, counted from 1; nested items: `items.2.children.1.url` |

Region names `name` and `title`, and the column name `container`, are reserved so that these paths stay unambiguous.

## Responses

Each line gets a response, and the batch a summary:

```
[1] Ok. site.name = Kovács Kft.
[2] Ok. layout.default.body.main.width = 9
The changes would break these rules:
    - The columns of this region are 13 wide in total; at most 12 fit. (layout.default.body)
→ Batch not executed, nothing was changed.
```

The number in brackets is the line number in the batch. A line is `Ok.`, `Error:` or skipped (an earlier line failed).

| Outcome | Meaning | CLI exit code |
| --- | --- | --- |
| `applied` | All commands succeeded; changed files were written | 0 |
| `unchanged` | All commands succeeded; nothing needed writing | 0 |
| `dry_run` | All commands succeeded; nothing was written on purpose | 0 |
| `rejected` | A syntax error or a failing command | 1 |
| `invalid` | The result would break validation rules | 1 |
| `failed` | The project files could not be read | 2 |

### JSON

The same result is available as JSON (`bin/campanella --json`). Every message carries its stable `key` and `params` next to the translated `text`, so programs never have to parse prose:

```json
{
    "ok": false,
    "outcome": "rejected",
    "dry_run": false,
    "summary": { "key": "batch.rejected", "params": {}, "text": "Batch not executed, nothing was changed." },
    "lines": [
        {
            "line": 1,
            "source": "get page.9",
            "command": null,
            "status": "error",
            "message": { "key": "page.not_found", "params": { "page": "9" }, "text": "No page with the id or alias \"9\"." },
            "output": null
        }
    ],
    "violations": [],
    "changed_files": []
}
```

`output` holds the data of `get` (`"type": "value"`), `list` (`"type": "table"`, with `columns` and `rows`) and `help` (`"type": "lines"`).

### Languages

Messages come from the catalogues in `app/translations/messages.{locale}.yaml`. English is the default and the fallback for missing translations. To add a language, copy `messages.en.yaml` and translate the texts; the keys stay the same.

## Commands

### `get <path>`

Shows a value, or a whole entity as YAML.

```
get site.name
get page./about
get layout.default.body
```

### `set <path> <value>`

Changes a value. Text with spaces must be quoted. An empty value (`""`) removes an optional value, such as an alias, a description or a translation.

| Settable paths | Value |
| --- | --- |
| `site.name`, `site.base_url`, `site.theme`, `site.default_language`, `site.default_layout` | Text |
| `site.front_page` | Page id, or `""` |
| `page.<page>.title[.<lang>]`, `.description[.<lang>]`, `.alias[.<lang>]` | Text, or `""` |
| `page.<page>.layout` | Layout name |
| `page.<page>.status` | `draft` or `published` |
| `layout.<layout>.title[.<lang>]` | Text, or `""` |
| `layout.<layout>.<region>.container` | `fixed` or `fluid` |
| `layout.<layout>.<region>.<column>.width` | 1–12 |
| `layout.<layout>.<region>.<column>.menu` | Menu name, or `""` |
| `content.<content>.<lang>.title` | Text |
| `menu.<menu>.items.<n>[.children.<n>…].label[.<lang>]` | Text, or `""` |
| `menu.<menu>.items.<n>….url` | URL, or `""` |
| `menu.<menu>.items.<n>….page` | Page id, or `""` |

Structural changes (adding regions, placing content, creating pages) have their own commands (milestone 3). System fields such as page ids and `site.next_page_id` cannot be set.

### `list <type>`

Lists `pages`, `layouts`, `content`, `menus` or `languages` as a table.

### `help [<command>]`

Without an argument, lists every command with its usage. With a command name, shows its description, usage and examples.

## Message keys

Responses use these key families; the full list with texts is in `app/translations/messages.en.yaml`.

| Prefix | Used for |
| --- | --- |
| `syntax.*` | Syntax errors |
| `command.*` | Unknown commands, wrong arguments or options |
| `path.*`, `value.*` | Invalid paths and values for `get` and `set` |
| `page.*`, `layout.*`, `content.*`, `menu.*`, `list.*` | Entities that were not found |
| `ok.*` | Successful commands |
| `batch.*` | Batch summaries |
| `violation.*` | Validation rules; see [data-model.md](data-model.md#violation-codes) |
| `help.*` | Command descriptions |

## Developer CLI

`bin/campanella` runs batches from the command line through the same interpreter:

```
bin/campanella -e "get site.name"
bin/campanella batch.txt
bin/campanella --dry-run --json < batch.txt
bin/campanella --root=/path/to/installation --locale=en -e "list pages"
```
