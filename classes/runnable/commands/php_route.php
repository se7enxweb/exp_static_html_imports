<?php
/**
 * exp:static-import:route (ext:exp_static_html_imports:route): puts the pages an earlier run imported where the
 * type's rules send them today, with unique names, without a run.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Route extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Put the pages an earlier run imported where the type's rules send them today, keeping their node ids (the\n"
                . "old addresses stay and redirect): the containers of RouteContainers[] and VirtualPages[] are made, moved and\n"
                . "named as the rules say, each routed page moves into its container, takes its own name (unique below the\n"
                . "container), its relation to the current page (history_of), and is shown again if an earlier step hid it.\n"
                . "The state file is never written (a state patch tells the next run), so it is safe while a run is going on.\n"
                . "Running it again changes only what is still out of place.\n\n"
                . "  --type=<label>   the import type (e.g. doc-html)\n"
                . "  --all            every imported page below the parent the rules give it today (paths, breadcrumbs,\n"
                . "                   ParentRules[], TopParentRules[]) with the title of NameRules[], every group page made,\n"
                . "                   copies (CopyOfPatterns[]) and the leftovers at the top retired (RetiredRules[]),\n"
                . "                   and same-name siblings renamed\n"
                . "  --batch=<n>      a progress line and a cursor (var/<site>/static-import/<label>/route-cursor.json) every n pages\n"
                . "  --resume         continue from the cursor of an interrupted step\n"
                . "  --limit=<n>      stop after n pages (continue with --resume)\n"
                . "  --dry-run        count, change nothing\n"
                . "  --format=json    as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][dry-run][all][batch:][resume][limit:][format:]', '',
                                   array( 'type' => 'The import type', 'dry-run' => 'Change nothing', 'all' => 'Every page where the rules put it',
                                          'batch' => 'Pages per batch', 'resume' => 'Continue from the cursor', 'limit' => 'Stop after this many pages',
                                          'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        $started = microtime( true );
        $progress = function ( $event, array $data ) use ( $json, $started )
        {
            if ( $event !== 'route' )
                return;
            $line = sprintf( 'route %d/%d: %d moved, %d renamed, %d retired, the root has %d children (%ds)', $data['done'], $data['of'], $data['moved'],
                             $data['renamed'], $data['retired'], $data['root_children'], (int)( microtime( true ) - $started ) );
            // JSON goes to the standard output: the progress lines to the error output
            if ( $json )
                fwrite( STDERR, $line . "\n" );
            else
                $this->output( $line );
        };
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ), $progress );
        $result = $engine->applyRoutes( !empty( $options['dry-run'] ), !empty( $options['all'] ),
                                        array( 'batch' => isset( $options['batch'] ) ? (int)$options['batch'] : 0, 'resume' => !empty( $options['resume'] ),
                                               'limit' => isset( $options['limit'] ) ? (int)$options['limit'] : 0 ) );
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( $result['families'] as $family => $count )
                $this->output( sprintf( '  %6d  %s', $count, $family ) );
            foreach ( $result['warnings'] as $warning )
                $this->output( '  warning: ' . $warning );
            $this->output( sprintf( 'PASS %d containers (%d new, %d moved, %d renamed), %d routed pages imported (%d not yet), %d other pages checked, %d moved, %d renamed, %d retired, %d leftovers, %d shown again, %d relations; pages %d to %d of %d%s; the root had %d children, now %d',
                                    $result['containers'], $result['containers_created'], $result['containers_moved'], $result['retitled'], $result['routed'], $result['not_imported'],
                                    $result['pages'], $result['moved'], $result['renamed'], $result['retired'], $result['leftovers'], $result['shown'], $result['related'],
                                    $result['start'], $result['position'], $result['items'], $result['complete'] ? '' : ' (not complete: continue with --resume)',
                                    $result['root_children_before'], $result['root_children_after'] ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
