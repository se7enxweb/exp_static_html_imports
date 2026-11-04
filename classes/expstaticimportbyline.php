<?php
/**
 * File containing the expStaticImportByline class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Byline extraction: the authorship lines a static page carries in its body ("Written by: A (14/09/2010 10:14 am)",
 * "Last updated by: B (20/01/2012 2:12 pm)", "Maintainers: C, D") are read into fields of their own and removed from
 * the body, so the content keeps only the text and the designs draw the byline from attributes.
 *
 * Rules (any HTML type):
 *   BylineSelectors[]           the elements holding the byline lines; removed from the body after reading
 *   BylineFields[<field>]=<label>   a line "<label>: ..." fills <field> (e.g. written, updated, maintainers)
 *   BylineListFields[]          fields whose value is a comma separated list of names (no date)
 *   BylineDateFormats[]         date formats of the "(...)" after a name, PHP DateTime::createFromFormat()
 *   BylineTimezone              the timezone the source wrote its dates in (default UTC)
 *
 * A line is "<label>: <name>" with an optional "(<date>)"; the label is the text of a <label> element or the text
 * before the first colon. The result is array( field => array( 'name' => ..., 'time' => int|null ) ) and, for list
 * fields, array( field => array( 'names' => array( ... ) ) ). Lines that do not parse are returned in 'failures'.
 */
class expStaticImportByline
{
    /** @var expStaticImportRules */
    protected $rules;

    /**
     * @param expStaticImportRules $rules
     */
    public function __construct( expStaticImportRules $rules )
    {
        $this->rules = $rules;
    }

    /**
     * Reads the byline of a page body and removes its elements from the body.
     *
     * @param DOMDocument $doc
     * @param DOMElement $body
     * @return array( 'fields' => array, 'failures' => array of the lines that could not be read )
     */
    public function extract( DOMDocument $doc, DOMElement $body )
    {
        $result = array( 'fields' => array(), 'failures' => array() );
        $labels = $this->rules->getHash( 'BylineFields' );
        if ( !$labels )
            return $result;
        foreach ( $this->rules->getList( 'BylineSelectors' ) as $selector )
            foreach ( iterator_to_array( expStaticImportDom::query( $doc, $selector, $body ) ) as $container )
            {
                if ( !$container->parentNode )
                    continue;
                foreach ( $this->lines( $container ) as $line )
                {
                    $parsed = $this->parseLine( $line['label'], $line['value'] );
                    if ( $parsed === null )
                    {
                        if ( trim( $line['label'] . $line['value'] ) !== '' )
                            $result['failures'][] = trim( $line['label'] . ': ' . $line['value'] );
                        continue;
                    }
                    $result['fields'][$parsed['field']] = $parsed['value'];
                }
                $container->parentNode->removeChild( $container );
            }
        return $result;
    }

    /**
     * The "<label>: <value>" lines of a byline element: one per <p>, or per line of its text when it has none.
     *
     * @param DOMElement $container
     * @return array of array( label, value )
     */
    protected function lines( DOMElement $container )
    {
        $lines = array();
        // a line break ends a line
        foreach ( iterator_to_array( $container->getElementsByTagName( 'br' ) ) as $br )
            $br->parentNode->replaceChild( $container->ownerDocument->createTextNode( "\n" ), $br );
        $paragraphs = $container->getElementsByTagName( 'p' );
        $nodes = $paragraphs->length ? iterator_to_array( $paragraphs ) : array( $container );
        foreach ( $nodes as $node )
        {
            $labelNode = $node->getElementsByTagName( 'label' )->item( 0 );
            if ( $labelNode )
            {
                $label = expStaticImportDom::text( $labelNode );
                $value = trim( str_replace( "\xc2\xa0", ' ', substr( $node->textContent, strpos( $node->textContent, $labelNode->textContent ) + strlen( $labelNode->textContent ) ) ) );
                $lines[] = array( 'label' => rtrim( $label, ': ' ), 'value' => trim( preg_replace( '/\s+/u', ' ', $value ) ) );
                continue;
            }
            foreach ( preg_split( '/\R/u', str_replace( "\xc2\xa0", ' ', $node->textContent ) ) as $text )
            {
                $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
                if ( $text === '' )
                    continue;
                $colon = strpos( $text, ':' );
                $lines[] = $colon === false ? array( 'label' => '', 'value' => $text )
                                            : array( 'label' => trim( substr( $text, 0, $colon ) ), 'value' => trim( substr( $text, $colon + 1 ) ) );
            }
        }
        return $lines;
    }

    /**
     * @param string $label
     * @param string $value
     * @return array|null array( 'field' => ..., 'value' => ... ), null when the label is none of BylineFields[] or the
     *                    value does not parse
     */
    public function parseLine( $label, $value )
    {
        $field = null;
        foreach ( $this->rules->getHash( 'BylineFields' ) as $name => $text )
            if ( strcasecmp( trim( $label ), trim( $text ) ) === 0 )
            {
                $field = $name;
                break;
            }
        if ( $field === null )
            return null;
        $value = trim( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
        if ( in_array( $field, $this->rules->getList( 'BylineListFields' ), true ) )
        {
            $names = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
            return $names ? array( 'field' => $field, 'value' => array( 'names' => $names ) ) : null;
        }
        $time = null;
        if ( preg_match( '/^(.*?)\s*\(([^()]*)\)\s*$/u', $value, $m ) )
        {
            $time = $this->parseDate( $m[2] );
            if ( $time === null )
                return null;
            $value = $m[1];
        }
        $name = trim( $value );
        if ( $name === '' )
            return null;
        return array( 'field' => $field, 'value' => array( 'name' => $name, 'time' => $time ) );
    }

    /**
     * @param string $text
     * @return int|null the timestamp of a date in one of BylineDateFormats[], in the source's timezone
     */
    public function parseDate( $text )
    {
        $text = trim( $text );
        $zone = new DateTimeZone( (string)$this->rules->get( 'BylineTimezone', 'UTC' ) );
        foreach ( $this->rules->getList( 'BylineDateFormats' ) ?: array( 'd/m/Y g:i a' ) as $format )
        {
            $date = DateTime::createFromFormat( '!' . $format, $text, $zone );
            $errors = DateTime::getLastErrors();
            if ( $date && ( !$errors || ( !$errors['warning_count'] && !$errors['error_count'] ) ) )
                return $date->getTimestamp();
        }
        return null;
    }

    /**
     * @param string $name
     * @return string the name as it identifies a person: whitespace collapsed, lower case
     */
    public static function normalizeName( $name )
    {
        return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string)$name ) ), 'UTF-8' );
    }
}
