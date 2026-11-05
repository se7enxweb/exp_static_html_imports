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

    public function testWithoutCodeSelectorsTheRemoveSelectorsWorkAsBefore()
    {
        $rules = $this->rules();
        unset( $rules['LiteralSelectors'], $rules['CodeSelectors'] );
        $this->assertSame( 0, preg_match_all( '#<pre#', $this->clean( $rules ) ) );
    }
}
