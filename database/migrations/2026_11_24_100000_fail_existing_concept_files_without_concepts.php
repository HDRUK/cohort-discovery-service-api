<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::update(
            <<<'SQL'
            UPDATE result_files
            SET status = 'failed',
                error = 'No concepts found in file',
                updated_at = updated_at
            WHERE file_name = 'code.distribution'
              AND status = 'done'
              AND NOT EXISTS (
                  SELECT 1
                  FROM distributions
                  WHERE distributions.concept_id > 0
                    AND (
                        distributions.result_file_id = result_files.id
                        OR (
                            distributions.result_file_id IS NULL
                            AND distributions.task_id = result_files.task_id
                        )
                    )
              )
            SQL
        );
    }

    public function down(): void
    {
    }
};
