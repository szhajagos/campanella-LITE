# Data model

The website is described by YAML and Markdown files in the `project/` directory. This document describes the entities, their files and fields, and the rules the validator enforces.

## Files

```
project/
├── site.yaml                     # Site
├── layouts/{name}.yaml           # Layout, with its regions and columns
├── pages/{id}.yaml               # Page
├── content/{name}/{language}.md  # Content, one file per language
└── menus/{name}.yaml             # Menu
```

The name inside a file must match its file name: `layouts/default.yaml` contains `name: default`, `pages/2.yaml` contains `id: 2`. Unknown keys are rejected, so typos in hand-edited files are caught.

## Identifiers

| Entity | Identifier | Assigned by |
| --- | --- | --- |
| Page | Positive whole number, never reused | The system (`site.next_page_id`) |
| Layout, region, column, content, menu | Machine name | The administrator |

A **machine name** consists of lowercase ASCII letters and digits, with words joined by single hyphens (`footer-text`, `col2`). It is at most 64 characters long.

## Language-dependent fields

Titles, descriptions, aliases, menu labels and content bodies can differ per language. Language-dependent fields are always stored as a mapping of language codes to values, even on a single-language website:

```yaml
title: { en: About us }
```

Content is stored as one Markdown file per language in the content's directory. Language codes follow ISO 639, optionally with a region (`en`, `hu`, `pt-BR`). Every language used must be listed in `site.languages`.

## Entities

### Site (`site.yaml`)

| Field | Type | Required | Default | Meaning |
| --- | --- | --- | --- | --- |
| `name` | text | yes | | Website name |
| `base_url` | text | no | `''` | Public address, e.g. `https://example.com` |
| `languages` | list of language codes | yes | | Enabled languages |
| `default_language` | language code | yes | | Language of unprefixed URLs and fallback texts |
| `default_layout` | layout name | yes | | Layout for new pages |
| `theme` | text | no | `default` | Theme directory |
| `next_page_id` | whole number | no | `1` | Id the next new page receives |
| `front_page` | page id | no | | Page served at `/` |

### Layout (`layouts/{name}.yaml`)

```yaml
name: default
title: { en: Default layout }
regions:
  - name: header
    columns:
      - { name: brand, width: 12, content: [site-title] }
  - name: nav
    container: fluid
    columns:
      - { name: menu, width: 12, menu: main }
  - name: body
    columns:
      - { name: main, width: 8 }
      - { name: sidebar, width: 4, content: [contact-box] }
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | machine name | |
| `title` | localized text, optional | Shown in the admin |
| `regions` | list | Horizontal bands, top to bottom; each a Bootstrap `row` |
| `regions[].name` | machine name | Unique within the layout; not `name` or `title` |
| `regions[].container` | `fixed` or `fluid`, optional | Bootstrap container type; default `fixed` |
| `regions[].columns` | list | Each a Bootstrap `col-md-{width}` |
| `columns[].name` | machine name | Unique within the region; not `container` |
| `columns[].width` | 1–12 | Grid columns; a region's widths add up to at most 12 |
| `columns[].content` | list of content names, optional | Default content, shown on every page that does not override the column |
| `columns[].menu` | menu name, optional | A menu to show in the column; excludes `content` |

A column is addressed as `region.column`, for example `body.main`.

### Page (`pages/{id}.yaml`)

```yaml
id: 2
alias: { en: /about }
title: { en: About us }
description: { en: Meet our team. }
layout: default
status: published
placements:
  body.main: [intro, team]
