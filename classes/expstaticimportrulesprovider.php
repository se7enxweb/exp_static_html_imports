<?php
/**
 * File containing the expStaticImportRulesProvider interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * Rules written as PHP: statichtmlimport.ini [Type_<label>] RulesFormat=php and RulesClass=<this class>. rules()
 * returns the same keys the INI group has (Classes, PathRules, BodySelector, ...).
 */
interface expStaticImportRulesProvider
{
    /**
     * @return array
     */
    public function rules();
}
