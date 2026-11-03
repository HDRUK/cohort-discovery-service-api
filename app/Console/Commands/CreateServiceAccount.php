<?php

namespace App\Console\Commands;

use App\Models\ServiceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateServiceAccount extends Command
{
    protected $signature = 'service-account:create
        {name : Name of the service account, e.g. scheduler}
        {--regenerate : Replace the secret of an existing service account, keeping its client ID}';

    protected $description = 'Create a service account for the service layer and print its Basic Auth credentials once.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('Name cannot be empty.');

            return self::FAILURE;
        }

        $serviceAccount = ServiceAccount::where('name', $name)->first();

        if ($serviceAccount && ! $this->option('regenerate')) {
            $this->error("Service account [{$name}] already exists. Use --regenerate to replace its secret.");

            return self::FAILURE;
        }

        $clientSecret = Str::random(64);

        if ($serviceAccount) {
            $serviceAccount->update(['client_secret' => $clientSecret]);
            $this->info("Regenerated the secret for service account [{$name}]. The previous secret no longer works.");
        } else {
            $serviceAccount = ServiceAccount::create([
                'name' => $name,
                'client_id' => (string) Str::uuid(),
                'client_secret' => $clientSecret,
            ]);
            $this->info("Created service account [{$name}].");
        }

        $clientId = $serviceAccount->client_id;

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
