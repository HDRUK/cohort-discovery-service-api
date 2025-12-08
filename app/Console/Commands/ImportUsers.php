<?php

namespace App\Console\Commands;

use App\Models\Custodian;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\UserHasRole;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;
use App\Models\CustodianHasUser;
use Spatie\Permission\Models\Role;

class ImportUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Examples:
     *  php artisan user:import --name="John Doe" --email=john@example.com --password=secret
     *  php artisan user:import --file=/path/to/users.csv
     */
    protected $signature = 'user:import
                            {--name= : Name of the user}
                            {--email= : Email of the user}
                            {--password= : Plain text password}
                            {--file= : Path to CSV file}';

    /**
     * The console command description.
     */
    protected $description = 'Create users from command line options or from a CSV file.';

    /**
     * Store generated passwords so we can print them at the end.
     *
     * @var array<int, array{email:string,password:string}>
     */
    protected array $generatedPasswords = [];

    protected Custodian $custodian;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $file = $this->option('file');

        $this->custodian = Custodian::firstOrCreate(
            ['name' => 'Health Data Research UK'],
            [
                'external_custodian_id' => null,
                'external_custodian_name' => null,
            ]
        );

        $this->custodian->update([
            'external_custodian_id' => $this->custodian->id,
            'external_custodian_name' => $this->custodian->name
        ]);


        if ($file) {
            $result = $this->importFromFile($file);
        } else {
            $result = $this->createSingleUser();
        }

        $this->printGeneratedPasswordsSummary();

        return $result;
    }

    protected function createSingleUser(): int
    {
        $name  = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');

        // If password option is not given, generate one.
        $password = $this->option('password');

        if (! $password) {
            $password = $this->generatePassword();
            $this->generatedPasswords[] = [
                'email'    => $email,
                'password' => $password,
            ];
        }
        $user = User::firstOrCreate([
            'name'     => $name,
            'email'    => $email,
        ], [
            'password' => Hash::make($password),
        ]);

<<<<<<< HEAD
        CustodianHasUser::create([
=======
        CustodianHasUser::firstOrCreate([
>>>>>>> feat/DP-288-2
           'user_id' => $user->id,
           'custodian_id' => $this->custodian->id
        ]);

        $this->addRole($user, 'admin');
        $this->addToWorkgroup($user, 'ADMIN');


        $this->info("Created user #{$user->id} ({$user->email})");

        return self::SUCCESS;
    }

    protected function importFromFile(string $file): int
    {
        if (! file_exists($file) || ! is_readable($file)) {
            $this->error("File [{$file}] does not exist or is not readable.");
            return self::FAILURE;
        }

        if (($handle = fopen($file, 'r')) === false) {
            $this->error("Could not open file [{$file}].");
            return self::FAILURE;
        }

        // Expect header row: name,email,password
        $header = fgetcsv($handle);

        if (! $header || ! in_array('email', $header)) {
            $this->error('CSV must have at least an "email" column (ideally: name,email,password).');
            fclose($handle);
            return self::FAILURE;
        }

        $rowNumber = 1;
        $created   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            $data = array_combine($header, $row);

            $name     = $data['name']     ?? null;
            $email    = $data['email']    ?? null;
            $password = $data['password'] ?? null;

            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Row {$rowNumber}: invalid or missing email, skipping.");
                continue;
            }

            if (! $password) {
                $password = $this->generatePassword();
                $this->generatedPasswords[] = [
                    'email'    => $email,
                    'password' => $password,
                ];
            }

            $user = User::firstOrCreate([
                'name'     => $name ?: 'Unnamed User',
                'email'    => $email
            ], [
                'password' => Hash::make($password),
            ]);

            CustodianHasUser::firstOrCreate([
                'user_id' => $user->id,
                'custodian_id' => $this->custodian->id
            ]);

            $this->addRole($user, 'admin');
            $this->addToWorkgroup($user, 'ADMIN');


            $created++;
            $this->line("Row {$rowNumber}: created user #{$user->id} ({$user->email})");
        }

        fclose($handle);

        $this->info("Done. Created {$created} user(s).");

        return self::SUCCESS;
    }

    /**
     * Generate a random password of 7 letters (A–Z, a–z).
     */
    protected function generatePassword(int $length = 7): string
    {
        if ($length < 3) {
            throw new \InvalidArgumentException('Password length must be at least 3.');
        }

        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits    = '0123456789';
        $special   = '!?.,;:#^%';


        $all = $uppercase . $lowercase . $digits . $special;

        $password = [];

        $password[] = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password[] = $digits[random_int(0, strlen($digits) - 1)];
        $password[] = $special[random_int(0, strlen($special) - 1)];


        for ($i = 3; $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle
        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    /**
     * Print all generated passwords at the end of the command.
     */
    protected function printGeneratedPasswordsSummary(): void
    {
        if (empty($this->generatedPasswords)) {
            return;
        }

        $this->newLine();
        $this->info('Generated passwords (store these securely):');

        foreach ($this->generatedPasswords as $entry) {
            $this->line(" - {$entry['email']}: {$entry['password']}");
        }

        $this->newLine();
        $this->warn('Make sure to save these passwords somewhere secure; they will not be shown again.');
    }


    private function addToWorkgroup(User $user, string $workgroup): void
    {
        $workgroup = Workgroup::where('name', $workgroup)->firstOrFail();
        UserHasWorkgroup::create([
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id
        ]);

<<<<<<< HEAD
=======
        $this->info("... added to workgroup {$user->id} {$workgroup->id}");

>>>>>>> feat/DP-288-2
    }

    private function addRole(User $user, string $role): void
    {
        $role = Role::where('name', $role)->firstOrFail();
        UserHasRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

}
