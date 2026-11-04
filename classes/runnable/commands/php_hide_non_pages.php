<?php
/**
 * exp:static-import:hide-non-pages (ext:exp_static_html_imports:hide-non-pages): hides the nodes of imported pages that
 * are no pages by the type's rules today (error pages, files that are no HTML, the viewers of one media file).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class HideNonPages extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Hide the nodes of pages an earlier run imported that are no pages by the type's rules today: the export's\n"
                . "error pages (NotFoundTitlePatterns[]), files with a page suffix that are no HTML (SkipBinaryPages) and the\n"
                . "viewers of one image or file (AssetPages). Only the visibility of those nodes changes, never the state file,\n"
                . "so it is safe while a run of the type is going on; the next run records them and imports the viewers' media.\n"
                . "A node with children is left alone and listed. Running it again changes nothing.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --path=<pattern>   only these source paths (shell pattern, may be repeated)\n"
                . "  --dry-run          list them, hide nothing\n"
                . "  --format=json      as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][path:*][dry-run][format:]', '', array( 'type' => 'The import type', 'path' => 'Only these paths',
                                    'dry-run' => 'Hide nothing', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->hideNonPages( !empty( $options['path'] ) ? (array)$options['path'] : array(), !empty( $options['dry-run'] ) );
        if ( isset( $options['format'] ) && $options['format'] === 'json' )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( $result['hidden'] as $line )
                $this->output( ( !empty( $options['dry-run'] ) ? 'would hide' : 'hidden' ) . " node {$line['node_id']} {$line['name']} ({$line['why']}): {$line['path']}" );
            foreach ( $result['kept'] as $line )
                $this->output( "kept node {$line['node_id']} {$line['name']} ({$line['why']}, {$line['children']} children): {$line['path']}" );
            $this->output( sprintf( 'PASS %d checked, %d %s, %d hidden before, %d kept for their children', $result['checked'], count( $result['hidden'] ),
                                    !empty( $options['dry-run'] ) ? 'to hide' : 'hidden', $result['already_hidden'], count( $result['kept'] ) ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
