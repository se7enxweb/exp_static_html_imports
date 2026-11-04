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

## Bylines: who wrote a page, and every person it names

- Labelled lines (`BylineSelectors[]`, `BylineFields[<field>]=<label>`, `BylineListFields[]`): "Written by: A
  (14/09/2010 10:14 am)", "Last updated by: B (...)", "Maintainers: C, D".
- Sentences (`BylineSentenceSelectors[]`, anywhere in the page, and `BylineSentences[]`): regular expressions with the
  named groups `written`, `written_at`, `updated` and `updated_at`, tried in order, such as Confluence's "Created by A,
  last modified by B on Oct 28, 2016". A sentence with the date of the last change and no name of its own credits the
  change to the writer. A date link whose `title` is a fuller date counts with that one.
- Comment authors (`BylineCommentAuthorSelectors[]`): the list field `commenters`.
- The field `contributors` holds every person of every byline field, each once (names compared without case and extra
  spaces): map it to an object relation list, e.g. `AttributeMap[*/contributors]=contributors`.
- Byline text that no rule reads is reported per page (`byline_failures`), never dropped.
- `./console exp:static-import:bylines --type=<label>` is the no-data-loss audit: every person and date of the source's
  bylines is looked for in the stored attributes, per page and in total, with the unknown byline texts. `--fill` writes
  what is missing into the published versions (a role attribute only when it is empty, the contributors list to the
  full set) with their object relations, without a run and without touching the state file.

## Routes: a family of pages in a container of its own

