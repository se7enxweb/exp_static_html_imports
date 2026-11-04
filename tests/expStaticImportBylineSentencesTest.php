<?php
/**
 * File containing the expStaticImportBylineSentencesTest class.
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
 * Bylines written as a sentence (Confluence's four variants), comment authors, and the contributors list of every
 * person a page names, without a database (tests/fixtures/flat-site/display/SPC/Byline-*.html).
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportBylineSentencesTest extends \PHPUnit\Framework\TestCase
{
    /** @var expStaticImportTypeDocHtml */
    protected $type;

    /** @var string */
    protected $source;

    protected function setUp(): void
    {
        $this->source = __DIR__ . '/fixtures/flat-site';
        $this->type = new expStaticImportTypeDocHtml();
        $this->type->configure( new expStaticImportRules( 'doc-html', ( require __DIR__ . '/fixtures/confluence-byline-rules.php' ) + array(
            'TitleSelector' => '#title-text, h1',
            'BodySelector' => '#main-content, body',
            'DefaultClass' => 'exp_doc_page',
            'ConfluenceCommentsSelector' => '#page-comments', 'CommentClass' => 'comment_historical',
            'AttributeMap' => array( '*/title' => 'title', '*/written_by' => 'written_by', '*/written_at' => 'written_at',
                                     '*/last_updated_by' => 'last_updated_by', '*/last_updated_at' => 'last_updated_at',
                                     '*/contributors' => 'contributors' ),
        ) ) );
        $this->type->setKnownPaths( $this->type->discover( $this->source ) );
    }

    protected function time( $text )
    {
        return DateTime::createFromFormat( '!M d, Y' . ( strpos( $text, ':' ) ? ' H:i' : '' ), $text, new DateTimeZone( 'Europe/Oslo' ) )->getTimestamp();
    }

    public function testCreatedByAndLastModifiedByAnother()
    {
        $page = $this->type->map( $this->type->parse( $this->source, 'display/SPC/Byline-both.html' ) );
        $this->assertSame( 'Ann Writer', $page['bylines']['written']['name'] );
        $this->assertNull( $page['bylines']['written']['time'] );
        $this->assertSame( 'Bob Editor', $page['bylines']['updated']['name'] );
        $this->assertSame( $this->time( 'Oct 28, 2016' ), $page['bylines']['updated']['time'] );
        $this->assertSame( array(), $page['byline_failures'] );
        // the commenters, each person once over every role: the same name in another case is the same person
        $this->assertSame( array( 'Cid Reader', 'ann writer' ), $page['bylines']['commenters']['names'] );
        $this->assertSame( array( 'Ann Writer', 'Bob Editor', 'Cid Reader' ), expStaticImportByline::people( $page['bylines'] ) );
        $this->assertSame( '{contributors:*}', $page['attributes']['contributors'] );
    }

    public function testCreatedAndLastModifiedByOneTakesTheFullerDate()
    {
        $page = $this->type->parse( $this->source, 'display/SPC/Byline-same.html' );
        $this->assertSame( 'Ann Writer', $page['bylines']['written']['name'] );
        $this->assertSame( 'Ann Writer', $page['bylines']['updated']['name'] );
        $this->assertSame( $this->time( 'May 27, 2014 07:44' ), $page['bylines']['updated']['time'] );
    }

    public function testLastModifiedWithoutANameCreditsTheWriter()
    {
        $page = $this->type->parse( $this->source, 'display/SPC/Byline-nameless.html' );
        $this->assertSame( 'Ann Writer', $page['bylines']['updated']['name'] );
        $this->assertSame( $this->time( 'Sep 13, 2016' ), $page['bylines']['updated']['time'] );
    }

    public function testCreatedOnADateByAnUnknownUser()
    {
        $page = $this->type->map( $this->type->parse( $this->source, 'display/SPC/Byline-created.html' ) );
        $this->assertSame( 'Unknown User (kore.nordmann)', $page['bylines']['written']['name'] );
        $this->assertSame( (string)$this->time( 'May 24, 2016' ), $page['attributes']['written_at'] );
        $this->assertArrayNotHasKey( 'updated', $page['bylines'] );
    }

    public function testARelativeDateCountsFromTheDayThePageWasSaved()
    {
        // saved Tue, 21 Oct 2014 08:54:32 GMT: "yesterday at 7:42 PM" is Oct 20, 2014 19:42 in the source's timezone
        $page = $this->type->parse( $this->source, 'display/SPC/Byline-relative.html' );
        $this->assertSame( 'Bob Editor', $page['bylines']['updated']['name'] );
        $this->assertSame( $this->time( 'Oct 20, 2014 19:42' ), $page['bylines']['updated']['time'] );
        $this->assertSame( array(), $page['byline_failures'] );
    }

    public function testAnUnknownVariantIsListedNeverDropped()
    {
        $page = $this->type->parse( $this->source, 'display/SPC/Byline-odd.html' );
        $this->assertSame( array( 'Edited somehow by Ann Writer at teatime' ), $page['byline_failures'] );
        $this->assertArrayNotHasKey( 'written', $page['bylines'] );
    }

    public function testConfluenceCommentsAndRepliesAreKeptWithAllTheirData()
    {
        $page = $this->type->parse( $this->source, 'display/SPC/Commented.html' );
        $this->assertCount( 1, $page['children'] );
        $comment = $this->type->map( $page['children'][0] );
        $this->assertSame( 'display/SPC/Commented.html#comment501', $comment['path'] );
        $this->assertSame( 'comment_historical', $comment['class'] );
        $this->assertSame( 'display/SPC/Commented.html', $comment['parent_path'] );
        $this->assertSame( 'Dee Reader', $comment['meta']['author'] );
        $this->assertSame( $this->time( 'Jan 16, 2017 16:15' ), $comment['meta']['modified'] );
        $this->assertStringContainsString( 'First remark.', $comment['body_html'] );
        // the original markup is the comment's own, without its replies
        $this->assertStringContainsString( 'id="comment-501"', $comment['original_html'] );
        $this->assertStringNotContainsString( 'A reply.', $comment['original_html'] );
        $this->assertSame( '{contributor:written}', $comment['attributes']['written_by'] );
        $reply = $this->type->map( $comment['children'][0] );
        $this->assertSame( 'display/SPC/Commented.html#comment501', $reply['parent_path'] );
        $this->assertSame( '501', $reply['meta']['parent_comment_id'] );
        $this->assertSame( 'Eve Answer', $reply['meta']['author'] );
    }
}
