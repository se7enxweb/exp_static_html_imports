# Rules

The rules of a type are the group `[Type_<label>]` of `statichtmlimport.ini`. With `RulesFormat=yaml` and
`RulesFile=<file>`, or `RulesFormat=php` and `RulesClass=<class implementing expStaticImportRulesProvider>`, they come
from that file or class instead; what the file or class leaves out falls back to the group. The full list, with a line
on each rule, is the comment at the top of the extension's `settings/statichtmlimport.ini.append.php`.

- **Source and place:** `SourceRepository`, `SourceBranch`, `SourceLocation`, `ParentNode` or `ParentRemoteID`,
  `MediaParentNode` or `MediaParentRemoteID`, `ImportUser`, `Language`, `BatchSize`.
- **Pages:**
  - which files: `Suffixes[]`, `IncludePaths[]`, `ExcludePaths[]`, `HiddenPaths[]`, `IndexFiles[]`;
  - which class: `ClassRules[]` (`<shell pattern>;<class>`, the first match wins), `DefaultClass`;
  - which attribute takes which field: `AttributeMap[<class>/<attribute>]=<field>` (`*` for every class).
- **Fields:**
  - `title`, `short_title`, `body` (the XML of the cleaned body), `original_html`, `source_path`, `page_id`;
  - `description`, `keywords`, `metadata_xml`, `labels_tags`;
  - the byline fields: `written_by`, `written_at`, `last_updated_by`, `last_updated_at`, `maintainers`;
  - `import_hash`.
- **Cleaning the body:** `RemoveSelectors[]`, `UnwrapSelectors[]`, `PanelSelectors[<type>]`, `CodeSelectors[]`,
  `LiteralSelectors[]`, `CodeLineSelector`, `CodeLanguagePatterns[]`, `ExpandSelectors[]`, `ChildrenSelectors[]`,
  `EmbedAlignSelectors[<align>]`.
- **Byline:** `BylineSelectors[]`, `BylineFields[<field>]=<label>`, `BylineListFields[]`, `BylineDateFormats[]`,
  `BylineTimezone`, `ContributorClass`, `ContributorNameAttribute`, `ContributorParentRemoteID`.
- **Copies and redirects:** `DuplicatePatterns[]`, `DeduplicateAll`, `CreateRedirects`, `RedirectPrefix`,
  `NotFoundTitlePatterns[]`.
- **Media:** `AssetSuffixes[]`, `AssetClassRules[]`, `AssetAttributeMap[<class>/<attribute>]`.

## Where a page goes

A page's parent is the page of its folder: `<folder>.html` next to the folder, or the folder's index file. Some exports
are flat, though: Confluence saves every page of a space in one folder, `display/<SPACE>/<page>.html`, and only the
breadcrumb says where a page belongs.

- `BreadcrumbSelector` (e.g. `#breadcrumbs a`) reads the breadcrumb of the pages that match `BreadcrumbPaths[]`. The
  nearest link that is a page of the import is the parent. A breadcrumb that leads round in a circle is not followed.
- `GroupFolders[]` (shell patterns of folders, e.g. `display` and `display/*`) gives each matching folder that has no
  page of its own a section page, the parent of the folder's pages that have no other parent. Its title is
  `GroupTitles[<folder>]`, else the text of `GroupTitleSelector` (the breadcrumb's first item, the space's name) in the
  folder's first page, else the folder's name. `GroupClass` is its class, `GroupBody` an optional text (`{title}` is
  the title). Its path is the folder with a slash (`display/EZP/`), and the folder's old address redirects to it.
- Every page is imported after its parent. A run of a few paths (`--path`) still knows every page of the source, so
  a parent outside the run is still the parent.
- A page imported below the wrong node, by an earlier run or an earlier rule, is moved on the next run.

## What is no page

- `NotFoundTitlePatterns[]`: regular expressions on the `<title>` of the export's error pages (`/^404\b/`,
  `/^kernel \(\d+\) \/ Error\b/`).
- `SkipBinaryPages` (enabled by default): a file with a page suffix that holds no HTML, such as a font or a gzip file
  that the export saved as `.html`.
- `AssetPages` (disabled by default): a page whose body is one image, or one link to a media file, with no text of its
  own (at most `AssetPageMaxText` characters, default 0) is the viewer of that file. The file is imported as media, and
  the viewer's path redirects to it. `AssetPagePaths[]` names pages that are always such viewers.
- A page that an earlier run imported and that is no page by these rules is hidden on the next run (trashed with
  `--apply-removals`). `./console exp:static-import:hide-non-pages --type=<label>` hides them at once, without a run.
