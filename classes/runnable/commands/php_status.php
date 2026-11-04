<?php
/**
 * exp:static-import:status (ext:exp_static_html_imports:status): the state of an import type.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Status extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "The state of the static imports: per type the pages by status (created, updated, unchanged, flagged,\n"
                . "failed, duplicate, removed), the media, a run in progress and the last runs.\n\n"
                . "  --type=<label>   one type (default: every type that has run)\n"
                . "  --format=json    as JSON (schema exp-static-import/1)",
            'use-session' => false, 'use-modules' => false, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][format:]', '', array( 'type' => 'One import type', 'format' => 'text or json' ) );
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        $labels = !empty( $options['type'] ) ? array( ltrim( (string)$options['type'], '-' ) ) : array_keys( \expStaticImportRegistry::types() );
        $out = array();
        foreach ( $labels as $label )
        {
            $status = \expStaticImportEngine::status( $label );
            if ( empty( $options['type'] ) && !$status['pages'] && !$status['runs'] )
                continue;
            $out[$label] = $status;
        }
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'types' => $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        else
        {
            if ( !$out )
                $this->output( 'No import has run yet.' );
            foreach ( $out as $label => $status )
            {
                $this->output( $label . ': ' . $status['pages'] . ' pages, ' . $status['assets'] . ' media' );
                foreach ( $status['by_status'] as $name => $count )
                    $this->output( sprintf( '  %-10s %d', $name, $count ) );
                if ( $status['run_in_progress'] )
                    $this->output( '  run ' . $status['run_in_progress']['id'] . ' interrupted in phase ' . $status['run_in_progress']['phase'] . ' at ' . $status['run_in_progress']['position'] . ' (the next run resumes it)' );
                foreach ( array_slice( $status['runs'], 0, 5 ) as $run )
                    $this->output( sprintf( '  run %s  %s  %.1f s  %d created, %d updated, %d flagged, %d failed', $run['id'], date( 'Y-m-d H:i', $run['finished'] ), $run['seconds'],
                                            $run['counts']['created'], $run['counts']['updated'], $run['counts']['flagged'], $run['counts']['failed'] ) );
            }
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
