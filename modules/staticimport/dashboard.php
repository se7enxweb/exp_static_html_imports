<?php
/**
 * staticimport/dashboard: every import type with its state, its last runs and a run in progress.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$Module = $Params['Module'];
$types = array();
foreach ( expStaticImportRegistry::types() as $label => $class )
{
    $rules = expStaticImportRules::load( $label );
    $types[$label] = array_merge( expStaticImportEngine::status( $label ),
                                  array( 'label' => $label, 'class' => $class,
                                         'source' => (string)$rules->get( 'SourceRepository', (string)$rules->get( 'SourceLocation', '' ) ) ) );
}
$tpl = eZTemplate::factory();
$tpl->setVariable( 'types', $types );
$tpl->setVariable( 'can_run', eZUser::currentUser()->hasAccessTo( 'staticimport', 'run' ) );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:staticimport/dashboard.tpl' );
$Result['path'] = array( array( 'url' => false, 'text' => ezpI18n::tr( 'extension/exp_static_html_imports', 'Static site import' ) ) );
