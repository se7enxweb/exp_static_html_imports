<?php
/**
 * File containing the expStaticImportRouteRulesTest class.
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
 * RouteRules[]: Confluence's page history views and old versions (tests/fixtures/flat-site/pages) go into containers
 * below "Historical", one per current page, each with a name of its own and the current page's id, never as same-name
 * siblings in the tree; a copy of the current version is left alone (it stays a duplicate).
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportRouteRulesTest extends \PHPUnit\Framework\TestCase
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
            'BreadcrumbSelector' => '#breadcrumbs a', 'BreadcrumbPaths' => array( 'display/**', 'pages/**' ),
            'DuplicatePatterns' => array( '#^pages/#' ),
            'TitleSuffixPatterns' => array( '/\s*-\s*[^-]*-\s*eZ Documentation$/u' ),
            'RouteMeta' => array( 'page_id' => '/name="ajs-page-id" content="(\d+)"/', 'latest_id' => '/name="ajs-latest-page-id" content="(\d+)"/',
                                  'page_title' => '/name="ajs-page-title" content="([^"]*)"/', 'space' => '/name="ajs-space-key" content="([^"]*)"/',
                                  'versions' => '/<title>[^<]*\((v\.\d+ vs\.? v\.\d+)\)/' ),
            'RouteRules' => array(
                'pages/diffpagesbyversion*;historical/page-history/{latest_id|page_id}/;{page_title}: comparison of {versions|title};;{latest_id|page_id}',
                'pages/viewpreviousversions*;historical/page-history/{latest_id|page_id}/;{page_title}: page history;;{latest_id|page_id}',
                'pages/viewpage*;historical/page-history/{latest_id}/;{page_title}: old version (page id {page_id});latest_id!=page_id;{latest_id}',
            ),
            'RouteContainers' => array( 'historical' => 'exp_doc_historical;Historical', 'historical/page-history' => 'exp_doc_historical;Page History',
                                        'historical/page-history/*' => 'exp_doc_historical;{page_title}' ),
            'DefaultClass' => 'exp_doc_page',
            'AttributeMap' => array( '*/title' => 'title', '*/history_of' => 'history_of' ),
        ) ) );
        $this->paths = $this->type->discover( $this->source );
        $this->type->setKnownPaths( $this->paths );
    }

    public function testTheContainersAreNestedBelowHistorical()
    {
        foreach ( array( 'historical/', 'historical/page-history/', 'historical/page-history/100/' ) as $container )
            $this->assertContains( $container, $this->paths );
        $this->assertSame( '', $this->type->parentPath( 'historical/' ) );
        $this->assertSame( 'historical/', $this->type->parentPath( 'historical/page-history/' ) );
        $this->assertSame( 'historical/page-history/', $this->type->parentPath( 'historical/page-history/100/' ) );
        $page = $this->type->map( $this->type->parse( $this->source, 'historical/page-history/100/' ) );
        $this->assertSame( 'Current', $page['title'] );
        $this->assertSame( 'exp_doc_historical', $page['class'] );
        // the container of one page is a history of that page
        $this->assertSame( '{page:100}', $page['attributes']['history_of'] );
        $this->assertSame( 'Historical', $this->type->parse( $this->source, 'historical/' )['title'] );
    }

    public function testEachViewHasANameOfItsOwnAndItsCurrentPage()
    {
        $names = array();
        foreach ( array( 'pages/diffpagesbyversion00aa.html', 'pages/diffpagesbyversion00bb.html', 'pages/viewpreviousversions00aa.html', 'pages/viewpage00aa.html' ) as $path )
        {
            $page = $this->type->map( $this->type->parse( $this->source, $path ) );
            $this->assertSame( 'historical/page-history/100/', $page['parent_path'], $path );
            $this->assertSame( '{page:100}', $page['attributes']['history_of'], $path );
            $names[] = $page['title'];
        }
        $this->assertSame( array( 'Current: comparison of v.1 vs v.2', 'Current: comparison of v.2 vs v.3', 'Current: page history', 'Current: old version (page id 99)' ), $names );
    }

    public function testACopyOfTheCurrentVersionIsNoHistory()
    {
        $this->assertNull( $this->type->route( 'pages/viewpage00bb.html' ) );
        $this->assertNotSame( 0, strpos( $this->type->parentPath( 'pages/viewpage00bb.html' ), 'historical/' ) );
    }

    public function testTheCurrentPageIsKnownByItsPageId()
    {
        $this->assertSame( 'display/SPC/Current.html', $this->type->pathOfPageId( '100' ) );
        $this->assertSame( '', $this->type->pathOfPageId( '99' ) );
    }

    public function testPlaceholdersAndConditions()
    {
        $this->assertSame( 'b (x)', expStaticImportHtmlType::fill( '{a|b} ({c})', array( 'a' => '', 'b' => 'b', 'c' => 'x' ) ) );
        $this->assertTrue( expStaticImportHtmlType::condition( 'latest_id!=page_id', array( 'latest_id' => '100', 'page_id' => '99' ) ) );
        $this->assertFalse( expStaticImportHtmlType::condition( 'latest_id!=page_id', array( 'latest_id' => '100', 'page_id' => '100' ) ) );
    }
}
