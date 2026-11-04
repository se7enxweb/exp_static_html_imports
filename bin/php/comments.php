#!/usr/bin/env php
<?php
/**
 * Static site import: the no-data-loss audit of the imported comments: every comment of the source, its text,
 * author, date and place. Read-only.
 *
 * Usage:
 *   ./console exp:static-import:comments --type=<label>   (or ext:exp_static_html_imports:comments)
 *   --help shows the options.
 *
 * @alias exp:static-import:comments
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

// The code is in extension/exp_static_html_imports/classes/runnable/commands/php_comments.php; this file is the entry point.
\Exponential\Command\Extension\ExpStaticHtmlImports\Comments::main( __FILE__ );
