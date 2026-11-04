<?php
/**
 * File containing the expStaticImportJob class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package exp_static_html_imports
 */

/**
 * The job queue handler of a static import run started from the administration (expqueue.ini HandlerList), so a
 * long import runs in a worker, resumes after an interruption and shows in the job queue's own pages.
 */
class expStaticImportJob implements expQueueHandler
{
    const TYPE = 'staticimport.run';

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $payload = $job->payloadArray();
        $engine = new expStaticImportEngine( (string)$payload['type'], isset( $payload['options'] ) ? (array)$payload['options'] : array() );
        return $engine->run();
    }
}
