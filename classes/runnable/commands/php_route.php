<?php
/**
 * exp:static-import:route (ext:exp_static_html_imports:route): puts the pages an earlier run imported where the
 * type's RouteRules[] send them today, with unique names, without a run.
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
            'description' => "Put the pages an earlier run imported where the type's RouteRules[] send them today: the containers of\n"
                . "RouteContainers[] are made, each routed page moves into its container, takes its own name (unique below the\n"
                . "container), its relation to the current page (history_of), and is shown again if an earlier step hid it.\n"
                . "The state file is never written (a state patch tells the next run), so it is safe while a run is going on.\n"
                . "Running it again changes only what is still out of place.\n\n"
                . "  --type=<label>   the import type (e.g. doc-html)\n"
                . "  --dry-run        count, change nothing\n"
                . "  --format=json    as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][dry-run][format:]', '', array( 'type' => 'The import type', 'dry-run' => 'Change nothing', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->applyRoutes( !empty( $options['dry-run'] ) );
        if ( isset( $options['format'] ) && $options['format'] === 'json' )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( $result['families'] as $family => $count )
                $this->output( sprintf( '  %6d  %s', $count, $family ) );
            $this->output( sprintf( 'PASS %d containers (%d new), %d routed pages imported (%d not yet), %d moved, %d renamed, %d shown again, %d relations; the root had %d children, now %d',
                                    $result['containers'], $result['containers_created'], $result['routed'], $result['not_imported'], $result['moved'], $result['renamed'],
                                    $result['shown'], $result['related'], $result['root_children_before'], $result['root_children_after'] ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
