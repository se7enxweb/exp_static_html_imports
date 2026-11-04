#!/usr/bin/env php
<?php
/**
 * Static site import: puts the pages an earlier run imported where the RouteRules[] of the type send them today,
 * with unique names. Safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:route --type=<label> [--dry-run]   (or ext:exp_static_html_imports:route)
 *   --help shows the options.
 *
 * @alias exp:static-import:route
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_route.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Route::main( __FILE__ );
