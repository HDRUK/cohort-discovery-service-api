<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateServiceAccount extends Command
{
    protected $signature = 'service-account:create {name : Name of the service account, e.g. scheduler}';

    protected $description = 'Create a service account for the service layer and print its Basic Auth credentials once.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('Name cannot be empty.');

            return self::FAILURE;
        }

        if (ServiceAccount::where('name', $name)->exists()) {
            $this->error("Service account [{$name}] already exists.");

            return self::FAILURE;
        }

        $clientId = (string) Str::uuid();
        $clientSecret = Str::random(64);

        ServiceAccount::create([
            'name' => $name,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $this->info("Created service account [{$name}].");
        $this->warn('The secret is stored hashed and will not be shown again.');
        $this->newLine();
        $this->line("Client ID:     {$clientId}");
        $this->line("Client secret: {$clientSecret}");
        $this->newLine();
        $this->line('Secret Manager value (client_id:client_secret):');
        $this->line("{$clientId}:{$clientSecret}");
        $this->newLine();
        $this->line('Authorization header:');
        $this->line('Authorization: Basic '.base64_encode("{$clientId}:{$clientSecret}"));

        return self::SUCCESS;
    }
}
