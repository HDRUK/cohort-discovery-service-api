<?php

namespace App\Enums;

enum DistributionJobType: string
{
    case GENERIC = 'a';
    case DEMOGRAPHICS = 'b';

    public function queryType(): QueryType
    {
        return match ($this) {
            self::GENERIC => QueryType::GENERIC,
            self::DEMOGRAPHICS => QueryType::DEMOGRAPHICS,
        };
    }

    public function queryNamePrefix(): string
    {
        return match ($this) {
            self::GENERIC => 'omop-concept-job',
            self::DEMOGRAPHICS => 'distribution-job',
        };
    }
}
