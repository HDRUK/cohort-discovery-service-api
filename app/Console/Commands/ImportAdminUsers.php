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

class ImportAdminUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Examples:
     *  php artisan user:import --name="John Doe" --email=john@example.com --password=secret
     *  php artisan user:import --file=/path/to/users.csv
     */
    protected $signature = 'admin-user:import
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
                'external_custodian_id'   => null,
                'external_custodian_name' => null,
            ]
        );

        $this->custodian->update([
            'external_custodian_id'   => $this->custodian->id,
            'external_custodian_name' => $this->custodian->name,
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
        $name     = $this->option('name') ?: $this->ask('Name');
        $email    = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password'); // may be null

        $user   = $this->createUserWithDefaults($email, $name, $password);
        $action = $user->wasRecentlyCreated ? 'Created' : 'Existing';

        $this->info("{$action} user #{$user->id} ({$user->email})");

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

            $user   = $this->createUserWithDefaults($email, $name, $password);
            $action = $user->wasRecentlyCreated ? 'created' : 'existing';

            if ($user->wasRecentlyCreated) {
                $created++;
            }

            $this->line("Row {$rowNumber}: {$action} user #{$user->id} ({$user->email})");
        }

        fclose($handle);

        $this->info("Done. Created {$created} user(s).");

        return self::SUCCESS;
    }

    /**
     * Create or get a user, attach custodian, role and workgroup.
     */
    private function createUserWithDefaults(string $email, ?string $name = null, ?string $password = null): User
    {
        $user = $this->createUser($email, $name, $password);

        // Ensure user <-> custodian
        CustodianHasUser::firstOrCreate([
            'user_id'      => $user->id,
            'custodian_id' => $this->custodian->id,
        ]);

        // Ensure role & workgroup
        $this->addRole($user, 'admin');
        $this->addToWorkgroup($user, 'ADMIN');

        return $user;
    }

    /**
     * Create (or fetch) a user and handle password generation + tracking.
     */
    private function createUser(string $email, ?string $name = null, ?string $password = null): User
    {
        $generated = false;

        if (! $password) {
            $password  = $this->generatePassword();
            $generated = true;
        }

        $user = User::firstOrCreate(
            [
                'name'  => $name ?: 'Unnamed User',
                'email' => $email,
            ],
            [
                'password' => Hash::make($password),
            ]
        );

        // Only store the generated password if we actually created a new user.
        if ($generated && $user->wasRecentlyCreated) {
            $this->generatedPasswords[] = [
                'email'    => $email,
                'password' => $password,
            ];
        }

        return $user;
    }

    /**
     * Generate a random password of 7 characters from:
     *  - uppercase letters
     *  - lowercase letters
     *  - digits
     *  - special characters
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

        // Ensure at least one of each required type
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

    private function addToWorkgroup(User $user, string $workgroupName): void
    {
        $workgroup = Workgroup::where('name', $workgroupName)->firstOrFail();

        UserHasWorkgroup::firstOrCreate([
            'user_id'      => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);

        $this->info("... ensured user {$user->id} is in workgroup {$workgroup->id}");
    }

    private function addRole(User $user, string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        UserHasRole::firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }
}
