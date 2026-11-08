# Generator and public website

Campanella Lite serves its public website as static HTML. A page is rendered the first time someone asks for it, stored in the web root, and from then on served by the web server without PHP. Any change to the website clears the stored pages, and they are rendered again on their next visit.

## Request flow

1. A visitor asks for `/about`.
2. The web server looks for `public/about/index.html`. If it exists, it is sent as it is.
3. If not, the request reaches `public/index.php`, the **front controller**.
4. The **router** decides what the address shows (below).
5. A published page is rendered with the theme, stored as `public/about/index.html`, recorded in the cache manifest, and sent.
6. Redirects (301) and "not found" pages (404) are answered by PHP every time and never stored.

Only `GET` and `HEAD` are answered; other methods get `405`. If the project files cannot be read, visitors get a plain `503` without details.

## Addresses

Every page has one canonical address per language. The default language has no prefix; every other language is prefixed with its code.

| Address | Shows |
| --- | --- |
| `/` | The front page (`site.front_page`) in the default language |
| `/about` | The page whose alias in the default language is `/about` |
| `/node/7` | Page 7, if it has no alias in the default language |
| `/hu/` | The front page in Hungarian |
| `/hu/rolunk` | The page whose Hungarian alias is `/rolunk` |
| `/hu/node/7` | Page 7 in Hungarian, if it has no Hungarian alias |

Other ways to reach a page answer with a **301 redirect** to its canonical address:

- `/node/7` of a page that has an alias;
- the alias or `/node/<id>` of the front page (→ `/`);
- the default language with an explicit prefix (`/en/about` → `/about`);
- old aliases recorded in `project/redirects.yaml`.

Drafts are never shown to visitors; their addresses answer with 404. Menu items that point to drafts are left out.

A trailing slash is ignored (`/about/` shows `/about`). Paths with `..`, `//`, backslashes or control characters are refused with 404.

## Redirects

When a page loses an alias (it is changed or removed), the old address is recorded in `project/redirects.yaml`:

```yaml
/about-us: { page: 2, language: en }
/hu/regi-cim: { page: 2, language: hu }
```

A redirect points to the **page**, not to another address, so it always leads to the page's current address in that language and never forms a chain. Redirects are maintained at the end of every batch:

- a changed or removed alias becomes a redirect;
- a redirect is removed when another page takes its address, when the page gets the address back, when the page is deleted, or when the language is removed.

`list redirects` shows them; `redirect remove <path>` removes one. Inside a batch, `list redirects` shows the state before the batch, because redirects are updated when the batch ends.

## Page cache

Generated pages are listed in `var/cache-manifest.json`. Clearing the cache deletes exactly those files and any directories left empty, so nothing else in the web root is touched. The generator also refuses to overwrite a file it did not create.

- Every batch that changes the website clears all generated pages (MVP rule; a change to a menu, a layout or the site name can affect every page). Smarter invalidation is on the roadmap.
- `build` generates the published pages in every language right away, for example after a deployment; `build <page>` generates one page. It runs after the batch is written, so `set …` followed by `build` generates the new state.
- `cache clear` deletes the generated pages.

Neither runs on a dry run or in a rejected batch.

## Rendering

`Campanella\Lite\Render\PageRenderer` renders a page with Twig. Templates receive only plain arrays (the *view*), never model objects:

| Variable | Contents |
| --- | --- |
| `site` | `name`, `language` (of this page), `languages`, `theme`, `home` (front page address in this language) |
| `page` | `id`, `title`, `description`, `path`, `canonical` (absolute, if `site.base_url` is set), `is_front` |
| `regions` | Regions of the layout: `name`, `container` (`container` or `container-fluid`), `columns` |
| `regions[].columns[]` | `name`, `width`, `blocks` (`name`, `html`), `menu` (`name`, `items`) or null |
| `menu.items[]` | `label`, `url`, `current` (this page), `active` (this page or a child), `children` |
| `alternates` | One entry per language: `language`, `url`, `absolute_url`, `current` |
| `assets` | Base path of the assets, `/assets` |

The function `t(key, language)` returns a theme text from the message catalogue (`theme.*` keys), in the visitor's language with English fallback.

Texts missing in a language fall back to the default language: page titles, menu labels, and content that has no version in that language.

### Content

Content is Markdown, converted with [league/commonmark](https://commonmark.thephpleague.com/). Raw HTML in content is escaped and unsafe links (`javascript:` and similar) are removed. Twig escapes every other value automatically. HTML content is planned for a later version.

## Themes

The built-in theme lives in two places:

```
app/themes/default/templates/     Twig templates
    base.html.twig                  HTML skeleton, assets
    page.html.twig                  regions, columns, blocks
    404.html.twig                   not found page
    partials/menu.html.twig         Bootstrap navbar with dropdowns
    partials/languages.html.twig    language switcher
public/assets/themes/default/     theme CSS
public/assets/vendor/bootstrap/   Bootstrap 5.3.8 CSS and JS bundle (MIT)
```

`site.theme` selects a theme by name; an unknown theme falls back to `default`. Templates are not editable through commands, so a theme can never become a way to run code.

## Web server

### Apache

`public/.htaccess` (needs `mod_rewrite`):

- serves existing files (assets, generated pages) directly;
- serves `/about` from `about/index.html` without redirecting to `/about/` (`DirectorySlash Off`);
- sends everything else to `index.php`;
- refuses hidden files and turns off directory listings.

The web server's document root should be the `public/` directory. Where that is not possible, the `.htaccess` file in the installation root passes every request on to `public/`. See [installation.md](installation.md#2-point-the-web-server-at-public).

### Development server

PHP's built-in server with the development router mirrors the Apache rules:

```
php -S localhost:8000 -t public app/dev-router.php
```
