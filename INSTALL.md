# Installing Exponential Static Site Import

**Requirements.**
- Exponential 6.0.15 or later. Earlier 6.0 versions work without the audit events and the console aliases.
- PHP 7.4 or later; PHP 8.0 and later are supported.
- Any of the five databases.
- The online editor (ezoe), active as it is by default. Its HTML-to-XML parser turns the pages into rich text.
- `git` on the server, for sources given as a repository.

## Install

1. Get the extension:
   - with Composer: `composer require se7enxweb/exp_static_html_imports`;
   - or by hand: put this folder at `extension/exp_static_html_imports`.
2. Activate it in `settings/override/site.ini.append.php`:

   ```ini
   [ExtensionSettings]
   ActiveExtensions[]=exp_static_html_imports
   ```

3. Regenerate the autoloads and deploy: `./console exp:velocity deploy --allow-root-user`, or
   `php bin/php/ezpgenerateautoloads.php -e` followed by a cache clear.
4. Check: `./console exp:static-import:types` lists the four types.

## Configure a type

Copy the group of the type you need from `settings/statichtmlimport.ini.append.php` into
`settings/override/statichtmlimport.ini.append.php` and set at least:
- the source: `SourceRepository` or `SourceLocation`;
- the place: `ParentNode` or `ParentRemoteID`;
- the classes: `ClassRules[]`, `DefaultClass` and `AttributeMap[]`.

Then run it once with `--dry-run`. The documentation in [doc/index.md](doc/index.md) describes every rule.
