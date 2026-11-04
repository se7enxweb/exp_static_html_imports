<?php /* #?ini charset="utf-8"?

# A static import run started from the administration (staticimport/run): one job per run, in a worker. A long
# import resumes where it stopped (its state file), so a retry continues it.
[JobType_staticimport.run]
Handler=expStaticImportJob
Class=system
MaxAttempts=3
Timeout=86400
RetryBase=60
MaxBackoff=600
Description=Static site import run (staticimport/dashboard)

*/ ?>
