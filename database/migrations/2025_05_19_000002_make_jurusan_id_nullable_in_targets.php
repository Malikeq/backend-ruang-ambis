<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_kampus_targets')) return;
        if (!Schema::hasColumn('user_kampus_targets', 'jurusan_id')) return;

        // Make jurusan_id nullable so users can save a PTN target
        // without having picked a specific jurusan yet.
        // Drop the existing FK constraint first, then re-add as nullable.
        try {
            DB::statement('ALTER TABLE user_kampus_targets DROP FOREIGN KEY user_kampus_targets_jurusan_id_foreign');
        } catch (\Exception) { /* may already be gone */ }

        DB::statement('ALTER TABLE user_kampus_targets MODIFY jurusan_id BIGINT UNSIGNED NULL');

        DB::statement('ALTER TABLE user_kampus_targets ADD CONSTRAINT user_kampus_targets_jurusan_id_foreign
            FOREIGN KEY (jurusan_id) REFERENCES jurusan(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_kampus_targets')) return;

        try {
            DB::statement('ALTER TABLE user_kampus_targets DROP FOREIGN KEY user_kampus_targets_jurusan_id_foreign');
        } catch (\Exception) {}

        DB::statement('ALTER TABLE user_kampus_targets MODIFY jurusan_id BIGINT UNSIGNED NOT NULL');

        DB::statement('ALTER TABLE user_kampus_targets ADD CONSTRAINT user_kampus_targets_jurusan_id_foreign
            FOREIGN KEY (jurusan_id) REFERENCES jurusan(id) ON DELETE CASCADE');
    }
};
