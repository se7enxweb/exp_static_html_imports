<?php
/**
 * exp:static-import:links (ext:exp_static_html_imports:links): rewrites the links of the stored bodies of an import
 * into node links, in place, in batches, resumable.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Links extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "Rewrite the links of the bodies an import stored: every link whose address is an imported page or media\n"
                . "file becomes a node link (eznode://<node>, with its anchor), which the output turns into the node's current\n"
                . "address on every render. The link resolver reads relative and root-relative paths, page ids, the site's own\n"
                . "full URLs (LinkHosts[], LinkHostsIfImported[]) and a mirror's original addresses (SourceAddressPattern).\n"
                . "The current version is changed in place (no new version, no editor change); the edit detection's baseline\n"
                . "moves with it through a state patch; only the changed pages' view caches are cleared. Batches in object id\n"
                . "order, the position saved after each (links-rewrite.json), so a second call resumes. Every link it cannot\n"
                . "resolve is listed per page in links-unresolved.jsonl in the work folder, its address left as it was.\n\n"
                . "  --type=<label>     the import type (e.g. doc-html)\n"
                . "  --batch=<n>        objects per batch (default 200)\n"
                . "  --limit=<n>        stop after n objects (resume later)\n"
                . "  --restart          from the first object again\n"
                . "  --object=<id>      only this object (may be repeated; the position is not saved)\n"
                . "  --settle           after the other console steps: move the baseline of each page this step changed whose\n"
                . "                     baseline is still the one before its change (links-baselines.jsonl)\n"
                . "  --dry-run          count, change nothing\n"
                . "  --format=json      as JSON",
            'use-session' => false, 'use-modules' => true, 'use-extensions' => true
        ) );
        $options = $this->startup( '[type:][batch:][limit:][restart][object:*][settle][dry-run][format:]', '',
                                   array( 'type' => 'The import type', 'batch' => 'Objects per batch', 'limit' => 'Stop after n objects', 'restart' => 'From the first object',
                                          'object' => 'Only these objects', 'settle' => 'Settle the baselines', 'dry-run' => 'Change nothing', 'format' => 'text or json' ) );
        $type = !empty( $options['type'] ) ? ltrim( (string)$options['type'], '-' ) : '';
        if ( $type === '' )
        {
            $this->output( 'FAIL no import type: give --type=<label>' );
            $this->shutdown( 2 );
            return 2;
        }
        $engine = new \expStaticImportEngine( $type, array( 'pull' => false ) );
        $rewriter = new \expStaticImportLinkRewriter( $engine, array(
            'dry_run' => !empty( $options['dry-run'] ), 'batch' => !empty( $options['batch'] ) ? (int)$options['batch'] : 200,
            'limit' => !empty( $options['limit'] ) ? (int)$options['limit'] : 0, 'restart' => !empty( $options['restart'] ),
            'objects' => !empty( $options['object'] ) ? array_map( 'intval', (array)$options['object'] ) : array() ) );
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        if ( !empty( $options['settle'] ) )
        {
            $result = $rewriter->settle();
            if ( $json )
                $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            else
                $this->output( sprintf( 'PASS %d pages: %d baselines settled, %d already current, %d changed since (left to the edit detection%s), %d unknown',
                                        $result['pages'],
                                        $result['settled'], $result['already'], $result['changed'],
                                        $result['changed_objects'] ? ': objects ' . implode( ',', array_slice( $result['changed_objects'], 0, 20 ) ) . ( count( $result['changed_objects'] ) > 20 ? ' ...' : '' ) : '',
                                        $result['unknown'] ) );
            $this->shutdown( 0 );
            return 0;
        }
        $self = $this;
        $totals = $rewriter->run( $json ? null : function ( $done, $last, $totals ) use ( $self ) {
            $self->output( sprintf( '  %6d objects (up to id %d): %d links rewritten, %d unresolved', $done, $last, $totals['rewritten'], $totals['unresolved'] ) );
        } );
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'type' => $type ) + $totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            foreach ( array_slice( $totals['unresolved_forms'], 0, 15, true ) as $form => $count )
                $this->output( sprintf( '  unresolved %6d  %s', $count, $form ) );
            foreach ( array_slice( $totals['unresolved_reasons'], 0, 10, true ) as $reason => $count )
                $this->output( sprintf( '  reason     %6d  %s', $count, $reason ) );
            $this->output( sprintf( 'PASS %d objects, %d pages changed: %d links, %d node links (%d rewritten now, %d retargeted, %d to nodes that do not exist), %d anchors, %d external, %d mailto, %d ignored, %d unresolved on %d pages; %d baselines moved, %d edited pages changed%s',
                                    $totals['objects'], $totals['pages_changed'], $totals['links'], $totals['node_links'], $totals['rewritten'], $totals['retargeted'], $totals['node_links_dead'],
                                    $totals['anchors'], $totals['external'], $totals['mailto'], $totals['ignored'], $totals['unresolved'], $totals['pages_with_unresolved'],
                                    $totals['baselines_moved'], $totals['edited_pages_changed'], $totals['finished'] ? '' : ' (not finished: run again to resume)' ) );
        }
        $this->shutdown( 0 );
        return 0;
    }
}

}
