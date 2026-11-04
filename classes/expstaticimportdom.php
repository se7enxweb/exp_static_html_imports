<?php
/**
 * File containing the expStaticImportDom class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * DOM helpers for the HTML types: loading a page whatever its charset, a small CSS selector subset turned into XPath,
 * inner HTML, and path arithmetic for links between files.
 *
 * Selectors: tag, #id, .class, tag.class, tag#id, [attr], [attr=value], several of them joined by spaces (descendant)
 * or ">" (child), and alternatives separated by commas. That is all the rules of a static site need.
 */
class expStaticImportDom
{
    /**
     * @param string $html
     * @return DOMDocument
     */
    public static function load( $html )
    {
        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        if ( !preg_match( '/^\s*<\?xml/i', $html ) )
        {
            $charset = 'UTF-8';
            if ( preg_match( '/<meta[^>]+charset=["\']?([a-zA-Z0-9_-]+)/i', $html, $m ) )
                $charset = strtoupper( $m[1] );
            if ( $charset !== 'UTF-8' && function_exists( 'mb_convert_encoding' ) )
                $html = mb_convert_encoding( $html, 'UTF-8', $charset );
            $html = '<?xml encoding="UTF-8">' . $html;
        }
        $doc->loadHTML( $html, LIBXML_NONET | LIBXML_COMPACT | ( defined( 'LIBXML_HTML_NODEFDTD' ) ? LIBXML_HTML_NODEFDTD : 0 ) );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $doc;
    }

