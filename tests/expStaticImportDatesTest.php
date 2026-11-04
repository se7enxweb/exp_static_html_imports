<?php
/**
 * File containing the expStaticImportDatesTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once __DIR__ . '/../classes/expstaticimportrules.php';
require_once __DIR__ . '/../classes/expstaticimportdates.php';

/**
 * The date rule chain (DateRules[]) without a database: the byline values first, then the git history (its index
 * parser, bulk commits, renamed folders), the meta date tags and the file time; today only when nothing gives a date.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportDatesTest extends \PHPUnit\Framework\TestCase
{
    const NOW = 1793750400; // 2026-11-04

    /** @var string */
    protected $dir;

    protected function setUp(): void
    {
        $this->dir = dirname( __DIR__ ) . '/tests/fixtures/dates-site';
    }

    protected function chain()
    {
        $rules = array(
            // the 6.x pages carry the byline of the 4.x page they were copied from: their own dates are git's
            'created;git;first;git;Exponential/6.x/**',
            'created;attribute;written_at;byline',
            'created;attribute;created;comment',
            'created;git;first',
            'created;meta;dcterms.created,article:published_time',
            'created;mtime',
            'created;children',
            'modified;git;last;git;Exponential/6.x/**',
            'modified;attribute;last_updated_at;byline',
            'modified;attribute;created;comment',
            'modified;attribute;written_at;byline',
            'modified;git;last,nobulk',
            'modified;meta;dcterms.modified,article:modified_time,confluence-request-time',
            'modified;mtime',
            'modified;children',
        );
        $dates = new expStaticImportDates( new expStaticImportRules( 'test', array(
            'DateRules' => $rules, 'DateTimezone' => 'Europe/Oslo', 'DateGitBulkCommitFiles' => '2',
            'DateGitPathAliases' => array( '#^Exponential/#;eZ-Publish/' ) ) ), $this->dir, '', self::NOW );
        $dates->setGitIndex( expStaticImportDates::parseGitLog( explode( "\n", $this->gitLog() ), 2 ) );
        return $dates;
    }

    /**
     * Newest commit first, as `git log --name-only --format=@%at` prints it. With DateGitBulkCommitFiles=2 the first
     * and the last commit are bulk commits (a site-wide rewrite and the initial import); the second renames a folder.
     */
    protected function gitLog()
    {
        return "@1756448903\n\nExponential/6.x/install.html\ndisplay/EZP/page.html\nimages/logo.png\n\n"   // 2025-08-29, bulk
             . "@1755107585\n\nExponential/6.x/install.html\neZ-Publish/6.x/install.html\n\n"              // 2025-08-13, the rename
             . "@1715299200\n\neZ-Publish/6.x/install.html\n\n"                                              // 2024-05-10
             . "@1700000000\n\ndisplay/EZP/page.html\n\n"                                                    // 2023-11-14
             . "@1486472236\n\ndisplay/EZP/page.html\nimages/logo.png\nold/gone.html\n";                     // 2017-02-07, bulk
    }

    protected function values( array $values )
    {
        return function ( $identifier ) use ( $values ) { return isset( $values[$identifier] ) ? $values[$identifier] : null; };
    }

    protected function brief( array $dates )
    {
        return array( $dates['created'], $dates['modified'], $dates['created_source'], $dates['modified_source'] );
    }

    public function testGitLogParserKeepsFirstLastAndLastNonBulkCommitPerFile()
    {
        $index = expStaticImportDates::parseGitLog( explode( "\n", $this->gitLog() ), 2 );
        $this->assertSame( array( 'first' => 1755107585, 'last' => 1756448903, 'last_nobulk' => 1755107585 ), $index['Exponential/6.x/install.html'] );
        $this->assertSame( array( 'first' => 1715299200, 'last' => 1755107585, 'last_nobulk' => 1755107585 ), $index['eZ-Publish/6.x/install.html'] );
        $this->assertSame( array( 'first' => 1486472236, 'last' => 1756448903, 'last_nobulk' => 1700000000 ), $index['display/EZP/page.html'] );
        $this->assertSame( array( 'first' => 1486472236, 'last' => 1756448903, 'last_nobulk' => null ), $index['images/logo.png'] );
        $this->assertCount( 5, $index );
        // with the default threshold (1000 files) no commit is a bulk commit
        $index = expStaticImportDates::parseGitLog( explode( "\n", $this->gitLog() ) );
        $this->assertSame( 1756448903, $index['images/logo.png']['last_nobulk'] );
    }

    public function testGitLogParserIgnoresFilesBeforeTheFirstCommitAndOutOfOrderHistory()
    {
        $index = expStaticImportDates::parseGitLog( array( "stray.html\n", "@200\n", "a.html\n", "@300\n", "a.html\n", "@100\n", "a.html\n" ) );
        $this->assertArrayNotHasKey( 'stray.html', $index );
        $this->assertSame( array( 'first' => 100, 'last' => 300, 'last_nobulk' => 300 ), $index['a.html'] );
    }

    public function testBylineWinsOverGit()
    {
        $dates = $this->chain()->resolve( 'display/EZP/page.html', $this->values( array( 'written_at' => '1262304000', 'last_updated_at' => '1325376000' ) ) );
        $this->assertSame( array( 1262304000, 1325376000, 'byline', 'byline' ), $this->brief( $dates ) );
    }

    public function testPathRuleAndRenamedFolderGiveACopiedPageItsOwnHistory()
    {
        // the copied 4.x byline loses against the rule for the 6.x paths; the first commit is the one under the old
        // folder name (eZ-Publish/), not the rename; the last change counts bulk commits too (no nobulk)
        $dates = $this->chain()->resolve( 'Exponential/6.x/install.html', $this->values( array( 'written_at' => '1262304000', 'last_updated_at' => '1325376000' ) ) );
        $this->assertSame( array( 1715299200, 1756448903, 'git', 'git' ), $this->brief( $dates ) );
    }

    public function testCreatedOnlyBylineMeansNeverChangedAfterwards()
    {
        $dates = $this->chain()->resolve( 'display/EZP/page.html', $this->values( array( 'written_at' => 1262304000 ) ) );
        $this->assertSame( array( 1262304000, 1262304000, 'byline', 'byline' ), $this->brief( $dates ) );
    }

    public function testModifiedOnlyBylineClampsTheGitCreationDate()
    {
        // Confluence's "Created by A, last modified on <date>": the git history starts years after the change
        $dates = $this->chain()->resolve( 'display/EZP/page.html', $this->values( array( 'last_updated_at' => 1325376000 ) ) );
        $this->assertSame( array( 1325376000, 1325376000, 'byline', 'byline' ), $this->brief( $dates ) );
    }

    public function testCommentTakesItsOwnDate()
    {
        $dates = $this->chain()->resolve( 'display/EZP/page.html#comment12', $this->values( array( 'created' => '1300000000' ) ) );
        $this->assertSame( array( 1300000000, 1300000000, 'comment', 'comment' ), $this->brief( $dates ) );
    }

    public function testGitWithoutBylineLeavesBulkCommitsOutOfTheLastChange()
    {
        $this->assertSame( array( 1486472236, 1700000000, 'git', 'git' ), $this->brief( $this->chain()->resolve( 'display/EZP/page.html', $this->values( array() ) ) ) );
        // only bulk commits after the first: never changed on its own
        $this->assertSame( array( 1486472236, 1486472236, 'git', 'git' ), $this->brief( $this->chain()->resolve( 'images/logo.png' ) ) );
        // a comment without a date of its own reads its page's file
        $this->assertSame( 'git', $this->chain()->resolve( 'display/EZP/page.html#comment3' )['created_source'] );
    }

    public function testMetaTagsWhenGitDoesNotKnowTheFile()
    {
        $dates = $this->chain()->resolve( 'meta.html' );
        $this->assertSame( 'meta', $dates['created_source'] );
        $this->assertSame( ( new DateTime( '2013-05-06T10:00:00+02:00' ) )->getTimestamp(), $dates['created'] );
        $this->assertSame( ( new DateTime( '2015-01-02 08:00', new DateTimeZone( 'Europe/Oslo' ) ) )->getTimestamp(), $dates['modified'] );
    }

    public function testMetaDatesParser()
    {
        $html = '<head><meta name="confluence-request-time" content="1485851880623"><meta property="article:published_time" content="2014-03-01">'
              . "<meta content='2016-07-08T09:10:11Z' name='DCTERMS.modified'><meta name=\"robots\" content=\"index\"></head>";
        $found = expStaticImportDates::metaDates( $html, array( 'dcterms.modified', 'confluence-request-time', 'article:published_time', 'robots' ), 'UTC' );
        $this->assertSame( array( 'dcterms.modified' => 1467969011, 'confluence-request-time' => 1485851880, 'article:published_time' => 1393632000 ), $found );
    }

    public function testFileTimeOnlyWhenOlderThanTheClone()
    {
        $file = $this->dir . '/mtime.html';
        touch( $file, 1400000000 );
        $this->assertSame( array( 1400000000, 'mtime' ), array_slice( array_values( array_intersect_key( $this->chain()->resolve( 'mtime.html' ), array( 'created' => 1, 'created_source' => 1 ) ) ), 0, 2 ) );
        // the time of the clone (or later) is no date of the page
        touch( $file, time() );
        $this->assertSame( expStaticImportDates::TODAY, $this->chain()->resolve( 'mtime.html' )['created_source'] );
        touch( $file, 1400000000 );
    }

    public function testChildrenThenToday()
    {
        $dates = $this->chain()->resolve( 'historical/page-history/x/', null, array( 'created' => 1200000000, 'modified' => 1400000000 ) );
        $this->assertSame( array( 1200000000, 1400000000, 'children', 'children' ), $this->brief( $dates ) );
        $this->assertSame( array( self::NOW, self::NOW, 'today', 'today' ), $this->brief( $this->chain()->resolve( 'historical/page-history/y/' ) ) );
    }

    public function testRelationGivesAnEmptyContainerTheDatesOfThePageItBelongsTo()
    {
        $dates = new expStaticImportDates( new expStaticImportRules( 'test', array( 'DateRules' => array(
            'created;children', 'created;relation;history_of', 'modified;children', 'modified;relation;history_of' ) ) ), '', '', self::NOW );
        $related = function ( $identifier ) { return $identifier === 'history_of' ? array( 1262304000, 1325376000 ) : null; };
        $this->assertSame( array( 1262304000, 1325376000, 'relation', 'relation' ), $this->brief( $dates->resolve( 'historical/page-history/x/', null, null, $related ) ) );
        // its children, once it has them, come first
        $this->assertSame( 'children', $dates->resolve( 'historical/page-history/x/', null, array( 'created' => 1300000000, 'modified' => 1400000000 ), $related )['created_source'] );
    }

    public function testRulesParserAndDefaultChain()
    {
        $chain = expStaticImportDates::parseRules( array( 'created;attribute;written_at, created;byline', 'bogus;git', 'modified;nosuch',
                                                          'modified;git;last,nobulk;;a/**, b/*.html' ) );
        $this->assertSame( array( array( 'field' => 'created', 'source' => 'attribute', 'args' => array( 'written_at', 'created' ), 'label' => 'byline', 'paths' => array() ),
                                  array( 'field' => 'modified', 'source' => 'git', 'args' => array( 'last', 'nobulk' ), 'label' => 'git', 'paths' => array( 'a/**', 'b/*.html' ) ) ),
                           $chain );
        $default = new expStaticImportDates( new expStaticImportRules( 'test', array() ) );
        $this->assertTrue( $default->uses( 'git' ) );
        $this->assertFalse( $default->uses( 'attribute' ) );
        $this->assertTrue( expStaticImportDates::matchesAny( 'a/b/c.html', array( 'x/*', 'a/**' ) ) );
        $this->assertFalse( expStaticImportDates::matchesAny( 'b/c/d.html', array( 'b/*.html' ) ) );
    }

    public function testInvalidValuesAreSkipped()
    {
        $this->assertNull( expStaticImportDates::validTime( '' ) );
        $this->assertNull( expStaticImportDates::validTime( 0 ) );
        $this->assertNull( expStaticImportDates::validTime( 'not a date' ) );
        $this->assertNull( expStaticImportDates::validTime( time() + 10 * 86400 ) );
        $dates = $this->chain()->resolve( 'display/EZP/page.html', $this->values( array( 'written_at' => '0' ) ) );
        $this->assertSame( 'git', $dates['created_source'] );
    }
}
