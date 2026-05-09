<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Mapel;
use App\Models\SubMateri;
use App\Models\Kampus;
use App\Models\Jurusan;
use App\Models\Soal;
use App\Models\PilihanJawaban;
use App\Models\Package;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,   // ← first, so admin exists before other seeders
            UserSeeder::class,
            MapelSeeder::class,
            KampusSeeder::class,
            PackageSeeder::class,
            SoalSeeder::class,
        ]);
    }
}
