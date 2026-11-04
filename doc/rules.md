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
