<?php
/**
 * The byline rules of Confluence's page sentences, as exp_doc sets them for --doc-html, for the tests.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

$date = '(?:yesterday|today) at .+|[A-Z][a-z]{2} \d{1,2}, \d{4}.*';
return array(
    'BylineDateFormats' => array( 'M d, Y H:i', 'M d, Y' ),
    'BylineTimezone' => 'Europe/Oslo',
    'BylineSentenceSelectors' => array( '.page-metadata-modification-info' ),
    'BylineSavedAtPattern' => '/Mirrored from .* by HTTrack[^\]]*\],\s*(.+? GMT)/',
    'BylineSentences' => array(
        "/^Created and last modified by (?<written>.+?) (?:on )?(?<updated_at>$date)$/u",
        "/^Created by (?<written>.+?), last modified by (?<updated>.+?) (?:on )?(?<updated_at>$date)$/u",
        "/^Created by (?<written>.+?), last modified (?:on )?(?<updated_at>$date)$/u",
        "/^Created by (?<written>.+?) (?:on )?(?<written_at>$date)$/u",
    ),
    'BylineCommentAuthorSelectors' => array( '.comment-header .author' ),
);
