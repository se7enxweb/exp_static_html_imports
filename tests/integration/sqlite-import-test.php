<?php
/**
 * The import integration test on SQLite: a fixture site (tests/fixtures/flat-site) imported for real, objects, nodes,
 * media and URL aliases, into a throwaway copy of an installation's SQLite database, never into the installation's
 * own database, files or import state.
 *
 * Run from the root of an installation on SQLite that has the classes of the rules below (exp_doc's, or set
 * EXP_IT_CLASS to another class with title and body), as the user that owns var/:
 *
 *   php bin/php/ezexec.php extension/exp_static_html_imports/tests/integration/sqlite-import-test.php
 *
 * Env: EXP_IT_DIR the work folder (default var/<site>/static-import/integration-test): the copy of the database
 *      (test.db), a var folder of its own for the media and the state file. Each run overwrites them.
 *
 * What it does:
 *   1. copies the database with SQLite's online backup (safe while the installation is in use), switches eZDB to
 *      the copy and refuses to go on unless every query goes to the copy;
 *   2. points the var folder (storage, the import's state) into the work folder, and registers the fixture as a type
 *      of its own (it-flat-html), so nothing is shared with a real type;
 *   3. imports the fixture below a new folder, twice, and once after moving a page to the wrong place; then runs
 *      the byline audit.
 * Prints PASS or FAIL per check and exits 1 when any check failed.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$failures = 0;
$check = function ( $ok, $what ) use ( &$failures )
{
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $failures++;
};
$stop = function ( $why )
{
    echo "FAIL $why\n";
    exit( 1 );
};

// 1. the throwaway database
$siteIni = eZINI::instance( 'site.ini' );
if ( $siteIni->variable( 'DatabaseSettings', 'DatabaseImplementation' ) !== 'sqlite3' )
    $stop( 'the installation is not on SQLite (DatabaseImplementation=sqlite3)' );
$live = eZSQLite3DB::filePath( $siteIni->variable( 'DatabaseSettings', 'Database' ) );
$work = getenv( 'EXP_IT_DIR' ) ?: eZSys::rootDir() . '/' . eZSys::varDirectory() . '/static-import/integration-test';
if ( !is_dir( $work ) && !mkdir( $work, 0775, true ) )
    $stop( "cannot create $work" );
$work = realpath( $work );
$copy = $work . '/test.db';
if ( realpath( $live ) === $copy )
    $stop( 'the copy would be the live database' );
$started = microtime( true );
$from = new SQLite3( $live, SQLITE3_OPEN_READONLY );
$from->busyTimeout( 30000 );
$to = new SQLite3( $copy );
if ( !$from->backup( $to ) )
    $stop( 'the backup of the database failed: ' . $from->lastErrorMsg() );
$from->close();
$to->exec( 'PRAGMA journal_mode=DELETE' );
$to->close();
$check( true, sprintf( 'copied the database (%.0f MB) in %.1f s', filesize( $copy ) / 1048576, microtime( true ) - $started ) );

$db = eZDB::instance( 'sqlite3', array( 'server' => '', 'port' => '', 'user' => '', 'password' => '', 'database' => $copy, 'charset' => 'utf-8' ), true );
if ( !$db->isConnected() )
    $stop( 'cannot open the copy' );
eZDB::setInstance( $db );
$list = eZDB::instance()->arrayQuery( 'PRAGMA database_list' );
$main = '';
foreach ( $list as $row )
    if ( $row['name'] === 'main' )
        $main = realpath( $row['file'] );
if ( $main !== $copy )
    $stop( "eZDB still answers from $main, not from the copy" );
$check( true, 'every query goes to the copy ' . $copy );

// 2. a var folder and a type of its own
$varDir = substr( $work, strlen( eZSys::rootDir() ) + 1 ) . '/var';
$siteIni->setVariable( 'FileSettings', 'VarDir', $varDir );
$siteIni->setVariable( 'SearchSettings', 'DelayedIndexing', 'enabled' );
$check( strpos( eZSys::varDirectory(), 'integration-test' ) !== false, 'the var folder is ' . eZSys::varDirectory() );
$label = 'it-flat-html';
$stateFile = expStaticImportEngine::workDir( $label ) . '/state.json';
if ( is_file( $stateFile ) )
    file_put_contents( $stateFile, '{}' );

$class = getenv( 'EXP_IT_CLASS' ) ?: 'exp_doc_page';
$section = getenv( 'EXP_IT_SECTION_CLASS' ) ?: ( eZContentClass::fetchByIdentifier( 'exp_doc_section' ) ? 'exp_doc_section' : $class );
if ( !eZContentClass::fetchByIdentifier( $class ) )
    $stop( "no class $class in the installation (set EXP_IT_CLASS)" );
$admin = eZUser::fetchByName( 'admin' );
if ( !$admin )
    $stop( 'no user admin' );
eZUser::setCurrentlyLoggedInUser( $admin, (int)$admin->attribute( 'contentobject_id' ) );
$top = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => 2, 'class_identifier' => 'folder', 'creator_id' => (int)$admin->attribute( 'contentobject_id' ),
                                                         'remote_id' => 'exp-it-top-' . uniqid(), 'attributes' => array( 'name' => 'Integration test' ) ) );
if ( !$top )
    $stop( 'cannot create the test folder in the copy' );
$topNode = (int)$top->attribute( 'main_node_id' );

$ini = eZINI::instance( 'statichtmlimport.ini' );
$types = (array)$ini->variable( 'ImportTypes', 'TypeList' );
$types[$label] = 'expStaticImportTypeDocHtml';
$ini->setVariable( 'ImportTypes', 'TypeList', $types );
$rules = array(
    'SourceLocation' => realpath( __DIR__ . '/../fixtures/flat-site' ),
    'ParentNode' => (string)$topNode, 'MediaParentNode' => (string)$topNode, 'ImportUser' => 'admin', 'Language' => 'eng-US', 'BatchSize' => '5',
    'TitleSelector' => '#title-text, h1', 'BodySelector' => '#main-content, body',
    'NotFoundTitlePatterns' => array( '/^404\b/', '/^kernel \(\d+\) \/ Error\b/' ),
    'RemoveSelectors' => array( '.pageinfo' ),
    'BreadcrumbSelector' => '#breadcrumbs a', 'BreadcrumbPaths' => array( 'display/**' ),
    'GroupFolders' => array( 'display', 'display/*' ), 'GroupTitles' => array( 'display' => 'Spaces' ),
    'GroupTitleSelector' => '#breadcrumbs li.first a', 'GroupClass' => $section,
    'AssetPages' => 'enabled', 'AssetSuffixes' => array( 'png' ),
    'AssetClassRules' => array( '**;' . ( eZContentClass::fetchByIdentifier( 'exp_doc_image' ) ? 'exp_doc_image' : 'image' ) ),
    'AssetAttributeMap' => array( 'exp_doc_image/name' => 'title', 'exp_doc_image/image' => 'file', 'exp_doc_image/alt' => 'alt',
                                  'image/name' => 'title', 'image/image' => 'file' ),
    'BylineDateFormats' => array( 'M d, Y H:i', 'M d, Y' ), 'BylineTimezone' => 'Europe/Oslo',
    'BylineSentenceSelectors' => array( '.page-metadata-modification-info' ),
    'BylineSentences' => array( '/^Created and last modified by (?<written>.+?) on (?<updated_at>.+)$/u',
                                '/^Created by (?<written>.+?), last modified by (?<updated>.+?) on (?<updated_at>.+)$/u',
                                '/^Created by (?<written>.+?), last modified on (?<updated_at>.+)$/u',
                                '/^Created by (?<written>.+?) on (?<written_at>.+)$/u' ),
    'BylineCommentAuthorSelectors' => array( '.comment-header .author' ),
    'ContributorClass' => eZContentClass::fetchByIdentifier( 'exp_doc_contributor' ) ? 'exp_doc_contributor' : '',
    'ContributorParentNode' => (string)$topNode,
    'DefaultClass' => $class,
    'AttributeMap' => array( '*/title' => 'title', '*/body' => 'body', '*/source_path' => 'source_path', '*/written_by' => 'written_by',
                             '*/written_at' => 'written_at', '*/last_updated_by' => 'last_updated_by', '*/last_updated_at' => 'last_updated_at',
                             '*/contributors' => 'contributors', 'folder/name' => 'title' ),
    'CreateRedirects' => 'enabled', 'DeferViewCacheClearing' => 'disabled',
);
$ini->setVariables( array( 'Type_' . $label => $rules ) );

