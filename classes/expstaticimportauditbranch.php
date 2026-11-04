<?php
/**
 * File containing the expStaticImportAuditBranch class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The audit events of the static site import (audit.ini [AuditEventSettings] Branches[]).
 */
class expStaticImportAuditBranch implements expAuditTaxonomyBranch
{
    public function events()
    {
        return array(
            'system.staticimport_run.start' => array( 'label' => 'Static site import started', 'severity' => 'info', 'default' => 'on', 'channel' => 'system' ),
            'system.staticimport_run.finish' => array( 'label' => 'Static site import finished', 'severity' => 'notice', 'default' => 'on', 'channel' => 'system' ),
            'system.staticimport_page.create' => array( 'label' => 'Page created by a static site import', 'severity' => 'info', 'default' => 'off', 'channel' => 'system' ),
            'system.staticimport_page.update' => array( 'label' => 'Page updated by a static site import', 'severity' => 'info', 'default' => 'off', 'channel' => 'system' ),
            'system.staticimport_page.flag' => array( 'label' => 'Page changed in the CMS, left alone by a static site import', 'severity' => 'notice', 'default' => 'on', 'channel' => 'system' ),
            'system.staticimport_page.remove' => array( 'label' => 'Page that left its static source hidden or trashed', 'severity' => 'notice', 'default' => 'on', 'channel' => 'system' ),
            'system.staticimport_settings.change' => array( 'label' => 'Static site import settings changed', 'severity' => 'notice', 'default' => 'on', 'channel' => 'system' ),
        );
    }
}
