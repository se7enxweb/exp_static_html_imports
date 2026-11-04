<?php
/**
 * File containing the expStaticImportTypeInterface interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * One kind of static source (an import type, chosen with --<label>, e.g. --doc-html): what is unique to it.
 *
 * The engine (expStaticImportEngine) does everything that is the same for every source: the clone or pull, batches,
 * the state file and resume, remote ids, hashes and edit detection, creating and updating objects, removals, media,
 * links and the reports. A type only answers five questions, in this order, for each page:
 *
 *   discover()  which files (or URLs) of the source are pages, in an order where a parent comes before its children
 *   parse()     what a page contains: its title, its body as HTML, its metadata (the page array, see below)
 *   map()       which content class it becomes, under which parent, with which attribute values
 *   assets()    the images and files it uses, as source paths
 *   links()     its links to other pages, as source paths, so the engine can turn them into node links
 *
 * Types are registered in statichtmlimport.ini [ImportTypes] TypeList[<label>]=<class>, and their rules are read by
 * expStaticImportRules (INI by default, YAML or a PHP rule class when the type's settings name one).
 *
 * The page array (every key optional except path):
 *   path         the source path relative to the source root (or the URL for a crawl), the page's identity
 *   title        the page's title
 *   body_html    the cleaned HTML of the body, ready for the XML input parser (custom tags already in place)
 *   original_html the page's main area exactly as it was in the source, kept unchanged
 *   meta         array: author, modified (a timestamp), page_id, labels (array), description, keywords (array)
 *   hidden       true for a page that is reachable but not listed
 *   aliases      array of other source paths showing the same page (they become redirects)
 *   class        the content class identifier (set by map())
 *   parent_path  the source path of the parent page, '' for the top of the import (set by map())
 *   attributes   attribute identifier => value as fromString() takes it (set by map())
 *   priority     the node priority among its siblings (set by map())
 */
interface expStaticImportTypeInterface
{
    /**
     * @return string the type's label, as given on the command line without the dashes (e.g. doc-html)
     */
    public function identifier();

    /**
     * @param expStaticImportRules $rules the type's rules
     */
    public function configure( expStaticImportRules $rules );

    /**
     * @param string $source the source root (a directory, or a URL for a crawl)
     * @return array the page paths, parents first
     */
    public function discover( $source );

    /**
     * @param string $source
     * @param string $path
     * @return array|null the page array, null to skip a path that is not a page after all
     * @throws expStaticImportPageException for a page that cannot be read (skipped, reported, "needs review")
     */
    public function parse( $source, $path );

    /**
     * @param array $page the page array from parse()
     * @return array the page array with class, parent_path, attributes and priority
     */
    public function map( array $page );

    /**
     * @param array $page
     * @return array source paths of the images and files the page uses
     */
    public function assets( array $page );

    /**
     * @param array $page
     * @return array href as found in body_html => source path it points to (only links to pages of this source)
     */
    public function links( array $page );
}
