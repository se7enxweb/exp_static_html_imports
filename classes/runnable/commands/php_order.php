<?php
/**
 * exp:static-import:order (ext:exp_static_html_imports:order): gives the children of the imported containers the order
 * the static site listed them in (node priorities, the container sorted by priority).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Order extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Give the children of every imported container the order the static site listed them in: the links of the\n"
                . "container's own source page inside the type's OrderSelectors[] (its table of contents, its child list) that\n"
                . "lead to its children, in the order they first appear, get the priorities 10, 20, 30 ...; the children the page\n"
                . "does not list follow by name. The container then sorts its children by priority, ascending. Containers whose\n"
                . "page lists none of their children, containers with children that are not from the import, OrderSkipNodes[]\n"
                . "and children of OrderIgnoreClasses[] (comments) are left alone. Only priorities and the sort order change\n"
                . "(the kernel's updatePriority and changeSortOrder): no new version, no edit for the edit detection, no state\n"
                . "file write. Running it again changes nothing. A run of the import orders the containers it created pages in.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --dry-run          report what would change, change nothing\n"
                . "  --path=<pattern>   only the containers of these source paths (shell pattern, may be repeated)\n"
                . "  --node=<id>        only these container nodes (may be repeated)\n"
                . "  --limit=<n>        stop after n containers\n"
                . "  --format=json      the report as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][dry-run][path:*][node:*][limit:][format:]', '',
                                   array( 'type' => 'The import type', 'dry-run' => 'Change nothing', 'path' => 'Only these paths',
                                          'node' => 'Only these container nodes', 'limit' => 'Stop after n containers', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        // the import's user: the kernel's operations check nothing, but the audit trail names who changed the order
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $login = (string)$engine->rules()->get( 'ImportUser', \eZINI::instance( 'statichtmlimport.ini' )->variable( 'ImportSettings', 'ImportUser' ) );
        $user = $login !== '' ? \eZUser::fetchByName( $login ) : null;
        if ( $user instanceof \eZUser )
            \eZUser::setCurrentlyLoggedInUser( $user, (int)$user->attribute( 'contentobject_id' ) );
        $report = \expStaticImportOrder::run( $engine, array( 'dry_run' => !empty( $options['dry-run'] ),
            'paths' => !empty( $options['path'] ) ? (array)$options['path'] : array(),
            'nodes' => !empty( $options['node'] ) ? array_map( 'intval', (array)$options['node'] ) : array(),
            'limit' => !empty( $options['limit'] ) ? (int)$options['limit'] : 0,
            'progress' => $json ? null : function ( $done, $of ) { fwrite( STDERR, "\r$done of $of containers" ); } ) );
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => $report['failed'] === 0, 'type' => $type ) + $report,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            $this->output( '' );
            foreach ( $report['examples'] as $example )
                $this->output( sprintf( '  node %-7d %-70s %3d listed of %3d children, %3d priorities', $example['node'], $example['path'], $example['listed'],
                                        $example['children'], $example['changed'] ) );
            foreach ( $report['skipped'] as $reason => $count )
                $this->output( sprintf( '  left alone: %-48s %6d', $reason, $count ) );
            $this->output( sprintf( '%s %d containers: %d ordered (%d priorities, %d sort orders), %d already in order, %d failed%s',
                                    $report['failed'] ? 'FAIL' : 'PASS', $report['containers'], $report['ordered'], $report['children'], $report['sort_changed'],
                                    $report['unchanged'], $report['failed'], $report['dry_run'] ? ' (dry run)' : '' ) );
        }
        $this->shutdown( $report['failed'] ? 1 : 0 );
        return $report['failed'] ? 1 : 0;
    }
}

}
