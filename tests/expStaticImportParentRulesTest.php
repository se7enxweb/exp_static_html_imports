<?php
/**
 * File containing the expStaticImportParentRulesTest class.
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
 * The parent mapping with placeholders, without a database: ParentRules[] whose parent is filled from the page's path
 * ({folder}, {file} ...) into containers RouteContainers[] makes (a version container per manual version, with its
 * version value), a parent outside the import ("@<remote id>"), NameRules[] (a manual's version page named after its
 * manual inside its version), GroupShortTitles[] (a space addressed by its key) and the containers a fixed ParentRules[]
 * parent needs.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportParentRulesTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array $rules
     * @param string $fixture
     * @return array( expStaticImportTypeDocHtml, paths )
     */
    protected function type( array $rules, $fixture )
    {
        $type = new expStaticImportTypeDocHtml();
        $type->configure( new expStaticImportRules( 'doc-html', $rules + array(
            'TitleSelector' => '#title-text, .attribute-heading h1, h1',
            'BodySelector' => '#main-content, .area-docmain-normal .content-view-full, body',
            'DefaultClass' => 'exp_doc_page',
            'AttributeMap' => array( '*/title' => 'title', '*/short_title' => 'short_title', '*/body' => 'body', 'exp_doc_version/version' => 'version',
                                     'exp_doc_version/status' => 'version_status' ),
        ) ) );
        $paths = $type->discover( __DIR__ . '/fixtures/' . $fixture );
        $type->setKnownPaths( $paths );
        return array( $type, $paths );
    }

    /**
     * @return array( expStaticImportTypeDocHtml, paths )
     */
    protected function versions()
    {
        return $this->type( array(
            'FolderPagePatterns' => array( 'Manual/[0-9]*.html' ),
            'GenericTitles' => array( 'Table of contents' ),
            'ClassRules' => array( 'Manual/[0-9]*.html;exp_doc_version' ),
            'ParentRules' => array( 'Manual/[0-9]*.html;Versions/{folder}/' ),
            'RouteContainers' => array( 'Versions' => 'exp_doc_section;All versions;versions', 'Versions/*' => 'exp_doc_version;{folder};{folder};version={folder}' ),
            'NameRules' => array( 'Manual/[0-9]*.html;Manual {folder};Manual' ),
            'VersionStatus' => array( '1*' => 'current' ),
        ), 'doc-site' );
    }

    public function testPathValues()
    {
        list( $type ) = $this->versions();
        $values = $type->pathValues( 'Manual/1-0.html' );
        $this->assertSame( '1-0', $values['file'] );
        $this->assertSame( 'Manual', $values['dir'] );
        $this->assertSame( '1.0', $values['folder'] );
        $this->assertSame( 'Manual', $values['seg1'] );
        // a folder keeps its dots: only a page suffix is cut
        $this->assertSame( '4.7', $type->pathValues( 'Exponential/4.7/' )['file'] );
        $this->assertNull( expStaticImportHtmlType::fillStrict( 'a/{none}/', array() ) );
        $this->assertSame( 'a/x/', expStaticImportHtmlType::fillStrict( 'a/{none|file}/', array( 'file' => 'x' ) ) );
    }

    public function testAVersionPageGoesIntoTheContainerOfItsVersion()
    {
        list( $type, $paths ) = $this->versions();
        $this->assertContains( 'Versions/', $paths );
        $this->assertContains( 'Versions/1.0/', $paths );
        $this->assertTrue( $type->isGroup( 'Versions/1.0/' ) );
        $this->assertSame( 'Versions/1.0/', $type->parentPath( 'Manual/1-0.html' ) );
        $this->assertSame( 'Versions/', $type->parentPath( 'Versions/1.0/' ) );
        $this->assertSame( '', $type->parentPath( 'Versions/' ) );
        // the pages of the version folder stay below the version page
        $this->assertSame( 'Manual/1-0.html', $type->parentPath( 'Manual/1.0/Chapter.html' ) );
        // the containers come before the pages below them
        $this->assertLessThan( array_search( 'Manual/1-0.html', $paths ), array_search( 'Versions/1.0/', $paths ) );
        $this->assertLessThan( array_search( 'Versions/1.0/', $paths ), array_search( 'Versions/', $paths ) );
    }

    public function testTheVersionContainerHasItsTitleAddressAndVersion()
    {
        list( $type ) = $this->versions();
        $page = $type->map( $type->parse( __DIR__ . '/fixtures/doc-site', 'Versions/1.0/' ) );
        $this->assertSame( '1.0', $page['title'] );
        $this->assertSame( 'exp_doc_version', $page['class'] );
        $this->assertSame( '1.0', $page['attributes']['short_title'] );
        $this->assertSame( '1.0', $page['attributes']['version'] );
        $this->assertSame( 'current', $page['attributes']['status'] );
        $top = $type->map( $type->parse( __DIR__ . '/fixtures/doc-site', 'Versions/' ) );
        $this->assertSame( 'All versions', $top['title'] );
        $this->assertSame( 'versions', $top['attributes']['short_title'] );
    }

    public function testNameRulesNameTheManualOfAVersion()
    {
        list( $type ) = $this->versions();
        $page = $type->map( $type->parse( __DIR__ . '/fixtures/doc-site', 'Manual/1-0.html' ) );
        $this->assertSame( 'Manual 1.0', $page['title'] );
        $this->assertSame( 'Manual', $page['attributes']['short_title'] );
        $this->assertSame( '1.0', $page['attributes']['version'] );
        $this->assertSame( array( 'Manual 1.0', 'Manual' ), $type->nameRule( 'Manual/1-0.html' ) );
        $this->assertNull( $type->nameRule( 'Manual.html' ) );
    }

    public function testAParentOutsideTheImportByItsRemoteID()
    {
        list( $type ) = $this->versions();
        list( $other ) = $this->type( array( 'ParentRules' => array( 'Manual/**;@exp-doc-media' ) ), 'doc-site' );
        $this->assertSame( '@exp-doc-media', $other->parentPath( 'Manual/1.0/Chapter/Page.html' ) );
        // a node outside the import is a top of its own
        $this->assertSame( '', $other->parentPath( '@exp-doc-media' ) );
    }

    public function testSpacesBelowAContainerAddressedByTheirKey()
    {
        list( $type, $paths ) = $this->type( array(
            'BreadcrumbSelector' => '#breadcrumbs a',
            'BreadcrumbPaths' => array( 'display/**' ),
            'GroupFolders' => array( 'display', 'display/*' ),
            'GroupTitleSelector' => '#breadcrumbs li.first a',
            'GroupShortTitles' => array( 'display/*' => '{file}' ),
            'GroupClass' => 'exp_doc_section',
            'VirtualPages' => array( 'display/confluence/' => 'exp_doc_section;Confluence;Confluence', 'display/OWN/' => 'exp_doc_section;Own space;OWN' ),
            'ParentRules' => array( 'display/confluence/;display/', 'display/*/;display/confluence/', 'display/*.html;display/{file}/',
                                    'pages/viewpage*;display/confluence/pages/copies/' ),
            'RouteContainers' => array( 'display/confluence/pages' => 'exp_doc_historical;Pages', 'display/confluence/pages/copies' => 'exp_doc_historical;Copies' ),
        ), 'flat-site' );
        $this->assertSame( 'display/confluence/', $type->parentPath( 'display/SPC/' ) );
        $this->assertSame( 'display/', $type->parentPath( 'display/confluence/' ) );
        $space = $type->map( $type->parse( __DIR__ . '/fixtures/flat-site', 'display/SPC/' ) );
        $this->assertSame( 'Space One', $space['title'] );
        $this->assertSame( 'SPC', $space['attributes']['short_title'] );
        // a space with a page of its own: the page inside the space's section
        $this->assertSame( 'display/OWN/', $type->parentPath( 'display/OWN.html' ) );
        $this->assertSame( 'display/confluence/', $type->parentPath( 'display/OWN/' ) );
        // a fixed parent that is a container of RouteContainers[] is made
        $this->assertContains( 'display/confluence/pages/copies/', $paths );
        $this->assertSame( 'display/confluence/pages/copies/', $type->parentPath( 'pages/viewpage00aa.html' ) );
        $this->assertSame( 'display/confluence/pages/', $type->parentPath( 'display/confluence/pages/copies/' ) );
    }

    public function testAFolderIndexWithTheSameTextIsACopyOfThePageNextToItsFolder()
    {
        list( $type, $paths ) = $this->type( array( 'FolderIndexCopies' => 'enabled' ), 'index-copies' );
        $this->assertSame( 'Guide.html', $type->copyOf( 'Guide/index.html' ) );
        $this->assertSame( '', $type->copyOf( 'Other/index.html' ) );
        $this->assertSame( '', $type->copyOf( 'Guide.html' ) );
        $page = $type->parse( __DIR__ . '/fixtures/index-copies', 'Guide/index.html' );
        $this->assertSame( 'Guide.html', $page['redirect_to'] );
        $pairs = $type->folderIndexPairs( $paths );
        $this->assertTrue( $pairs['Guide/index.html']['same'] );
        $this->assertFalse( $pairs['Other/index.html']['same'] );
        // off unless the rule says so
        list( $off ) = $this->type( array(), 'index-copies' );
        $this->assertSame( '', $off->copyOf( 'Guide/index.html' ) );
    }
}
