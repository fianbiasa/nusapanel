<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResetPasswordCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:reset-password
        {username : Account whose password to reset}
        {--password= : New password; a strong one is generated when omitted}';

    /**
     * @var string
     */
    protected $description = 'Reset an account password from the server, e.g. a lost admin password';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('username', (string) $this->argument('username'))->first();

        if ($user === null) {
            $this->components->error("No account named [{$this->argument('username')}].");

            return self::FAILURE;
        }

        $generated = $this->option('password') === null;
        $password = $generated ? Str::password(24) : (string) $this->option('password');

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password, $audit): void {
            $user->forceFill(['password' => $password])->save();

            // Sign the account out everywhere: whoever had the old password
            // must not keep a session.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $audit->record('account.password_reset', $user, ['via' => 'cli']);
        });

        $this->components->info("Password for [{$user->username}] reset; existing sessions were signed out.");

        if ($generated) {
            $this->components->warn("New password (shown only once): {$password}");
        }

        return self::SUCCESS;
    }
}
