<?php
/**
 * The sandbox of the integration tests (included, never run on its own): a throwaway copy of the installation's
 * SQLite database that eZDB is switched to, a var folder of its own (media, the import's state) and a top folder
 * "Integration test" in the copy, so a test never touches the installation's database, files or import state.
 *
 * Before the include: $label (the import type the test registers, so no state is shared with a real type).
 * After it: $check( bool, text ), $stop( text ), $failures, $work (the work folder), $admin, $topNode.
 * Env: EXP_IT_DIR the work folder (default var/<site>/static-import/integration-test); each run overwrites it.
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
    eZScript::instance()->shutdown( 1 );
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
$stateFile = expStaticImportEngine::workDir( $label ) . '/state.json';
if ( is_file( $stateFile ) )
    file_put_contents( $stateFile, '{}' );
$admin = eZUser::fetchByName( 'admin' );
if ( !$admin )
    $stop( 'no user admin' );
eZUser::setCurrentlyLoggedInUser( $admin, (int)$admin->attribute( 'contentobject_id' ) );
$top = eZContentFunctions::createAndPublishObject( array( 'parent_node_id' => 2, 'class_identifier' => 'folder', 'creator_id' => (int)$admin->attribute( 'contentobject_id' ),
                                                         'remote_id' => 'exp-it-top-' . uniqid(), 'attributes' => array( 'name' => 'Integration test' ) ) );
if ( !$top )
    $stop( 'cannot create the test folder in the copy' );
$topNode = (int)$top->attribute( 'main_node_id' );
