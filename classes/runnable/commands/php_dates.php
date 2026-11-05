<?php
/**
 * exp:static-import:dates (ext:exp_static_html_imports:dates): gives the objects an earlier run imported their real
 * dates (published and modified) by the type's DateRules[].
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Dates extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Give every object an earlier run imported (pages, sections and containers, comments, media) its real dates:\n"
                . "the object's published and modified dates and its versions' created and modified dates, found by the type's\n"
                . "DateRules[] (the page's own byline values, the source repository's git history, the file's <meta> date tags,\n"
                . "its time; the day of the run only when nothing gives a date). Objects without dates of their own take their\n"
                . "children's, the deepest first. Batches, resumable (dates-state.json in the type's work folder; the import's\n"
                . "own state file is never written, so it is safe next to a run). Changed objects are queued for the search\n"
                . "index and their view caches cleared. The dates are no attributes: the import hash does not change.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --dry-run          count, change nothing\n"
                . "  --restart          start again from the first object (default: resume where the last run stopped)\n"
                . "  --batch=<n>        objects per batch (default 200)\n"
                . "  --limit=<n>        stop after about n objects (a test; the next call resumes)\n"
                . "  --index=<mode>     queue (default: for the cronjob indexcontent), now, or none\n"
                . "  --path=<pattern>   only these source paths (shell pattern, may be repeated)\n"
                . "  --skip-file=<file> leave the dates of these objects as they are (one object id per line, the first\n"
                . "                     word; the rest of the line and lines starting with # are comments): pages an\n"
                . "                     editor changed in the CMS\n"
                . "  --format=json      the report as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][dry-run][restart][batch:][limit:][index:][path:*][skip-file:][format:]', '',
                                   array( 'type' => 'The import type', 'dry-run' => 'Change nothing', 'restart' => 'Start from the first object',
                                          'batch' => 'Objects per batch', 'limit' => 'Stop after about n objects', 'index' => 'queue, now or none',
                                          'path' => 'Only these paths', 'skip-file' => 'Object ids to leave alone', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        $index = !empty( $options['index'] ) ? (string)$options['index'] : 'queue';
        if ( $type === '' || !in_array( $index, array( 'queue', 'now', 'none' ), true ) )
        {
            $this->output( $type === '' ? 'FAIL no import type: give --type=<label>' : 'FAIL --index takes queue, now or none' );
            $this->shutdown( 2 );
            return 2;
        }
        $skip = array();
        if ( !empty( $options['skip-file'] ) )
        {
            if ( !is_file( (string)$options['skip-file'] ) || !is_readable( (string)$options['skip-file'] ) )
            {
                $this->output( 'FAIL the skip file ' . $options['skip-file'] . ' cannot be read' );
                $this->shutdown( 2 );
                return 2;
            }
            $skip = \expStaticImportDates::parseSkipList( (string)file_get_contents( (string)$options['skip-file'] ) );
        }
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->restoreDates( array( 'dry_run' => !empty( $options['dry-run'] ), 'restart' => !empty( $options['restart'] ),
                                                'batch' => !empty( $options['batch'] ) ? (int)$options['batch'] : 200,
                                                'limit' => !empty( $options['limit'] ) ? (int)$options['limit'] : 0, 'index' => $index,
                                                'paths' => !empty( $options['path'] ) ? (array)$options['path'] : array(), 'skip' => $skip ),
                                         $json ? null : function ( $done, $of ) { fwrite( STDERR, "\r$done of about $of objects" ); } );
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            $this->output( '' );
            foreach ( array( 'created_sources' => 'created from', 'modified_sources' => 'modified from' ) as $key => $label )
                foreach ( $result[$key] as $source => $count )
                    $this->output( sprintf( '  %-14s %-12s %7d', $label, $source, $count ) );
            foreach ( $result['families'] as $family => $line )
                $this->output( sprintf( '  %-24s %7d objects, published %d / %d / %d (min / median / max)', $family, $line['objects'], $line['min'], $line['median'], $line['max'] ) );
            $this->output( sprintf( '%s %d objects, %d changed, %d (%.2f%%) still dated today, %d waiting, %d skipped; phase %s%s', $result['phase'] === 'done' ? 'PASS' : 'PARTIAL',
                                    $result['objects'], $result['changed'], $result['today'], $result['today_percent'], $result['pending'], $result['skipped'], $result['phase'],
                                    $result['dry_run'] ? ' (dry run)' : '' ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