    /**
     * @param string $selector a CSS selector of the supported subset
     * @return string the XPath expression (relative to the document root)
     */
    public static function toXPath( $selector )
    {
        $alternatives = array();
        foreach ( explode( ',', $selector ) as $alternative )
        {
            $alternative = trim( $alternative );
            if ( $alternative === '' )
                continue;
            $xpath = '';
            $axis = '//';
            foreach ( preg_split( '/\s*(>)\s*|\s+/', $alternative, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $part )
            {
                if ( $part === '>' )
                {
                    $axis = '/';
                    continue;
                }
                $xpath .= $axis . self::stepToXPath( $part );
                $axis = '//';
            }
            $alternatives[] = $xpath;
        }
        return implode( ' | ', $alternatives );
    }

    /**
     * @param string $step one compound selector, e.g. div.attribute-heading or #main
     * @return string
     */
    protected static function stepToXPath( $step )
    {
        if ( !preg_match( '/^([a-zA-Z0-9*]*)(.*)$/', $step, $m ) )
            return '*';
        $tag = $m[1] === '' ? '*' : strtolower( $m[1] );
        $predicates = '';
        preg_match_all( '/([#.])([a-zA-Z0-9_-]+)|\[([a-zA-Z0-9_-]+)(?:([*^$]?=)["\']?([^"\'\]]*)["\']?)?\]/', $m[2], $parts, PREG_SET_ORDER );
        foreach ( $parts as $part )
        {
            if ( $part[1] === '#' )
                $predicates .= "[@id='" . $part[2] . "']";
            else if ( $part[1] === '.' )
                $predicates .= "[contains(concat(' ', normalize-space(@class), ' '), ' " . $part[2] . " ')]";
            else if ( isset( $part[4] ) && $part[4] !== '' )
            {
                $name = $part[3];
                $value = $part[5];
                switch ( $part[4] )
                {
                    case '^=': $predicates .= "[starts-with(@$name, '$value')]"; break;
                    case '*=': $predicates .= "[contains(@$name, '$value')]"; break;
                    case '$=': $predicates .= "[substring(@$name, string-length(@$name) - " . ( strlen( $value ) - 1 ) . ") = '$value']"; break;
                    default:   $predicates .= "[@$name='$value']";
                }
            }
            else
                $predicates .= '[@' . $part[3] . ']';
        }
        return $tag . $predicates;
    }

    /**
     * @param DOMDocument $doc
     * @param string $selector
     * @param DOMNode|null $context
     * @return DOMNodeList|array
     */
    public static function query( DOMDocument $doc, $selector, $context = null )
    {
        if ( trim( (string)$selector ) === '' )
            return array();
        $xpath = new DOMXPath( $doc );
        $expression = self::toXPath( $selector );
        if ( $context !== null )
            $expression = implode( ' | ', array_map( function ( $alternative ) { return '.' . $alternative; }, explode( ' | ', $expression ) ) );
        $result = $context !== null ? $xpath->query( $expression, $context ) : $xpath->query( $expression );
        return $result === false ? array() : $result;
    }

    /**
     * @param DOMDocument $doc
     * @param string $selector
     * @param DOMNode|null $context
     * @return DOMElement|null the first match; with alternatives (a, b) the first alternative that matches wins, so
     *                         "main, body" means "main, else body", not "whichever comes first in the page"
     */
    public static function first( DOMDocument $doc, $selector, $context = null )
    {
        foreach ( explode( ',', (string)$selector ) as $alternative )
            foreach ( self::query( $doc, $alternative, $context ) as $node )
                return $node;
        return null;
    }

    /**
     * @param DOMNode $node
     * @return string the HTML of the node's children
     */
    public static function innerHtml( DOMNode $node )
    {
        $html = '';
        foreach ( $node->childNodes as $child )
            $html .= $node->ownerDocument->saveHTML( $child );
        return $html;
    }

    /**
     * @param DOMNode $node
     * @return string the node's text, whitespace collapsed
     */
    public static function text( DOMNode $node )
    {
        return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $node->textContent ) ) );
    }

    /**
     * A name or a title as written: character references left in the text after parsing (a source that encoded them
     * twice, "Kara&amp;#347;", gives "Kara&#347;" as text) become their characters.
     *
     * @param string $text
     * @return string
     */
    public static function decodeText( $text )
    {
        $text = (string)$text;
        if ( strpos( $text, '&' ) === false )
            return $text;
        return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
    }

    /**
     * Markup with its twice-encoded numeric character references ("&amp;#347;", which shows as "&#347;") made the
     * reference they were meant to be. Named references stay: a text about markup may show "&amp;nbsp;" on purpose.
     *
     * @param string $html
     * @return string
     */
    public static function decodeDoubleEncoded( $html )
    {
        return preg_replace_callback( '/&amp;#(\d{1,7}|x[0-9a-fA-F]{1,6});/', function ( $m ) {
            $n = $m[1][0] === 'x' ? hexdec( substr( $m[1], 1 ) ) : (int)$m[1];
            // 128 - 159 are Windows-1252's characters as the old site wrote them ("&#133;" is its ellipsis), no
            // control characters: their Unicode reference
            if ( $n >= 128 && $n <= 159 && function_exists( 'mb_convert_encoding' ) && ( $c = mb_convert_encoding( chr( $n ), 'UTF-8', 'Windows-1252' ) ) !== '' && $c !== '?' )
                return '&#' . mb_ord( $c, 'UTF-8' ) . ';';
            return '&#' . $m[1] . ';';
        }, (string)$html );
    }

    /**
     * Resolves a link of a page to a path relative to the source root.
     *
     * @param string $fromPath the page's own path (e.g. Exponential/Technical-manual/3-5.html)
     * @param string $href the link as written
     * @return string|null the path, null for a link that leaves the source (another host, mailto:, javascript:, #)
     */
    public static function resolve( $fromPath, $href )
    {
        $href = trim( html_entity_decode( (string)$href, ENT_QUOTES, 'UTF-8' ) );
        if ( $href === '' || $href[0] === '#' || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) || strpos( $href, '//' ) === 0 )
            return null;
        $href = preg_replace( '/[#?].*$/s', '', $href );
        if ( $href === '' )
            return null;
        $href = rawurldecode( $href );
        $base = $href[0] === '/' ? '' : dirname( $fromPath );
        $parts = array();
        foreach ( explode( '/', ( $base === '.' || $base === '' ? '' : $base . '/' ) . ltrim( $href, '/' ) ) as $segment )
        {
            if ( $segment === '' || $segment === '.' )
                continue;
            if ( $segment === '..' )
            {
                if ( !$parts )
                    return null;
                array_pop( $parts );
                continue;
            }
            $parts[] = $segment;
        }
        return implode( '/', $parts );
    }
}
