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
        $this->type->configure( new expStaticImportRules( 'doc-html', array(
            'TitleSelector' => '#title-text, h1',
            'BodySelector' => '#main-content, body',
            'BylineDateFormats' => array( 'M d, Y H:i', 'M d, Y' ),
            'BylineTimezone' => 'Europe/Oslo',
            'BylineSentenceSelectors' => array( '.page-metadata-modification-info' ),
            'BylineSentences' => array(
                '/^Created and last modified by (?<written>.+?) on (?<updated_at>.+)$/u',
                '/^Created by (?<written>.+?), last modified by (?<updated>.+?) on (?<updated_at>.+)$/u',
                '/^Created by (?<written>.+?), last modified on (?<updated_at>.+)$/u',
                '/^Created by (?<written>.+?) on (?<written_at>.+)$/u',
            ),
            'BylineCommentAuthorSelectors' => array( '.comment-header .author' ),
            'DefaultClass' => 'exp_doc_page',
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

    public function testAnUnknownVariantIsListedNeverDropped()
    {
        $page = $this->type->parse( $this->source, 'display/SPC/Byline-odd.html' );
        $this->assertSame( array( 'Edited somehow by Ann Writer at teatime' ), $page['byline_failures'] );
        $this->assertArrayNotHasKey( 'written', $page['bylines'] );
    }
}
