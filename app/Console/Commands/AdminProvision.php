<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create or promote an administrator.
 *
 * Admin is deliberately NOT accepted by POST /api/register — that endpoint
 * used to mass-assign a client-supplied `role`, which let anyone self-register
 * as an administrator. This command is the supported way in: it requires shell
 * access to the app, so granting admin is an explicit operator decision rather
 * than something reachable over HTTP.
 *
 *   php artisan admin:create  --email=admin@fastnetstays.com --password=...
 *   php artisan admin:promote --email=someone@example.com
 *   php artisan admin:promote --email=someone@example.com --revoke
 */
class AdminProvision extends Command
{
    protected $signature = 'admin:create
                            {--email= : Email address for the administrator}
                            {--password= : Password (min 8 chars). Omit to generate one.}
                            {--name= : Display name. Defaults to the email local part.}
                            {--promote : Promote an existing account instead of creating one}
                            {--revoke : With --promote, demote back to customer}';

    protected $description = 'Create a new admin account, or promote/revoke an existing one';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid --email address.');

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($this->option('revoke')) {
            return $this->revoke($existing, $email);
        }

        if ($existing && ! $this->option('promote')) {
            $this->error("An account already exists for {$email}.");
            $this->line('  To make it an admin instead: php artisan admin:create --email=' . $email . ' --promote');

            return self::FAILURE;
        }

        if ($existing) {
            return $this->promote($existing);
        }

        return $this->create($email);
    }

    private function create(string $email): int
    {
        $password = (string) $this->option('password');

        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        } else {
            $generated = false;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $name = ucfirst(strtok($email, '@'));
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
            'role'     => 'admin',
        ]);

        // 'status' is not mass-assignable, so set it explicitly. Without this
        // the row keeps the column default and an admin created by an older
        // build can end up 'Pending Verification' and be refused at login.
        $user->forceFill([
            'status'            => 'Active',
            'email_verified_at' => now(),
        ])->save();

        $this->info("Admin created: {$user->email}");

        if ($generated) {
            $this->newLine();
            $this->line('  Generated password: ' . $password);
            $this->line('  Store it now — it is not shown again.');
        }

        $this->newLine();
        $this->line('  Sign in at /login?role=admin');

        return self::SUCCESS;
    }

    private function promote(User $user): int
    {
        $user->forceFill(['role' => 'admin', 'status' => 'Active'])->save();
        $user->tokens()->delete();

        $this->info("{$user->email} is now an admin.");
        $this->line('  Existing sessions were revoked so the new role takes effect.');

        return self::SUCCESS;
    }

    private function revoke(?User $user, string $email): int
    {
        if (! $user) {
            $this->error("No account found for {$email}.");

            return self::FAILURE;
        }

        if ($user->role !== 'admin') {
            $this->warn("{$email} is not an admin (role={$user->role}). Nothing to do.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => 'customer'])->save();
        $user->tokens()->delete();

        $this->info("{$email} is no longer an admin.");

        return self::SUCCESS;
    }
}