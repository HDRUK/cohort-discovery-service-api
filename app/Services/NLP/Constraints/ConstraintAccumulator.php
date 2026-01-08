<?php

namespace App\Services\NLP\Constraints;

use Carbon\Carbon;

class ConstraintAccumulator
{
    public ?int $ageMin = null;
    public ?int $ageMax = null;

    public ?string $timeFrom = null;
    public ?string $timeTo = null;

    // For later use, potentially.
    public bool $ageAmbiguous = false;
    public bool $timeAmbiguous = false;
    public string $timeScope = 'event_date';

    public function addAgeMin(int $value, bool $ambiguous = true): void
    {
        $this->ageMin = max($this->ageMin ?? $value, $value);
        $this->ageAmbiguous = $this->ageAmbiguous || $ambiguous;
    }

    public function addAgeMax(int $value, bool $ambiguous = true): void
    {
        $this->ageMax = min($this->ageMax ?? $value, $value);
        $this->ageAmbiguous = $this->ageAmbiguous || $ambiguous;
    }

    public function setTimeRange(?string $from, ?string $to, bool $ambiguous = true): void
    {
        $this->timeFrom = $from;
        $this->timeTo = $to;
        $this->timeAmbiguous = $this->timeAmbiguous || $ambiguous;
    }

    public function toArray(): array
    {
        return [
            'ageConstraint' => [
                $this->ageMin,
                $this->ageMax
            ],
            'timeConstraint' => [
                Carbon::parse($this->timeFrom)->toISOString(),
                Carbon::parse($this->timeTo)->toISOString()
            ],
        ];
    }
}
