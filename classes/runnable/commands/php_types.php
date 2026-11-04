<?php
/**
 * exp:static-import:types (ext:exp_static_html_imports:types): the import types.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

namespace Exponential\Command\Extension\ExpStaticHtmlImports
{

class Types extends \Exponential\Runnable\Command
{
    public function run()
    {
        $this->script( array(
            'description' => "The import types (statichtmlimport.ini [ImportTypes]), their classes, where their rules come from\n"
                . "(ini, yaml, php) and their source.\n\n  --format=json    as JSON (schema exp-static-import/1)",
            'use-session' => false, 'use-modules' => false, 'use-extensions' => true
        ) );
        $options = $this->startup( '[format:]', '', array( 'format' => 'text or json' ) );
        $json = isset( $options['format'] ) && $options['format'] === 'json';
        $types = array();
        foreach ( \expStaticImportRegistry::types() as $label => $class )
        {
            $rules = \expStaticImportRules::load( $label );
            $types[$label] = array( 'class' => $class, 'available' => class_exists( $class ),
                                    'rules' => (string)$rules->get( 'RulesFormat', 'ini' ),
                                    'source' => (string)$rules->get( 'SourceRepository', (string)$rules->get( 'SourceLocation', '' ) ) );
        }
        if ( $json )
            $this->output( json_encode( array( 'schema' => \expStaticImportEngine::SCHEMA, 'ok' => true, 'types' => $types ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        else
            foreach ( $types as $label => $type )
                $this->output( sprintf( '--%-15s %-36s rules: %-4s %s%s', $label, $type['class'], $type['rules'], $type['source'], $type['available'] ? '' : '  (class missing)' ) );
        $this->shutdown( 0 );
        return 0;
    }
}

}
