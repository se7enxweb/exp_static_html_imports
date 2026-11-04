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
                    if ( $folder !== '(language)' && $folder !== '.' && $folder !== '..' && !isset( $known[$folderPath . '.html'] ) && !isset( $this->folderPages[$folderPath] ) && is_dir( rtrim( $source, '/' ) . '/' . $folderPath ) )
                    {
                        $this->folderPages[$folderPath] = $path;
                        break;
                    }
                }
            }
            // the containers ParentRules[] send the folder pages into ({folder}: a version page below the container
            // of its version) are only known now, and so is the order of the pages below them
            $count = count( $paths );
            $paths = $this->addParentContainers( $paths );
            if ( count( $paths ) !== $count )
            {
                $this->known = array_fill_keys( $paths, true );
                $paths = $this->parentsFirst( $paths );
            }
        }
        return $paths;
    }

    /**
     * @param string $path
     * @return string the folder a renamed folder page stands for (4.7 for Technical-manual/4.html), '' for other pages
     */
    protected function folderOf( $path )
    {
        $folder = array_search( $path, $this->folderPages, true );
        return $folder === false ? '' : basename( (string)$folder );
    }

    protected function folderParent( $path )
    {
        $dir = dirname( $path );
        while ( $this->folderPages && $dir !== '.' && $dir !== '' )
        {
            if ( isset( $this->folderPages[$dir] ) && $this->folderPages[$dir] !== $path )
            {
                // the nearest ancestor with a page of its own by name wins over a farther folder page
                $byName = parent::folderParent( $path );
                if ( $byName === '' || strlen( dirname( $byName ) ) < strlen( $dir ) )
                    return $this->folderPages[$dir];
                return $byName;
            }
            $dir = dirname( $dir );
        }
        return parent::folderParent( $path );
    }

    public function parse( $source, $path )
    {
        if ( $this->isGroup( $path ) )
            return parent::parse( $source, $path );
        $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
        // the export's error pages ("404 Not Found", "kernel (20) / Error") and files that are no HTML are no pages
        if ( $this->notAPage( $html ) !== null )
            return null;
        // its redirect stubs ("Page has moved", a meta refresh) become a redirect to the page they point to
        if ( preg_match( '/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+content=["\']?\d+;\s*url=([^"\'>]+)/i', $html, $r ) )
        {
            $target = expStaticImportDom::resolve( $path, $r[1] );
            return array( 'path' => $path, 'title' => '', 'body_html' => '', 'original_html' => '', 'meta' => array(),
                          'hidden' => false, 'aliases' => array(), 'redirect_to' => $target !== null ? $target : '' );
        }
        $page = parent::parse( $source, $path );
        if ( $page === null || isset( $page['asset_of'] ) || isset( $page['redirect_to'] ) )
            return $page;
        $meta = $page['meta'];
        // the bylines (the documentation site's byline box, Confluence's byline sentence, the comment authors) are read
        // by the generic byline rules in parent::parse(), only from their own elements. (A search of the whole HTML
        // for "Created by ..." read sentences of the text as names, and is gone.)
        if ( !empty( $page['bylines']['written']['name'] ) )
            $meta['author'] = $page['bylines']['written']['name'];
        if ( !empty( $page['bylines']['updated']['name'] ) )
            $meta['modifier'] = $page['bylines']['updated']['name'];
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
        $confluence = (string)$this->rules->get( 'ConfluenceCommentsSelector', '' );
        $class = (string)$this->rules->get( 'CommentClass', '' );
        if ( ( $selector === '' && $confluence === '' ) || $class === '' )
            return array();
        $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
        $hasEzdoc = $selector !== '' && strpos( $html, 'class-comment' ) !== false;
        $hasConfluence = $confluence !== '' && strpos( $html, 'comment-thread' ) !== false;
        if ( !$hasEzdoc && !$hasConfluence )
            return array();
        $doc = expStaticImportDom::load( $html );
        // Confluence: <ol class="comment-threads"> of <li class="comment-thread">, the replies in an <ol> in the <li>
        if ( $hasConfluence && ( $root = expStaticImportDom::first( $doc, $confluence ) ) )
            return $this->confluenceCommentList( $doc, $root, $path, $path, $class );
        $root = $hasEzdoc ? expStaticImportDom::first( $doc, $selector ) : null;
        if ( !$root )
            return array();
        foreach ( $root->childNodes as $child )
            if ( $child instanceof DOMElement && $child->nodeName === 'ul' )
                return $this->commentList( $doc, $child, $path, $path, $class );
        return array();
    }

    /**
     * A comment as a page: the fields every comment variant fills.
     *
     * @return array
     */
    protected function commentPage( $pagePath, $parentPath, $class, $id, $title, $author, $time, $text, $original, $position )
    {
        $commentPath = $pagePath . '#comment' . $id;
        $parentID = $parentPath !== $pagePath && preg_match( '/#comment(\w+)$/', $parentPath, $p ) ? $p[1] : '';
        // the subject, the author and the text as written: the old site encoded some characters twice ("Kara&amp;#347;"),
        // so their references are decoded (the original markup stays as the source has it)
        $title = expStaticImportDom::decodeText( $title );
        $author = expStaticImportDom::decodeText( $author );
        $text = expStaticImportDom::decodeDoubleEncoded( $this->cleanCommentText( $text ) );
        return array(
            'path' => $commentPath,
            'title' => $title,
            'body_html' => $text === '' ? '' : ( preg_match( '/^\s*<(p|ul|ol|div|pre|table|h\d)\b/i', $text ) ? $text : '<p>' . $text . '</p>' ),
            'original_html' => $original,
            'meta' => array( 'author' => $author, 'modified' => $time ?: null, 'labels' => array(), 'comment_id' => (string)$id,
                             'parent_comment_id' => $parentID, 'source_url' => $commentPath ),
            // the author and time are the comment's byline: a relation to the contributor, and its date
            // (an author field that holds the reply's subject, "re: change a package", is no person: the comment keeps
            // the text as its author name and relates to nobody)
            'bylines' => $author !== '' && expStaticImportByline::isName( $author, $title ) ? array( 'written' => array( 'name' => $author, 'time' => $time ?: null ) ) : array(),
            'byline_failures' => array(),
            'hidden' => false,
            'aliases' => array(),
            'class_fixed' => $class,
            'parent_fixed' => $parentPath,
            'priority' => $position,
            'children' => array(),
        );
    }

    /**
     * A comment's text cleaned as a page body is (the page's own cleaner rules: code lines become one literal, code
     * and panel macros their custom tags), in a document of its own so the original markup stays as it is.
     *
     * @param string $html
     * @return string
     */
    protected function cleanCommentText( $html )
    {
        if ( trim( (string)$html ) === '' )
            return '';
        $doc = expStaticImportDom::load( '<html><head><meta charset="utf-8"></head><body><div id="static-import-comment-text">' . $html . '</div></body></html>' );
        $box = $doc->getElementById( 'static-import-comment-text' );
        if ( !$box instanceof DOMElement )
            return (string)$html;
        $cleaner = new expStaticImportHtmlCleaner( $this->rules );
        return trim( $cleaner->clean( $doc, $box ) );
    }

    /**
     * @param string $text a date of a comment (the source's own format, a weekday in front or not)
     * @return int|null in the source's timezone (BylineTimezone)
     */
    protected function commentTime( $text )
    {
        $text = trim( preg_replace( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s+/i', '', trim( $text ) ) );
        if ( $text === '' )
            return null;
        $byline = new expStaticImportByline( $this->rules );
        if ( ( $time = $byline->parseDate( $text ) ) !== null )
            return $time;
        try
        {
            $date = new DateTime( $text, new DateTimeZone( (string)$this->rules->get( 'BylineTimezone', 'UTC' ) ) );
            return $date->getTimestamp();
        }
        catch ( Exception $e )
        {
            return null;
        }
    }

    /**
     * Confluence's comment threads, the replies below the comment they answer.
     *
     * @return array
     */
    protected function confluenceCommentList( DOMDocument $doc, DOMElement $list, $pagePath, $parentPath, $class )
    {
        $comments = array();
        $position = 0;
        foreach ( $list->childNodes as $li )
        {
            if ( !$li instanceof DOMElement || $li->nodeName !== 'li' )
                continue;
            $box = null;
            foreach ( $li->childNodes as $child )
                if ( $child instanceof DOMElement && strpos( ' ' . $child->getAttribute( 'class' ) . ' ', ' comment ' ) !== false )
                    $box = $child;
            if ( !$box )
                continue;
            $position++;
            $id = preg_match( '/(\d+)/', $box->getAttribute( 'id' ), $m ) ? $m[1] : (string)$position;
            $author = ( $a = expStaticImportDom::first( $doc, '.comment-header .author', $box ) ) ? expStaticImportDom::text( $a ) : '';
            $content = expStaticImportDom::first( $doc, '.comment-content', $box );
            $text = $content ? trim( expStaticImportDom::innerHtml( $content ) ) : '';
            $dateNode = expStaticImportDom::first( $doc, '.comment-date a', $box );
            $time = $dateNode ? ( $this->commentTime( $dateNode->getAttribute( 'title' ) ) ?: $this->commentTime( expStaticImportDom::text( $dateNode ) ) ) : null;
            $label = $time ? date( 'Y-m-d H:i', $time ) : '';
            $comment = $this->commentPage( $pagePath, $parentPath, $class, $id, trim( 'Comment by ' . ( $author !== '' ? $author : 'an unknown reader' ) . ( $label !== '' ? ', ' . $label : '' ) ),
                                           $author, $time, $text, trim( $doc->saveHTML( $box ) ), $position );
            foreach ( $li->childNodes as $child )
                if ( $child instanceof DOMElement && $child->nodeName === 'ol' )
                    $comment['children'] = array_merge( $comment['children'], $this->confluenceCommentList( $doc, $child, $pagePath, $comment['path'], $class ) );
            $comments[] = $comment;
        }
        return $comments;
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
            $heading = $this->ownFirst( $doc, '.attribute-heading', $li );
            $id = $heading && preg_match( '/(\d+)/', $heading->getAttribute( 'id' ), $m ) ? $m[1] : (string)$position;
            $title = ( $h = $this->ownFirst( $doc, '.attribute-heading h2', $li ) ) ? expStaticImportDom::text( $h ) : '';
            $date = ( $d = $this->ownFirst( $doc, 'p.date', $li ) ) ? expStaticImportDom::text( $d ) : '';
            $author = ( $a = $this->ownFirst( $doc, 'p.author', $li ) ) ? expStaticImportDom::text( $a ) : '';
            $textNode = $this->ownFirst( $doc, '.attribute-short', $li );
            $text = $textNode ? trim( expStaticImportDom::innerHtml( $textNode ) ) : '';
            // the replies: the list whose nearest <li> is this comment's (the old site puts it inside the comment's
            // div.class-comment, four levels down), never a list of the comment's text
            $replies = $this->replyList( $li );
            // the comment's own markup without its replies is kept as the original
            $own = $li->cloneNode( true );
            if ( $ownReplies = $this->replyList( $own ) )
                $ownReplies->parentNode->removeChild( $ownReplies );
            $comment = $this->commentPage( $pagePath, $parentPath, $class, $id, $title !== '' ? $title : 'Comment by ' . ( $author !== '' ? $author : 'an unknown reader' ),
                                           $author, $date !== '' ? $this->commentTime( $date ) : null, $text, trim( $doc->saveHTML( $own ) ), $position );
            if ( $replies )
                $comment['children'] = $this->commentList( $doc, $replies, $pagePath, $comment['path'], $class );
            $comments[] = $comment;
        }
        return $comments;
    }

    /**
     * @param DOMNode $node
     * @return DOMElement|null the nearest <li> above a node that is a comment's, not one of a list in a comment's text
     */
    protected function commentItemOf( DOMNode $node )
    {
        // inside a comment's text (.attribute-short) the <li> below the text are its lists: the comment's <li> is the
        // first one above the text
        $chain = array();
        $text = -1;
        for ( $n = $node->parentNode; $n instanceof DOMElement; $n = $n->parentNode )
        {
            if ( $text < 0 && strpos( ' ' . $n->getAttribute( 'class' ) . ' ', ' attribute-short ' ) !== false )
                $text = count( $chain );
            $chain[] = $n;
        }
        foreach ( $chain as $i => $n )
            if ( $i > $text && $n->nodeName === 'li' )
                return $n;
        return null;
    }

    /**
     * @return DOMElement|null the first element of the selector that belongs to this comment, not to a reply
     */
    protected function ownFirst( DOMDocument $doc, $selector, DOMElement $li )
    {
        foreach ( explode( ',', (string)$selector ) as $alternative )
            foreach ( expStaticImportDom::query( $doc, $alternative, $li ) as $node )
                if ( $node instanceof DOMElement && ( $node === $li || $this->commentItemOf( $node ) === $li ) )
                    return $node;
        return null;
    }

    /**
     * @return DOMElement|null the list of a comment's replies: a <ul> whose comment <li> is this one, outside its text
     */
    protected function replyList( DOMElement $li )
    {
        foreach ( iterator_to_array( $li->getElementsByTagName( 'ul' ) ) as $ul )
        {
            if ( $this->commentItemOf( $ul ) !== $li )
                continue;
            for ( $n = $ul->parentNode; $n instanceof DOMElement && $n !== $li; $n = $n->parentNode )
                if ( strpos( ' ' . $n->getAttribute( 'class' ) . ' ', ' attribute-short ' ) !== false )
                    continue 2;
            return $ul;
        }
        return null;
    }

    protected function fields( array $page )
    {
        $fields = parent::fields( $page );
        // a comment's own fields: its id in the source, the id of the comment it answers, its address
        foreach ( array( 'comment_id', 'parent_comment_id', 'source_url' ) as $key )
            $fields[$key] = isset( $page['meta'][$key] ) ? (string)$page['meta'][$key] : '';
        $fields['author_name'] = isset( $page['meta']['author'] ) ? (string)$page['meta']['author'] : '';
        $fields['version'] = isset( $page['meta']['version'] ) ? (string)$page['meta']['version'] : '';
        // VersionStatus[<shell pattern of the version>]=<status>: the first match wins (e.g. 6*=current, 3*=EOL)
        $fields['version_status'] = '';
        foreach ( $this->rules->getHash( 'VersionStatus' ) as $pattern => $status )
            if ( $fields['version'] !== '' && fnmatch( (string)$pattern, $fields['version'] ) )
            {
                $fields['version_status'] = (string)$status;
                break;
            }
        $fields['modifier'] = isset( $page['meta']['modifier'] ) ? (string)$page['meta']['modifier'] : '';
        $fields['created'] = isset( $page['meta']['created'] ) ? (string)(int)$page['meta']['created'] : '';
        return $fields;
    }
}
