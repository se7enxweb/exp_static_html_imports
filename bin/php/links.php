#!/usr/bin/env php
<?php
/**
 * Static site import: rewrites the links of the bodies an import stored, so every link to an imported page or media
 * file is a node link (eznode) that follows moves and renames. In place, in batches, resumable; no editor change.
 * Safe while a run of the type is going on.
 *
 * Usage:
 *   ./console exp:static-import:links --type=<label> [--dry-run] [--batch=200] [--limit=N] [--restart]
 *   (or ext:exp_static_html_imports:links); --help shows the options.
 *
 * @alias exp:static-import:links
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_links.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Links::main( __FILE__ );
