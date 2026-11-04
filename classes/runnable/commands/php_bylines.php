<?php
/**
 * exp:static-import:bylines (ext:exp_static_html_imports:bylines): the no-data-loss audit of the bylines, and the fill
 * of what is missing.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Bylines extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "The no-data-loss audit of the bylines: for every imported page, each person and date the source's byline\n"
                . "areas name (written by, last updated by, maintainers, Confluence's created and last modified by, comment\n"
                . "authors) is looked for in the page's attributes (written_by, written_at, last_updated_by, last_updated_at,\n"
                . "maintainers, contributors). Byline text no rule reads is listed as unknown, never dropped.\n"
                . "With --fill, what is missing is written into the published version (a role attribute only when empty, the\n"
                . "contributors list to the full set), with the object relations. The state file is never written, so it is\n"
                . "safe while a run of the type is going on. Exit code 1 when anything is still missing or unknown.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --path=<pattern>   only these source paths (shell pattern, may be repeated)\n"
                . "  --fill             write what is missing\n"
                . "  --details=<n>      list up to n pages with what they miss (default 20)\n"
                . "  --format=json      the whole report as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][path:*][fill][details:][format:]', '', array( 'type' => 'The import type', 'path' => 'Only these paths',
                                    'fill' => 'Write what is missing', 'details' => 'Pages listed', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->bylines( !empty( $options['path'] ) ? (array)$options['path'] : array(), !empty( $options['fill'] ) );
        $ok = !$result['missing'] && !$result['unknown'];
        if ( isset( $options['format'] ) && $options['format'] === 'json' )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => $ok, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            $limit = isset( $options['details'] ) ? (int)$options['details'] : 20;
            foreach ( array_slice( $result['by_page'], 0, $limit, true ) as $path => $line )
            {
                $this->output( "$path (object {$line['object_id']})" );
                foreach ( $line['missing'] as $missing )
                    $this->output( "  missing  $missing" );
                foreach ( $line['unknown'] as $unknown )
                    $this->output( "  unknown  $unknown" );
            }
            $this->output( sprintf( '%s %d pages, %d people, %d dates; %d missing, %d unknown byline texts%s', $ok ? 'PASS' : 'FAIL', $result['pages'],
                                    $result['people'], $result['dates'], $result['missing'], $result['unknown'],
                                    !empty( $options['fill'] ) ? ', ' . $result['filled'] . ' pages filled' : '' ) );
        }
        $this->shutdown( $ok ? 0 : 1 );
        return $ok ? 0 : 1;
    }
}

}
