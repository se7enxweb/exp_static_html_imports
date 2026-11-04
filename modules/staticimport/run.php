<?php
/**
 * staticimport/run: starts a run of an import type as a background job (expqueue), or runs it here when the job
 * queue is not installed; then goes to the dashboard. A form posts here and redirects; nothing is read from GET.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$Module = $Params['Module'];
if ( !$Module->isCurrentAction( 'Start' ) )
    return $Module->redirectToView( 'dashboard' );
$type = (string)$Module->actionParameter( 'Type' );
if ( !isset( expStaticImportRegistry::types()[$type] ) )
    return $Module->redirectToView( 'dashboard' );
$options = array( 'dry_run' => (bool)$Module->actionParameter( 'DryRun' ), 'apply_removals' => (bool)$Module->actionParameter( 'ApplyRemovals' ) );
if ( class_exists( 'expQueue' ) && expQueue::installed() )
    expQueue::enqueue( expStaticImportJob::TYPE, array( 'type' => $type, 'options' => $options ),
                       array( 'idempotency_key' => 'staticimport:' . $type . ':' . gmdate( 'YmdHi' ) ) );
else
{
    $engine = new expStaticImportEngine( $type, $options );
    $engine->run();
}
return $Module->redirectToView( 'dashboard' );
