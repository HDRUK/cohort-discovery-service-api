<?php

namespace App\Contracts;

interface ValidatableModel
{
    public function getValidationRules(string $context): array;
}
