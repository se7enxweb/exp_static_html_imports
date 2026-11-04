#!/usr/bin/env php
<?php
/**
 * Static site import: the no-data-loss audit of the bylines: every person and date of the source's bylines
 * against the stored attributes; --fill writes what is missing. Safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:bylines --type=<label> [--fill]   (or ext:exp_static_html_imports:bylines)
 *   --help shows the options.
 *
 * @alias exp:static-import:bylines
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_bylines.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Bylines::main( __FILE__ );
