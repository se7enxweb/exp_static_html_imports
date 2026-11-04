<?php
/**
 * File containing the expStaticImportLinkResolverTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once __DIR__ . '/../classes/expstaticimportlinkresolver.php';

/**
 * The link resolver, without a database: every link form the documentation export holds (relative and root-relative
 * paths, anchors, queries, page ids, Confluence titles with encoded "+", HTTrack's renamed files and their original
 * addresses, attachments and images, login pages that forward, the old site's full URLs) resolves to the node it
 * stands for, or is reported with a reason; mailto, anchors, external links and placeholders are left alone.
 */
#[\PHPUnit\Framework\Attributes\Group( 'exp_static_html_imports' )]
class expStaticImportLinkResolverTest extends \PHPUnit\Framework\TestCase
{
    /** @var expStaticImportLinkResolver */
    protected $resolver;

    /** @var array source path => node */
    protected $pages = array(
        'Exponential.html' => 10,
        'Exponential/Technical-manual/4.html' => 47,
        'Exponential/Technical-manual/6.html' => 50,
        'Exponential/Technical-manual/4.7/Features/Clustering.html' => 4701,
        'Exponential/Technical-manual/4.x/Reference/Content-classes/Users/User.html' => 4801,
        'Exponential/Upgrading.html' => 60,
        'Extensions/eZ-Find/index.html' => 70,
        'display/DEVELOPER/SiteAccess.html' => 200,
        'display/DEVELOPER/Content+Rendering.html' => 201,
        'display/EZP/Requirements+5.html' => 202,
        'display/EZMA/Configuring+eZ+Marketing+Automation52f5.html' => 203,
        'display/USER/Getting+Started.html' => 204,
        'doc_hidden/Standing-on-the-shoulders-of-giants.html' => 90,
        'pages/viewpage414d.html' => 300,
        'display/USER/eZ+Platform+Enterprise+Edition.html' => 205,
    );

    protected function setUp(): void
    {
        $pages = $this->pages;
        $lower = array();
        foreach ( array_keys( $pages ) as $path )
            $lower[strtolower( $path )] = $path;
        $this->resolver = new expStaticImportLinkResolver( array(
            'page' => function ( $path ) use ( $pages ) { return isset( $pages[$path] ) ? $pages[$path] : 0; },
            'page_id' => function ( $id ) { return $id === '31429536' ? 555 : 0; },
            'asset' => function ( $path ) {
                $assets = array( 'download/attachments/31429510/get started6b3d.png' => array( 'object_id' => 9001, 'node_id' => 9002 ),
                                 'var/doc/storage/images/a/b/diagram.png' => array( 'object_id' => 9101, 'node_id' => 9102 ),
                                 'var/doc/storage/generated/manual.zip' => array( 'object_id' => 9201, 'node_id' => 9202 ) );
                return isset( $assets[$path] ) ? $assets[$path] : null;
            },
            'caseless' => function ( $path ) use ( $lower ) { return isset( $lower[strtolower( $path )] ) ? $lower[strtolower( $path )] : null; },
            'hosts' => array( 'doc.ez.no', 'exponential.doc.exponential.earth' ),
            'soft_hosts' => array( 'ez.no', 'share.ez.no' ),
            'addresses' => array(
                'doc.ez.no/Exponential/Technical-manual/4.7' => 'Exponential/Technical-manual/4.html',
                'doc.ez.no/Exponential/Technical-manual/5.x' => 'Exponential/Technical-manual/6.html',
                'doc.ez.no/display/USER/Documentation' => 'display/USER.html',
                'doc.ez.no/login.action?os_destination=%2Fpages%2Fviewpage.action%3FpageId%3D31429536' => 'login47b7.html',
                'doc.ez.no/login.action?os_destination=%2Fusers%2Fviewuserprofile.action%3Fusername%3Dsomeone&permissionViolation=true' => 'loginee65.html',
                'doc.ez.no/download/attachments/31429510/get started.png?version=1' => 'download/attachments/31429510/get started6b3d.png',
            ),
        ) );
    }

