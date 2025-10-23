<?php

namespace App\Contracts;

/**
 * This potentially goes against DRY principles, but I argue that being forced
 * to repeatedly make and maintain Create/Update/Destroy<model_name>Request
 * per controller http method is also against DRY. So, I've re-invented to
 * couple the rule config to a specific model, thus reducing the requirement
 * to create additional files.
 */
interface ValidatableModel
{
    public function getValidationRules(string $context): array;
}
