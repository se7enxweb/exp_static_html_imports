#!/usr/bin/env php
<?php
/**
 * Static site import: import or re-sync a static site (--<type> --source=...); applies by default, --dry-run previews
 *
 * Usage:
 *   ./console exp:static-import:run [options]   (or ext:exp_static_html_imports:run)
 *   --help shows the options.
 *
 * @alias exp:static-import:run
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_run.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Run::main( __FILE__ );
