<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GeminiKeyStatus extends Command
{
    protected $signature   = 'gemini:key-status {--reset : Reset all exhausted keys}';
    protected $description = 'Show status of all configured Gemini API keys';

    public function handle(): void
    {
        $keys = array_values(array_filter((array) config('services.gemini.api_keys', [])));


        if (empty($keys)) {
            $this->error('No GEMINI_API_KEYS configured.');
            return;
        }

        if ($this->option('reset')) {
            foreach ($keys as $key) {
                Cache::forget('gemini_key_exhausted_' . md5($key));
            }
            $this->info('✅ All exhausted keys have been reset.');
        }

        $rows = [];
        foreach ($keys as $i => $key) {
            $cacheKey  = 'gemini_key_exhausted_' . md5($key);
            $exhausted = Cache::has($cacheKey);
            $rows[]    = [
                '#' . ($i + 1),
                '...' . substr($key, -10),
                $exhausted ? '❌ Exhausted (429)' : '✅ Active',
            ];
        }

        $this->table(['#', 'Key (suffix)', 'Status'], $rows);
    }
}
