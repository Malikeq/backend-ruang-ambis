<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('user');
            $table->enum('tier', ['free', 'premium', 'daily_pass'])->default('free');
            $table->unsignedBigInteger('points')->default(0);
            $table->unsignedInteger('streak_days')->default(0);
            $table->timestamp('last_active')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->boolean('onboarding_completed')->default(false);
            $table->boolean('diagnostic_completed')->default(false);
            $table->string('avatar_url')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // ── Kampus (seeded from api.co.id) ──────────────────
        Schema::create('kampus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_id')->nullable()->unique()->comment('id from api.co.id');
            $table->string('nama');
            $table->string('akronim', 30);
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('tipe', 20)->default('PTN');
            $table->string('group', 10)->default('PTN');
            $table->text('alamat')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('logo_url')->nullable();
            $table->timestamps();

            $table->index(['group', 'provinsi']);
        });

        Schema::create('jurusan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kampus_id')->constrained('kampus')->cascadeOnDelete();
            $table->string('nama');
            $table->string('fakultas')->nullable();
            $table->decimal('passing_grade_estimate', 5, 2)->nullable();
            $table->unsignedInteger('peminat_tahun_lalu')->nullable();
            $table->timestamps();

            $table->index(['kampus_id']);
        });

        Schema::create('user_kampus_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kampus_id')->constrained('kampus')->cascadeOnDelete();
            $table->foreignId('jurusan_id')->constrained('jurusan')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->timestamps();
        });

        Schema::create('mapel', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('kode', 10)->unique();
            $table->decimal('snbt_weight', 4, 2)->default(1.00);
            $table->timestamps();
        });

        Schema::create('sub_materi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->string('nama');
            $table->text('deskripsi')->nullable();
            $table->timestamps();
        });

        Schema::create('soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel');
            $table->foreignId('sub_materi_id')->constrained('sub_materi');
            $table->text('konten');
            $table->enum('tipe', ['MC', 'BS', 'MJ'])->default('MC');
            $table->enum('tingkat_kesulitan', ['mudah', 'sedang', 'sulit'])->default('sedang');
            $table->decimal('irt_a', 5, 3)->nullable();
            $table->decimal('irt_b', 5, 3)->nullable();
            $table->decimal('irt_c', 5, 3)->nullable();
            $table->unsignedSmallInteger('sumber_tahun')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->boolean('is_published')->default(false);
            $table->text('source_chunk_ref')->nullable();
            $table->timestamps();
            $table->index(['mapel_id', 'is_published']);
        });

        Schema::create('pilihan_jawaban', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soal_id')->constrained('soal')->cascadeOnDelete();
            $table->string('label', 1);
            $table->text('konten');
            $table->boolean('is_correct')->default(false);
            $table->timestamps();
        });

        Schema::create('pembahasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soal_id')->constrained('soal')->cascadeOnDelete();
            $table->json('langkah_langkah');
            $table->enum('locked_by_tier', ['free', 'premium', 'daily_pass'])->nullable();
            $table->timestamps();
        });

        Schema::create('material_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users');
            $table->string('filename');
            $table->string('file_type', 20);
            $table->string('file_url');
            $table->enum('status', ['processing', 'done', 'failed'])->default('processing');
            $table->json('target_mapel_ids');
            $table->unsignedSmallInteger('jumlah_soal_target')->default(10);
            $table->timestamps();
        });

        Schema::create('ai_draft_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('material_uploads')->cascadeOnDelete();
            $table->json('draft');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sesi_latihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('tipe', ['harian', 'ujian', 'diagnostic'])->default('harian');
            $table->json('soal_ids');
            $table->timestamp('mulai')->useCurrent();
            $table->timestamp('selesai')->nullable();
            $table->decimal('skor_raw', 5, 2)->nullable();
            $table->decimal('skor_irt', 5, 3)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tipe', 'created_at']);
        });

        Schema::create('user_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('soal_id')->constrained('soal')->cascadeOnDelete();
            $table->foreignId('sesi_latihan_id')->constrained('sesi_latihan')->cascadeOnDelete();
            $table->foreignId('jawaban_id')->nullable()->constrained('pilihan_jawaban')->nullOnDelete();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('waktu_ms')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'soal_id', 'created_at']);
        });

        Schema::create('weakness_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_materi_id')->constrained('sub_materi')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('accuracy_rate', 5, 2)->default(0);
            $table->unsignedInteger('avg_waktu_ms')->default(0);
            $table->boolean('is_flagged')->default(false);
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'sub_materi_id']);
        });

        Schema::create('ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soal_id')->unique()->constrained('soal')->cascadeOnDelete();
            $table->longText('konten_cached');
            $table->string('model_used', 60)->default('gemini-1.5-flash');
            $table->unsignedInteger('token_used')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fitur', 50);
            $table->string('model', 60);
            $table->unsignedInteger('token_in')->default(0);
            $table->unsignedInteger('token_out')->default(0);
            $table->decimal('cost_idr', 10, 4)->default(0);
            $table->boolean('cached')->default(false);
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->unsignedInteger('harga_idr');
            $table->unsignedSmallInteger('durasi_hari');
            $table->json('fitur_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->unsignedTinyInteger('diskon_persen');
            $table->unsignedInteger('max_uses')->default(100);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages');
            $table->timestamp('mulai')->useCurrent();
            $table->timestamp('selesai')->nullable();
            $table->string('payment_id')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamps();
        });

        Schema::create('points_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('jumlah');
            $table->enum('tipe', ['earn', 'spend']);
            $table->string('alasan');
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages');
            $table->unsignedInteger('gross_amount');
            $table->string('payment_method', 50)->nullable();
            $table->string('midtrans_order_id')->unique()->nullable();
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('points_transactions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('ai_call_logs');
        Schema::dropIfExists('ai_explanations');
        Schema::dropIfExists('weakness_reports');
        Schema::dropIfExists('user_attempts');
        Schema::dropIfExists('sesi_latihan');
        Schema::dropIfExists('ai_draft_soal');
        Schema::dropIfExists('material_uploads');
        Schema::dropIfExists('pembahasan');
        Schema::dropIfExists('pilihan_jawaban');
        Schema::dropIfExists('soal');
        Schema::dropIfExists('sub_materi');
        Schema::dropIfExists('mapel');
        Schema::dropIfExists('user_kampus_targets');
        Schema::dropIfExists('jurusan');
        Schema::dropIfExists('kampus');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
