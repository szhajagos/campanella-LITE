# Installation

This document describes how to put Campanella Lite on a web server, set it up and update it.

## Requirements

- PHP 8.3 or newer with the `mbstring` extension
- Apache with `mod_rewrite` (`mod_headers` is used when available)
- Write permission for the web server user in `config/`, `project/`, `var/` and `public/`

No database is needed. Composer is only needed when you install from the source code instead of the release package.

## 1. Get the files

**Release package (recommended).** Download `campanella-lite-<version>.zip` from the [releases page](https://github.com/szhajagos/campanella-LITE/releases). It contains everything, Composer dependencies included. The `.sha256` file next to it lets you check the download:

```
sha256sum -c campanella-lite-0.1.0.zip.sha256
```

Unpack it and upload the contents of the `campanella-lite-<version>/` folder to the server.

**From the source code.** Clone the repository and install the dependencies:

```
git clone https://github.com/szhajagos/campanella-LITE.git campanella-lite
cd campanella-lite
composer install --no-dev --optimize-autoloader
```

The repository also contains development files (`tests/`, `.github/`, `.git/`). They are harmless on a server where the document root is `public/`, but the release package leaves them out.

## 2. Point the web server at `public/`

The document root of the website must be the `public/` directory, so that `app/`, `config/`, `project/` and `var/` cannot be downloaded. On most hosting control panels this is the "document root" or "web root" setting of the domain; with your own Apache:

```apache
<VirtualHost *:443>
    ServerName example.com
    DocumentRoot /var/www/campanella-lite/public

    <Directory /var/www/campanella-lite/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` (or at least `FileInfo Options Indexes`) lets Apache read `public/.htaccess`.

**When the document root cannot be changed**, upload the installation into the existing web root as it is. The `.htaccess` file in the installation root then sends every request to `public/`, so nothing outside it can be downloaded. If `mod_rewrite` is missing, it refuses every request instead of exposing files. Installing into a subdirectory of a domain (`example.com/site/`) is not supported yet.

> These Apache rules are written for and reviewed against Apache's documentation, but have not yet been tested on every kind of hosting. Please report problems as GitHub issues.

## 3. Set it up

### In the browser

Open `https://example.com/admin`. Because there is no admin user yet, the setup form appears instead of the login form. It asks for:

| Field | What to enter |
| --- | --- |
| Setup key | The code in `config/setup-key.txt`. The file is created on the server when the setup form is first opened; read it with the file manager of your hosting, FTP or SSH. It proves that the person setting up the website has access to the server. |
| Name of the website | Shown in the header and the page titles. Can be changed later with `set site.name`. |
| Starter content | English or Hungarian sample pages: a home page, an about page and a contact page. More languages can be added later with `language add`. |
| First admin user | User name and password (at least 10 characters). |

After the setup you are logged in to the dashboard, and the setup key is deleted. The address the setup was opened at becomes the site address (`site.base_url`), used for canonical links. If the website was already set up on the command line, the form only creates the admin user.

Wrong setup keys are throttled like failed logins (5 attempts per 15 minutes per address) and logged in `var/log/auth.log`.

### On the command line

With SSH access, the same can be done with the CLI:

```
bin/campanella --init --starter=en --site-name="My website" --base-url=https://example.com
bin/campanella --add-user=admin
```

`--init` creates `config/`, `var/` and `project/`, copies the starter website into `project/` and names it. It never overwrites an existing project. `--add-user` asks for the password. Both can be given in one call.

## 4. Check the permissions

The web server user must be able to write:

| Directory | Why |
| --- | --- |
| `project/` | Every command that changes the website writes here. |
| `public/` | Generated pages are stored here as static HTML. |
| `var/` | Logs, the write lock, undo snapshots, Twig caches, login throttling. |
| `config/` | Admin users (`users.yaml`, mode 0600) and the setup key. |

The installer puts a `Require all denied` `.htaccess` file into `config/`, `project/` and `var/`, as a second line of defence.

## Updating

Back up `config/` and `project/` first (they are the whole website and its users). Then replace everything else with the files of the new version: `app/`, `bin/`, `public/index.php`, `public/admin/`, `public/assets/`, `public/.htaccess` and the root `.htaccess`. Keep `config/`, `project/`, `var/` and the generated pages in `public/`. Finally run `cache clear` in the dashboard, so every page is generated again with the new version. Read the [changelog](../CHANGELOG.md) for changes that need attention.

## Backups

`config/` and `project/` are all you need to restore a website; `var/` and the generated pages are recreated. `project/` is plain YAML and Markdown, so it can also be kept in Git.
