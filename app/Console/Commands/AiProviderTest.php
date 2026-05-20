<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AiService;

class AiProviderTest extends Command
{
    protected $signature   = 'ai:test {--prompt= : Custom test prompt}';
    protected $description = 'Test the currently active AI provider (Gemini or Ollama)';

    public function handle(AiService $ai): void
    {
        $provider = $ai->getProviderName();
        $this->info("🤖 Testing AI provider: <comment>{$provider}</comment>");

        $prompt = $this->option('prompt')
            ?? 'Respond with exactly this JSON and nothing else: {"status":"ok","provider":"test"}';

        $this->line("Prompt: {$prompt}");
        $this->line('Calling API...');

        $start = microtime(true);

        try {
            $result = $ai->generateFlash($prompt, null, 'test');
            $elapsed = round((microtime(true) - $start) * 1000);

            $this->newLine();
            $this->info("✅ Success in {$elapsed}ms");
            $this->line("Response: " . $result['text']);
            $this->line("Tokens in: {$result['token_in']} | out: {$result['token_out']}");

        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->newLine();
            $this->error("❌ Failed after {$elapsed}ms");
            $this->error($e->getMessage());

            if ($provider === 'ollama') {
                $this->newLine();
                $this->warn('Ollama troubleshooting:');
                $this->line('  1. Is Ollama running?       → ollama serve');
                $this->line('  2. Is the model pulled?     → ollama pull ' . config('services.ollama.model'));
                $this->line('  3. Check OLLAMA_URL in .env → ' . config('services.ollama.url'));
            }
        }
    }
}
