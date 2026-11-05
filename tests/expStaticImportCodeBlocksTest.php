<?php
/**
 * File containing the expStaticImportCodeBlocksTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

foreach ( array( 'expstaticimportrules', 'expstaticimportdom', 'expstaticimporthtmlcleaner' ) as $file )
    require_once __DIR__ . '/../classes/' . $file . '.php';

/**
 * The code listings of a tutorial step survive the cleaner, without a database. The old documentation writes its code
 * as <pre class="wordwrap copytoclipboard">; a remove selector for the copy button (.copytoclipboard) matched the
 * listings too and the import stored the page without them. A code block (LiteralSelectors[], CodeSelectors[]) and
 * what is inside one are never removed; what a remove selector matches around a code block still goes.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportCodeBlocksTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array $rules
     * @param string $html
     * @return string the cleaned body
     */
    protected function clean( array $rules, $html = null )
    {
        $doc = expStaticImportDom::load( $html !== null ? $html : file_get_contents( __DIR__ . '/fixtures/code-blocks/Template-operators.html' ) );
        $body = expStaticImportDom::first( $doc, '.area-docmain-normal .content-view-full, body' );
        $cleaner = new expStaticImportHtmlCleaner( new expStaticImportRules( 'doc-html', $rules ) );
        return $cleaner->clean( $doc, $body );
    }

    /**
     * The documentation type's rules for code and for the parts of a page that go.
     */
    protected function rules()
    {
        return array(
            'RemoveSelectors' => array( '.attribute-byline', '.pageinfo', '.copytoclipboard', '#comments-section' ),
            'UnwrapSelectors' => array( '.template-object', '.template-module' ),
            'LiteralSelectors' => array( 'pre.wordwrap' ),
            'CodeSelectors' => array( '.codeContent pre', 'pre.syntaxhighlighter-pre' ),
            'CodeLineSelector' => 'span.line',
        );
    }

    public function testTheFourListingsOfTheTemplateOperatorsStepAreKept()
    {
        $html = $this->clean( $this->rules() );
        $this->assertSame( 4, preg_match_all( '#<pre class="wordwrap copytoclipboard">#', $html ), $html );
        // one line per source line, the entities decoded once, the listing captions after them
        $this->assertStringContainsString( "&lt;?php\n/**", $html );
        $this->assertStringContainsString( "\$eZTemplateOperatorArray = array();", $html );
        $this->assertStringContainsString( "&lt;h2&gt;Template Operator: jac('count') and jac('list')&lt;/h2&gt;", $html );
        $this->assertStringContainsString( 'Listing 18.', $html );
        $this->assertStringContainsString( 'Listing 21.', $html );
        $this->assertStringNotContainsString( '<span class="line">', $html );
    }

    public function testARemoveSelectorStillRemovesWhatIsNoCode()
    {
        $html = $this->clean( $this->rules(), '<html><body><p class="copytoclipboard"><img src="copy.gif"></p>'
            . '<pre class="wordwrap copytoclipboard"><span class="line">a = 1</span></pre>'
            . '<div id="comments-section"><pre class="wordwrap"><span class="line">in a comment</span></pre></div></body></html>' );
        $this->assertStringNotContainsString( 'copy.gif', $html );
        $this->assertStringNotContainsString( 'in a comment', $html, 'a code block inside a removed part goes with it' );
        $this->assertStringContainsString( '<pre class="wordwrap copytoclipboard">a = 1</pre>', $html );
    }

    /**
     * Every form of code block the documentation export has (a scan of its 100,288 HTML files): each comes out as
     * one <pre> with its text, a Confluence code macro as the doc_code custom tag with its language.
     */
    public function testEveryCodeBlockFormOfTheExportIsKept()
    {
        $forms = array(
            'pre.wordwrap (54,119)' => '<pre class="wordwrap"><span class="line">a = 1</span></pre>',
            'pre.wordwrap.copytoclipboard (22,375)' => '<pre class="wordwrap copytoclipboard"><span class="line">b = 2</span></pre>',
            'pre.wordwrap.nocopy (287)' => '<pre class="wordwrap nocopy"><span class="line">c = 3</span></pre>',
            'pre (28,471)' => '<pre>d = 4</pre>',
            'pre.pfix (14,254)' => '<pre class="pfix">e = 5</pre>',
            'Confluence code macro (13,864)' => '<div class="code panel pdl"><div class="codeContent panelContent pdl">'
                . '<pre class="syntaxhighlighter-pre" data-syntaxhighlighter-params="brush: php; gutter: false">f = 6</pre></div></div>',
            'Confluence noformat (2,128)' => '<div class="preformatted panel"><div class="preformattedContent panelContent"><pre>g = 7</pre></div></div>',
            'Markdown highlight (127)' => '<div class="highlight highlight-php"><pre>h = 8</pre></div>',
            'in a table cell (2,854)' => '<table><tr><td class="confluenceTd"><pre>i = 9</pre></td></tr></table>',
            'in an output box (364)' => '<div class="output"><pre>j = 10</pre></div>',
            'in a list item (74)' => '<ul><li><pre>k = 11</pre></li></ul>',
        );
        $html = $this->clean( $this->rules() + array( 'CodeLanguagePatterns' => array( '/brush:\s*([a-z0-9+#]+)/i' ) ),
                              '<html><body>' . implode( '', $forms ) . '</body></html>' );
        $this->assertSame( count( $forms ), preg_match_all( '#<pre\b#', $html ), $html );
        foreach ( array( 'a = 1', 'b = 2', 'c = 3', 'd = 4', 'e = 5', 'f = 6', 'g = 7', 'h = 8', 'i = 9', 'j = 10', 'k = 11' ) as $line )
            $this->assertStringContainsString( $line, $html );
        $this->assertStringContainsString( 'class="doc_code"', $html );
        $this->assertStringContainsString( 'lang|php', $html );
    }

    /**
     * The code blocks of the content/list fetch function page keep every line and its indentation: what the cleaner
     * gives is the source's text line for line (no-break spaces as spaces, nothing at the end of a line), and what
     * goes to the online editor's parser has each line break as <br /> (a raw line break inside <pre> is dropped by
     * it: the stored code was one long line, "[MyFilter]#The name of the extension...").
     */
    public function testTheLinesAndTheIndentationOfEveryCodeBlockAreKept()
    {
        $html = $this->clean( $this->rules(), file_get_contents( __DIR__ . '/fixtures/code-blocks/fetch-functions-list.html' ) );
        $this->assertSame( 3, preg_match_all( '#<pre class="([^"]*)">(.*?)</pre>#s', $html, $m ) );
        $texts = array_map( function ( $text ) { return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ); }, $m[2] );

        // the fetch() usage, its columns of spaces as the source has them
        $this->assertStringStartsWith( "fetch( 'content', 'list',\n hash( 'parent_node_id',            parent_node_id,\n     [ 'sort_by',", $texts[0] );
        // the INI block: a line per setting, the empty lines kept
        $this->assertStringStartsWith( "#The name of the filter.\n[MyFilter]\n\n#The name of the extension where the filtering code is defined.\nExtensionName=myextension\n", $texts[1] );
        // the PHP class: its indentation, line by line
        $this->assertStringStartsWith( "class eZMyExtendedFilter\n{\n    /*!\n     Constructor\n    */\n    public function __construct()\n    {\n        // Empty...\n    }\n\n    public function createSqlParts( \$params )\n    {\n        \$sqlTables= ', ezmyfiltertable ';", $texts[2] );
        foreach ( $texts as $text )
        {
            $this->assertStringNotContainsString( "\xc2\xa0", $text );
            $this->assertDoesNotMatchRegularExpression( '/[ \t]$/m', $text, 'no white space at the end of a line' );
            $this->assertDoesNotMatchRegularExpression( '/^\n|\n$/', $text, 'no empty first or last line' );
        }

        // to the parser: each line break of a <pre> as <br />, nothing else changed
        $parserInput = expStaticImportHtmlCleaner::preLinesAsBreaks( $html );
        $this->assertSame( 3, preg_match_all( '#<pre class="([^"]*)">(.*?)</pre>#s', $parserInput, $p ) );
        foreach ( $p[2] as $i => $text )
        {
            $this->assertStringNotContainsString( "\n", $text );
            $this->assertSame( $m[2][$i], str_replace( '<br />', "\n", $text ) );
        }
        $this->assertSame( str_replace( $m[0], '', $html ), str_replace( $p[0], '', $parserInput ) );
    }

    public function testCodeTextKeepsRelativeIndentationAndTakesOnlyWhatTheHtmlAdded()
    {
        // a shared indent from the HTML's formatting goes; one line against another keeps its indent; tabs stay
        $this->assertSame( "if ( \$a )\n{\n\treturn 1;\n}", expStaticImportHtmlCleaner::codeText( "\r\n    if ( \$a )   \r\n    {\r\n    \treturn 1;\r\n    }\r\n\r\n" ) );
        // no-break spaces are spaces; an empty line inside stays
        $this->assertSame( "a\n\n  b", expStaticImportHtmlCleaner::codeText( "a\n\xc2\xa0\n\xc2\xa0\xc2\xa0b" ) );
        // nothing shared: nothing taken
        $this->assertSame( "x\n  y", expStaticImportHtmlCleaner::codeText( "x\n  y" ) );
        // a <pre> with <br> and plain text, not a literal selector: its lines too
        $html = $this->clean( $this->rules(), '<html><body><pre>  one<br>    two<br/>  three</pre></body></html>' );
        $this->assertStringContainsString( "<pre>one\n  two\nthree</pre>", $html );
        $this->assertSame( '<pre>one<br />  two<br />three</pre>', expStaticImportHtmlCleaner::preLinesAsBreaks( "<pre>one\r\n  two\nthree</pre>" ) );
    }

    public function testWithoutCodeSelectorsTheRemoveSelectorsWorkAsBefore()
    {
        $rules = $this->rules();
        unset( $rules['LiteralSelectors'], $rules['CodeSelectors'] );
        $this->assertSame( 0, preg_match_all( '#<pre#', $this->clean( $rules ) ) );
    }
}
