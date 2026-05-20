<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add asal_sekolah to users
        if (!Schema::hasColumn('users', 'asal_sekolah')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('asal_sekolah', 200)->nullable()->after('referral_source');
            });
        }

        // Add skor_akhir (SNBT scale 400-800) to sesi_latihan
        if (Schema::hasTable('sesi_latihan') && !Schema::hasColumn('sesi_latihan', 'skor_akhir')) {
            Schema::table('sesi_latihan', function (Blueprint $table) {
                $table->float('skor_akhir')->nullable()->after('skor_raw')
                    ->comment('Estimated SNBT score (400-800 scale)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'asal_sekolah')) {
            Schema::table('users', fn(Blueprint $t) => $t->dropColumn('asal_sekolah'));
        }
        if (Schema::hasTable('sesi_latihan') && Schema::hasColumn('sesi_latihan', 'skor_akhir')) {
            Schema::table('sesi_latihan', fn(Blueprint $t) => $t->dropColumn('skor_akhir'));
        }
    }
};
