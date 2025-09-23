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

        if (!static::$migrated) {
            Artisan::call('migrate');
            Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);

            Artisan::call('db:seed', [
                '--class'    => 'SimpleOmopSeeder',
                '--database' => 'omop',
            ]);


            static::$migrated = true;

            // Store the connection (for SQLite in-memory)
            static::$databaseConnection = DB::connection()->getPdo();
            static::$omopConnection     = DB::connection('omop')->getPdo();
        }

        // Reuse the same connection across tests (fix for SQLite in-memory)
        DB::connection()->setPdo(static::$databaseConnection);
        DB::connection('omop')->setPdo(static::$omopConnection);

        // Start a manual transaction
        DB::beginTransaction();
        DB::connection('omop')->beginTransaction();
    }

    public function tearDown(): void
    {
        // Rollback after each test
        DB::rollBack();
        DB::connection('omop')->rollBack();

        parent::tearDown();
    }
}