```

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `id` | whole number | yes | Assigned by the system |
| `alias` | localized text | no | Readable address, e.g. `/about` |
| `title` | localized text | yes | Must have a value in the default language |
| `description` | localized text | no | Meta description |
| `layout` | layout name | yes | |
| `status` | `draft` or `published` | no | Default `draft`; drafts are never generated for visitors |
| `placements` | mapping of `region.column` to content names | no | Overrides the layout's default content for those columns |

A page without an alias is available at `/node/{id}`. With an alias, `/node/{id}` and earlier aliases redirect to it (milestone 4).

### Content (`content/{name}/{language}.md`)

```markdown
---
title: Introduction
format: markdown
---
Our small team has been building **custom** websites since 2015.
```

| Field | Type | Meaning |
| --- | --- | --- |
| `title` | text | Shown in the admin; required by the validator |
| `format` | `markdown` | Body format; HTML is planned for a later version |
| *body* | text | Everything after the closing `---` line |

### Menu (`menus/{name}.yaml`)

```yaml
name: main
items:
  - { label: { en: Home }, page: 1 }
  - { label: { en: About }, page: 2 }
  - label: { en: More }
    url: https://blog.example.com
    children:
      - { label: { en: Contact }, page: 3 }
```

Each item has a `label` (localized, required in the default language) and exactly one target: `page` (a page id) or `url` (an `http(s)://` URL, a `mailto:` link or a site-relative path starting with `/`). Items can have `children`.

## Validation

`ProjectValidator` checks the whole project and returns every violation it finds. A violation has a stable **code**, a **path** in the dotted notation of the command language, and **parameters** for the message:

```
page.unknown_layout at page.2.layout (layout=wide)
```

The text of each code comes from the message catalogue under the key `violation.{code}`.

### Aliases

An alias:

- starts with `/` and consists of one or more segments of lowercase ASCII letters and digits, words joined by single hyphens (`/about`, `/services/web-design`);
- has no trailing slash, no accents, no uppercase letters;
- does not start with a reserved segment: `admin`, `assets`, `node`, or any enabled language code (`/en/...`), which are kept free for language prefixes;
- is unique per language.

### Violation codes

| Code | Meaning | Parameters |
| --- | --- | --- |
| `name.invalid` | Not a valid machine name | `name`, `max` |
| `name.reserved` | A region named `name` or `title`, or a column named `container` | `name` |
| `site.no_languages` | No language is enabled | |
| `site.invalid_language` | Not a valid language code | `language` |
| `site.duplicate_language` | A language is listed twice | `language` |
| `site.unknown_default_language` | The default language is not enabled | `language` |
| `site.unknown_default_layout` | The default layout does not exist | `layout` |
| `site.unknown_front_page` | The front page does not exist | `page` |
| `site.next_page_id_in_use` | `next_page_id` is not above the highest page id | `next`, `highest` |
| `layout.duplicate_region` | Two regions share a name | `region` |
| `layout.duplicate_column` | Two columns of a region share a name | `column` |
| `layout.invalid_width` | Width outside 1–12 | `width`, `max` |
| `layout.region_too_wide` | The column widths of a region exceed 12 | `width`, `max` |
| `layout.content_and_menu` | A column has both content and a menu | |
| `layout.unknown_content` | A column refers to missing content | `content` |
| `layout.unknown_menu` | A column refers to a missing menu | `menu` |
| `page.invalid_id` | The page id is not positive | `id` |
| `page.unknown_layout` | The page's layout does not exist | `layout` |
| `page.invalid_alias` | The alias breaks the alias rules | `alias` |
| `page.reserved_alias` | The alias starts with a reserved segment | `alias`, `segment` |
| `page.duplicate_alias` | Another page already has the alias | `alias`, `page` |
| `page.unknown_column` | A placement targets a column the layout lacks | `column`, `layout` |
| `page.unknown_content` | A placement refers to missing content | `content` |
| `content.no_translations` | Content has no language file | |
| `content.missing_title` | A translation has no title | |
| `menu.invalid_target` | An item has neither or both of `page` and `url` | |
| `menu.unknown_page` | An item points to a missing page | `page` |
| `menu.invalid_url` | An item's URL is not allowed | `url` |
| `text.unknown_language` | A text uses a language that is not enabled | `language` |
| `text.missing_default_language` | A required text has no value in the default language | `language` |
