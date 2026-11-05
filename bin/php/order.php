#!/usr/bin/env php
<?php
/**
 * Static site import: gives the children of the imported containers the order the static site listed them in (the
 * links of the container's own page inside OrderSelectors[]): node priorities, the container sorted by priority.
 * No new versions, no edits; safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:order --type=<label> [--dry-run] [--path=<pattern>] [--node=<id>]   (or ext:exp_static_html_imports:order)
 *   --help shows the options.
 *
 * @alias exp:static-import:order
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_order.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Order::main( __FILE__ );
