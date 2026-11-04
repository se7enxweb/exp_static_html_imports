#!/usr/bin/env php
<?php
/**
 * Static site import: hides the nodes of imported pages that are no pages by the type's rules today (error pages,
 * files that are no HTML, the viewers of one media file). Safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:hide-non-pages --type=<label> [--dry-run]   (or ext:exp_static_html_imports:hide-non-pages)
 *   --help shows the options.
 *
 * @alias exp:static-import:hide-non-pages
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_hide_non_pages.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\HideNonPages::main( __FILE__ );
