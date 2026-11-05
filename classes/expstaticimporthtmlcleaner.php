<?php
/**
 * File containing the expStaticImportHtmlCleaner class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Prepares the body of a static page for the editor's XML input parser (the online editor's HTML-to-XML parser):
 * removes what never belongs in content (scripts, styles, comments, the selectors a type names), turns the source's
 * macros into custom tags, and code blocks into a code custom tag around a literal.
 *
 * The custom tags are written the way the online editor writes them, so the kernel's parser keeps them:
 *   <div type="custom" class="doc_panel" customattributes="type|info">...</div>
 * Rules (from the type's settings):
 *   RemoveSelectors[]          removed with their content
 *   UnwrapSelectors[]          removed, their content kept
 *   PanelSelectors[<type>]     a panel of that type (info, note, warning, tip)
 *   CodeSelectors[]            code blocks; the language from CodeLanguageAttribute patterns
 *   ExpandSelectors[]          an expandable section (its title from ExpandTitleSelector)
 *   ChildrenSelectors[]        a list of the page's children: replaced by an empty doc_children tag, drawn live
 *   CodeLineSelector           elements that each hold one line of a code block (e.g. span.line)
 */
class expStaticImportHtmlCleaner
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
     * @param DOMDocument $doc
     * @param DOMElement $body the element whose content is the page body
     * @return string the cleaned HTML of the body's content
     */
    public function clean( DOMDocument $doc, DOMElement $body )
    {
        $xpath = new DOMXPath( $doc );
        foreach ( iterator_to_array( $xpath->query( './/comment()', $body ) ) as $comment )
            $comment->parentNode->removeChild( $comment );
        foreach ( array( 'script', 'style', 'noscript', 'iframe', 'object', 'form', 'input', 'button', 'select', 'textarea' ) as $tag )
            foreach ( iterator_to_array( $body->getElementsByTagName( $tag ) ) as $node )
                $node->parentNode->removeChild( $node );
        // a code block (LiteralSelectors[], CodeSelectors[]) and what is inside one are never removed, even when a
        // remove selector matches them too: the old documentation's code is <pre class="wordwrap copytoclipboard">,
        // and ".copytoclipboard", meant for the copy button next to it, took the listings with it
        $code = array();
        foreach ( array_merge( $this->rules->getList( 'LiteralSelectors' ), $this->rules->getList( 'CodeSelectors' ) ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
                $code[] = $node;
        foreach ( $this->rules->getList( 'RemoveSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
            {
                if ( !$node->parentNode )
                    continue;
                for ( $up = $node; $code && $up instanceof DOMElement; $up = $up->parentNode )
                    if ( in_array( $up, $code, true ) )
                        continue 2;
                $node->parentNode->removeChild( $node );
            }
        foreach ( $this->rules->getList( 'UnwrapSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
                $this->unwrap( $node );

        foreach ( $this->rules->getList( 'ChildrenSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
                $this->replace( $node, $this->customTag( $doc, 'doc_children', array() ) );

        // literal blocks (the source's own rendered code, e.g. <pre class="wordwrap"> of one <span class="line"> per
        // line): one plain text <pre> that keeps its class, which the parser turns into a literal with that class
        $lineSelector = (string)$this->rules->get( 'CodeLineSelector', '' );
        foreach ( $this->rules->getList( 'LiteralSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
            {
                $text = $this->blockText( $doc, $node, $lineSelector );
                $pre = $doc->createElement( 'pre' );
                if ( $node->getAttribute( 'class' ) !== '' )
                    $pre->setAttribute( 'class', trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) ) );
                $pre->appendChild( $doc->createTextNode( $text ) );
                $pre->setAttribute( 'data-static-import', 'literal' );
                $this->replace( $node, $pre );
            }

        // aligned images: a wrapper (e.g. div.object-center) gives its image an alignment, which the import keeps
        // on the embed, and goes
        foreach ( $this->rules->getHash( 'EmbedAlignSelectors' ) as $align => $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
            {
                foreach ( $this->toArray( $node->getElementsByTagName( 'img' ) ) as $img )
                    $img->setAttribute( 'data-align', $align );
                $this->unwrap( $node );
            }

        foreach ( $this->rules->getList( 'CodeSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
                if ( $node->getAttribute( 'data-static-import' ) !== 'literal' )
                    $this->convertCode( $doc, $node );
        // every other <pre> of plain text (no links or styles inside): its text as a code block's (codeText())
        foreach ( $this->toArray( $body->getElementsByTagName( 'pre' ) ) as $node )
            if ( !$node->hasAttribute( 'data-static-import' ) )
            {
                foreach ( $this->toArray( $node->getElementsByTagName( 'br' ) ) as $br )
                    $br->parentNode->replaceChild( $doc->createTextNode( "\n" ), $br );
                if ( !$node->getElementsByTagName( '*' )->length )
                {
                    $text = self::codeText( $node->textContent );
                    while ( $node->firstChild )
                        $node->removeChild( $node->firstChild );
                    $node->appendChild( $doc->createTextNode( $text ) );
                }
            }
        foreach ( iterator_to_array( $xpath->query( './/*[@data-static-import]', $body ) ) as $node )
            $node->removeAttribute( 'data-static-import' );

        foreach ( $this->rules->getHash( 'PanelSelectors' ) as $type => $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
            {
                $panel = $this->customTag( $doc, 'doc_panel', array( 'type' => $type ) );
                $this->moveChildren( $node, $panel );
                $this->replace( $node, $panel );
            }

        $titleSelector = (string)$this->rules->get( 'ExpandTitleSelector', '' );
        foreach ( $this->rules->getList( 'ExpandSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
            {
                $title = '';
                $titleNode = $titleSelector !== '' ? expStaticImportDom::first( $doc, $titleSelector, $node ) : null;
                if ( $titleNode )
                {
                    $title = expStaticImportDom::text( $titleNode );
                    $titleNode->parentNode->removeChild( $titleNode );
                }
                $expand = $this->customTag( $doc, 'doc_expand', array( 'title' => $title ) );
                $this->moveChildren( $node, $expand );
                $this->replace( $node, $expand );
            }

        // presentation attributes never reach the content: the designs style it, and the pages must pass the CSP
        foreach ( iterator_to_array( $xpath->query( './/*[@style or @onclick or @onload or @onmouseover or @align or @valign or @bgcolor]', $body ) ) as $node )
            foreach ( array( 'style', 'onclick', 'onload', 'onmouseover', 'align', 'valign', 'bgcolor' ) as $attribute )
                $node->removeAttribute( $attribute );

        return trim( expStaticImportDom::innerHtml( $body ) );
    }

    /**
     * The text of a code block, line by line: the elements of CodeLineSelector one per line when the block has them,
     * else its text, with its <br> as line breaks.
     *
     * @param DOMDocument $doc
     * @param DOMElement $node
     * @param string $lineSelector
     * @return string
     */
    protected function blockText( DOMDocument $doc, DOMElement $node, $lineSelector )
    {
        $lines = $lineSelector !== '' ? $this->toArray( expStaticImportDom::query( $doc, $lineSelector, $node ) ) : array();
        if ( $lines )
            return self::codeText( implode( "\n", array_map( function ( $line ) { return $line->textContent; }, $lines ) ) );
        foreach ( $this->toArray( $node->getElementsByTagName( 'br' ) ) as $br )
            $br->parentNode->replaceChild( $doc->createTextNode( "\n" ), $br );
        return self::codeText( $node->textContent );
    }

    /**
     * The text of a code block as it is stored: every line break, tab and space of the code kept, with only what the
     * old site's HTML added taken away: line breaks as \n (CRLF, CR), no-break spaces as spaces, the white space at the
     * end of each line, the empty lines before the first and after the last line, and an indent every line shares
     * (the HTML's own formatting; the indentation of one line against another stays exactly).
     *
     * @param string $text
     * @return string
     */
    public static function codeText( $text )
    {
        $text = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), (string)$text );
        $lines = array_map( 'rtrim', explode( "\n", $text ) );
        while ( $lines && $lines[0] === '' )
            array_shift( $lines );
        while ( $lines && end( $lines ) === '' )
            array_pop( $lines );
        $indent = null;
        foreach ( $lines as $line )
        {
            if ( $line === '' )
                continue;
            preg_match( '/^[ \t]*/', $line, $m );
            $indent = $indent === null ? $m[0] : self::commonPrefix( $indent, $m[0] );
            if ( $indent === '' )
                break;
        }
        if ( $indent )
            foreach ( $lines as $i => $line )
                $lines[$i] = $line === '' ? '' : substr( $line, strlen( $indent ) );
        return implode( "\n", $lines );
    }

    /**
     * The HTML for the online editor's input parser: the line breaks inside each <pre> written as <br />, which the
     * parser turns into the line breaks of the literal (a raw line break inside <pre> is dropped by it, and a code
     * block became one long line).
     *
     * @param string $html
     * @return string
     */
    public static function preLinesAsBreaks( $html )
    {
        return preg_replace_callback( '#(<pre\b[^>]*>)(.*?)(</pre>)#is', function ( $m )
        {
            return $m[1] . str_replace( "\n", '<br />', str_replace( array( "\r\n", "\r" ), "\n", $m[2] ) ) . $m[3];
        }, (string)$html );
    }

    /**
     * @param string $a
     * @param string $b
     * @return string
     */
    protected static function commonPrefix( $a, $b )
    {
        $n = min( strlen( $a ), strlen( $b ) );
        $i = 0;
        while ( $i < $n && $a[$i] === $b[$i] )
            $i++;
        return substr( $a, 0, $i );
    }

    /**
     * A code block becomes <div type="custom" class="doc_code" customattributes="lang|..."><pre>text</pre></div>.
     *
     * @param DOMDocument $doc
     * @param DOMElement $node
     */
    protected function convertCode( DOMDocument $doc, DOMElement $node )
    {
        $text = $this->blockText( $doc, $node, (string)$this->rules->get( 'CodeLineSelector', '' ) );
        $language = '';
        $class = (string)$node->getAttribute( 'class' ) . ' ' . (string)$node->getAttribute( 'data-syntaxhighlighter-params' ) . ' ' . (string)$node->getAttribute( 'data-lang' );
        foreach ( $this->rules->getList( 'CodeLanguagePatterns' ) as $pattern )
            if ( @preg_match( $pattern, $class, $m ) && isset( $m[1] ) )
            {
                $language = strtolower( $m[1] );
                break;
            }
        $pre = $doc->createElement( 'pre' );
        $pre->appendChild( $doc->createTextNode( $text ) );
        $code = $this->customTag( $doc, 'doc_code', $language !== '' ? array( 'lang' => $language ) : array() );
        $code->appendChild( $pre );
        $this->replace( $node, $code );
    }

    /**
     * @param DOMDocument $doc
     * @param string $name
     * @param array $attributes
     * @return DOMElement
     */
    protected function customTag( DOMDocument $doc, $name, array $attributes )
    {
        $element = $doc->createElement( 'div' );
        $element->setAttribute( 'type', 'custom' );
        $element->setAttribute( 'class', $name );
        $pairs = array();
        foreach ( $attributes as $key => $value )
            $pairs[] = $key . '|' . $value;
        if ( $pairs )
            $element->setAttribute( 'customattributes', implode( 'attribute_separation', $pairs ) );
        return $element;
    }

    protected function moveChildren( DOMNode $from, DOMNode $to )
    {
        while ( $from->firstChild )
            $to->appendChild( $from->firstChild );
    }

    protected function replace( DOMNode $old, DOMNode $new )
    {
        if ( $old->parentNode )
            $old->parentNode->replaceChild( $new, $old );
    }

    protected function unwrap( DOMNode $node )
    {
        if ( !$node->parentNode )
            return;
        while ( $node->firstChild )
            $node->parentNode->insertBefore( $node->firstChild, $node );
        $node->parentNode->removeChild( $node );
    }

    /**
     * @param DOMNodeList|array $list
     * @return array a static copy, safe to change the tree while walking it
     */
    protected function toArray( $list )
    {
        return is_array( $list ) ? $list : iterator_to_array( $list );
    }
}
