<?php

namespace App\Services\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivityLogger
{
    public function viewed(string $logName, ?Model $subject = null, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'viewed', $subject, $properties, $description);
    }

    public function created(string $logName, Model $subject, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'created', $subject, $properties, $description);
    }

    public function updated(string $logName, Model $subject, array $before, array $after, ?string $description = null): void
    {
        $this->log($logName, 'updated', $subject, [
            'before' => $before,
            'after' => $after,
        ], $description);
    }

    public function deleted(string $logName, Model $subject, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'deleted', $subject, $properties, $description);
    }

    public function attached(string $logName, Model $subject, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'attached', $subject, $properties, $description);
    }

    public function detached(string $logName, Model $subject, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'detached', $subject, $properties, $description);
    }

    public function processed(string $logName, ?Model $subject = null, array $properties = [], ?string $description = null): void
    {
        $this->log($logName, 'processed', $subject, $properties, $description);
    }

    public function failed(
        string $logName,
        ?Model $subject,
        \Throwable $e,
        array $properties = [],
        ?string $description = null
    ): void {
        $this->log($logName, 'failed', $subject, array_merge($properties, [
            'error' => [
                'class' => get_class($e),
                'message' => mb_strimwidth($e->getMessage(), 0, 2000, '…'),
            ],
        ]), $description);
    }

    public function custom(
        string $logName,
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null
    ): void {
        $this->log($logName, $event, $subject, $properties, $description);
    }

    private function log(
        string $logName,
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null
    ): void {
        $activity = activity($logName)
            ->event($event)
            ->causedBy(Auth::user());

        if ($subject) {
            $activity->performedOn($subject);
        }

        if ($properties !== []) {
            $activity->withProperties($properties);
        }

        $activity->log($description ?? $this->defaultDescription($logName, $event));
    }

    private function defaultDescription(string $logName, string $event): string
    {
        //sigular to make tasks -> task
        return Str::singular($logName).'_'.$event;
    }
}
