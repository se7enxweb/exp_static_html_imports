#!/usr/bin/env php
<?php
/**
 * Static site import: gives the objects an earlier run imported their real dates (published and modified) by the
 * type's DateRules[]: the byline, the source's git history, the meta tags, the file's time. Resumable, and safe while
 * a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:dates --type=<label> [--dry-run] [--restart]   (or ext:exp_static_html_imports:dates)
 *   --help shows the options.
 *
 * @alias exp:static-import:dates
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_dates.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Dates::main( __FILE__ );
