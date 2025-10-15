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

        // $appDb   = database_path('testing.sqlite');
        // $omopDb  = database_path('omop_testing.sqlite');

        // if (! file_exists($appDb)) {
        //     touch($appDb);
        // }
        // if (! file_exists($omopDb)) {
        //     touch($omopDb);
        // }

        // config()->set('database.default', 'sqlite');
        // config()->set('database.connections.sqlite.database', $appDb);

        // config()->set('database.connections.omop', [
        //     'driver' => 'sqlite',
        //     'database' => $omopDb,
        //     'prefix' => '',
        //     'foreign_key_constraints' => true,
        // ]);

        if (!static::$migrated) {
            if (env('APP_ENV') === 'testing') {
                Artisan::call('migrate:fresh');
                Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);

                Artisan::call('migrate:fresh', [
                    '--database' => 'omop',
                    '--path'     => 'database/migrations_omop',
                ]);

                Artisan::call('db:seed', [
                    '--class'    => 'MinimalOmopSeeder',
                    '--database' => 'omop',
                ]);

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
        DB::beginTransaction();
    }

    public function tearDown(): void
    {
        // Rollback after each test
        DB::rollBack();

        parent::tearDown();
    }
}
