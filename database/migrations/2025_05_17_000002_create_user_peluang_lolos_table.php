<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_peluang_lolos')) return;

        Schema::create('user_peluang_lolos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('jurusan_id');
            $table->foreign('jurusan_id')->references('id')->on('jurusan')->cascadeOnDelete();
            $table->string('kode_prodi', 10)->nullable();
            $table->decimal('skor_user', 6, 2)->nullable();
            $table->enum('status_lolos', ['AMAN','KUNING','MERAH','BELUM_DIHITUNG'])->default('BELUM_DIHITUNG');
            $table->decimal('probabilitas_persen', 5, 2)->nullable();
            $table->decimal('gap_skor', 6, 2)->nullable();
            $table->text('catatan_ai')->nullable();
            $table->timestamp('dihitung_pada')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'jurusan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_peluang_lolos');
    }
};
