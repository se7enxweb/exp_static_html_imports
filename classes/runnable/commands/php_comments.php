<?php
/**
 * exp:static-import:comments (ext:exp_static_html_imports:comments): the no-data-loss audit of the imported comments.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Comments extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "The no-data-loss audit of the comments: for every imported page, each comment of its source (with its\n"
                . "replies) must be an object of the comment class, below its page or the comment it answers, with the same\n"
                . "original markup, its author and its date. Read-only. Exit code 1 while anything is missing.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --path=<pattern>   only these source paths (shell pattern, may be repeated)\n"
                . "  --details=<n>      list up to n pages with what they miss (default 20)\n"
                . "  --trash-replaced   on every page that misses nothing, move the objects its comments replaced (a class\n"
                . "                     change, remote id <id>-was-<class>) to the trash, each only when its subtree is all replaced\n"
                . "  --format=json      the whole report as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][path:*][details:][trash-replaced][format:]', '', array( 'type' => 'The import type', 'path' => 'Only these paths',
                                    'details' => 'Pages listed', 'trash-replaced' => 'Trash the replaced objects', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $result = $engine->commentsAudit( !empty( $options['path'] ) ? (array)$options['path'] : array(), !empty( $options['trash-replaced'] ) );
        $ok = !$result['missing'];
        if ( isset( $options['format'] ) && $options['format'] === 'json' )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => $ok, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( array_slice( $result['by_page'], 0, isset( $options['details'] ) ? (int)$options['details'] : 20, true ) as $path => $lines )
            {
                $this->output( $path );
                foreach ( $lines as $line )
                    $this->output( "  $line" );
            }
            $this->output( sprintf( '%s %d pages with comments, %d comments in the source, %d stored, %d missing, %d replaced objects left, %d trashed', $ok ? 'PASS' : 'FAIL',
                                    $result['pages'], $result['comments'], $result['stored'], $result['missing'], $result['replaced_left'], $result['trashed'] ) );
        }
        $this->shutdown( $ok ? 0 : 1 );
        return $ok ? 0 : 1;
    }
}

}
