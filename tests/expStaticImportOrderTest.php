<?php
/**
 * File containing the expStaticImportOrderTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

foreach ( array( 'expstaticimportdom', 'expstaticimportlinkresolver', 'expstaticimportorder' ) as $file )
    require_once __DIR__ . '/../classes/' . $file . '.php';

/**
 * The order of a container's children read from its own source page, without a database: the links of the table of
 * contents (and of a child list) that lead to the container's children, in the order they first appear, become the
 * priorities 10, 20, 30 ...; the children the page does not list follow by name; a container with children that are
 * not from the import, or whose page lists none of them, is left alone; comments keep their own order.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportOrderTest extends \PHPUnit\Framework\TestCase
{
    const PAGE = 'Exponential/Technical-manual/4.6/Concepts-and-basics.html';

    /** @var array source path => node id: the chapter (100), its children (101 ...), its siblings and grandchildren */
    protected $nodes = array(
        'Exponential/Technical-manual/4.6/Installation.html' => 50,
        'Exponential/Technical-manual/4.6/Templates.html' => 51,
        'Exponential/Technical-manual/4.6/Concepts-and-basics.html' => 100,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/The-internal-structure-of-Exponential.html' => 101,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Content-and-design.html' => 102,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Content-management.html' => 103,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Configuration.html' => 104,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Modules-and-views.html' => 105,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Access-control.html' => 106,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Workflows.html' => 107,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Webshop.html' => 108,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Glossary.html' => 109,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/The-internal-structure-of-Exponential/Directory-structure.html' => 201,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Content-management/Datatypes.html' => 202,
        'Exponential/Technical-manual/4.6/Concepts-and-basics/Content-management/The-content-class.html' => 203,
    );

    /**
     * @return array node ids in the order the fixture page lists them
     */
    protected function listed( array $selectors )
    {
        $nodes = $this->nodes;
        $resolver = new expStaticImportLinkResolver( array( 'page' => function ( $path ) use ( $nodes ) { return isset( $nodes[$path] ) ? $nodes[$path] : 0; } ) );
        $listed = array();
        foreach ( expStaticImportOrder::links( file_get_contents( __DIR__ . '/fixtures/order/Concepts-and-basics.html' ), $selectors ) as $href )
        {
            $link = $resolver->resolve( self::PAGE, $href );
            if ( $link['status'] === expStaticImportLinkResolver::NODE )
                $listed[] = (int)$link['node'];
        }
        return $listed;
    }

    /**
     * @return array the chapter's children as the database gives them: name, priority 0, class, from the import
     */
    protected function children( array $extra = array() )
    {
        $names = array( 101 => 'The internal structure of Exponential', 102 => 'Content and design', 103 => 'Content management', 104 => 'Configuration',
                        105 => 'Modules and views', 106 => 'Access control', 107 => 'Workflows', 108 => 'Webshop', 109 => 'Glossary' );
        $children = array();
        foreach ( $names as $node => $name )
            $children[$node] = array( 'name' => $name, 'priority' => 0, 'class' => 'exp_doc_page', 'imported' => true );
        return $extra + $children;
    }

    public function testTheTableOfContentsGivesTheLinksInDocumentOrderOnce()
    {
        $hrefs = expStaticImportOrder::links( file_get_contents( __DIR__ . '/fixtures/order/Concepts-and-basics.html' ), array( '#contentstructure' ) );
        $this->assertSame( 'Installation.html', $hrefs[0] );
        $this->assertSame( 'Concepts-and-basics.html', $hrefs[1] );
        $this->assertSame( 'Templates.html', end( $hrefs ) );
        $this->assertNotContains( '../4-2.html', $hrefs, 'the heading of the menu is outside the selector' );
        $this->assertSame( array(), expStaticImportOrder::links( '', array( '#contentstructure' ) ) );
        $this->assertSame( array(), expStaticImportOrder::links( '<p>x</p>', array() ) );
    }

    public function testTheChildrenTakeTheOrderOfTheMenuAndTheRestFollowByName()
    {
        $plan = expStaticImportOrder::plan( $this->listed( array( '#contentstructure' ) ), $this->children() );
        $this->assertSame( '', $plan['reason'] );
        $this->assertSame( 8, $plan['listed'] );
        // the menu's order, not the alphabet (and not the text, which links the webshop first); the siblings (50, 51)
        // and the grandchildren (201 ...) are no children; the glossary is not in the menu and comes last
        $this->assertSame( array( 101 => 10, 102 => 20, 103 => 30, 104 => 40, 105 => 50, 106 => 60, 107 => 70, 108 => 80, 109 => 90 ), $plan['priorities'] );
    }

    public function testASecondSelectorAddsWhatTheFirstDidNotList()
    {
        // the child list (#page-children) lists the glossary, then access control, which the menu listed already
        $plan = expStaticImportOrder::plan( $this->listed( array( '#contentstructure', '#page-children' ) ), $this->children() );
        $this->assertSame( 9, $plan['listed'] );
        $this->assertSame( 90, $plan['priorities'][109] );
        $this->assertSame( 60, $plan['priorities'][106] );
        // only the child list: its two pages first, the others by name
        $plan = expStaticImportOrder::plan( $this->listed( array( '#page-children' ) ), $this->children() );
        $this->assertSame( array( 109 => 10, 106 => 20, 104 => 30, 102 => 40, 103 => 50, 105 => 60, 101 => 70, 108 => 80, 107 => 90 ), $plan['priorities'] );
    }

    public function testUnlistedChildrenAreOrderedNaturallyAndCommentsKeepTheirPriority()
    {
        $children = $this->children( array(
            301 => array( 'name' => 'Page 10', 'priority' => 0, 'class' => 'exp_doc_page', 'imported' => true ),
            302 => array( 'name' => 'page 9', 'priority' => 0, 'class' => 'exp_doc_page', 'imported' => true ),
            400 => array( 'name' => 'A comment', 'priority' => 3, 'class' => 'comment_historical', 'imported' => false ),
        ) );
        $plan = expStaticImportOrder::plan( array( 101 ), $children, array( 'comment', 'comment_historical' ) );
        $this->assertSame( '', $plan['reason'] );
        $this->assertSame( 10, $plan['priorities'][101] );
        $this->assertArrayNotHasKey( 400, $plan['priorities'] );
        $this->assertLessThan( $plan['priorities'][301], $plan['priorities'][302], 'page 9 before Page 10, case-insensitive and natural' );
    }

    public function testAContainerIsLeftAloneWhenItsPageListsNoneOrItHoldsOtherContent()
    {
        $plan = expStaticImportOrder::plan( array( 50, 51, 201 ), $this->children() );
        $this->assertSame( 'the page lists none of its children', $plan['reason'] );
        $this->assertSame( array(), $plan['priorities'] );

        // a copy made in the CMS below the container: the order there is an editor's
        $children = $this->children( array( 500 => array( 'name' => 'A copy', 'priority' => 0, 'class' => 'exp_doc_page', 'imported' => false ) ) );
        $plan = expStaticImportOrder::plan( $this->listed( array( '#contentstructure' ) ), $children );
        $this->assertSame( 'children that are not from the import', $plan['reason'] );
    }

    public function testARunAgainChangesNothing()
    {
        $children = $this->children();
        $first = expStaticImportOrder::plan( $this->listed( array( '#contentstructure' ) ), $children );
        foreach ( $first['priorities'] as $node => $priority )
            $children[$node]['priority'] = $priority;
        $second = expStaticImportOrder::plan( $this->listed( array( '#contentstructure' ) ), $children, array(), 10 );
        $this->assertSame( '', $second['reason'] );
        $this->assertSame( array(), $second['priorities'] );
    }
}
