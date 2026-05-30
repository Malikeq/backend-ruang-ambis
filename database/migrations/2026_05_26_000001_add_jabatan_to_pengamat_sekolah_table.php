<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengamat_sekolah', function (Blueprint $table) {
            if (!Schema::hasColumn('pengamat_sekolah', 'jabatan')) {
                $table->string('jabatan', 100)->nullable()->after('sekolah_id')
                      ->comment('Jabatan: guru_bk, wali_kelas, kepala_sekolah, lainnya');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pengamat_sekolah', function (Blueprint $table) {
            if (Schema::hasColumn('pengamat_sekolah', 'jabatan')) {
                $table->dropColumn('jabatan');
            }
        });
    }
};
