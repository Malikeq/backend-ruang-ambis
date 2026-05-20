<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Tabel sekolah formal ──────────────────────────────
        Schema::create('sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('slug')->unique();
            $table->string('kota', 100)->nullable();
            $table->string('provinsi', 100)->nullable();
            $table->string('npsn', 20)->nullable()->unique()->comment('Nomor Pokok Sekolah Nasional');
            $table->timestamps();
            $table->index('slug');
        });

        // ── 2. Relasi pengamat ↔ sekolah ────────────────────────
        Schema::create('pengamat_sekolah', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengamat_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sekolah_id')->constrained('sekolah')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan')->nullable()->comment('Catatan dari admin saat approve/reject');
            $table->timestamps();
            $table->unique(['pengamat_id', 'sekolah_id']);
        });

        // ── 3. Tambah sekolah_id ke users ───────────────────────
        if (!Schema::hasColumn('users', 'sekolah_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('sekolah_id')
                      ->nullable()
                      ->after('asal_sekolah')
                      ->constrained('sekolah')
                      ->nullOnDelete();
            });
        }

        // ── 4. Normalisasi asal_sekolah → sekolah table ──────────
        // Ambil semua nilai unik asal_sekolah yang tidak null/kosong
        $asalSekolahs = DB::table('users')
            ->whereNotNull('asal_sekolah')
            ->where('asal_sekolah', '!=', '')
            ->select('asal_sekolah')
            ->distinct()
            ->pluck('asal_sekolah');

        foreach ($asalSekolahs as $nama) {
            $nama  = trim($nama);
            if (!$nama) continue;

            $slug  = Str::slug($nama);

            // Handle duplicate slugs by appending suffix
            $original = $slug;
            $counter  = 1;
            while (DB::table('sekolah')->where('slug', $slug)->exists()) {
                $slug = $original . '-' . $counter++;
            }

            $sekolahId = DB::table('sekolah')->insertGetId([
                'nama'       => $nama,
                'slug'       => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Link semua user dengan asal_sekolah ini ke sekolah baru
            DB::table('users')
                ->where('asal_sekolah', $nama)
                ->update(['sekolah_id' => $sekolahId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'sekolah_id')) {
            Schema::table('users', fn(Blueprint $t) => $t->dropForeign(['sekolah_id']));
            Schema::table('users', fn(Blueprint $t) => $t->dropColumn('sekolah_id'));
        }
        Schema::dropIfExists('pengamat_sekolah');
        Schema::dropIfExists('sekolah');
    }
};
