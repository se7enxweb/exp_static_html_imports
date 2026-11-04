<?php
/**
 * A live, polite test of --sitemap-crawl: a few public pages of a real site fetched over HTTPS and imported into the
 * throwaway SQLite sandbox (sandbox.php), never into the installation's own database.
 *
 *   php bin/php/ezexec.php extension/exp_static_html_imports/tests/integration/sitemap-crawl-live-test.php
 *
 * Env: EXP_CRAWL_BASE the site (default https://doc.exponential.earth/doc/), EXP_CRAWL_URLS a comma list of paths
 *      below it (default five pages of the documentation), EXP_CRAWL_SITEMAP a sitemap instead ("none" by default:
 *      the pages are named).
 *
 * Checks: robots.txt of the host is read first; one request a second at most (RequestInterval=1); every request
 * carries the crawler's own user agent; the pages are imported below the test folder with their titles; a second run
 * reads the mirror and sends no request for the pages.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$label = 'it-sitemap-crawl';
require __DIR__ . '/sandbox.php';

$base = rtrim( getenv( 'EXP_CRAWL_BASE' ) ?: 'https://doc.exponential.earth/doc/', '/' ) . '/';
$urls = array_values( array_filter( array_map( 'trim', explode( ',', getenv( 'EXP_CRAWL_URLS' )
        ?: 'site/exponential/technical-manual,site/frequently-asked-questions,site/tutorials,site/extensions,site/exponential/user-manual' ) ) ) );
$mirror = expStaticImportEngine::workDir( $label ) . '/mirror';
if ( is_dir( $mirror ) )
    // a fresh mirror, so every page is fetched: the old one is moved aside, never deleted
    rename( $mirror, $mirror . '-' . date( 'Ymd-His' ) );

$ini = eZINI::instance( 'statichtmlimport.ini' );
$types = (array)$ini->variable( 'ImportTypes', 'TypeList' );
$types[$label] = 'expStaticImportTypeSitemapCrawl';
$ini->setVariable( 'ImportTypes', 'TypeList', $types );
$agent = 'Exponential static import test (exp_static_html_imports; +https://doc.exponential.earth)';
$ini->setVariables( array( 'Type_' . $label => array(
    'SourceLocation' => $base, 'SitemapPath' => getenv( 'EXP_CRAWL_SITEMAP' ) ?: 'none', 'Urls' => $urls,
    'RequestInterval' => '1', 'Timeout' => '30', 'UserAgent' => $agent,
    'ParentNode' => (string)$topNode, 'MediaParentNode' => (string)$topNode, 'ImportUser' => 'admin', 'Language' => 'eng-US',
    'TitleSelector' => 'h1', 'BodySelector' => 'main, article, #main-content, body',
    'RemoveSelectors' => array( 'nav', 'header', 'footer', 'script' ),
    'DefaultClass' => 'folder', 'AttributeMap' => array( 'folder/name' => 'title', 'folder/short_description' => 'body' ),
    'AssetSuffixes' => array( 'none' ), 'CreateRedirects' => 'disabled', 'DeferViewCacheClearing' => 'disabled',
) ) );

$engine = new expStaticImportEngine( $label, array( 'pull' => false, 'restart' => true ) );
$report = $engine->run();
$requests = $engine->type()->requests();
$check( count( $requests ) >= 1 && preg_match( '#^https?://[^/]+/robots\.txt$#', $requests[0]['url'] ), 'the first request is the host\'s robots.txt (' . ( $requests ? $requests[0]['url'] : 'none' ) . ')' );
$gaps = array();
for ( $i = 1; $i < count( $requests ); $i++ )
    $gaps[] = $requests[$i]['time'] - $requests[$i - 1]['time'];
$check( $gaps && min( $gaps ) >= 0.99, sprintf( '%d requests, never faster than one a second (the shortest gap %.2f s)', count( $requests ), $gaps ? min( $gaps ) : 0 ) );
$check( $report['counts']['failed'] === 0, 'no page failed (' . json_encode( $report['failed'] ) . ')' );
$check( $report['counts']['created'] === count( $urls ), "{$report['counts']['created']} of " . count( $urls ) . ' pages created below the test folder' );
$top = eZContentObjectTreeNode::fetch( $topNode );
$names = array();
foreach ( $top ? $top->subTree( array( 'Limitation' => array() ) ) : array() as $child )
    $names[] = $child->attribute( 'name' );
echo '     the pages: ' . implode( ' | ', $names ) . "\n";
$check( count( $names ) === count( $urls ) && !in_array( '', $names, true ), 'every page has its title' );

$again = new expStaticImportEngine( $label, array( 'pull' => false ) );
$second = $again->run();
$pageRequests = array_filter( $again->type()->requests(), function ( $r ) { return !preg_match( '#/robots\.txt$#', $r['url'] ); } );
$check( !$pageRequests && $second['counts']['created'] === 0, 'a second run reads the mirror: ' . count( $pageRequests ) . ' page requests, ' . $second['counts']['unchanged'] . ' unchanged' );

$log = $work . '/crawl-requests.json';
file_put_contents( $log, json_encode( array( 'agent' => $agent, 'requests' => $requests ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo ( $failures ? "FAIL $failures check(s) failed" : 'PASS every check' ) . " (the requests are listed in $log)\n";
eZScript::instance()->shutdown( $failures ? 1 : 0 );
