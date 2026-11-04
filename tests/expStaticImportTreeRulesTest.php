<?php
/**
 * File containing the expStaticImportTreeRulesTest class.
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
 * Where pages go and what is no page, without a database, on a fixture of a flat export (tests/fixtures/flat-site):
 * Confluence-like spaces (display/<SPACE>/<page>.html) whose parents are only in the breadcrumb, a group page per
 * space, binary files and error pages with a page suffix, and the viewer page of one image.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportTreeRulesTest extends \PHPUnit\Framework\TestCase
{
    /** @var expStaticImportTypeDocHtml */
    protected $type;

    /** @var string */
    protected $source;

    /** @var array */
    protected $paths;

    protected function setUp(): void
    {
        $this->source = __DIR__ . '/fixtures/flat-site';
        $this->type = new expStaticImportTypeDocHtml();
        $this->type->configure( new expStaticImportRules( 'doc-html', array(
            'TitleSelector' => '#title-text, h1',
            'BodySelector' => '#main-content, body',
            'NotFoundTitlePatterns' => array( '/^404\b/', '/^kernel \(\d+\) \/ Error\b/' ),
            'RemoveSelectors' => array( '.pageinfo' ),
            'BreadcrumbSelector' => '#breadcrumbs a',
            'BreadcrumbPaths' => array( 'display/**' ),
            'GroupFolders' => array( 'display', 'display/*' ),
            'GroupTitles' => array( 'display' => 'Spaces' ),
            'GroupTitleSelector' => '#breadcrumbs li.first a',
            'GroupClass' => 'exp_doc_section',
            'AssetPages' => 'enabled', 'LinkHosts' => array( 'doc.example' ), 'NormalizeHeadings' => 'enabled',
            'DefaultClass' => 'exp_doc_page',
            'AttributeMap' => array( '*/title' => 'title', '*/body' => 'body' ),
        ) ) );
        $this->paths = $this->type->discover( $this->source );
        $this->type->setKnownPaths( $this->paths );
    }

    public function testAFolderWithoutAPageOfItsOwnGetsAGroupPage()
    {
        $this->assertContains( 'display/', $this->paths );
        $this->assertContains( 'display/SPC/', $this->paths );
        // a folder with its own page by name keeps that one
        $this->assertNotContains( 'display/OWN/', $this->paths );
        $this->assertSame( 'display/OWN.html', $this->type->parentPath( 'display/OWN/Page.html' ) );
        $this->assertSame( 'display/', $this->type->parentPath( 'display/OWN.html' ) );
        $this->assertSame( 'display/', $this->type->parentPath( 'display/SPC/' ) );
        $this->assertSame( '', $this->type->parentPath( 'display/' ) );
    }

    public function testTheGroupPageIsASectionNamedByTheBreadcrumbOrTheRules()
    {
        $space = $this->type->map( $this->type->parse( $this->source, 'display/SPC/' ) );
        $this->assertSame( 'Space One', $space['title'] );
        $this->assertSame( 'exp_doc_section', $space['class'] );
        $this->assertSame( 'display/', $space['parent_path'] );
        $top = $this->type->parse( $this->source, 'display/' );
        $this->assertSame( 'Spaces', $top['title'] );
    }

    public function testTheNearestBreadcrumbPageIsTheParent()
    {
        // the space's home (the first crumb) is not in the export: the next known crumb wins, else the group page
        $this->assertSame( 'display/SPC/', $this->type->parentPath( 'display/SPC/Releases.html' ) );
        $this->assertSame( 'display/SPC/Releases.html', $this->type->parentPath( 'display/SPC/1.html' ) );
        $this->assertSame( 'display/SPC/1.html', $this->type->parentPath( 'display/SPC/0.html' ) );
        $this->assertSame( 'display/SPC/1.html', $this->type->map( $this->type->parse( $this->source, 'display/SPC/0.html' ) )['parent_path'] );
    }

    public function testABreadcrumbCircleIsBroken()
    {
        $a = $this->type->parentPath( 'display/SPC/A.html' );
        $b = $this->type->parentPath( 'display/SPC/B.html' );
        $this->assertFalse( $a === 'display/SPC/B.html' && $b === 'display/SPC/A.html' );
        $this->assertContains( 'display/SPC/', array( $a, $b ) );
    }

    public function testEveryPageComesAfterItsParent()
    {
        $position = array_flip( $this->paths );
        foreach ( $this->paths as $path )
        {
            $parent = $this->type->parentPath( $path );
            if ( $parent !== '' )
                $this->assertLessThan( $position[$path], $position[$parent], "$parent before $path" );
        }
    }

    public function testBinaryFilesAndErrorPagesAreNoPages()
    {
        $this->assertNull( $this->type->parse( $this->source, 'display/SPC/fonts/icon-font.html' ) );
        $this->assertNull( $this->type->parse( $this->source, 'display/SPC/module-error.html' ) );
        $this->assertSame( 'not HTML', $this->type->notAPage( (string)file_get_contents( $this->source . '/display/SPC/fonts/icon-font.html' ) ) );
        $this->assertSame( 'an error page', $this->type->notAPage( (string)file_get_contents( $this->source . '/display/SPC/module-error.html' ) ) );
        $this->assertNull( $this->type->notAPage( (string)file_get_contents( $this->source . '/display/SPC/1.html' ) ) );
    }

    public function testTheViewerOfOneImageBecomesThatImage()
    {
        $page = $this->type->parse( $this->source, 'Manual/Images/Screen.html' );
        $this->assertSame( 'Manual/storage/screen.png', $page['asset_of'] );
        $this->assertSame( 'The screen.', $page['asset_meta']['alt'] );
        // a page with words of its own stays a page, image and all
        $described = $this->type->parse( $this->source, 'Manual/Images/Described.html' );
        $this->assertArrayNotHasKey( 'asset_of', $described );
        $this->assertSame( 'Shot', $described['title'] );
    }

    public function testEveryInternalLinkFormResolvesToAPath()
    {
        $links = $this->type->links( $this->type->parse( $this->source, 'Manual/Links.html' ) );
        // a folder without a suffix is the page of the folder
        $this->assertSame( 'Manual/Images.html', $links['Images'] );
        $this->assertSame( 'display/SPC/1.html', $links['../display/SPC/1.html'] );
        // a link by page id is resolved by the id
        $this->assertSame( 'page:100', $links['../pages/viewpage.action?pageId=100'] );
        // a full URL of the site itself (LinkHosts[]) is a path from the top; another site's is left alone
        $this->assertSame( 'Manual.html', $links['https://doc.example/Manual.html#top'] );
        $this->assertArrayNotHasKey( 'https://elsewhere.example/x.html', $links );
        $this->assertSame( 'Manual/storage/screen.png', $links['storage/screen.png'] );
    }

    public function testTopParentRulesKeepTheTopSmall()
    {
        $type = new expStaticImportTypeDocHtml();
        $type->configure( new expStaticImportRules( 'doc-html', array(
            'BreadcrumbSelector' => '#breadcrumbs a', 'BreadcrumbPaths' => array( 'display/**' ),
            'GroupFolders' => array( 'display', 'display/*' ),
            'TopParentRules' => array( 'Manual.html;', 'display/;', '**;Manual.html' ),
        ) ) );
        $type->setKnownPaths( $type->discover( $this->source ) );
        $this->assertSame( '', $type->parentPath( 'Manual.html' ) );
        $this->assertSame( '', $type->parentPath( 'display/' ) );
        // pages/ has no page of its own: its pages go below Manual.html, not to the top
        $this->assertSame( 'Manual.html', $type->parentPath( 'pages/viewpage00bb.html' ) );
        $this->assertSame( 'display/', $type->parentPath( 'display/SPC/' ) );
    }

    public function testTheHighestHeadingOfTheTextBecomesTheFirstLevel()
    {
        $page = $this->type->parse( $this->source, 'Manual.html' );
        $this->assertSame( 'Manual', $page['title'] );
        $this->assertStringContainsString( '<h1>Part</h1>', $page['body_html'] );
        $this->assertStringContainsString( '<h2>Sub</h2>', $page['body_html'] );
    }
}
