<?php
/**
 * File containing the expStaticImportBylineTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once __DIR__ . '/../classes/expstaticimportrules.php';
require_once __DIR__ . '/../classes/expstaticimportdom.php';
require_once __DIR__ . '/../classes/expstaticimportbyline.php';

/**
 * Byline extraction without a database: every markup and date variant of the documentation's byline box.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportBylineTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @return expStaticImportByline
     */
    protected function byline( $zone = 'Europe/Oslo' )
    {
        return new expStaticImportByline( new expStaticImportRules( 'test', array(
            'BylineSelectors' => array( '.pageinfo' ),
            'BylineFields' => array( 'written' => 'Written by', 'updated' => 'Last updated by', 'maintainers' => 'Maintainers' ),
            'BylineListFields' => array( 'maintainers' ),
            'BylineDateFormats' => array( 'd/m/Y g:i a' ),
            'BylineTimezone' => $zone,
        ) ) );
    }

    protected function extract( $inner )
    {
        $doc = expStaticImportDom::load( '<html><body><div id="b"><p>Text before.</p>' . $inner . '<p>Text after.</p></div></body></html>' );
        $body = $doc->getElementById( 'b' );
        $result = $this->byline()->extract( $doc, $body );
        $result['body'] = expStaticImportDom::innerHtml( $body );
        return $result;
    }

    protected function time( $text, $zone = 'Europe/Oslo' )
    {
        return DateTime::createFromFormat( '!d/m/Y g:i a', $text, new DateTimeZone( $zone ) )->getTimestamp();
    }

    public function testTheFullBoxIsReadAndRemoved()
    {
        $r = $this->extract( '<div class="break"></div><div class="pageinfo float-break"><div class="created">'
            . '<p><label>Written by:</label>Balazs Halasy&nbsp;(14/09/2010 10:22 am)</p></div><div class="modified">'
            . '<p><label>Last updated by:</label>Geir Arne Waaler&nbsp;(28/09/2010 9:12 am)</p></div><div class="authors">'
            . '<p><label>Maintainers:</label>Andr&eacute; R., Andrea Melo, Ricardo Correia</p></div></div>' );
        $this->assertSame( 'Balazs Halasy', $r['fields']['written']['name'] );
        $this->assertSame( $this->time( '14/09/2010 10:22 am' ), $r['fields']['written']['time'] );
        $this->assertSame( 'Geir Arne Waaler', $r['fields']['updated']['name'] );
        $this->assertSame( $this->time( '28/09/2010 9:12 am' ), $r['fields']['updated']['time'] );
        $this->assertSame( array( 'André R.', 'Andrea Melo', 'Ricardo Correia' ), $r['fields']['maintainers']['names'] );
        $this->assertSame( array(), $r['failures'] );
        $this->assertStringNotContainsString( 'Written by', $r['body'] );
        $this->assertStringNotContainsString( 'pageinfo', $r['body'] );
        $this->assertStringContainsString( 'Text before.', $r['body'] );
        $this->assertStringContainsString( 'Text after.', $r['body'] );
    }

    public function testHoursWithOneAndTwoDigitsAndAfternoon()
    {
        $b = $this->byline();
        foreach ( array( '05/11/2013 3:09 pm', '20/01/2012 12:15 pm', '01/02/2011 12:05 am', '30/06/2009 11:59 am' ) as $date )
            $this->assertSame( $this->time( $date ), $b->parseDate( $date ), $date );
        $this->assertSame( 15, (int)( new DateTime( '@' . $b->parseDate( '05/11/2013 3:09 pm' ) ) )->setTimezone( new DateTimeZone( 'Europe/Oslo' ) )->format( 'G' ) );
    }

    public function testTheTimezoneOfTheSourceIsUsed()
    {
        $oslo = $this->byline( 'Europe/Oslo' )->parseDate( '14/09/2010 10:22 am' );
        $utc = $this->byline( 'UTC' )->parseDate( '14/09/2010 10:22 am' );
        $this->assertSame( 7200, $utc - $oslo ); // summer time in Oslo: UTC+2
    }

    public function testANameWithoutADate()
    {
        $r = $this->byline()->parseLine( 'Written by', 'Ricardo Correia' );
        $this->assertSame( array( 'field' => 'written', 'value' => array( 'name' => 'Ricardo Correia', 'time' => null ) ), $r );
    }

    public function testPlainTextLinesWithoutLabelElements()
    {
        $r = $this->extract( '<div class="pageinfo">Written by: Ann Example (14/09/2010 10:22 am)<br/>Maintainers: B, C</div>' );
        $this->assertSame( 'Ann Example', $r['fields']['written']['name'] );
        $this->assertSame( array( 'B', 'C' ), $r['fields']['maintainers']['names'] );
    }

    public function testAnUnreadableDateIsAFailureForReview()
    {
        $r = $this->extract( '<div class="pageinfo"><p><label>Written by:</label>Someone&nbsp;(sometime in 2010)</p></div>' );
        $this->assertArrayNotHasKey( 'written', $r['fields'] );
        $this->assertCount( 1, $r['failures'] );
        $this->assertStringContainsString( 'sometime in 2010', $r['failures'][0] );
        $this->assertStringNotContainsString( 'Someone', $r['body'] );
    }

    public function testAnUnknownLabelIsNoField()
    {
        $this->assertNull( $this->byline()->parseLine( 'Reviewed by', 'Someone (14/09/2010 10:22 am)' ) );
        $this->assertNull( $this->byline()->parseLine( 'Written by', '' ) );
    }

    public function testInvalidCalendarDatesAreRefused()
    {
        $this->assertNull( $this->byline()->parseDate( '31/02/2010 10:22 am' ) );
        $this->assertNull( $this->byline()->parseDate( '14/13/2010 10:22 am' ) );
    }

    public function testNamesAreNormalisedForIdentity()
    {
        $this->assertSame( 'geir arne waaler', expStaticImportByline::normalizeName( "  Geir  Arne\tWaaler " ) );
        $this->assertSame( expStaticImportByline::normalizeName( 'André R.' ), expStaticImportByline::normalizeName( 'ANDRÉ R.' ) );
    }

    public function testAPageWithoutABylineIsLeftAlone()
    {
        $r = $this->extract( '' );
        $this->assertSame( array(), $r['fields'] );
        $this->assertStringContainsString( 'Text before.', $r['body'] );
    }
}