// 3. the runs
$node = function ( $path ) use ( $label )
{
    $object = eZContentObject::fetchByRemoteID( expStaticImportEngine::remoteID( $label, $path ) );
    return $object ? $object->attribute( 'main_node' ) : null;
};
$parentOf = function ( $path ) use ( $node )
{
    $n = $node( $path );
    return $n ? (int)$n->attribute( 'parent_node_id' ) : 0;
};
$nodeID = function ( $path ) use ( $node )
{
    $n = $node( $path );
    return $n ? (int)$n->attribute( 'node_id' ) : -1;
};

$engine = new expStaticImportEngine( $label, array( 'pull' => false, 'restart' => true ) );
$report = $engine->run();
$check( $report['counts']['failed'] === 0, 'first run: no page failed (' . json_encode( $report['failed'] ) . ')' );
$check( $report['counts']['created'] >= 12, "first run: {$report['counts']['created']} pages created" );
$check( $parentOf( 'display/' ) === $topNode, 'the group page of display/ is a top page' );
$check( $parentOf( 'display/SPC/' ) === $nodeID( 'display/' ), 'the space display/SPC/ is below display/' );
$check( $node( 'display/SPC/' ) && $node( 'display/SPC/' )->attribute( 'name' ) === 'Space One', 'the space is named by its breadcrumb' );
$check( $parentOf( 'display/SPC/Releases.html' ) === $nodeID( 'display/SPC/' ), 'a page whose breadcrumb names no imported page is below its space' );
$check( $parentOf( 'display/SPC/0.html' ) === $nodeID( 'display/SPC/1.html' ), 'a page is below the nearest page of its breadcrumb' );
$check( $parentOf( 'display/OWN/Page.html' ) === $nodeID( 'display/OWN.html' ), 'a folder with a page of its own keeps it as the parent' );
$check( $node( 'display/SPC/fonts/icon-font.html' ) === null, 'a binary file with a page suffix is no page' );
$check( $node( 'display/SPC/module-error.html' ) === null, 'an error page is no page' );
$check( $node( 'Manual/Images/Screen.html' ) === null, 'the viewer of one image is no page' );
$media = eZContentObject::fetchByRemoteID( expStaticImportEngine::remoteID( $label . '-media', 'Manual/storage/screen.png' ) );
$check( $media instanceof eZContentObject, 'the image the viewer showed is a media object' );
$alias = eZURLAliasML::fetchNodeIDByPath( 'Manual/Images/Screen' );
$check( $media && (int)$alias === (int)$media->attribute( 'main_node_id' ), 'the viewer\'s old path leads to the media object' );
$check( $node( 'Manual/Images/Described.html' ) !== null, 'a page with words of its own and an image stays a page' );
$both = $node( 'display/SPC/Byline-both.html' );
if ( $both && isset( $both->dataMap()['contributors'] ) )
{
    $names = array();
    $content = $both->dataMap()['contributors']->content();
    foreach ( $content['relation_list'] as $item )
        $names[] = eZContentObject::fetch( (int)$item['contentobject_id'] )->attribute( 'name' );
    $check( $names === array( 'Ann Writer', 'Bob Editor', 'Cid Reader' ), 'the contributors of a page: ' . implode( ', ', $names ) );
    $writer = eZContentObject::fetch( (int)$both->dataMap()['written_by']->attribute( 'data_int' ) );
    $check( $writer && $writer->attribute( 'name' ) === 'Ann Writer', 'Confluence\'s "Created by" is written_by' );
}
else
    $check( false, "the class $class has no contributors attribute" );

