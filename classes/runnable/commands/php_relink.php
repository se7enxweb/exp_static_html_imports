<?php
/**
 * exp:static-import:relink (ext:exp_static_html_imports:relink): writes again the pages of an earlier run whose links
 * to static paths can be resolved now, without a run.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Relink extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Write again the pages an earlier run imported whose links were left as static paths and can be resolved now\n"
                . "(the target was imported later): each such page is imported again from its source, as the links phase of a run\n"
                . "does. The state file is never written (a state patch tells the next run), so it is safe while a run is going\n"
                . "on. Lists the targets still unresolved, the most frequent first.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --path=<pattern>   only these source paths (shell pattern, may be repeated)\n"
                . "  --dry-run          count, change nothing\n"
                . "  --format=json      as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][path:*][dry-run][format:]', '', array( 'type' => 'The import type', 'path' => 'Only these paths',
                                    'dry-run' => 'Change nothing', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->relinkExisting( !empty( $options['path'] ) ? (array)$options['path'] : array(), !empty( $options['dry-run'] ) );
        if ( isset( $options['format'] ) && $options['format'] === 'json' )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( array_slice( $result['targets'], 0, 20, true ) as $target => $count )
                $this->output( sprintf( '  %5d  %s', $count, $target ) );
            $this->output( sprintf( 'PASS %d pages with static links, %d written again (%d links resolved now), %d links still unresolved, %d flagged, %d failed',
                                    $result['pages'], $result['rewritten'], $result['links'], $result['unresolved'], $result['flagged'], $result['failed'] ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
