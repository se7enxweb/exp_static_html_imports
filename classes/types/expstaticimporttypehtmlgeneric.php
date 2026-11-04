<?php
/**
 * File containing the expStaticImportTypeHtmlGeneric class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * --html-generic: any static HTML site, a folder of .html files, read entirely through the rules of
 * statichtmlimport.ini [Type_html-generic] (selectors, class rules, attribute map). Copy that group into a site's
 * override, or point RulesFormat/RulesFile at a YAML file, to import another site.
 */
class expStaticImportTypeHtmlGeneric extends expStaticImportHtmlType
{
    public function identifier()
    {
        return 'html-generic';
    }
}
