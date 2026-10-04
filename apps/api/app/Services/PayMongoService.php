<?php

namespace App\Services;

use App\Support\SimpleQr;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around the PayMongo REST API (QR Ph via Payment Intents).
 * The secret key is read from config/services.php -> .env and never leaves the server.
 *
 * Set QR_PAYMENTS_DRIVER=fake (local/testing only) to simulate QR payments without a key.
 */
class PayMongoService
{
    private function usingFake(): bool
    {
        if (config('services.paymongo.driver') !== 'fake') {
            return false;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The fake QR driver is only allowed when APP_ENV is local or testing.');
        }

        return true;
    }

    private function client(): PendingRequest
    {
        $secret = config('services.paymongo.secret');
        if (! $secret) {
            throw new RuntimeException('PAYMONGO_SECRET_KEY is not configured.');
        }

        // PayMongo uses HTTP Basic auth: secret key as username, blank password.
        return Http::withBasicAuth($secret, '')
            ->baseUrl(config('services.paymongo.base_url'))
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    /**
     * Create a dynamic QR Ph code for the given amount (in pesos).
     *
     * @return array{payment_id:string, qr_image:string, amount:int, test_url:?string}
     */
    public function createQrPayment(float $amountPesos, string $description, array $metadata = []): array
    {
        $amount = (int) round($amountPesos * 100); // centavos

        if ($this->usingFake()) {
            return $this->fakeCreate($amount);
        }

        // 1) Payment Intent
        $intent = $this->client()->post('/payment_intents', ['data' => ['attributes' => [
            'amount' => $amount,
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => $description,
            'metadata' => $metadata,
        ]]])->throw()->json('data');

        // 2) QR Ph payment method (billing name/email are required for qrph)
        $method = $this->client()->post('/payment_methods', ['data' => ['attributes' => [
            'type' => 'qrph',
            'billing' => [
                'name' => config('services.paymongo.billing_name'),
                'email' => config('services.paymongo.billing_email'),
            ],
        ]]])->throw()->json('data');

        // 3) Attach -> response contains next_action.code.image_url (base64 PNG data URI)
        $attached = $this->client()->post("/payment_intents/{$intent['id']}/attach", ['data' => ['attributes' => [
            'payment_method' => $method['id'],
            'client_key' => $intent['attributes']['client_key'] ?? null,
        ]]])->throw()->json('data');

        $code = data_get($attached, 'attributes.next_action.code', []);

        return [
            'payment_id' => $intent['id'],
            'qr_image' => $code['image_url'] ?? throw new RuntimeException('PayMongo did not return a QR image.'),
            'amount' => $amount,
            // Test mode only: docs mention a test_url for simulating payment. Log $code once to confirm its key.
            'test_url' => $code['test_url'] ?? null,
        ];
    }

    /** @return array{status:string, amount:int, last_error:?string} */
    public function retrieveIntent(string $paymentIntentId): array
    {
        if ($this->usingFake()) {
            return $this->fakeRetrieve($paymentIntentId);
        }

        $intent = $this->client()->get("/payment_intents/{$paymentIntentId}")->throw()->json('data');

        return [
            'status' => data_get($intent, 'attributes.status'),
            'amount' => (int) data_get($intent, 'attributes.amount'),
            'last_error' => data_get($intent, 'attributes.last_payment_error.failed_message'),
        ];
    }

    // ---------------------------------------------------------------- fake driver

    private function fakeCreate(int $amount): array
    {
        $delay = max(1, (int) config('services.paymongo.fake_delay', 8));
        $id = 'pi_fake'.Str::random(20); // alphanumeric only, matches ^pi_[A-Za-z0-9]+$

        Cache::put("fake-paymongo:{$id}", [
            'amount' => $amount,
            'paid_at' => now()->addSeconds($delay)->timestamp,
        ], now()->addHour());

        // Scannable QR pattern, but NOT a payable QR Ph code (its text is only a test marker).
        try {
            $qrImage = SimpleQr::svgDataUri("FRESHMART-FAKE|{$id}|{$amount}");
        } catch (Throwable $e) {
            report($e);
            $qrImage = $this->fakePlaceholder($delay);
        }

        return [
            'payment_id' => $id,
            'qr_image' => $qrImage,
            'amount' => $amount,
            'test_url' => null,
        ];
    }

    /** Plain placeholder used only if QR rendering fails. */
    private function fakePlaceholder(int $delay): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="260" viewBox="0 0 260 260">'
            .'<rect width="260" height="260" fill="#ffffff" stroke="#000000" stroke-width="4"/>'
            .'<text x="130" y="110" font-family="Arial" font-size="26" font-weight="bold" text-anchor="middle">FAKE QR</text>'
            .'<text x="130" y="145" font-family="Arial" font-size="16" text-anchor="middle">TEST ONLY - DO NOT PAY</text>'
            .'<text x="130" y="180" font-family="Arial" font-size="14" text-anchor="middle">Auto-pays in '.$delay.'s</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function fakeRetrieve(string $id): array
    {
        $row = Cache::get("fake-paymongo:{$id}");

        if (! $row) {
            return ['status' => 'awaiting_next_action', 'amount' => 0, 'last_error' => null];
        }

        return [
            'status' => now()->timestamp >= $row['paid_at'] ? 'succeeded' : 'awaiting_next_action',
            'amount' => $row['amount'],
            'last_error' => null,
        ];
    }
}
