<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'admin@ailolosiptn.com'], [
            'name' => 'Super Admin', 'password' => Hash::make('admin123!'),
            'role' => 'superadmin', 'tier' => 'premium',
            'onboarding_completed' => true, 'diagnostic_completed' => true,
            'email_verified_at' => now(),
        ]);

        User::firstOrCreate(['email' => 'demo@ailolosiptn.com'], [
            'name' => 'Demo Pejuang PTN', 'password' => Hash::make('demo123!'),
            'role' => 'user', 'tier' => 'free',
            'email_verified_at' => now(),
        ]);

        $this->command->info('✅ Users seeded.');
    }
}
