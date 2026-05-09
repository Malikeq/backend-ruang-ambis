<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Midtrans snap token (sandbox)
        $snapToken = $this->createMidtransToken($orderId, $grossAmount, $request->user(), $package);

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
                'is_production'  => config('services.midtrans.is_production'),
            ],
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $data = $request->all();

        // Verify signature
        $signatureKey = hash('sha512',
            $data['order_id'] . $data['status_code'] . $data['gross_amount'] . config('services.midtrans.server_key')
        );

        if ($signatureKey !== $data['signature_key']) {
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 403);
        }

        $transaction = Transaction::where('midtrans_order_id', $data['order_id'])->first();
        if (!$transaction) return response()->json(['success' => false], 404);

        $status = match($data['transaction_status']) {
            'settlement', 'capture' => 'paid',
            'cancel', 'expire', 'deny' => 'failed',
            default => 'pending',
        };

        $transaction->update(['status' => $status, 'payment_method' => $data['payment_type'] ?? null]);

        if ($status === 'paid') {
            $package = Package::find($transaction->package_id);
            $selesai = now()->addDays($package->durasi_hari);

            Subscription::create([
                'user_id'    => $transaction->user_id,
                'package_id' => $transaction->package_id,
                'mulai'      => now(),
                'selesai'    => $selesai,
                'payment_id' => $transaction->midtrans_order_id,
                'status'     => 'active',
            ]);

            $tier = $package->durasi_hari === 1 ? 'daily_pass' : 'premium';
            $transaction->user->update(['tier' => $tier]);
        }

        return response()->json(['success' => true]);
    }

    public function status(Request $request, string $orderId): JsonResponse
    {
        $transaction = Transaction::where('midtrans_order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $transaction->load('package')]);
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
        // Midtrans sandbox snap API
        $payload = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
            'customer_details'    => ['first_name' => $user->name, 'email' => $user->email],
            'item_details'        => [['id' => $package->id, 'price' => $amount, 'quantity' => 1, 'name' => $package->nama]],
        ];

        $url      = config('services.midtrans.is_production')
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $response = \Http::withBasicAuth(config('services.midtrans.server_key'), '')
            ->post($url, $payload);

        return $response->json('token') ?? '';
    }
}
