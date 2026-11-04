<?php
/**
 * File containing the expStaticImportDocHtmlFixtureTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

foreach ( array( 'expstaticimportrules', 'expstaticimportdom', 'expstaticimportbyline', 'expstaticimporthtmlcleaner',
                 'expstaticimportpageexception', 'expstaticimporttypeinterface', 'expstaticimporthtmltype' ) as $file )
    require_once __DIR__ . '/../classes/' . $file . '.php';
require_once __DIR__ . '/../classes/types/expstaticimporttypedochtml.php';

/**
 * The --doc-html type on a small fixture of the documentation export (tests/fixtures/doc-site), without a database:
 * discovery, the renamed version page, the byline fields out of the body, literals, comments, redirect stubs and
 * error pages.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportDocHtmlFixtureTest extends \PHPUnit\Framework\TestCase
{
    /** @var expStaticImportTypeDocHtml */
    protected $type;

    /** @var string */
    protected $source;

    protected function setUp(): void
    {
        $this->source = __DIR__ . '/fixtures/doc-site';
        $this->type = new expStaticImportTypeDocHtml();
        $this->type->configure( new expStaticImportRules( 'doc-html', array(
            'TitleSelector' => '.attribute-heading h1, h1',
            'BodySelector' => '.area-docmain-normal .content-view-full, body',
            'FolderPagePatterns' => array( 'Manual/[0-9]*.html' ),
            'CopyOfPatterns' => array( '#^(.*)/\(language\)/[^/]+\.html$#' ),
            'GenericTitles' => array( 'Table of contents' ),
            'NotFoundTitlePatterns' => array( '/^404\b/' ),
            'BylineSelectors' => array( '.pageinfo' ),
            'BylineFields' => array( 'written' => 'Written by', 'updated' => 'Last updated by', 'maintainers' => 'Maintainers' ),
            'BylineListFields' => array( 'maintainers' ),
            'BylineDateFormats' => array( 'd/m/Y g:i a' ),
            'BylineTimezone' => 'Europe/Oslo',
            'LiteralSelectors' => array( 'pre.wordwrap' ),
            'CodeLineSelector' => 'span.line',
            'CommentsSelector' => '#comments-content',
            'CommentClass' => 'exp_doc_comment',
            'RemoveSelectors' => array( '#comments', '.attribute-byline' ),
            'DefaultClass' => 'exp_doc_page',
            'ClassRules' => array( 'Manual/[0-9]*.html;exp_doc_version' ),
            'AttributeMap' => array( '*/title' => 'title', '*/body' => 'body', '*/written_by' => 'written_by', '*/written_at' => 'written_at',
                                     '*/last_updated_by' => 'last_updated_by', '*/last_updated_at' => 'last_updated_at', '*/maintainers' => 'maintainers' ),
        ) ) );
        $paths = $this->type->discover( $this->source );
        $this->type->setKnownPaths( $paths );
    }

    public function testDiscoveryIsParentsFirst()
    {
        $paths = $this->type->discover( $this->source );
        $this->assertSame( 'Manual.html', $paths[0] );
        $this->assertLessThan( array_search( 'Manual/1.0/Chapter/Page.html', $paths ), array_search( 'Manual/1.0/Chapter.html', $paths ) );
    }

    public function testTheRenamedVersionPageParentsItsFolder()
    {
        $this->assertSame( 'Manual/1-0.html', $this->type->parentPath( 'Manual/1.0/Chapter.html' ) );
        $this->assertSame( 'Manual/1.0/Chapter.html', $this->type->parentPath( 'Manual/1.0/Chapter/Page.html' ) );
        $page = $this->type->map( $this->type->parse( $this->source, 'Manual/1-0.html' ) );
        $this->assertSame( '1.0', $page['title'] );
        $this->assertSame( 'exp_doc_version', $page['class'] );
        $this->assertSame( 'Manual.html', $page['parent_path'] );
    }

    public function testTheBylineLeavesTheBodyForItsFields()
    {
        $page = $this->type->map( $this->type->parse( $this->source, 'Manual/1.0/Chapter.html' ) );
        $this->assertStringNotContainsString( 'Written by', $page['body_html'] );
        $this->assertStringNotContainsString( 'Maintainers', $page['body_html'] );
        $this->assertStringContainsString( 'Chapter text.', $page['body_html'] );
        $this->assertStringContainsString( 'Written by', $page['original_html'] );
        $this->assertSame( 'Ann Example', $page['bylines']['written']['name'] );
        $this->assertSame( 'Bob Example', $page['bylines']['updated']['name'] );
        $this->assertSame( array( 'Ann Example', 'Bob Example' ), $page['bylines']['maintainers']['names'] );
        $this->assertSame( '{contributor:written}', $page['attributes']['written_by'] );
        $this->assertSame( '{contributor:updated}', $page['attributes']['last_updated_by'] );
        $this->assertSame( '{contributors:maintainers}', $page['attributes']['maintainers'] );
        $this->assertSame( (string)DateTime::createFromFormat( '!d/m/Y g:i a', '05/11/2013 3:09 pm', new DateTimeZone( 'Europe/Oslo' ) )->getTimestamp(),
                           $page['attributes']['last_updated_at'] );
    }

    public function testAnUnreadableBylineIsReportedAndStillRemoved()
    {
        $page = $this->type->parse( $this->source, 'Manual/1.0/Chapter/Page.html' );
        $this->assertSame( array( 'Written by: Ann Example (sometime)' ), $page['byline_failures'] );
        $this->assertStringNotContainsString( 'Written by', $page['body_html'] );
    }

    public function testCodeStaysALiteralWithItsClass()
    {
        $page = $this->type->parse( $this->source, 'Manual/1.0/Chapter/Page.html' );
        $this->assertStringContainsString( "<pre class=\"wordwrap\">a = 1\nb = 2</pre>", $page['body_html'] );
    }

    public function testCommentsAndRepliesBecomeChildPages()
    {
        $page = $this->type->parse( $this->source, 'Manual/1.0/Chapter/Page.html' );
        $this->assertCount( 1, $page['children'] );
        $comment = $page['children'][0];
        $this->assertSame( 'Manual/1.0/Chapter/Page.html#comment11', $comment['path'] );
        $this->assertSame( 'exp_doc_comment', $comment['class_fixed'] );
        $this->assertSame( 'Reader', $comment['meta']['author'] );
        $this->assertCount( 1, $comment['children'] );
        $this->assertSame( 'Manual/1.0/Chapter/Page.html#comment11', $comment['children'][0]['parent_fixed'] );
        $this->assertStringNotContainsString( 'Why?', $page['body_html'] );
    }

    public function testCommentNamesSubjectsAndTextsAreDecodedTheOriginalIsNot()
    {
        $page = $this->type->parse( $this->source, 'Manual/1.0/Chapter/Page.html' );
        $reply = $page['children'][0]['children'][0];
        // the source encoded these characters twice ("Kara&amp;#347;"): as written they are the characters
        $this->assertSame( "Piotrek Kara\u{015B}", $reply['meta']['author'] );
        $this->assertSame( "Piotrek Kara\u{015B}", $reply['bylines']['written']['name'] );
        $this->assertSame( "Re: Question \u{201C}why\u{201D}", $reply['title'] );
        $this->assertSame( '<p>Because &#322;.</p>', $reply['body_html'] );
        $this->assertStringContainsString( 'Kara&amp;#347;', $reply['original_html'] );
        $this->assertSame( 'a &amp;nbsp; b &#322;', expStaticImportDom::decodeDoubleEncoded( 'a &amp;nbsp; b &amp;#322;' ) );
        $this->assertSame( "Kara\u{015B}", expStaticImportDom::decodeText( 'Kara&#347;' ) );
    }

    public function testRepliesInsideTheCommentBoxNestAsDeepAsTheSource()
    {
        // the old site's markup: a reply list inside the comment's div.class-comment, four levels below its <li>,
        // and a list in a comment's text that is no reply
        $page = $this->type->parse( $this->source, 'Manual/1.0/Chapter/Deep.html' );
        $this->assertCount( 2, $page['children'] );
        list( $first, $fourth ) = $page['children'];
        $this->assertSame( array( '1', '' ), array( $first['meta']['comment_id'], $first['meta']['parent_comment_id'] ) );
        $this->assertSame( array(), $fourth['children'] );
        $second = $first['children'][0];
        $this->assertCount( 1, $first['children'] );
        $this->assertSame( array( '2', '1', 'Second', 'Reader 2' ), array( $second['meta']['comment_id'], $second['meta']['parent_comment_id'], $second['title'], $second['meta']['author'] ) );
        $this->assertStringContainsString( 'a list item of the text', $second['body_html'] );
        $third = $second['children'][0];
        $this->assertCount( 1, $second['children'] );
        $this->assertSame( array( '3', '2', 'Reader 3' ), array( $third['meta']['comment_id'], $third['meta']['parent_comment_id'], $third['meta']['author'] ) );
        $this->assertSame( 'Manual/1.0/Chapter/Deep.html#comment2', $third['parent_fixed'] );
        // a comment's code is cleaned as a page's: one literal, its line markup gone; the original keeps the markup
        $this->assertStringContainsString( "<pre class=\"wordwrap\">x = 1\ny = 2</pre>", $third['body_html'] );
        $this->assertStringContainsString( '<span class="line">x = 1</span>', $third['original_html'] );
        // a comment's own markup leaves its replies out, and keeps its text's list
        $this->assertStringNotContainsString( 'comment2', $first['original_html'] );
        // a pasted text's style spans go from the text (the XML would show them), the original keeps them
        $this->assertSame( '<p class="p1">Top.</p>', $first['body_html'] );
        $this->assertStringContainsString( '<span class="s1">Top.</span>', $first['original_html'] );
        $this->assertStringContainsString( 'a list item of the text', $second['original_html'] );
        $this->assertStringNotContainsString( 'Deepest', $second['original_html'] );
    }

    public function testRedirectStubsAndErrorPages()
    {
        $stub = $this->type->parse( $this->source, 'moved.html' );
        $this->assertSame( 'Manual.html', $stub['redirect_to'] );
        $this->assertNull( $this->type->parse( $this->source, 'missing.html' ) );
    }

    public function testAViewParameterCopyRedirectsToThePageOfItsFolder()
    {
        // Manual/1.0/(language)/eng-GB.html copies the page of the folder 1.0, which is the renamed version page
        $copy = $this->type->parse( $this->source, 'Manual/1.0/(language)/eng-GB.html' );
        $this->assertSame( 'Manual/1-0.html', $copy['redirect_to'] );
    }
}
