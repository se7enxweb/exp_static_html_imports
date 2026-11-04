# Writing an import type

1. **Write a class** implementing `expStaticImportTypeInterface`, usually by extending `expStaticImportHtmlType`. A
   type answers five questions:
   - `discover()`: which paths are pages, parents first;
   - `parse()`: the page array (title, body_html, original_html, meta, hidden ...), or `null` for no page;
   - `map()`: class, parent_path and attributes;
   - `assets()`: the media it uses;
   - `links()`: its links to other pages.
2. **Register it** in an extension's `statichtmlimport.ini.append.php`:

   ```ini
   [ImportTypes]
   TypeList[my-site]=myImportType
   ```

3. **Give it a group** `[Type_my-site]` with its rules, then run `./console exp:static-import:run --my-site --dry-run`.

The engine does the rest: batches, the state file, remote ids, updates, edit detection, removals, redirects, media,
links, contributors and reports. The tests in `tests/` show a type at work on a fixture site, without a database.
