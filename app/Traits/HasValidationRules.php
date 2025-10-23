<?php

namespace App\Traits;

/**
 * This potentially goes against DRY principles, but I argue that being forced
 * to repeatedly make and maintain Create/Update/Destroy<model_name>Request
 * per controller http method is also against DRY. So, I've re-invented to
 * couple the rule config to a specific model, thus reducing the requirement
 * to create additional files.
 */
trait HasValidationRules
{
    // public function getFillableValidationRules(): array
    // {
    //     if (!method_exists($this, 'getValidationRules')) {
    //         return [];
    //     }

    //     return array_intersect_key(
    //         $this->getValidationRules(),
    //         array_flip($this->getFillable())
    //     );
    // }
}
