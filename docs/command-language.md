# Command language

Everything that changes a Campanella Lite website goes through the command language: the admin dashboard, the developer CLI and the future AI module all submit the same commands to the same interpreter.

This document describes the syntax, how a batch is executed, the responses, and every command. `help` and `help <command>` show the same information in the admin and the CLI.

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

Messages come from the catalogues in `app/translations/messages.{locale}.yaml`; English (`en`) and Hungarian (`hu`) are included. English is the default, and any text missing from another catalogue is shown in English, key by key. To add a language, copy `messages.en.yaml` and translate the texts; the keys and the `{placeholders}` stay the same (a test checks this for every catalogue). The same catalogues hold the texts of the public theme (`theme.*`), shown in the visitor's language.

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

Structural changes (adding regions, placing content, creating pages) have their own commands, below. System fields such as page ids and `site.next_page_id` cannot be set.

### `list <type>`

Lists `pages`, `layouts`, `content`, `menus`, `languages` or `redirects` as a table. Pages are listed with their address in the default language.

### `help [<command>]`

Without an argument, lists every command with its usage. With a command name, shows its description, usage and examples.

### `check`

Validates the whole website and lists every problem with its path, including problems that existed before (for example from hand-edited files). It changes nothing.

## Common conventions

- **Pages** are referred to by id or alias: `2` or `/about`. Inside a batch, refer to a page you just created by the alias you gave it.
- **Columns** are written as `region.column`: `body.main`.
- **Menu items** are positions counted from 1; `2.1` is the first child of the second item.
- **`--lang=<code>`** chooses the language of a text; without it, the default language is meant. The language must be enabled.
- **`--at=<n>`** inserts at a position counted from 1; without it, items go to the end.
- **Deleting something that is still used** fails and lists what uses it. **`--force`** deletes it anyway and removes those references too (menu items, placements, the front page setting). Layouts in use cannot be deleted, because pages cannot be left without a layout.

## Pages

| Command | Description |
| --- | --- |
| `page create --title=<title> [--alias=<alias>] [--layout=<layout>] [--status=draft\|published] [--description=<text>] [--lang=<code>]` | Creates a page. Its id comes from `site.next_page_id`; without an alias it is at `/node/<id>`. New pages are drafts and use the default layout unless told otherwise. |
| `page delete <page> [--force]` | Deletes a page. Menu items pointing to it and the front page setting block this unless `--force`. |
| `page alias <page> <alias> [--lang=<code>]` | Sets the alias. `page alias <page> --remove` removes it. |
| `page publish <page>` / `page unpublish <page>` | Makes the page visible to visitors, or a draft again. |
| `page place <page> <column> <content> [<content> …] [--at=<n>]` | Places content into a column of the page. |
| `page unplace <page> <column> <content>` | Removes content from a column of the page. |
| `page reset <page> <column>` | Makes the column show the layout's default content again. |

**How placements work.** A column of a page shows the layout's default content until the page places or removes something there. From then on the page keeps its own list for that column. `page place` and `page unplace` start from what the page currently shows, so they add to or remove from the visible content; `page reset` returns to the layout's defaults.

```
page create --title="Contact" --alias=/contact --status=published
page place /contact body.main contact-text
page unplace /contact body.sidebar contact-box
menu item add main --label="Contact" --page=/contact
```

## Content

| Command | Description |
| --- | --- |
| `content create <name> --title=<title> [--lang=<code>] [<<< text >>>]` | Creates content in one language. |
| `content edit <name> [--lang=<code>] <<< text >>>` | Replaces the text of an existing language version. |
| `content translate <name> <language> [--title=<title>] [<<< text >>>]` | Adds a language version (title required), or updates the title and/or text of an existing one. |
| `content delete <name> [--force]` | Deletes the content with all its language versions. Placements in layouts and pages block this unless `--force`. |
| `content delete <name> --lang=<code>` | Deletes one language version. The last remaining version cannot be deleted this way. |

```
content create contact-text --title="Contact" <<<
Write to us: **info@example.com**
>>>
content translate contact-text hu --title="Kapcsolat" <<<
Írjon nekünk: **info@example.com**
>>>
```

## Layouts

| Command | Description |
| --- | --- |
| `layout create <name> [--from=<layout>] [--title=<title>] [--lang=<code>]` | Creates an empty layout, or a copy of another one. |
| `layout delete <name>` | Deletes a layout that no page and no site setting uses. |
| `layout region add <layout> <region> [--after=<region>\|--before=<region>] [--container=fixed\|fluid]` | Adds a region (a Bootstrap row), at the bottom or next to another region. |
| `layout region remove <layout> <region> [--force]` | Removes a region and its columns. Page placements into those columns block this unless `--force`. |
| `layout column add <layout> <region.column> --width=<1–12> [--after=<column>\|--before=<column>] [--menu=<menu>]` | Adds a column to a region. |
| `layout column remove <layout> <region.column> [--force]` | Removes a column. Page placements into it block this unless `--force`. |
| `layout place <layout> <column> <content> [<content> …] [--at=<n>]` | Adds default content to a column; every page that does not override the column shows it. |
| `layout unplace <layout> <column> <content>` | Removes default content from a column. |

Column widths are checked when the batch ends, so a batch can first narrow one column and then add another:

```
set layout.default.body.main.width 6
layout column add default body.aside --width=2 --before=main
```

## Menus

| Command | Description |
| --- | --- |
| `menu create <name>` | Creates an empty menu. Show it in a layout column with `set layout.<layout>.<region>.<column>.menu <name>` or `layout column add … --menu=<name>`. |
| `menu delete <name> [--force]` | Deletes a menu. Layout columns that show it block this unless `--force`. |
| `menu item add <menu> --label=<label> (--page=<page>\|--url=<url>) [--parent=<item>] [--at=<n>] [--lang=<code>]` | Adds an item that links to a page or a URL, at the top level or below another item. The response names the new item's position. |
| `menu item remove <menu> <item>` | Removes an item and its children. |

## Languages

| Command | Description |
| --- | --- |
| `language add <code> [--default]` | Enables a language (`en`, `hu`, `pt-BR`). |
| `language default <code>` | Makes an enabled language the default. |
| `language remove <code> [--force]` | Disables a language. Texts and content versions in it block this unless `--force`, which deletes them. The default language cannot be removed. |

Required texts (page titles, menu labels) must exist in the default language, so a new default language needs those texts first; the batch can add them before switching:

```
language add hu
set page.1.title.hu "Kezdőlap"
set menu.main.items.1.label.hu "Kezdőlap"
language default hu
```

## Publishing

| Command | Description |
| --- | --- |
| `build [<page>]` | Generates the published pages (or one page) in every language now, instead of on their first visit. |
| `cache clear` | Deletes the generated pages; they are generated again on their next visit. |
| `list redirects` | Lists the old addresses that redirect to pages. |
| `redirect remove <path>` | Removes a redirect. |

`build` and `cache clear` run after the batch has been written, so they see its changes; they do nothing on a dry run or in a rejected batch. Every batch that changes the website clears the generated pages by itself. Changing or removing an alias records a redirect from the old address automatically. See [generator.md](generator.md).

## Your settings

| Command | Description |
| --- | --- |
| `user set locale <locale>` | Sets the language of your own messages in the admin dashboard. Only works there, for the logged-in user. |

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
