#!/usr/bin/env php
<?php
/**
 * Static site import: the state of an import type: pages by status, the last runs, a run in progress
 *
 * Usage:
 *   ./console exp:static-import:status [options]   (or ext:exp_static_html_imports:status)
 *   --help shows the options.
 *
 * @alias exp:static-import:status
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_status.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Status::main( __FILE__ );
