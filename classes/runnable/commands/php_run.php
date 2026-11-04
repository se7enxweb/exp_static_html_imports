<?php
/**
 * exp:static-import:run (ext:exp_static_html_imports:run): imports a static site, or brings an earlier import in step
 * with its source. Applies by default; --dry-run only counts.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Run extends \Exponential\Runnable\Command
{
    /** @var bool */
    protected $json = false;

    public function run()
    {
        $this->script( array(
            'description' => "Import a static site into the content tree, by content classes, or bring an earlier import in step with\n"
                . "its source: new pages are created, changed pages updated, pages an editor changed in the CMS are flagged and\n"
                . "left alone, pages that left the source are hidden (trashed only with --apply-removals). Writes by default.\n\n"
                . "  --type=<label> or --<label>   the import type: doc-html, html-generic, markdown, sitemap-crawl\n"
                . "  --source=<folder|url>         the source (default: the type's SourceRepository clone or SourceLocation)\n"
                . "  --dry-run                     count what would change, write nothing\n"
                . "  --apply-removals              move pages that left the source to the trash instead of hiding them\n"
                . "  --path=<pattern>              only these source paths (shell pattern, may be repeated)\n"
                . "  --limit=<n>                   only the first n pages\n"
                . "  --batch=<n>                   pages per batch (default BatchSize)\n"
                . "  --restart                     start over instead of resuming an interrupted run\n"
                . "  --no-pull                     do not pull the source repository first\n"
                . "  --parent-node=<id>            where the top pages go (default the type's ParentNode)\n"
                . "  --user=<login>                import as this user (default ImportUser)\n"
                . "  --format=json                 the report as JSON (schema exp-static-import/1)\n\n"
                . "Exit codes: 0 done, 1 pages failed or the run failed, 2 usage error.\n\n"
                . "Example: ./console exp:static-import:run --markdown --source=doc/eng-us --parent-node=60 --dry-run",
            'use-session' => false,
            'use-modules' => true,
            'use-extensions' => true
        ) );
        $labels = array_keys( \expStaticImportRegistry::types() );
        $config = '[type:][source:][dry-run][apply-removals][path:*][limit:][batch:][restart][no-pull][parent-node:][user:][format:]';
        foreach ( $labels as $label )
            $config .= '[' . $label . ']';
        $options = $this->startup( $config, '', array(
            'type' => 'The import type', 'source' => 'The source folder or URL', 'dry-run' => 'Write nothing',
            'apply-removals' => 'Trash pages that left the source', 'path' => 'Only these paths', 'limit' => 'Only n pages',
            'batch' => 'Pages per batch', 'restart' => 'Do not resume', 'no-pull' => 'Do not pull the source',
            'parent-node' => 'The parent node of the top pages', 'user' => 'The login to import as', 'format' => 'text or json',
        ) );
        $this->json = isset( $options['format'] ) && $options['format'] === 'json';
        $type = !empty( $options['type'] ) ? (string)$options['type'] : '';
        foreach ( $labels as $label )
            if ( !empty( $options[$label] ) )
                $type = $label;
        if ( $type === '' )
            return $this->usage( 'no import type: give --type=<label> or one of --' . implode( ', --', $labels ) );

        $engineOptions = array(
            'source' => isset( $options['source'] ) ? (string)$options['source'] : '',
            'dry_run' => !empty( $options['dry-run'] ),
            'apply_removals' => !empty( $options['apply-removals'] ),
            'paths' => !empty( $options['path'] ) ? (array)$options['path'] : array(),
            'limit' => !empty( $options['limit'] ) ? (int)$options['limit'] : 0,
            'batch' => !empty( $options['batch'] ) ? (int)$options['batch'] : 0,
            'restart' => !empty( $options['restart'] ),
            'pull' => empty( $options['no-pull'] ),
            'parent_node' => !empty( $options['parent-node'] ) ? (int)$options['parent-node'] : 0,
            'user' => isset( $options['user'] ) ? (string)$options['user'] : '',
        );
        $json = $this->json;
        $cli = $this;
        try
        {
            $engine = new \expStaticImportEngine( $type, $engineOptions, function ( $event, array $data ) use ( $json, $cli ) {
                if ( $json )
                    return;
                if ( $event === 'discover' )
                    $cli->output( sprintf( '%d pages in the source', $data['pages'] ) );
                else if ( $event === 'pages' )
                    $cli->output( sprintf( 'pages %d/%d: %d created, %d updated, %d unchanged, %d flagged, %d failed, %d duplicates',
                                           $data['done'], $data['of'], $data['counts']['created'], $data['counts']['updated'], $data['counts']['unchanged'],
                                           $data['counts']['flagged'], $data['counts']['failed'], $data['counts']['duplicates'] ) );
                else if ( $event === 'assets' )
                    $cli->output( sprintf( 'media %d/%d', $data['done'], $data['of'] ) );
            } );
            $report = $engine->run();
        }
        catch ( \Throwable $e )
        {
            if ( $this->json )
                $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => false, 'error' => $e->getMessage() ) ) );
            else
                $this->error( 'FAIL: ' . $e->getMessage() );
            $this->shutdown( 1 );
            return 1;
        }
        $code = $report['counts']['failed'] > 0 ? 1 : 0;
        if ( $this->json )
            $this->output( json_encode( array_merge( array( 'ok' => $code === 0 ), $report ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            $this->output();
            foreach ( $report['counts'] as $name => $count )
                $this->output( sprintf( '  %-11s %d', $name, $count ) );
            foreach ( array_slice( $report['failed'], 0, 20 ) as $entry )
                $this->output( '  FAILED  ' . $entry['path'] . ': ' . $entry['reason'] );
            foreach ( array_slice( $report['flagged'], 0, 20 ) as $entry )
                $this->output( '  FLAGGED ' . $entry['path'] . ': ' . $entry['reason'] );
            if ( !empty( $report['report_files'] ) )
                $this->output( '  report: ' . implode( ', ', $report['report_files'] ) );
            $summary = ( $report['dry_run'] ? 'dry run, nothing written: ' : '' ) . $report['counts']['pages'] . ' pages in ' . $report['seconds'] . ' s';
            if ( $code === 0 )
                $this->output( 'PASS: ' . $summary );
            else
                $this->error( 'FAIL: ' . $report['counts']['failed'] . ' pages failed (' . $summary . ')' );
        }
        $this->shutdown( $code );
        return $code;
    }

    protected function usage( $text )
    {
        if ( $this->json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => false, 'error' => $text ) ) );
        else
            $this->error( 'FAIL: ' . $text );
        $this->shutdown( 2 );
        return 2;
    }
}

}
