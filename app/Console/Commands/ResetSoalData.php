<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetSoalData extends Command
{
    protected $signature   = 'soal:reset {--yes : Skip confirmation prompt}';
    protected $description = 'Wipe ALL soal, drafts, uploads, sessions & AI data for a clean test run.';

    public function handle(): int
    {
        if (!$this->option('yes')) {
            if (!$this->confirm('⚠️  This will DELETE all soal, drafts, uploads, sessions and AI explanations. Continue?')) {
                $this->line('Cancelled.');
                return 0;
            }
        }

        $this->info('Disabling foreign key checks…');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'user_attempts',
            'sesi_latihan',
            'ai_call_logs',
            'ai_explanations',
            'pembahasan',
            'pilihan_jawaban',
            'soal',
            'ai_draft_soal',
            'material_uploads',
            'weakness_reports',
            'sub_materi',   // will be re-created by AI generation
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
            $this->line("  ✓ Truncated <comment>{$table}</comment>");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('✅  All soal data has been wiped. Database is clean and ready for testing.');
        $this->line('   Next step: upload a PDF via the admin panel → approve drafts → test user flow.');

        return 0;
    }
}
