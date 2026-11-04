#!/usr/bin/env php
<?php
/**
 * Static site import: the import types and where their rules come from
 *
 * Usage:
 *   ./console exp:static-import:types [options]   (or ext:exp_static_html_imports:types)
 *   --help shows the options.
 *
 * @alias exp:static-import:types
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_types.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Types::main( __FILE__ );
