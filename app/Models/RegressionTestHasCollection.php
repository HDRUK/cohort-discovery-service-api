<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int|null $expected_result
 */
class RegressionTestHasCollection extends Pivot
{
    protected $casts = ['expected_result' => 'integer'];
}