    /**
     * @return array href, page it is on, expected status, expected node, expected anchor
     */
    public static function links()
    {
        return array(
            'relative .html' => array( 'Exponential/Technical-manual/6.html', 'Exponential.html', 'node', 50, '' ),
            'relative ../ .html' => array( '../../4.x/Reference/Content-classes/Users/User.html', 'Exponential/Technical-manual/4.7/Concepts-and-basics/Access-control.html', 'node', 4801, '' ),
            'relative with anchor' => array( '../Features/Clustering.html#ini_settings', 'Exponential/Technical-manual/4.7/Concepts-and-basics/URL-translation.html', 'node', 4701, 'ini_settings' ),
            'root-relative source path' => array( '/doc_hidden/Standing-on-the-shoulders-of-giants.html', 'License.html', 'node', 90, '' ),
            'root-relative original address (HTTrack renamed the file)' => array( '/Exponential/Technical-manual/4.7', 'Exponential.html', 'node', 47, '' ),
            'absolute doc.ez.no original address with anchor' => array( 'http://doc.ez.no/Exponential/Technical-manual/5.x#top', 'FAQ.html', 'node', 50, 'top' ),
            'absolute doc.ez.no Confluence title' => array( 'https://doc.ez.no/display/DEVELOPER/SiteAccess', 'pages/diffpagesbyversion0019.html', 'node', 200, '' ),
            'absolute doc.ez.no title with + and anchor' => array( 'https://doc.ez.no/display/DEVELOPER/Content+Rendering#ContentRendering-x', 'pages/a.html', 'node', 201, 'ContentRendering-x' ),
            'absolute static site path' => array( 'https://exponential.doc.exponential.earth/Exponential.html', 'index.html', 'node', 10, '' ),
            'by pageId (viewpage file with query)' => array( '../../pages/viewpage414d.html?pageId=31429536', 'display/DEVELOPER/Clustering.html', 'node', 555, '' ),
            'by pageId (viewpage.action, entities)' => array( '/pages/viewpage.action?spaceKey=EZP&amp;pageId=31429536', 'display/EZP/x.html', 'node', 555, '' ),
            'unknown pageId falls back to the file' => array( '../../pages/viewpage414d.html?pageId=1', 'display/EZP/x.html', 'node', 300, '' ),
            '%2b-encoded Confluence title' => array( '../../../../../display/EZP/Requirements%2b5.html', 'Exponential/Technical-manual/5.x/Features/Clustering/Cluster-File-Handlers.html', 'node', 202, '' ),
            'HTTrack hashed file with a query' => array( 'Configuring%2beZ%2bMarketing%2bAutomation52f5.html?preview=%2F8323629%2F8519898%2FScreen+S', 'display/EZMA/eZ+Marketing+Automation+documentation.html', 'node', 203, '' ),
            'file renamed after mirroring: relative to its original address' => array( 'eZ%2bPlatform%2bEnterprise%2bEdition.html', 'display/USER.html', 'node', 205, '' ),
            'root-relative Confluence title' => array( '/display/USER/Getting+Started.html', 'display/USER.html', 'node', 204, '' ),
            'other case' => array( 'exponential/technical-manual/6.html', 'Exponential.html', 'node', 50, '' ),
            'folder without suffix: its page' => array( 'Exponential/Upgrading', 'Exponential.html', 'node', 60, '' ),
            'folder: its index file' => array( 'Extensions/eZ-Find/', 'Extensions.html', 'node', 70, '' ),
            'attachment link (encoded name)' => array( '../../download/attachments/31429510/get%20started6b3d.png?version=1&amp;modificationDate=1', 'display/USER.html', 'media', 9002, '' ),
            'file link' => array( 'var/doc/storage/generated/manual.zip', 'doc_hidden.html', 'media', 9202, '' ),
            'login page forwarding to a page id' => array( '../../login47b7.html', 'display/DEVELOPER/Working+with+Locations.html', 'node', 555, '' ),
            'login page forwarding to a user profile' => array( 'loginee65.html', 'index.html', 'unresolved', 0, '' ),
            'not imported page' => array( '../../4.6/Concepts-and-basics/Content-management/The-content-object.html', 'Exponential/Technical-manual/4.7/Features/Clustering.html', 'unresolved', 0, '' ),
            'absolute doc.ez.no not imported' => array( 'https://doc.ez.no/display/NOPE/Missing', 'pages/a.html', 'unresolved', 0, '' ),
            '.. above the top stays at the top' => array( '../../../Exponential.html', 'a/b.html', 'node', 10, '' ),
            'ez.no page not in the import stays external' => array( 'http://ez.no/Products/eZ-Market', 'FAQ/eZ-Market.html', 'external', 0, '' ),
            'share.ez.no stays external' => array( 'http://share.ez.no/articles/x', 'a.html', 'external', 0, '' ),
            'external' => array( 'https://github.com/se7enxweb/exponential', 'index.html', 'external', 0, '' ),
            'protocol-relative external' => array( '//jira.ez.no/browse/EZP-1', 'index.html', 'external', 0, '' ),
            'mailto' => array( 'mailto:security@ez.no', 'Security.html', 'mailto', 0, '' ),
            'pure anchor' => array( '#ini_settings', 'a.html', 'anchor', 0, 'ini_settings' ),
            'javascript' => array( 'javascript:void(0)', 'a.html', 'ignored', 0, '' ),
            'template placeholder' => array( '{$current_node.parent.url_alias|ezurl( ', 'a.html', 'ignored', 0, '' ),
            'Confluence placeholder' => array( '/display/{4}/', 'display/DEVELOPER/Backend+interface.html', 'ignored', 0, '' ),
            'empty' => array( '', 'a.html', 'ignored', 0, '' ),
            'already a node link' => array( 'eznode://128', 'a.html', 'ignored', 0, '' ),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'links' )]
    public function testResolve( $href, $from, $status, $node, $anchor )
    {
        $result = $this->resolver->resolve( $from, $href );
        $this->assertSame( $status, $result['status'], "$href on $from: " . $result['reason'] );
        $this->assertSame( $node, $result['node'], $href );
        $this->assertSame( $anchor, $result['anchor'], $href );
        $this->assertSame( $href, $result['href'], 'the original href is kept' );
        if ( $status === 'unresolved' )
            $this->assertNotSame( '', $result['reason'], 'an unresolved link says why' );
    }

