<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use App\Rules\ValidUsername;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nusapanel:create-admin
        {username : Login name, also used as the Linux user name}
        {email : Contact email address}
        {--name= : Display name (defaults to the username)}
        {--password= : Password; falls back to $NUSAPANEL_ADMIN_PASSWORD, then a generated one}';

    /**
     * @var string
     */
    protected $description = 'Create a panel administrator account (used by the installer)';

    public function handle(AuditLogger $audit): int
    {
        $username = (string) $this->argument('username');
        $email = Str::lower((string) $this->argument('email'));
        $password = $this->option('password') ?? $this->passwordFromEnvironment();
        $generated = $password === null;
        $password = $generated ? Str::password(24) : (string) $password;

        $validator = Validator::make(
            ['username' => $username, 'email' => $email, 'password' => $password],
            [
                'username' => ['required', new ValidUsername, 'unique:users,username'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $admin = new User([
            'username' => $username,
            'name' => (string) ($this->option('name') ?? $username),
            'email' => $email,
            'password' => $password,
        ]);

        // One transaction, so a failed audit write never leaves an admin behind
        // that a re-run of the installer would then trip over.
        DB::transaction(function () use ($admin, $audit): void {
            $admin->forceFill([
                'role' => Role::Admin,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $audit->record('account.created', $admin, ['role' => Role::Admin->value, 'via' => 'cli']);
        });

        $this->components->info("Admin account [{$admin->username}] created.");

        if ($generated) {
            $this->components->warn("Generated password (shown only once): {$password}");
        }

        return self::SUCCESS;
    }

    /**
     * The installer passes the first password through the environment so it
     * never appears in the process list, unlike a command-line option.
     */
    private function passwordFromEnvironment(): ?string
    {
        $password = getenv('NUSAPANEL_ADMIN_PASSWORD');

        return is_string($password) && $password !== '' ? $password : null;
    }
}
