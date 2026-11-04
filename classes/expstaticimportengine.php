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

    /** @var expStaticImportDates|null the date rule chain (DateRules[]) */
    protected $datesChain = null;

    /** @var string the source folder the date rule chain was made for */
    protected $datesSource = null;

    /** @var array object id => path, the objects of this run whose dates wait for their children */
    protected $datesPending = array();

    /** @var expStaticImportByline|null the byline reader of the type's rules, for contributor names */
    protected $bylineParser = null;

    /**
     * @return expStaticImportByline
     */
    protected function bylineParser()
    {
        if ( $this->bylineParser === null )
            $this->bylineParser = new expStaticImportByline( $this->rules );
        return $this->bylineParser;
    }

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

        // the sections and containers of this run that have no dates of their own take their children's
        if ( $this->datesPending && !$this->options['dry_run'] )
            $this->report['counts']['dated_by_children'] = $this->datePendingContainers( $this->datesPending );
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
     * The node of a parent path: '' is the import's top node; "@<remote id>" a node outside the import (ParentRules[]);
     * else the page the state knows, or the object a console step made for it (a container) by its remote id.
     *
     * @param string $parentPath
     * @param array $containerNodes group path => node id, made in this step
     * @return int 0 when the parent is not there (yet)
     */
    protected function resolveParentNode( $parentPath, array $containerNodes = array() )
    {
        $parentPath = (string)$parentPath;
        if ( $parentPath === '' )
            return $this->parentNode();
        if ( $parentPath[0] === '@' )
        {
            $object = eZContentObject::fetchByRemoteID( substr( $parentPath, 1 ) );
            return $object instanceof eZContentObject ? (int)$object->attribute( 'main_node_id' ) : 0;
        }
        if ( isset( $containerNodes[$parentPath] ) )
            return (int)$containerNodes[$parentPath];
        if ( $node = $this->state->nodeOf( $parentPath ) )
            return (int)$node;
        $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $parentPath ) );
        return $object instanceof eZContentObject ? (int)$object->attribute( 'main_node_id' ) : 0;
    }

    /**
     * Puts the pages an earlier run imported where the rules send them today (exp:static-import:route), keeping every
     * node id (the kernel's node move; the old addresses stay as history and redirect):
     * - the containers (RouteContainers[], VirtualPages[], with $all every group page) are made with the remote ids a
     *   run gives them, moved below the parent the rules give them and given the title and short title of the rules;
     * - each routed page (RouteRules[]) moves into its container, takes its own name (unique below the container) and
     *   its relation to the current page (history_of), and is shown again if an earlier step hid it;
     * - with $all every other imported page moves below the parent its path, breadcrumb, ParentRules[] or
     *   TopParentRules[] give it, with the title of NameRules[] and a name unique below that parent; a copy of another
     *   page (CopyOfPatterns[]) is hidden and goes into RetiredParent; and the import's leftovers at the top (nodes of
     *   earlier runs that are no pages of the state any more) go where RetiredRules[] send them, hidden.
     * The pages go in batches ($options['batch'] pages each, with a progress event and a cursor file after each, so an
     * interrupted step continues with $options['resume']); $options['limit'] stops after that many pages. The state
     * file is never written: what the next run must know (the new written hash of a renamed page) goes to a state
     * patch. Safe while a run is going on.
     *
     * @param bool $dryRun only count
     * @param bool $all every page, not only the routed ones
     * @param array $options batch, resume, limit
     * @return array the counts and the lists
     */
    public function applyRoutes( $dryRun = false, $all = false, array $options = array() )
    {
        $options += array( 'batch' => 0, 'resume' => false, 'limit' => 0, 'trash_merged' => false );
        $this->options['pull'] = false;
        $this->options['dry_run'] = (bool)$dryRun;
        $this->report = array( 'counts' => array(), 'warnings' => array() );
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $user = $this->actAs();
        // as in a run: the search index is queued for the cronjob indexcontent and the view cache cleared once at the end
        $deferred = $this->deferWork();
        $root = $this->parentNode();
        $countChildren = function ( $node ) { return (int)eZDB::instance()->arrayQuery( "SELECT COUNT(*) AS n FROM ezcontentobject_tree WHERE parent_node_id = " . (int)$node )[0]['n']; };
        $out = array( 'root_children_before' => $countChildren( $root ), 'containers_created' => 0, 'containers' => 0, 'containers_moved' => 0,
                      'retitled' => 0, 'routed' => 0, 'moved' => 0, 'renamed' => 0, 'shown' => 0, 'related' => 0, 'not_imported' => 0,
                      'retired' => 0, 'leftovers' => 0, 'pages' => 0, 'items' => 0, 'start' => 0, 'position' => 0, 'batches' => 0,
                      'complete' => false, 'families' => array(), 'moved_by' => array(), 'renamed_by' => array(), 'unplaced_by' => array(), 'retired_by' => array(),
                      'merged' => 0, 'merged_by' => array(), 'merged_fields_by' => array(), 'patch' => '' );
        $routes = method_exists( $this->type, 'routes' ) ? $this->type->routes() : array();
        // the containers, parents first: without $all only the route containers (the other group pages are the next
        // run's), with $all every group page
        $containerNodes = array();
        $wanted = array();
        foreach ( $routes as $route )
            for ( $dir = $route['container']; $dir !== '' && $dir !== './'; $dir = dirname( rtrim( $dir, '/' ) ) === '.' ? '' : dirname( rtrim( $dir, '/' ) ) . '/' )
                $wanted[$dir] = true;
        foreach ( $paths as $group )
            if ( $all ? substr( $group, -1 ) === '/' : isset( $wanted[$group] ) )
                $this->ensureContainer( $group, $containerNodes, $user, $out );

        // the pages: the routed ones (with $all every imported page)
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
        $retiredNode = $this->retiredNode( $containerNodes, $user, $out );
        $keys = array_map( 'strval', array_keys( $items ) );
        $out['items'] = count( $keys );
        $cursorFile = $this->workDir . '/route-cursor.json';
        $start = 0;
        if ( $options['resume'] && is_file( $cursorFile ) )
        {
            $cursor = json_decode( (string)file_get_contents( $cursorFile ), true );
            if ( is_array( $cursor ) && empty( $cursor['complete'] ) && isset( $cursor['position'], $cursor['items'] ) && (int)$cursor['items'] === count( $keys ) && (bool)$cursor['all'] === (bool)$all )
                $start = (int)$cursor['position'];
            else
                $this->report['warnings'][] = 'the cursor does not fit this step (another list of pages or a finished step): started from the first page';
        }
        $out['start'] = $start;
        $batch = max( 0, (int)$options['batch'] );
        $limit = max( 0, (int)$options['limit'] );
        $patch = array();
        $i = $start;
        for ( $n = count( $keys ); $i < $n; $i++ )
        {
            if ( $limit && $i - $start >= $limit )
                break;
            $path = $keys[$i];
            $route = $items[$path];
            if ( ( $i - $start + 1 ) % 50 === 0 )
                eZContentObject::clearCache();
            if ( $route === null )
                $this->placePage( $path, $root, $retiredNode, $containerNodes, $user, $patch, $out );
            else
                $this->placeRoutedPage( $path, $route, $containerNodes, $patch, $out );
            if ( $batch && ( $i - $start + 1 ) % $batch === 0 )
            {
                $out['batches']++;
                if ( !$dryRun )
                {
                    file_put_contents( $cursorFile, json_encode( array( 'position' => $i + 1, 'items' => count( $keys ), 'all' => (bool)$all, 'time' => time() ) ) );
                    // what the next run must know, after every batch: an interrupted step loses none of it
                    if ( $patch )
                        $this->writeStatePatch( $patch, 'route-' . ( $i + 1 ) );
                    $patch = array();
                }
                $this->emit( 'route', array( 'done' => $i + 1, 'of' => count( $keys ), 'moved' => $out['moved'], 'renamed' => $out['renamed'],
                                             'retired' => $out['retired'], 'root_children' => $countChildren( $root ) ) );
            }
        }
        $out['position'] = $i;
        $out['complete'] = $i >= count( $keys );

        // with $all: the import's leftovers at the top (nodes of earlier runs that are no pages of the state: pages
        // that became redirects, copies or no pages) go where RetiredRules[] send them, else into RetiredParent
        // the copies merged into their pages: links and relations moved to the page, then the copies trashed or hidden
        $this->finishMerges( $retiredNode, !empty( $options['trash_merged'] ), $out );
        if ( $all && $out['complete'] )
            $this->placeLeftovers( $root, $retiredNode, $containerNodes, $user, $out );

        if ( !$dryRun )
        {
            file_put_contents( $cursorFile, json_encode( array( 'position' => $i, 'items' => count( $keys ), 'all' => (bool)$all, 'time' => time(), 'complete' => $out['complete'] ) ) );
            $out['patch'] = $this->writeStatePatch( $patch, 'route' );
            eZContentCacheManager::clearAllContentCache( true );
        }
        $this->report['counts'] += array( 'created' => 0, 'updated' => 0 );
        $this->finishDeferredWork( $deferred );
        $out['root_children_after'] = $countChildren( $root );
        $out['warnings'] = array_slice( $this->report['warnings'], 0, 50 );
        return $out;
    }

    /**
     * Makes a container (a group page) when it is missing, else moves it below the parent the rules give it and gives
     * it the title, short title and string values of the rules (a renamed product, a version's number).
     *
     * @param string $group
     * @param array $containerNodes group path => node id (-1 for one a dry run would make)
     * @param eZUser $user
     * @param array $out
     * @return int the node, 0 when its parent is not there
     */
    protected function ensureContainer( $group, array &$containerNodes, eZUser $user, array &$out )
    {
        if ( isset( $containerNodes[$group] ) )
            return $containerNodes[$group];
        $dryRun = (bool)$this->options['dry_run'];
        $page = $this->type->map( $this->type->parse( $this->source, $group ) );
        $parent = $this->resolveParentNode( $page['parent_path'], $containerNodes );
        if ( !$parent )
        {
            $this->report['warnings'][] = "$group: its parent {$page['parent_path']} is not there, the container is left out";
            return 0;
        }
        $remoteID = self::remoteID( $this->label, $group );
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        $out['containers']++;
        if ( !$object )
        {
            $out['containers_created']++;
            if ( $dryRun || $parent < 0 )
                return $containerNodes[$group] = -1;
            $attributes = array();
            foreach ( $page['attributes'] as $identifier => $value )
                if ( $value === '{body}' )
                    $attributes[$identifier] = (string)$this->toXml( $page['body_html'], $group );
                else if ( $value !== '{hash}' && strpos( (string)$value, '{' ) !== 0 )
                    $attributes[$identifier] = $value;
            // no two containers of one name below one parent
            $name = $this->uniqueSiblingName( $parent, $page['title'], 0, $page );
            if ( $name !== null && $name !== $page['title'] && isset( $attributes['title'] ) )
                $attributes['title'] = $name;
            $object = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => $parent, 'class_identifier' => $page['class'],
                'creator_id' => (int)$user->attribute( 'contentobject_id' ), 'remote_id' => $remoteID, 'attributes' => $attributes,
                'language' => $this->language(), 'section_id' => $this->sectionFor( $page ) ) );
            if ( !$object instanceof eZContentObject )
            {
                $this->report['warnings'][] = "$group: the container could not be made";
                return 0;
            }
        }
        else
        {
            $node = $object->attribute( 'main_node' );
            if ( $node instanceof eZContentObjectTreeNode && $parent > 0 && (int)$node->attribute( 'parent_node_id' ) !== (int)$parent && (int)$node->attribute( 'node_id' ) !== (int)$parent )
            {
                $out['containers_moved']++;
                if ( !$dryRun )
                    eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$parent );
            }
            // the string values of the rules (title, short title, version ...) where they differ
            $dataMap = $object->dataMap();
            $changes = array();
            foreach ( $page['attributes'] as $identifier => $value )
            {
                if ( !isset( $dataMap[$identifier] ) || !is_string( $value ) || ( $value !== '' && $value[0] === '{' ) || $identifier === 'source_path' )
                    continue;
                if ( $dataMap[$identifier]->attribute( 'data_type_string' ) !== 'ezstring' )
                    continue;
                if ( trim( (string)$dataMap[$identifier]->toString() ) !== trim( $value ) )
                    $changes[$identifier] = $value;
            }
            if ( isset( $changes['title'] ) && $parent > 0 )
            {
                $name = $this->uniqueSiblingName( $parent, $changes['title'], (int)$object->attribute( 'id' ), $page );
                if ( $name !== null )
                    $changes['title'] = $name;
            }
            if ( $changes )
            {
                $out['retitled']++;
                if ( !$dryRun )
                {
                    eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $changes, 'language' => $this->language() ) );
                    eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                    $object = eZContentObject::fetch( (int)$object->attribute( 'id' ) );
                }
            }
        }
        $containerNodes[$group] = (int)$object->attribute( 'main_node_id' );
        if ( !empty( $page['history_of'] ) && !$dryRun )
            $out['related'] += $this->setRelation( $object, 'history_of', $this->objectByPageId( $page['history_of'] ) );
        return $containerNodes[$group];
    }

    /**
     * The node RetiredParent names (a container or page path), made when it is a container the rules know.
     *
     * @return int 0 when there is none
     */
    protected function retiredNode( array &$containerNodes, eZUser $user, array &$out )
    {
        $retired = (string)$this->rules->get( 'RetiredParent', '' );
        if ( $retired === '' )
            return 0;
        if ( substr( $retired, -1 ) === '/' && method_exists( $this->type, 'isGroup' ) && $this->type->isGroup( $retired ) )
            return (int)$this->ensureContainer( $retired, $containerNodes, $user, $out );
        return $this->resolveParentNode( $retired, $containerNodes );
    }

    /**
     * A page that is not routed: below the parent its path, breadcrumb, ParentRules[] or TopParentRules[] give it today,
     * with the title of NameRules[] and a name unique below that parent; a copy of another page is retired.
     */
    protected function placePage( $path, $root, $retiredNode, array &$containerNodes, eZUser $user, array &$patch, array &$out )
    {
        $dryRun = (bool)$this->options['dry_run'];
        $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $path ) );
        $node = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
        if ( !$node instanceof eZContentObjectTreeNode )
            return;
        // a copy of another page (CopyOfPatterns[], FolderIndexCopies: Exponential/index.html of Exponential.html) is no
        // page of its own: merged into the page it copies (mergeCopy())
        if ( method_exists( $this->type, 'copyOf' ) && ( $canonical = $this->type->copyOf( $path ) ) !== '' )
        {
            $keep = $this->resolveParentNode( $canonical, $containerNodes );
            $keepNode = $keep > 0 && $keep !== (int)$node->attribute( 'node_id' ) ? eZContentObjectTreeNode::fetch( $keep ) : null;
            if ( $keepNode instanceof eZContentObjectTreeNode )
                $this->mergeCopy( $path, $canonical, $object, $node, $keepNode, $patch, $out );
            else if ( $retiredNode )
                $this->retireNode( $node, $retiredNode, $out );
            return;
        }
        // an error page an earlier run imported as a page (a rule of RetiredRules[] with a name check: "Page Not
        // Found") goes where that rule says, hidden, named by its file
        if ( $retired = $this->retiredRule( $path, (string)$object->attribute( 'name' ), $containerNodes, $user, $out, true ) )
        {
            $this->retireInto( $object, $node, $retired[0], $retired[1], $path, $out );
            return;
        }
        $parentPath = $this->type->parentPath( $path );
        $target = $this->resolveParentNode( $parentPath, $containerNodes );
        if ( !$target || (int)$target === (int)$node->attribute( 'node_id' ) )
        {
            if ( !$target && $parentPath !== '' )
            {
                $this->tally( $out, 'unplaced_by', $path, "its parent $parentPath is not imported yet" );
                $this->report['warnings'][] = "$path: its parent $parentPath is not imported yet";
            }
            return;
        }
        $out['pages']++;
        if ( (int)$node->attribute( 'parent_node_id' ) !== (int)$target )
        {
            $out['moved']++;
            $this->tally( $out, 'moved_by', $path, (int)$node->attribute( 'parent_node_id' ) . ' -> ' . $target );
            if ( !$dryRun && $target > 0 && !eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$target ) )
                $this->report['warnings'][] = "$path could not be moved below node $target";
        }
        // the title NameRules[] give it (the version page of a manual below its version: "Technical manual 4.7")
        $current = (string)$object->attribute( 'name' );
        $want = $current;
        $short = '';
        $dataMap = $object->dataMap();
        $nameValues = array( 'title' => isset( $dataMap['title'] ) ? trim( (string)$dataMap['title']->toString() ) : $current,
                             'page_id' => isset( $dataMap['source_page_id'] ) ? trim( (string)$dataMap['source_page_id']->toString() ) : '' );
        // a rule that takes the page's own title reads it from the source (the stored one may carry a rule's name
        // already), so the step names the page as a run would, and a second step changes nothing
        foreach ( $this->rules->getList( 'NameRules' ) as $rule )
        {
            list( $pattern, $titlePattern ) = array_pad( array_map( 'trim', explode( ';', $rule, 3 ) ), 2, '' );
            if ( $pattern === '' || !expStaticImportHtmlType::matches( $path, $pattern ) )
                continue;
            if ( strpos( $titlePattern, '{title' ) !== false )
            {
                try
                {
                    $parsed = $this->type->parse( $this->source, $path );
                    if ( is_array( $parsed ) && isset( $parsed['title'] ) && trim( (string)$parsed['title'] ) !== '' )
                        $nameValues['title'] = trim( (string)$parsed['title'] );
                }
                catch ( expStaticImportPageException $e )
                {
                }
            }
            break;
        }
        if ( method_exists( $this->type, 'nameRule' ) && ( $named = $this->type->nameRule( $path, $nameValues ) ) )
            list( $want, $short ) = $named;
        $name = $target > 0 ? $this->uniqueSiblingName( (int)$target, $want, (int)$object->attribute( 'id' ), array( 'path' => $path, 'meta' => array( 'page_id' => $nameValues['page_id'] ) ) ) : $want;
        $shortNow = isset( $dataMap['short_title'] ) ? trim( (string)$dataMap['short_title']->toString() ) : '';
        if ( $name !== null && ( $name !== $current || ( $short !== '' && $short !== $shortNow ) ) )
        {
            $out['renamed']++;
            $this->tally( $out, 'renamed_by', $path, "'$current' -> '$name'" . ( $short !== '' ? " ($short)" : '' ) );
            if ( !$dryRun )
                $this->renameObject( $object, $name, $short );
            if ( !$dryRun && ( $entry = $this->state->page( $path ) ) && !empty( $entry['written_fields'] ) )
            {
                eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                $patch[$path] = array( 'written_hash' => $this->contentHash( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), (array)$entry['written_fields'] ) );
            }
        }
    }

    /**
     * A routed page (RouteRules[]): into its container, with its own name and its relation to the current page.
     */
    protected function placeRoutedPage( $path, array $route, array $containerNodes, array &$patch, array &$out )
    {
        $dryRun = (bool)$this->options['dry_run'];
        $family = preg_replace( '/[0-9a-f]{4}(-)?\.html$|\.html$/', '', basename( $path ) );
        $out['families'][$family] = ( isset( $out['families'][$family] ) ? $out['families'][$family] : 0 ) + 1;
        $object = eZContentObject::fetchByRemoteID( self::remoteID( $this->label, $path ) );
        if ( !$object instanceof eZContentObject )
        {
            $out['not_imported']++;
            return;
        }
        $out['routed']++;
        $container = isset( $containerNodes[$route['container']] ) ? (int)$containerNodes[$route['container']] : 0;
        $node = $object->attribute( 'main_node' );
        if ( !$container || !$node instanceof eZContentObjectTreeNode )
            return;
        if ( (int)$node->attribute( 'parent_node_id' ) !== $container )
        {
            $out['moved']++;
            if ( !$dryRun && $container > 0 )
                eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), $container );
        }
        if ( (int)$node->attribute( 'is_hidden' ) && !expStaticImportHtmlType::matchesAny( $path, $this->rules->getList( 'HiddenPaths' ) ) )
        {
            $out['shown']++;
            if ( !$dryRun )
                eZContentObjectTreeNode::unhideSubTree( $node );
        }
        $name = $container > 0 ? $this->uniqueSiblingName( $container, $route['name'], (int)$object->attribute( 'id' ), array( 'path' => $path, 'route_meta' => $route['meta'], 'meta' => array( 'page_id' => isset( $route['meta']['page_id'] ) ? $route['meta']['page_id'] : '' ) ) ) : $route['name'];
        if ( $name !== null && $name !== (string)$object->attribute( 'name' ) )
        {
            $out['renamed']++;
            if ( !$dryRun )
                $this->renameObject( $object, $name );
        }
        if ( !$dryRun && $route['current'] !== '' )
            $out['related'] += $this->setRelation( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), 'history_of', $this->objectByPageId( $route['current'] ) );
        if ( !$dryRun && ( $entry = $this->state->page( $path ) ) && !empty( $entry['written_fields'] ) )
        {
            eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
            $patch[$path] = array( 'written_hash' => $this->contentHash( eZContentObject::fetch( (int)$object->attribute( 'id' ) ), (array)$entry['written_fields'] ) );
        }
    }

    /** @var array copy node id => the node it is merged into (mergeCopy()) */
    protected $mergedNodes = array();

    /** @var array copy object id => the object it is merged into */
    protected $mergedObjects = array();

    /** @var array copy path => array( node, keep node, old addresses ) */
    protected $mergedCopies = array();

    /**
     * A copy of a page (copyOf()) that an earlier run imported as a node of its own goes into the page it copies:
     * - its children move below that page (a child the page has already, by class and source comment id, source path
     *   or name, stays with the copy), with names unique there;
     * - the page takes the copy's contributors and maintainers it lacks, and its writer, updater, dates and page id
     *   where it has none;
     * - after every page of the step: the links and relations to the copy point at the page (finishMerges()), and the
     *   copy goes to the trash (with $options['trash_merged']; else it is hidden in RetiredParent), its addresses and
     *   source path then redirect to the page.
     * The state patch records the copy's path as a duplicate of the page.
     */
    protected function mergeCopy( $path, $canonical, eZContentObject $object, eZContentObjectTreeNode $node, eZContentObjectTreeNode $keepNode, array &$patch, array &$out )
    {
        $dryRun = (bool)$this->options['dry_run'];
        $keepObject = $keepNode->object();
        $out['merged']++;
        $this->tally( $out, 'merged_by', $path, 'node ' . $node->attribute( 'node_id' ) . ' into node ' . $keepNode->attribute( 'node_id' ) . " ($canonical)" );
        $key = function ( eZContentObjectTreeNode $child )
        {
            $map = $child->dataMap();
            foreach ( array( 'source_comment_id', 'source_path' ) as $identifier )
                if ( isset( $map[$identifier] ) && trim( (string)$map[$identifier]->toString() ) !== '' )
                    return $child->attribute( 'class_identifier' ) . "\0" . $identifier . "\0" . trim( (string)$map[$identifier]->toString() );
            return $child->attribute( 'class_identifier' ) . "\0name\0" . mb_strtolower( (string)$child->attribute( 'name' ), 'UTF-8' );
        };
        $have = array();
        foreach ( $keepNode->children() as $child )
            $have[$key( $child )] = true;
        foreach ( $node->children() as $child )
        {
            if ( isset( $have[$key( $child )] ) )
            {
                $out['merge_children_kept_with_copy'] = ( isset( $out['merge_children_kept_with_copy'] ) ? $out['merge_children_kept_with_copy'] : 0 ) + 1;
                continue;
            }
            $out['merge_children_moved'] = ( isset( $out['merge_children_moved'] ) ? $out['merge_children_moved'] : 0 ) + 1;
            $childObject = $child->object();
            $name = $this->uniqueSiblingName( (int)$keepNode->attribute( 'node_id' ), (string)$childObject->attribute( 'name' ), (int)$childObject->attribute( 'id' ), array( 'path' => $path, 'meta' => array() ) );
            if ( $dryRun )
                continue;
            eZContentObjectTreeNodeOperations::move( (int)$child->attribute( 'node_id' ), (int)$keepNode->attribute( 'node_id' ) );
            if ( $name !== null && $name !== (string)$childObject->attribute( 'name' ) )
                $this->renameObject( $childObject, $name );
        }
        // the people and the page id the page lacks
        $changes = array();
        $keepMap = $keepObject->dataMap();
        $copyMap = $object->dataMap();
        foreach ( array( 'contributors', 'maintainers' ) as $identifier )
        {
            if ( !isset( $keepMap[$identifier], $copyMap[$identifier] ) )
                continue;
            $ids = self::relationIDs( $keepMap[$identifier] );
            $all = array_values( array_unique( array_merge( $ids, self::relationIDs( $copyMap[$identifier] ) ) ) );
            if ( count( $all ) > count( $ids ) )
                $changes[$identifier] = implode( '-', $all );
        }
        foreach ( array( 'written_by', 'written_at', 'last_updated_by', 'last_updated_at', 'source_page_id' ) as $identifier )
            if ( isset( $keepMap[$identifier], $copyMap[$identifier] ) && !$keepMap[$identifier]->hasContent() && $copyMap[$identifier]->hasContent() )
                $changes[$identifier] = (string)$copyMap[$identifier]->toString();
        if ( $changes )
        {
            $out['merge_fields'] = ( isset( $out['merge_fields'] ) ? $out['merge_fields'] : 0 ) + count( $changes );
            $this->tally( $out, 'merged_fields_by', $path, implode( ', ', array_keys( $changes ) ) );
            if ( !$dryRun )
                eZContentFunctions::updateAndPublishObject( $keepObject, array( 'attributes' => $changes, 'language' => $this->language() ) );
        }
        $addresses = array( (string)$node->attribute( 'url_alias' ) );
        if ( strpos( $path, '(' ) === false )
        {
            $addresses[] = preg_replace( '/\.html?$/i', '', $path );
            $addresses[] = $path;
        }
        $this->mergedNodes[(int)$node->attribute( 'node_id' )] = (int)$keepNode->attribute( 'node_id' );
        $this->mergedObjects[(int)$object->attribute( 'id' )] = (int)$keepObject->attribute( 'id' );
        $this->mergedCopies[$path] = array( 'node' => (int)$node->attribute( 'node_id' ), 'object' => (int)$object->attribute( 'id' ),
                                            'keep' => (int)$keepNode->attribute( 'node_id' ), 'addresses' => array_values( array_unique( array_filter( $addresses ) ) ) );
        $patch[$path] = array( 'status' => 'duplicate', 'duplicate_of' => $canonical, 'node_id' => 0, 'object_id' => 0, 'redirected' => true );
    }

    /**
     * @return int[] the object ids of a relation list attribute
     */
    protected static function relationIDs( eZContentObjectAttribute $attribute )
    {
        $ids = array();
        $content = $attribute->content();
        foreach ( isset( $content['relation_list'] ) ? $content['relation_list'] : array() as $item )
            $ids[] = (int)$item['contentobject_id'];
        return $ids;
    }

    /**
     * After the pages of a step: the links (node_id / object_id in the XML of the current versions) and the object
     * relations to the merged copies point at the pages they were merged into; then each copy that nothing refers to
     * any more goes to the trash ($trash) and its addresses redirect to the page, or it is hidden in RetiredParent.
     */
    protected function finishMerges( $retiredNode, $trash, array &$out )
    {
        if ( !$this->mergedCopies )
            return;
        $dryRun = (bool)$this->options['dry_run'];
        $db = eZDB::instance();
        $nodes = $this->mergedNodes;
        $objects = $this->mergedObjects;
        // the object relations
        foreach ( $objects as $from => $to )
        {
            foreach ( (array)$db->arrayQuery( "SELECT id, from_contentobject_id, from_contentobject_version, contentclassattribute_id, relation_type FROM ezcontentobject_link WHERE to_contentobject_id = " . (int)$from ) as $row )
            {
                $out['merge_relations'] = ( isset( $out['merge_relations'] ) ? $out['merge_relations'] : 0 ) + 1;
                if ( $dryRun )
                    continue;
                $same = $db->arrayQuery( "SELECT id FROM ezcontentobject_link WHERE to_contentobject_id = " . (int)$to . " AND from_contentobject_id = " . (int)$row['from_contentobject_id']
                                         . " AND from_contentobject_version = " . (int)$row['from_contentobject_version'] . " AND contentclassattribute_id = " . (int)$row['contentclassattribute_id'] );
                if ( $same || (int)$row['from_contentobject_id'] === (int)$to )
                    $db->query( "DELETE FROM ezcontentobject_link WHERE id = " . (int)$row['id'] );
                else
                    $db->query( "UPDATE ezcontentobject_link SET to_contentobject_id = " . (int)$to . " WHERE id = " . (int)$row['id'] );
            }
        }
        // the links in the XML of the current versions
        $last = 0;
        $rewrite = function ( $m ) use ( $nodes, $objects )
        {
            $map = $m[1] === 'node_id' ? $nodes : $objects;
            return isset( $map[(int)$m[2]] ) ? $m[1] . '="' . $map[(int)$m[2]] . '"' : $m[0];
        };
        do
        {
            $rows = $db->arrayQuery( "SELECT a.id, a.version, a.data_text FROM ezcontentobject_attribute a JOIN ezcontentobject o ON o.id = a.contentobject_id AND o.current_version = a.version
                                      WHERE a.data_type_string = 'ezxmltext' AND a.id > " . (int)$last . " AND ( a.data_text LIKE '%node_id=%' OR a.data_text LIKE '%object_id=%' ) ORDER BY a.id LIMIT 1000" );
            foreach ( (array)$rows as $row )
            {
                $last = (int)$row['id'];
                $text = preg_replace_callback( '/\b(node_id|object_id)="(\d+)"/', $rewrite, (string)$row['data_text'] );
                if ( $text === (string)$row['data_text'] )
                    continue;
                $out['merge_links'] = ( isset( $out['merge_links'] ) ? $out['merge_links'] : 0 ) + 1;
                if ( !$dryRun )
                    $db->query( "UPDATE ezcontentobject_attribute SET data_text = '" . $db->escapeString( $text ) . "' WHERE id = " . (int)$row['id'] . " AND version = " . (int)$row['version'] );
            }
        }
        while ( $rows );
        // the copies: to the trash when nothing refers to them any more, and their addresses to the page
        foreach ( $this->mergedCopies as $path => $copy )
        {
            $node = eZContentObjectTreeNode::fetch( $copy['node'] );
            if ( !$node instanceof eZContentObjectTreeNode )
                continue;
            $left = $dryRun ? array( array( 'n' => 0 ) ) : $db->arrayQuery( "SELECT COUNT(*) AS n FROM ezcontentobject_link WHERE to_contentobject_id = " . (int)$copy['object'] );
            if ( !$trash || (int)$left[0]['n'] > 0 )
            {
                if ( $trash )
                    $this->report['warnings'][] = "$path: still referred to by {$left[0]['n']} relation(s), hidden instead of trashed";
                if ( $retiredNode )
                    $this->retireNode( $node, $retiredNode, $out );
                continue;
            }
            $out['merged_trashed'] = ( isset( $out['merged_trashed'] ) ? $out['merged_trashed'] : 0 ) + 1;
            if ( $dryRun )
                continue;
            eZContentObjectTreeNode::removeSubtrees( array( (int)$copy['node'] ), true );
            $link = self::nameElementID( $copy['keep'] );
            foreach ( $copy['addresses'] as $old )
            {
                if ( !$link || strpos( $old, '(' ) !== false || strpos( $old, '#' ) !== false )
                    continue;
                $result = eZURLAliasML::storePath( $old, 'eznode:' . $copy['keep'], $this->language() ?: false, $link, true, false, false, false, false, true );
                if ( isset( $result['status'] ) && $result['status'] === true )
                    $out['merge_redirects'] = ( isset( $out['merge_redirects'] ) ? $out['merge_redirects'] : 0 ) + 1;
            }
        }
    }

    /**
     * Counts a change by the family of its path (the first two folders) and keeps the first examples of each, for the
     * report of a step.
     */
    protected function tally( array &$out, $key, $path, $what )
    {
        $parts = explode( '/', (string)$path );
        $family = implode( '/', array_slice( $parts, 0, min( 2, max( 1, count( $parts ) - 1 ) ) ) );
        if ( !isset( $out[$key][$family] ) )
            $out[$key][$family] = array( 'count' => 0, 'examples' => array() );
        $out[$key][$family]['count']++;
        if ( count( $out[$key][$family]['examples'] ) < 3 )
            $out[$key][$family]['examples'][] = $path . ': ' . $what;
    }

    /**
     * Hides a node and moves it into the retired container.
     */
    protected function retireNode( eZContentObjectTreeNode $node, $retiredNode, array &$out )
    {
        if ( (int)$node->attribute( 'parent_node_id' ) === (int)$retiredNode && (int)$node->attribute( 'is_hidden' ) )
            return;
        $out['retired']++;
        if ( $this->options['dry_run'] || $retiredNode <= 0 )
            return;
        if ( !(int)$node->attribute( 'is_hidden' ) )
            eZContentObjectTreeNode::hideSubTree( $node );
        if ( (int)$node->attribute( 'parent_node_id' ) !== (int)$retiredNode )
            eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$retiredNode );
    }

    /**
     * The first rule of RetiredRules[] for a node of the import, by its source path and its name:
     *   RetiredRules[]=<shell pattern of its source path>;<container path>[;<name pattern>[;<regular expression on its name>]]
     * The container path and the name pattern take the values of the source path ({file}, {seg2} ...: pathValues())
     * and {title}, the node's name; a container of RouteContainers[] is made when it is missing. With $checkedOnly only
     * the rules with a name check count (an error page an earlier run imported as a page: "Page Not Found").
     *
     * @return array|null array( node id of the container (-1 in a dry run), the name )
     */
    protected function retiredRule( $source, $name, array &$containerNodes, eZUser $user, array &$out, $checkedOnly = false )
    {
        foreach ( $this->rules->getList( 'RetiredRules' ) as $line )
        {
            $rule = array_pad( array_map( 'trim', explode( ';', $line, 4 ) ), 4, '' );
            if ( $rule[0] === '' || $rule[1] === '' || ( $checkedOnly && $rule[3] === '' ) || !expStaticImportHtmlType::matches( $source, $rule[0] )
                 || ( $rule[3] !== '' && !@preg_match( $rule[3], $name ) ) )
                continue;
            $values = ( method_exists( $this->type, 'pathValues' ) ? $this->type->pathValues( $source ) : array() ) + array( 'title' => $name );
            $container = expStaticImportHtmlType::fill( $rule[1], $values );
            $target = 0;
            if ( substr( $container, -1 ) === '/' && method_exists( $this->type, 'addContainer' ) )
            {
                if ( !$this->type->isGroup( $container ) )
                    $this->type->addContainer( $container, $values, array() );
                if ( $this->type->isGroup( $container ) )
                    $target = (int)$this->ensureContainer( $container, $containerNodes, $user, $out );
            }
            if ( !$target )
                $target = $this->resolveParentNode( $container, $containerNodes );
            if ( !$target )
                return null;
            $filled = $rule[2] !== '' ? expStaticImportHtmlType::fillStrict( $rule[2], $values ) : null;
            return array( $target, $filled !== null && trim( $filled ) !== '' ? trim( $filled ) : $name );
        }
        return null;
    }

    /**
     * Moves a node that is no page into its container, hidden, with a name unique there.
     */
    protected function retireInto( eZContentObject $object, eZContentObjectTreeNode $node, $target, $name, $source, array &$out )
    {
        $dryRun = (bool)$this->options['dry_run'];
        $current = (string)$object->attribute( 'name' );
        if ( (int)$node->attribute( 'parent_node_id' ) === (int)$target && (int)$node->attribute( 'is_hidden' ) && $current === $name )
            return;
        $out['retired']++;
        $this->tally( $out, 'retired_by', $source, "'$current' -> '$name' below node $target" );
        if ( $target > 0 )
        {
            $unique = $this->uniqueSiblingName( (int)$target, $name, (int)$object->attribute( 'id' ), array( 'path' => $source, 'meta' => array() ) );
            if ( $unique !== null && $unique !== $current )
            {
                $out['renamed']++;
                if ( !$dryRun )
                    $this->renameObject( $object, $unique );
            }
        }
        if ( $dryRun || $target <= 0 )
            return;
        if ( !(int)$node->attribute( 'is_hidden' ) )
            eZContentObjectTreeNode::hideSubTree( $node );
        if ( (int)$node->attribute( 'parent_node_id' ) !== (int)$target )
            eZContentObjectTreeNodeOperations::move( (int)$node->attribute( 'node_id' ), (int)$target );
    }

    /**
     * The import's nodes at the top that are no pages of the state (left by earlier runs: redirects, copies, error
     * pages): each goes where the first rule of RetiredRules[] that matches sends it, else into RetiredParent, hidden.
     */
    protected function placeLeftovers( $root, $retiredNode, array &$containerNodes, eZUser $user, array &$out )
    {
        $managed = array();
        foreach ( $this->state->data['pages'] as $path => $entry )
            if ( !empty( $entry['node_id'] ) && ( !isset( $entry['status'] ) || !in_array( $entry['status'], array( 'removed', 'duplicate', 'failed' ), true ) ) && strpos( (string)$path, '#' ) === false )
                $managed[(int)$entry['node_id']] = true;
        foreach ( $containerNodes as $nodeID )
            $managed[(int)$nodeID] = true;
        $db = eZDB::instance();
        $like = $db->escapeString( 'staticimport-' . $this->label . '-' );
        foreach ( (array)$db->arrayQuery( "SELECT t.node_id, o.id FROM ezcontentobject_tree t JOIN ezcontentobject o ON o.id = t.contentobject_id
                                           WHERE t.parent_node_id = " . (int)$root . " AND o.remote_id LIKE '$like%'" ) as $row )
        {
            if ( isset( $managed[(int)$row['node_id']] ) || (int)$row['node_id'] === (int)$retiredNode )
                continue;
            $object = eZContentObject::fetch( (int)$row['id'] );
            $node = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
            if ( !$node instanceof eZContentObjectTreeNode )
                continue;
            $dataMap = $object->dataMap();
            $source = isset( $dataMap['source_path'] ) ? trim( (string)$dataMap['source_path']->toString() ) : '';
            $current = (string)$object->attribute( 'name' );
            $retired = $this->retiredRule( $source, $current, $containerNodes, $user, $out );
            $target = $retired ? $retired[0] : $retiredNode;
            if ( !$target )
                continue;
            $out['leftovers']++;
            $this->retireInto( $object, $node, $target, $retired ? $retired[1] : $current, $source, $out );
        }
    }

    /**
     * Publishes a new name of an object into the attributes AttributeMap[] gives the fields title and short_title (the
     * short title is the name too, unless one is given: the name patterns of the classes read either).
     *
     * @param eZContentObject $object
     * @param string $name
     * @param string $short the short title ('' for the name)
     */
    protected function renameObject( eZContentObject $object, $name, $short = '' )
    {
        $titles = array();
        $dataMap = $object->dataMap();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
        {
            list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
            if ( ( $field === 'title' || $field === 'short_title' ) && ( $mapClass === '*' || $mapClass === (string)$object->attribute( 'class_identifier' ) ) && isset( $dataMap[$identifier] ) )
                $titles[$identifier] = $field === 'title' ? $name : ( $short !== '' ? $short : $name );
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
    public function relinkExisting( array $patterns = array(), $dryRun = false, array $markers = array() )
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
        // the pages a page carries (its comments, "<page>#comment<id>"), by page, read once
        $carried = array();
        foreach ( array_keys( $this->state->data['pages'] ) as $childPath )
            if ( ( $hash = strpos( (string)$childPath, '#' ) ) !== false )
                $carried[substr( (string)$childPath, 0, $hash )][] = (string)$childPath;
        foreach ( array_keys( $this->state->data['pages'] ) as $path )
        {
            $path = (string)$path;
            $entry = $this->state->page( $path );
            // (with $markers only the comments are written, so a page edited in the CMS, flagged, keeps its own text)
            if ( empty( $entry['object_id'] ) || ( empty( $entry['unresolved'] ) && !$markers ) || strpos( $path, '#' ) !== false
                 || ( isset( $entry['status'] ) && in_array( $entry['status'], $markers ? array( 'removed', 'duplicate', 'failed' ) : array( 'removed', 'duplicate', 'failed', 'flagged' ), true ) ) )
                continue;
            if ( $patterns && !expStaticImportHtmlType::matchesAny( $path, $patterns ) )
                continue;
            $out['pages']++;
            if ( ++$n % 50 === 0 )
                eZContentObject::clearCache();
            // with $markers: every page whose source holds one of them (e.g. its comments), whatever its links
            $resolvable = false;
            if ( $markers )
            {
                $html = (string)@file_get_contents( $this->source . '/' . $path );
                foreach ( $markers as $marker )
                    if ( $marker !== '' && strpos( $html, $marker ) !== false )
                        $resolvable = true;
                if ( !$resolvable )
                    continue;
                if ( $dryRun )
                {
                    $out['rewritten']++;
                    continue;
                }
                // only the page's comments, each written again (a fix of how they are written reaches them too):
                // the page itself is left alone, it may be edited in the CMS or being moved by a route step
                foreach ( isset( $carried[$path] ) ? $carried[$path] : array() as $childPath )
                    $this->state->setPage( $childPath, array( 'source_hash' => '' ) );
                $failed = $this->report['counts']['failed'];
                $flagged = $this->report['counts']['flagged'];
                $written = $this->importCarried( $path, $user );
                $out['comments'] = ( isset( $out['comments'] ) ? $out['comments'] : 0 ) + $written;
                $out['failed'] += $this->report['counts']['failed'] - $failed;
                $out['flagged'] += $this->report['counts']['flagged'] - $flagged;
                if ( $written )
                    $out['rewritten']++;
                foreach ( $this->state->data['pages'] as $childPath => $childEntry )
                    if ( strpos( (string)$childPath, $path . '#' ) === 0 )
                        $patch[(string)$childPath] = $childEntry;
                // a patch every 100 pages, so a stopped run keeps what it wrote
                if ( $out['rewritten'] && $out['rewritten'] % 100 === 0 && $patch )
                {
                    $out['patch'] = $this->writeStatePatch( $patch, 'relink-comments' );
                    $patch = array();
                }
                continue;
            }
            foreach ( (array)$entry['unresolved'] as $target )
                if ( $this->targetResolvable( (string)$target ) )
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
            // with $markers its comments are written again too, even where their source did not change (a fix of how
            // they are written, such as a subject kept as written, reaches them)
            if ( $markers && isset( $carried[$path] ) )
                foreach ( $carried[$path] as $childPath )
                    $this->state->setPage( $childPath, array( 'source_hash' => '' ) );
            $this->importPage( $path, $user );
            $after = $this->state->page( $path );
            $out['links'] += $this->report['counts']['links'] - $links;
            $out['failed'] += $this->report['counts']['failed'] - $failed;
            $out['flagged'] += $this->report['counts']['flagged'] - $flagged;
            if ( $after && isset( $after['written_hash'] ) && $this->report['counts']['failed'] === $failed && $this->report['counts']['flagged'] === $flagged )
            {
                $out['rewritten']++;
                $patch[$path] = array_intersect_key( $after, array_flip( array( 'source_hash', 'written_hash', 'written_fields', 'unresolved', 'node_id', 'object_id' ) ) );
                // the pages it carries (its comments) are written with it: their entries go with the patch
                foreach ( $this->state->data['pages'] as $childPath => $childEntry )
                    if ( strpos( (string)$childPath, $path . '#' ) === 0 )
                        $patch[(string)$childPath] = $childEntry;
                foreach ( isset( $after['unresolved'] ) ? (array)$after['unresolved'] : array() as $target )
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

    /**
     * The no-data-loss audit of the comments (exp:static-import:comments): for every imported page, each comment of
     * its source (the pages a type builds below a page, with their replies) must be an object of its class, below its
     * page or the comment it answers, with the same original markup (sha1), the same text, its subject as written, its
     * author (relation and name as written), its date, its own id and the id of the comment it answers. Read-only,
     * unless $trashReplaced: then, on a page that misses nothing, the objects its comments replaced (a class change,
     * remote id "<remote id>-was-<class>") go to the trash, each only when its whole subtree is replaced objects.
     *
     * @param array $patterns shell patterns of the source paths (empty: all)
     * @param bool $trashReplaced
     * @return array( 'pages' => pages with comments, 'comments' => n, 'stored' => n, 'missing' => n, 'replaced_left' =>
     *                replaced objects still there, 'trashed' => n, 'by_page' => what each page misses, 'counts' => per
     *                page array( source, stored ) )
     */
    public function commentsAudit( array $patterns = array(), $trashReplaced = false )
    {
        $this->options['pull'] = false;
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $paths = $this->type->discover( $this->source );
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
        $map = $this->rules->getHash( 'AttributeMap' );
        $db = eZDB::instance();
        $out = array( 'pages' => 0, 'comments' => 0, 'stored' => 0, 'missing' => 0, 'replaced_left' => 0, 'trashed' => 0, 'by_page' => array(), 'counts' => array() );
        // text without markup, references and whitespace: what a reader reads, however the XML wrote it
        $plain = function ( $html ) { return preg_replace( '/[\s\x{00A0}]+/u', '', html_entity_decode( strip_tags( str_replace( '<', ' <', (string)$html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); };
        $n = 0;
        foreach ( $this->state->data['pages'] as $path => $entry )
        {
            $path = (string)$path;
            if ( empty( $entry['object_id'] ) || strpos( $path, '#' ) !== false || substr( $path, -1 ) === '/'
                 || ( isset( $entry['status'] ) && in_array( $entry['status'], array( 'removed', 'duplicate', 'failed' ), true ) ) )
                continue;
            if ( $patterns && !expStaticImportHtmlType::matchesAny( $path, $patterns ) )
                continue;
            if ( ++$n % 50 === 0 )
                eZContentObject::clearCache();
            try
            {
                $page = $this->type->parse( $this->source, $path );
            }
            catch ( Throwable $e )
            {
                continue;
            }
            if ( !is_array( $page ) || empty( $page['children'] ) )
                continue;
            $out['pages']++;
            $object = eZContentObject::fetch( (int)$entry['object_id'] );
            $missing = array();
            $replaced = array();
            $count = array( 'source' => 0, 'stored' => 0 );
            $walk = function ( array $comments, $parentNode ) use ( &$walk, &$missing, &$out, &$replaced, &$count, $map, $db, $plain )
            {
                foreach ( $comments as $comment )
                {
                    $comment = $this->type->map( $comment );
                    $out['comments']++;
                    $count['source']++;
                    $remoteID = self::remoteID( $this->label, $comment['path'] );
                    foreach ( $db->arrayQuery( "SELECT id FROM ezcontentobject WHERE status = 1 AND remote_id LIKE '" . $db->escapeString( $remoteID ) . "-was-%'" ) as $row )
                        $replaced[] = (int)$row['id'];
                    $stored = eZContentObject::fetchByRemoteID( $remoteID );
                    $id = isset( $comment['meta']['comment_id'] ) ? $comment['meta']['comment_id'] : $comment['path'];
                    if ( !$stored instanceof eZContentObject || (int)$stored->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
                    {
                        $missing[] = "comment $id: not imported";
                        $walk( isset( $comment['children'] ) ? (array)$comment['children'] : array(), 0 );
                        continue;
                    }
                    $out['stored']++;
                    $count['stored']++;
                    $node = $stored->attribute( 'main_node' );
                    if ( (string)$stored->attribute( 'class_identifier' ) !== (string)$comment['class'] )
                        $missing[] = "comment $id: class " . $stored->attribute( 'class_identifier' ) . ", not " . $comment['class'];
                    if ( !$parentNode || !$node || (int)$node->attribute( 'parent_node_id' ) !== (int)$parentNode )
                        $missing[] = "comment $id: not below the page or the comment it answers";
                    $dataMap = $stored->dataMap();
                    foreach ( $map as $key => $field )
                    {
                        list( $mapClass, $identifier ) = array_pad( explode( '/', (string)$key, 2 ), 2, '' );
                        if ( ( $mapClass !== '*' && $mapClass !== $comment['class'] ) || !isset( $dataMap[$identifier] ) )
                            continue;
                        $attribute = $dataMap[$identifier];
                        $text = (string)$attribute->attribute( 'data_text' );
                        if ( $field === 'original_html' && sha1( trim( $text ) ) !== sha1( trim( (string)$comment['original_html'] ) ) )
                            $missing[] = "comment $id: the original text differs";
                        if ( $field === 'body' && $plain( $comment['body_html'] ) !== '' && $plain( $text ) === '' )
                            $missing[] = "comment $id: no text";
                        else if ( $field === 'body' && sha1( $plain( $comment['body_html'] ) ) !== sha1( $plain( $text ) ) )
                            $missing[] = "comment $id: the text differs";
                        if ( $field === 'title' && trim( $text ) !== trim( (string)$comment['title'] ) )
                            $missing[] = "comment $id: the subject differs";
                        if ( $field === 'author_name' && (string)( isset( $comment['meta']['author'] ) ? $comment['meta']['author'] : '' ) !== trim( $text ) )
                            $missing[] = "comment $id: " . ( trim( $text ) === '' ? 'no author name' : 'the author name differs' );
                        if ( $field === 'written_by' && !empty( $comment['meta']['author'] ) && expStaticImportByline::isName( $comment['meta']['author'], (string)$comment['title'] )
                             && !(int)$attribute->attribute( 'data_int' ) )
                            $missing[] = "comment $id: no author";
                        if ( ( $field === 'written_at' || $field === 'modified' ) && !empty( $comment['meta']['modified'] ) && (int)$attribute->attribute( 'data_int' ) !== (int)$comment['meta']['modified'] )
                            $missing[] = "comment $id: " . ( (int)$attribute->attribute( 'data_int' ) ? 'the date differs' : 'no date' );
                        if ( $field === 'comment_id' && trim( $text ) !== (string)$comment['meta']['comment_id'] )
                            $missing[] = "comment $id: the comment id differs";
                        if ( $field === 'parent_comment_id' && $text !== (string)$comment['meta']['parent_comment_id'] )
                            $missing[] = "comment $id: the parent comment id differs";
                    }
                    $walk( isset( $comment['children'] ) ? (array)$comment['children'] : array(), $node ? (int)$node->attribute( 'node_id' ) : 0 );
                }
            };
            $walk( (array)$page['children'], $object ? (int)$object->attribute( 'main_node_id' ) : 0 );
            $missing = array_values( array_unique( $missing ) );
            if ( $count['source'] !== $count['stored'] )
                $missing[] = "the source has {$count['source']} comments, {$count['stored']} are stored";
            $out['missing'] += count( $missing );
            $out['counts'][$path] = $count;
            if ( $missing )
                $out['by_page'][$path] = $missing;
            foreach ( array_unique( $replaced ) as $oldID )
            {
                $old = eZContentObject::fetch( $oldID );
                if ( !$old instanceof eZContentObject || (int)$old->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
                    continue;
                $node = $old->attribute( 'main_node' );
                // only a page that misses nothing gives up its replaced objects, and only a subtree of replaced objects
                $live = $node instanceof eZContentObjectTreeNode ? (int)$db->arrayQuery( "SELECT COUNT(*) AS n FROM ezcontentobject_tree t JOIN ezcontentobject o ON o.id = t.contentobject_id
                                                                                          WHERE t.path_string LIKE '" . $db->escapeString( $node->attribute( 'path_string' ) ) . "%'
                                                                                          AND o.remote_id NOT LIKE '%-was-%'" )[0]['n'] : 1;
                if ( $trashReplaced && !$missing && !$live )
                {
                    eZContentObjectTreeNode::removeSubtrees( array( (int)$node->attribute( 'node_id' ) ), true );
                    $out['trashed']++;
                    $this->audit( 'system.staticimport_page.trash_replaced', array( 'path' => $path, 'object' => $oldID ) );
                }
                else
                    $out['replaced_left']++;
            }
        }
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
                                                            WHERE t.parent_node_id = $parentNode AND t.node_id <> t.parent_node_id
                                                            AND o.remote_id NOT LIKE '%-was-%'" ) as $row )
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
            $previous = null;
            for ( $n = 2; $n < 10000; $n++ )
            {
                $candidate = $name . expStaticImportHtmlType::fill( $suffix, $values + array( 'n' => (string)$n ) );
                if ( !$taken( $candidate ) )
                    break;
                // a suffix that does not count (it named the space or the page id, and that is taken too) gets the
                // counter after it, once: "Comments (2)", "Page (EZP) (2)", never "Comments (3) (3)"
                if ( $candidate === $previous || ( $previous === null && !preg_match( '/\{[^}]*\bn\}/', $suffix ) ) )
                {
                    $suffix .= ' ({n})';
                    $previous = null;
                    continue;
                }
                $previous = $candidate;
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
            // one person under every variant of the name (a glued date, an alias): as contributor() finds them
            $parser = $this->bylineParser();
            $identity = function ( $name ) use ( $parser ) { return expStaticImportByline::normalizeName( $parser->canonicalName( $name ) ); };
            $have = array_map( $identity, (array)$stored );
            foreach ( $value as $person )
                if ( !in_array( $identity( $person ), $have, true ) )
                    $missing[] = "$field: $person";
            // a person stored that the source does not name is wrong data (an earlier rule read text as a name)
            $want = array_map( $identity, $value );
            foreach ( (array)$stored as $person )
                if ( !in_array( $identity( $person ), $want, true ) )
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
    /**
     * Writes only the pages a page carries (its comments, with their replies below the comment they answer), never the
     * page itself (relink --comments).
     *
     * @param string $path
     * @param eZUser $user
     * @return int the comments written, created or found unchanged
     */
    protected function importCarried( $path, eZUser $user )
    {
        try
        {
            $page = $this->type->parse( $this->source, $path );
            if ( !is_array( $page ) || isset( $page['redirect_to'] ) || isset( $page['asset_of'] ) || empty( $page['children'] ) )
                return 0;
        }
        catch ( Throwable $e )
        {
            $this->fail( $path, get_class( $e ) . ': ' . $e->getMessage() );
            return 0;
        }
        $before = $this->report['counts']['created'] + $this->report['counts']['updated'] + $this->report['counts']['unchanged'];
        foreach ( (array)$page['children'] as $child )
        {
            $child['child_of'] = $path;
            $this->importParsedPage( $this->type->map( $child ), $user );
        }
        return $this->report['counts']['created'] + $this->report['counts']['updated'] + $this->report['counts']['unchanged'] - $before;
    }

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
        // a name as written, never with the character references of a twice-encoded source (Kara&#347;), a date or
        // time glued to it, or a known variant of the person's name (ContributorAliases)
        $name = $this->bylineParser()->canonicalName( $name );
        $key = expStaticImportByline::normalizeName( $name );
        if ( $class === '' || $key === '' )
            return 0;
        // code or a piece of a sentence never becomes a contributor, whatever rule read it
        if ( !expStaticImportByline::isName( $name ) )
        {
            $this->report['warnings'][] = "'" . mb_substr( $name, 0, 80 ) . "' is no person's name, no contributor was made";
            return 0;
        }
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

        $parentNode = $this->resolveParentNode( $page['parent_path'] );
        if ( !$parentNode )
        {
            $parentNode = $this->parentNode();
            // a page at the top that the rules did not put there: its parent is not imported yet
            if ( $page['parent_path'] !== '' )
                $this->report['warnings'][] = "$path: its parent {$page['parent_path']} is not imported yet, it went to the top";
        }

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
        // a page whose class changed in the rules (e.g. the comments of exp_doc_comment that are comment_historical
        // now): the old object is hidden and gives up the remote id (ClassChange=replace, the default; keep leaves it)
        if ( $object instanceof eZContentObject && !$this->isRootPage( $path ) && (string)$object->attribute( 'class_identifier' ) !== (string)$page['class']
             && strtolower( (string)$this->rules->get( 'ClassChange', 'replace' ) ) === 'replace' && !$this->options['dry_run'] )
        {
            $node = $object->attribute( 'main_node' );
            if ( $node instanceof eZContentObjectTreeNode && !(int)$node->attribute( 'is_hidden' ) )
                eZContentObjectTreeNode::hideSubTree( $node );
            $object->setAttribute( 'remote_id', $remoteID . '-was-' . $object->attribute( 'class_identifier' ) );
            $object->store();
            // the replaced object is going: its name is no longer taken below its parent (uniqueSiblingName())
            if ( $node instanceof eZContentObjectTreeNode && isset( $this->siblingNames[(int)$node->attribute( 'parent_node_id' )] ) )
                foreach ( $this->siblingNames[(int)$node->attribute( 'parent_node_id' )] as $key => $id )
                    if ( $id === (int)$object->attribute( 'id' ) )
                        unset( $this->siblingNames[(int)$node->attribute( 'parent_node_id' )][$key] );
            $this->report['counts']['replaced'] = ( isset( $this->report['counts']['replaced'] ) ? $this->report['counts']['replaced'] : 0 ) + 1;
            $previous = null;
            $object = null;
        }
        // the spec check: no two pages with the same name below one parent (SiblingNames=unique renames the later
        // one with SiblingNameSuffix, refuse skips it, warn only reports it)
        $unique = $this->isRootPage( $path ) ? $page['title'] : $this->uniqueSiblingName( $parentNode, $page['title'], $object instanceof eZContentObject ? (int)$object->attribute( 'id' ) : 0, $page );
        if ( $unique === null )
        {
            $this->fail( $path, "a page named '{$page['title']}' is below node $parentNode already (SiblingNames=refuse)", $page );
            return;
        }
        // SiblingNameKeepsTitle[]: classes whose title is content as written (a comment's subject): the unique name is
        // only the object's name (and so its node name), the title attribute keeps the title
        $keepsTitle = in_array( (string)$page['class'], $this->rules->getList( 'SiblingNameKeepsTitle' ), true );
        if ( $unique !== $page['title'] && !$keepsTitle )
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
        // a title kept as written (SiblingNameKeepsTitle[]) with a name taken below the parent: the object's name
        // carries the suffix (the publish named it after the title); the node's address is made unique by the kernel
        if ( $keepsTitle && !$isRoot && $unique !== null && (string)$object->attribute( 'name' ) !== (string)$unique )
        {
            $object->setName( $unique, (int)$object->attribute( 'current_version' ), $this->language() );
            $object->store();
        }
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
        // the page's real dates (DateRules[]), from the values the import just wrote, not the day of the import; a
        // page with none of its own (a section) waits for its children, at the end of the run
        $dates = $this->datesChain()->resolve( $path, function ( $identifier ) use ( $attributes ) {
            return isset( $attributes[$identifier] ) ? $attributes[$identifier] : null;
        } );
        if ( $dates['created_source'] === expStaticImportDates::TODAY && $this->datesChain()->uses( 'children' ) )
            $this->datesPending[(int)$object->attribute( 'id' )] = $path;
        else
            $this->storeDates( $object, $dates['created'], $dates['modified'] );
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
     * Every link of a page goes through the link resolver (expStaticImportLinkResolver): a link to an imported page,
     * or to a media file, becomes a node link (eznode://<node id>[#anchor]), which the output turns into the node's
     * current address on every render, so it follows moves and renames; an image whose file was imported becomes an
     * embed of its object. The others keep their address and are counted as unresolved: the page is written again in
     * the links phase, and exp:static-import:links rewrites the stored bodies once their targets exist.
     *
     * @param string $html
     * @param array $links href => target path (the type's own reading; tried when the resolver finds nothing)
     * @param array $page
     * @return array( string html, array unresolved targets )
     */
    protected function rewriteLinks( $html, array $links, array $page )
    {
        $unresolved = array();
        $resolver = $this->linkResolver();
        $engine = $this;
        $counts =& $this->report['counts'];
        $html = preg_replace_callback( '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is', function ( $m ) use ( $resolver, $page, $links, $engine, &$unresolved, &$counts ) {
            $result = $resolver->resolve( $page['path'], $m[3] );
            $node = in_array( $result['status'], array( expStaticImportLinkResolver::NODE, expStaticImportLinkResolver::MEDIA ), true ) ? (int)$result['node'] : 0;
            // the type's own reading of the link (a target the resolver does not know of)
            if ( !$node && $result['status'] === expStaticImportLinkResolver::UNRESOLVED && isset( $links[$m[3]] ) )
                $node = $engine->nodeOfTarget( (string)$links[$m[3]] );
            if ( !$node )
            {
                if ( $result['status'] === expStaticImportLinkResolver::UNRESOLVED )
                    $unresolved[] = $result['target'] !== '' ? $result['target'] : $result['href'];
                return $m[0];
            }
            $counts['links'] = ( isset( $counts['links'] ) ? $counts['links'] : 0 ) + 1;
            return $m[1] . '"eznode://' . $node . ( $result['anchor'] !== '' ? '#' . htmlspecialchars( $result['anchor'], ENT_QUOTES ) : '' ) . '"';
        }, $html );
        // images: an <img> whose file was imported becomes an embed of its object
        $html = preg_replace_callback( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', function ( $m ) use ( $page, $resolver, &$unresolved ) {
            $result = $resolver->resolve( $page['path'], $m[1], true );
            if ( $result['status'] !== expStaticImportLinkResolver::MEDIA || !$result['object'] )
            {
                if ( $result['status'] === expStaticImportLinkResolver::UNRESOLVED )
                {
                    $unresolved[] = $result['target'] !== '' ? $result['target'] : $result['href'];
                    // a file of the source the type did not list (a full URL of the site, an encoded name): imported
                    // in the assets phase, embedded when the page is written again
                    $this->queueAsset( $result['target'] );
                }
                return $m[0];
            }
            $alt = preg_match( '/\balt=["\']([^"\']*)["\']/i', $m[0], $a ) ? $a[1] : '';
            $align = preg_match( '/\bdata-align=["\']([a-z]+)["\']/i', $m[0], $g ) ? ' align="' . $g[1] . '"' : '';
            return '<img id="eZObject_' . (int)$result['object'] . '" src="' . htmlspecialchars( $m[1] ) . '" alt="' . $alt . '"' . $align . ' />';
        }, $html );
        return array( $html, array_values( array_unique( $unresolved ) ) );
    }

    /**
     * @param string $target a source path the resolver found for an image
     */
    protected function queueAsset( $target )
    {
        if ( $target === '' || strpos( $target, 'page:' ) === 0 || !$this->source || !is_file( $this->source . '/' . $target ) )
            return;
        $suffixes = $this->rules->getList( 'AssetSuffixes' ) ?: array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'pdf', 'zip' );
        if ( !in_array( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ), $suffixes, true ) )
            return;
        $this->assetPaths[$target] = $this->state->data['pending_assets'][$target] = true;
    }

    /** @var expStaticImportLinkResolver|null */
    protected $linkResolver;

    /** @var array|null original address => source path (SourceAddressPattern) */
    protected $sourceAddressMap;

    /** @var array page id => node id */
    protected $pageIdNodes = array();

    /** @var bool the map holds every page id of the import (preloadPageIdNodes()) */
    protected $pageIdNodesComplete = false;

    /** @var array remote id => main node of this import's objects (0: none) */
    protected $remoteNodes = array();

    /** @var bool $remoteNodes holds every object of the import (preloadRemoteNodes()) */
    protected $remoteNodesComplete = false;

    /**
     * Every object of this import by its remote id, with its main node, in one query (for a console step).
     *
     * @return int the objects known
     */
    public function preloadRemoteNodes()
    {
        $db = eZDB::instance();
        $rows = $db->arrayQuery( "SELECT o.remote_id, t.node_id FROM ezcontentobject o JOIN ezcontentobject_tree t ON t.contentobject_id = o.id AND t.node_id = t.main_node_id
                                  WHERE o.status = 1 AND o.remote_id LIKE '" . $db->escapeString( 'staticimport-' . $this->label . '-' ) . "%'" );
        foreach ( $rows as $row )
            $this->remoteNodes[(string)$row['remote_id']] = (int)$row['node_id'];
        $this->remoteNodesComplete = true;
        return count( $this->remoteNodes );
    }

    /**
     * The node a link to a source path goes to: the top node for RootPage (the state may still name the page an
     * earlier run made for it, retired since), else the state's page or media file (a duplicate: the page it
     * duplicates), else the object the import made for the path (a group page or a container of a console step).
     *
     * @param string $path
     * @return int 0 when the path is no imported page
     */
    public function pageNode( $path )
    {
        $path = (string)$path;
        if ( $this->isRootPage( $path ) )
            return (int)$this->parentNode();
        $node = isset( $this->state->data['pages'][$path] ) || isset( $this->state->data['assets'][$path] ) ? $this->state->nodeOf( $path ) : 0;
        return $node ?: $this->nodeByRemotePath( $path );
    }

    /**
     * @param string $path a source path (or a group page's path, ending in "/")
     * @return int the main node of the object the import made for it, 0 when none
     */
    public function nodeByRemotePath( $path )
    {
        $remoteID = self::remoteID( $this->label, (string)$path );
        if ( isset( $this->remoteNodes[$remoteID] ) || $this->remoteNodesComplete )
            return isset( $this->remoteNodes[$remoteID] ) ? $this->remoteNodes[$remoteID] : 0;
        // during a run only for a group page (one query each, cached): the other pages are in the state once imported
        if ( substr( (string)$path, -1 ) !== '/' )
            return 0;
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        return $this->remoteNodes[$remoteID] = $object instanceof eZContentObject ? (int)$object->attribute( 'main_node_id' ) : 0;
    }

    /**
     * Every page id of the imported pages with its main node, in one query (a console step resolves thousands of
     * links by page id; one query each would read the attribute table every time). Of several objects with one page
     * id, a page wins over a historical copy, a comment or an object a class change replaced.
     *
     * @return int the page ids known
     */
    public function preloadPageIdNodes()
    {
        $identifiers = array();
        foreach ( $this->rules->getHash( 'AttributeMap' ) as $key => $field )
            if ( $field === 'page_id' && strpos( (string)$key, '/' ) !== false )
                $identifiers[] = substr( (string)$key, strpos( (string)$key, '/' ) + 1 );
        if ( !$identifiers )
            return 0;
        $db = eZDB::instance();
        $list = "'" . implode( "','", array_map( array( $db, 'escapeString' ), array_unique( $identifiers ) ) ) . "'";
        $rows = $db->arrayQuery( "SELECT a.data_text AS page_id, o.remote_id, c.identifier AS class, t.node_id FROM ezcontentobject_attribute a
                                  JOIN ezcontentclass_attribute ca ON ca.id = a.contentclassattribute_id AND ca.version = 0 AND ca.identifier IN ( $list )
                                  JOIN ezcontentobject o ON o.id = a.contentobject_id AND o.current_version = a.version AND o.status = 1
                                  JOIN ezcontentclass c ON c.id = o.contentclass_id AND c.version = 0
                                  JOIN ezcontentobject_tree t ON t.contentobject_id = o.id AND t.node_id = t.main_node_id
                                  WHERE o.remote_id LIKE '" . $db->escapeString( 'staticimport-' . $this->label . '-' ) . "%'
                                  ORDER BY o.id" );
        $rank = array();
        foreach ( $rows as $row )
        {
            $id = trim( (string)$row['page_id'] );
            if ( $id === '' )
                continue;
            $weight = preg_match( '/historical|comment/i', (string)$row['class'] ) || strpos( (string)$row['remote_id'], '-was-' ) !== false ? 1 : 0;
            if ( !isset( $rank[$id] ) || $weight < $rank[$id] )
            {
                $rank[$id] = $weight;
                $this->pageIdNodes[$id] = (int)$row['node_id'];
            }
        }
        $this->pageIdNodesComplete = true;
        return count( $this->pageIdNodes );
    }

    /**
     * The link resolver of this import, with the state's pages and media, the page ids, the site's own hosts
     * (LinkHosts[]; LinkHostsIfImported[] for hosts only partly imported), the original addresses of a mirrored
     * source (SourceAddressPattern), IndexFiles[] and LinkForwardParameters[].
     *
     * @param bool $refresh build it again (the state changed)
     * @return expStaticImportLinkResolver
     */
    public function linkResolver( $refresh = false )
    {
        if ( $this->linkResolver && !$refresh )
            return $this->linkResolver;
        $state = $this->state;
        $engine = $this;
        $lower = null;
        $options = array(
            'page' => function ( $path ) use ( $engine ) { return $engine->pageNode( $path ); },
            'page_id' => function ( $id ) use ( $engine ) { return $engine->nodeByPageId( $id ); },
            'asset' => function ( $path ) use ( $state ) {
                $asset = $state->asset( $path );
                return $asset && !empty( $asset['object_id'] ) ? $asset : null;
            },
            'caseless' => function ( $path ) use ( $state, &$lower ) {
                if ( $lower === null )
                {
                    $lower = array();
                    foreach ( array_keys( $state->data['pages'] ) as $known )
                        if ( !isset( $lower[strtolower( (string)$known )] ) )
                            $lower[strtolower( (string)$known )] = (string)$known;
                }
                return isset( $lower[strtolower( $path )] ) ? $lower[strtolower( $path )] : null;
            },
            'hosts' => $this->rules->getList( 'LinkHosts' ),
            'soft_hosts' => $this->rules->getList( 'LinkHostsIfImported' ),
            'addresses' => $this->sourceAddresses(),
            'index_files' => $this->rules->getList( 'IndexFiles' ) ?: array( 'index.html' ),
        );
        if ( $this->rules->get( 'LinkForwardParameters', null ) !== null )
            $options['forward_parameters'] = $this->rules->getList( 'LinkForwardParameters' );
        return $this->linkResolver = new expStaticImportLinkResolver( $options );
    }

    /**
     * SourceAddressPattern (a regular expression whose first group is a page's original address, e.g. HTTrack's
     * "<!-- Mirrored from (\S+) by HTTrack"): the original address of every file of the source, read from its first
     * 4 KB, kept in <work folder>/source-addresses.json until the source's commit or the pattern changes.
     *
     * @return array original address (no scheme) => source path
     */
    public function sourceAddresses()
    {
        if ( $this->sourceAddressMap !== null )
            return $this->sourceAddressMap;
        $pattern = (string)$this->rules->get( 'SourceAddressPattern', '' );
        if ( $pattern === '' )
            return $this->sourceAddressMap = array();
        $source = $this->source ?: $this->prepareSource();
        $head = is_dir( $source . '/.git' ) ? trim( (string)shell_exec( 'git -C ' . escapeshellarg( $source ) . ' rev-parse HEAD 2>/dev/null' ) ) : (string)@filemtime( $source );
        $key = sha1( $pattern . '|' . $head );
        $cache = $this->workDir . '/source-addresses.json';
        $cached = is_file( $cache ) ? json_decode( (string)file_get_contents( $cache ), true ) : null;
        if ( is_array( $cached ) && isset( $cached['key'], $cached['addresses'] ) && $cached['key'] === $key )
            return $this->sourceAddressMap = (array)$cached['addresses'];
        $suffixes = $this->rules->getList( 'Suffixes' ) ?: array( 'html', 'htm' );
        $map = array();
        $iterator = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
            function ( $file ) { return $file->getFilename()[0] !== '.'; } ) );
        foreach ( $iterator as $file )
        {
            if ( !$file->isFile() || !in_array( strtolower( $file->getExtension() ), $suffixes, true ) )
                continue;
            $head4k = (string)@file_get_contents( $file->getPathname(), false, null, 0, 4096 );
            if ( @preg_match( $pattern, $head4k, $m ) && isset( $m[1] ) && $m[1] !== '' )
            {
                $address = expStaticImportLinkResolver::normalizeAddress( $m[1] );
                $path = substr( $file->getPathname(), strlen( $source ) + 1 );
                if ( !isset( $map[$address] ) || strcmp( $path, $map[$address] ) < 0 )
                    $map[$address] = $path;
            }
        }
        if ( is_dir( $this->workDir ) )
            @file_put_contents( $cache, json_encode( array( 'key' => $key, 'addresses' => $map ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $this->sourceAddressMap = $map;
    }

    /**
     * @param string $pageId
     * @return int the main node of the current page with that page id, 0 when it is not imported
     */
    public function nodeByPageId( $pageId )
    {
        $pageId = (string)$pageId;
        if ( !isset( $this->pageIdNodes[$pageId] ) && $this->pageIdNodesComplete )
            return 0;
        if ( !isset( $this->pageIdNodes[$pageId] ) )
        {
            $objectID = $this->objectByPageId( $pageId );
            $object = $objectID ? eZContentObject::fetch( $objectID ) : null;
            $this->pageIdNodes[$pageId] = $object ? (int)$object->attribute( 'main_node_id' ) : 0;
        }
        return $this->pageIdNodes[$pageId];
    }

    /**
     * @param string $target an unresolved target a page's state names (a source path, or page:<page id>)
     * @return bool it stands for an imported page or media file now
     */
    protected function targetResolvable( $target )
    {
        if ( $target === '' )
            return false;
        if ( $this->state->nodeOf( $target ) || $this->state->asset( $target ) )
            return true;
        if ( strpos( $target, 'page:' ) === 0 )
            return (bool)$this->objectByPageId( substr( $target, 5 ) );
        $result = $this->linkResolver()->resolve( '', '/' . ltrim( $target, '/' ) );
        return in_array( $result['status'], array( expStaticImportLinkResolver::NODE, expStaticImportLinkResolver::MEDIA ), true ) && $result['node'];
    }

    /**
     * @param string $target a source path, or page:<page id>
     * @return int the node it stands for, 0 when none
     */
    public function nodeOfTarget( $target )
    {
        if ( strpos( $target, 'page:' ) === 0 )
            return $this->nodeByPageId( substr( $target, 5 ) );
        return $this->state->nodeOf( $target );
    }

    /**
     * For the console steps outside the engine (expStaticImportLinkRewriter): the state, the rules, the work folder,
     * the hash the edit detection compares and a state patch.
     */
    public function state()
    {
        return $this->state;
    }

    public function rules()
    {
        return $this->rules;
    }

    public function workDirectory()
    {
        return $this->workDir;
    }

    public function label()
    {
        return $this->label;
    }

    /**
     * @param eZContentObject $object
     * @param array $identifiers
     * @return string
     */
    public function writtenHashOf( eZContentObject $object, array $identifiers )
    {
        return $this->contentHash( $object, $identifiers );
    }

    /**
     * @param array $patch path => values
     * @param string $what
     * @return string the patch file
     */
    public function addStatePatch( array $patch, $what )
    {
        return $this->writeStatePatch( $patch, $what );
    }

    /**
     * The state patches of the other console steps, read again into this process's state (a long console step reads
     * them before each batch).
     */
    public function reloadStatePatches()
    {
        $this->loadStatePatches();
    }

    /**
     * Prepares the source and the state patches of earlier console steps, without a pull (for a console step).
     */
    public function prepareForConsoleStep()
    {
        $this->options['pull'] = false;
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $this->linkResolver = null;
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
            if ( $this->targetResolvable( (string)$target ) )
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
        {
            // the file's real dates (DateRules[]: git, meta, mtime), not the day of the import
            $dates = $this->datesChain()->resolve( $path );
            $this->storeDates( $object, $dates['created'], $dates['modified'] );
            $this->state->setAsset( $path, array( 'object_id' => (int)$object->attribute( 'id' ), 'node_id' => (int)$object->attribute( 'main_node_id' ), 'hash' => $hash ) );
        }
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
     * @return expStaticImportDates the date rule chain of the type (DateRules[]) for the current source folder
     */
    protected function datesChain()
    {
        if ( $this->datesChain === null || $this->datesSource !== (string)$this->source )
        {
            $this->datesSource = (string)$this->source;
            $this->datesChain = new expStaticImportDates( $this->rules, $this->datesSource, $this->workDir . '/history.git' );
        }
        return $this->datesChain;
    }

    /**
     * Gives an object and its versions their real dates: the object's published (first published) and modified, each
     * version's created and modified (the current one ends at the modified date, earlier ones at the created date).
     * Only these fields are written (eZPersistentObject field filters), so nothing else of the object can be
     * overwritten by a stale copy. The dates are no attributes, so the import hash (edit detection) does not see them.
     * With immediate search indexing the object is indexed again (a delayed index reads the dates when it runs).
     *
     * @param eZContentObject $object
     * @param int $created
     * @param int $modified
     * @param bool $index register the object for indexing when indexing is immediate
     * @return bool whether anything changed
     */
    protected function storeDates( eZContentObject $object, $created, $modified, $index = true )
    {
        $created = (int)$created;
        $modified = max( $created, (int)$modified );
        $changed = false;
        $db = eZDB::instance();
        $db->begin();
        if ( (int)$object->attribute( 'published' ) !== $created || (int)$object->attribute( 'modified' ) !== $modified )
        {
            $object->setAttribute( 'published', $created );
            $object->setAttribute( 'modified', $modified );
            $object->store( array( 'published', 'modified' ) );
            $changed = true;
        }
        $current = (int)$object->attribute( 'current_version' );
        $versions = eZPersistentObject::fetchObjectList( eZContentObjectVersion::definition(), null,
                                                         array( 'contentobject_id' => (int)$object->attribute( 'id' ) ) );
        foreach ( is_array( $versions ) ? $versions : array() as $version )
        {
            $versionModified = (int)$version->attribute( 'version' ) === $current ? $modified : $created;
            if ( (int)$version->attribute( 'created' ) !== $created || (int)$version->attribute( 'modified' ) !== $versionModified )
            {
                $version->setAttribute( 'created', $created );
                $version->setAttribute( 'modified', $versionModified );
                $version->store( array( 'created', 'modified' ) );
                $changed = true;
            }
        }
        $db->commit();
        if ( $changed && $index && eZINI::instance( 'site.ini' )->variable( 'SearchSettings', 'DelayedIndexing' ) !== 'enabled' )
            eZContentOperationCollection::registerSearchObject( (int)$object->attribute( 'id' ) );
        return $changed;
    }

    /**
     * The earliest created and the latest modified date of a node's children.
     *
     * @param int $nodeID
     * @param array|null $known object id => array( created, modified ): only these children count (null: every child
     *                   by the dates it has)
     * @return array|null array( 'created' => ..., 'modified' => ... ), null when no child has dates
     */
    protected function childrenDates( $nodeID, $known = null )
    {
        $rows = eZDB::instance()->arrayQuery( 'SELECT o.id, o.published, o.modified FROM ezcontentobject_tree t, ezcontentobject o
                                               WHERE t.contentobject_id = o.id AND t.parent_node_id = ' . (int)$nodeID . ' AND t.node_id <> ' . (int)$nodeID );
        $created = $modified = null;
        foreach ( is_array( $rows ) ? $rows : array() as $row )
        {
            if ( is_array( $known ) )
            {
                if ( !isset( $known[(int)$row['id']] ) )
                    continue;
                list( $c, $m ) = $known[(int)$row['id']];
            }
            else
                list( $c, $m ) = array( (int)$row['published'], (int)$row['modified'] );
            if ( $c > 0 && ( $created === null || $c < $created ) )
                $created = $c;
            if ( $m > 0 && ( $modified === null || $m > $modified ) )
                $modified = $m;
        }
        return $created === null ? null : array( 'created' => $created, 'modified' => $modified !== null ? $modified : $created );
    }

    /**
     * The containers of a run that have no dates of their own take their children's, the deepest first.
     *
     * @param array $pending object id => path
     * @return int how many took their children's dates
     */
    protected function datePendingContainers( array $pending )
    {
        $items = array();
        foreach ( $pending as $id => $path )
            if ( ( $object = eZContentObject::fetch( (int)$id ) ) && ( $node = $object->attribute( 'main_node' ) ) )
                $items[] = array( $object, $node, $path );
        usort( $items, function ( $a, $b ) { return (int)$b[1]->attribute( 'depth' ) - (int)$a[1]->attribute( 'depth' ); } );
        $done = 0;
        foreach ( $items as $item )
        {
            list( $object, $node, $path ) = $item;
            $children = $this->childrenDates( (int)$node->attribute( 'node_id' ) );
            $dates = $this->datesChain()->resolve( $path, null, $children );
            if ( $dates['created_source'] !== expStaticImportDates::TODAY )
                $done++;
            $this->storeDates( $object, $dates['created'], $dates['modified'] );
        }
        return $done;
    }

    /**
     * exp:static-import:dates: gives every object an earlier run imported (pages, sections and containers, comments,
     * media, and the objects a class change replaced) its real dates by the type's DateRules[], in batches, resumable
     * (var/<site>/static-import/<type>/dates-state.json; never the import's own state file, so it is safe next to a
     * run). Objects without dates of their own take their children's in a second pass, the deepest first. Changed
     * objects are queued for the search index (or indexed now) and their view caches cleared, batch by batch.
     *
     * @param array $options dry_run, restart, batch (objects per batch, default 200), limit (objects, for a test),
     *                       index (queue, now or none; default queue), paths (shell patterns of source paths)
     * @param callable|null $progress receives (done, of)
     * @return array the report: counts by source, the year spread per family, the objects still dated today
     */
    public function restoreDates( array $options = array(), $progress = null )
    {
        $options = array_merge( array( 'dry_run' => false, 'restart' => false, 'batch' => 200, 'limit' => 0, 'index' => 'queue', 'paths' => array() ), $options );
        $this->options['pull'] = false;
        $this->options['dry_run'] = (bool)$options['dry_run'];
        $this->report = array( 'counts' => array(), 'warnings' => array() );
        $this->source = $this->prepareSource();
        $this->loadStatePatches();
        $chain = $this->datesChain();
        $stateFile = $this->workDir . '/dates-state.json';
        $state = !$options['restart'] && !$options['dry_run'] && is_file( $stateFile ) ? json_decode( (string)file_get_contents( $stateFile ), true ) : null;
        if ( !is_array( $state ) || empty( $state['phase'] ) || $state['phase'] === 'done' )
            $state = array( 'started' => time(), 'phase' => 'objects', 'position' => 0, 'resolved' => array(), 'pending' => array(), 'changed' => 0 );

        // md5 of a source path => the path, for the pages, the media and the group pages of the source
        $paths = array();
        foreach ( array_keys( (array)$this->state->data['pages'] ) as $path )
            $paths[md5( (string)$path )] = (string)$path;
        $media = array();
        foreach ( array_keys( isset( $this->state->data['assets'] ) ? (array)$this->state->data['assets'] : array() ) as $path )
            $media[md5( (string)$path )] = (string)$path;
        foreach ( $this->type->discover( $this->source ) as $path )
            if ( substr( (string)$path, -1 ) === '/' )
                $paths[md5( (string)$path )] = (string)$path;
        $prefix = 'staticimport-' . $this->label . '-';
        $rootPage = trim( (string)$this->rules->get( 'RootPage', '' ) );
        $root = $rootPage !== '' ? $this->rootObject() : null;
        $db = eZDB::instance();
        $batch = max( 1, (int)$options['batch'] );
        $total = (int)$db->arrayQuery( "SELECT COUNT(*) AS n FROM ezcontentobject WHERE remote_id LIKE '" . $db->escapeString( $prefix ) . "%'" )[0]['n'] + ( $root ? 1 : 0 );
        $handled = 0;

        $site = eZINI::instance( 'site.ini' );
        $delayed = $site->variable( 'SearchSettings', 'DelayedIndexing' );
        $finishBatch = function ( array $changedIDs ) use ( $options, $site, $delayed )
        {
            if ( $options['dry_run'] || !$changedIDs )
                return;
            if ( $options['index'] !== 'none' )
            {
                $site->setVariable( 'SearchSettings', 'DelayedIndexing', $options['index'] === 'queue' ? 'enabled' : 'disabled' );
                foreach ( $changedIDs as $id )
                    eZContentOperationCollection::registerSearchObject( (int)$id );
                $site->setVariable( 'SearchSettings', 'DelayedIndexing', $delayed );
            }
            eZContentCacheManager::clearObjectViewCacheArray( array_map( 'intval', $changedIDs ) );
        };
        // which object is which source path, and its family (the first folder of the path) for the report
        $describe = function ( eZContentObject $object ) use ( $paths, $media, $prefix, $root, $rootPage )
        {
            if ( $root && (int)$object->attribute( 'id' ) === (int)$root->attribute( 'id' ) )
                return array( $rootPage, 'root' );
            $rest = substr( (string)$object->attribute( 'remote_id' ), strlen( $prefix ) );
            if ( strpos( $rest, 'media-' ) === 0 && preg_match( '/^media-([0-9a-f]{32})/', $rest, $m ) )
                return array( isset( $media[$m[1]] ) ? $media[$m[1]] : '', 'media' );
            $path = preg_match( '/^([0-9a-f]{32})/', $rest, $m ) && isset( $paths[$m[1]] ) ? $paths[$m[1]] : '';
            if ( $path === '' )
            {
                $dataMap = $object->dataMap();
                $path = isset( $dataMap['source_path'] ) ? trim( (string)$dataMap['source_path']->toString() ) : '';
            }
            if ( strpos( $path, '#' ) !== false )
                return array( $path, 'comments' );
            if ( $path === '' || substr( $path, -1 ) === '/' )
                return array( $path, 'containers' );
            return array( $path, strpos( $path, '/' ) !== false ? strstr( $path, '/', true ) : 'top' );
        };
        $save = function () use ( &$state, $stateFile, $options )
        {
            if ( !$options['dry_run'] )
                file_put_contents( $stateFile, json_encode( $state ), LOCK_EX );
        };

        if ( $state['phase'] === 'objects' )
        {
            $ids = $root ? array( (int)$root->attribute( 'id' ) ) : array();
            while ( true )
            {
                $rows = $db->arrayQuery( "SELECT id FROM ezcontentobject WHERE remote_id LIKE '" . $db->escapeString( $prefix ) . "%' AND id > " . (int)$state['position']
                                         . ' ORDER BY id', array( 'limit' => $batch ) );
                $batchIDs = array_merge( $ids, array_map( function ( $row ) { return (int)$row['id']; }, is_array( $rows ) ? $rows : array() ) );
                $ids = array();
                if ( !$batchIDs )
                    break;
                $changedIDs = array();
                foreach ( $batchIDs as $id )
                {
                    $object = eZContentObject::fetch( $id );
                    if ( !$object instanceof eZContentObject || (int)$object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED )
                        continue;
                    list( $path, $family ) = $describe( $object );
                    if ( $options['paths'] && ( $path === '' || !expStaticImportHtmlType::matchesAny( $path, (array)$options['paths'] ) ) )
                        continue;
                    $dataMap = $object->dataMap();
                    // an object a class change replaced ("<remote id>-was-<class>") has the dates of the page it was:
                    // the values of the object that replaced it count where its own are empty
                    $replacement = array();
                    if ( preg_match( '/^(.+)-was-[a-z0-9_]+$/', (string)$object->attribute( 'remote_id' ), $m )
                         && ( $current = eZContentObject::fetchByRemoteID( $m[1] ) ) instanceof eZContentObject )
                        $replacement = $current->dataMap();
                    // where neither holds a value (an attribute an earlier run left empty), the value the import would
                    // write today, read from the source by the type
                    $dates = $chain->resolve( $path, function ( $identifier ) use ( $dataMap, $replacement, $path ) {
                        foreach ( array( $dataMap, $replacement ) as $map )
                        {
                            if ( !isset( $map[$identifier] ) )
                                continue;
                            $int = (int)$map[$identifier]->attribute( 'data_int' );
                            $value = $int > 0 ? $int : trim( (string)$map[$identifier]->toString() );
                            if ( expStaticImportDates::validTime( $value ) !== null )
                                return $value;
                        }
                        $parsed = $this->parsedValues( $path );
                        return isset( $parsed[$identifier] ) ? $parsed[$identifier] : null;
                    } );
                    if ( $dates['created_source'] === expStaticImportDates::TODAY && $chain->uses( 'children' ) && (int)$object->attribute( 'main_node_id' ) )
                    {
                        $state['pending'][$id] = array( $path, $family );
                        continue;
                    }
                    if ( !$options['dry_run'] && $this->storeDates( $object, $dates['created'], $dates['modified'], false ) )
                        $changedIDs[] = $id;
                    $state['resolved'][$id] = array( $dates['created'], $dates['modified'], $dates['created_source'], $dates['modified_source'], $family );
                }
                $finishBatch( $changedIDs );
                $state['changed'] += count( $changedIDs );
                $state['position'] = max( $state['position'], max( $batchIDs ) );
                $handled += count( $batchIDs );
                $save();
                eZContentObject::clearCache();
                if ( $progress )
                    call_user_func( $progress, count( $state['resolved'] ) + count( $state['pending'] ), $total );
                if ( $options['limit'] > 0 && $handled >= (int)$options['limit'] )
                    break;
            }
            if ( !( $options['limit'] > 0 && $handled >= (int)$options['limit'] ) )
                $state['phase'] = 'containers';
            $save();
        }

        if ( $state['phase'] === 'containers' )
        {
            // the deepest first, so a container's child containers have their dates before it takes theirs
            $items = array();
            foreach ( $state['pending'] as $id => $entry )
                if ( ( $object = eZContentObject::fetch( (int)$id ) ) && ( $node = $object->attribute( 'main_node' ) ) )
                    $items[] = array( (int)$id, (int)$node->attribute( 'node_id' ), (int)$node->attribute( 'depth' ), $entry );
            usort( $items, function ( $a, $b ) { return $b[2] - $a[2]; } );
            $known = array();
            foreach ( $state['resolved'] as $id => $row )
                $known[(int)$id] = array( $row[0], $row[1] );
            $changedIDs = array();
            foreach ( $items as $item )
            {
                list( $id, $nodeID, , $entry ) = $item;
                $dates = $chain->resolve( $entry[0], null, $this->childrenDates( $nodeID, $known ) );
                $object = eZContentObject::fetch( $id );
                if ( !$options['dry_run'] && $object && $this->storeDates( $object, $dates['created'], $dates['modified'], false ) )
                    $changedIDs[] = $id;
                if ( $dates['created_source'] !== expStaticImportDates::TODAY )
                    $known[$id] = array( $dates['created'], $dates['modified'] );
                $state['resolved'][$id] = array( $dates['created'], $dates['modified'], $dates['created_source'], $dates['modified_source'], $entry[1] );
                unset( $state['pending'][$id] );
                if ( count( $changedIDs ) >= $batch )
                {
                    $finishBatch( $changedIDs );
                    $state['changed'] += count( $changedIDs );
                    $changedIDs = array();
                    $save();
                    eZContentObject::clearCache();
                }
            }
            $finishBatch( $changedIDs );
            $state['changed'] += count( $changedIDs );
            $state['phase'] = 'done';
            $save();
        }
        if ( $site->variable( 'SearchSettings', 'DelayedIndexing' ) !== $delayed )
            $site->setVariable( 'SearchSettings', 'DelayedIndexing', $delayed );

        $report = self::datesReport( $state['resolved'] );
        $report['changed'] = (int)$state['changed'];
        $report['pending'] = count( $state['pending'] );
        $report['phase'] = $state['phase'];
        $report['dry_run'] = (bool)$options['dry_run'];
        $report['index'] = $options['index'];
        if ( !$options['dry_run'] )
        {
            if ( !is_dir( $this->workDir . '/reports' ) )
                mkdir( $this->workDir . '/reports', 0775, true );
            file_put_contents( $this->workDir . '/reports/dates-' . date( 'Ymd-His' ) . '.json',
                               json_encode( array( 'schema' => self::SCHEMA, 'type' => $this->label ) + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        }
        return $report;
    }

    /** @var array the page last read by parsedValues(): array( 'page' => path, 'values' => path => attributes ) */
    protected $parsedCache = array( 'page' => null, 'values' => array() );

    /**
     * The attribute values the import would write today for a source path: its page read and mapped by the type, and
     * the pages it carries (its comments, by their paths "<page>#comment<id>"). The last page read is kept, since a
     * page's comments follow it.
     *
     * @param string $path
     * @return array attribute identifier => value, empty when the path is no page of the source
     */
    protected function parsedValues( $path )
    {
        $path = (string)$path;
        $pagePath = strpos( $path, '#' ) !== false ? strstr( $path, '#', true ) : $path;
        if ( $pagePath === '' || substr( $pagePath, -1 ) === '/' )
            return array();
        if ( $this->parsedCache['page'] !== $pagePath )
        {
            $values = array();
            try
            {
                $page = $this->type->parse( $this->source, $pagePath );
                if ( is_array( $page ) && !isset( $page['redirect_to'] ) && !isset( $page['asset_of'] ) )
                {
                    $walk = function ( array $item ) use ( &$walk, &$values )
                    {
                        $mapped = $this->type->map( $item );
                        $values[(string)$item['path']] = isset( $mapped['attributes'] ) ? (array)$mapped['attributes'] : array();
                        foreach ( isset( $item['children'] ) ? (array)$item['children'] : array() as $child )
                            $walk( $child );
                    };
                    $walk( $page );
                }
            }
            catch ( Throwable $e )
            {
                $values = array();
            }
            $this->parsedCache = array( 'page' => $pagePath, 'values' => $values );
        }
        return isset( $this->parsedCache['values'][$path] ) ? $this->parsedCache['values'][$path] : array();
    }

    /**
     * @param array $resolved object id => array( created, modified, created source, modified source, family )
     * @return array( 'objects' => n, 'created_sources' => source => n, 'modified_sources' => source => n,
     *                'today' => n, 'today_percent' => float, 'families' => family => array( n, min, median, max year ) )
     */
    public static function datesReport( array $resolved )
    {
        $out = array( 'objects' => count( $resolved ), 'created_sources' => array(), 'modified_sources' => array(), 'today' => 0, 'families' => array() );
        $years = $familySources = array();
        foreach ( $resolved as $row )
        {
            list( $created, , $createdSource, $modifiedSource, $family ) = array_pad( array_values( $row ), 5, '' );
            $out['created_sources'][$createdSource] = ( isset( $out['created_sources'][$createdSource] ) ? $out['created_sources'][$createdSource] : 0 ) + 1;
            $out['modified_sources'][$modifiedSource] = ( isset( $out['modified_sources'][$modifiedSource] ) ? $out['modified_sources'][$modifiedSource] : 0 ) + 1;
            $familySources[$family][$createdSource] = ( isset( $familySources[$family][$createdSource] ) ? $familySources[$family][$createdSource] : 0 ) + 1;
            if ( $createdSource === expStaticImportDates::TODAY )
                $out['today']++;
            $years[$family][] = (int)date( 'Y', (int)$created );
        }
        arsort( $out['created_sources'] );
        arsort( $out['modified_sources'] );
        $out['today_percent'] = $out['objects'] ? round( 100 * $out['today'] / $out['objects'], 2 ) : 0.0;
        ksort( $years );
        foreach ( $years as $family => $list )
        {
            sort( $list );
            $out['families'][$family] = array( 'objects' => count( $list ), 'min' => $list[0], 'median' => $list[(int)floor( ( count( $list ) - 1 ) / 2 )],
                                               'max' => $list[count( $list ) - 1], 'created_sources' => $familySources[$family] );
        }
        return $out;
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
        // GitAuthors=enabled, or a git rule in DateRules[]: the history of the repository (who changed which page, and
        // when), kept as a bare clone without file contents (history.git in the work folder), cloned once and fetched
        // with every pull
        if ( $this->rules->enabled( 'GitAuthors', false ) || $this->datesChain()->uses( 'git' ) )
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
