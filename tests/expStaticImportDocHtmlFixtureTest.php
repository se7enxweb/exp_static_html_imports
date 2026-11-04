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
