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
use Illuminate\Support\Facades\DB;

class ImportUsersFromCsv extends Command
{
    use HelperFunctions;

    protected $signature = 'user:import-csv
                            {--file= : Path to CSV file}
                            {--delimiter=, : CSV delimiter (default: ,)}
                            {--password-length=12 : Generated password length (default: 12)}
                            {--remove : Remove (delete) users listed in the CSV instead of importing}';

    protected $description = 'Import users from a CSV (name,email,custodian,workgroup,role). Generates passwords for newly-created users and prints them at the end.';

    /**
     * @var array<int, array{email:string,password:string}>
     */
    protected array $generatedPasswords = [];

    /**
     * @var array<int, array{email:string, status:string, id:int|null}>
     */
    protected array $processedUsers = [];

    /**
     * @var array<int, array{email:string, id:int|null}>
     */
    protected array $deletedUsers = [];

    protected int $skippedRows = 0;

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $delimiter = (string) ($this->option('delimiter') ?? ',');
        $passwordLength = (int) ($this->option('password-length') ?? 12);

        $required = ['name', 'email', 'custodian', 'workgroup', 'role'];

        try {
            $this->processCsvFile($this, $file, $delimiter, $required, function (array $data, int $rowNumber) use ($passwordLength) {
                if (!empty($data['__parse_error__'])) {
                    $this->warn("Row {$rowNumber}: could not parse, skipping.");
                    $this->skippedRows++;
                    return;
                }

                $name          = $this->clean($data['name'] ?? null);
                $email         = $this->clean($data['email'] ?? null);
                $custodianName = $this->clean($data['custodian'] ?? null);
                $workgroupName = $this->clean($data['workgroup'] ?? null);
                $roleName      = $this->clean($data['role'] ?? null);

                if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->warn("Row {$rowNumber}: invalid or missing email, skipping.");
                    $this->skippedRows++;
                    return;
                }

                if ($this->option('remove')) {
                    $this->forceDeleteUserByEmail($email, $rowNumber);
                    return;
                }

                if (! $custodianName || ! $workgroupName || ! $roleName) {
                    $this->warn("Row {$rowNumber}: custodian/workgroup/role must be present, skipping.");
                    $this->skippedRows++;
                    return;
                }

                $workgroup = Workgroup::where('name', $workgroupName)->first();
                if (! $workgroup) {
                    $this->warn("Row {$rowNumber}: workgroup [{$workgroupName}] not found, skipping.");
                    $this->skippedRows++;
                    return;
                }

                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    $this->warn("Row {$rowNumber}: role [{$roleName}] not found, skipping.");
                    $this->skippedRows++;
                    return;
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

                $this->line("Row {$rowNumber}: {$status} user #{$user->id} ({$user->email}) | custodian={$custodian->name} workgroup={$workgroup->name} role={$role->name}");

                if ($generatedPassword !== null) {
                    $this->generatedPasswords[] = [
                        'email'    => $email,
                        'password' => $generatedPassword,
                    ];
                }
            });
        } catch (\Throwable $e) {
            // processCsvFile already printed a useful error; just return failure
            return self::FAILURE;
        }

        $this->newLine();
        if ($this->option('remove')) {
            $this->info('Removal complete.');
            $this->line('Deleted users: ' . count($this->deletedUsers ?? []));
        } else {
            $this->info('Import complete.');
            $this->line('Processed users: ' . count($this->processedUsers));
            $this->printGeneratedPasswordsSummary();
        }

        $this->line('Skipped rows: ' . $this->skippedRows);

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

    private function forceDeleteUserByEmail(string $email, int $rowNumber): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->warn("Row {$rowNumber}: user not found for email [{$email}], skipping.");
            $this->skippedRows++;
            return;
        }

        DB::transaction(function () use ($user) {
            CustodianHasUser::where('user_id', $user->id)->delete();
            UserHasWorkgroup::where('user_id', $user->id)->delete();
            UserHasRole::where('user_id', $user->id)->delete();

            $user->forceDelete();

        });

        $this->deletedUsers[] = ['email' => $email, 'id' => $user->id];
        $this->line("Row {$rowNumber}: force deleted user #{$user->id} ({$email})");
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
