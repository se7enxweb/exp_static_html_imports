<?php /* #?ini charset="utf-8"?

# Exponential static site import (exp_static_html_imports): turns a static site into content objects of content
# classes, and keeps them in step with the source on every later run. The documentation: doc/index.md in this
# extension (shown in the help center when the extension is active).

[ImportSettings]
# The login the objects are created as (the owner of the imported versions); a type may set its own ImportUser
ImportUser=admin
# Pages per batch: the position is saved and memory freed after every batch, so an interrupted run resumes there
BatchSize=50
# Run history kept per type (the admin view and the dashboard block show it)
HistorySize=50

# The import types: TypeList[<label>]=<class implementing expStaticImportTypeInterface>. The label is the option of
# the command (--doc-html) and the name of the type's settings group [Type_<label>].
[ImportTypes]
TypeList[]
TypeList[doc-html]=expStaticImportTypeDocHtml
TypeList[html-generic]=expStaticImportTypeHtmlGeneric
TypeList[markdown]=expStaticImportTypeMarkdown
TypeList[sitemap-crawl]=expStaticImportTypeSitemapCrawl

# ---------------------------------------------------------------------------------------------------------------------
# The rules of a type. RulesFormat=ini (the default) reads them from the group itself; RulesFormat=yaml with
# RulesFile=<file.yml>, or RulesFormat=php with RulesClass=<class implementing expStaticImportRulesProvider>, read them
# from there, falling back to the group for what they leave out.
#
# Common to every type:
#   SourceRepository / SourceBranch   a git repository cloned into var/<site>/static-import/<type>/source and pulled
#                                     before every run; or SourceLocation, a folder (or the base URL of a crawl)
#   ParentNode / ParentRemoteID       where the top pages go;  MediaParentNode / MediaParentRemoteID for the media
#   ImportUser, Language, BatchSize   as in [ImportSettings]
#   SectionMap[<class>]=<section identifier>  (and SectionMap[*]); HiddenSection for hidden pages; MediaSection
#   ClassRules[]=<shell pattern>;<class>  DefaultClass   which class a page becomes, the first match wins
#   AttributeMap[<class>/<attribute>]=<field>   title, short_title, body, original_html, source_path, page_id, author,
#                                     modified, labels, description, keywords, import_hash, version (doc-html)
#   AssetClassRules[]=<shell pattern>;<class>  AssetAttributeMap[<class>/<attribute>]=<field> (file, title, alt,
#                                     caption, credit, source_path)
#   IncludePaths[] ExcludePaths[] HiddenPaths[] DuplicatePatterns[] (regular expressions) DeduplicateAll
#   CreateRedirects (default enabled)  RedirectPrefix   old paths answer with a redirect to their node
# HTML types: Suffixes[] IndexFiles[] TitleSelector BodySelector OriginalSelector RemoveTitleFromBody MetaSelectors[]
#   PageIdPatterns[] TitleSuffixPatterns[] RemoveSelectors[] UnwrapSelectors[] PanelSelectors[<type>] CodeSelectors[]
#   CodeLineSelector CodeLanguagePatterns[] ExpandSelectors[] ExpandTitleSelector ChildrenSelectors[] AssetSuffixes[]
# ---------------------------------------------------------------------------------------------------------------------

[Type_html-generic]
RulesFormat=ini
Suffixes[]
Suffixes[]=html
Suffixes[]=htm
IndexFiles[]
IndexFiles[]=index.html
TitleSelector=h1
BodySelector=main, article, body
DefaultClass=folder
ClassRules[]
AttributeMap[]
AttributeMap[folder/name]=title
AttributeMap[folder/short_description]=body
AttributeMap[article/title]=title
AttributeMap[article/body]=body
RemoveSelectors[]
RemoveSelectors[]=nav
RemoveSelectors[]=header
RemoveSelectors[]=footer
CodeSelectors[]
CodeSelectors[]=pre
CodeLanguagePatterns[]
CodeLanguagePatterns[]=/language-([a-z0-9+#]+)/i
AssetClassRules[]
AssetClassRules[]=*.pdf;file
AssetClassRules[]=*.zip;file
AssetClassRules[]=*;image
AssetAttributeMap[]
AssetAttributeMap[image/name]=title
AssetAttributeMap[image/image]=file
AssetAttributeMap[file/name]=title
AssetAttributeMap[file/file]=file

[Type_markdown]
RulesFormat=ini
Suffixes[]
Suffixes[]=md
Suffixes[]=markdown
IndexFiles[]
IndexFiles[]=README.md
IndexFiles[]=index.md
DefaultClass=folder
AttributeMap[]
AttributeMap[folder/name]=title
AttributeMap[folder/short_description]=body
CodeSelectors[]
CodeSelectors[]=pre
CodeLanguagePatterns[]
CodeLanguagePatterns[]=/language-([a-z0-9+#]+)/i

[Type_sitemap-crawl]
RulesFormat=ini
SitemapPath=sitemap.xml
RequestInterval=1
Timeout=30
UserAgent=Exponential static import (exp_static_html_imports; +https://doc.exponential.earth)
Refetch=disabled
TitleSelector=h1
BodySelector=main, article, body
DefaultClass=folder
AttributeMap[]
AttributeMap[folder/name]=title
AttributeMap[folder/short_description]=body
RemoveSelectors[]
RemoveSelectors[]=nav
RemoveSelectors[]=header
RemoveSelectors[]=footer

# The documentation site's static export. exp_doc gives this type its classes, selectors and every other rule in its
# own statichtmlimport.ini.append.php; only the source is set here. (Settings of an extension listed earlier in
# ActiveExtensions win over a later one's, so rules set here could not be changed by exp_doc.) Without exp_doc the
# type falls back to the selectors of its class and imports into folders.
[Type_doc-html]
RulesFormat=ini
SourceRepository=https://github.com/se7enxweb/exponential.doc.exponential.earth.git
SourceBranch=master

*/ ?>
