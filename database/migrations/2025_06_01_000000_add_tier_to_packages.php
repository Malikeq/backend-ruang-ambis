<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('tier')->default('premium')->after('nama'); // 'premium' | 'daily_pass'
            $table->text('deskripsi')->nullable()->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['tier', 'deskripsi']);
        });
    }
};
