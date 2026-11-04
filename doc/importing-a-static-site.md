# Importing a static site

1. **Choose the type** (`./console exp:static-import:types`):
   - `--html-generic` for a folder of HTML files;
   - `--markdown` for Markdown files;
   - `--sitemap-crawl` for a live site;
   - a type of your own.
2. **Set its rules** in `settings/override/statichtmlimport.ini.append.php`, in the group `[Type_<label>]`:
   - the source: `SourceLocation`, or `SourceRepository` for a git repository the import clones and pulls;
   - the place: `ParentNode` or `ParentRemoteID`;
   - the classes: `ClassRules[]`, `DefaultClass` and `AttributeMap[]`.

   See [Rules](rules.md).
3. **Look first:** `./console exp:static-import:run --<label> --dry-run`. It counts what would be created, updated,
   flagged and removed, and writes a report, without changing anything.
4. **Import:** `./console exp:static-import:run --<label>`.
   - A long import runs in batches and saves its position after each one; run the same command again to resume
     it.
   - `--path=<pattern>` and `--limit=<n>` import a part first.
5. **Check:** `./console exp:static-import:status`, or Setup > Static site import in the administration. There, a run
   also starts as a background job, and each run's report is one click away.
