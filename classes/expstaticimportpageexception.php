<?php
/**
 * File containing the expStaticImportPageException class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * A page that cannot be imported: the engine skips it, keeps its original HTML in the report and marks it
 * "needs review", and goes on with the next page.
 */
class expStaticImportPageException extends Exception
{
}
