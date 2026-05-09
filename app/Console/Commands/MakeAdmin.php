<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * php artisan admin:create
 * php artisan admin:create --email=foo@bar.com --password=secret123
 * php artisan admin:promote --email=existing@user.com
 */
class MakeAdmin extends Command
{
    protected $signature = 'admin:create
                            {--email=   : Email address of the admin}
                            {--name=    : Display name}
                            {--password= : Password (min 8 chars)}
                            {--promote  : Promote existing user (skip password prompt)}';

    protected $description = 'Create a new superadmin account or promote an existing user';

    public function handle(): int
    {
        $this->info('');
        $this->info('┌─────────────────────────────────────┐');
        $this->info('│       AI Lolos PTN — Admin Setup     │');
        $this->info('└─────────────────────────────────────┘');
        $this->info('');

        $email    = $this->option('email')    ?? $this->ask('Email');
        $promote  = $this->option('promote');

        // ── Promote existing user ───────────────────────────
        $existing = User::where('email', $email)->first();

        if ($existing && $promote) {
            $existing->update(['role' => 'superadmin', 'tier' => 'premium', 'onboarding_completed' => true]);
            $this->info("✅ Promoted {$email} to superadmin.");
            return self::SUCCESS;
        }

        if ($existing && !$promote) {
            if ($this->confirm("User {$email} already exists. Promote to superadmin?", true)) {
                $existing->update(['role' => 'superadmin', 'tier' => 'premium', 'onboarding_completed' => true]);
                $this->info("✅ Promoted {$email} to superadmin.");
                return self::SUCCESS;
            }
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        // ── Create new admin ────────────────────────────────
        $name     = $this->option('name')     ?? $this->ask('Name', 'Super Admin');
        $password = $this->option('password') ?? $this->secret('Password (min 8 chars)');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $user = User::create([
            'name'                 => $name,
            'email'                => $email,
            'password'             => Hash::make($password),
            'role'                 => 'superadmin',
            'tier'                 => 'premium',
            'onboarding_completed' => true,
            'diagnostic_completed' => true,
            'email_verified_at'    => now(),
        ]);

        $this->info('');
        $this->info("✅ Admin account created!");
        $this->table(['Field', 'Value'], [
            ['Email',    $email],
            ['Name',     $name],
            ['Role',     'superadmin'],
            ['URL',      'http://localhost:3000/admin/dashboard'],
        ]);

        return self::SUCCESS;
    }
}
