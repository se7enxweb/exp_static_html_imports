<?php
/**
 * File containing the expStaticImportTypeDocHtml class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * --doc-html: the static export of the documentation site (https://exponential.doc.exponential.earth, source
 * se7enxweb/exponential.doc.exponential.earth). It is the mirror of a documentation site rendered by the eZ Publish
 * era doc extension, plus pages exported from Confluence. What is particular to it:
 *
 * - The content of a page is the area .area-docmain-normal .content-view-full; the heading is
 *   .attribute-heading h1, the author and date line .attribute-byline.
 * - The same page exists under view-parameter copies such as ".../(language)/eng-GB.html" and under copies in
 *   doc_hidden/; these are duplicates (DuplicatePatterns[]) that become redirects to the one imported page.
 * - Confluence pages carry their page id in "pageId=<n>" links and in their file names (viewpage<n>.html).
 * - Code blocks are <pre> elements made of <span class="line"> lines; panels are Confluence macro divs.
 * - Manual and version pages (Technical-manual, User-manual, the version numbers below them) become the manual and
 *   version classes by ClassRules[].
 *
 * Every selector and pattern is a setting of [Type_doc-html], so this class only adds what a setting cannot say: the
 * version of a page from its path, the author and date split of the byline, the Confluence page id of a file name.
 */
class expStaticImportTypeDocHtml extends expStaticImportHtmlType
{
    public function identifier()
    {
        return 'doc-html';
    }

    public function parse( $source, $path )
    {
        $page = parent::parse( $source, $path );
        if ( $page === null )
            return null;
        $meta = $page['meta'];
        // ".attribute-byline": "<author> - <date>" in the old documentation; split when the selector caught both
        if ( isset( $meta['byline'] ) )
        {
            // Confluence: "Created and last modified by <author> on <Month d, yyyy>" (or "Created by A, last modified by B on ...")
            if ( preg_match( '/\bby\s+(.+?)\s+on\s+(\w+ \d{1,2},? \d{4})/u', $meta['byline'], $c ) )
            {
                if ( empty( $meta['author'] ) )
                    $meta['author'] = trim( preg_replace( '/^.*\bby\s+/u', '', $c[1] ) );
                $time = strtotime( $c[2] );
                if ( $time )
                    $meta['modified'] = $time;
            }
            else if ( preg_match( '/^(.*?)\s*[-|]\s*(\d{1,2}[\/. ]\w+[\/. ]\d{2,4}.*|\w+ \d{1,2},? \d{4}.*)$/u', $meta['byline'], $m ) )
            {
                if ( empty( $meta['author'] ) )
                    $meta['author'] = trim( $m[1] );
                $time = strtotime( trim( $m[2] ) );
                if ( $time )
                    $meta['modified'] = $time;
            }
            else if ( empty( $meta['author'] ) )
                $meta['author'] = $meta['byline'];
            unset( $meta['byline'] );
        }
        if ( empty( $meta['page_id'] ) && preg_match( '/viewpage(\d+)\.html$/', $path, $m ) )
            $meta['page_id'] = $m[1];
        if ( preg_match( '#/(\d+(?:[.-]\d+)*(?:\.x)?)(?:/|\.html$)#', $path, $m ) )
            $meta['version'] = str_replace( '-', '.', $m[1] );
        $page['meta'] = $meta;
        return $page;
    }

    protected function fields( array $page )
    {
        $fields = parent::fields( $page );
        $fields['version'] = isset( $page['meta']['version'] ) ? (string)$page['meta']['version'] : '';
        return $fields;
    }
}
