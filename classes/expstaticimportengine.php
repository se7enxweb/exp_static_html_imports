<?php
/**
 * File containing the expStaticImportEngine class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Runs one import: everything that is the same whatever the source. The type (expStaticImportTypeInterface) says what
 * a page is; the engine turns pages into content objects and keeps them in step with the source on every later run.
 *
 * Phases, in this order, each resumable (the position is saved after every batch):
 *   source     clone the source repository into the work folder, or pull it (SourceRepository, SourceBranch)
 *   discover   the type lists the pages, parents first
 *   pages      each page parsed, mapped and written: created, updated, unchanged, flagged (an editor changed it since
 *              the last import: never overwritten), duplicate (the same content as a page already imported: it
 *              becomes a redirect to that page) or failed (skipped, "needs review", its HTML kept in the report)
 *   assets     the images and files the pages use, as objects of the media classes
 *   links      the links between pages and to media turned into node links and embeds
 *   redirects  every page's old path, and every duplicate's, answers with a redirect to its node
 *   removals   a page that left the source is hidden and reported; with --apply-removals it goes to the trash
 *
 * Identity: an object's remote id is "staticimport-<type>-<md5 of its source path>", so a page is found again on
 * every run whatever happened to the tree. Edit detection: the import stores the hash of what it wrote (state file,
 * and the class's import_hash attribute when it has one); when the object's current content no longer has that
 * hash, an editor changed it, and the page is flagged and skipped.
 *
 * Settings: statichtmlimport.ini [ImportSettings] and the type's [Type_<label>]:
 *   ParentNode (node id) or ParentRemoteID   where the import's top pages go
 *   MediaParentNode / MediaParentRemoteID    where the media go (default: ParentNode)
 *   SectionMap[<class>]=<section identifier>, HiddenSection   sections by class, and for hidden pages
 *   ImportUser                               the login the objects are created as (owner of the versions)
 *   Language                                 the language of the imported content (default: the top language)
 *   AssetClassRules[]                        <shell pattern>;<class> for media files
 *   AssetAttributeMap[<class>/<attribute>]   file, alt, caption, credit, source_path, title
 *   BatchSize                                pages per batch (default 50)
 */
class expStaticImportEngine
{
    const SCHEMA = 'exp-static-import/1';

    /** @var expStaticImportTypeInterface */
    protected $type;

    /** @var string */
    protected $label;

    /** @var expStaticImportRules */
    protected $rules;

    /** @var array */
    protected $options;

    /** @var expStaticImportState */
    protected $state;

    /** @var string */
    protected $workDir;

    /** @var string */
    protected $source;

    /** @var callable|null receives (string $event, array $data) */
    protected $progress;

    /** @var array the run's counters and lists */
    protected $report;

    /** @var array source content hash => path of the page imported with it */
    protected $seenHashes = array();

    /** @var array asset path => true, the media the pages use */
    protected $assetPaths = array();

    /** @var array asset path => what the page that showed it said of it (alt) */
    protected $assetMeta = array();

    /** @var array page path => array of href => target path, links still to turn into node links */
    protected $pendingLinks = array();

    /**
     * @param string $label the import type (doc-html, html-generic, markdown, sitemap-crawl)
     * @param array $options source, dry_run, apply_removals, batch, limit, paths (shell patterns), resume, restart,
     *                       pull (default true), user, parent_node, language
     * @param callable|null $progress
     */
    public function __construct( $label, array $options = array(), $progress = null )
    {
        $this->label = ltrim( (string)$label, '-' );
        $this->type = expStaticImportRegistry::create( $this->label );
        $this->rules = expStaticImportRules::load( $this->label );
        $this->options = array_merge( array( 'source' => '', 'dry_run' => false, 'apply_removals' => false, 'batch' => 0,
                                             'limit' => 0, 'paths' => array(), 'resume' => true, 'restart' => false,
                                             'pull' => true, 'user' => '', 'parent_node' => 0, 'language' => '', 'overwrite_flagged' => false ), $options );
        $this->progress = $progress;
        $this->workDir = self::workDir( $this->label );
        $this->state = new expStaticImportState( $this->workDir . '/state.json' );
        if ( method_exists( $this->type, 'setWorkDir' ) )
            $this->type->setWorkDir( $this->workDir );
        if ( method_exists( $this->type, 'setHistoryDir' ) )
            $this->type->setHistoryDir( $this->workDir . '/history.git' );
    }

    /**
     * @return expStaticImportTypeInterface the type of this import
     */
    public function type()
    {
        return $this->type;
    }

    /**
     * @param string $label
     * @return string var/<site>/static-import/<label>
     */
    public static function workDir( $label )
    {
        return eZSys::rootDir() . '/' . eZSys::varDirectory() . '/static-import/' . preg_replace( '/[^a-z0-9_-]/i', '', (string)$label );
    }

    /**
     * @return array the report
     */
    public function run()
    {
        $started = microtime( true );
        $run = $this->state->data['run'];
        $resume = $this->options['resume'] && !$this->options['restart'] && is_array( $run ) && !$this->options['dry_run'];
        if ( !$resume )
            $run = array( 'id' => date( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 ), 'phase' => 'source', 'position' => 0,
                          'started' => time(), 'counts' => array() );
        $this->report = array( 'schema' => self::SCHEMA, 'type' => $this->label, 'run' => $run['id'], 'dry_run' => (bool)$this->options['dry_run'],
                               'resumed' => $resume, 'counts' => array_merge( array( 'pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
                                   'flagged' => 0, 'failed' => 0, 'duplicates' => 0, 'hidden' => 0, 'assets' => 0, 'links' => 0, 'redirects' => 0,
                                   'removed' => 0 ), isset( $run['counts'] ) ? $run['counts'] : array() ),
                               'flagged' => array(), 'failed' => array(), 'removed' => array(), 'byline_failures' => array(), 'warnings' => array() );
        $this->audit( 'system.staticimport_run.start', array( 'run' => $run['id'], 'resumed' => $resume ) );
        if ( !$this->options['dry_run'] )
            $this->applyStatePatches();

        $user = $this->actAs();
        $deferred = $this->deferWork();
        $this->source = $this->prepareSource();
        $this->emit( 'source', array( 'source' => $this->source ) );

        $paths = $this->type->discover( $this->source );
        // every page of the source is known when a page's parent is looked for, also in a run of only some paths
        // (--path, --limit): a parent outside the run is still the parent, never the top
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        if ( $this->options['paths'] )
            $paths = array_values( array_filter( $paths, function ( $path ) {
                return expStaticImportHtmlType::matchesAny( $path, (array)$this->options['paths'] );
            } ) );
        // the paths that may be copies (DuplicatePatterns[]) come after the others, so the canonical page is the one
        // imported and the copies become its redirects; the order within each part stays parents-first
        $patterns = $this->rules->getList( 'DuplicatePatterns' );
        if ( $patterns )
        {
            $canonical = $copies = array();
            foreach ( $paths as $path )
            {
                $copy = false;
                foreach ( $patterns as $pattern )
                    if ( @preg_match( $pattern, $path ) )
                    {
                        $copy = true;
                        break;
                    }
                if ( $copy )
                    $copies[] = $path;
                else
                    $canonical[] = $path;
            }
            $paths = array_merge( $canonical, $copies );
        }
        if ( $this->options['limit'] > 0 )
            $paths = array_slice( $paths, 0, (int)$this->options['limit'] );
        $this->report['counts']['pages'] = count( $paths );
        $this->emit( 'discover', array( 'pages' => count( $paths ) ) );

        foreach ( $this->state->data['pages'] as $path => $entry )
            if ( isset( $entry['source_hash'], $entry['status'] ) && $entry['status'] !== 'duplicate' && $entry['status'] !== 'removed' )
                $this->seenHashes[$entry['source_hash']] = $path;

        $batch = $this->batchSize();
        $phases = array( 'pages', 'assets', 'links', 'redirects', 'removals' );
        $phaseIndex = array_search( $run['phase'], $phases, true );
        if ( $phaseIndex === false )
            $phaseIndex = 0;
        foreach ( array_slice( $phases, $phaseIndex ) as $phase )
        {
            $position = $run['phase'] === $phase ? (int)$run['position'] : 0;
            $run['phase'] = $phase;
            switch ( $phase )
            {
                case 'pages':
                    for ( $i = $position, $n = count( $paths ); $i < $n; $i += $batch )
                    {
                        foreach ( array_slice( $paths, $i, $batch ) as $path )
                            $this->importPage( $path, $user );
                        $this->checkpoint( $run, $phase, min( $i + $batch, $n ) );
                        $this->emit( 'pages', array( 'done' => min( $i + $batch, $n ), 'of' => $n, 'counts' => $this->report['counts'] ) );
                    }
                    break;
                case 'assets':
                    // the media of the pages of this run, and those an interrupted run had found before it stopped
                    $assets = array_keys( $this->assetPaths + (array)( isset( $this->state->data['pending_assets'] ) ? $this->state->data['pending_assets'] : array() ) );
                    sort( $assets );
                    for ( $i = $position, $n = count( $assets ); $i < $n; $i += $batch )
                    {
                        foreach ( array_slice( $assets, $i, $batch ) as $asset )
                            $this->importAsset( $asset, $user );
                        $this->checkpoint( $run, $phase, min( $i + $batch, $n ) );
                        $this->emit( 'assets', array( 'done' => min( $i + $batch, $n ), 'of' => $n ) );
                    }
                    break;
                case 'links':
                    $pending = array_keys( $this->pendingLinks ?: (array)( isset( $this->state->data['pending_links'] ) ? $this->state->data['pending_links'] : array() ) );
                    for ( $i = $position, $n = count( $pending ); $i < $n; $i += $batch )
                    {
                        foreach ( array_slice( $pending, $i, $batch ) as $path )
                            $this->relinkPage( $path, $user );
                        $this->checkpoint( $run, $phase, min( $i + $batch, $n ) );
                    }
                    break;
                case 'redirects':
                    if ( !$this->options['dry_run'] && $this->rules->enabled( 'CreateRedirects', true ) )
                        $this->createRedirects( $paths );
                    $this->checkpoint( $run, $phase, 0 );
                    break;
                case 'removals':
                    if ( !$this->options['paths'] && !$this->options['limit'] )
                        $this->handleRemovals( $paths );
                    break;
            }
        }

        $this->finishDeferredWork( $deferred );
        $this->report['seconds'] = round( microtime( true ) - $started, 1 );
        $this->report['finished'] = date( 'c' );
        if ( !$this->options['dry_run'] )
        {
            $this->state->data['run'] = null;
            $this->state->data['pending_links'] = array();
            $this->state->data['pending_assets'] = array();
            $this->state->addRun( array( 'id' => $run['id'], 'finished' => time(), 'seconds' => $this->report['seconds'], 'counts' => $this->report['counts'] ) );
            $this->state->save();
        }
        $this->writeReports();
        $this->audit( 'system.staticimport_run.finish', array( 'run' => $run['id'], 'counts' => $this->report['counts'] ) );
        $this->emit( 'done', $this->report );
        return $this->report;
    }

