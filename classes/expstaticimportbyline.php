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
    /** @var int|null when the page was saved (BylineSavedAtPattern), the day relative dates count from */
    protected $savedAt = null;

    public function extract( DOMDocument $doc, DOMElement $body )
    {
        $result = array( 'fields' => array(), 'failures' => array() );
        // BylineSavedAtPattern: a regular expression on the page's HTML comments whose first group is the date the
        // page was saved (an HTTrack mirror: "Mirrored from ... by HTTrack Website Copier/3.x [...], Tue, 21 Oct 2014
        // 08:54:32 GMT"), so "yesterday at 7:42 PM" has a day
        $this->savedAt = null;
        if ( ( $pattern = (string)$this->rules->get( 'BylineSavedAtPattern', '' ) ) !== '' )
            foreach ( ( new DOMXPath( $doc ) )->query( '//comment()' ) as $comment )
                if ( @preg_match( $pattern, $comment->nodeValue, $m ) && isset( $m[1] ) && ( $time = strtotime( $m[1] ) ) )
                {
                    $this->savedAt = $time;
                    break;
                }
        $labels = $this->rules->getHash( 'BylineFields' );
        foreach ( $labels ? $this->rules->getList( 'BylineSelectors' ) : array() as $selector )
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
        $this->sentences( $doc, $result );
        $this->commenters( $doc, $result );
        return $result;
    }

    /**
     * Bylines written as a sentence (Confluence: "Created by A, last modified by B on Oct 28, 2016"), anywhere in the
     * page: the text of each BylineSentenceSelectors[] element is matched against BylineSentences[], regular
     * expressions with the named groups written, written_at, updated and updated_at. A sentence with the date of the
     * last change and no name for it ("Created by A, last modified on <date>", "Created and last modified by A on
     * <date>") credits the change to the writer. An element whose title attribute is a date (a more precise one, such
     * as "May 27, 2014 07:44") counts with that date. The fields a labelled line filled are not replaced. A sentence
     * that no expression reads is a failure, listed, never dropped. The elements leave the page.
     *
     * @param DOMDocument $doc
     * @param array $result
     */
    protected function sentences( DOMDocument $doc, array &$result )
    {
        $patterns = $this->rules->getList( 'BylineSentences' );
        foreach ( $this->rules->getList( 'BylineSentenceSelectors' ) as $selector )
            foreach ( iterator_to_array( expStaticImportDom::query( $doc, $selector ) ) as $element )
            {
                if ( !$element instanceof DOMElement || !$element->parentNode )
                    continue;
                $xpath = new DOMXPath( $doc );
                foreach ( iterator_to_array( $xpath->query( './/*[@title]', $element ) ) as $dated )
                    if ( $this->parseDate( $dated->getAttribute( 'title' ) ) !== null )
                        $dated->nodeValue = $dated->getAttribute( 'title' );
                $text = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $element->textContent ) ) );
                $text = preg_replace( '/\s+,/u', ',', $text );
                $element->parentNode->removeChild( $element );
                if ( $text === '' )
                    continue;
                $read = false;
                foreach ( $patterns as $pattern )
                {
                    if ( !@preg_match( $pattern, $text, $m ) )
                        continue;
                    $values = array();
                    foreach ( array( 'written', 'updated' ) as $field )
                    {
                        $name = isset( $m[$field] ) ? $this->canonicalName( $m[$field] ) : '';
                        $time = isset( $m[$field . '_at'] ) && trim( $m[$field . '_at'] ) !== '' ? $this->parseDate( $m[$field . '_at'] ) : null;
                        if ( $name !== '' || $time !== null )
                            $values[$field] = array( 'name' => $name, 'time' => $time );
                    }
                    if ( isset( $values['updated'] ) && $values['updated']['name'] === '' && isset( $values['written'] ) )
                        $values['updated']['name'] = $values['written']['name'];
                    $ok = (bool)$values;
                    foreach ( $values as $value )
                        if ( $value['name'] === '' )
                            $ok = false;
                    // a date group that matched text but no date format is a sentence not read
                    foreach ( array( 'written_at', 'updated_at' ) as $group )
                        if ( isset( $m[$group] ) && trim( $m[$group] ) !== '' && $this->parseDate( $m[$group] ) === null )
                            $ok = false;
                    if ( !$ok )
                        continue;
                    foreach ( $values as $field => $value )
                        if ( !isset( $result['fields'][$field] ) )
                            $result['fields'][$field] = $value;
                    $read = true;
                    break;
                }
                if ( !$read )
                    $result['failures'][] = $text;
            }
    }

    /**
     * The authors of a page's comments (BylineCommentAuthorSelectors[], the whole page) as the list field
     * "commenters", in order, each name once.
     *
     * @param DOMDocument $doc
     * @param array $result
     */
    protected function commenters( DOMDocument $doc, array &$result )
    {
        $names = array();
        foreach ( $this->rules->getList( 'BylineCommentAuthorSelectors' ) as $selector )
            foreach ( expStaticImportDom::query( $doc, $selector ) as $element )
            {
                // a name as written, never with the character references of a twice-encoded source (Kara&#347;)
                $name = $this->canonicalName( $element->textContent );
                if ( $name !== '' && !isset( $names[self::normalizeName( $name )] ) )
                    $names[self::normalizeName( $name )] = $name;
            }
        if ( $names )
            $result['fields']['commenters'] = array( 'names' => array_values( $names ) );
    }

    /**
     * Every person a byline names, each once, in the order of the fields (written, updated, maintainers, commenters).
     *
     * @param array $fields the 'fields' of extract()
     * @return array of names
     */
    public static function people( array $fields )
    {
        $people = array();
        foreach ( $fields as $value )
            foreach ( isset( $value['names'] ) ? (array)$value['names'] : ( isset( $value['name'] ) ? array( $value['name'] ) : array() ) as $name )
                if ( trim( (string)$name ) !== '' && !isset( $people[self::normalizeName( $name )] ) )
                    $people[self::normalizeName( $name )] = trim( preg_replace( '/\s+/u', ' ', (string)$name ) );
        return array_values( $people );
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
            $names = array_values( array_filter( array_map( array( $this, 'canonicalName' ), explode( ',', $value ) ), 'strlen' ) );
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
        else
        {
            // a date or time glued to the name without its brackets, or with one of them missing
            // ("Graham Brookins (14/08/2024 9:50 pm"): the date is read, and never becomes part of the name
            list( $name, $date ) = self::splitDateSuffix( $value );
            if ( $date !== null )
            {
                $time = $this->parseDate( $date );
                if ( $time === null )
                    return null;
                $value = $name;
            }
        }
        $name = $this->canonicalName( $value );
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
        // a relative date ("yesterday at 7:42 PM", "today at 9:01 AM") counts from the day the page was saved
        if ( $this->savedAt && preg_match( '/^(yesterday|today) at (\d{1,2}:\d{2}\s*[ap]m)$/i', $text, $m ) )
        {
            $day = ( new DateTime( '@' . $this->savedAt ) )->setTimezone( $zone );
            if ( strtolower( $m[1] ) === 'yesterday' )
                $day->modify( '-1 day' );
            $date = DateTime::createFromFormat( '!Y-m-d g:i a', $day->format( 'Y-m-d' ) . ' ' . strtolower( preg_replace( '/\s+/', ' ', $m[2] ) ), $zone )
                 ?: DateTime::createFromFormat( '!Y-m-d g:ia', $day->format( 'Y-m-d' ) . ' ' . strtolower( preg_replace( '/\s+/', '', $m[2] ) ), $zone );
            return $date ? $date->getTimestamp() : null;
        }
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

    /** a date or a time a name may carry at its end: 14/08/2024, 2024-08-14, Oct 28, 2016, 28 Oct 2016, 9:50 pm, yesterday */
    const DATE_TOKEN = '(?:\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?)?|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}|\d{1,2}\.?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?,?\s+\d{4}|\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]\.?\s?m\.?)?|yesterday|today)';

    /**
     * Splits a date or time glued to the end of a name off it: "Graham Brookins (14/08/2024 9:50 pm",
     * "Ann 14/08/2024 9:50 pm", "Ann, Oct 28, 2016 at 7:42 PM", "Ann [2024-08-14]", "Ann on Oct 28, 2016".
     *
     * @param string $text
     * @return array( name, the date text or null when there is none )
     */
    public static function splitDateSuffix( $text )
    {
        $text = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', (string)$text ) ) );
        $token = self::DATE_TOKEN;
        // the date starts the text, or follows the name after a space, a comma, a dash or an opening bracket (never
        // glued to a word: "andre1", "user12:30" stay names)
        if ( preg_match( '/^(?:(?<name>.*?)[\s,;\-\x{2013}\x{2014}]*[\s(\[]\s*)?(?:(?:on|at)\s+)?(?<date>' . $token . '(?:[\s,]+(?:at\s+)?' . $token . ')*)\s*[)\]]?\s*$/iu', $text, $m ) )
            return array( isset( $m['name'] ) ? trim( preg_replace( '/[\s,;(\[\-\x{2013}\x{2014}]+$/u', '', $m['name'] ) ) : '', trim( $m['date'] ) );
        return array( $text, null );
    }

    /**
     * A person's name as one contributor: the character references of a twice-encoded source decoded (Kara&#347;),
     * a glued date or time removed, whitespace collapsed, and a known variant replaced by its person's name
     * (ContributorAliases[<variant>]=<name> in the type's rules, and [ContributorAliases] Map[<variant>]=<name> of
     * statichtmlimport.ini; variants match without case, dots and extra spaces: "Graham B." is "graham b").
     *
     * @param string $name
     * @return string
     */
    public function canonicalName( $name )
    {
        $name = expStaticImportDom::decodeText( trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', (string)$name ) ) ) );
        list( $name ) = self::splitDateSuffix( $name );
        $name = trim( preg_replace( '/[\s(\[]+$/u', '', $name ) );
        $aliases = $this->aliases();
        $key = self::aliasKey( $name );
        return isset( $aliases[$key] ) ? $aliases[$key] : $name;
    }

    /**
     * @param string $name
     * @return string the key a name variant is looked up by: lower case, dots as spaces, whitespace collapsed
     */
    public static function aliasKey( $name )
    {
        return trim( preg_replace( '/[\s.]+/u', ' ', mb_strtolower( (string)$name, 'UTF-8' ) ) );
    }

    /** @var array|null alias key => the person's name */
    protected $aliases = null;

    /**
     * @return array alias key => the person's name, from the rules (ContributorAliases[]) and statichtmlimport.ini
     *               ([ContributorAliases] Map[]); the rules win
     */
    public function aliases()
    {
        if ( $this->aliases !== null )
            return $this->aliases;
        $map = array();
        if ( class_exists( 'eZINI', false ) )
        {
            $ini = eZINI::instance( 'statichtmlimport.ini' );
            if ( $ini->hasVariable( 'ContributorAliases', 'Map' ) )
                $map = (array)$ini->variable( 'ContributorAliases', 'Map' );
        }
        $map = array_merge( $map, $this->rules->getHash( 'ContributorAliases' ) );
        $this->aliases = array();
        foreach ( $map as $variant => $person )
            if ( trim( (string)$variant ) !== '' && trim( (string)$person ) !== '' )
                $this->aliases[self::aliasKey( $variant )] = trim( preg_replace( '/\s+/u', ' ', (string)$person ) );
        return $this->aliases;
    }
}
