<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    private const API_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * @param  Collection<int, string>|array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     */
    public function send(array|Collection $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = collect($tokens)->filter()->unique()->values();
        if ($tokens->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($tokens->chunk(100) as $chunk) {
            $messages = $chunk->map(fn (string $token) => [
                'to'        => $token,
                'title'     => $title,
                'body'      => $body,
                'data'      => $data,
                'sound'     => 'default',
                'priority'  => 'high',
                'channelId' => 'pengingat',
            ])->values()->all();

            try {
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->post(self::API_URL, $messages);

                if (!$response->successful()) {
                    Log::warning('Expo push failed', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    continue;
                }

                $sent += count($messages);
            } catch (\Throwable $e) {
                Log::error('Expo push exception', ['message' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