    public function testImagesResolveOnlyToMedia()
    {
        $image = $this->resolver->resolve( 'Exponential/Technical-manual/4.7/a.html', '../../../../var/doc/storage/images/a/b/diagram.png', true );
        $this->assertSame( 'media', $image['status'] );
        $this->assertSame( 9101, $image['object'] );
        $missing = $this->resolver->resolve( 'a.html', 'images/none.png', true );
        $this->assertSame( 'unresolved', $missing['status'] );
        $this->assertSame( 'images/none.png', $missing['target'] );
        $this->assertSame( 'the media file is not imported', $missing['reason'] );
        // a src that names a page is no image
        $this->assertSame( 'unresolved', $this->resolver->resolve( 'index.html', 'Exponential.html', true )['status'] );
    }

    public function testUnresolvedPageIdIsReportedByItsId()
    {
        $result = $this->resolver->resolve( 'a.html', 'pages/viewpage.action?pageId=777' );
        $this->assertSame( 'unresolved', $result['status'] );
        $this->assertSame( 'page id 777 is not imported', $result['reason'] );
    }

    public function testJoin()
    {
        $this->assertSame( 'a/c.html', expStaticImportLinkResolver::join( 'a/b.html', 'c.html' ) );
        $this->assertSame( 'c.html?x=1', expStaticImportLinkResolver::join( 'a/b.html', '../c.html?x=1' ) );
        $this->assertSame( 'x/y/', expStaticImportLinkResolver::join( 'a/b.html', '/x/y/' ) );
        $this->assertSame( 'c.html', expStaticImportLinkResolver::join( 'b.html', '../c.html' ) );
        $this->assertNull( expStaticImportLinkResolver::join( 'b.html', '' ) );
    }

    public function testForms()
    {
        $this->assertSame( 'relative .html page', expStaticImportLinkResolver::form( 'x.html' ) );
        $this->assertSame( 'relative ../ .html page +anchor', expStaticImportLinkResolver::form( '../x.html#a' ) );
        $this->assertSame( 'root-relative path without suffix', expStaticImportLinkResolver::form( '/Exponential/Technical-manual/4.7' ) );
        $this->assertSame( 'relative ../ by pageId +query', expStaticImportLinkResolver::form( '../../pages/viewpage414d.html?pageId=1' ) );
        $this->assertSame( 'relative ../ display/SPACE/Title (+ encoded)', expStaticImportLinkResolver::form( '../display/EZP/Requirements%2b5.html' ) );
        $this->assertSame( 'download/attachments', expStaticImportLinkResolver::form( '../../download/attachments/1/a.png' ) );
        $this->assertSame( 'login page', expStaticImportLinkResolver::form( '../../login47b7.html' ) );
        $this->assertSame( 'absolute doc.ez.no', expStaticImportLinkResolver::form( 'https://doc.ez.no/display/X/Y' ) );
        $this->assertSame( 'absolute ez.no', expStaticImportLinkResolver::form( 'http://www.ez.no/x' ) );
        $this->assertSame( 'mailto', expStaticImportLinkResolver::form( 'mailto:a@b.c' ) );
        $this->assertSame( 'pure anchor', expStaticImportLinkResolver::form( '#x' ) );
        $this->assertSame( 'template placeholder', expStaticImportLinkResolver::form( '/display/{4}/' ) );
    }
}
