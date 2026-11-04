<?php
/**
 * File containing the expStaticImportContributorNamesTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once __DIR__ . '/../classes/expstaticimportrules.php';
require_once __DIR__ . '/../classes/expstaticimportdom.php';
require_once __DIR__ . '/../classes/expstaticimportbyline.php';

/**
 * A contributor is one person under every form the source writes the name in: a date or time glued to the name never
 * becomes part of it, character references are decoded, and known variants map to the person (ContributorAliases).
 * Without a database.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportContributorNamesTest extends \PHPUnit\Framework\TestCase
{
    protected function byline( array $aliases = array() )
    {
        return new expStaticImportByline( new expStaticImportRules( 'test', array(
            'BylineSelectors' => array( '.pageinfo' ),
            'BylineFields' => array( 'written' => 'Written by', 'updated' => 'Last updated by', 'maintainers' => 'Maintainers' ),
            'BylineListFields' => array( 'maintainers' ),
            'BylineDateFormats' => array( 'd/m/Y g:i a', 'M d, Y H:i', 'M d, Y' ),
            'BylineTimezone' => 'Europe/Oslo',
            'ContributorAliases' => $aliases,
        ) ) );
    }

    protected function time( $text )
    {
        return DateTime::createFromFormat( '!d/m/Y g:i a', $text, new DateTimeZone( 'Europe/Oslo' ) )->getTimestamp();
    }

    public function testADateWithoutItsClosingBracketIsReadAndLeavesTheName()
    {
        // the source of Exponential/Technical-manual/6.x/Installation.html
        $r = $this->byline()->parseLine( 'Last updated by', 'Graham Brookins (14/08/2024 9:50 pm' );
        $this->assertSame( array( 'field' => 'updated', 'value' => array( 'name' => 'Graham Brookins', 'time' => $this->time( '14/08/2024 9:50 pm' ) ) ), $r );
    }

    public function testEveryGluedDateVariantLeavesTheName()
    {
        $b = $this->byline();
        foreach ( array( 'Graham Brookins (14/08/2024 9:50 pm)', 'Graham Brookins (14/08/2024 9:50 pm', 'Graham Brookins 14/08/2024 9:50 pm',
                         'Graham Brookins, 14/08/2024 9:50 pm', 'Graham Brookins - 14/08/2024 9:50 pm', 'Graham Brookins [14/08/2024 9:50 pm]',
                         "Graham Brookins\xc2\xa0(14/08/2024 9:50 pm)", 'Graham Brookins (14/08/2024 09:50 pm)' ) as $value )
        {
            $r = $b->parseLine( 'Written by', $value );
            $this->assertSame( 'Graham Brookins', $r['value']['name'], $value );
            $this->assertSame( $this->time( '14/08/2024 9:50 pm' ), $r['value']['time'], $value );
        }
    }

    public function testCanonicalNamesNeverCarryADateOrATime()
    {
        $b = $this->byline();
        foreach ( array( 'Graham Brookins (14/08/2024 9:50 pm', 'Graham Brookins (14/08/2024', 'Graham Brookins 9:50 pm', 'Graham Brookins (9:50 pm)',
                         'Graham Brookins 2024-08-14', 'Graham Brookins (2024-08-14T21:50:00Z)', 'Graham Brookins on Oct 28, 2016',
                         'Graham Brookins, Oct 28, 2016 at 7:42 PM', 'Graham Brookins 28 Oct 2016', 'Graham Brookins yesterday at 7:42 PM',
                         '  Graham   Brookins  ' ) as $value )
            $this->assertSame( 'Graham Brookins', $b->canonicalName( $value ), $value );
    }

    public function testNamesThatOnlyLookLikeDatesStayNames()
    {
        $b = $this->byline();
        foreach ( array( 'andre1', 'roman204', 'Xc(Xiongjie Chen)', 'Unknown User (kore.nordmann)', 'Mark Simon (all2e gmbH)', 'jcp.bellagio', 'Paul (eZ)' ) as $name )
            $this->assertSame( $name, $b->canonicalName( $name ), $name );
        $this->assertSame( array( 'andre1', null ), expStaticImportByline::splitDateSuffix( 'andre1' ) );
        $this->assertSame( array( 'Ann', '14/08/2024 9:50 pm' ), expStaticImportByline::splitDateSuffix( 'Ann (14/08/2024 9:50 pm' ) );
    }

    public function testAnUnreadableGluedDateIsAFailureNotAName()
    {
        $this->assertNull( $this->byline()->parseLine( 'Written by', 'Ann Example (31/02/2010 10:22 am' ) );
    }

    public function testMaintainersListsLoseGluedDates()
    {
        $r = $this->byline()->parseLine( 'Maintainers', 'Graham Brookins (14/08/2024 9:50 pm, Geir Arne Waaler' );
        $this->assertSame( array( 'Graham Brookins', 'Geir Arne Waaler' ), $r['value']['names'] );
    }

    public function testCharacterReferencesAreDecoded()
    {
        $this->assertSame( 'Piotrek Karaś', $this->byline()->canonicalName( 'Piotrek Kara&#347;' ) );
        $this->assertSame( 'André R.', $this->byline()->canonicalName( 'Andr&eacute; R.' ) );
    }

    public function testKnownVariantsAreOnePerson()
    {
        $b = $this->byline( array( 'graham b' => 'Graham Brookins', 'Brookins Consulting' => 'Graham Brookins' ) );
        foreach ( array( 'Graham B.', 'graham b', 'GRAHAM  B', 'Graham B. (10/05/2024 06:15 pm)', 'Brookins Consulting' ) as $variant )
            $this->assertSame( 'Graham Brookins', $b->canonicalName( $variant ), $variant );
        $this->assertSame( 'Graham Bell', $b->canonicalName( 'Graham Bell' ) );
        $r = $b->parseLine( 'Written by', 'Graham B. (10/05/2024 06:15 pm)' );
        $this->assertSame( 'Graham Brookins', $r['value']['name'] );
        $this->assertSame( $this->time( '10/05/2024 6:15 pm' ), $r['value']['time'] );
        $this->assertSame( 'graham b', expStaticImportByline::aliasKey( ' Graham  B. ' ) );
    }

    public function testTheBylineBoxGivesOnePersonPerName()
    {
        $doc = expStaticImportDom::load( '<html><body><div id="b"><p>Text.</p><div class="pageinfo"><div class="created"><p><label>Written by:</label>'
            . 'Graham B. (10/05/2024 06:15 pm)</p></div><div class="modified"><p><label>Last updated by:</label>Graham Brookins (14/08/2024 9:50 pm</p>'
            . '</div><div class="authors"><p><label>Maintainers:</label>Graham Brookins, Geir Arne Waaler</p></div></div></div></body></html>' );
        $r = $this->byline( array( 'graham b' => 'Graham Brookins' ) )->extract( $doc, $doc->getElementById( 'b' ) );
        $this->assertSame( array(), $r['failures'] );
        $this->assertSame( array( 'Graham Brookins', 'Geir Arne Waaler' ), expStaticImportByline::people( $r['fields'] ) );
    }

    /** the four texts the documentation's earlier imports made contributors of */
    const JUNK = array( ': {{ ez_field_value( content', 'hand with one version per supported database', 're: change a package',
                        'eZ in collaboration with a global user base. The software is based on open standards' );

    public function testCodeSentencesAndSubjectsAreNoNames()
    {
        foreach ( self::JUNK as $text )
            $this->assertFalse( expStaticImportByline::isName( $text ), $text );
        foreach ( array( '{{ author }}', '<b>Ann</b>', '$author', 'a = b', 'Fw: hello', 'ez_field_value( content', ': Ann' ) as $text )
            $this->assertFalse( expStaticImportByline::isName( $text ), $text );
        // the comment's own subject in its author field
        $this->assertFalse( expStaticImportByline::isName( 'change a package', 'Re: change a package' ) );
        $this->assertTrue( expStaticImportByline::isName( 'Harry van Irsel', 'change a package' ) );
    }

    public function testRealNamesStayNames()
    {
        foreach ( array( 'Graham Brookins', 'André R.', 'Łukasz Serwatka', '//kracker', 'Unknown User (toby.schlitt)', 'Xc(Xiongjie Chen)',
                         'Mark Simon (all2e gmbH)', 'vera lucia alves capistrano', 'admin informazzurro.it', 'jcp.bellagio', 'José María de la Fuente',
                         'Paul (eZ)', 'Yannick\'s', 'xuefeng_lee', 'andre1', 'Brookins Consulting' ) as $name )
            $this->assertTrue( expStaticImportByline::isName( $name ), $name );
    }

    public function testNoParserPathMakesAPersonOfThem()
    {
        $b = new expStaticImportByline( new expStaticImportRules( 'test', array(
            'BylineSelectors' => array( '.pageinfo' ),
            'BylineFields' => array( 'written' => 'Written by', 'updated' => 'Last updated by', 'maintainers' => 'Maintainers' ),
            'BylineListFields' => array( 'maintainers' ),
            'BylineDateFormats' => array( 'd/m/Y g:i a', 'M d, Y' ),
            'BylineTimezone' => 'Europe/Oslo',
            'BylineSentenceSelectors' => array( 'li.page-metadata-modification-info', 'p.text' ),
            'BylineSentences' => array( '/^Created by (?<written>.+?) (?:on )?(?<written_at>[A-Z][a-z]{2} \d{1,2}, \d{4})$/u',
                                        '/created by (?<written>.+?), as this/u' ),
            'BylineCommentAuthorSelectors' => array( '#comments-content p.author' ),
        ) ) );
        // a labelled line, a sentence rule that matches the body text, and an author field holding the reply's subject
        $doc = expStaticImportDom::load( '<html><body><div id="b"><p class="text">The SQL statements should never be created by hand with one '
            . 'version per supported database, as this increases the load</p><div class="pageinfo"><p><label>Written by:</label>: {{ ez_field_value( content</p>'
            . '<p><label>Maintainers:</label>Ann Example, hand with one version per supported database</p></div></div>'
            . '<div id="comments-content"><ul><li><div class="class-comment"><h2>change a package</h2><p class="author">Harry van Irsel</p></div>'
            . '<ul><li><div class="class-comment"><h2>Re: change a package</h2><p class="author">re: change a package</p></div></li></ul></li></ul></div>'
            . '</body></html>' );
        $r = $b->extract( $doc, $doc->getElementById( 'b' ) );
        $people = expStaticImportByline::people( $r['fields'] );
        $this->assertSame( array( 'Harry van Irsel' ), $people );
        // nothing of the source is lost: what is no name is listed for review
        $this->assertCount( 3, $r['failures'] );
        $this->assertNull( $b->parseLine( 'Written by', 'hand with one version per supported database (14/09/2010 10:22 am)' ) );
    }
}
