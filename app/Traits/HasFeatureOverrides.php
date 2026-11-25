<?php

namespace App\Traits;

use DB;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;

trait HasFeatureOverrides
{
    protected static function bootHasFeatureOverrides(): void
    {
        static::deleted(function (Model $model) {
            DB::table('features')
                ->where('scope', Feature::serializeScope($model))
                ->delete();
        });
    }
}
