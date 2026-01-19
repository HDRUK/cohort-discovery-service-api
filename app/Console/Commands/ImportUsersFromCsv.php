<?php

namespace App\Console\Commands;

use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Models\UserHasRole;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use App\Traits\HelperFunctions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ImportUsersFromCsv extends Command
{
    use HelperFunctions;

    protected $signature = 'user:import-csv
                            {--file= : Path to CSV file}
                            {--delimiter=, : CSV delimiter (default: ,)}
                            {--password-length=12 : Generated password length (default: 12)}';

    protected $description = 'Import users from a CSV (name,email,custodian,workgroup,role). Generates passwords for newly-created users and prints them at the end.';

    /**
     * @var array<int, array{email:string,password:string}>
     */
    protected array $generatedPasswords = [];

    /**
     * @var array<int, array{email:string, status:string, id:int|null}>
     */
    protected array $processedUsers = [];

    protected int $skippedRows = 0;

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $delimiter = (string) ($this->option('delimiter') ?? ',');
        $passwordLength = (int) ($this->option('password-length') ?? 12);

        if (! $file) {
            $this->error('Missing required option: --file=/path/to/users.csv');
            return self::FAILURE;
        }

        if (! file_exists($file) || ! is_readable($file)) {
            $this->error("File [{$file}] does not exist or is not readable.");
            return self::FAILURE;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error("Could not open file [{$file}].");
            return self::FAILURE;
        }

        $header = fgetcsv($handle, 0, $delimiter);
        if (! $header) {
            $this->error('CSV appears to be empty.');
            fclose($handle);
            return self::FAILURE;
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $required = ['name', 'email', 'custodian', 'workgroup', 'role'];
        $missing = array_values(array_diff($required, $header));
        if (! empty($missing)) {
            $this->error('CSV is missing required column(s): ' . implode(', ', $missing));
            $this->line('Expected header: name,email,custodian,workgroup,role');
            fclose($handle);
            return self::FAILURE;
        }

        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), null);
            }

            $data = array_combine($header, $row);
            if ($data === false) {
                $this->warn("Row {$rowNumber}: could not parse, skipping.");
                $this->skippedRows++;
                continue;
            }

            $name          = $this->clean($data['name'] ?? null);
            $email         = $this->clean($data['email'] ?? null);
            $custodianName = $this->clean($data['custodian'] ?? null);
            $workgroupName = $this->clean($data['workgroup'] ?? null);
            $roleName      = $this->clean($data['role'] ?? null);

            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Row {$rowNumber}: invalid or missing email, skipping.");
                $this->skippedRows++;
                continue;
            }

            if (! $custodianName || ! $workgroupName || ! $roleName) {
                $this->warn("Row {$rowNumber}: custodian/workgroup/role must be present, skipping.");
                $this->skippedRows++;
                continue;
            }

            $workgroup = Workgroup::where('name', $workgroupName)->first();
            if (! $workgroup) {
                $this->warn("Row {$rowNumber}: workgroup [{$workgroupName}] not found, skipping.");
                $this->skippedRows++;
                continue;
            }

            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                $this->warn("Row {$rowNumber}: role [{$roleName}] not found, skipping.");
                $this->skippedRows++;
                continue;
            }

            $custodian = $this->getOrCreateCustodian($custodianName);

            [$user, $status, $generatedPassword] = $this->getOrCreateUser($email, $name, $passwordLength);


            CustodianHasUser::firstOrCreate([
                'user_id'      => $user->id,
                'custodian_id' => $custodian->id,
            ]);

            UserHasRole::firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);

            UserHasWorkgroup::firstOrCreate([
                'user_id'      => $user->id,
                'workgroup_id' => $workgroup->id,
            ]);

            $this->processedUsers[] = [
                'email'  => $email,
                'status' => $status,
                'id'     => $user->id,
            ];

            $msg = "Row {$rowNumber}: {$status} user #{$user->id} ({$user->email}) "
                 . "| custodian={$custodian->name} workgroup={$workgroup->name} role={$role->name}";
            $this->line($msg);

            if ($generatedPassword !== null) {
                $this->generatedPasswords[] = [
                    'email'    => $email,
                    'password' => $generatedPassword,
                ];
            }
        }

        fclose($handle);

        $this->newLine();
        $this->info('Import complete.');
        $this->line('Processed users: ' . count($this->processedUsers));
        $this->line('Skipped rows: ' . $this->skippedRows);

        $this->printGeneratedPasswordsSummary();

        return self::SUCCESS;
    }

    private function clean(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function getOrCreateCustodian(string $custodianName): Custodian
    {
        $custodian = Custodian::firstOrCreate(
            ['name' => $custodianName],
            [
                'external_custodian_id'   => null,
                'external_custodian_name' => null,
            ]
        );

        $custodian->update([
            'external_custodian_id'   => $custodian->id,
            'external_custodian_name' => $custodian->name,
        ]);

        return $custodian;
    }

    /**
     * @return array{0:User,1:string,2:?string} [user, status(created|existing), generatedPasswordOrNull]
     */
    private function getOrCreateUser(string $email, ?string $name, int $passwordLength): array
    {
        $generatedPassword = null;

        $user = User::where('email', $email)->first();

        if (! $user) {
            $generatedPassword = $this->generatePassword($passwordLength);

            $user = User::create([
                'name'     => $name ?: 'Unnamed User',
                'email'    => $email,
                'password' => Hash::make($generatedPassword),
            ]);

            return [$user, 'created', $generatedPassword];
        }

        if ($name && $user->name !== $name) {
            $user->update(['name' => $name]);
        }

        return [$user, 'existing', null];
    }

    protected function printGeneratedPasswordsSummary(): void
    {
        if (empty($this->generatedPasswords)) {
            $this->newLine();
            $this->info('No new users were created, so no passwords were generated.');
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
}
