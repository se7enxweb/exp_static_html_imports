<?php
/**
 * File containing the expStaticImportHtmlType class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The common part of the HTML types (--html-generic, --doc-html): a folder of .html files whose folder structure is
 * the page tree, read with selectors from the type's rules.
 *
 * Rules (statichtmlimport.ini [Type_<label>]):
 *   Suffixes[]               the page files (default html, htm)
 *   IncludePaths[]           shell patterns of the paths that are pages (empty: all)
 *   ExcludePaths[]           shell patterns left out
 *   HiddenPaths[]            shell patterns of pages imported hidden (reachable, not listed)
 *   IndexFiles[]             the file that is a folder's own page (default index.html)
 *   TitleSelector            the title (default h1); falls back to <title> without TitleSuffixPatterns[]
 *   BodySelector             the body (default body)
 *   OriginalSelector         the part kept as original HTML (default: the body selector)
 *   MetaSelectors[<key>]     author, modified, labels (all matches), page_id
 *   PageIdPatterns[]         regular expressions on the HTML that capture a page id
 *   ClassRules[]             <shell pattern>;<class identifier>, the first match wins
 *   DefaultClass             the class of every other page
 *   AttributeMap[<class>/<attribute>]  title, short_title, body, original_html, source_path, page_id, author,
 *                            modified, labels, description, keywords, import_hash (the page field that fills it)
 *   AssetSuffixes[]          files that are media, not pages (png, jpg, gif, pdf, zip ...)
 *   DuplicatePatterns[]      regular expressions on the path; a matching page is imported only when no page with the
 *                            same content was imported under another path (it then becomes an alias of it)
 *
 * Where a page goes when its folder says nothing (an export of flat folders, such as Confluence's display/<SPACE>/):
 *   BreadcrumbSelector       the links of a page's breadcrumb (e.g. "#breadcrumbs a"), from the top down: the nearest
 *                            one that is a page of the import is the page's parent, before the folder rule
 *   BreadcrumbPaths[]        shell patterns of the pages whose breadcrumb is read (empty: every page)
 *   GroupFolders[]           shell patterns of folders (e.g. "display", "display/*") that become a section page of their
 *                            own when they have no page by their name: the parent of the folder's pages left without
 *                            one. Its path is the folder with a slash ("display/EZP/")
 *   GroupTitles[<folder>]    the title of a group page; else the text of GroupTitleSelector (e.g. the breadcrumb's
 *                            first item, the space's name) in the first page of the folder that has it; else the name
 *   GroupClass               the class of the group pages (default DefaultClass);  GroupBody  an optional text
 *
 * What is no page:
 *   NotFoundTitlePatterns[]  regular expressions on <title>: the export's error pages (404, "kernel (20) / Error")
 *   SkipBinaryPages          (default enabled) a file with a page suffix that is no HTML (a font, a gzip file)
 *   AssetPages               (default disabled) a page whose body is one image or one link to a media file and no
 *                            text (AssetPageMaxText, default 0 characters) is the viewer of that file: the file becomes a
 *                            media object and the page's path redirects to it.  AssetPagePaths[]: always such pages
 */
abstract class expStaticImportHtmlType implements expStaticImportTypeInterface
{
    /** @var expStaticImportRules */
    protected $rules;

    /** @var array path => true, the pages discover() found */
    protected $known = array();

    /** @var array path => the parent read from the page's breadcrumb */
    protected $breadcrumbParents = array();

    /** @var array group path ("<folder>/") => its title */
    protected $groups = array();

    /** @var array group path => its class (a route container's) */
    protected $groupClasses = array();

    /** @var array group path => the values of its first page (RouteMeta[]), for its title and relation */
    protected $groupMeta = array();

    /** @var array path => array( 'container' => ..., 'name' => ..., 'meta' => array, 'current' => page id ) */
    protected $routes = array();

    /** @var array page id => the path of the current page with that id (pages whose breadcrumb is read) */
    protected $pageIdPaths = array();

    /**
     * @param string $pageId
     * @return string the path of the current page with that page id, '' when unknown
     */
    public function pathOfPageId( $pageId )
    {
        return isset( $this->pageIdPaths[(string)$pageId] ) ? $this->pageIdPaths[(string)$pageId] : '';
    }

    public function configure( expStaticImportRules $rules )
    {
        $this->rules = $rules;
    }

    /**
     * @param array $paths the discovered paths, so map() can find a page's parent
     */
    public function setKnownPaths( array $paths )
    {
        $this->known = array_fill_keys( $paths, true );
    }

