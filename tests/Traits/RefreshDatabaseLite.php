<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait RefreshDatabaseLite
{
    protected static bool $migrated = false;

    protected static $databaseConnection = null;

    protected static $omopConnection = null;

    public function liteSetUp(): void
    {
        parent::setUp();
        if (! static::$migrated) {
            if (env('APP_ENV') === 'testing') {
                Artisan::call('migrate:fresh');
                Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);
                Artisan::call('db:seed', ['--class' => 'TestingSeeder']);

                Artisan::call('migrate:fresh', [
                    '--database' => 'omop',
                    '--path' => 'database/migrations_omop',
                ]);

                Artisan::call('db:seed', [
                    '--class' => 'MinimalOmopSeeder',
                    '--database' => 'omop',
                ]);

                // LS - Removed for now, as I'm not sure where best placed to put it
                //
                // Artisan::call('db:seed', [
                //     '--class' => 'MinimalDistributionSeeder',
                //     '--database' => 'omop',
                // ]);

                // Artisan::call('db:seed', [
                //     '--class' => 'DistributionConceptViewSeeder',
                //     '--database' => config('database.connections.database') ?? 'omop',
                // ]);

                // Run the fulltext index on omop concept table
                Artisan::call('app:add-full-text-index-to-omop-concepts');

                static::$migrated = true;

                // Store the connection (for SQLite in-memory)
                static::$databaseConnection = DB::connection()->getPdo();
            }
        }

        // Reuse the same connection across tests (fix for SQLite in-memory)
        DB::connection()->setPdo(static::$databaseConnection);

        // Start a manual transaction
        // DB::beginTransaction();
    }

    public function tearDown(): void
    {
        // Rollback after each test
        DB::rollBack();

        parent::tearDown();
    }
}
