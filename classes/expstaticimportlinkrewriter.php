<?php
/**
 * File containing the expStaticImportLinkRewriter class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Rewrites the links of the bodies an import already stored (exp:static-import:links): every <link url_id> of an
 * XML text whose address is an imported page or media file becomes <link node_id> (the eznode form, resolved to the
 * node's current address on every render), with the anchor kept as anchor_name. Nothing else of the text changes.
 *
 * - The current version is changed in place: no new version, no editor change. The edit detection's baseline (the
 *   page's written hash) moves with it through a state patch, but only for a page that was unchanged by editors
 *   before (an edited page stays flagged).
 * - The kernel's link relations (RELATION_LINK) are added, and the url links that are no longer used are removed, so
 *   the view caches of the linking pages are cleared when a target moves (viewcache.ini reverse_linked).
 * - Only the view caches of the changed pages are cleared.
 * - Batches of objects in id order; the position is saved after every batch (links-rewrite.json in the work folder),
 *   so a stopped run resumes where it ended. --restart starts from the first object.
 * - Every link it cannot resolve is reported per page, with its form, its address and the reason (the address
 *   stays as it was): links-unresolved.jsonl in the work folder, one line per page (links-unresolved-dry-run.jsonl
 *   for a dry run).
 */
class expStaticImportLinkRewriter
{
    /** @var expStaticImportEngine */
    protected $engine;

    /** @var array */
    protected $options;

    /** @var array node id => object id, every node there is */
    protected $nodes = array();

    /** @var array object id => its node ids */
    protected $nodesOfObject = array();

    /** @var array path by object id (the state's pages) */
    protected $pathOfObject = array();

    /**
     * @param expStaticImportEngine $engine
     * @param array $options dry_run, batch (objects per batch, default 200), limit (objects, 0 = all), restart,
     *                       objects (only these object ids)
     */
    public function __construct( expStaticImportEngine $engine, array $options = array() )
    {
        $this->engine = $engine;
        $this->options = array_merge( array( 'dry_run' => false, 'batch' => 200, 'limit' => 0, 'restart' => false, 'objects' => array() ), $options );
    }