    /**
     * Hides the nodes of pages an earlier run imported that are no pages by the type's rules today (error pages,
     * files that are no HTML, the viewers of one media file): exp:static-import:hide-non-pages. It reads the source as
     * it is (no pull) and changes nothing but the visibility of those nodes: not the state file, so it is safe while a
     * run of the type is going on, which may save the state at any time. The next run records them as removed and
     * imports the media of the viewers. A node with visible children is left alone and reported.
     *
     * @param array $patterns shell patterns of the source paths to look at (empty: all)
     * @param bool $dryRun only report
     * @return array( 'checked' => n, 'hidden' => list, 'already_hidden' => n, 'kept' => list )
     */
    public function hideNonPages( array $patterns = array(), $dryRun = false )
    {
        $this->options['pull'] = false;
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $out = array( 'checked' => 0, 'hidden' => array(), 'already_hidden' => 0, 'kept' => array() );
        $discovered = array_fill_keys( $paths, true );
        foreach ( $this->state->data['pages'] as $path => $entry )
        {
            $path = (string)$path;
            if ( empty( $entry['node_id'] ) || strpos( $path, '#' ) !== false || ( isset( $entry['status'] ) && in_array( $entry['status'], array( 'removed', 'duplicate' ), true ) ) )
                continue;
            if ( $patterns && !expStaticImportHtmlType::matchesAny( $path, $patterns ) )
                continue;
            if ( method_exists( $this->type, 'isGroup' ) && $this->type->isGroup( $path ) )
                continue;
            $out['checked']++;
            $exists = is_file( $this->source . '/' . $path );
            if ( $exists && !isset( $discovered[$path] ) )
                // still in the source, but the rules leave it out now (ExcludePaths[], IncludePaths[], Suffixes[])
                $page = false;
            else
                try
                {
                    $page = $exists ? $this->type->parse( $this->source, $path ) : array();
                }
                catch ( Throwable $e )
                {
                    continue;
                }
            if ( is_array( $page ) && !isset( $page['asset_of'] ) && empty( $page['redirect_to'] ) )
                continue;
            $why = $page === false ? 'left out by the rules' : ( $page === null ? 'no page' : ( !empty( $page['redirect_to'] ) ? 'a copy of ' . $page['redirect_to'] : 'the viewer of ' . $page['asset_of'] ) );
            $node = eZContentObjectTreeNode::fetch( (int)$entry['node_id'] );
            if ( !$node instanceof eZContentObjectTreeNode )
                continue;
            if ( (int)$node->attribute( 'is_hidden' ) )
            {
                $out['already_hidden']++;
                continue;
            }
            $children = (int)$node->subTreeCount( array( 'Limitation' => array(), 'IgnoreVisibility' => false, 'Depth' => 1, 'DepthOperator' => 'eq' ) );
            $line = array( 'path' => $path, 'node_id' => (int)$node->attribute( 'node_id' ), 'name' => (string)$node->attribute( 'name' ), 'why' => $why );
            if ( $children > 0 )
            {
                $out['kept'][] = $line + array( 'children' => $children );
                continue;
            }
            if ( !$dryRun )
            {
                eZContentObjectTreeNode::hideSubTree( $node );
                eZContentCacheManager::clearContentCacheIfNeeded( (int)$node->attribute( 'contentobject_id' ) );
                $this->audit( 'system.staticimport_page.hide', array( 'path' => $path, 'node' => (int)$node->attribute( 'node_id' ), 'why' => $why ) );
            }
            $out['hidden'][] = $line;
        }
        return $out;
    }

    /**
     * What a console step changed while a run may have been going on (exp:static-import:route renames and moves
     * pages) is written to <work folder>/state-patches/*.json, never into the state file, which that run would save
     * over. The next run merges each patch into the state, saves it, and renames the patch to .applied.
     */
    /**
     * The state patches not applied yet, merged into this process's memory only (a console step that follows another
     * one, before a run has applied its patch)
     */
    protected function loadStatePatches()
    {
        $files = glob( $this->workDir . '/state-patches/*.json' ) ?: array();
        sort( $files );
        foreach ( $files as $file )
        {
            $patch = json_decode( (string)file_get_contents( $file ), true );
            foreach ( is_array( $patch ) ? $patch : array() as $path => $values )
                if ( is_array( $values ) )
                    $this->state->setPage( (string)$path, $values );
        }
    }

    protected function applyStatePatches()
    {
        $files = glob( $this->workDir . '/state-patches/*.json' ) ?: array();
        if ( !$files )
            return;
        sort( $files );
        foreach ( $files as $file )
        {
            $patch = json_decode( (string)file_get_contents( $file ), true );
            foreach ( is_array( $patch ) ? $patch : array() as $path => $values )
                if ( is_array( $values ) )
                    $this->state->setPage( (string)$path, $values );
        }
        $this->state->save();
        foreach ( $files as $file )
            rename( $file, $file . '.applied' );
        $this->report['warnings'][] = count( $files ) . ' state patch(es) of console steps applied';
    }

