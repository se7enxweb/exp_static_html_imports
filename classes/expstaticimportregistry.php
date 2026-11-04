<?php
/**
 * File containing the expStaticImportRegistry class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The import types: statichtmlimport.ini [ImportTypes] TypeList[<label>]=<class implementing
 * expStaticImportTypeInterface>. An extension adds a type with its own statichtmlimport.ini.append.php; exp_doc adds
 * nothing here, it only gives --doc-html the documentation site's defaults.
 */
class expStaticImportRegistry
{
    /**
     * @return array label => class
     */
    public static function types()
    {
        $ini = eZINI::instance( 'statichtmlimport.ini' );
        $types = $ini->hasVariable( 'ImportTypes', 'TypeList' ) ? (array)$ini->variable( 'ImportTypes', 'TypeList' ) : array();
        $out = array();
        foreach ( $types as $label => $class )
            if ( is_string( $label ) && $label !== '' && is_string( $class ) && $class !== '' )
                $out[$label] = $class;
        ksort( $out );
        return $out;
    }

    /**
     * @param string $label
     * @return expStaticImportTypeInterface
     * @throws RuntimeException for an unknown type
     */
    public static function create( $label )
    {
        $label = ltrim( (string)$label, '-' );
        $types = self::types();
        if ( !isset( $types[$label] ) )
            throw new RuntimeException( "unknown import type '$label' (known: " . implode( ', ', array_keys( $types ) ) . ')' );
        $class = $types[$label];
        if ( !class_exists( $class ) )
            throw new RuntimeException( "the class $class of the import type '$label' does not exist" );
        $type = new $class();
        if ( !$type instanceof expStaticImportTypeInterface )
            throw new RuntimeException( "the class $class does not implement expStaticImportTypeInterface" );
        $type->configure( expStaticImportRules::load( $label ) );
        return $type;
    }
}
