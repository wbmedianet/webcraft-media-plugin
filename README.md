# Webcraft Media

WordPress plugin for the sites built and maintained by [Webcraft Media](https://webcraftmedia.net).

It delivers updates for custom themes and plugins kept in GitHub repositories: new versions
appear in **Dashboard → Updates** like any other update. Private repositories need an
**update key** saved in **Settings → Webcraft Media**, so updates can be switched on and off
per site.

## How it works

1. A theme or plugin opts in with one header line naming its repository:

   ```
   Update URI: https://github.com/wbmedianet/moroccan-spirit
   ```

2. A new version is published as a GitHub release with the installable zip
   (see [Publishing a version](#publishing-a-version)).
3. About twice a day, and whenever someone opens **Dashboard → Updates**, the site asks GitHub
   for the latest release. When it is newer than the installed version, WordPress offers it.
4. **Update now** downloads the zip through the GitHub API, with the site's update key, and
   installs it. Content and Site Editor changes are kept in the database and are not touched.

Without a valid key a private product simply gets no updates; the site keeps working. While
that is the case, administrators see a notice on the Dashboard, Updates, Themes and Plugins
screens (it can be switched off on the settings page). This plugin's own repository is
public, so the plugin updates itself without a key.

## Making a theme or plugin updatable

1. Add the `Update URI` header (`style.css` for a theme, the main plugin file for a plugin).
2. Copy [`.github/workflows/release.yml`](.github/workflows/release.yml) into the repository
   and set its three settings:

   | Setting     | Theme example                               | Plugin example      |
   | ----------- | ------------------------------------------- | ------------------- |
   | `SLUG`      | `moroccan-spirit`                           | `webcraft-media`    |
   | `SOURCE`    | `app/public/wp-content/themes/moroccan-spirit` | `''` (repo root) |
   | `MAIN_FILE` | `style.css`                                 | `webcraft-media.php` |

   `SLUG` must be the installed folder name: the zip holds that folder.

### Several plugins in one repository

Plugins can also share a repository, one folder each (as in the private
[wbmedianet/plugins](https://github.com/wbmedianet/plugins)). The `Update URI` then names the
folder after the repository:

```
Update URI: https://github.com/wbmedianet/plugins/tree/main/wm-contact-form
```

Each plugin's releases are tagged `{folder}-v{version}` (e.g. `wm-contact-form-v1.2.0`) and
carry `{folder}.zip`; the site takes the highest version among that plugin's releases. The
shared repository has its own release workflow, which reads the folder and the version from
the tag.

## Publishing a version

1. Raise `Version:` in `style.css` or the main plugin file, commit and push.
2. Tag the commit with the same version; the tag message becomes the release notes shown to
   the site owners under **View version details**:

   ```bash
   git tag -a v1.1.0 -m "New gallery layout; faster menu page."
   git push origin v1.1.0
   ```

3. The **Release** workflow checks that the tag matches the version, builds `{SLUG}.zip` and
   publishes the release. Sites see it at their next check (Settings → Webcraft Media →
   **Check now** checks at once).

## Update keys

An update key is a GitHub fine-grained personal access token that can only read the client's
repository.

**Create a key for a client**

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained tokens →
   Generate new token**.
2. **Token name**: the client, e.g. `moroccan-spirit updates`. **Expiration**: the end of the
   maintenance period (or the longest GitHub allows).
3. **Resource owner**: `wbmedianet`. **Repository access**: *Only select repositories* → the
   client's repository, plus `wbmedianet/plugins` when the site uses plugins from there.
4. **Permissions → Repository permissions → Contents: Read-only** (Metadata: Read-only is
   added automatically). Nothing else.
5. **Generate token**, copy it and send it to the client, or paste it yourself in
   **Settings → Webcraft Media → Update key** and save. The page confirms at once whether the
   key works.

**Pause updates**: delete the token on GitHub. The site stops seeing updates at its next
check and shows the "updates are not active" notice.

**Resume updates**: create a new token the same way (a deleted token cannot be restored) and
paste it in the settings page. The latest version, with everything released in between, is
offered right away.

The key can also be set in `wp-config.php` instead of the settings page:

```php
define( 'WEBCRAFT_MEDIA_TOKEN', 'github_pat_…' );
```

## Development copies

Updates are never offered for a theme or plugin inside a git working copy (a `.git` folder in
it or above it, up to the folder holding WordPress): installing a release there would overwrite
work in progress. A folder linked into the site from a working copy elsewhere (a symlink or a
Windows junction) counts too. The settings page lists such products as development copies.

## Filters

- `webcraft_media_github_owners`: GitHub accounts whose themes and plugins are updated
  (default `wbmedianet`).
- `webcraft_media_skip_git_copies`: return `false` to offer updates to git working copies too.

## Requirements

WordPress 6.5 or later, PHP 7.4 or later.
