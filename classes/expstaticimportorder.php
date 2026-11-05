<?php
/**
 * File containing the expStaticImportOrder class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The order of the children of the imported containers, as the static site listed them. A static export keeps the
 * order its site chose in the menus and child lists of each page; the import writes every page with priority 0, so
 * a container would list its children by name. This step reads, for each imported container, the links of its own
 * source page inside OrderSelectors[] (in turn: the table of contents, the child list ...), keeps those that lead to
 * one of its children, in the order they first appear, and gives those children the priorities 10, 20, 30 ...; the
 * children the page does not list follow in the alphabetical order of their names. The container then sorts its
 * children by priority, ascending. Both through the kernel's operations (updatePriority, changeSortOrder).
 *
 * Left alone: a container whose page lists none of its children (a container a console step made, a page without a
 * menu), a container with children that are no objects of this import (copies or pages made in the CMS; their order
 * is an editor's), and children of the classes OrderIgnoreClasses[] (comments, which keep their own order).
 * OrderSkipNodes[] names containers that are never touched (an order an editor set by hand).
 *
 * Settings of the type ([Type_<label>] of statichtmlimport.ini):
 *   OrderSelectors[]       where a page lists its children, e.g. #contentstructure, #page-children
 *   OrderIgnoreClasses[]   classes of children that keep their priority (and do not stop the container)
 *   OrderSkipNodes[]       container node ids never touched
 *   OrderStep              the priority step (default 10)
 *
 * The planning (links() and plan()) works without a database and is what the tests check; run() is the console step
 * (exp:static-import:order) and the import's last phase for the containers of the pages it created.
 */
class expStaticImportOrder
{
    /**
     * The hrefs inside the selectors, the first selector's first, in document order, each once.
     *
     * @param string $html the container's source page
     * @param array $selectors
     * @return array of href strings
     */
    public static function links( $html, array $selectors )
    {
        if ( !$selectors || trim( (string)$html ) === '' )
            return array();
        $doc = expStaticImportDom::load( (string)$html );
        $hrefs = array();
        foreach ( $selectors as $selector )
            foreach ( expStaticImportDom::query( $doc, (string)$selector ) as $area )
                foreach ( expStaticImportDom::query( $doc, 'a[href]', $area ) as $link )
                {
                    $href = (string)$link->getAttribute( 'href' );
                    if ( $href !== '' && !isset( $hrefs[$href] ) )
                        $hrefs[$href] = true;
                }
        return array_keys( $hrefs );
    }

    /**
     * The new priorities of a container's children.
     *
     * @param array $listed node ids in the order the source page lists them (other nodes may be among them)
     * @param array $children node id => array( 'name' => string, 'priority' => int, 'class' => string, 'imported' => bool )
     * @param array $ignoreClasses classes that keep their priority
     * @param int $step
     * @return array( 'priorities' => node id => priority (only the children that change), 'listed' => int, 'reason' => string )
     *         reason is '' when the container is ordered, else why it is left alone
     */
    public static function plan( array $listed, array $children, array $ignoreClasses = array(), $step = 10 )
    {
        $step = max( 1, (int)$step );
        $pages = array();
        foreach ( $children as $nodeID => $child )
        {
            if ( in_array( (string)$child['class'], $ignoreClasses, true ) )
                continue;
            if ( empty( $child['imported'] ) )
                return array( 'priorities' => array(), 'listed' => 0, 'reason' => 'children that are not from the import' );
            $pages[(int)$nodeID] = $child;
        }
        $order = array();
        foreach ( $listed as $nodeID )
            if ( isset( $pages[(int)$nodeID] ) && !in_array( (int)$nodeID, $order, true ) )
                $order[] = (int)$nodeID;
        if ( !$order )
            return array( 'priorities' => array(), 'listed' => 0, 'reason' => 'the page lists none of its children' );
        $count = count( $order );
        $rest = array_diff( array_keys( $pages ), $order );
        usort( $rest, function ( $a, $b ) use ( $pages )
        {
            $byName = strnatcasecmp( (string)$pages[$a]['name'], (string)$pages[$b]['name'] );
            return $byName !== 0 ? $byName : $a - $b;
        } );
        $priorities = array();
        foreach ( array_merge( $order, $rest ) as $index => $nodeID )
            if ( (int)$pages[$nodeID]['priority'] !== ( $index + 1 ) * $step )
                $priorities[$nodeID] = ( $index + 1 ) * $step;
        return array( 'priorities' => $priorities, 'listed' => $count, 'reason' => '' );
    }

