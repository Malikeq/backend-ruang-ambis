<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'promo_code' => ['nullable', 'string'],
        ]);

        $package     = Package::findOrFail($data['package_id']);
        $grossAmount = $package->harga_idr;
        $promoUsed   = null;

        // Apply promo if provided
        if (!empty($data['promo_code'])) {
            $promo = PromoCode::where('kode', $data['promo_code'])
                ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
                ->where('used_count', '<', \DB::raw('max_uses'))
                ->first();

            if ($promo) {
                $grossAmount = intval($grossAmount * (1 - $promo->diskon_persen / 100));
                $promoUsed   = $promo;
            }
        }

        $orderId = 'AILOLOS-' . strtoupper(Str::random(10)) . '-' . time();

        $transaction = Transaction::create([
            'user_id'          => $request->user()->id,
            'package_id'       => $package->id,
            'gross_amount'     => $grossAmount,
            'midtrans_order_id'=> $orderId,
            'status'           => 'pending',
        ]);

        // Create Midtrans Snap token
        try {
            $snapToken = $this->createMidtransToken($orderId, $grossAmount, $request->user(), $package);
        } catch (\RuntimeException $e) {
            $transaction->delete(); // rollback pending transaction
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }

        if ($promoUsed) {
            $promoUsed->increment('used_count');
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'transaction_id' => $transaction->id,
                'order_id'       => $orderId,
                'gross_amount'   => $grossAmount,
                'snap_token'     => $snapToken,
                'client_key'     => config('services.midtrans.client_key'),
                'merchant_id'    => config('services.midtrans.merchant_id'),
                'is_production'  => (bool) config('services.midtrans.is_production'),
                'snap_url'       => config('services.midtrans.is_production')
                    ? "https://app.midtrans.com/snap/v2/vtweb/{$snapToken}"
                    : "https://app.sandbox.midtrans.com/snap/v2/vtweb/{$snapToken}",
            ],
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $data = $request->all();

        // Verify Midtrans signature
        $expected = hash('sha512',
            ($data['order_id'] ?? '') .
            ($data['status_code'] ?? '') .
            ($data['gross_amount'] ?? '') .
            config('services.midtrans.server_key')
        );

        if ($expected !== ($data['signature_key'] ?? '')) {
            \Log::warning('Midtrans webhook invalid signature', ['order' => $data['order_id'] ?? '']);
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 403);
        }

        $transaction = Transaction::where('midtrans_order_id', $data['order_id'])->first();
        if (!$transaction) return response()->json(['success' => false], 404);

        $mtStatus = $data['transaction_status'] ?? '';

        if (in_array($mtStatus, ['settlement', 'capture'])) {
            $this->activateSubscription($transaction);
        } elseif (in_array($mtStatus, ['cancel', 'expire', 'deny'])) {
            $transaction->update(['status' => 'failed']);
        }

        return response()->json(['success' => true]);
    }

    public function status(Request $request, string $orderId): JsonResponse
    {
        $transaction = Transaction::where('midtrans_order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->with('package')
            ->firstOrFail();

        // If already paid, return immediately
        if ($transaction->status === 'paid') {
            return response()->json([
                'success' => true,
                'data'    => ['status' => 'paid', 'package' => $transaction->package],
            ]);
        }

        // Ask Midtrans directly for the real status
        try {
            $isProd   = (bool) config('services.midtrans.is_production');
            $baseUrl  = $isProd
                ? 'https://api.midtrans.com/v2'
                : 'https://api.sandbox.midtrans.com/v2';

            $mtResp = \Http::withBasicAuth(config('services.midtrans.server_key'), '')
                ->get("{$baseUrl}/{$orderId}/status");

            if ($mtResp->successful()) {
                $mtStatus = $mtResp->json('transaction_status');

                if (in_array($mtStatus, ['settlement', 'capture'])) {
                    // Activate subscription — idempotent via firstOrCreate
                    $this->activateSubscription($transaction);
                    return response()->json([
                        'success' => true,
                        'data'    => ['status' => 'paid', 'package' => $transaction->package],
                    ]);
                }

                if (in_array($mtStatus, ['cancel', 'expire', 'deny'])) {
                    $transaction->update(['status' => 'failed']);
                    return response()->json([
                        'success' => true,
                        'data'    => ['status' => 'failed'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Midtrans status check failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data'    => ['status' => $transaction->status, 'package' => $transaction->package],
        ]);
    }

    /**
     * Activate subscription for a transaction — idempotent.
     */
    private function activateSubscription(Transaction $transaction): void
    {
        // Avoid duplicate subscriptions
        $already = Subscription::where('payment_id', $transaction->midtrans_order_id)->exists();
        if ($already) return;

        $package = Package::find($transaction->package_id);
        if (!$package) return;

        $selesai = now()->addDays($package->durasi_hari);
        $tier    = $package->tier ?? ($package->durasi_hari === 1 ? 'daily_pass' : 'premium');

        Subscription::create([
            'user_id'    => $transaction->user_id,
            'package_id' => $transaction->package_id,
            'mulai'      => now(),
            'selesai'    => $selesai,
            'payment_id' => $transaction->midtrans_order_id,
            'status'     => 'active',
        ]);

        $transaction->update(['status' => 'paid', 'payment_method' => 'midtrans']);
        $transaction->user->update(['tier' => $tier]);
    }


    public function applyPromo(Request $request): JsonResponse
    {
        $data  = $request->validate(['kode' => ['required', 'string']]);
        $promo = PromoCode::where('kode', $data['kode'])
            ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
            ->first();

        if (!$promo || $promo->used_count >= $promo->max_uses) {
            return response()->json(['success' => false, 'message' => 'Kode promo tidak valid.'], 404);
        }

        return response()->json(['success' => true, 'data' => ['diskon_persen' => $promo->diskon_persen]]);
    }

    private function createMidtransToken(string $orderId, int $amount, $user, Package $package): string
    {
        $isProd  = (bool) config('services.midtrans.is_production');
        $payload = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email'      => $user->email,
            ],
            'item_details' => [[
                'id'       => (string) $package->id,
                'price'    => $amount,
                'quantity' => 1,
                'name'     => $package->nama,
            ]],
            'callbacks' => [
                'finish' => config('services.midtrans.finish_url',
                    config('app.url') . '/api/v1/payment/done'),
            ],
        ];

        $url = $isProd
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $response = \Http::withBasicAuth(config('services.midtrans.server_key'), '')
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->post($url, $payload);

        if (!$response->successful()) {
            \Log::error('Midtrans Snap error', ['body' => $response->body(), 'status' => $response->status()]);
            throw new \RuntimeException('Midtrans: ' . ($response->json('error_messages.0') ?? 'Unknown error'));
        }

        return $response->json('token') ?? '';
    }

    /**
     * Mobile-friendly payment finish page.
     * Midtrans redirects here after payment. Returns a styled HTML page.
     */
    public function done(Request $request)
    {
        $status  = $request->query('transaction_status', 'unknown');
        $orderId = $request->query('order_id', '');

        $isSuccess = in_array($status, ['settlement', 'capture']);
        $isPending = $status === 'pending';

        $emoji  = $isSuccess ? '🎉' : ($isPending ? '⏳' : '❌');
        $title  = $isSuccess ? 'Pembayaran Berhasil!'
                             : ($isPending ? 'Menunggu Pembayaran' : 'Pembayaran Gagal');
        $msg    = $isSuccess
            ? 'Transaksi dikonfirmasi. Akun kamu sudah diupgrade!'
            : ($isPending ? 'Pembayaranmu sedang diproses.' : 'Terjadi masalah dengan pembayaran.');
        $color  = $isSuccess ? '#10b981' : ($isPending ? '#f59e0b' : '#ef4444');

        // Pre-compute to avoid ternary inside heredoc
        $orderHtml   = $orderId ? "<p class=\"order\">Order: {$orderId}</p>" : '';
        $borderColor = $color . '66';
        $glowColor   = $color . '22';
        $btnBg       = $color;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Status Pembayaran</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #0B0B1A; color: #f1f5f9;
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      padding: 24px;
    }
    .card {
      background: #141428; border-radius: 24px;
      border: 1px solid $borderColor;
      padding: 40px 32px; max-width: 380px; width: 100%;
      text-align: center; box-shadow: 0 0 60px $glowColor;
    }
    .emoji { font-size: 64px; margin-bottom: 20px; display: block; }
    h1 { font-size: 22px; font-weight: 800; margin-bottom: 12px; color: $color; }
    p  { font-size: 15px; color: #94a3b8; line-height: 1.6; margin-bottom: 16px; }
    .order { font-size: 11px; color: #475569; font-family: monospace; }
    .btn {
      display: block; background: $btnBg; color: #fff;
      padding: 14px 32px; border-radius: 14px; font-weight: 700;
      font-size: 15px; cursor: pointer; border: none; width: 100%;
      margin-top: 24px;
    }
    .close-note { font-size: 12px; color: #334155; margin-top: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <span class="emoji">$emoji</span>
    <h1>$title</h1>
    <p>$msg</p>
    $orderHtml
    <button class="btn" onclick="window.close()">Tutup &amp; Kembali ke App</button>
    <p class="close-note">Silakan tutup halaman ini untuk kembali ke aplikasi.</p>
  </div>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html']);
    }
}
