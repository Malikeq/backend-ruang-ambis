<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('prodi_statistik')) {
            Schema::create('prodi_statistik', function (Blueprint $table) {
                $table->id();
                $table->string('kode_ptn', 10);
                $table->string('nama_ptn', 200);
                $table->string('kode_prodi', 10)->index();
                $table->string('nama_prodi', 200);
                $table->enum('jenjang', ['S1','D3','D4'])->default('S1');
                $table->enum('kelompok_ujian', ['SAINTEK','SOSHUM','CAMPURAN'])->default('SAINTEK');
                $table->smallInteger('tahun')->unsigned();
                $table->smallInteger('kuota_snbt')->unsigned()->default(0);
                $table->integer('peminat_snbt')->unsigned()->default(0);
                $table->decimal('rerata_skor_diterima', 6, 2)->nullable();
                $table->decimal('skor_minimum_diterima', 6, 2)->nullable();
                $table->decimal('skor_maksimum_diterima', 6, 2)->nullable();
                $table->decimal('keketatan_persen', 5, 2)->nullable()->index();
                $table->enum('kategori_keketatan', ['LONGGAR','SEDANG','KETAT','SANGAT_KETAT'])->nullable();
                $table->decimal('skor_aman', 6, 2)->nullable();
                $table->decimal('skor_kuning', 6, 2)->nullable();
                $table->smallInteger('kuota_snbp')->unsigned()->default(0);
                $table->integer('peminat_snbp')->unsigned()->default(0);
                $table->timestamps();
                $table->unique(['kode_prodi', 'tahun']);
                $table->index('tahun');
            });
        }

        if (!Schema::hasTable('user_peluang_lolos')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('user_peluang_lolos');
        Schema::dropIfExists('prodi_statistik');
    }
};