    /**
     * @param callable|null $progress receives (int done, int last object id, array totals)
     * @return array the totals
     */
    public function run( $progress = null )
    {
        $this->engine->prepareForConsoleStep();
        $this->engine->preloadPageIdNodes();
        $this->engine->preloadRemoteNodes();
        $resolver = $this->engine->linkResolver();
        $state = $this->engine->state();
        $label = $this->engine->label();
        $work = $this->engine->workDirectory();
        $db = eZDB::instance();
        $dry = !empty( $this->options['dry_run'] );

        foreach ( $state->data['pages'] as $path => $entry )
            if ( !empty( $entry['object_id'] ) && empty( $entry['duplicate_of'] ) && !isset( $this->pathOfObject[(int)$entry['object_id']] ) )
                $this->pathOfObject[(int)$entry['object_id']] = (string)$path;
        foreach ( $db->arrayQuery( 'SELECT node_id, contentobject_id FROM ezcontentobject_tree' ) as $row )
        {
            $this->nodes[(int)$row['node_id']] = (int)$row['contentobject_id'];
            $this->nodesOfObject[(int)$row['contentobject_id']][] = (int)$row['node_id'];
        }
        $xmlAttributes = $sourceAttributes = array();
        foreach ( $db->arrayQuery( "SELECT id, identifier, data_type_string FROM ezcontentclass_attribute WHERE version = 0" ) as $row )
        {
            if ( $row['data_type_string'] === 'ezxmltext' )
                $xmlAttributes[(int)$row['id']] = $row['identifier'];
            else if ( $row['identifier'] === 'source_path' )
                $sourceAttributes[(int)$row['id']] = true;
        }

        $cursorFile = $work . '/links-rewrite.json';
        $reportFile = $work . ( $dry ? '/links-unresolved-dry-run.jsonl' : '/links-unresolved.jsonl' );
        $cursor = !$this->options['restart'] && !$dry && !$this->options['objects'] && is_file( $cursorFile ) ? json_decode( (string)file_get_contents( $cursorFile ), true ) : null;
        $totals = is_array( $cursor ) && isset( $cursor['totals'] ) ? $cursor['totals'] : self::emptyTotals();
        $last = is_array( $cursor ) && isset( $cursor['last_object_id'] ) ? (int)$cursor['last_object_id'] : 0;
        if ( $last === 0 || $this->options['restart'] )
        {
            $totals = self::emptyTotals();
            if ( !$this->options['objects'] )
                @file_put_contents( $reportFile, '' );
        }
        $totals['started'] = isset( $totals['started'] ) && $totals['started'] ? $totals['started'] : date( 'c' );
        $batch = max( 1, (int)$this->options['batch'] );
        $done = 0;
        $remote = $db->escapeString( 'staticimport-' . $label . '-' );
        $only = $this->options['objects'] ? ' AND id IN (' . implode( ',', array_map( 'intval', (array)$this->options['objects'] ) ) . ')' : '';

        while ( true )
        {
            $limit = $this->options['limit'] ? min( $batch, (int)$this->options['limit'] - $done ) : $batch;
            if ( $limit <= 0 )
                break;
            $objects = $db->arrayQuery( "SELECT id, current_version FROM ezcontentobject WHERE status = 1 AND remote_id LIKE '{$remote}%' AND id > $last$only ORDER BY id",
                                        array( 'limit' => $limit ) );
            if ( !$objects )
            {
                $totals['finished'] = date( 'c' );
                break;
            }
            $versions = array();
            foreach ( $objects as $row )
                $versions[(int)$row['id']] = (int)$row['current_version'];
            $in = implode( ',', array_keys( $versions ) );
            $rows = $db->arrayQuery( "SELECT id, contentobject_id, version, contentclassattribute_id, data_text FROM ezcontentobject_attribute
                                      WHERE contentobject_id IN ($in) AND contentclassattribute_id IN (" . implode( ',', array_merge( array_keys( $xmlAttributes ), array_keys( $sourceAttributes ) ) ) . ')' );
            $byObject = array();
            $urlIDs = array();
            foreach ( $rows as $row )
            {
                if ( (int)$row['version'] !== $versions[(int)$row['contentobject_id']] )
                    continue;
                $byObject[(int)$row['contentobject_id']][] = $row;
                if ( isset( $xmlAttributes[(int)$row['contentclassattribute_id']] ) && preg_match_all( '/\burl_id="(\d+)"/', (string)$row['data_text'], $m ) )
                    foreach ( $m[1] as $id )
                        $urlIDs[(int)$id] = true;
            }
            $urls = array();
            if ( $urlIDs )
                foreach ( $db->arrayQuery( 'SELECT id, url FROM ezurl WHERE id IN (' . implode( ',', array_keys( $urlIDs ) ) . ')' ) as $row )
                    $urls[(int)$row['id']] = (string)$row['url'];

            $patch = array();
            $changedObjects = array();
            $reportLines = array();
            if ( !$dry )
                $db->begin();
            foreach ( $versions as $objectID => $version )
            {
                $last = $objectID;
                $done++;
                $totals['objects']++;
                $path = isset( $this->pathOfObject[$objectID] ) ? $this->pathOfObject[$objectID] : '';
                foreach ( isset( $byObject[$objectID] ) ? $byObject[$objectID] : array() as $row )
                    if ( $path === '' && isset( $sourceAttributes[(int)$row['contentclassattribute_id']] ) )
                        $path = (string)$row['data_text'];
                $pagePath = strpos( $path, '#' ) !== false ? substr( $path, 0, strpos( $path, '#' ) ) : $path;
                $pageUnresolved = array();
                $changes = array();
                foreach ( isset( $byObject[$objectID] ) ? $byObject[$objectID] : array() as $row )
                {
                    if ( !isset( $xmlAttributes[(int)$row['contentclassattribute_id']] ) || strpos( (string)$row['data_text'], '<link' ) === false )
                        continue;
                    $result = $this->rewriteXml( (string)$row['data_text'], $pagePath, $urls, $resolver, $totals, $pageUnresolved, $objectID );
                    if ( $result !== null )
                        $changes[] = array( 'row' => $row, 'xml' => $result['xml'], 'targets' => $result['targets'], 'removed_urls' => $result['removed_urls'] );
                }
                if ( $pageUnresolved )
                {
                    $totals['pages_with_unresolved']++;
                    $reportLines[] = json_encode( array( 'object' => $objectID, 'path' => $path, 'unresolved' => $pageUnresolved ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                }
                if ( !$changes )
                    continue;
                $totals['pages_changed']++;
                if ( $dry )
                    continue;
                $object = eZContentObject::fetch( $objectID );
                if ( !$object instanceof eZContentObject )
                    continue;
                // the edit detection: a page nobody edited keeps its baseline (the written hash moves with the links)
                $entry = $path !== '' ? $state->page( $path ) : null;
                $fields = $entry && !empty( $entry['written_fields'] ) ? (array)$entry['written_fields'] : array();
                $wasClean = $entry && !empty( $entry['written_hash'] ) && $fields && $this->engine->writtenHashOf( $object, $fields ) === $entry['written_hash'];
                foreach ( $changes as $change )
                {
                    $row = $change['row'];
                    $db->query( "UPDATE ezcontentobject_attribute SET data_text = '" . $db->escapeString( $change['xml'] ) . "'
                                 WHERE id = " . (int)$row['id'] . ' AND version = ' . (int)$row['version'] );
                    if ( $change['removed_urls'] )
                        $db->query( 'DELETE FROM ezurl_object_link WHERE contentobject_attribute_id = ' . (int)$row['id'] . ' AND contentobject_attribute_version = ' . (int)$row['version']
                                    . ' AND url_id IN (' . implode( ',', array_map( 'intval', $change['removed_urls'] ) ) . ')' );
                    foreach ( array_unique( $change['targets'] ) as $toObject )
                        if ( $toObject && $toObject !== $objectID )
                            $object->addContentObjectRelation( $toObject, $version, 0, eZContentObject::RELATION_LINK );
                }
                eZContentObject::clearCache( array( $objectID ) );
                $changedObjects[] = $objectID;
                if ( $wasClean )
                {
                    $object = eZContentObject::fetch( $objectID );
                    $patch[$path] = array( 'written_hash' => $this->engine->writtenHashOf( $object, $fields ),
                                           'unresolved' => array_values( array_unique( array_map( function ( $u ) { return $u['target'] !== '' ? $u['target'] : $u['href']; }, $pageUnresolved ) ) ) );
                    $totals['baselines_moved']++;
                }
                else
                    $totals['edited_pages_changed']++;
            }
            if ( !$dry )
            {
                $db->commit();
                if ( $patch )
                    $this->engine->addStatePatch( $patch, 'links' );
                // the view caches of the changed pages only (their own nodes), a few at a time (CacheThreshold)
                foreach ( array_chunk( $changedObjects, 40 ) as $chunk )
                {
                    $nodeIDs = array();
                    foreach ( $chunk as $id )
                        foreach ( isset( $this->nodesOfObject[$id] ) ? $this->nodesOfObject[$id] : array() as $nodeID )
                            $nodeIDs[] = $nodeID;
                    if ( $nodeIDs )
                        eZContentCacheManager::clearNodeViewCacheArray( $nodeIDs, $chunk );
                }
                if ( !$this->options['objects'] )
                    file_put_contents( $cursorFile, json_encode( array( 'last_object_id' => $last, 'totals' => $totals, 'saved' => date( 'c' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            }
            if ( $reportLines && !$this->options['objects'] )
                file_put_contents( $reportFile, implode( "\n", $reportLines ) . "\n", FILE_APPEND );
            eZContentObject::clearCache();
            if ( $progress )
                call_user_func( $progress, $done, $last, $totals );
        }
        arsort( $totals['unresolved_forms'] );
        arsort( $totals['unresolved_reasons'] );
        arsort( $totals['external_hosts'] );
        arsort( $totals['unresolved_targets'] );
        $totals['unresolved_targets'] = array_slice( $totals['unresolved_targets'], 0, 60, true );
        $totals['unresolved_examples'] = array_intersect_key( $totals['unresolved_examples'], $totals['unresolved_targets'] );
        $totals['external_hosts'] = array_slice( $totals['external_hosts'], 0, 40, true );
        return $totals;
    }

    /**
     * @param string $xml the stored XML
     * @param string $path the page's source path
     * @param array $urls url id => address
     * @param expStaticImportLinkResolver $resolver
     * @param array $totals counters
     * @param array $unresolved the page's unresolved links (added to)
     * @param int $objectID
     * @return array|null xml, targets (object ids linked), removed_urls; null when nothing changed
     */
    public function rewriteXml( $xml, $path, array $urls, expStaticImportLinkResolver $resolver, array &$totals, array &$unresolved, $objectID = 0 )
    {
        $doc = new DOMDocument();
        if ( !@$doc->loadXML( $xml ) )
        {
            $totals['xml_errors']++;
            return null;
        }
        $changed = false;
        $targets = array();
        $before = array();
        $after = array();
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'link' ) ) as $link )
        {
            if ( $link->hasAttribute( 'node_id' ) )
            {
                $totals['links']++;
                $node = (int)$link->getAttribute( 'node_id' );
                if ( isset( $this->nodes[$node] ) )
                {
                    $totals['node_links']++;
                    $targets[] = $this->nodes[$node];
                }
                else
                {
                    $totals['node_links_dead']++;
                    $unresolved[] = array( 'form' => 'eznode', 'href' => 'eznode://' . $node, 'target' => '', 'reason' => 'the node does not exist' );
                }
                continue;
            }
            if ( !$link->hasAttribute( 'url_id' ) )
            {
                if ( $link->hasAttribute( 'anchor_name' ) )
                {
                    $totals['links']++;
                    $totals['anchors']++;
                }
                continue;
            }
            $totals['links']++;
            $urlID = (int)$link->getAttribute( 'url_id' );
            $before[$urlID] = true;
            $address = isset( $urls[$urlID] ) ? $urls[$urlID] : '';
            $anchor = $link->getAttribute( 'anchor_name' );
            $result = $resolver->resolve( $path, $address . ( $anchor !== '' ? '#' . $anchor : '' ) );
            $node = in_array( $result['status'], array( expStaticImportLinkResolver::NODE, expStaticImportLinkResolver::MEDIA ), true ) ? (int)$result['node'] : 0;
            if ( $node && !isset( $this->nodes[$node] ) )
            {
                $result['status'] = expStaticImportLinkResolver::UNRESOLVED;
                $result['reason'] = 'the state names node ' . $node . ', which does not exist';
                $node = 0;
            }
            if ( $node )
            {
                $link->removeAttribute( 'url_id' );
                $link->setAttribute( 'node_id', (string)$node );
                if ( $result['anchor'] !== '' )
                    $link->setAttribute( 'anchor_name', $result['anchor'] );
                $targets[] = $this->nodes[$node];
                $changed = true;
                $totals['rewritten']++;
                $totals['node_links']++;
                continue;
            }
            $after[$urlID] = true;
            switch ( $result['status'] )
            {
                case expStaticImportLinkResolver::EXTERNAL:
                    $totals['external']++;
                    $host = strtolower( (string)parse_url( $address, PHP_URL_HOST ) );
                    $totals['external_hosts'][$host] = ( isset( $totals['external_hosts'][$host] ) ? $totals['external_hosts'][$host] : 0 ) + 1;
                    break;
                case expStaticImportLinkResolver::MAILTO:
                    $totals['mailto']++;
                    break;
                case expStaticImportLinkResolver::IGNORED:
                case expStaticImportLinkResolver::ANCHOR:
                    $totals['ignored']++;
                    break;
                default:
                    $totals['unresolved']++;
                    $totals['unresolved_forms'][$result['form']] = ( isset( $totals['unresolved_forms'][$result['form']] ) ? $totals['unresolved_forms'][$result['form']] : 0 ) + 1;
                    $reason = preg_replace( '/\d+/', 'N', strtok( $result['reason'], ':' ) );
                    $totals['unresolved_reasons'][$reason] = ( isset( $totals['unresolved_reasons'][$reason] ) ? $totals['unresolved_reasons'][$reason] : 0 ) + 1;
                    $pattern = preg_replace( '/\d+/', 'N', preg_replace( '#[^/]+$#', '*', $result['target'] !== '' ? $result['target'] : $address ) );
                    $totals['unresolved_targets'][$pattern] = ( isset( $totals['unresolved_targets'][$pattern] ) ? $totals['unresolved_targets'][$pattern] : 0 ) + 1;
                    if ( !isset( $totals['unresolved_examples'][$pattern] ) )
                        $totals['unresolved_examples'][$pattern] = $path . ' -> ' . $address . ( $anchor !== '' ? '#' . $anchor : '' ) . ' (' . $result['reason'] . ')';
                    $unresolved[] = array( 'form' => $result['form'], 'href' => $address . ( $anchor !== '' ? '#' . $anchor : '' ), 'target' => $result['target'], 'reason' => $result['reason'] );
            }
        }
        if ( !$changed )
            return null;
        return array( 'xml' => $doc->saveXML(), 'targets' => $targets, 'removed_urls' => array_keys( array_diff_key( $before, $after ) ) );
    }

    /**
     * @return array
     */
    public static function emptyTotals()
    {
        return array( 'objects' => 0, 'pages_changed' => 0, 'links' => 0, 'node_links' => 0, 'rewritten' => 0, 'node_links_dead' => 0, 'anchors' => 0,
                      'external' => 0, 'mailto' => 0, 'ignored' => 0, 'unresolved' => 0, 'pages_with_unresolved' => 0, 'baselines_moved' => 0,
                      'edited_pages_changed' => 0, 'xml_errors' => 0, 'unresolved_forms' => array(), 'unresolved_reasons' => array(), 'unresolved_targets' => array(), 'unresolved_examples' => array(),
                      'external_hosts' => array(), 'started' => '', 'finished' => '' );
    }
}