    /**
     * @param array $patch path => values merged into the page's state entry by the next run
     * @param string $what a word for the file name
     */
    protected function writeStatePatch( array $patch, $what )
    {
        if ( !$patch )
            return '';
        $dir = $this->workDir . '/state-patches';
        if ( !is_dir( $dir ) )
            mkdir( $dir, 0775, true );
        $file = $dir . '/' . date( 'Ymd-His' ) . '-' . preg_replace( '/[^a-z0-9-]/i', '', $what ) . '-' . getmypid() . '.json';
        file_put_contents( $file, json_encode( $patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $file;
    }

    /**
     * Puts the pages an earlier run imported where RouteRules[] send them today (exp:static-import:route): the
     * containers of RouteContainers[] are made (with the remote ids a run gives them), each routed page moves into
     * its container, takes its own name (unique below the container) and its relation to the current page
     * (history_of), and is shown again if an earlier step hid it. The state file is never written: what the next run
     * must know (the new written hash of a renamed page) goes to a state patch. Safe while a run is going on.
     *
     * @param bool $dryRun only count
     * @return array the counts and the lists
     */
    public function applyRoutes( $dryRun = false, $all = false )
    {
        $this->options['pull'] = false;
        $this->options['dry_run'] = (bool)$dryRun;
        $this->report = array( 'counts' => array(), 'warnings' => array() );
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $user = $this->actAs();
        $root = $this->parentNode();
        $countChildren = function ( $node ) { return (int)eZDB::instance()->arrayQuery( "SELECT COUNT(*) AS n FROM ezcontentobject_tree WHERE parent_node_id = " . (int)$node )[0]['n']; };
        $out = array( 'root_children_before' => $countChildren( $root ), 'containers_created' => 0, 'containers' => 0, 'routed' => 0, 'moved' => 0,
                      'renamed' => 0, 'shown' => 0, 'related' => 0, 'not_imported' => 0, 'families' => array(), 'patch' => '' );
        $routes = method_exists( $this->type, 'routes' ) ? $this->type->routes() : array();
        // the containers, parents first
        $containerNodes = array();
        // only the route containers (the other group pages are the next run's)
        $wanted = array();
        foreach ( $routes as $route )
            for ( $dir = $route['container']; $dir !== '' && $dir !== './'; $dir = dirname( rtrim( $dir, '/' ) ) === '.' ? '' : dirname( rtrim( $dir, '/' ) ) . '/' )
                $wanted[$dir] = true;
        // with $all every group page (GroupFolders[] too), and every imported page goes where the rules put it today
        $groups = array_values( array_filter( $paths, function ( $p ) use ( $wanted, $all ) { return $all ? substr( $p, -1 ) === '/' : isset( $wanted[$p] ); } ) );
        foreach ( $groups as $group )
        {
            $page = $this->type->map( $this->type->parse( $this->source, $group ) );
            $parent = $page['parent_path'] !== '' ? ( isset( $containerNodes[$page['parent_path']] ) ? $containerNodes[$page['parent_path']] : $this->state->nodeOf( $page['parent_path'] ) ) : $root;
            if ( !$parent )
                continue;
            $remoteID = self::remoteID( $this->label, $group );
            $object = eZContentObject::fetchByRemoteID( $remoteID );
            $out['containers']++;
            if ( !$object && !$dryRun )
            {
                $attributes = array();
                foreach ( $page['attributes'] as $identifier => $value )
                    if ( $value === '{body}' )
                        $attributes[$identifier] = (string)$this->toXml( $page['body_html'], $group );
                    else if ( $value !== '{hash}' && strpos( (string)$value, '{' ) !== 0 )
                        $attributes[$identifier] = $value;
                $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => $parent, 'class_identifier' => $page['class'],
                    'creator_id' => (int)$user->attribute( 'contentobject_id' ), 'remote_id' => $remoteID, 'attributes' => $attributes,
                    'language' => $this->language(), 'section_id' => $this->sectionFor( $page ) ) );
                if ( $object )
                    $out['containers_created']++;
            }
            if ( $object instanceof eZContentObject )
            {
                $containerNodes[$group] = (int)$object->attribute( 'main_node_id' );
                if ( !empty( $page['history_of'] ) && !$dryRun )
                    $out['related'] += $this->setRelation( $object, 'history_of', $this->objectByPageId( $page['history_of'] ) );
            }
        }
        // the routed pages (with $all: every imported page, routed or not)
        $patch = array();
        $n = 0;
        $items = $routes;
        if ( $all )
            foreach ( $this->state->data['pages'] as $path => $entry )
            {
                $path = (string)$path;
                if ( !isset( $items[$path] ) && !empty( $entry['object_id'] ) && strpos( $path, '#' ) === false && substr( $path, -1 ) !== '/'
                     && ( !isset( $entry['status'] ) || !in_array( $entry['status'], array( 'removed', 'duplicate', 'failed' ), true ) )
                     && !$this->isRootPage( $path ) && ( !method_exists( $this->type, 'isKnown' ) || $this->type->isKnown( $path ) ) )
                    $items[$path] = null;
            }
        foreach ( $items as $path => $route )
        {
            $path = (string)$path;
            $entry = $this->state->page( $path );
            if ( $route === null )
            {
                // a page that is not routed: below the parent its path, breadcrumb or TopParentRules[] give it today
                $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $path ) );
                $node = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
                if ( !$node instanceof eZContentObjectTreeNode )
                    continue;
                if ( ++$n % 50 === 0 )
                    eZContentObject::clearCache();
                $parentPath = $this->type->parentPath( $path );
                $target = $parentPath === '' ? $root : ( isset( $containerNodes[$parentPath] ) ? $containerNodes[$parentPath] : $this->state->nodeOf( $parentPath ) );
                if ( !$target && $parentPath !== '' && ( $parentObject = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $parentPath ) ) ) )
                    $target = (int)$parentObject->attribute( 'main_node_id' );
                if ( !$target || (int)$target === (int)$node->attribute( 'node_id' ) )
                    continue;
                $out['pages'] = ( isset( $out['pages'] ) ? $out['pages'] : 0 ) + 1;
                if ( (int)$node->attribute( 'parent_node_id' ) !== (int)$target )
                {
                    $out['moved']++;
                    if ( !$dryRun && !eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$target ) )
                        $out['warnings_move'][] = "$path could not be moved below node $target";
                }
                $name = $this->uniqueSiblingName( (int)$target, (string)$object->attribute( 'name' ), (int)$object->attribute( 'id' ), array( 'path' => $path, 'meta' => array() ) );
                if ( $name !== null && $name !== (string)$object->attribute( 'name' ) )
                {
                    $out['renamed']++;
                    if ( !$dryRun )
                        $this->renameObject( $object, $name );
                }
                if ( !$dryRun && $entry && !empty( $entry['written_fields'] ) && $name !== (string)$object->attribute( 'name' ) )
                {
                    eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                    $patch[$path] = array( 'written_hash' => $this->contentHash( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), (array)$entry['written_fields'] ) );
                }
                continue;
            }
            $family = preg_replace( '/[0-9a-f]{4}(-)?\.html$|\.html$/', '', basename( $path ) );
            $out['families'][$family] = ( isset( $out['families'][$family] ) ? $out['families'][$family] : 0 ) + 1;
            $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $path ) );
            if ( !$object instanceof eZContentObject )
            {
                $out['not_imported']++;
                continue;
            }
            if ( ++$n % 50 === 0 )
                eZContentObject::clearCache();
            $out['routed']++;
            $container = isset( $containerNodes[$route['container']] ) ? $containerNodes[$route['container']] : 0;
            $node = $object->attribute( 'main_node' );
            if ( !$container || !$node instanceof eZContentObjectTreeNode )
                continue;
            if ( (int)$node->attribute( 'parent_node_id' ) !== $container )
            {
                $out['moved']++;
                if ( !$dryRun )
                    eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), $container );
            }
            if ( (int)$node->attribute( 'is_hidden' ) && !expStaticImportHtmlType::matchesAny( $path, $this->rules->getList( 'HiddenPaths' ) ) )
            {
                $out['shown']++;
                if ( !$dryRun )
                    eZContentObjectTreeNode::unhideSubTree( $node );
            }
            $name = $this->uniqueSiblingName( $container, $route['name'], (int)$object->attribute( 'id' ), array( 'path' => $path, 'route_meta' => $route['meta'], 'meta' => array( 'page_id' => isset( $route['meta']['page_id'] ) ? $route['meta']['page_id'] : '' ) ) );
            if ( $name !== null && $name !== (string)$object->attribute( 'name' ) )
            {
                $out['renamed']++;
                if ( !$dryRun )
                {
                    $titles = array();
                    foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
                    {
                        list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
                        if ( $field === 'title' && ( $mapClass === '*' || $mapClass === (string)$object->attribute( 'class_identifier' ) ) && isset( $object->dataMap()[$identifier] ) )
                            $titles[$identifier] = $name;
                    }
                    if ( $titles )
                        eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $titles, 'language' => $this->language() ) );
                }
            }
            if ( !$dryRun && $route['current'] !== '' )
                $out['related'] += $this->setRelation( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), 'history_of', $this->objectByPageId( $route['current'] ) );
            if ( !$dryRun && $entry && !empty( $entry['written_fields'] ) )
            {
                eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                $patch[$path] = array( 'written_hash' => $this->contentHash( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), (array)$entry['written_fields'] ) );
            }
        }
        // with $all: the hidden leftovers of earlier runs at the top (pages that became redirects, copies or no pages,
        // kept hidden and no longer in the state as pages) go into RetiredParent (a page or container path)
        $retired = (string)$this->rules->get( 'RetiredParent', '' );
        if ( $all && $retired !== '' )
        {
            $retiredNode = isset( $containerNodes[$retired] ) ? $containerNodes[$retired] : $this->state->nodeOf( $retired );
            if ( !$retiredNode && ( $retiredObject = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $retired ) ) ) )
                $retiredNode = (int)$retiredObject->attribute( 'main_node_id' );
            $managed = array();
            foreach ( $this->state->data['pages'] as $path => $entry )
                if ( !empty( $entry['node_id'] ) && ( !isset( $entry['status'] ) || !in_array( $entry['status'], array( 'removed', 'duplicate' ), true ) ) )
                    $managed[(int)$entry['node_id']] = true;
            $db = eZDB::instance();
            $like = $db->escapeString( 'staticimport-' . $this->label . '-' );
            foreach ( $retiredNode ? (array)$db->arrayQuery( "SELECT t.node_id FROM ezcontentobject_tree t JOIN ezcontentobject o ON o.id = t.contentobject_id
                                                               WHERE t.parent_node_id = " . (int)$root . " AND t.is_hidden = 1 AND o.remote_id LIKE '$like%'" ) : array() as $row )
            {
                if ( isset( $managed[(int)$row['node_id']] ) || (int)$row['node_id'] === (int)$retiredNode )
                    continue;
                $out['retired_moved'] = ( isset( $out['retired_moved'] ) ? $out['retired_moved'] : 0 ) + 1;
                if ( !$dryRun )
                    eZContentObjectTreeNodeOperations::move( (int)$row['node_id'], (int)$retiredNode );
            }
        }
        if ( !$dryRun )
        {
            $out['patch'] = $this->writeStatePatch( $patch, 'route' );
            eZContentCacheManager::clearAllContentCache( true );
        }
        $out['root_children_after'] = $countChildren( $root );
        $out['warnings'] = array_slice( $this->report['warnings'], 0, 50 );
        return $out;
    }

    /**
     * Publishes a new name of an object into the attributes AttributeMap[] gives the field title.
     *
     * @param eZContentObject $object
     * @param string $name
     */
    protected function renameObject( eZContentObject $object, $name )
    {
        $titles = array();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
        {
            list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
            if ( $field === 'title' && ( $mapClass === '*' || $mapClass === (string)$object->attribute( 'class_identifier' ) ) && isset( $object->dataMap()[$identifier] ) )
                $titles[$identifier] = $name;
        }
        if ( $titles )
            eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $titles, 'language' => $this->language() ) );
    }

    /**
     * Sets an object relation attribute of the published version to an object, with the object relation.
     *
     * @return int 1 when it was set, 0 when it held that already or cannot
     */
    protected function setRelation( $object, $identifier, $toID )
    {
        if ( !$object instanceof eZContentObject || !$toID )
            return 0;
        $dataMap = $object->dataMap();
        $attribute = isset( $dataMap[$identifier] ) ? $dataMap[$identifier] : $this->createAttribute( $object, $identifier );
        if ( !$attribute || (int)$attribute->attribute( 'data_int' ) === (int)$toID )
            return 0;
        $attribute->setAttribute( 'data_int', (int)$toID );
        $attribute->setAttribute( 'sort_key_int', (int)$toID );
        $attribute->store();
        $object->addContentObjectRelation( (int)$toID, (int)$object->attribute( 'current_version' ), (int)$attribute->attribute( 'contentclassattribute_id' ) );
        return 1;
    }

    /**
     * The links of the pages an earlier run imported, written again where a link that was left as a static path can
     * be resolved now (exp:static-import:relink): each such page is imported again from the source, as the links phase
     * of a run does, with the state kept in memory; what the next run must know (the page's new hashes and its links
     * still unresolved) goes into a state patch. Safe while a run is going on.
     *
     * @param array $patterns shell patterns of the source paths (empty: all)
     * @param bool $dryRun only count
     * @return array( 'pages' => checked, 'rewritten' => n, 'links' => resolved now, 'unresolved' => n, 'targets' => the
     *                unresolved targets by count )
     */
    public function relinkExisting( array $patterns = array(), $dryRun = false )
    {
        $this->options['pull'] = false;
        $this->report = array( 'counts' => array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'flagged' => 0, 'failed' => 0, 'duplicates' => 0,
                                                  'hidden' => 0, 'removed' => 0, 'links' => 0 ), 'flagged' => array(), 'failed' => array(), 'removed' => array(),
                               'byline_failures' => array(), 'warnings' => array() );
        $this->source = $this->prepareSource();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $user = $this->actAs();
        $this->loadStatePatches();
        $out = array( 'pages' => 0, 'rewritten' => 0, 'links' => 0, 'unresolved' => 0, 'targets' => array(), 'flagged' => 0, 'failed' => 0, 'patch' => '' );
        $patch = array();
        $n = 0;
        foreach ( array_keys( $this->state->data['pages'] ) as $path )
        {
            $path = (string)$path;
            $entry = $this->state->page( $path );
            if ( empty( $entry['object_id'] ) || empty( $entry['unresolved'] ) || strpos( $path, '#' ) !== false
                 || ( isset( $entry['status'] ) && in_array( $entry['status'], array( 'removed', 'duplicate', 'failed', 'flagged' ), true ) ) )
                continue;
            if ( $patterns && !expStaticImportHtmlType::matchesAny( $path, $patterns ) )
                continue;
            $out['pages']++;
            if ( ++$n % 50 === 0 )
                eZContentObject::clearCache();
            $resolvable = false;
            foreach ( (array)$entry['unresolved'] as $target )
                if ( $this->state->nodeOf( $target ) || $this->state->asset( $target )
                     || ( strpos( (string)$target, 'page:' ) === 0 && $this->objectByPageId( substr( (string)$target, 5 ) ) ) )
                {
                    $resolvable = true;
                    break;
                }
            if ( !$resolvable )
            {
                foreach ( (array)$entry['unresolved'] as $target )
                    $out['targets'][$target] = ( isset( $out['targets'][$target] ) ? $out['targets'][$target] : 0 ) + 1;
                $out['unresolved'] += count( (array)$entry['unresolved'] );
                continue;
            }
            if ( $dryRun )
            {
                $out['rewritten']++;
                continue;
            }
            $links = $this->report['counts']['links'];
            $failed = $this->report['counts']['failed'];
            $flagged = $this->report['counts']['flagged'];
            $this->state->setPage( $path, array( 'source_hash' => '' ) );
            $this->importPage( $path, $user );
            $after = $this->state->page( $path );
            $out['links'] += $this->report['counts']['links'] - $links;
            $out['failed'] += $this->report['counts']['failed'] - $failed;
            $out['flagged'] += $this->report['counts']['flagged'] - $flagged;
            if ( $after && isset( $after['written_hash'] ) && $this->report['counts']['failed'] === $failed && $this->report['counts']['flagged'] === $flagged )
            {
                $out['rewritten']++;
                $patch[$path] = array_intersect_key( $after, array_flip( array( 'source_hash', 'written_hash', 'written_fields', 'unresolved', 'node_id', 'object_id' ) ) );
                foreach ( (array)$after['unresolved'] as $target )
                    $out['targets'][$target] = ( isset( $out['targets'][$target] ) ? $out['targets'][$target] : 0 ) + 1;
                $out['unresolved'] += count( (array)$after['unresolved'] );
            }
        }
        if ( !$dryRun )
            $out['patch'] = $this->writeStatePatch( $patch, 'relink' );
        arsort( $out['targets'] );
        $out['targets'] = array_slice( $out['targets'], 0, 100, true );
        return $out;
    }

    /** @var array page id => object id (0: none), objectByPageId()'s memory */
    protected $pageIdObjects = array();

    /** @var array parent node => array( lower-case name => object id ), uniqueSiblingName()'s memory */
    protected $siblingNames = array();

    /**
     * The object of the current page with a source page id (the attribute AttributeMap[] gives the field page_id),
     * never a routed copy, version or view of it (RouteRules[]).
     *
     * @param string $pageId
     * @return int 0 when there is none
     */
    public function objectByPageId( $pageId )
    {
        $pageId = (string)$pageId;
        if ( !empty( $this->pageIdObjects[$pageId] ) )
            return $this->pageIdObjects[$pageId];
        // the type knows the current page of the id from the source (RouteMeta[page_id]): its object
        if ( method_exists( $this->type, 'pathOfPageId' ) && ( $path = $this->type->pathOfPageId( $pageId ) ) !== '' )
        {
            if ( isset( $this->state->data['pages'][$path]['duplicate_of'] ) && $this->state->data['pages'][$path]['duplicate_of'] !== '' )
                $path = $this->state->data['pages'][$path]['duplicate_of'];
            $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $path ) );
            if ( $object instanceof eZContentObject )
                return $this->pageIdObjects[$pageId] = (int)$object->attribute( 'id' );
        }
        $identifiers = array();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
            if ( $field === 'page_id' && strpos( (string)$key, '/' ) !== false )
                $identifiers[] = substr( (string)$key, strpos( (string)$key, '/' ) + 1 );
        if ( !$identifiers || !ctype_digit( $pageId ) )
            return 0;
        $db = eZDB::instance();
        $list = "'" . implode( "','", array_map( array( $db, 'escapeString' ), array_unique( $identifiers ) ) ) . "'";
        $rows = $db->arrayQuery( "SELECT o.id, o.remote_id FROM ezcontentobject_attribute a
                                  JOIN ezcontentclass_attribute ca ON ca.id = a.contentclassattribute_id AND ca.version = 0 AND ca.identifier IN ( $list )
                                  JOIN ezcontentobject o ON o.id = a.contentobject_id AND o.current_version = a.version AND o.status = 1
                                  WHERE a.data_text = '" . $db->escapeString( $pageId ) . "'
                                  AND o.remote_id LIKE '" . $db->escapeString( 'staticimport-' . $this->label . '-' ) . "%'" );
        $routed = array();
        if ( method_exists( $this->type, 'routes' ) )
            foreach ( array_keys( $this->type->routes() ) as $path )
                $routed[self::remoteID( $this->label, $path )] = true;
        $found = 0;
        foreach ( $rows as $row )
            if ( !isset( $routed[$row['remote_id']] ) )
            {
                $found = (int)$row['id'];
                break;
            }
        return $this->pageIdObjects[$pageId] = $found;
    }

    /**
     * The spec check of sibling names (SiblingNames: unique, the default, refuse or warn): a page may not take the
     * name of another object below the same parent. unique gives it SiblingNameSuffix (default " ({n})"; {n} counts
     * from 2, the page's RouteMeta[] values and {page_id} may be used) until it is free; every renaming is reported.
     *
     * @param int $parentNode
     * @param string $name
     * @param int $objectID the page's own object (0 for a new one)
     * @param array $page
     * @return string|null the name to use, null when the page is refused
     */
    protected function uniqueSiblingName( $parentNode, $name, $objectID, array $page )
    {
        $mode = strtolower( (string)$this->rules->get( 'SiblingNames', 'unique' ) );
        $parentNode = (int)$parentNode;
        if ( $mode === 'off' || !$parentNode || trim( (string)$name ) === '' )
            return $name;
        if ( !isset( $this->siblingNames[$parentNode] ) )
        {
            $this->siblingNames[$parentNode] = array();
            foreach ( (array)eZDB::instance()->arrayQuery( "SELECT o.id, o.name FROM ezcontentobject_tree t JOIN ezcontentobject o ON o.id = t.contentobject_id
                                                            WHERE t.parent_node_id = $parentNode AND t.node_id <> t.parent_node_id" ) as $row )
            {
                $key = mb_strtolower( trim( (string)$row['name'] ), 'UTF-8' );
                if ( !isset( $this->siblingNames[$parentNode][$key] ) )
                    $this->siblingNames[$parentNode][$key] = (int)$row['id'];
            }
            // the page's own object counts under its own name only
        }
        $taken = function ( $candidate ) use ( $parentNode, $objectID )
        {
            $key = mb_strtolower( trim( $candidate ), 'UTF-8' );
            return isset( $this->siblingNames[$parentNode][$key] ) && $this->siblingNames[$parentNode][$key] !== (int)$objectID;
        };
        $result = $name;
        if ( $taken( $name ) )
        {
            if ( $mode === 'refuse' )
                return null;
            if ( $mode === 'warn' )
            {
                $this->report['warnings'][] = "{$page['path']}: the name '$name' is taken below node $parentNode";
                return $name;
            }
            // (an INI value loses its leading space: the suffix always starts with one)
            $suffix = ' ' . ltrim( (string)$this->rules->get( 'SiblingNameSuffix', '({n})' ) );
            $values = ( isset( $page['route_meta'] ) ? (array)$page['route_meta'] : array() ) + array( 'page_id' => isset( $page['meta']['page_id'] ) ? $page['meta']['page_id'] : '' );
            for ( $n = 2; $n < 10000; $n++ )
            {
                $candidate = $name . expStaticImportHtmlType::fill( $suffix, $values + array( 'n' => (string)$n ) );
                if ( !$taken( $candidate ) )
                    break;
                // a suffix without {n} that is taken already gets the counter too
                if ( strpos( $suffix, '{n}' ) === false )
                    $suffix .= ' ({n})';
            }
            $result = $candidate;
            $this->report['counts']['renamed'] = ( isset( $this->report['counts']['renamed'] ) ? $this->report['counts']['renamed'] : 0 ) + 1;
            $this->report['warnings'][] = "{$page['path']}: named '$result', '$name' is taken below node $parentNode";
        }
        $this->siblingNames[$parentNode][mb_strtolower( trim( $result ), 'UTF-8' )] = $objectID ?: -1;
        return $result;
    }

    /** the byline fields AttributeMap[] may give an attribute, and what each holds */
    const BYLINE_FIELDS = array( 'written_by' => 'person', 'last_updated_by' => 'person', 'maintainers' => 'people', 'contributors' => 'people',
                                 'written_at' => 'time', 'last_updated_at' => 'time' );

    /**
     * The no-data-loss audit of the bylines (exp:static-import:bylines): for every imported page, each person and
     * date the source's byline areas name (written, updated, maintainers, comment authors) is looked for in the
     * attributes the import stores them in (AttributeMap[] of the fields written_by, written_at, last_updated_by,
     * last_updated_at, maintainers, contributors). With $fill, what is missing is written into the published version
     * (a role attribute only when it is empty, the contributors list always to the full set), with the object
     * relations, and checked again. It reads the source as it is and never writes the state file, so it is safe while
     * a run of the type is going on. Byline text no rule could read is listed as unknown, never dropped.
     *
     * @param array $patterns shell patterns of the source paths (empty: all)
     * @param bool $fill write what is missing
     * @return array( 'pages' => n, 'people' => n, 'dates' => n, 'missing' => n, 'filled' => n, 'unknown' => n,
     *                'by_page' => array( path => array( 'missing' => list, 'unknown' => list ) ) )
     */
    public function bylines( array $patterns = array(), $fill = false, $replaceRoles = false )
    {
        $this->options['pull'] = false;
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $user = $fill ? $this->actAs() : null;
        $map = $this->rules->getHash( 'AttributeMap' );
        $out = array( 'pages' => 0, 'people' => 0, 'dates' => 0, 'missing' => 0, 'filled' => 0, 'unknown' => 0, 'by_page' => array() );
        $n = 0;
        foreach ( $this->state->data['pages'] as $path => $entry )
        {
            $path = (string)$path;
            if ( empty( $entry['object_id'] ) || strpos( $path, '#' ) !== false || ( isset( $entry['status'] ) && in_array( $entry['status'], array( 'removed', 'duplicate', 'failed' ), true ) ) )
                continue;
            if ( $patterns && !expStaticImportHtmlType::matchesAny( $path, $patterns ) )
                continue;
            if ( ++$n % 50 === 0 )
                eZContentObject::clearCache();
            try
            {
                $page = $this->type->parse( $this->source, $path );
                if ( $page === null || isset( $page['asset_of'] ) || isset( $page['redirect_to'] ) )
                    continue;
                $page = $this->type->map( $page );
            }
            catch ( Throwable $e )
            {
                continue;
            }
            $object = eZContentObject::fetch( (int)$entry['object_id'] );
            if ( !$object instanceof eZContentObject )
                continue;
            $out['pages']++;
            $bylines = isset( $page['bylines'] ) ? (array)$page['bylines'] : array();
            $unknown = isset( $page['byline_failures'] ) ? array_values( (array)$page['byline_failures'] ) : array();
            // the attribute of each byline field in this page's class
            $attributes = array();
            foreach ( $map as $key => $field )
            {
                list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
                if ( isset( self::BYLINE_FIELDS[$field] ) && ( $mapClass === '*' || $mapClass === $page['class'] ) && !isset( $attributes[$field] ) )
                    $attributes[$field] = $identifier;
            }
            $expected = $this->expectedBylines( $bylines );
            $out['people'] += count( $expected['contributors'] );
            $out['dates'] += count( array_filter( array( $expected['written_at'], $expected['last_updated_at'] ) ) );
            $missing = $this->missingBylines( $object, $attributes, $expected );
            if ( $missing && $fill )
            {
                if ( $this->fillBylines( $object, $attributes, $expected, $missing, $page, $user, $replaceRoles && ( !isset( $entry['status'] ) || $entry['status'] !== 'flagged' ) ) )
                    $out['filled']++;
                eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                $missing = $this->missingBylines( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), $attributes, $expected );
            }
            $out['missing'] += count( $missing );
            $out['unknown'] += count( $unknown );
            if ( $missing || $unknown )
                $out['by_page'][$path] = array( 'object_id' => (int)$object->attribute( 'id' ), 'missing' => $missing, 'unknown' => $unknown );
        }
        if ( $out['filled'] )
            eZContentCacheManager::clearAllContentCache( true );
        return $out;
    }

    /**
     * @param array $bylines the byline fields of a page
     * @return array what the attributes should hold: names (or a list) per person field, a time per date field
     */
    protected function expectedBylines( array $bylines )
    {
        $name = function ( $field ) use ( $bylines ) { return isset( $bylines[$field]['name'] ) ? trim( (string)$bylines[$field]['name'] ) : ''; };
        $time = function ( $field ) use ( $bylines ) { return !empty( $bylines[$field]['time'] ) ? (int)$bylines[$field]['time'] : 0; };
        return array(
            'written_by' => $name( 'written' ), 'written_at' => $time( 'written' ),
            'last_updated_by' => $name( 'updated' ), 'last_updated_at' => $time( 'updated' ),
            'maintainers' => isset( $bylines['maintainers']['names'] ) ? array_values( (array)$bylines['maintainers']['names'] ) : array(),
            'contributors' => expStaticImportByline::people( $bylines ),
        );
    }

    /**
     * @param eZContentObject $object
     * @param array $attributes byline field => attribute identifier
     * @param array $expected
     * @return array of "<field>: <value>" the stored attributes do not hold
     */
    protected function missingBylines( eZContentObject $object, array $attributes, array $expected )
    {
        $dataMap = $object->dataMap();
        $missing = array();
        foreach ( $expected as $field => $value )
        {
            $attribute = isset( $attributes[$field], $dataMap[$attributes[$field]] ) ? $dataMap[$attributes[$field]] : null;
            $stored = $attribute ? $this->storedByline( $attribute, self::BYLINE_FIELDS[$field] ) : null;
            if ( self::BYLINE_FIELDS[$field] === 'time' )
            {
                if ( $value && (int)$stored !== (int)$value )
                    $missing[] = "$field: " . date( 'c', (int)$value );
                continue;
            }
            $value = $value === '' ? array() : (array)$value;
            $have = array_map( array( 'expStaticImportByline', 'normalizeName' ), (array)$stored );
            foreach ( $value as $person )
                if ( !in_array( expStaticImportByline::normalizeName( $person ), $have, true ) )
                    $missing[] = "$field: $person";
            // a person stored that the source does not name is wrong data (an earlier rule read text as a name)
            $want = array_map( array( 'expStaticImportByline', 'normalizeName' ), $value );
            foreach ( (array)$stored as $person )
                if ( !in_array( expStaticImportByline::normalizeName( $person ), $want, true ) )
                    $missing[] = "$field: (not in the source) $person";
        }
        return $missing;
    }

    /**
     * @param eZContentObjectAttribute $attribute
     * @param string $kind person, people or time
     * @return mixed the names of the related objects, or the time
     */
    protected function storedByline( eZContentObjectAttribute $attribute, $kind )
    {
        if ( $kind === 'time' )
            return (int)$attribute->attribute( 'data_int' );
        $ids = array();
        if ( $attribute->attribute( 'data_type_string' ) === 'ezobjectrelationlist' )
        {
            $content = $attribute->content();
            foreach ( isset( $content['relation_list'] ) ? $content['relation_list'] : array() as $item )
                $ids[] = (int)$item['contentobject_id'];
        }
        else if ( (int)$attribute->attribute( 'data_int' ) )
            $ids[] = (int)$attribute->attribute( 'data_int' );
        $names = array();
        foreach ( $ids as $id )
            if ( $related = eZContentObject::fetch( $id ) )
                $names[] = (string)$related->attribute( 'name' );
        return $names;
    }

    /**
     * Writes the missing bylines into the object's published version, with its object relations.
     *
     * @return bool whether anything was written
     */
    protected function fillBylines( eZContentObject $object, array $attributes, array $expected, array $missing, array $page, eZUser $user, $replaceRoles = false )
    {
        $fields = array();
        foreach ( $missing as $line )
            $fields[substr( $line, 0, strpos( $line, ':' ) )] = true;
        $version = (int)$object->attribute( 'current_version' );
        $dataMap = $object->dataMap();
        $written = false;
        foreach ( array_keys( $fields ) as $field )
        {
            if ( !isset( $attributes[$field] ) )
                continue;
            $attribute = isset( $dataMap[$attributes[$field]] ) ? $dataMap[$attributes[$field]] : $this->createAttribute( $object, $attributes[$field] );
            if ( !$attribute )
                continue;
            $kind = self::BYLINE_FIELDS[$field];
            if ( $kind === 'time' )
            {
                // a role's date is filled when empty, replaced only with --replace-roles
                if ( (int)$attribute->attribute( 'data_int' ) && !$replaceRoles )
                    continue;
                $attribute->setAttribute( 'data_int', (int)$expected[$field] );
                $attribute->setAttribute( 'sort_key_int', (int)$expected[$field] );
                $attribute->store();
                $written = true;
                continue;
            }
            $ids = array();
            foreach ( $expected[$field] === '' ? array() : (array)$expected[$field] as $name )
                if ( $id = $this->contributor( $name, $user ) )
                    $ids[] = $id;
            // nobody to write, unless --replace-roles clears what the source does not name
            if ( !$ids && !$replaceRoles )
                continue;
            $classAttributeID = (int)$attribute->attribute( 'contentclassattribute_id' );
            if ( $kind === 'person' )
            {
                $old = (int)$attribute->attribute( 'data_int' );
                if ( $old && !$replaceRoles )
                    continue;
                $new = $ids ? (int)$ids[0] : 0;
                // --replace-roles: the role is the source's person, the one it held loses the relation
                if ( $old && $old !== $new )
                    $object->removeContentObjectRelation( $old, $version, $classAttributeID, eZContentObject::RELATION_ATTRIBUTE );
                $attribute->setAttribute( 'data_int', $new );
                $attribute->setAttribute( 'sort_key_int', $new );
                $attribute->store();
            }
            else
            {
                // a list keeps what it has and gets the people it lacks; with --replace-roles it is exactly the
                // source's people, and the others lose their relation
                $have = array();
                $content = $attribute->content();
                foreach ( isset( $content['relation_list'] ) ? $content['relation_list'] : array() as $item )
                    $have[] = (int)$item['contentobject_id'];
                if ( $replaceRoles )
                    foreach ( array_diff( $have, $ids ) as $old )
                        $object->removeContentObjectRelation( (int)$old, $version, $classAttributeID, eZContentObject::RELATION_ATTRIBUTE );
                $ids = array_values( array_unique( $replaceRoles ? $ids : array_merge( $have, $ids ) ) );
                $attribute->fromString( implode( '-', $ids ) );
                $attribute->store();
            }
            foreach ( $ids as $id )
                $object->addContentObjectRelation( $id, $version, (int)$attribute->attribute( 'contentclassattribute_id' ) );
            $written = true;
        }
        if ( $written )
            $this->audit( 'system.staticimport_page.bylines', array( 'path' => $page['path'], 'object' => (int)$object->attribute( 'id' ), 'fields' => array_keys( $fields ) ) );
        return $written;
    }

    /**
     * The attribute row of a class attribute an object was created without (the class got it later, while a process
     * with the old class definition created the object), in its published version and language.
     *
     * @return eZContentObjectAttribute|null
     */
    protected function createAttribute( eZContentObject $object, $identifier )
    {
        $class = $object->contentClass();
        $classAttribute = $class ? $class->fetchAttributeByIdentifier( $identifier ) : null;
        if ( !$classAttribute )
            return null;
        $language = $object->attribute( 'initial_language_code' );
        $attribute = eZContentObjectAttribute::create( (int)$classAttribute->attribute( 'id' ), (int)$object->attribute( 'id' ), (int)$object->attribute( 'current_version' ), $language );
        $attribute->setAttribute( 'language_id', (int)eZContentLanguage::idByLocale( $language ) | ( (int)$object->attribute( 'language_mask' ) & 1 ) );
        $attribute->initialize();
        $attribute->store();
        return $attribute;
    }

    /**
     * Work a publish does for every object that an import can do once at the end, which keeps a large import fast:
     * - DeferIndexing (default enabled): objects are queued for the search index (site.ini [SearchSettings]
     *   DelayedIndexing=enabled for this run), indexed by the cronjob indexcontent instead of during the publish;
     * - DeferViewCacheClearing (default enabled): the view caches are not cleared object by object (site.ini
     *   [ContentSettings] ViewCaching=disabled for this run); the whole content view cache is cleared once at the end.
     * Only this process changes; the settings files stay as they are.
     *
     * @return array what was deferred
     */
    protected function deferWork()
    {
        $deferred = array();
        if ( $this->options['dry_run'] )
            return $deferred;
        $site = eZINI::instance( 'site.ini' );
        if ( $this->rules->enabled( 'DeferIndexing', true ) && $site->variable( 'SearchSettings', 'DelayedIndexing' ) !== 'enabled' )
        {
            $deferred['indexing'] = $site->variable( 'SearchSettings', 'DelayedIndexing' );
            $site->setVariable( 'SearchSettings', 'DelayedIndexing', 'enabled' );
        }
        if ( $this->rules->enabled( 'DeferViewCacheClearing', true ) && $site->variable( 'ContentSettings', 'ViewCaching' ) === 'enabled' )
        {
            $deferred['view_cache'] = 'enabled';
            $site->setVariable( 'ContentSettings', 'ViewCaching', 'disabled' );
        }
        return $deferred;
    }

    /**
     * @param array $deferred what deferWork() changed: put back, and the view cache cleared once
     */
    protected function finishDeferredWork( array $deferred )
    {
        $site = eZINI::instance( 'site.ini' );
        if ( isset( $deferred['indexing'] ) )
        {
            $site->setVariable( 'SearchSettings', 'DelayedIndexing', $deferred['indexing'] );
            $this->report['warnings'][] = 'the search index is brought up to date by the cronjob indexcontent (the objects were queued)';
        }
        if ( isset( $deferred['view_cache'] ) )
        {
            $site->setVariable( 'ContentSettings', 'ViewCaching', $deferred['view_cache'] );
            if ( $this->report['counts']['created'] + $this->report['counts']['updated'] > 0 )
                eZContentCacheManager::clearAllContentCache( true );
        }
    }

    /**
     * One page: parse, map, find the duplicate, write.
     *
     * @param string $path
     * @param eZUser $user
     */
    protected function importPage( $path, eZUser $user )
    {
        try
        {
            $page = $this->type->parse( $this->source, $path );
            if ( $page === null )
            {
                // not a page (an error page of the export): one imported by an earlier run is hidden
                $this->retire( $path, 'not a page' );
                return;
            }
            if ( isset( $page['redirect_to'] ) )
            {
                // a redirect stub: its path redirects to the page it points to, nothing is imported for it
                $this->report['counts']['duplicates']++;
                $this->retire( $path, 'a redirect' );
                if ( !$this->options['dry_run'] && $page['redirect_to'] !== '' )
                    $this->state->setPage( $path, array( 'status' => 'duplicate', 'duplicate_of' => $page['redirect_to'], 'redirected' => false ) );
                return;
            }
            if ( isset( $page['asset_of'] ) )
            {
                // the viewer of one media file (AssetPages): never a page. The file becomes a media object in the
                // assets phase, and the page's path redirects to it; a page an earlier run made for it is hidden
                $asset = (string)$page['asset_of'];
                $this->report['counts']['asset_pages'] = ( isset( $this->report['counts']['asset_pages'] ) ? $this->report['counts']['asset_pages'] : 0 ) + 1;
                $this->retire( $path, 'the viewer of the media file ' . $asset );
                $this->assetPaths[$asset] = true;
                if ( !empty( $page['asset_meta'] ) )
                    $this->assetMeta[$asset] = (array)$page['asset_meta'];
                if ( !$this->options['dry_run'] )
                {
                    $this->state->data['pending_assets'][$asset] = true;
                    $this->state->setPage( $path, array( 'status' => 'duplicate', 'duplicate_of' => $asset, 'redirected' => false, 'asset_page' => true ) );
                }
                return;
            }
            // RootPage: one source page (e.g. index.html) is the content of the import's top node itself, written into
            // that object with its own class, never a page of its own
            if ( $this->isRootPage( $path ) && ( $root = $this->rootObject() ) )
            {
                $page['class_fixed'] = (string)$root->attribute( 'class_identifier' );
                $page['parent_fixed'] = '';
            }
            $page = $this->type->map( $page );
        }
        catch ( expStaticImportPageException $e )
        {
            $this->fail( $path, $e->getMessage() );
            return;
        }
        catch ( Throwable $e )
        {
            $this->fail( $path, get_class( $e ) . ': ' . $e->getMessage() );
            return;
        }
        $this->importParsedPage( $page, $user );
    }

    /**
     * Puts the contributor objects in place of a byline placeholder: {contributor:<field>} becomes the id of the
     * contributor named in that byline field, {contributors:<field>} the ids of the names of a list field joined by "-"
     * (as the relation list datatype takes them). A contributor is found by its remote id (from its normalised name)
     * or created below ContributorParentRemoteID as ContributorClass, its name in ContributorNameAttribute.
     *
     * @param string $value
     * @param array $page
     * @param eZUser $user
     * @return string
     */
    protected function contributorValue( $value, array $page, eZUser $user )
    {
        if ( !is_string( $value ) || !preg_match( '/^\{(contributors?):(\w+|\*)\}$/', $value, $m ) )
            return $value;
        if ( $m[2] === '*' )
            // {contributors:*}: every person of every byline field
            $names = expStaticImportByline::people( isset( $page['bylines'] ) ? (array)$page['bylines'] : array() );
        else
        {
            $field = isset( $page['bylines'][$m[2]] ) ? $page['bylines'][$m[2]] : array();
            $names = isset( $field['names'] ) ? (array)$field['names'] : ( isset( $field['name'] ) ? array( $field['name'] ) : array() );
        }
        $ids = array();
        foreach ( $names as $name )
            if ( $id = $this->contributor( $name, $user ) )
                $ids[] = $id;
        return implode( '-', array_unique( $ids ) );
    }

    /**
     * @param string $name
     * @param eZUser $user
     * @return int the contributor object of a person, 0 in a dry run or when none can be made
     */
    protected function contributor( $name, eZUser $user )
    {
        $class = (string)$this->rules->get( 'ContributorClass', '' );
        $key = expStaticImportByline::normalizeName( $name );
        if ( $class === '' || $key === '' )
            return 0;
        if ( isset( $this->state->data['contributors'][$key] ) && eZContentObject::exists( (int)$this->state->data['contributors'][$key] ) )
            return (int)$this->state->data['contributors'][$key];
        $remoteID = 'staticimport-contributor-' . md5( $key );
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        if ( !$object instanceof eZContentObject )
        {
            if ( $this->options['dry_run'] )
            {
                $this->report['counts']['contributors'] = ( isset( $this->report['counts']['contributors'] ) ? $this->report['counts']['contributors'] : 0 ) + 1;
                $this->state->data['contributors_dry'][$key] = true;
                return 0;
            }
            $object = eZContentFunctions::createAndPublishObject( array(
                'parent_node_id' => $this->nodeSetting( 'ContributorParentNode', 'ContributorParentRemoteID', $this->parentNode() ),
                'class_identifier' => $class, 'creator_id' => (int)$user->attribute( 'contentobject_id' ), 'remote_id' => $remoteID,
                'attributes' => array( (string)$this->rules->get( 'ContributorNameAttribute', 'name' ) => trim( preg_replace( '/\s+/u', ' ', $name ) ) ),
                'language' => $this->language(),
                'section_id' => $this->sectionByIdentifier( (string)$this->rules->get( 'ContributorSection', '' ) ) ) );
            if ( !$object instanceof eZContentObject )
            {
                $this->report['warnings'][] = "the contributor '$name' could not be created as $class";
                return 0;
            }
            $this->report['counts']['contributors'] = ( isset( $this->report['counts']['contributors'] ) ? $this->report['counts']['contributors'] : 0 ) + 1;
        }
        $this->state->data['contributors'][$key] = (int)$object->attribute( 'id' );
        return (int)$object->attribute( 'id' );
    }

    /**
     * @param string $path
     * @return bool whether the path is the RootPage of the type
     */
    protected function isRootPage( $path )
    {
        $root = trim( (string)$this->rules->get( 'RootPage', '' ) );
        return $root !== '' && $root === $path;
    }

    /**
     * @return eZContentObject|null the object of the import's top node (ParentNode or ParentRemoteID)
     */
    protected function rootObject()
    {
        $node = eZContentObjectTreeNode::fetch( $this->parentNode() );
        return $node instanceof eZContentObjectTreeNode ? $node->object() : null;
    }

    /**
     * Hides an object made by an earlier run that is no longer a page of its own (trashed with --apply-removals).
     *
     * @param eZContentObject $object
     * @param string $path
     */
    protected function retireObject( eZContentObject $object, $path )
    {
        $node = $object->attribute( 'main_node' );
        if ( !$node instanceof eZContentObjectTreeNode )
            return;
        $this->report['counts']['removed']++;
        $this->report['removed'][] = array( 'path' => $path, 'node_id' => (int)$node->attribute( 'node_id' ), 'reason' => 'merged into the top node', 'trashed' => (bool)$this->options['apply_removals'] );
        if ( $this->options['apply_removals'] )
            eZContentObjectTreeNode::removeSubtrees( array( (int)$node->attribute( 'node_id' ) ), true );
        else if ( !(int)$node->attribute( 'is_hidden' ) )
            eZContentObjectTreeNode::hideSubTree( $node );
        // the old object gives up the page's remote id, so the page is the top node from now on
        $object->setAttribute( 'remote_id', $object->attribute( 'remote_id' ) . '-retired' );
        $object->store();
    }

    /**
     * A path that is no longer a page of its own (an error page, a redirect stub): the node an earlier run made for it
     * is hidden (moved to the trash with --apply-removals).
     *
     * @param string $path
     * @param string $why
     */
    protected function retire( $path, $why )
    {
        $entry = $this->state->page( $path );
        if ( !$entry || empty( $entry['node_id'] ) || ( isset( $entry['status'] ) && $entry['status'] === 'removed' ) )
            return;
        $this->report['counts']['removed']++;
        $this->report['removed'][] = array( 'path' => $path, 'node_id' => (int)$entry['node_id'], 'reason' => $why, 'trashed' => (bool)$this->options['apply_removals'] );
        if ( $this->options['dry_run'] )
            return;
        $node = eZContentObjectTreeNode::fetch( (int)$entry['node_id'] );
        if ( $node instanceof eZContentObjectTreeNode )
        {
            if ( $this->options['apply_removals'] )
                eZContentObjectTreeNode::removeSubtrees( array( (int)$entry['node_id'] ), true );
            else if ( !(int)$node->attribute( 'is_hidden' ) )
                eZContentObjectTreeNode::hideSubTree( $node );
        }
        $this->state->setPage( $path, array( 'status' => 'removed', 'node_id' => 0, 'object_id' => 0, 'reason' => $why ) );
    }

    /**
     * Writes one mapped page (and the pages it carries, such as its comments, below it).
     *
     * @param array $page
     * @param eZUser $user
     */
    protected function importParsedPage( array $page, eZUser $user )
    {
        $path = $page['path'];
        // the pages a page carries (its comments) count too, so a new comment brings its page up again
        $sourceHash = sha1( $page['class'] . "\0" . $page['title'] . "\0" . $page['body_html'] . "\0"
                             . ( empty( $page['children'] ) ? '' : md5( json_encode( $page['children'] ) ) )
                             // and the values the rules give it, so a changed rule (a version status, a field) reaches it
                             . "\0" . md5( json_encode( isset( $page['attributes'] ) ? $page['attributes'] : array() ) ) );
        $previous = $this->state->page( $path );

        // the same content under another path: one node, this path redirects to it
        if ( isset( $this->seenHashes[$sourceHash] ) && $this->seenHashes[$sourceHash] !== $path
             && ( !empty( $page['duplicate_candidate'] ) || $this->rules->enabled( 'DeduplicateAll' ) ) )
        {
            $this->report['counts']['duplicates']++;
            if ( !$this->options['dry_run'] )
                $this->state->setPage( $path, array( 'status' => 'duplicate', 'duplicate_of' => $this->seenHashes[$sourceHash], 'source_hash' => $sourceHash ) );
            return;
        }
        $this->seenHashes[$sourceHash] = $path;

        foreach ( $this->type->assets( $page ) as $asset )
            $this->assetPaths[$asset] = $this->state->data['pending_assets'][$asset] = true;

        $parentNode = $page['parent_path'] !== '' ? $this->state->nodeOf( $page['parent_path'] ) : $this->parentNode();
        // a parent the state does not know yet (made by a console step, such as a route container) by its remote id
        if ( !$parentNode && $page['parent_path'] !== '' && ( $parentObject = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $page['parent_path'] ) ) ) )
            $parentNode = (int)$parentObject->attribute( 'main_node_id' );
        if ( !$parentNode )
            $parentNode = $this->parentNode();

        $links = $this->type->links( $page );
        list( $html, $unresolved ) = $this->rewriteLinks( $page['body_html'], $links, $page );
        $xml = $this->toXml( $html, $path );
        if ( $xml === null )
        {
            $this->fail( $path, 'the body could not be turned into XML', $page );
            return;
        }
        $attributes = array();
        foreach ( $page['attributes'] as $identifier => $value )
        {
            $attributes[$identifier] = $value === '{body}' ? $xml : ( $value === '{hash}' ? $sourceHash : $this->contributorValue( $value, $page, $user ) );
            // {page:<page id>}: the object of the current page with that page id (history_of); one not imported yet
            // is resolved in the links phase
            if ( is_string( $value ) && preg_match( '/^\{page:(\w+)\}$/', $value, $m ) )
            {
                $attributes[$identifier] = (string)$this->objectByPageId( $m[1] );
                if ( $attributes[$identifier] === '0' )
                {
                    $attributes[$identifier] = '';
                    $unresolved[] = 'page:' . $m[1];
                }
            }
        }
        foreach ( isset( $page['byline_failures'] ) ? (array)$page['byline_failures'] : array() as $line )
            $this->report['byline_failures'][] = array( 'path' => $path, 'line' => $line );

        $remoteID = self::remoteID( $this->label, $path );
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        // the spec check: no two pages with the same name below one parent (SiblingNames=unique renames the later
        // one with SiblingNameSuffix, refuse skips it, warn only reports it)
        $unique = $this->isRootPage( $path ) ? $page['title'] : $this->uniqueSiblingName( $parentNode, $page['title'], $object instanceof eZContentObject ? (int)$object->attribute( 'id' ) : 0, $page );
        if ( $unique === null )
        {
            $this->fail( $path, "a page named '{$page['title']}' is below node $parentNode already (SiblingNames=refuse)", $page );
            return;
        }
        if ( $unique !== $page['title'] )
            foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
            {
                list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
                if ( $field === 'title' && ( $mapClass === '*' || $mapClass === $page['class'] ) && isset( $attributes[$identifier] ) )
                    $attributes[$identifier] = $unique;
            }
        $isRoot = $this->isRootPage( $path ) && ( $root = $this->rootObject() );
        if ( $isRoot )
        {
            // a page an earlier run made for the root page goes (hidden, or trashed with --apply-removals); its address
            // then redirects to the top node like every old path
            if ( $object instanceof eZContentObject && (int)$object->attribute( 'id' ) !== (int)$root->attribute( 'id' ) && !$this->options['dry_run'] )
                $this->retireObject( $object, $path );
            $object = $root;
        }

        if ( $this->options['dry_run'] )
        {
            $this->report['counts'][$object ? ( $previous && $previous['source_hash'] === $sourceHash ? 'unchanged' : 'updated' ) : 'created']++;
            return;
        }

        if ( $object instanceof eZContentObject )
        {
            // the hash is taken over the attributes the import wrote then, so a changed attribute map is no edit
            $writtenHash = $previous && isset( $previous['written_hash'] ) ? $previous['written_hash'] : '';
            // a hash written for another object (the page an earlier run made before RootPage put it into the top
            // node) says nothing of this one: it is no edit
            if ( $previous && !empty( $previous['object_id'] ) && (int)$previous['object_id'] !== (int)$object->attribute( 'id' ) )
                $writtenHash = '';
            $writtenFields = $previous && !empty( $previous['written_fields'] ) ? (array)$previous['written_fields'] : array_keys( $attributes );
            $edited = $writtenHash !== '' && $this->contentHash( $object, $writtenFields ) !== $writtenHash;
            if ( $edited && !$this->options['overwrite_flagged'] )
            {
                $this->report['counts']['flagged']++;
                $this->report['flagged'][] = array( 'path' => $path, 'object_id' => (int)$object->attribute( 'id' ), 'reason' => 'changed in the CMS since the last import' );
                $this->state->setPage( $path, array( 'status' => 'flagged', 'source_hash' => $sourceHash ) );
                $this->audit( 'system.staticimport_page.flag', array( 'path' => $path, 'object' => (int)$object->attribute( 'id' ) ) );
                return;
            }
            // unchanged: the same source, and no link that was unresolved before can be resolved now
            $before = $previous && isset( $previous['unresolved'] ) ? (array)$previous['unresolved'] : array();
            if ( !$isRoot )
                $this->moveIfNeeded( $object, $parentNode, $path );
            if ( $previous && isset( $previous['source_hash'] ) && $previous['source_hash'] === $sourceHash && !array_diff( $before, $unresolved ) && !$edited
                 && ( empty( $previous['object_id'] ) || (int)$previous['object_id'] === (int)$object->attribute( 'id' ) ) )
            {
                $this->report['counts']['unchanged']++;
                $this->state->setPage( $path, array( 'status' => 'unchanged' ) );
                return;
            }
            $result = eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $attributes, 'language' => $this->language() ) );
            if ( !$result )
            {
                $this->fail( $path, 'the update was refused', $page );
                return;
            }
            $object = eZContentObject::fetch( (int)$object->attribute( 'id' ) );
            $status = 'updated';
        }
        else
        {
            $params = array( 'parent_node_id' => $parentNode, 'class_identifier' => $page['class'], 'creator_id' => (int)$user->attribute( 'contentobject_id' ),
                             'remote_id' => $remoteID, 'attributes' => $attributes, 'language' => $this->language() );
            $section = $this->sectionFor( $page );
            if ( $section )
                $params['section_id'] = $section;
            $object = eZContentFunctions::createAndPublishObject( $params );
            if ( !$object instanceof eZContentObject )
            {
                $this->fail( $path, "the object of class '{$page['class']}' could not be created under node $parentNode", $page );
                return;
            }
            $status = 'created';
        }

        // read back what was published (datatypes may normalise a value on publish), so the written hash is of what
        // the next run will find
        eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
        $object = eZContentObject::fetch( (int)$object->attribute( 'id' ) );
        $node = $object->attribute( 'main_node' );
        if ( $node instanceof eZContentObjectTreeNode )
        {
            if ( !empty( $page['priority'] ) && (int)$node->attribute( 'priority' ) !== (int)$page['priority'] )
            {
                $node->setAttribute( 'priority', (int)$page['priority'] );
                $node->store();
            }
            if ( !empty( $page['hidden'] ) && !(int)$node->attribute( 'is_hidden' ) )
            {
                eZContentObjectTreeNode::hideSubTree( $node );
                $this->report['counts']['hidden']++;
            }
        }
        $this->report['counts'][$status]++;
        $this->state->setPage( $path, array( 'object_id' => (int)$object->attribute( 'id' ), 'node_id' => $node ? (int)$node->attribute( 'node_id' ) : 0,
                                              'source_hash' => $sourceHash, 'written_hash' => $this->contentHash( $object, array_keys( $attributes ) ),
                                              'written_fields' => array_keys( $attributes ),
                                              'unresolved' => $unresolved,
                                              'status' => $status, 'class' => $page['class'], 'aliases' => isset( $page['aliases'] ) ? $page['aliases'] : array(),
                                              'child_of' => isset( $page['child_of'] ) ? $page['child_of'] : '',
                                              'duplicate_of' => '' ) );
        if ( $unresolved )
        {
            $this->pendingLinks[$path] = true;
            $this->state->data['pending_links'][$path] = true;
        }
        $this->audit( 'system.staticimport_page.' . ( $status === 'created' ? 'create' : 'update' ), array( 'path' => $path, 'object' => (int)$object->attribute( 'id' ) ) );
        foreach ( isset( $page['children'] ) ? (array)$page['children'] : array() as $child )
        {
            $child['child_of'] = isset( $page['child_of'] ) ? $page['child_of'] : $path;
            $this->importParsedPage( $this->type->map( $child ), $user );
        }
    }

    /**
     * An imported page whose parent in the source changed (or was wrong in an earlier run) moves to its parent.
     *
     * @param eZContentObject $object
     * @param int $parentNode
     * @param string $path
     */
    protected function moveIfNeeded( eZContentObject $object, $parentNode, $path )
    {
        $node = $object->attribute( 'main_node' );
        if ( !$node instanceof eZContentObjectTreeNode || !$parentNode || (int)$node->attribute( 'parent_node_id' ) === (int)$parentNode )
            return;
        if ( $this->options['dry_run'] )
            return;
        if ( eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$parentNode ) )
        {
            $this->report['counts']['moved'] = isset( $this->report['counts']['moved'] ) ? $this->report['counts']['moved'] + 1 : 1;
            eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
        }
        else
            $this->report['warnings'][] = "$path could not be moved below node $parentNode";
    }

    /**
     * Links to pages and media already imported become node links and embeds; the others are counted as unresolved
     * and the page is written again in the links phase.
     *
     * @param string $html
     * @param array $links href => target path
     * @param array $page
     * @return array( string html, array unresolved targets )
     */
    protected function rewriteLinks( $html, array $links, array $page )
    {
        $unresolved = array();
        foreach ( $links as $href => $target )
        {
            // page:<id>: the current page with that page id (a Confluence link by id)
            if ( strpos( (string)$target, 'page:' ) === 0 )
            {
                $objectID = $this->objectByPageId( substr( (string)$target, 5 ) );
                $object = $objectID ? eZContentObject::fetch( $objectID ) : null;
                $node = $object ? (int)$object->attribute( 'main_node_id' ) : 0;
            }
            else
                $node = $this->state->nodeOf( $target );
            if ( !$node )
            {
                $unresolved[] = $target;
                continue;
            }
            $fragment = strpos( $href, '#' ) !== false ? substr( $href, strpos( $href, '#' ) ) : '';
            $html = str_replace( array( 'href="' . $href . '"', "href='" . $href . "'" ), 'href="eznode://' . $node . $fragment . '"', $html );
            $this->report['counts']['links']++;
        }
        // images: an <img> whose file was imported becomes an embed of its object
        $state = $this->state;
        $html = preg_replace_callback( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', function ( $m ) use ( $page, $state, &$unresolved ) {
            $target = expStaticImportDom::resolve( $page['path'], $m[1] );
            if ( $target === null )
                return $m[0];
            $asset = $state->asset( $target );
            if ( !$asset || empty( $asset['object_id'] ) )
            {
                $unresolved[] = $target;
                return $m[0];
            }
            $alt = preg_match( '/\balt=["\']([^"\']*)["\']/i', $m[0], $a ) ? $a[1] : '';
            $align = preg_match( '/\bdata-align=["\']([a-z]+)["\']/i', $m[0], $g ) ? ' align="' . $g[1] . '"' : '';
            return '<img id="eZObject_' . (int)$asset['object_id'] . '" src="' . htmlspecialchars( $m[1] ) . '" alt="' . $alt . '"' . $align . ' />';
        }, $html );
        return array( $html, array_values( array_unique( $unresolved ) ) );
    }

    /**
     * The links phase: a page whose links could not all be resolved when it was written is parsed and written again.
     *
     * @param string $path
     * @param eZUser $user
     */
    protected function relinkPage( $path, eZUser $user )
    {
        $entry = $this->state->page( $path );
        if ( !$entry || empty( $entry['object_id'] ) || $this->options['dry_run'] )
            return;
        // only when a link it could not resolve can be resolved now
        $resolvable = false;
        foreach ( isset( $entry['unresolved'] ) ? (array)$entry['unresolved'] : array() as $target )
            if ( $this->state->nodeOf( $target ) || $this->state->asset( $target )
                 || ( strpos( (string)$target, 'page:' ) === 0 && $this->objectByPageId( substr( (string)$target, 5 ) ) ) )
            {
                $resolvable = true;
                break;
            }
        if ( !$resolvable )
        {
            unset( $this->state->data['pending_links'][$path] );
            return;
        }
        // forgetting the source hash makes importPage() write the page again with the links it can now resolve
        $this->state->setPage( $path, array( 'source_hash' => '' ) );
        $this->importPage( $path, $user );
        unset( $this->state->data['pending_links'][$path] );
    }

    /**
     * @param string $path
     * @param eZUser $user
     */
    protected function importAsset( $path, eZUser $user )
    {
        $file = rtrim( $this->source, '/' ) . '/' . $path;
        if ( !is_file( $file ) )
        {
            $this->report['warnings'][] = "missing media file $path";
            return;
        }
        $hash = sha1_file( $file );
        $previous = $this->state->asset( $path );
        if ( $previous && isset( $previous['hash'] ) && $previous['hash'] === $hash && !empty( $previous['object_id'] ) )
            return;
        $class = 'image';
        foreach ( $this->rules->getList( 'AssetClassRules' ) as $rule )
        {
            list( $pattern, $identifier ) = array_map( 'trim', explode( ';', $rule . ';' ) );
            if ( $pattern !== '' && expStaticImportHtmlType::matches( $path, $pattern ) )
            {
                $class = $identifier;
                break;
            }
        }
        $title = ucfirst( str_replace( array( '-', '_' ), ' ', pathinfo( $path, PATHINFO_FILENAME ) ) );
        $fields = array( 'file' => $file, 'title' => $title, 'alt' => $title, 'caption' => '', 'credit' => '', 'source_path' => $path );
        if ( !empty( $this->assetMeta[$path]['alt'] ) )
            $fields['alt'] = (string)$this->assetMeta[$path]['alt'];
        $attributes = array();
        foreach ( $this->rules->getHash( 'AssetAttributeMap' ) as $key => $field )
        {
            list( $mapClass, $attribute ) = array_pad( explode( '/', $key, 2 ), 2, '' );
            if ( ( $mapClass === $class || $mapClass === '*' ) && isset( $fields[$field] ) )
                $attributes[$attribute] = $fields[$field];
        }
        if ( !$attributes )
            $attributes = $class === 'image' ? array( 'name' => $title, 'image' => $file ) : array( 'name' => $title, 'file' => $file );
        $this->report['counts']['assets']++;
        if ( $this->options['dry_run'] )
            return;
        $remoteID = self::remoteID( $this->label . '-media', $path );
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        if ( $object instanceof eZContentObject )
            eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $attributes, 'language' => $this->language() ) );
        else
            $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => $this->mediaParentNode(), 'class_identifier' => $class,
                                                                        'creator_id' => (int)$user->attribute( 'contentobject_id' ), 'remote_id' => $remoteID,
                                                                        'attributes' => $attributes, 'language' => $this->language(),
                                                                        'section_id' => $this->sectionByIdentifier( (string)$this->rules->get( 'MediaSection', '' ) ) ) );
        if ( $object instanceof eZContentObject )
            $this->state->setAsset( $path, array( 'object_id' => (int)$object->attribute( 'id' ), 'node_id' => (int)$object->attribute( 'main_node_id' ), 'hash' => $hash ) );
        else
            $this->report['warnings'][] = "the media file $path could not be imported as $class";
    }

    /**
     * Old paths answer with a redirect: a page's own path (without .html) and each duplicate's path.
     *
     * @param array $paths
     */
    protected function createRedirects( array $paths )
    {
        $prefix = trim( (string)$this->rules->get( 'RedirectPrefix', '' ), '/' );
        foreach ( $paths as $path )
        {
            $entry = $this->state->page( $path );
            if ( !$entry || !empty( $entry['redirected'] ) )
                continue;
            $node = $this->state->nodeOf( $path );
            if ( !$node )
                continue;
            // an alias of the node's own address (linked to the element of its name), never a new original: that
            // would replace the clean address the node has from its name
            $link = self::nameElementID( $node );
            if ( !$link )
                continue;
            // a group page ("display/EZP/") answers for its folder's old address ("display/EZP")
            foreach ( array_unique( substr( $path, -1 ) === '/' ? array( rtrim( $path, '/' ) ) : array( preg_replace( '/\.html?$/i', '', $path ), $path ) ) as $old )
            {
                // a path with a view parameter, such as .../(language)/eng-GB, is no address the site can answer
                if ( strpos( $old, '(' ) !== false || strpos( $old, '#' ) !== false )
                    continue;
                $old = ( $prefix !== '' ? $prefix . '/' : '' ) . $old;
                $result = eZURLAliasML::storePath( $old, 'eznode:' . $node, $this->language() ?: false, $link, true, false, false, false, false, true );
                if ( isset( $result['status'] ) && $result['status'] === true )
                    $this->report['counts']['redirects']++;
            }
            $this->state->setPage( $path, array( 'redirected' => true ) );
        }
    }

    /**
     * @param int $nodeID
     * @return int the id of the URL alias element a node has from its name (0 when it has none yet)
     */
    public static function nameElementID( $nodeID )
    {
        foreach ( (array)eZURLAliasML::fetchByAction( 'eznode', (int)$nodeID ) as $element )
            if ( (int)$element->attribute( 'is_original' ) === 1 && (int)$element->attribute( 'is_alias' ) === 0 )
                return (int)$element->attribute( 'id' );
        return 0;
    }

    /**
     * Pages that left the source: hidden and reported; trashed with --apply-removals.
     *
     * @param array $paths
     */
    protected function handleRemovals( array $paths )
    {
        $present = array_fill_keys( $paths, true );
        foreach ( $this->state->data['pages'] as $path => $entry )
        {
            if ( !empty( $entry['child_of'] ) && isset( $present[$entry['child_of']] ) )
                continue;
            if ( isset( $present[$path] ) || empty( $entry['node_id'] ) || ( isset( $entry['status'] ) && $entry['status'] === 'removed' ) )
                continue;
            $node = eZContentObjectTreeNode::fetch( (int)$entry['node_id'] );
            $this->report['counts']['removed']++;
            $this->report['removed'][] = array( 'path' => $path, 'node_id' => (int)$entry['node_id'], 'trashed' => (bool)$this->options['apply_removals'] );
            if ( $this->options['dry_run'] || !$node instanceof eZContentObjectTreeNode )
                continue;
            if ( $this->options['apply_removals'] )
                eZContentObjectTreeNode::removeSubtrees( array( (int)$entry['node_id'] ), true );
            else if ( !(int)$node->attribute( 'is_hidden' ) )
                eZContentObjectTreeNode::hideSubTree( $node );
            $this->state->setPage( $path, array( 'status' => 'removed' ) );
            $this->audit( 'system.staticimport_page.remove', array( 'path' => $path, 'node' => (int)$entry['node_id'], 'trashed' => (bool)$this->options['apply_removals'] ) );
        }
    }

    /**
     * @param string $html
     * @param string $path
     * @return string|null the XML of the editor's format
     */
    public function toXml( $html, $path = '' )
    {
        if ( trim( $html ) === '' )
            $html = '<p></p>';
        if ( !class_exists( 'eZOEInputParser' ) )
        {
            $this->report['warnings'][] = 'the online editor (ezoe) is not installed; bodies are stored as plain paragraphs';
            $parser = null;
        }
        else
            $parser = new eZOEInputParser();
        if ( $parser )
        {
            $dom = $parser->process( $html );
            if ( $dom instanceof DOMDocument )
                return eZXMLTextType::domString( $dom );
        }
        // fallback: the text in paragraphs, so nothing is lost
        $text = trim( html_entity_decode( strip_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $html ) ), ENT_QUOTES, 'UTF-8' ) );
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
        foreach ( preg_split( '/\n{2,}/', $text ) as $paragraph )
            if ( trim( $paragraph ) !== '' )
                $xml .= '<paragraph>' . htmlspecialchars( trim( $paragraph ), ENT_XML1 ) . '</paragraph>';
        return $xml . '</section>';
    }

    /**
     * The hash of an object's current content in the attributes the import writes.
     *
     * @param eZContentObject $object
     * @param array $identifiers
     * @return string
     */
    protected function contentHash( eZContentObject $object, array $identifiers )
    {
        $dataMap = $object->dataMap();
        $values = array();
        sort( $identifiers );
        foreach ( $identifiers as $identifier )
            if ( isset( $dataMap[$identifier] ) && $identifier !== 'import_hash' )
                $values[] = $identifier . '=' . $dataMap[$identifier]->toString();
        return sha1( implode( "\0", $values ) );
    }

    /**
     * @param string $label
     * @param string $path
     * @return string
     */
    public static function remoteID( $label, $path )
    {
        return 'staticimport-' . $label . '-' . md5( (string)$path );
    }

    protected function fail( $path, $reason, array $page = array() )
    {
        $this->report['counts']['failed']++;
        $entry = array( 'path' => $path, 'reason' => $reason, 'needs_review' => true );
        if ( isset( $page['original_html'] ) )
            $entry['original_bytes'] = strlen( $page['original_html'] );
        $this->report['failed'][] = $entry;
        if ( !$this->options['dry_run'] )
            $this->state->setPage( $path, array( 'status' => 'failed', 'reason' => $reason ) );
    }

    /**
     * @return string the source folder (cloned or pulled when SourceRepository is set and no --source was given)
     */
    protected function prepareSource()
    {
        if ( $this->options['source'] !== '' )
            return rtrim( (string)$this->options['source'], '/' );
        $repository = (string)$this->rules->get( 'SourceRepository', '' );
        $location = (string)$this->rules->get( 'SourceLocation', '' );
        if ( $repository === '' )
        {
            if ( $location !== '' )
                return rtrim( $location, '/' );
            throw new RuntimeException( "no source: give --source, or set SourceRepository or SourceLocation in [Type_{$this->label}]" );
        }
        $dir = $this->workDir . '/source';
        $branch = (string)$this->rules->get( 'SourceBranch', '' );
        if ( !is_dir( $dir . '/.git' ) )
        {
            if ( !is_dir( $this->workDir ) )
                mkdir( $this->workDir, 0775, true );
            $command = 'git clone --depth 1 ' . ( $branch !== '' ? '--branch ' . escapeshellarg( $branch ) . ' ' : '' ) . escapeshellarg( $repository ) . ' ' . escapeshellarg( $dir ) . ' 2>&1';
            exec( $command, $output, $code );
            if ( $code !== 0 )
                throw new RuntimeException( 'git clone failed: ' . implode( ' ', array_slice( $output, -3 ) ) );
        }
        else if ( $this->options['pull'] )
        {
            exec( 'git -C ' . escapeshellarg( $dir ) . ' pull --ff-only 2>&1', $output, $code );
            if ( $code !== 0 )
                $this->report['warnings'][] = 'git pull failed, the import uses the copy it has: ' . implode( ' ', array_slice( $output, -2 ) );
        }
        // GitAuthors=enabled: the history of the repository (who changed which page, and when), kept as a bare clone
        // without file contents (history.git in the work folder), cloned once and fetched with every pull
        if ( $this->rules->enabled( 'GitAuthors', false ) )
        {
            $history = $this->workDir . '/history.git';
            if ( !is_dir( $history ) )
            {
                exec( 'git clone --quiet --bare --filter=blob:none ' . ( $branch !== '' ? '--branch ' . escapeshellarg( $branch ) . ' ' : '' )
                      . escapeshellarg( $repository ) . ' ' . escapeshellarg( $history ) . ' 2>&1', $output, $code );
                if ( $code !== 0 )
                    $this->report['warnings'][] = 'the history clone failed, no authors from git: ' . implode( ' ', array_slice( $output, -2 ) );
            }
            else if ( $this->options['pull'] )
                exec( 'git -C ' . escapeshellarg( $history ) . ' fetch --quiet --filter=blob:none origin ' . ( $branch !== '' ? escapeshellarg( $branch . ':' . $branch ) : '' ) . ' 2>&1', $output, $code );
        }
        return $dir;
    }

    /**
     * @return eZUser
     */
    protected function actAs()
    {
        $login = (string)$this->options['user'];
        if ( $login === '' )
            $login = (string)$this->rules->get( 'ImportUser', eZINI::instance( 'statichtmlimport.ini' )->variable( 'ImportSettings', 'ImportUser' ) );
        $user = eZUser::fetchByName( $login !== '' ? $login : 'admin' );
        if ( !$user instanceof eZUser )
            throw new RuntimeException( "no user '$login' to import as (ImportUser)" );
        eZUser::setCurrentlyLoggedInUser( $user, (int)$user->attribute( 'contentobject_id' ), eZUser::NO_SESSION_REGENERATE );
        // a siteaccess whose content root is the import's top node (a site of a multisite tree, content.ini
        // [NodeSettings] RootNode) would give that node an empty URL alias when it is published (RootPage), and every
        // address below it would break: for this process the content root is the node above it
        $content = eZINI::instance( 'content.ini' );
        $top = eZContentObjectTreeNode::fetch( $this->parentNode() );
        if ( $top && (int)$content->variable( 'NodeSettings', 'RootNode' ) === (int)$top->attribute( 'node_id' ) && (int)$top->attribute( 'parent_node_id' ) > 1 )
            $content->setVariable( 'NodeSettings', 'RootNode', (string)$top->attribute( 'parent_node_id' ) );
        return $user;
    }

    /**
     * @return int
     */
    protected function parentNode()
    {
        if ( (int)$this->options['parent_node'] > 0 )
            return (int)$this->options['parent_node'];
        return $this->nodeSetting( 'ParentNode', 'ParentRemoteID', 2 );
    }

    /**
     * @return int
     */
    protected function mediaParentNode()
    {
        return $this->nodeSetting( 'MediaParentNode', 'MediaParentRemoteID', $this->parentNode() );
    }

    /**
     * @param string $idSetting
     * @param string $remoteSetting
     * @param int $default
     * @return int
     */
    protected function nodeSetting( $idSetting, $remoteSetting, $default )
    {
        $id = (int)$this->rules->get( $idSetting, 0 );
        if ( $id > 0 )
            return $id;
        $remote = (string)$this->rules->get( $remoteSetting, '' );
        if ( $remote !== '' )
        {
            $node = eZContentObjectTreeNode::fetchByRemoteID( $remote );
            if ( $node instanceof eZContentObjectTreeNode )
                return (int)$node->attribute( 'node_id' );
            $object = eZContentObject::fetchByRemoteID( $remote );
            if ( $object instanceof eZContentObject )
                return (int)$object->attribute( 'main_node_id' );
        }
        return (int)$default;
    }

    /**
     * @param array $page
     * @return int the section of a page (SectionMap[] by class, HiddenSection for hidden pages), 0 for the parent's
     */
    protected function sectionFor( array $page )
    {
        if ( !empty( $page['hidden'] ) && ( $hidden = (string)$this->rules->get( 'HiddenSection', '' ) ) !== '' )
            return $this->sectionByIdentifier( $hidden );
        $map = $this->rules->getHash( 'SectionMap' );
        if ( isset( $map[$page['class']] ) )
            return $this->sectionByIdentifier( (string)$map[$page['class']] );
        return isset( $map['*'] ) ? $this->sectionByIdentifier( (string)$map['*'] ) : 0;
    }

    /**
     * @param string $identifier
     * @return int
     */
    protected function sectionByIdentifier( $identifier )
    {
        if ( $identifier === '' )
            return 0;
        if ( ctype_digit( $identifier ) )
            return (int)$identifier;
        $section = eZSection::fetchByIdentifier( $identifier, false );
        return is_array( $section ) ? (int)$section['id'] : 0;
    }

    /**
     * @return string
     */
    protected function language()
    {
        $language = (string)$this->options['language'];
        if ( $language === '' )
            $language = (string)$this->rules->get( 'Language', '' );
        return $language !== '' ? $language : (string)eZContentLanguage::topPriorityLanguage()->attribute( 'locale' );
    }

    /**
     * @return int
     */
    protected function batchSize()
    {
        $size = (int)$this->options['batch'];
        if ( $size <= 0 )
            $size = (int)$this->rules->get( 'BatchSize', (int)eZINI::instance( 'statichtmlimport.ini' )->variable( 'ImportSettings', 'BatchSize' ) );
        return $size > 0 ? $size : 50;
    }

    /**
     * Saves the position and frees memory between batches.
     */
    protected function checkpoint( array &$run, $phase, $position )
    {
        $run['phase'] = $phase;
        $run['position'] = $position;
        $run['counts'] = $this->report['counts'];
        if ( !$this->options['dry_run'] )
        {
            $this->state->data['run'] = $run;
            $this->state->save();
        }
        eZContentObject::clearCache();
        if ( function_exists( 'gc_collect_cycles' ) )
            gc_collect_cycles();
    }

    protected function writeReports()
    {
        $dir = $this->workDir . '/reports';
        if ( !is_dir( $dir ) )
            mkdir( $dir, 0775, true );
        $base = $dir . '/' . $this->report['run'] . ( $this->options['dry_run'] ? '-dry-run' : '' );
        file_put_contents( $base . '.json', json_encode( $this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        file_put_contents( $base . '.md', self::markdown( $this->report ) );
        $this->report['report_files'] = array( $base . '.json', $base . '.md' );
    }

    /**
     * @param array $report
     * @return string the Markdown summary of a run
     */
    public static function markdown( array $report )
    {
        $lines = array( '# Static import ' . $report['type'] . ', run ' . $report['run'], '' );
        $lines[] = ( $report['dry_run'] ? 'A dry run: nothing was written.' : 'Finished ' . ( isset( $report['finished'] ) ? $report['finished'] : '' ) . ' in ' . ( isset( $report['seconds'] ) ? $report['seconds'] : '?' ) . ' s.' );
        $lines[] = '';
        $lines[] = '| What | Count |';
        $lines[] = '|---|---|';
        foreach ( $report['counts'] as $name => $count )
            $lines[] = '| ' . $name . ' | ' . $count . ' |';
        foreach ( array( 'flagged' => 'Changed in the CMS, not overwritten', 'failed' => 'Skipped, needs review', 'removed' => 'Left the source' ) as $key => $title )
        {
            if ( empty( $report[$key] ) )
                continue;
            $lines[] = '';
            $lines[] = '## ' . $title;
            $lines[] = '';
            foreach ( array_slice( $report[$key], 0, 500 ) as $entry )
                $lines[] = '- `' . $entry['path'] . '`' . ( isset( $entry['reason'] ) ? ': ' . $entry['reason'] : '' );
        }
        if ( !empty( $report['byline_failures'] ) )
        {
            $lines[] = '';
            $lines[] = '## Byline lines that could not be read (for review)';
            $lines[] = '';
            foreach ( array_slice( $report['byline_failures'], 0, 500 ) as $entry )
                $lines[] = '- `' . $entry['path'] . '`: ' . $entry['line'];
        }
        if ( !empty( $report['warnings'] ) )
        {
            $lines[] = '';
            $lines[] = '## Warnings';
            $lines[] = '';
            foreach ( array_slice( array_unique( $report['warnings'] ), 0, 200 ) as $warning )
                $lines[] = '- ' . $warning;
        }
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * @param string $event
     * @param array $data
     */
    protected function emit( $event, array $data )
    {
        if ( $this->progress )
            call_user_func( $this->progress, $event, $data );
    }

    /**
     * @param string $name
     * @param array $data
     */
    protected function audit( $name, array $data )
    {
        if ( !class_exists( 'expAudit' ) || $this->options['dry_run'] )
            return;
        try
        {
            expAudit::event( $name, array( 'x' => array( 'staticimport' => array_merge( array( 'type' => $this->label ), $data ) ) ) );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeWarning( 'Audit: ' . $e->getMessage(), __METHOD__ );
        }
    }

    /**
     * @return array the state of a type: last runs, page counts by status, the run in progress
     */
    public static function status( $label )
    {
        $state = new expStaticImportState( self::workDir( $label ) . '/state.json' );
        $byStatus = array();
        foreach ( $state->data['pages'] as $entry )
        {
            $status = isset( $entry['status'] ) ? $entry['status'] : 'unknown';
            $byStatus[$status] = isset( $byStatus[$status] ) ? $byStatus[$status] + 1 : 1;
        }
        return array( 'schema' => self::SCHEMA, 'type' => $label, 'pages' => count( $state->data['pages'] ), 'by_status' => $byStatus,
                      'assets' => count( $state->data['assets'] ), 'run_in_progress' => $state->data['run'], 'runs' => $state->data['runs'] );
    }
}
