<?php
/**
 * File containing the expStaticImportRules class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The rules of one import type: which source paths are pages, which class each becomes, which parts of the HTML are
 * the title and the body, what is left out.
 *
 * Three formats, INI by default (statichtmlimport.ini [Type_<label>], overridable per siteaccess and in
 * settings/override like any setting):
 *   - INI:  the group [Type_<label>] itself
 *   - YAML: RulesFormat=yaml and RulesFile=<path to a .yml file>, read with Symfony YAML when it is installed
 *   - PHP:  RulesFormat=php and RulesClass=<a class implementing expStaticImportRulesProvider>
 * Whatever the format, the result is one flat array of settings; values the format leaves out fall back to the INI
 * group, so a YAML or PHP file only needs what it changes.
 */
class expStaticImportRules
{
    /** @var string */
    protected $type;

    /** @var array */
    protected $values = array();

    /**
     * @param string $type the import type label
     * @param array $values the rules
     */
    public function __construct( $type, array $values = array() )
    {
        $this->type = (string)$type;
        $this->values = $values;
    }

    /**
     * The rules of a type, from statichtmlimport.ini and, when it names one, a YAML file or a PHP rule class.
     *
     * @param string $type
     * @param eZINI|null $ini
     * @return expStaticImportRules
     */
    public static function load( $type, $ini = null )
    {
        $ini = $ini ?: eZINI::instance( 'statichtmlimport.ini' );
        $group = 'Type_' . $type;
        $values = $ini->hasGroup( $group ) ? $ini->group( $group ) : array();
        $format = isset( $values['RulesFormat'] ) ? strtolower( trim( (string)$values['RulesFormat'] ) ) : 'ini';
        if ( $format === 'yaml' && !empty( $values['RulesFile'] ) )
            $values = array_merge( $values, self::fromYaml( (string)$values['RulesFile'] ) );
        else if ( $format === 'php' && !empty( $values['RulesClass'] ) )
            $values = array_merge( $values, self::fromClass( (string)$values['RulesClass'] ) );
        return new self( $type, $values );
    }

    /**
     * @param string $file
     * @return array
     */
    public static function fromYaml( $file )
    {
        if ( !is_file( $file ) )
            throw new RuntimeException( "The rules file $file does not exist" );
        if ( class_exists( 'Symfony\Component\Yaml\Yaml' ) )
            $data = \Symfony\Component\Yaml\Yaml::parseFile( $file );
        else if ( function_exists( 'yaml_parse_file' ) )
            $data = yaml_parse_file( $file );
        else
            throw new RuntimeException( 'Reading YAML rules needs symfony/yaml or the yaml extension' );
        return is_array( $data ) ? $data : array();
    }

    /**
     * @param string $class
     * @return array
     */
    public static function fromClass( $class )
    {
        if ( !class_exists( $class ) )
            throw new RuntimeException( "The rules class $class does not exist" );
        $provider = new $class();
        if ( !$provider instanceof expStaticImportRulesProvider )
            throw new RuntimeException( "The rules class $class does not implement expStaticImportRulesProvider" );
        return (array)$provider->rules();
    }

    /**
     * @return string
     */
    public function type()
    {
        return $this->type;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get( $name, $default = null )
    {
        return array_key_exists( $name, $this->values ) ? $this->values[$name] : $default;
    }

    /**
     * A list setting (Name[]=...), always an array; an INI list cleared with Name[] and no entries is empty.
     *
     * @param string $name
     * @return array
     */
    public function getList( $name )
    {
        $value = $this->get( $name, array() );
        if ( !is_array( $value ) )
            $value = $value === '' || $value === null ? array() : array( $value );
        return array_values( array_filter( $value, function ( $entry ) { return $entry !== '' && $entry !== null; } ) );
    }

    /**
     * A hash setting (Name[key]=value).
     *
     * @param string $name
     * @return array
     */
    public function getHash( $name )
    {
        $value = $this->get( $name, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * @param string $name
     * @param bool $default
     * @return bool
     */
    public function enabled( $name, $default = false )
    {
        $value = $this->get( $name, null );
        if ( $value === null )
            return $default;
        return in_array( strtolower( (string)$value ), array( 'enabled', 'true', '1', 'yes', 'on' ), true );
    }

    /**
     * @return array
     */
    public function all()
    {
        return $this->values;
    }
}
