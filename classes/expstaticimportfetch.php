<?php
/**
 * File containing the expStaticImportFetch class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The fetch functions of the module staticimport.
 */
class expStaticImportFetch
{
    /**
     * @return array array( 'result' => label => status ), only the types that have run
     */
    public static function status()
    {
        $out = array();
        foreach ( array_keys( expStaticImportRegistry::types() ) as $label )
        {
            $status = expStaticImportEngine::status( $label );
            if ( $status['pages'] || $status['runs'] || $status['run_in_progress'] )
                $out[$label] = $status;
        }
        return array( 'result' => $out );
    }
}
