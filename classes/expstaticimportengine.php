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
                               'flagged' => array(), 'failed' => array(), 'removed' => array(), 'warnings' => array() );
        $this->audit( 'system.staticimport_run.start', array( 'run' => $run['id'], 'resumed' => $resume ) );

        $user = $this->actAs();
        $this->source = $this->prepareSource();
        $this->emit( 'source', array( 'source' => $this->source ) );

        $paths = $this->type->discover( $this->source );
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
        if ( method_exists( $this->type, 'setKnownPaths' ) )
            $this->type->setKnownPaths( $paths );
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
                    $assets = array_keys( $this->assetPaths );
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

        $this->report['seconds'] = round( microtime( true ) - $started, 1 );
        $this->report['finished'] = date( 'c' );
        if ( !$this->options['dry_run'] )
        {
            $this->state->data['run'] = null;
            $this->state->data['pending_links'] = array();
            $this->state->addRun( array( 'id' => $run['id'], 'finished' => time(), 'seconds' => $this->report['seconds'], 'counts' => $this->report['counts'] ) );
            $this->state->save();
        }
        $this->writeReports();
        $this->audit( 'system.staticimport_run.finish', array( 'run' => $run['id'], 'counts' => $this->report['counts'] ) );
        $this->emit( 'done', $this->report );
        return $this->report;
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
                return;
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
                             . ( empty( $page['children'] ) ? '' : md5( json_encode( $page['children'] ) ) ) );
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
            $this->assetPaths[$asset] = true;

        $parentNode = $page['parent_path'] !== '' ? $this->state->nodeOf( $page['parent_path'] ) : $this->parentNode();
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
            $attributes[$identifier] = $value === '{body}' ? $xml : ( $value === '{hash}' ? $sourceHash : $value );

        $remoteID = self::remoteID( $this->label, $path );
        $object = eZContentObject::fetchByRemoteID( $remoteID );

        if ( $this->options['dry_run'] )
        {
            $this->report['counts'][$object ? ( $previous && $previous['source_hash'] === $sourceHash ? 'unchanged' : 'updated' ) : 'created']++;
            return;
        }

        if ( $object instanceof eZContentObject )
        {
            // the hash is taken over the attributes the import wrote then, so a changed attribute map is no edit
            $writtenHash = $previous && isset( $previous['written_hash'] ) ? $previous['written_hash'] : '';
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
            $this->moveIfNeeded( $object, $parentNode, $path );
            if ( $previous && isset( $previous['source_hash'] ) && $previous['source_hash'] === $sourceHash && !array_diff( $before, $unresolved ) && !$edited )
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
            if ( $this->state->nodeOf( $target ) || $this->state->asset( $target ) )
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
            foreach ( array_unique( array( preg_replace( '/\.html?$/i', '', $path ), $path ) ) as $old )
            {
                $old = ( $prefix !== '' ? $prefix . '/' : '' ) . $old;
                $result = eZURLAliasML::storePath( $old, 'eznode:' . $node, $this->language() ?: false, false, true, false, false, false, false, true );
                if ( isset( $result['status'] ) && $result['status'] === true )
                    $this->report['counts']['redirects']++;
            }
            $this->state->setPage( $path, array( 'redirected' => true ) );
        }
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
