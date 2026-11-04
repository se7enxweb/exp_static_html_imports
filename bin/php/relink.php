#!/usr/bin/env php
<?php
/**
 * Static site import: writes again the pages of an earlier run whose links to static paths can be resolved now,
 * without a run. Safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:relink --type=<label> [--dry-run]   (or ext:exp_static_html_imports:relink)
 *   --help shows the options.
 *
 * @alias exp:static-import:relink
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_relink.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Relink::main( __FILE__ );
