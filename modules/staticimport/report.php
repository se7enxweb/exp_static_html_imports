<?php
/**
 * staticimport/report/<type>/<run id>: the Markdown report of one run, as text.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$Module = $Params['Module'];
$type = preg_replace( '/[^a-z0-9_-]/i', '', (string)$Params['Type'] );
$run = preg_replace( '/[^a-z0-9_-]/i', '', (string)$Params['RunID'] );
$file = expStaticImportEngine::workDir( $type ) . '/reports/' . $run . '.md';
if ( $type === '' || $run === '' || !is_file( $file ) )
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
$tpl = eZTemplate::factory();
$tpl->setVariable( 'type', $type );
$tpl->setVariable( 'run', $run );
$tpl->setVariable( 'report', (string)file_get_contents( $file ) );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:staticimport/report.tpl' );
$Result['path'] = array( array( 'url' => 'staticimport/dashboard', 'text' => ezpI18n::tr( 'extension/exp_static_html_imports', 'Static site import' ) ),
                         array( 'url' => false, 'text' => $type . ' ' . $run ) );
