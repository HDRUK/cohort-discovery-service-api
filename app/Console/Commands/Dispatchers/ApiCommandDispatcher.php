<?php

namespace App\Console\Commands\Dispatchers;

use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApiCommandDispatcher
{
    protected array $commands = [
        'distributions-collector' => \App\Console\Commands\DistributionsCollector::class,
        'collection-no-activity-monitor' => \App\Console\Commands\CollectionNoActivityMonitor::class,
    ];

    public function run(string $command, array $input)
    {
        if (!array_key_exists($command, $this->commands)) {
            throw new NotFoundHttpException('Command [ ' . $command . '] not found.');
        }

        $commandClass = $this->commands[$command];
        /** @var \App\Contracts\ApiCommand $instance */
        $instance = app($commandClass);

        $validator = Validator::make($input, $instance->rules());
        $validator->validate();

        return $instance->handle($input);
    }
}
