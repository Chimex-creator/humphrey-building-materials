<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Paystack REST API.
 *
 * Everything in here runs on the SERVER ONLY — the secret key never leaves
 * this class (config('services.paystack.key')). The browser only ever sees
 * the public key and a redirect URL returned by Paystack.
 */
class PaystackService
{
    /** Base API url (from .env: PAYSTACK_PAYMENT_URL). */
    protected string $baseUrl;

    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.paystack.url', 'https://api.paystack.co'), '/');
        $this->secretKey = (string) config('services.paystack.key', '');
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    public function publicKey(): string
    {
        return (string) config('services.paystack.public_key', '');
    }

    /**
     * Naira → kobo. Paystack only understands the smallest currency unit:
     * ₦500,000.00 must be sent as 50000000.
     */
    public function toKobo($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Emails Paystack will accept.
     *
     * Our demo accounts use the reserved ".test" TLD (admin@demo.test),
     * which Paystack rejects with '"email" must be a valid email'. Reserved
     * domains (.test/.local/.invalid/.example) are swapped for example.com
     * — only for the Paystack call; our database keeps the real address.
     */
    public function safeEmail(string $email): string
    {
        $email = trim($email);

        if (! str_contains($email, '@')) {
            return 'customer@example.com';
        }

        [$local, $domain] = explode('@', $email, 2);

        if (preg_match('/\.(test|local|invalid|example)$/i', $domain)) {
            $domain = 'example.com';
        }

        $local = preg_replace('/[^A-Za-z0-9._%+\-]/', '', $local);

        if ($local === '' || $local === null) {
            $local = 'customer';
        }

        return $local.'@'.$domain;
    }

    /**
     * Start a transaction. Returns Paystack's response array on success.
     *
     * @throws RuntimeException with a safe, user-friendly message on failure.
     */
    public function initialize(array $payload): array
    {
        // callback_url = where Paystack sends the customer's browser AFTER
        // payment (our GET /paystack/callback). It is NOT proof of payment —
        // we always verify server-side.
        $response = Http::withToken($this->secretKey)
            ->timeout(20)
            ->post($this->baseUrl.'/transaction/initialize', $payload);

        $body = $response->json();

        if (! $response->ok() || ! isset($body['status']) || $body['status'] !== true || empty($body['data']['authorization_url'])) {
            throw new RuntimeException($this->friendlyError($body, 'Could not start the payment. Please try again.'));
        }

        return $body['data'];
    }

    /**
     * Ask Paystack directly whether a reference was actually paid.
     * This is the ONLY trustworthy source of truth (never trust the browser).
     *
     * @throws RuntimeException when Paystack cannot confirm the transaction.
     */
    public function verify(string $reference): array
    {
        $response = Http::withToken($this->secretKey)
            ->timeout(20)
            ->get($this->baseUrl.'/transaction/verify/'.urlencode($reference));

        $body = $response->json();

        if (! $response->ok() || ! isset($body['status']) || $body['status'] !== true || ! isset($body['data'])) {
            throw new RuntimeException($this->friendlyError($body, 'We could not verify this payment. Please contact support.'));
        }

        return $body['data'];
    }

    /**
     * Check a Paystack webhook signature (X-Paystack-Signature header).
     * Only Paystack knows the secret, so a matching HMAC proves the request
     * really came from Paystack.
     */
    public function validSignature(?string $signature, string $rawPayload): bool
    {
        if ($signature === null || $signature === '' || $this->secretKey === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, $this->secretKey);

        return hash_equals($expected, $signature);
    }

    /** Turn a Paystack error body into a safe message (never leaks keys). */
    protected function friendlyError(?array $body, string $fallback): string
    {
        $message = $body['message'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return $fallback;
        }

        // Strip anything that looks like a key just in case.
        $message = preg_replace('/(pk|sk)_[A-Za-z0-9]+/', '***', $message);

        return $message;
    }
}