    /**
     * Orders the children of the imported containers.
     *
     * @param expStaticImportEngine $engine
     * @param array $options dry_run, paths (shell patterns of the containers' source paths), nodes (container node ids:
     *              only these), limit (containers), progress (callable done, of)
     * @return array the report: containers, ordered, unchanged, skipped (reason => count), children, examples, sort_changed
     */
    public static function run( expStaticImportEngine $engine, array $options = array() )
    {
        $options = array_merge( array( 'dry_run' => false, 'paths' => array(), 'nodes' => array(), 'limit' => 0, 'progress' => null ), $options );
        $rules = $engine->rules();
        $selectors = $rules->getList( 'OrderSelectors' );
        $report = array( 'containers' => 0, 'ordered' => 0, 'unchanged' => 0, 'skipped' => array(), 'children' => 0, 'sort_changed' => 0,
                         'failed' => 0, 'examples' => array(), 'dry_run' => (bool)$options['dry_run'] );
        if ( !$selectors )
        {
            $report['skipped']['no OrderSelectors[] for this type'] = 1;
            return $report;
        }
        $ignore = $rules->getList( 'OrderIgnoreClasses' );
        $skipNodes = array_map( 'intval', $rules->getList( 'OrderSkipNodes' ) );
        $step = (int)$rules->get( 'OrderStep', 10 ) ?: 10;
        $engine->prepareForConsoleStep();
        $engine->preloadRemoteNodes();
        $resolver = $engine->linkResolver( true );
        $source = $engine->sourceDirectory();
        $prefix = 'staticimport-' . $engine->label() . '-';

        // md5 of a source path => the path (the remote id of an imported object carries it)
        $paths = array();
        foreach ( array_keys( (array)$engine->state()->data['pages'] ) as $path )
            $paths[md5( (string)$path )] = (string)$path;

        $db = eZDB::instance();
        $where = '';
        if ( $options['nodes'] )
            $where = ' AND p.node_id IN (' . implode( ',', array_map( 'intval', (array)$options['nodes'] ) ) . ')';
        $containers = $db->arrayQuery( "SELECT p.node_id, p.contentobject_id, p.sort_field, p.sort_order, o.remote_id
                                        FROM ezcontentobject_tree p JOIN ezcontentobject o ON o.id = p.contentobject_id AND o.status = 1
                                        WHERE p.node_id = p.main_node_id AND o.remote_id LIKE '" . $db->escapeString( $prefix ) . "%'$where
                                          AND EXISTS ( SELECT 1 FROM ezcontentobject_tree c WHERE c.parent_node_id = p.node_id )
                                        ORDER BY p.node_id" );
        $total = count( $containers );
        foreach ( $containers as $index => $container )
        {
            if ( $options['limit'] > 0 && $report['containers'] >= (int)$options['limit'] )
                break;
            if ( $options['progress'] && $index % 100 === 0 )
                call_user_func( $options['progress'], $index, $total );
            $nodeID = (int)$container['node_id'];
            $hash = substr( (string)$container['remote_id'], strlen( $prefix ), 32 );
            $path = isset( $paths[$hash] ) ? $paths[$hash] : '';
            if ( $options['paths'] && ( $path === '' || !self::matches( $path, (array)$options['paths'] ) ) )
                continue;
            $report['containers']++;
            if ( in_array( $nodeID, $skipNodes, true ) )
            {
                self::skip( $report, 'OrderSkipNodes[]' );
                continue;
            }
            $file = $path !== '' ? $source . '/' . $path : '';
            if ( $path === '' || substr( $path, -1 ) === '/' || !is_file( $file ) )
            {
                self::skip( $report, 'no source page of its own' );
                continue;
            }
            $children = array();
            foreach ( $db->arrayQuery( "SELECT t.node_id, t.priority, o.name, o.remote_id, c.identifier FROM ezcontentobject_tree t
                                        JOIN ezcontentobject o ON o.id = t.contentobject_id JOIN ezcontentclass c ON c.id = o.contentclass_id AND c.version = 0
                                        WHERE t.parent_node_id = $nodeID" ) as $row )
                $children[(int)$row['node_id']] = array( 'name' => (string)$row['name'], 'priority' => (int)$row['priority'], 'class' => (string)$row['identifier'],
                                                         'imported' => strpos( (string)$row['remote_id'], $prefix ) === 0 );
            $listed = array();
            foreach ( self::links( (string)file_get_contents( $file ), $selectors ) as $href )
            {
                $link = $resolver->resolve( $path, $href );
                if ( $link['status'] === expStaticImportLinkResolver::NODE && (int)$link['node'] )
                    $listed[] = (int)$link['node'];
            }
            $plan = self::plan( $listed, $children, $ignore, $step );
            if ( $plan['reason'] !== '' )
            {
                self::skip( $report, $plan['reason'] );
                continue;
            }
            $sortChange = (int)$container['sort_field'] !== eZContentObjectTreeNode::SORT_FIELD_PRIORITY
                          || (int)$container['sort_order'] !== eZContentObjectTreeNode::SORT_ORDER_ASC;
            if ( !$plan['priorities'] && !$sortChange )
            {
                $report['unchanged']++;
                continue;
            }
            if ( count( $report['examples'] ) < 20 )
                $report['examples'][] = array( 'node' => $nodeID, 'path' => $path, 'listed' => $plan['listed'], 'children' => count( $children ),
                                               'changed' => count( $plan['priorities'] ) );
            if ( !$options['dry_run'] && !self::write( $nodeID, $plan['priorities'], $sortChange ) )
            {
                $report['failed']++;
                continue;
            }
            $report['ordered']++;
            $report['children'] += count( $plan['priorities'] );
            $report['sort_changed'] += $sortChange ? 1 : 0;
        }
        return $report;
    }

    /**
     * Writes one container through the kernel's operations, again while the database is busy.
     */
    protected static function write( $nodeID, array $priorities, $sortChange )
    {
        for ( $attempt = 1; $attempt <= 6; $attempt++ )
        {
            try
            {
                if ( $priorities )
                    eZContentOperationCollection::updatePriority( $nodeID, array_values( $priorities ), array_keys( $priorities ) );
                if ( $sortChange )
                    eZContentOperationCollection::changeSortOrder( $nodeID, eZContentObjectTreeNode::SORT_FIELD_PRIORITY, true );
                return true;
            }
            catch ( Exception $e )
            {
                $db = eZDB::instance();
                while ( $db->transactionCounter() > 0 )
                    $db->rollback();
                eZDebug::writeWarning( "Ordering the children of node $nodeID, attempt $attempt: " . $e->getMessage(), __METHOD__ );
                sleep( min( 30, 5 * $attempt ) );
            }
        }
        return false;
    }

    protected static function skip( array &$report, $reason )
    {
        $report['skipped'][$reason] = isset( $report['skipped'][$reason] ) ? $report['skipped'][$reason] + 1 : 1;
    }

    protected static function matches( $path, array $patterns )
    {
        foreach ( $patterns as $pattern )
            if ( fnmatch( (string)$pattern, $path ) )
                return true;
        return false;
    }
}
