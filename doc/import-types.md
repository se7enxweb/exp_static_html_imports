# The import types

| Type | Source | What it reads |
|---|---|---|
| `--html-generic` | a folder of `.html` files | the title, body and metadata through CSS selectors (`TitleSelector`, `BodySelector`, `MetaSelectors[]`); the folders make the tree |
| `--doc-html` | the static export of the Exponential documentation | everything `--html-generic` reads, plus the export's renamed version pages (`FolderPagePatterns[]`), its redirect stubs and error pages, the readers' comments as child pages, and Confluence's authorship |
| `--markdown` | a folder of `.md` files | front matter (title, order, keywords, summary), the help center's Markdown, `README.md` and `index.md` as a folder's page |
| `--sitemap-crawl` | the base URL of a live site | the pages of its `sitemap.xml`, fetched politely: robots.txt respected, one request a second (`RequestInterval`), its own `UserAgent`, a mirror so a page is fetched once |

Every type gets the same engine: remote ids from the source paths, updates, edit detection, removals, redirects for
old paths and copies, media as objects, node links, byline extraction, and reports.
