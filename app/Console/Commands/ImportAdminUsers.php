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
use App\Traits\HelperFunctions;
use Spatie\Permission\Models\Role;

class ImportAdminUsers extends Command
{
    use HelperFunctions;

    protected $signature = 'admin-user:import
                            {--name= : Name of the user}
                            {--email= : Email of the user}
                            {--password= : Plain text password}
                            {--admin= : Whether user should be admin (1/0, true/false)}
                            {--workgroup= : Additional workgroup to add}
                            {--custodian= : Whether user should belong to the default custodian (1/0, true/false)}
                            {--file= : Path to CSV file}';

    protected $description = 'Create users from command line options or from a CSV file.';

    /**
     * @var array<int, array{email:string,password:string}>
     */
    protected array $generatedPasswords = [];

    protected Custodian $custodian;

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
        $name          = $this->option('name') ?: $this->ask('Name');
        $email         = $this->option('email') ?: $this->ask('Email');
        $password      = $this->option('password');
        $isAdmin       = $this->toBool($this->option('admin'));
        $workgroup     = $this->option('workgroup');
        $hasCustodian  = $this->toBool($this->option('custodian'));

        $user = $this->createUserWithDefaults(
            email: $email,
            name: $name,
            password: $password,
            isAdmin: $isAdmin,
            extraWorkgroup: $workgroup,
            hasCustodian: $hasCustodian
        );

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

        $header = fgetcsv($handle);

        if (! $header || ! in_array('email', $header, true)) {
            $this->error('CSV must have at least an "email" column. Optional columns: name,password,admin,workgroup,custodian');
            fclose($handle);
            return self::FAILURE;
        }

        $rowNumber = 1;
        $created   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($row) !== count($header)) {
                $this->warn("Row {$rowNumber}: column count mismatch, skipping.");
                continue;
            }

            $data = array_combine($header, $row);

            $name         = $data['name'] ?? null;
            $email        = $data['email'] ?? null;
            $password     = $data['password'] ?? null;
            $isAdmin      = $this->toBool($data['admin'] ?? null);
            $workgroup    = $data['workgroup'] ?? null;
            $hasCustodian = $this->toBool($data['custodian'] ?? null);

            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Row {$rowNumber}: invalid or missing email, skipping.");
                continue;
            }

            try {
                $user = $this->createUserWithDefaults(
                    email: $email,
                    name: $name,
                    password: $password,
                    isAdmin: $isAdmin,
                    extraWorkgroup: $workgroup,
                    hasCustodian: $hasCustodian
                );

                $action = $user->wasRecentlyCreated ? 'created' : 'existing';

                if ($user->wasRecentlyCreated) {
                    $created++;
                }

                $this->line("Row {$rowNumber}: {$action} user #{$user->id} ({$user->email})");
            } catch (\Throwable $e) {
                $this->warn("Row {$rowNumber}: failed for {$email}: {$e->getMessage()}");
            }
        }

        fclose($handle);

        $this->info("Done. Created {$created} user(s).");

        return self::SUCCESS;
    }

    private function createUserWithDefaults(
        string $email,
        ?string $name = null,
        ?string $password = null,
        bool $isAdmin = false,
        ?string $extraWorkgroup = null,
        bool $hasCustodian = false
    ): User {
        $user = $this->createUser($email, $name, $password);

        $this->syncCustodianMembership($user, $hasCustodian);

        $this->addToWorkgroup($user, 'DEFAULT');

        if ($extraWorkgroup) {
            $this->addToWorkgroup($user, trim($extraWorkgroup));
        }

        $this->addRole($user, 'user');
        if ($isAdmin) {
            $this->addRole($user, 'admin');
        }

        return $user;
    }

    private function createUser(string $email, ?string $name = null, ?string $password = null): User
    {
        $generated = false;

        if (! $password) {
            $password  = $this->generatePassword();
            $generated = true;
        }

        $user = User::firstOrCreate(
            [
                'email' => $email,
            ],
            [
                'name'     => $name ?: 'Unnamed User',
                'password' => Hash::make($password),
            ]
        );

        if ($name && $user->name !== $name) {
            $user->name = $name;
            $user->save();
        }

        if ($generated && $user->wasRecentlyCreated) {
            $this->generatedPasswords[] = [
                'email'    => $email,
                'password' => $password,
            ];
        }

        return $user;
    }

    private function syncCustodianMembership(User $user, bool $hasCustodian): void
    {
        $query = CustodianHasUser::where([
            'user_id'      => $user->id,
            'custodian_id' => $this->custodian->id,
        ]);

        if ($hasCustodian) {
            $query->firstOrCreate([]);
            $this->info("... ensured user {$user->id} is linked to custodian {$this->custodian->id}");
            return;
        }

        $deleted = $query->delete();

        if ($deleted) {
            $this->info("... removed user {$user->id} from custodian {$this->custodian->id}");
        } else {
            $this->info("... ensured user {$user->id} is not linked to custodian {$this->custodian->id}");
        }
    }

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

        $this->info("... ensured user {$user->id} is in workgroup {$workgroup->name}");
    }

    private function addRole(User $user, string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        UserHasRole::firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'y'],
            true
        );
    }
}
