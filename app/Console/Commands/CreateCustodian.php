<?php

namespace App\Console\Commands;

use App\Models\Custodian;
use Illuminate\Console\Command;

class CreateCustodian extends Command
{
    protected $signature = 'custodian:create {name : Name of the custodian}';

    protected $description = 'Create a custodian by name, or report it already exists.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('Name cannot be empty.');

            return self::FAILURE;
        }

        $custodian = Custodian::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($custodian) {
            $this->warn("Custodian [{$custodian->name}] already exists.");
            $this->line((string) $custodian->id);

            return self::SUCCESS;
        }

        $custodian = Custodian::create(['name' => $name]);

        $this->info("Created custodian [{$custodian->name}].");
        $this->line((string) $custodian->id);

        return self::SUCCESS;
    }
}
