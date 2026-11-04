<?php
/**
 * Fetch functions of the static site import: fetch( 'staticimport', 'status' ) gives the state of every type that has
 * run (pages by status, media, a run in progress, the last runs), for the dashboard block.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$FunctionList = array();
$FunctionList['status'] = array( 'name' => 'status',
                                 'operation_types' => array( 'read' ),
                                 'call_method' => array( 'class' => 'expStaticImportFetch', 'method' => 'status' ),
                                 'parameter_type' => 'standard',
                                 'parameters' => array() );
