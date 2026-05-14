<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * AdminSeeder — creates / upgrades admin accounts.
 *
 * Run standalone:  php artisan db:seed --class=AdminSeeder
 * Run all:         php artisan db:seed
 *
 * Accounts created/upgraded:
 *  1. admin@ailolosiptn.com  / admin123!      (default system admin)
 *  2. michakleb8@gmail.com   / gbzky9vk       (owner / developer account)
 */
class AdminSeeder extends Seeder
{
    private array $admins = [
        [
            'email'    => 'admin@ailolosiptn.com',
            'name'     => 'Super Admin',
            'password' => 'admin123!',
        ],
        [
            'email'    => 'michakleb8@gmail.com',
            'name'     => 'Owner / Dev',
            'password' => 'gbzky9vk',
        ],
    ];

    public function run(): void
    {
        foreach ($this->admins as $admin) {
            $user = User::firstOrNew(['email' => $admin['email']]);

            $user->fill([
                'name'                 => $admin['name'],
                'role'                 => 'superadmin',
                'tier'                 => 'premium',
                'onboarding_completed' => true,
                'diagnostic_completed' => true,
                'email_verified_at'    => now(),
                'points'               => $user->points ?? 0,
                'streak_days'          => $user->streak_days ?? 0,
                'password'             => Hash::make($admin['password']),  // always sync
            ]);

            $user->save();

            $action = $user->wasRecentlyCreated ? 'Created' : 'Password reset & upgraded';
            $this->command->info("  ✅ [{$action}] {$admin['email']} / {$admin['password']}");
        }

        $count = User::where('role', 'superadmin')->count();
        $this->command->info("Total admin accounts: {$count}");
    }
}
