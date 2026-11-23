<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class () extends Migration {
    public function up(): void
    {
        $selfReferencing = DB::table('custodians')
            ->whereRaw('external_custodian_id = CAST(id AS CHAR)')
            ->get(['id', 'name']);

        foreach ($selfReferencing as $custodian) {
            Log::warning('Cleared local id from custodian external_custodian_id', [
                'custodian_id' => $custodian->id,
                'name' => $custodian->name,
            ]);
        }

        DB::table('custodians')
            ->whereIn('id', $selfReferencing->pluck('id'))
            ->update([
                'external_custodian_id' => null,
                'external_custodian_name' => null,
            ]);
    }

    public function down(): void
    {
    }
};
