<?php

use App\DeploymentSteps\DeploymentStep;

return new class () extends DeploymentStep {
    public function handle(): void
    {
        throw new \RuntimeException('Intentional failure for testing.');
    }
};
