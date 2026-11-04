# Exponential Static Site Import (exp_static_html_imports)

Imports a static site into the content tree of Exponential, page by page, into content classes, and keeps the
imported pages in step with their source on every later run.

- **Four source types**, chosen with an option of the command:
  - `--doc-html`: the static export of the Exponential documentation;
  - `--html-generic`: any folder of HTML files, read through selectors;
  - `--markdown`: a folder of Markdown files with front matter, read with the help center's rules;
  - `--sitemap-crawl`: a live site read through its sitemap, politely.
- **Your own types.** A type is a PHP class implementing `expStaticImportTypeInterface`, registered in
  `statichtmlimport.ini [ImportTypes]`. Its rules are INI by default, or YAML, or a PHP rule class.
- **Safe to run again.**
  - Every page keeps its identity (a remote id from its source path).
  - Changed pages are updated, and pages an editor changed in the CMS are flagged and left alone.
  - Pages that left the source are hidden, and only trashed on request.
  - Copies of a page become redirects to it.
- **Large sources.** The import runs in batches, saves its position after each one, and resumes an interrupted run.
- **Reports.** Each run writes a JSON report (schema `exp-static-import/1`) and a Markdown summary, plus audit trail
  events.

```text
./console exp:static-import:types
./console exp:static-import:run --markdown --source=/path/to/docs --parent-node=2 --dry-run
./console exp:static-import:run --markdown --source=/path/to/docs --parent-node=2
./console exp:static-import:status
```

Install: see [INSTALL.md](INSTALL.md). Documentation: [doc/index.md](doc/index.md), shown in the help center when the
extension is active.

License: GNU General Public License v2.0 (or any later version). Copyright (C) 1998 - 2026 Exponential Foundation
and 7x.
