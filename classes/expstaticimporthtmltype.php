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

    /** @var array group path => its short title (its address), from RouteContainers[] or GroupShortTitles[] */
    protected $groupShort = array();

    /** @var array group path => array( meta key => value ), from the fourth part of RouteContainers[] */
    protected $groupValues = array();

    /** @var array path => array( 'container' => ..., 'name' => ..., 'meta' => array, 'current' => page id ) */
    protected $routes = array();

    /** @var string the bare history clone of the source repository (GitAuthors) */
    protected $historyDir = '';

    /** @var array|null path => array( 'first' => array( name, time ), 'last' => array( name, time ), 'names' => list ) */
    protected $gitAuthors = null;

    /**
     * @param string $dir
     */
    public function setHistoryDir( $dir )
    {
        $this->historyDir = (string)$dir;
        $this->gitAuthors = null;
    }

    /**
     * GitAuthors=enabled: the people who wrote and changed each page by the history of the source repository, for the
     * pages of GitAuthorPaths[] (shell patterns). GitAuthorMap[<e-mail or git name>]=<name> joins several git
     * identities into one person (only the name is used, never the e-mail); GitAuthorSkip[] names identities that are
     * no authors (bots). A page whose source has no byline gets the first author as written_by and the last as
     * last_updated_by, with the dates of those commits; every author is one of its contributors.
     *
     * @return array path => authors
     */
    public function gitAuthors()
    {
        if ( $this->gitAuthors !== null )
            return $this->gitAuthors;
        $this->gitAuthors = array();
        $patterns = $this->rules->getList( 'GitAuthorPaths' );
        if ( !$this->rules->enabled( 'GitAuthors', false ) || !$patterns || $this->historyDir === '' || !is_dir( $this->historyDir ) )
            return $this->gitAuthors;
        $map = array_change_key_case( $this->rules->getHash( 'GitAuthorMap' ), CASE_LOWER );
        $skip = array_map( 'strtolower', $this->rules->getList( 'GitAuthorSkip' ) );
        $specs = '';
        foreach ( $patterns as $pattern )
            $specs .= ' ' . escapeshellarg( ':(glob)' . $pattern );
        $command = 'git -c core.quotepath=off -C ' . escapeshellarg( $this->historyDir ) . ' log --no-renames --name-only --format=' . escapeshellarg( '@%at|%an|%ae' ) . ' HEAD --' . $specs . ' 2>/dev/null';
        $handle = popen( $command, 'r' );
        if ( !$handle )
            return $this->gitAuthors;
        $commit = null;
        while ( ( $line = fgets( $handle ) ) !== false )
        {
            $line = rtrim( $line, "\r\n" );
            if ( $line === '' )
                continue;
            if ( $line[0] === '@' && substr_count( $line, '|' ) >= 2 )
            {
                list( $time, $name, $mail ) = explode( '|', substr( $line, 1 ), 3 );
                $person = isset( $map[strtolower( $mail )] ) ? $map[strtolower( $mail )] : ( isset( $map[strtolower( $name )] ) ? $map[strtolower( $name )] : $name );
                $commit = in_array( strtolower( $mail ), $skip, true ) || in_array( strtolower( $name ), $skip, true ) ? null : array( trim( $person ), (int)$time );
                continue;
            }
            if ( !$commit )
                continue;
            // newest first: the first commit seen is the last change, the last one seen the first
            if ( !isset( $this->gitAuthors[$line] ) )
                $this->gitAuthors[$line] = array( 'last' => $commit, 'first' => $commit, 'names' => array() );
            $this->gitAuthors[$line]['first'] = $commit;
            if ( !in_array( $commit[0], $this->gitAuthors[$line]['names'], true ) )
                $this->gitAuthors[$line]['names'][] = $commit[0];
        }
        pclose( $handle );
        return $this->gitAuthors;
    }

    /** @var string the source folder of the last discover() */
    protected $sourceDir = '';

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
        $this->breadcrumbParents = $this->groups = $this->groupShort = $this->groupValues = array();
        $this->sourceDir = rtrim( $source, '/' );
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
                // GroupShortTitles[<shell pattern of the folder>]=<pattern>: the group's short title (its address),
                // e.g. display/*={file} for a Confluence space addressed by its key
                foreach ( $this->rules->getHash( 'GroupShortTitles' ) as $pattern => $short )
                    if ( self::matches( $folder, (string)$pattern ) )
                    {
                        $short = self::fillStrict( (string)$short, $this->pathValues( $folder . '/' ) );
                        if ( $short !== null && trim( $short ) !== '' )
                            $this->groupShort[$folder . '/'] = trim( $short );
                        break;
                    }
                $paths[] = $folder . '/';
            }
        }
        $paths = $this->readRoutes( $source, $paths );
        $paths = $this->readVirtualPages( $paths );
        $paths = $this->addParentContainers( $paths );
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
    /**
     * VirtualPages[<path ending in />]=<class>;<title>;<short title>;<body file>: a section page the source does not
     * have (a product's landing page), or new values for a group page (GroupFolders[]). Its parent comes from the path,
     * ParentRules[] or TopParentRules[]; its address from the short title (the name pattern of the class); its text is
     * the HTML file (a path below the installation's root), if one is given.
     *
     * @param array $paths
     * @return array the paths, with the virtual pages added
     */
    protected function readVirtualPages( array $paths )
    {
        $this->virtualPages = array();
        foreach ( $this->rules->getHash( 'VirtualPages' ) as $path => $value )
        {
            $path = rtrim( (string)$path, '/' ) . '/';
            list( $class, $title, $short, $body ) = array_pad( array_map( 'trim', explode( ';', (string)$value, 4 ) ), 4, '' );
            if ( $title === '' )
                continue;
            if ( !isset( $this->groups[$path] ) )
                $paths[] = $path;
            $this->groups[$path] = $title;
            if ( $class !== '' )
                $this->groupClasses[$path] = $class;
            $this->virtualPages[$path] = array( 'short_title' => $short, 'body' => $body );
        }
        return $paths;
    }

    /** @var array path => array( 'short_title', 'body' ) of VirtualPages[] */
    protected $virtualPages = array();

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
                // the container and the containers above it (a container of one original page is a history of that
                // page too)
                $paths = $this->addContainer( $container, $meta, $paths, $this->routes[$path]['current'] );
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
     * Like fill(), but null when a placeholder has no value (a rule that cannot be applied to this page).
     *
     * @param string $pattern
     * @param array $values
     * @return string|null
     */
    public static function fillStrict( $pattern, array $values )
    {
        $missing = false;
        $out = preg_replace_callback( '/\{([a-z0-9_|]+)\}/i', function ( $m ) use ( $values, &$missing ) {
            foreach ( explode( '|', $m[1] ) as $key )
                if ( isset( $values[$key] ) && trim( (string)$values[$key] ) !== '' )
                    return trim( (string)$values[$key] );
            $missing = true;
            return '';
        }, (string)$pattern );
        return $missing ? null : $out;
    }

    /**
     * The values a path offers to the patterns of ParentRules[], TopParentRules[], NameRules[], GroupShortTitles[]
     * and RouteContainers[]: {path}; {file} (the last part without its page suffix); {dir} (the folder); {seg1},
     * {seg2} ... (the parts of the path); {folder} (the folder a renamed folder page stands for, e.g. 4.7 for
     * Technical-manual/4.html; empty for other pages).
     *
     * @param string $path
     * @return array
     */
    public function pathValues( $path )
    {
        $trimmed = rtrim( (string)$path, '/' );
        $dir = dirname( $trimmed );
        $suffixes = $this->rules->getList( 'Suffixes' ) ?: array( 'html', 'htm' );
        $file = basename( $trimmed );
        foreach ( $suffixes as $suffix )
            if ( substr( strtolower( $file ), -strlen( $suffix ) - 1 ) === '.' . strtolower( $suffix ) )
            {
                $file = substr( $file, 0, -strlen( $suffix ) - 1 );
                break;
            }
        $values = array( 'path' => (string)$path, 'file' => $file, 'dir' => $dir === '.' ? '' : $dir, 'folder' => $this->folderOf( $path ) );
        foreach ( explode( '/', $trimmed ) as $i => $segment )
            $values['seg' . ( $i + 1 )] = $segment;
        return $values;
    }

    /**
     * @param string $path
     * @return string the name of the folder the page stands for, '' when it is no folder page (see the doc-html type)
     */
    protected function folderOf( $path )
    {
        return '';
    }

    /**
     * The spec of RouteContainers[] that matches a container path, first match wins:
     *   RouteContainers[<container path, * for one folder>]=<class>;<title pattern>[;<short title pattern>[;<key>=<pattern>,...]]
     * The short title is the container's address (the name pattern of the class decides what is its name); the
     * key=pattern pairs are values of the container page (e.g. version={folder}, which AttributeMap[] can use).
     *
     * @param string $dir
     * @return array|null array( class, title pattern, short title pattern, fields )
     */
    protected function containerSpec( $dir )
    {
        foreach ( $this->rules->getHash( 'RouteContainers' ) as $key => $value )
            if ( self::matches( rtrim( (string)$dir, '/' ), rtrim( (string)$key, '/' ) ) )
                return array_pad( array_map( 'trim', explode( ';', (string)$value, 4 ) ), 4, '' );
        return null;
    }

    /**
     * @param string $fields "<key>=<pattern>,<key>=<pattern>"
     * @param array $values
     * @return array key => value
     */
    protected static function containerValues( $fields, array $values )
    {
        $out = array();
        foreach ( array_filter( array_map( 'trim', explode( ',', (string)$fields ) ) ) as $pair )
        {
            list( $key, $pattern ) = array_pad( array_map( 'trim', explode( '=', $pair, 2 ) ), 2, '' );
            $value = $key !== '' ? trim( self::fill( $pattern, $values ) ) : '';
            if ( $value !== '' )
                $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Makes a container (a group page, a path ending in "/") and the containers above it, each by its spec in
     * RouteContainers[]; a folder of the path that has no spec is left out (its parent comes from the other rules). A
     * container that exists already (a virtual page) only takes the values of its spec it does not have.
     *
     * @param string $container
     * @param array $values the values of the page that needs it, for the patterns
     * @param array $paths
     * @param string $current the page id of the page the container is a history of
     * @return array the paths, with the new containers added
     */
    public function addContainer( $container, array $values, array $paths, $current = '' )
    {
        $container = rtrim( (string)$container, '/' ) . '/';
        for ( $dir = $container; $dir !== '/' && $dir !== './' && $dir !== ''; $dir = ( dirname( rtrim( $dir, '/' ) ) === '.' ? '' : dirname( rtrim( $dir, '/' ) ) . '/' ) )
        {
            $spec = $this->containerSpec( $dir );
            if ( isset( $this->groups[$dir] ) )
            {
                if ( $spec && empty( $this->groupValues[$dir] ) )
                    $this->groupValues[$dir] = self::containerValues( $spec[3], $values );
                continue;
            }
            if ( !$spec )
                continue;
            list( $class, $title, $short, $fields ) = $spec;
            $title = trim( self::fill( $title, $values ) );
            $this->groups[$dir] = $title !== '' ? $title : basename( rtrim( $dir, '/' ) );
            if ( $class !== '' )
                $this->groupClasses[$dir] = $class;
            $short = trim( self::fill( $short, $values ) );
            if ( $short !== '' )
                $this->groupShort[$dir] = $short;
            $this->groupValues[$dir] = self::containerValues( $fields, $values );
            $this->groupMeta[$dir] = $dir === $container ? (string)$current : '';
            $paths[] = $dir;
        }
        return $paths;
    }

    /**
     * The containers ParentRules[] with placeholders send pages into (e.g. "Exponential/*\/[0-9]*.html;Exponential/{folder}/":
     * every version page below a container of its version), made by RouteContainers[].
     *
     * @param array $paths
     * @return array the paths, with the new containers added
     */
    protected function addParentContainers( array $paths )
    {
        $rules = $this->rules->getList( 'ParentRules' );
        if ( !$rules || !$this->rules->getHash( 'RouteContainers' ) )
            return $paths;
        $known = array_fill_keys( $paths, true );
        foreach ( $paths as $path )
        {
            foreach ( $rules as $rule )
            {
                list( $pattern, $parent ) = array_pad( array_map( 'trim', explode( ';', $rule, 2 ) ), 2, '' );
                if ( $pattern === '' || $parent === '' || $parent[0] === '@' || !self::matches( $path, $pattern ) )
                    continue;
                $values = $this->pathValues( $path );
                $filled = self::fillStrict( $parent, $values );
                if ( $filled === null || $filled === $path )
                    continue;
                // a container that is not there yet, or a group page (a virtual page) that takes the values of its spec
                if ( substr( $filled, -1 ) === '/' && ( !isset( $known[$filled] ) || isset( $this->groups[$filled] ) ) )
                {
                    $before = count( $paths );
                    $paths = $this->addContainer( $filled, $values, $paths );
                    foreach ( array_slice( $paths, $before ) as $added )
                        $known[$added] = true;
                }
                break;
            }
        }
        return $paths;
    }

    /**
     * The parent a rule of ParentRules[] or TopParentRules[] names, for one page: its placeholders filled from the
     * page's path (pathValues()); "@<remote id>" is a node outside the import (e.g. the media folder). '' when the
     * rule cannot be used (a placeholder without a value, the page itself, a parent the import does not know).
     *
     * @param string $parent
     * @param string $path
     * @return string
     */
    protected function ruleParent( $parent, $path )
    {
        if ( strpos( $parent, '{' ) !== false )
        {
            $parent = self::fillStrict( $parent, $this->pathValues( $path ) );
            if ( $parent === null )
                return '';
        }
        if ( $parent === '' || $parent === $path )
            return '';
        if ( $parent[0] === '@' )
            return $parent;
        return isset( $this->known[$parent] ) || isset( $this->groups[$parent] ) ? $parent : '';
    }

    /**
     * NameRules[]=<shell pattern>;<title pattern>[;<short title pattern>]: the title (and short title, the address)
     * a page gets instead of its own, first match wins; the patterns take the values of pathValues(), {title} (the
     * page's own title) and the page's meta values. A rule whose placeholders have no value is passed over.
     *
     * @param string $path
     * @param array $values more values ({title}, meta)
     * @return array|null array( title, short title ('' to keep the default) )
     */
    public function nameRule( $path, array $values = array() )
    {
        $rules = $this->rules->getList( 'NameRules' );
        if ( !$rules )
            return null;
        $values = $this->pathValues( $path ) + $values;
        foreach ( $rules as $rule )
        {
            list( $pattern, $title, $short ) = array_pad( array_map( 'trim', explode( ';', $rule, 3 ) ), 3, '' );
            if ( $pattern === '' || $title === '' || !self::matches( $path, $pattern ) )
                continue;
            $title = self::fillStrict( $title, $values );
            $short = $short === '' ? '' : self::fillStrict( $short, $values );
            if ( $title === null || $short === null || trim( $title ) === '' )
                continue;
            return array( trim( $title ), trim( $short ) );
        }
        return null;
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
     * @return bool whether the path is a page of the source (or a group page) as the last discover() found it
     */
    public function isKnown( $path )
    {
        return isset( $this->known[$path] );
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
        // GroupBodyFrom[<folder>]=<source page>: the group's text is that page's body (the landing page of a section,
        // e.g. the Confluence documentation center's start page for the section of all spaces)
        $from = $this->rules->getHash( 'GroupBodyFrom' );
        $folder = rtrim( $path, '/' );
        if ( isset( $from[$folder] ) && $this->sourceDir !== '' && is_file( $this->sourceDir . '/' . $from[$folder] ) )
        {
            $groups = $this->groups;
            unset( $this->groups[$from[$folder]] );
            $source = $this->parse( $this->sourceDir, (string)$from[$folder] );
            $this->groups = $groups;
            if ( is_array( $source ) && isset( $source['body_html'] ) && empty( $source['redirect_to'] ) && empty( $source['asset_of'] ) )
            {
                $page['body_html'] = $source['body_html'];
                $page['original_html'] = isset( $source['original_html'] ) ? $source['original_html'] : '';
                $page['meta']['description'] = isset( $source['meta']['description'] ) ? $source['meta']['description'] : '';
            }
        }
        // a virtual page (VirtualPages[]): its short title (its address) and its text from a file
        if ( isset( $this->virtualPages[$path] ) )
        {
            if ( $this->virtualPages[$path]['short_title'] !== '' )
                $page['short_title'] = $this->virtualPages[$path]['short_title'];
            $file = $this->virtualPages[$path]['body'];
            if ( $file !== '' && is_file( $file ) )
            {
                $page['body_html'] = trim( (string)file_get_contents( $file ) );
                $page['original_html'] = $page['body_html'];
                $page['meta']['description'] = mb_substr( trim( preg_replace( '/\s+/u', ' ', strip_tags( preg_replace( '#<h[1-6][^>]*>.*?</h[1-6]>#s', '', $page['body_html'] ) ) ) ), 0, 300 );
            }
        }
        // the short title (the address) and the values RouteContainers[] or GroupShortTitles[] give the group
        if ( empty( $page['short_title'] ) && isset( $this->groupShort[$path] ) )
            $page['short_title'] = $this->groupShort[$path];
        if ( !empty( $this->groupValues[$path] ) )
            $page['meta'] = array_merge( $this->groupValues[$path], $page['meta'] );
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

    /**
     * CopyOfPatterns[]: regular expressions on the path whose first group is the address of the page it copies (e.g.
     * "#^(.*)/\([a-z_]+\)/[^/]+\.html$#" for ".../(language)/eng-GB.html"). The page of that address is its .html, its
     * index file, its group page, or the page its folder belongs to (the folder page of a renamed version folder).
     *
     * @param string $path
     * @return string the page it copies, '' for none
     */
    public function copyOf( $path )
    {
        foreach ( $this->rules->getList( 'CopyOfPatterns' ) as $pattern )
        {
            if ( !@preg_match( $pattern, $path, $m ) || !isset( $m[1] ) )
                continue;
            $address = trim( $m[1], '/' );
            $page = $address === '' ? '' : $this->pageOfAddress( $address );
            if ( $page !== '' && isset( $this->known[$page] ) && $page !== $path )
                return $page;
            $parent = $this->parentPath( $address . '/' . basename( $path ) );
            return $parent !== $path ? $parent : '';
        }
        return '';
    }

    public function parse( $source, $path )
    {
        if ( isset( $this->groups[$path] ) )
            return $this->groupPage( $path );
        // CopyOfPatterns[]: a copy of a page under another address (a view parameter such as "(language)/eng-GB"),
        // whatever its content, is a redirect to that page, never a page of its own
        if ( ( $canonical = $this->copyOf( $path ) ) !== '' )
            return array( 'path' => $path, 'title' => '', 'body_html' => '', 'original_html' => '', 'meta' => array(),
                          'hidden' => false, 'aliases' => array(), 'redirect_to' => $canonical );
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
        $titleSelector = (string)$this->rules->get( 'TitleSelector', 'h1' );
        $titleNode = null;
        // TitleSelectorScope=document: each alternative of the selector in its order, in the whole page (a heading
        // outside the body, such as Confluence's #title-text, before the first heading of the text); default: the
        // body, the first match in document order
        if ( (string)$this->rules->get( 'TitleSelectorScope', 'body' ) === 'document' )
        {
            foreach ( explode( ',', $titleSelector ) as $alternative )
                if ( trim( $alternative ) !== '' && ( $titleNode = expStaticImportDom::first( $doc, trim( $alternative ) ) ) && expStaticImportDom::text( $titleNode ) !== '' )
                    break;
                else
                    $titleNode = null;
        }
        else
            $titleNode = expStaticImportDom::first( $doc, $titleSelector, $body );
        $inBody = false;
        for ( $parent = $titleNode; $parent; $parent = $parent->parentNode )
            if ( $parent === $body )
            {
                $inBody = true;
                break;
            }
        if ( $titleNode )
        {
            $title = expStaticImportDom::text( $titleNode );
            if ( $this->rules->enabled( 'RemoveTitleFromBody', true ) && $titleNode->parentNode && $inBody )
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
        // the people of the page's git history (GitAuthors): writer and updater where the source names none, and
        // every author a contributor (the list field git_authors)
        $git = $this->gitAuthors();
        if ( isset( $git[$path] ) )
        {
            if ( empty( $bylines['fields']['written']['name'] ) )
                $bylines['fields']['written'] = array( 'name' => $git[$path]['first'][0], 'time' => $git[$path]['first'][1] );
            if ( empty( $bylines['fields']['updated']['name'] ) )
                $bylines['fields']['updated'] = array( 'name' => $git[$path]['last'][0], 'time' => $git[$path]['last'][1] );
            $bylines['fields']['git_authors'] = array( 'names' => $git[$path]['names'] );
        }
        if ( isset( $bylines['fields']['written']['name'] ) )
            $meta['author'] = $bylines['fields']['written']['name'];
        if ( !empty( $bylines['fields']['updated']['time'] ) )
            $meta['modified'] = $bylines['fields']['updated']['time'];

        $cleaner = new expStaticImportHtmlCleaner( $this->rules );
        $bodyHtml = $cleaner->clean( $doc, $body );

        // NormalizeHeadings: the highest heading of the text becomes h1 and the others move up with it, since the
        // page's own title is no heading of the body (an h2 below the title is the first section of the XML, which
        // the designs draw one level below the title)
        if ( $this->rules->enabled( 'NormalizeHeadings', false ) && preg_match_all( '/<h([1-6])\b/i', $bodyHtml, $levels ) )
        {
            $shift = min( array_map( 'intval', $levels[1] ) ) - 1;
            if ( $shift > 0 )
                $bodyHtml = preg_replace_callback( '/<(\/?)h([1-6])\b/i', function ( $m ) use ( $shift ) {
                    return '<' . $m[1] . 'h' . max( 1, (int)$m[2] - $shift );
                }, $bodyHtml );
        }

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
        // NameRules[]: a title (and an address) of the rules instead of the page's own (a manual's version page below
        // a version container: "Technical manual 4.7", addressed technical-manual)
        if ( !array_key_exists( 'parent_fixed', $page ) && !isset( $this->groups[$page['path']] ) && !$this->route( $page['path'] ) )
        {
            $values = array( 'title' => $page['title'] );
            foreach ( isset( $page['meta'] ) ? (array)$page['meta'] : array() as $key => $value )
                if ( is_scalar( $value ) && !isset( $values[$key] ) )
                    $values[$key] = (string)$value;
            if ( $named = $this->nameRule( $page['path'], $values ) )
            {
                $page['title'] = $named[0];
                if ( $named[1] !== '' )
                    $page['short_title'] = $named[1];
            }
        }

        $fields = $this->fields( $page );
        $attributes = array();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
        {
            if ( strpos( $key, '/' ) === false )
                continue;
            list( $mapClass, $attribute ) = explode( '/', $key, 2 );
            if ( $mapClass !== $page['class'] && $mapClass !== '*' )
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
            'short_title' => !empty( $page['short_title'] ) ? (string)$page['short_title'] : ( mb_strlen( $page['title'] ) > 60 ? mb_substr( $page['title'], 0, 57 ) . '...' : $page['title'] ),
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
        // a node outside the import ("@<remote id>") is a top of its own
        if ( $path === '' || $path[0] === '@' )
            return '';
        if ( isset( $this->routes[$path] ) )
            return $this->routes[$path]['container'];
        // ParentRules[]=<shell pattern>;<parent path>: a fixed parent before any other rule (a space below its product).
        // The parent may take placeholders ({folder}, {file}, {seg2} ...: pathValues()), a container of RouteContainers[]
        // is made for it; "@<remote id>" is a node outside the import. A rule that cannot be used is passed over.
        foreach ( $this->rules->getList( 'ParentRules' ) as $rule )
        {
            list( $pattern, $parent ) = array_pad( array_map( 'trim', explode( ';', $rule, 2 ) ), 2, '' );
            if ( $pattern !== '' && $parent !== '' && self::matches( $path, $pattern ) && ( $target = $this->ruleParent( $parent, $path ) ) !== '' )
                return $target;
        }
        $parent = isset( $this->breadcrumbParents[$path] ) ? $this->breadcrumbParents[$path] : $this->folderParent( $path );
        return $parent === '' ? $this->topParent( $path ) : $parent;
    }

    /**
     * TopParentRules[]=<shell pattern>;<parent path>: where a page goes that would be a top page (no folder page, no
     * breadcrumb). The first rule that matches wins; an empty parent keeps the page at the top. With a last rule
     * "**;<page>" nothing but the pages named before it stays at the top, so the top node keeps few children.
     *
     * @param string $path
     * @return string
     */
    protected function topParent( $path )
    {
        foreach ( $this->rules->getList( 'TopParentRules' ) as $rule )
        {
            list( $pattern, $parent ) = array_pad( array_map( 'trim', explode( ';', $rule, 2 ) ), 2, '' );
            if ( $pattern === '' || !self::matches( $path, $pattern ) )
                continue;
            return $parent === '' ? '' : $this->ruleParent( $parent, $path );
        }
        return '';
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
        $hosts = array_map( 'strtolower', $this->rules->getList( 'LinkHosts' ) );
        $suffixes = $this->rules->getList( 'AssetSuffixes' ) ?: array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'pdf', 'zip' );
        foreach ( array_unique( $m[1] ) as $href )
        {
            $decoded = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
            // a link by page id (Confluence: viewpage.action?pageId=123, or any href with pageId=): that page's object
            if ( preg_match( '/[?&]pageId=(\d+)/', $decoded, $p ) && !preg_match( '/^[a-z][a-z0-9+.-]*:/i', $decoded ) )
            {
                $links[$href] = 'page:' . $p[1];
                continue;
            }
            // a full URL of the site itself (LinkHosts[]): its path, from the top
            if ( $hosts && preg_match( '#^https?://([^/:]+)(?::\d+)?(/[^?\#]*)?#i', $decoded, $u ) && in_array( strtolower( $u[1] ), $hosts, true ) )
                $decoded = '/' . ltrim( isset( $u[2] ) ? $u[2] : '', '/' ) . ( strpos( $decoded, '#' ) !== false ? substr( $decoded, strpos( $decoded, '#' ) ) : '' );
            $target = expStaticImportDom::resolve( $page['path'], $decoded );
            if ( $target === null )
                continue;
            $extension = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
            if ( preg_match( '/\.html?$/i', $target ) || in_array( $extension, $suffixes, true ) )
                $links[$href] = $target;
            // a folder or an address without a suffix: its page, its index file or the group page of the folder
            else if ( !preg_match( '/^[a-z]{2,5}$/', $extension ) )
                $links[$href] = $this->pageOfAddress( $target );
        }
        return $links;
    }

    /**
     * The page an address without a suffix stands for: <address>.html, <address>/<index file>, or the group page.
     *
     * @param string $target
     * @return string
     */
    protected function pageOfAddress( $target )
    {
        $target = rtrim( $target, '/' );
        $candidates = array( $target . '.html' );
        foreach ( $this->rules->getList( 'IndexFiles' ) ?: array( 'index.html' ) as $index )
            $candidates[] = ( $target === '' ? '' : $target . '/' ) . $index;
        $candidates[] = $target . '/';
        foreach ( $candidates as $candidate )
            if ( isset( $this->known[$candidate] ) )
                return $candidate;
        return $candidates[0];
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