Some exports hold hundreds of views of their pages with one name each, such as Confluence's "Page History",
"Page Comparison", "Page Information" and old versions. In the tree they would be hundreds of same-name siblings.
`RouteRules[]` sends such a family into containers, with names of their own:

    RouteMeta[page_id]=/name="ajs-page-id" content="(\d+)"/
    RouteRules[]=pages/viewpreviousversions*;historical/page-history/{page_id}/;{page_title}: page history;;{page_id}
    RouteContainers[historical]=exp_doc_historical;Historical
    RouteContainers[historical/page-history/*]=exp_doc_historical;{page_title}

- A rule is `<shell pattern of the path>;<container path>;<name pattern>[;<condition>[;<current page id>]]`; the first
  that matches wins.
- The patterns take `{key}` values: `RouteMeta[<key>]` (a regular expression on the page's HTML, its first group),
  `{title}` (the `<title>` without `TitleSuffixPatterns[]`) and `{path}`; `{a|b}` is the first that is not empty.
- The condition is `<key>!=<key>` or `<key>=<key>`, e.g. `latest_id!=page_id` for an old version.
- The current page id fills the field `history_of`: the object of the current page with that page id (map it to an
  object relation, `AttributeMap[*/history_of]=history_of`). A container of one page's views gets it as well.
- `RouteContainers[<container path>]=<class>;<title pattern>` makes the containers, nested by their paths; `*` stands
  for one folder of any name. A container's title takes the values of its first page.
- `./console exp:static-import:route --type=<label>` puts pages an earlier run imported where the rules send them today
  (containers made, pages moved, renamed and related), without a run; what the next run must know goes into a state
  patch (`state-patches/` in the type's work folder), merged by that run.

## Sibling names

No two pages of one name below one parent: `SiblingNames=unique` (the default) gives the later one
`SiblingNameSuffix` (default `({n})`, a counter from 2; it may use `{page_id}` and the `RouteMeta[]` values, and gets
the counter as well when it is taken too), `refuse` skips the page (failed, "needs review"), `warn` only reports it,
`off` turns the check off. Each renaming is listed in the report's warnings, and counted as `renamed`.
`SiblingNameKeepsTitle[]` names the classes whose title is content as written (a comment's subject): for them the
suffix goes only into the object's name, the title attribute keeps the title, and the kernel makes the address
unique. An object a class change replaced (remote id `<id>-was-<class>`) takes no name any more.

## The top of the tree, products and fixed parents

- `TopParentRules[]=<shell pattern>;<parent path>`: where a page goes that would be a top page. The first match wins;
  an empty parent keeps it at the top. A last line `**;<page>` keeps the top small: only the pages named before it stay.
- `ParentRules[]=<shell pattern>;<parent path>`: a fixed parent before every other rule (a space below its product).
  The parent may take the values of the page's path: `{file}` (the last part without its page suffix), `{dir}`,
  `{seg1}`, `{seg2}` ... and `{folder}` (the version folder a renamed version page stands for: `4.7` for
  `Technical-manual/4.html`). A parent ending in `/` that is no page of the source is made as a container by
  `RouteContainers[]`; `@<remote id>` is a node outside the import (the media folder). A rule whose placeholders have
  no value is passed over. For example, every version page in a container of its version:

      ParentRules[]=Exponential/*/[0-9]*.html;Exponential/{folder}/
      RouteContainers[Exponential/*]=exp_doc_version;{folder};{folder};version={folder}
      ParentRules[]=Media/**;@exp-doc-media

- `RouteContainers[<container path>]=<class>;<title pattern>[;<short title pattern>[;<key>=<pattern>,...]]`: the short
  title is the container's address; the `key=pattern` pairs are values of the container page (`version={folder}`
  fills the field `version` that `AttributeMap[]` can use).
- `NameRules[]=<shell pattern>;<title pattern>[;<short title pattern>]`: the title (and the address) a page gets
  instead of its own, first match wins, with the values of its path, `{title}` (its own title) and its meta values
  (`{page_id}`). A routed page keeps its route's name. For example `Exponential/Technical-manual/[0-9]*.html;Technical
  manual {folder};Technical manual` names a version page "Technical manual 4.7" at `/exponential/4.7/technical-manual`.
- `GroupShortTitles[<shell pattern of a folder>]=<pattern>`: the short title (the address) of a group page, such as
  `display/*={file}` for a Confluence space addressed by its key.
- `VirtualPages[<path>/]=<class>;<title>;<short title>;<HTML file>`: a section page the source does not have, such as a
  product's landing page; the short title is its address, the file (below the installation's root) its text. It also
  gives new values to a group page.
- `RetiredParent`: where `route --all` puts the hidden leftovers of earlier runs at the top.
- `RetiredRules[]=<shell pattern of the source path>;<container path>[;<name pattern>[;<regular expression on the
  name>]]`: where `route --all` puts a leftover at the top, or an error page an earlier run imported as a page (a rule
  with a name check: `/^Page Not Found\b/`), hidden, with a name of the pattern (`{title}: {file}`) unique there; the
  others go into `RetiredParent`.

## Titles, headings and links

- `TitleSelectorScope=document`: each alternative of `TitleSelector` in its order, in the whole page, so a heading
  outside the body (Confluence's `#title-text`) wins over the first heading of the text.
- `NormalizeHeadings` (disabled by default): the highest heading of the text becomes the first level.
- `CopyOfPatterns[]`: regular expressions on the path whose first group is the address of the page it copies (a view
  parameter such as `.../(language)/eng-GB.html`): always a redirect to that page.
- Links: every link to an imported page or media file is written as a node link, `eznode://<node id>` with its
  anchor, which the output turns into the node's current address on every render: it follows moves and renames, and
  no address is stored. The resolver (`expStaticImportLinkResolver`) reads relative and root-relative paths (`..`
  above the top stays at the top, as in a browser), URL-encoded names (`%2b`, `%20`) in any case, an address without
  a suffix (its page or index file), a link with `pageId=` (the page with that page id), a full URL of a host of
  `LinkHosts[]` (its path, or its original address), `LinkHostsIfImported[]` (hosts only partly in the import:
  linked when imported, else left as they are), `SourceAddressPattern` (a mirror's header naming each file's
  original address, so `doc.ez.no/.../4.7` finds the file HTTrack wrote as `4.html`) and `LinkForwardParameters[]`
  (a login link with `os_destination=` goes to that page). An image whose file is imported is embedded by its
  object; a link to a file is a node link to the file's node. A link it cannot resolve keeps its address and is
  reported with its form and the reason; `mailto:`, anchors, external links and template placeholders stay as they are.

## Comments

`CommentsSelector` (the old manual pages' `#comments-content`) and `ConfluenceCommentsSelector` (Confluence's
`#page-comments`) read the readers' comments; each becomes a page of `CommentClass` below its page, a reply below the
comment it answers, with its author (a contributor, and as written), its date, its id, the id of the comment it
answers, its address and its own markup. `ClassChange=replace` (the default) hides an object whose class the rules
changed and makes a new one. `CommentMarkers[]` name the text that marks a page with comments, for
`relink --comments`, which writes every comment of such a page again. Names, subjects and texts are read as written: character
references a source encoded twice (`Kara&amp;#347;`) become their characters; the original markup stays as it is.

`exp:static-import:comments` is the no-data-loss audit: per page the source's count against the stored one, and
for each comment its class, its place (below its page or the comment it answers), its original markup (sha1), its
text, its subject, its author (relation and name), its date, its id and the id of the comment it answers. With
`--trash-replaced` the objects a class change replaced go to the trash, on a page that misses nothing, and each
only when its whole subtree is replaced objects.

## Git authors

`GitAuthors=enabled` keeps the history of `SourceRepository` (a bare clone without file contents, `history.git` in the
work folder). For the pages of `GitAuthorPaths[]` every author of a commit is a contributor; where the source names
nobody, the first author is the writer and the last the updater, with the dates of the commits. `GitAuthorMap[<e-mail
or name>]=<name>` joins several identities into one person (only the name is shown), `GitAuthorSkip[]` leaves bots out.

## Dates: when a page was first published and last changed

Every object gets the real dates of its page, never the day of the import: the object's published and modified dates
and its versions' created and modified dates, set when the import creates or updates it. `DateRules[]` is a chain,
`<created|modified>;<source>[;<arguments>[;<label>[;<paths>]]]`; the first rule of a field that gives a date wins.

| Source | Arguments | Gives |
|---|---|---|
| `attribute` | AttributeMap[] identifiers | the page's own values, such as the byline's `written_at` and `last_updated_at`, or a comment's date |
| `git` | `first` or `last`, and `nobulk` | the commit that added the file, or the last one that changed it; `nobulk` leaves out commits of more than `DateGitBulkCommitFiles` files (default 1000: site-wide rewrites) |
| `meta` | `<meta>` names | date tags by name, property, itemprop or http-equiv (ISO dates, Unix times in seconds or milliseconds) |
| `mtime` | | the file time, never the time of the clone or later (`DateMtimeCloneWindow`, default 86400 seconds) |
| `children` | | the earliest created and the latest modified date of the object's children (a section without a file) |

The label names the source in the report (default: the source); the paths (shell patterns) limit a rule to some
pages. A created date later than the modified date takes the modified date; a page with one date has it as both. The
day of the run is used only when nothing gives a date, and the report counts it. The git index is built once from one
`git log --name-only` over the whole history (`history.git`, cloned when a rule reads git), and
`DateGitPathAliases[]=<regex>;<replacement>` names a path's earlier names, so a renamed folder keeps its first
commit. Without `DateRules[]` the chain is git, the meta tags, then the file time.

The dates are no attributes: the import hash does not see them, so the next run flags nothing. `exp:static-import:dates`
brings the objects of earlier runs in step.

## Console steps beside a run

They never write the state file (a running import would save over it): what the next run must know goes into a state
patch in `state-patches/` of the work folder, which that run merges first.

| Command | What it does |
|---|---|
| `exp:static-import:route --type=<label> [--all] [--batch=<n>] [--resume] [--limit=<n>]` | routed pages into their containers; with `--all` every page below the parent the rules give it today, named by `NameRules[]`, copies and leftovers retired; node ids are kept and old addresses redirect; a progress line and a cursor every n pages, `--resume` continues |
| `exp:static-import:relink --type=<label> [--comments]` | pages whose static links can be resolved now, or every page with comments, imported again |
| `exp:static-import:links --type=<label> [--dry-run] [--batch=200] [--limit=N] [--restart] [--object=<id>]` | the links of the stored bodies rewritten as node links, in place (no new version, no editor change: the edit detection's baseline moves with it), in batches with a saved position, only the changed pages' view caches cleared; every unresolved link per page in `links-unresolved.jsonl`; `--settle`, after the other console steps, moves the baseline of each changed page whose baseline is still the one before its change (`links-baselines.jsonl`) |
| `exp:static-import:hide-non-pages --type=<label>` | hides imported pages that are no pages today (error pages, binary files, viewers, copies, excluded) |
| `exp:static-import:bylines --type=<label> [--fill [--replace-roles]]` | the no-data-loss audit of the bylines, and the fill |
| `exp:static-import:comments --type=<label> [--trash-replaced]` | the no-data-loss audit of the comments, and the removal of the objects they replaced |
| `exp:static-import:dates --type=<label> [--dry-run] [--restart] [--index=queue\|now\|none]` | every imported object (pages, sections, comments, media) gets its real dates by `DateRules[]`, in resumable batches (`dates-state.json`); changed objects are queued for the search index and their view caches cleared |
