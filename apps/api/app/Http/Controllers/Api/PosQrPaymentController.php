<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayMongoService;
use App\Services\PosPricingService;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PosQrPaymentController extends Controller
{
    private const QR_TTL_MINUTES = 30; // PayMongo dynamic QR Ph expiry

    public function __construct(
        private PayMongoService $paymongo,
        private PosPricingService $pricing,
        private SystemSettingsService $settings,
    ) {}

    /** POST /workspace/pos/qr-payments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // IMPORTANT: reuse the SAME pricing/tax logic your checkout uses. Never trust a client-sent amount.
        $total = $this->pricing->total($data['items'], $this->settings);

        $qr = $this->paymongo->createQrPayment($total, 'FreshMart POS sale', [
            'cashier_id' => (string) $request->user()->id,
        ]);

        Cache::put($this->cacheKey($qr['payment_id']), [
            'expires_at' => now()->addMinutes(self::QR_TTL_MINUTES)->toIso8601String(),
        ], now()->addMinutes(self::QR_TTL_MINUTES + 5));

        return response()->json([
            'payment_id' => $qr['payment_id'],
            'qr_image' => $qr['qr_image'],
            'amount' => $total,
            'expires_at' => now()->addMinutes(self::QR_TTL_MINUTES)->toIso8601String(),
            // only expose in local/testing so cashiers never see it in production
            'test_url' => app()->environment(['local', 'testing']) ? $qr['test_url'] : null,
        ]);
    }

    /** GET /workspace/pos/qr-payments/{id} */
    public function show(string $id): JsonResponse
    {
        $intent = $this->paymongo->retrieveIntent($id);

        if ($intent['status'] === 'succeeded') {
            return response()->json(['status' => 'paid']);
        }

        $meta = Cache::get($this->cacheKey($id));
        if (! $meta || now()->greaterThan($meta['expires_at'])) {
            return response()->json(['status' => 'expired']);
        }

        return response()->json(['status' => $intent['last_error'] ? 'failed' : 'waiting']);
    }

    /**
     * Call this from your existing checkout action when payment_method === 'QR'.
     * Throws a validation error unless PayMongo confirms the exact amount was paid.
     */
    public static function assertQrPaid(PayMongoService $paymongo, string $paymentId, float $expectedTotal): void
    {
        $intent = $paymongo->retrieveIntent($paymentId);

        abort_unless($intent['status'] === 'succeeded', 422, 'QR payment has not been completed.');
        abort_unless($intent['amount'] === (int) round($expectedTotal * 100), 422, 'QR payment amount does not match the sale total.');
        // Also make sure the payment_reference column has a UNIQUE index so one QR can't pay two sales.
    }

    private function cacheKey(string $id): string
    {
        return "paymongo:qr:{$id}";
    }
}
