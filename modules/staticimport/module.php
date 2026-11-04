<?php
/**
 * The administration of the static site import: the types, their state and runs, a run started as a background
 * job (expqueue), the reports. Each view needs a policy of its own: staticimport/read to look, staticimport/run to
 * start a run, staticimport/setup for the settings; run and setup also ask "Verify it's you".
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$Module = array( 'name' => 'staticimport' );

$ViewList = array();
$ViewList['dashboard'] = array(
    'script' => 'dashboard.php',
    'functions' => array( 'read' ),
    'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array() );
$ViewList['run'] = array(
    'script' => 'run.php',
    'functions' => array( 'run' ),
    'default_navigation_part' => 'ezsetupnavigationpart',
    'single_post_actions' => array( 'StartButton' => 'Start' ),
    'post_action_parameters' => array( 'Start' => array( 'Type' => 'Type', 'DryRun' => 'DryRun', 'ApplyRemovals' => 'ApplyRemovals' ) ),
    'params' => array() );
$ViewList['report'] = array(
    'script' => 'report.php',
    'functions' => array( 'read' ),
    'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array( 'Type', 'RunID' ) );

$FunctionList = array();
$FunctionList['read'] = array();
$FunctionList['run'] = array();
$FunctionList['setup'] = array();
