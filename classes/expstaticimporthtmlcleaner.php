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
        foreach ( $this->rules->getList( 'RemoveSelectors' ) as $selector )
            foreach ( $this->toArray( expStaticImportDom::query( $doc, $selector, $body ) ) as $node )
                if ( $node->parentNode )
                    $node->parentNode->removeChild( $node );
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
                $lines = $lineSelector !== '' ? $this->toArray( expStaticImportDom::query( $doc, $lineSelector, $node ) ) : array();
                $text = $lines ? implode( "\n", array_map( function ( $line ) { return rtrim( str_replace( "\xc2\xa0", ' ', $line->textContent ) ); }, $lines ) )
                               : rtrim( str_replace( "\xc2\xa0", ' ', $node->textContent ) );
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
     * A code block becomes <div type="custom" class="doc_code" customattributes="lang|..."><pre>text</pre></div>.
     *
     * @param DOMDocument $doc
     * @param DOMElement $node
     */
    protected function convertCode( DOMDocument $doc, DOMElement $node )
    {
        $lineSelector = (string)$this->rules->get( 'CodeLineSelector', '' );
        $lines = $lineSelector !== '' ? $this->toArray( expStaticImportDom::query( $doc, $lineSelector, $node ) ) : array();
        if ( $lines )
            $text = implode( "\n", array_map( function ( $line ) { return rtrim( str_replace( "\xc2\xa0", ' ', $line->textContent ) ); }, $lines ) );
        else
            $text = rtrim( str_replace( "\xc2\xa0", ' ', $node->textContent ) );
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
