<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two columns:
 *   - kampus.logo_url       — URL to the campus logo (from logo.dev or manual)
 *   - users.referral_source — where the user heard about the platform
 */
return new class extends Migration
{
    public function up(): void
    {
        // kampus.logo_url — may already exist in some environments
        if (Schema::hasTable('kampus') && !Schema::hasColumn('kampus', 'logo_url')) {
            Schema::table('kampus', function (Blueprint $table) {
                $table->string('logo_url', 500)->nullable()->after('group');
            });
        }

        // users.referral_source
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'referral_source')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('referral_source', 100)->nullable()->after('onboarding_completed');
            });
        }
    }

    public function down(): void
    {
        Schema::table('kampus', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('referral_source');
        });
    }
};