    public function discover( $source )
    {
        $suffixes = $this->rules->getList( 'Suffixes' ) ?: array( 'html', 'htm' );
        $include = $this->rules->getList( 'IncludePaths' );
        $exclude = $this->rules->getList( 'ExcludePaths' );
        $paths = array();
        $source = rtrim( $source, '/' );
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
                function ( $file ) { return $file->getFilename()[0] !== '.'; }
            )
        );
        foreach ( $iterator as $file )
        {
            if ( !$file->isFile() || !in_array( strtolower( $file->getExtension() ), $suffixes, true ) )
                continue;
            $path = substr( $file->getPathname(), strlen( $source ) + 1 );
            if ( $include && !self::matchesAny( $path, $include ) )
                continue;
            if ( $exclude && self::matchesAny( $path, $exclude ) )
                continue;
            $paths[] = $path;
        }
        $paths = $this->readStructure( $source, $paths );
        usort( $paths, function ( $a, $b ) {
            $depth = substr_count( rtrim( $a, '/' ), '/' ) - substr_count( rtrim( $b, '/' ), '/' );
            return $depth !== 0 ? $depth : strcmp( $a, $b );
        } );
        $this->known = array_fill_keys( $paths, true );
        return $this->parentsFirst( $paths );
    }

    /**
     * The breadcrumb parents (BreadcrumbSelector) and the group pages (GroupFolders[]) of the discovered pages.
     *
     * @param string $source
     * @param array $paths
     * @return array the paths, with the group pages added
     */
    protected function readStructure( $source, array $paths )
    {
        $this->breadcrumbParents = $this->groups = array();
        $selector = trim( (string)$this->rules->get( 'BreadcrumbSelector', '' ) );
        $crumbPaths = $this->rules->getList( 'BreadcrumbPaths' );
        $groupFolders = $this->rules->getList( 'GroupFolders' );
        $titleSelector = trim( (string)$this->rules->get( 'GroupTitleSelector', '' ) );
        $known = array_fill_keys( $paths, true );
        $folderTitles = array();
        $this->pageIdPaths = array();
        $routeMeta = $this->rules->getHash( 'RouteMeta' );
        $idPattern = isset( $routeMeta['page_id'] ) ? (string)$routeMeta['page_id'] : '';
        $routedPatterns = array();
        foreach ( $this->rules->getList( 'RouteRules' ) as $line )
            $routedPatterns[] = trim( strtok( $line, ';' ) );
        foreach ( $paths as $path )
        {
            $read = $selector !== '' && ( !$crumbPaths || self::matchesAny( $path, $crumbPaths ) );
            $needTitle = $titleSelector !== '' && $groupFolders && !isset( $folderTitles[dirname( $path )] ) && self::matchesAny( dirname( $path ), $groupFolders );
            if ( !$read && !$needTitle )
                continue;
            $html = @file_get_contents( rtrim( $source, '/' ) . '/' . $path );
            if ( !is_string( $html ) || $html === '' || $this->isBinary( $html ) )
                continue;
            // the page id of a page whose breadcrumb is read (RouteMeta[page_id]): the current page of its copies
            if ( $idPattern !== '' && @preg_match( $idPattern, $html, $im ) && isset( $im[1] ) && !isset( $this->pageIdPaths[$im[1]] ) && !self::matchesAny( $path, $routedPatterns ) && !$this->isDuplicateCandidate( $path ) )
                $this->pageIdPaths[$im[1]] = $path;
            $doc = expStaticImportDom::load( $html );
            if ( $read )
            {
                $parent = '';
                foreach ( expStaticImportDom::query( $doc, $selector ) as $link )
                {
                    $target = $link instanceof DOMElement ? expStaticImportDom::resolve( $path, $link->getAttribute( 'href' ) ) : null;
                    if ( $target !== null && $target !== $path && isset( $known[$target] ) )
                        $parent = $target;
                }
                if ( $parent !== '' )
                    $this->breadcrumbParents[$path] = $parent;
            }
            if ( $needTitle && ( $node = expStaticImportDom::first( $doc, $titleSelector ) ) && ( $text = expStaticImportDom::text( $node ) ) !== '' )
                $folderTitles[dirname( $path )] = $text;
        }
        if ( $groupFolders )
        {
            $folders = array();
            foreach ( $paths as $path )
                for ( $dir = dirname( $path ); $dir !== '.' && $dir !== '' && $dir !== '/'; $dir = dirname( $dir ) )
                    $folders[$dir] = true;
            $titles = $this->rules->getHash( 'GroupTitles' );
            $indexFiles = $this->rules->getList( 'IndexFiles' ) ?: array( 'index.html' );
            foreach ( array_keys( $folders ) as $folder )
            {
                if ( !self::matchesAny( $folder, $groupFolders ) || isset( $known[$folder . '.html'] ) )
                    continue;
                foreach ( $indexFiles as $index )
                    if ( isset( $known[$folder . '/' . $index] ) )
                        continue 2;
                $this->groups[$folder . '/'] = isset( $titles[$folder] ) && trim( (string)$titles[$folder] ) !== '' ? trim( (string)$titles[$folder] )
                    : ( isset( $folderTitles[$folder] ) ? $folderTitles[$folder] : ucfirst( str_replace( array( '-', '_', '+' ), ' ', basename( $folder ) ) ) );
                $paths[] = $folder . '/';
            }
        }
        $paths = $this->readRoutes( $source, $paths );
        // a breadcrumb that leads round in a circle (A below B below A) is not followed
        $this->known = array_fill_keys( $paths, true );
        foreach ( array_keys( $this->breadcrumbParents ) as $path )
        {
            $seen = array( $path => true );
            for ( $step = $this->parentPath( $path ), $n = 0; $step !== '' && $n < 200; $step = $this->parentPath( $step ), $n++ )
            {
                if ( isset( $seen[$step] ) )
                {
                    unset( $this->breadcrumbParents[$path] );
                    break;
                }
                $seen[$step] = true;
            }
        }
        return $paths;
    }

    /**
     * RouteRules[]: a family of pages goes into a container of its own, with a name of its own, instead of the place
     * its path or breadcrumb would give it (e.g. Confluence's hundreds of "Page History" views, which would
     * otherwise be same-name siblings). Each rule is
     *   <shell pattern of the path>;<container path>;<name pattern>[;<condition>[;<current page id>]]
     * The patterns take {<key>} values of RouteMeta[<key>]=<regular expression on the page's HTML, first group>,
     * {title} (the page's <title> without TitleSuffixPatterns[]) and {path}; {a|b} is the first that is not empty.
     * The condition is "<key>!=<key>" or "<key>=<key>" (e.g. latest_id!=page_id: an old version). The current page id
     * is the page id (source_page_id) of the page this one is a copy, version or view of: it fills the field
     * history_of. The first rule that matches wins.
     * RouteContainers[<container path>]=<class>;<title pattern> makes the containers, each a group page (a path
     * ending in "/"), nested by their paths; a key with * stands for one folder of any name (one container per
     * original page: "historical/page-history/*\/"). A container's title takes the values of its first page.
     *
     * @param string $source
     * @param array $paths
     * @return array the paths, with the containers added
     */
    protected function readRoutes( $source, array $paths )
    {
        $this->routes = $this->groupClasses = $this->groupMeta = array();
        $rules = array();
        foreach ( $this->rules->getList( 'RouteRules' ) as $line )
        {
            $parts = array_map( 'trim', explode( ';', $line ) );
            if ( count( $parts ) >= 3 && $parts[0] !== '' && $parts[1] !== '' )
                $rules[] = array_pad( $parts, 5, '' );
        }
        if ( !$rules )
            return $paths;
        $metaRules = $this->rules->getHash( 'RouteMeta' );
        $containers = $this->rules->getHash( 'RouteContainers' );
        foreach ( $paths as $path )
        {
            $html = null;
            foreach ( $rules as $rule )
            {
                if ( !self::matches( $path, $rule[0] ) )
                    continue;
                if ( $html === null )
                {
                    $html = (string)@file_get_contents( rtrim( $source, '/' ) . '/' . $path );
                    $meta = array( 'path' => $path, 'title' => '' );
                    foreach ( $metaRules as $key => $regex )
                        $meta[$key] = @preg_match( $regex, $html, $m ) && isset( $m[1] ) ? trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) : '';
                    if ( preg_match( '/<title>([^<]*)<\/title>/i', $html, $t ) )
                    {
                        $meta['title'] = trim( html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) );
                        foreach ( $this->rules->getList( 'TitleSuffixPatterns' ) as $pattern )
                            $meta['title'] = trim( (string)@preg_replace( $pattern, '', $meta['title'] ) );
                    }
                }
                if ( $rule[3] !== '' && !self::condition( $rule[3], $meta ) )
                    continue;
                $container = rtrim( self::fill( $rule[1], $meta ), '/' ) . '/';
                $name = trim( self::fill( $rule[2], $meta ) );
                if ( $container === '/' || $name === '' || strpos( $container, '{' ) !== false )
                    continue;
                $this->routes[$path] = array( 'container' => $container, 'name' => $name, 'meta' => $meta,
                                              'current' => $rule[4] !== '' ? trim( self::fill( $rule[4], $meta ) ) : '' );
                // the container and the containers above it
                for ( $dir = $container; $dir !== '/' && $dir !== './' && $dir !== ''; $dir = ( dirname( rtrim( $dir, '/' ) ) === '.' ? '' : dirname( rtrim( $dir, '/' ) ) . '/' ) )
                {
                    if ( isset( $this->groups[$dir] ) )
                        continue;
                    foreach ( $containers as $key => $value )
                    {
                        if ( !self::matches( rtrim( $dir, '/' ), rtrim( (string)$key, '/' ) ) )
                            continue;
                        list( $class, $title ) = array_pad( array_map( 'trim', explode( ';', (string)$value, 2 ) ), 2, '' );
                        $title = trim( self::fill( $title, $meta ) );
                        $this->groups[$dir] = $title !== '' ? $title : basename( rtrim( $dir, '/' ) );
                        if ( $class !== '' )
                            $this->groupClasses[$dir] = $class;
                        // a container of one original page is a history of that page too
                        $this->groupMeta[$dir] = $dir === $container ? $this->routes[$path]['current'] : '';
                        $paths[] = $dir;
                        break;
                    }
                }
                break;
            }
        }
        return $paths;
    }

    /**
     * @param string $pattern with {key} and {a|b} placeholders
     * @param array $values
     * @return string
     */
    public static function fill( $pattern, array $values )
    {
        return preg_replace_callback( '/\{([a-z0-9_|]+)\}/i', function ( $m ) use ( $values ) {
            foreach ( explode( '|', $m[1] ) as $key )
                if ( isset( $values[$key] ) && trim( (string)$values[$key] ) !== '' )
                    return trim( (string)$values[$key] );
            return '';
        }, (string)$pattern );
    }

    /**
     * @param string $condition "<key>!=<key>" or "<key>=<key>"
     * @param array $values
     * @return bool
     */
    public static function condition( $condition, array $values )
    {
        if ( !preg_match( '/^\s*(\w+)\s*(!=|=)\s*(\w+)\s*$/', $condition, $m ) )
            return false;
        $a = isset( $values[$m[1]] ) ? (string)$values[$m[1]] : '';
        $b = isset( $values[$m[3]] ) ? (string)$values[$m[3]] : '';
        return $m[2] === '=' ? $a === $b : $a !== $b;
    }

    /**
     * @param string $path
     * @return array|null the route of a page (RouteRules[])
     */
    public function route( $path )
    {
        return isset( $this->routes[$path] ) ? $this->routes[$path] : null;
    }

    /**
     * @return array path => route, every routed page
     */
    public function routes()
    {
        return $this->routes;
    }

    /**
     * Every page after its parent, which a breadcrumb parent in the same folder may not be by the path order; the
     * order is kept otherwise.
     *
     * @param array $paths
     * @return array
     */
    protected function parentsFirst( array $paths )
    {
        if ( !$this->breadcrumbParents && !$this->groups )
            return $paths;
        $out = $done = array();
        foreach ( $paths as $path )
        {
            $chain = array();
            for ( $step = $path, $n = 0; $step !== '' && !isset( $done[$step] ) && $n < 200; $step = $this->parentPath( $step ), $n++ )
                $chain[] = $step;
            foreach ( array_reverse( $chain ) as $step )
                if ( !isset( $done[$step] ) && isset( $this->known[$step] ) )
                {
                    $done[$step] = true;
                    $out[] = $step;
                }
        }
        return $out;
    }

    /**
     * @param string $path
     * @return bool whether the path is a group page (GroupFolders[])
     */
    public function isGroup( $path )
    {
        return isset( $this->groups[$path] );
    }

    /**
     * A group page: a section made for a folder that has no page of its own.
     *
     * @param string $path
     * @return array
     */
    protected function groupPage( $path )
    {
        $body = trim( (string)$this->rules->get( 'GroupBody', '' ) );
        $page = array(
            'path' => $path,
            'title' => $this->groups[$path],
            'body_html' => $body === '' ? '' : '<p>' . htmlspecialchars( str_replace( '{title}', $this->groups[$path], $body ), ENT_QUOTES, 'UTF-8' ) . '</p>',
            'original_html' => '',
            'meta' => array( 'labels' => array(), 'modified' => null ),
            'hidden' => self::matchesAny( $path, $this->rules->getList( 'HiddenPaths' ) ),
            'aliases' => array(),
            'duplicate_candidate' => false,
            'bylines' => array(),
            'byline_failures' => array(),
        );
        $class = isset( $this->groupClasses[$path] ) ? $this->groupClasses[$path] : trim( (string)$this->rules->get( 'GroupClass', '' ) );
        if ( $class !== '' )
            $page['class_fixed'] = $class;
        if ( !empty( $this->groupMeta[$path] ) )
            $page['history_of'] = (string)$this->groupMeta[$path];
        return $page;
    }

    /**
     * @param string $html
     * @return bool whether a file with a page suffix holds no HTML (a font, a gzip file)
     */
    protected function isBinary( $html )
    {
        $head = substr( (string)$html, 0, 4096 );
        return strpos( $head, "\0" ) !== false || !preg_match( '/<\s*(?:!doctype|html|head|body|div|p|h[1-6]|title|meta|table|ul)\b/i', $head );
    }

    /**
     * @param string $html
     * @return string|null why the file is no page (binary, an error page of the export), null for a page
     */
    public function notAPage( $html )
    {
        if ( $this->rules->enabled( 'SkipBinaryPages', true ) && $this->isBinary( $html ) )
            return 'not HTML';
        if ( preg_match( '/<title>([^<]*)<\/title>/i', (string)$html, $t ) )
        {
            $title = trim( html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) );
            foreach ( $this->rules->getList( 'NotFoundTitlePatterns' ) as $pattern )
                if ( @preg_match( $pattern, $title ) )
                    return 'an error page';
        }
        return null;
    }

    /**
     * AssetPages: the media file a page only shows (one image or one link to a media file, no text of its own).
     *
     * @param DOMDocument $doc
     * @param DOMElement $body the cleaned body
     * @param string $path
     * @return array|null array( path of the media file, alt text ) or null
     */
    protected function assetOfPage( DOMDocument $doc, DOMElement $body, $path )
    {
        $forced = self::matchesAny( $path, $this->rules->getList( 'AssetPagePaths' ) );
        if ( !$forced && !$this->rules->enabled( 'AssetPages', false ) )
            return null;
        $suffixes = $this->rules->getList( 'AssetSuffixes' ) ?: array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'pdf', 'zip' );
        $found = array();
        $xpath = new DOMXPath( $doc );
        foreach ( $xpath->query( './/img[@src] | .//a[@href]', $body ) as $element )
        {
            $target = expStaticImportDom::resolve( $path, $element->getAttribute( $element->nodeName === 'img' ? 'src' : 'href' ) );
            if ( $target !== null && in_array( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ), $suffixes, true ) )
                $found[$target] = $element;
            else if ( $element->nodeName === 'a' && !$forced )
                return null;
        }
        if ( count( $found ) !== 1 )
            return null;
        $element = reset( $found );
        $target = key( $found );
        $own = trim( preg_replace( '/\s+/u', ' ', str_replace( $element->textContent, '', $body->textContent ) ) );
        if ( !$forced && mb_strlen( $own ) > (int)$this->rules->get( 'AssetPageMaxText', 0 ) )
            return null;
        $alt = $element->nodeName === 'img' ? trim( $element->getAttribute( 'alt' ) ?: $element->getAttribute( 'title' ) ) : trim( $element->textContent );
        return array( $target, $alt );
    }

    public function parse( $source, $path )
    {
        if ( isset( $this->groups[$path] ) )
            return $this->groupPage( $path );
        $file = rtrim( $source, '/' ) . '/' . $path;
        $html = @file_get_contents( $file );
        if ( $html === false )
            throw new expStaticImportPageException( "cannot read $path" );
        if ( $this->notAPage( $html ) !== null )
            return null;
        $doc = expStaticImportDom::load( $html );
        $bodySelector = (string)$this->rules->get( 'BodySelector', 'body' );
        $body = expStaticImportDom::first( $doc, $bodySelector );
        if ( !$body instanceof DOMElement )
            throw new expStaticImportPageException( "no body ($bodySelector) in $path" );

        $title = '';
        $titleNode = expStaticImportDom::first( $doc, (string)$this->rules->get( 'TitleSelector', 'h1' ), $body );
        if ( $titleNode )
        {
            $title = expStaticImportDom::text( $titleNode );
            if ( $this->rules->enabled( 'RemoveTitleFromBody', true ) && $titleNode->parentNode )
            {
                // the heading's wrapper (e.g. div.attribute-heading) goes with it when it holds nothing else
                $wrapper = $titleNode->parentNode;
                $wrapper->removeChild( $titleNode );
                if ( $wrapper !== $body && trim( $wrapper->textContent ) === '' && $wrapper->parentNode )
                    $wrapper->parentNode->removeChild( $wrapper );
            }
        }
        if ( $title === '' )
        {
            $titleTag = $doc->getElementsByTagName( 'title' )->item( 0 );
            $title = $titleTag ? expStaticImportDom::text( $titleTag ) : '';
            foreach ( $this->rules->getList( 'TitleSuffixPatterns' ) as $pattern )
                $title = trim( (string)@preg_replace( $pattern, '', $title ) );
        }
        if ( $title === '' )
            $title = ucfirst( str_replace( array( '-', '_' ), ' ', pathinfo( $path, PATHINFO_FILENAME ) ) );

        $meta = array( 'labels' => array() );
        foreach ( $this->rules->getHash( 'MetaSelectors' ) as $key => $selector )
        {
            if ( $key === 'labels' )
            {
                foreach ( expStaticImportDom::query( $doc, $selector ) as $node )
                    if ( ( $label = expStaticImportDom::text( $node ) ) !== '' )
                        $meta['labels'][] = $label;
                continue;
            }
            $node = expStaticImportDom::first( $doc, $selector );
            if ( $node )
                $meta[$key] = expStaticImportDom::text( $node );
        }
        foreach ( $this->rules->getList( 'PageIdPatterns' ) as $pattern )
            if ( @preg_match( $pattern, $html, $m ) && isset( $m[1] ) )
            {
                $meta['page_id'] = $m[1];
                break;
            }
        foreach ( array( 'description', 'keywords' ) as $name )
        {
            $xpath = new DOMXPath( $doc );
            $node = $xpath->query( "//meta[@name='$name']" )->item( 0 );
            if ( $node && trim( $node->getAttribute( 'content' ) ) !== '' )
                $meta[$name] = trim( $node->getAttribute( 'content' ) );
        }
        // a site-wide default description (IgnoreDescriptions[]) is no description of the page: the first paragraph is
        if ( isset( $meta['description'] ) && in_array( $meta['description'], $this->rules->getList( 'IgnoreDescriptions' ), true ) )
            unset( $meta['description'] );
        if ( isset( $meta['keywords'] ) )
            $meta['keywords'] = array_values( array_filter( array_map( 'trim', explode( ',', $meta['keywords'] ) ) ) );
        if ( isset( $meta['modified'] ) && !ctype_digit( (string)$meta['modified'] ) )
        {
            $time = strtotime( preg_replace( '/^.*?(\d)/', '$1', (string)$meta['modified'] ) );
            $meta['modified'] = $time ? $time : null;
        }
        if ( empty( $meta['modified'] ) )
            $meta['modified'] = (int)@filemtime( $file );

        $originalSelector = (string)$this->rules->get( 'OriginalSelector', '' );
        $originalNode = $originalSelector !== '' ? expStaticImportDom::first( $doc, $originalSelector ) : $body;
        $original = $originalNode ? expStaticImportDom::innerHtml( $originalNode ) : '';

        // the byline (who wrote and updated the page) leaves the body for fields of its own
        $byline = new expStaticImportByline( $this->rules );
        $bylines = $byline->extract( $doc, $body );
        if ( isset( $bylines['fields']['written']['name'] ) )
            $meta['author'] = $bylines['fields']['written']['name'];
        if ( !empty( $bylines['fields']['updated']['time'] ) )
            $meta['modified'] = $bylines['fields']['updated']['time'];

        $cleaner = new expStaticImportHtmlCleaner( $this->rules );
        $bodyHtml = $cleaner->clean( $doc, $body );

        // the viewer of one media file (AssetPages): the file is imported as media, the page's path redirects to it
        if ( $asset = $this->assetOfPage( $doc, $body, $path ) )
            return array( 'path' => $path, 'title' => $title, 'body_html' => '', 'original_html' => '', 'meta' => $meta, 'hidden' => false,
                          'aliases' => array(), 'asset_of' => $asset[0], 'asset_meta' => array( 'alt' => $asset[1] ) );

        if ( !isset( $meta['description'] ) )
        {
            $first = expStaticImportDom::first( $doc, 'p', $body );
            if ( $first && ( $text = expStaticImportDom::text( $first ) ) !== '' )
                $meta['description'] = mb_substr( $text, 0, 300 );
        }

        return array(
            'path' => $path,
            'title' => $title,
            'body_html' => $bodyHtml,
            'original_html' => trim( $original ),
            'meta' => $meta,
            'hidden' => self::matchesAny( $path, $this->rules->getList( 'HiddenPaths' ) ),
            'aliases' => array(),
            'duplicate_candidate' => $this->isDuplicateCandidate( $path ),
            'bylines' => $bylines['fields'],
            'byline_failures' => $bylines['failures'],
        );
    }

    public function map( array $page )
    {
        $class = (string)$this->rules->get( 'DefaultClass', 'folder' );
        foreach ( $this->rules->getList( 'ClassRules' ) as $rule )
        {
            if ( strpos( $rule, ';' ) === false )
                continue;
            list( $pattern, $identifier ) = array_map( 'trim', explode( ';', $rule, 2 ) );
            if ( self::matches( $page['path'], $pattern ) )
            {
                $class = $identifier;
                break;
            }
        }
        // a page a type built itself (a comment of a page) may come with its class and parent
        $page['class'] = !empty( $page['class_fixed'] ) ? (string)$page['class_fixed'] : $class;
        $page['parent_path'] = array_key_exists( 'parent_fixed', $page ) ? (string)$page['parent_fixed'] : $this->parentPath( $page['path'] );
        $page['priority'] = isset( $page['priority'] ) ? (int)$page['priority'] : 0;
        // a routed page (RouteRules[]): its container and its own name, and the page it is a copy or view of
        if ( !array_key_exists( 'parent_fixed', $page ) && ( $route = $this->route( $page['path'] ) ) )
        {
            $page['title'] = $route['name'];
            $page['route_meta'] = $route['meta'];
            if ( $route['current'] !== '' )
                $page['history_of'] = $route['current'];
        }

        $fields = $this->fields( $page );
        $attributes = array();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
        {
            if ( strpos( $key, '/' ) === false )
                continue;
            list( $mapClass, $attribute ) = explode( '/', $key, 2 );
            if ( $mapClass !== $class && $mapClass !== '*' )
                continue;
            if ( isset( $fields[$field] ) )
                $attributes[$attribute] = $fields[$field];
        }
        $page['attributes'] = $attributes;
        return $page;
    }

    /**
     * The values a page offers to AttributeMap[]. body is filled by the engine (the XML), the others here.
     *
     * @param array $page
     * @return array
     */
    protected function fields( array $page )
    {
        $meta = isset( $page['meta'] ) ? $page['meta'] : array();
        return array(
            'title' => $page['title'],
            'short_title' => mb_strlen( $page['title'] ) > 60 ? mb_substr( $page['title'], 0, 57 ) . '...' : $page['title'],
            'body' => '{body}',
            'original_html' => isset( $page['original_html'] ) ? $page['original_html'] : '',
            'source_path' => $page['path'],
            'page_id' => isset( $meta['page_id'] ) ? (string)$meta['page_id'] : '',
            'author' => isset( $meta['author'] ) ? (string)$meta['author'] : '',
            'modified' => isset( $meta['modified'] ) ? (string)(int)$meta['modified'] : '',
            'labels' => isset( $meta['labels'] ) ? implode( ', ', $meta['labels'] ) : '',
            'description' => isset( $meta['description'] ) ? (string)$meta['description'] : '',
            'keywords' => isset( $meta['keywords'] ) ? implode( ', ', (array)$meta['keywords'] ) : '',
            'import_hash' => '{hash}',
            // the byline: contributors are objects of their own, the engine puts their ids in place of these
            'written_by' => isset( $page['bylines']['written']['name'] ) ? '{contributor:written}' : '',
            'written_at' => !empty( $page['bylines']['written']['time'] ) ? (string)$page['bylines']['written']['time'] : '',
            'last_updated_by' => isset( $page['bylines']['updated']['name'] ) ? '{contributor:updated}' : '',
            'last_updated_at' => !empty( $page['bylines']['updated']['time'] ) ? (string)$page['bylines']['updated']['time'] : '',
            'maintainers' => !empty( $page['bylines']['maintainers']['names'] ) ? '{contributors:maintainers}' : '',
            // every person the page names (writer, updater, maintainers, comment authors), each once
            'contributors' => !empty( $page['bylines'] ) && expStaticImportByline::people( (array)$page['bylines'] ) ? '{contributors:*}' : '',
            // the current page a historical copy, version or view belongs to (RouteRules[]), by its page id
            'history_of' => !empty( $page['history_of'] ) ? '{page:' . $page['history_of'] . '}' : '',
            'labels_tags' => self::tagsString( isset( $meta['labels'] ) ? (array)$meta['labels'] : array(), (string)$this->rules->get( 'TagParent', '0' ),
                                               (string)$this->rules->get( 'Language', 'eng-US' ) ),
            'metadata_xml' => self::metadataXml( $page['title'], isset( $meta['keywords'] ) ? (array)$meta['keywords'] : array(),
                                                 isset( $meta['description'] ) ? (string)$meta['description'] : '' ),
        );
    }

    /**
     * The labels as the eztags datatype's fromString() takes them: ids, keywords, parents and locales, each list
     * joined by "|#" and the four lists joined by "|#" too. New tags have the id 0 and go below $parent.
     *
     * @param array $labels
     * @param string $parent
     * @param string $locale
     * @return string
     */
    public static function tagsString( array $labels, $parent, $locale )
    {
        $labels = array_values( array_unique( array_filter( array_map( function ( $label ) {
            return trim( str_replace( array( '|#', ',' ), ' ', (string)$label ) );
        }, $labels ) ) ) );
        if ( !$labels )
            return '';
        $count = count( $labels );
        return implode( '|#', array_merge( array_fill( 0, $count, '0' ), $labels, array_fill( 0, $count, (string)$parent ), array_fill( 0, $count, (string)$locale ) ) );
    }

    /**
     * The page's metadata as the xrowmetadata datatype stores it.
     *
     * @param string $title
     * @param array $keywords
     * @param string $description
     * @return string
     */
    public static function metadataXml( $title, array $keywords, $description )
    {
        $e = function ( $text ) { return htmlspecialchars( (string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8' ); };
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<MetaData><title>' . $e( $title ) . '</title><keywords>' . $e( implode( ',', $keywords ) )
            . '</keywords><description>' . $e( $description ) . '</description><priority></priority><change></change><sitemap_use>1</sitemap_use></MetaData>';
    }

    /**
     * The parent of a page: the page its breadcrumb names (BreadcrumbSelector), else the nearest ancestor folder's own
     * page (<folder>.html next to the folder, or its index file, or its group page), '' at the top.
     *
     * @param string $path
     * @return string
     */
    public function parentPath( $path )
    {
        if ( isset( $this->routes[$path] ) )
            return $this->routes[$path]['container'];
        if ( isset( $this->breadcrumbParents[$path] ) )
            return $this->breadcrumbParents[$path];
        return $this->folderParent( $path );
    }

    /**
     * The parent of a page by its folder alone.
     *
     * @param string $path
     * @return string
     */
    protected function folderParent( $path )
    {
        $indexFiles = $this->rules->getList( 'IndexFiles' ) ?: array( 'index.html' );
        $dir = dirname( rtrim( $path, '/' ) );
        if ( in_array( basename( $path ), $indexFiles, true ) )
            $dir = dirname( $dir );
        while ( $dir !== '.' && $dir !== '' && $dir !== '/' )
        {
            $candidates = array( $dir . '.html' );
            foreach ( $indexFiles as $index )
                $candidates[] = $dir . '/' . $index;
            $candidates[] = $dir . '/';
            foreach ( $candidates as $candidate )
                if ( $candidate !== $path && isset( $this->known[$candidate] ) )
                    return $candidate;
            $dir = dirname( $dir );
        }
        return '';
    }

    public function assets( array $page )
    {
        $suffixes = $this->rules->getList( 'AssetSuffixes' ) ?: array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'pdf', 'zip' );
        $assets = array();
        if ( !preg_match_all( '/\b(?:src|href)=["\']([^"\']+)["\']/i', $page['body_html'], $m ) )
            return array();
        foreach ( $m[1] as $href )
        {
            $target = expStaticImportDom::resolve( $page['path'], $href );
            if ( $target !== null && in_array( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ), $suffixes, true ) )
                $assets[$target] = true;
        }
        return array_keys( $assets );
    }

    public function links( array $page )
    {
        $links = array();
        if ( !preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $page['body_html'], $m ) )
            return array();
        foreach ( $m[1] as $href )
        {
            $target = expStaticImportDom::resolve( $page['path'], $href );
            if ( $target !== null && preg_match( '/\.html?$/i', $target ) )
                $links[$href] = $target;
        }
        return $links;
    }

    /**
     * @param string $path
     * @return bool
     */
    protected function isDuplicateCandidate( $path )
    {
        foreach ( $this->rules->getList( 'DuplicatePatterns' ) as $pattern )
            if ( @preg_match( $pattern, $path ) )
                return true;
        return false;
    }

    /**
     * @param string $path
     * @param string $pattern a shell pattern; ** matches across folders
     * @return bool
     */
    public static function matches( $path, $pattern )
    {
        if ( strpos( $pattern, '**' ) !== false )
        {
            $regex = '#^' . str_replace( array( '\*\*', '\*', '\?' ), array( '.*', '[^/]*', '[^/]' ), preg_quote( $pattern, '#' ) ) . '$#';
            return (bool)preg_match( $regex, $path );
        }
        return fnmatch( $pattern, $path, FNM_PATHNAME );
    }

    /**
     * @param string $path
     * @param array $patterns
     * @return bool
     */
    public static function matchesAny( $path, array $patterns )
    {
        foreach ( $patterns as $pattern )
            if ( self::matches( $path, $pattern ) )
                return true;
        return false;
    }
}