$second = ( new expStaticImportEngine( $label, array( 'pull' => false ) ) )->run();
$check( $second['counts']['created'] === 0 && $second['counts']['updated'] === 0, "second run: nothing created or updated ({$second['counts']['unchanged']} unchanged)" );

// a page an earlier run put in the wrong place moves back on the next run
eZContentObjectTreeNodeOperations::move( $nodeID( 'display/SPC/0.html' ), $topNode );
$check( $parentOf( 'display/SPC/0.html' ) === $topNode, 'moved display/SPC/0.html to the top by hand' );
$third = ( new expStaticImportEngine( $label, array( 'pull' => false ) ) )->run();
$check( $parentOf( 'display/SPC/0.html' ) === $nodeID( 'display/SPC/1.html' ), 'the next run moved it back below its breadcrumb parent (' . ( isset( $third['counts']['moved'] ) ? $third['counts']['moved'] : 0 ) . ' moved)' );

$audit = ( new expStaticImportEngine( $label, array( 'pull' => false ) ) )->bylines();
$check( $audit['missing'] === 0, "byline audit: {$audit['people']} people, {$audit['dates']} dates, {$audit['missing']} missing" );
$check( $audit['unknown'] === 1, "byline audit: the one unknown variant of the fixture is listed ({$audit['unknown']})" );

echo ( $failures ? "FAIL $failures check(s) failed" : 'PASS every check' ) . " (the copy and its files stay in $work for a look; the next run overwrites them)\n";
exit( $failures ? 1 : 0 );
