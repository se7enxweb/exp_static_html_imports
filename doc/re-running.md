# Re-running an import

An import can always run again; that is how a site stays in step with its source.

- **Identity.** Every page is the object with the remote id `staticimport-<type>-<md5 of its source path>`, wherever it
  has been moved to.
- **Changed source.** The page is updated: a new version, published.
- **Unchanged source.** Nothing is written.
- **Edited in the CMS.** The import keeps the hash of what it wrote. When the object no longer has that content, the
  page is flagged and left alone. `--overwrite-flagged` writes it anyway, and the edit is lost.
- **Left the source.** The node is hidden and reported. `--apply-removals` moves it to the trash instead.
- **Copies.** A page with the same content as one already imported (`DuplicatePatterns[]`) and a redirect stub become
  redirects to that page; error pages are skipped.
- **Old paths.** Every page's source path, with and without `.html`, answers with a redirect to its node.
