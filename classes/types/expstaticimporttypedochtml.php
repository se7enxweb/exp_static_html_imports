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

    /**
     * The documentation export's own selectors, for what the rules leave out (an installation without exp_doc).
     */
    public function configure( expStaticImportRules $rules )
    {
        $defaults = array(
            'TitleSelector' => '#title-text, .attribute-heading h1, h1',
            'BodySelector' => '#main-content, .area-docmain-normal .content-view-full, #main, body',
            'DefaultClass' => 'folder',
            'AttributeMap' => array( 'folder/name' => 'title', 'folder/short_description' => 'body' ),
        );
        $values = $rules->all();
        foreach ( $defaults as $key => $value )
            if ( !isset( $values[$key] ) || $values[$key] === '' || $values[$key] === array() )
                $values[$key] = $value;
        parent::configure( new expStaticImportRules( $rules->type(), $values ) );
    }

    /** @var array folder path => the page that is that folder's own page (its table of contents) */
    protected $folderPages = array();

    /**
     * The export renamed the version pages (Technical-manual/4.html is the page of the folder 4.7/, 3-5.html the one
     * of 3.6/). For the pages of FolderPagePatterns[], the folder their links point into most is the folder they stand
     * for; parentPath() then puts that folder's pages below them.
     */
    public function discover( $source )
    {
        $paths = parent::discover( $source );
        $patterns = $this->rules->getList( 'FolderPagePatterns' );
        if ( $patterns )
        {
            $known = array_fill_keys( $paths, true );
            foreach ( $paths as $path )
            {
                if ( !self::matchesAny( $path, $patterns ) )
                    continue;
                $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
                if ( !preg_match_all( '/href="([^"\/:#?]+)\//', $html, $m ) )
                    continue;
                $counts = array_count_values( $m[1] );
                arsort( $counts );
                $dir = dirname( $path );
                foreach ( $counts as $folder => $count )
                {
                    $folderPath = ( $dir === '.' ? '' : $dir . '/' ) . $folder;
                    // a folder that has its own page under its own name keeps that one
                    if ( $folder !== '(language)' && !isset( $known[$folderPath . '.html'] ) && !isset( $this->folderPages[$folderPath] ) && is_dir( rtrim( $source, '/' ) . '/' . $folderPath ) )
                    {
                        $this->folderPages[$folderPath] = $path;
                        break;
                    }
                }
            }
        }
        return $paths;
    }

    public function parentPath( $path )
    {
        $dir = dirname( $path );
        while ( $this->folderPages && $dir !== '.' && $dir !== '' )
        {
            if ( isset( $this->folderPages[$dir] ) && $this->folderPages[$dir] !== $path )
            {
                // the nearest ancestor with a page of its own by name wins over a farther folder page
                $byName = parent::parentPath( $path );
                if ( $byName === '' || strlen( dirname( $byName ) ) < strlen( $dir ) )
                    return $this->folderPages[$dir];
                return $byName;
            }
            $dir = dirname( $dir );
        }
        return parent::parentPath( $path );
    }

    public function parse( $source, $path )
    {
        $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
        // the export's error pages ("404 Not Found") are no pages
        foreach ( $this->rules->getList( 'NotFoundTitlePatterns' ) as $pattern )
            if ( preg_match( '/<title>([^<]*)<\/title>/i', $html, $t ) && @preg_match( $pattern, trim( $t[1] ) ) )
                return null;
        // its redirect stubs ("Page has moved", a meta refresh) become a redirect to the page they point to
        if ( preg_match( '/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+content=["\']?\d+;\s*url=([^"\'>]+)/i', $html, $r ) )
        {
            $target = expStaticImportDom::resolve( $path, $r[1] );
            return array( 'path' => $path, 'title' => '', 'body_html' => '', 'original_html' => '', 'meta' => array(),
                          'hidden' => false, 'aliases' => array(), 'redirect_to' => $target !== null ? $target : '' );
        }
        $page = parent::parse( $source, $path );
        if ( $page === null )
            return null;
        $meta = $page['meta'];
        // the documentation site's own byline box (Written by, Last updated by, Maintainers) is read by the generic
        // byline rules (BylineSelectors[], BylineFields[]) in parent::parse(). Confluence: "Created by <a>A</a>, last modified by <a>B</a> on <date>" (or "Created and last modified by")
        if ( preg_match( '/last modified by\s*(?:<a[^>]*>)?([^<]+?)(?:<\/a>)?\s+on\s+(\w+ \d{1,2},? \d{4})/iu', $html, $c ) )
        {
            $meta['modifier'] = trim( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) );
            if ( $time = strtotime( $c[2] ) )
                $meta['modified'] = $time;
        }
        if ( preg_match( '/Created by\s*(?:<a[^>]*>)?([^<]+?)(?:<\/a>)?\s*,/iu', $html, $c ) )
            $meta['author'] = trim( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) );
        // Confluence's authorship into the byline fields too, when the page had no byline box
        if ( empty( $page['bylines'] ) )
        {
            if ( !empty( $meta['author'] ) )
                $page['bylines']['written'] = array( 'name' => $meta['author'], 'time' => isset( $meta['created'] ) ? (int)$meta['created'] : null );
            if ( !empty( $meta['modifier'] ) )
                $page['bylines']['updated'] = array( 'name' => $meta['modifier'], 'time' => isset( $meta['modified'] ) ? (int)$meta['modified'] : null );
        }
        $folder = array_search( $path, $this->folderPages, true );
        if ( $folder !== false )
        {
            $meta['version'] = basename( $folder );
            if ( in_array( $page['title'], $this->rules->getList( 'GenericTitles' ), true ) )
                $page['title'] = basename( $folder );
        }
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
        if ( empty( $meta['version'] ) && preg_match( '#/(\d+(?:[.-]\d+)*(?:\.x)?)(?:/|\.html$)#', $path, $m ) )
            $meta['version'] = str_replace( '-', '.', $m[1] );
        $page['meta'] = $meta;
        $page['children'] = $this->comments( $source, $path );
        return $page;
    }

    /**
     * The readers' comments of a page (CommentsSelector, e.g. #comments-content), as pages of CommentClass below it:
     * one <li> per comment with its heading (h2), date (p.date), author (p.author) and text (.attribute-short), the
     * replies in a <ul> inside the <li>, below the comment they answer.
     *
     * @param string $source
     * @param string $path
     * @return array the comment pages, each with its replies in 'children'
     */
    protected function comments( $source, $path )
    {
        $selector = (string)$this->rules->get( 'CommentsSelector', '' );
        $class = (string)$this->rules->get( 'CommentClass', '' );
        if ( $selector === '' || $class === '' )
            return array();
        $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
        if ( strpos( $html, 'class-comment' ) === false )
            return array();
        $doc = expStaticImportDom::load( $html );
        $root = expStaticImportDom::first( $doc, $selector );
        if ( !$root )
            return array();
        foreach ( $root->childNodes as $child )
            if ( $child instanceof DOMElement && $child->nodeName === 'ul' )
                return $this->commentList( $doc, $child, $path, $path, $class );
        return array();
    }

    /**
     * @param DOMDocument $doc
     * @param DOMElement $ul
     * @param string $pagePath
     * @param string $parentPath
     * @param string $class
     * @return array
     */
    protected function commentList( DOMDocument $doc, DOMElement $ul, $pagePath, $parentPath, $class )
    {
        $comments = array();
        $position = 0;
        foreach ( $ul->childNodes as $li )
        {
            if ( !$li instanceof DOMElement || $li->nodeName !== 'li' )
                continue;
            $position++;
            $heading = expStaticImportDom::first( $doc, '.attribute-heading', $li );
            $id = $heading && preg_match( '/(\d+)/', $heading->getAttribute( 'id' ), $m ) ? $m[1] : (string)$position;
            $title = ( $h = expStaticImportDom::first( $doc, '.attribute-heading h2', $li ) ) ? expStaticImportDom::text( $h ) : '';
            $date = ( $d = expStaticImportDom::first( $doc, 'p.date', $li ) ) ? expStaticImportDom::text( $d ) : '';
            $author = ( $a = expStaticImportDom::first( $doc, 'p.author', $li ) ) ? expStaticImportDom::text( $a ) : '';
            $textNode = expStaticImportDom::first( $doc, '.attribute-short', $li );
            $text = $textNode ? trim( expStaticImportDom::innerHtml( $textNode ) ) : '';
            $commentPath = $pagePath . '#comment' . $id;
            $time = $date !== '' ? strtotime( preg_replace( '/^[A-Za-z]+\s+/', '', $date ) ) : 0;
            $comment = array(
                'path' => $commentPath,
                'title' => $title !== '' ? $title : 'Comment',
                'body_html' => $text === '' ? '' : ( preg_match( '/^\s*<(p|ul|ol|div|pre)\b/i', $text ) ? $text : '<p>' . $text . '</p>' ),
                'original_html' => $text,
                'meta' => array( 'author' => $author, 'modified' => $time ?: null, 'labels' => array() ),
                'hidden' => false,
                'aliases' => array(),
                'class_fixed' => $class,
                'parent_fixed' => $parentPath,
                'priority' => $position,
                'children' => array(),
            );
            foreach ( $li->getElementsByTagName( 'ul' ) as $nested )
            {
                // only the replies list of this <li>, not the lists inside the comment text
                if ( $nested->parentNode === $li || ( $nested->parentNode && $nested->parentNode->parentNode === $li ) )
                {
                    $comment['children'] = $this->commentList( $doc, $nested, $pagePath, $commentPath, $class );
                    break;
                }
            }
            $comments[] = $comment;
        }
        return $comments;
    }

    protected function fields( array $page )
    {
        $fields = parent::fields( $page );
        $fields['version'] = isset( $page['meta']['version'] ) ? (string)$page['meta']['version'] : '';
        $fields['modifier'] = isset( $page['meta']['modifier'] ) ? (string)$page['meta']['modifier'] : '';
        $fields['created'] = isset( $page['meta']['created'] ) ? (string)(int)$page['meta']['created'] : '';
        return $fields;
    }
}
