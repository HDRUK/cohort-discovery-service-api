<?php

use App\DeploymentSteps\DeploymentStep;
use App\Jobs\FailConceptFilesWithoutConcepts;

return new class () extends DeploymentStep {
    public function handle(): void
    {
        FailConceptFilesWithoutConcepts::dispatch();

        $this->info('Dispatched FailConceptFilesWithoutConcepts to the queue.');
    }
};
