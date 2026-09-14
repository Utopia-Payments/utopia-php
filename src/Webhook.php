<?php

declare(strict_types=1);

namespace Utopia;

use Utopia\Exception\WebhookVerificationException;

/**
 * Verifies webhook deliveries (Standard Webhooks). Needs no API key.
 */
final class Webhook
{
    /**
     * Returns the decoded event, or throws if the delivery isn't authentic.
     *
     * Pass the raw body exactly as received (file_get_contents('php://input')),
     * the request headers (getallheaders(), $_SERVER or a framework's header
     * bag as an array) and your endpoint's whsec_… secret.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, mixed>
     */
    public static function verify(string $payload, array $headers, string $secret, int $tolerance = 300): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        // Accept both "webhook-id" and $_SERVER's "HTTP_WEBHOOK_ID".
        $read = static fn (string $name): ?string => $normalized[$name]
            ?? $normalized['http_' . str_replace('-', '_', $name)]
            ?? null;

        $id = $read('webhook-id');
        $timestamp = $read('webhook-timestamp');
        $signatures = $read('webhook-signature');
        if (!$id || !$timestamp || !$signatures) {
            throw self::failure('Missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            throw self::failure('The webhook timestamp is too old or too far in the future');
        }
        $key = base64_decode((string) preg_replace('/^whsec_/', '', $secret), true);
        if ($key === false || $key === '') {
            throw self::failure('The webhook secret is not valid');
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true));
        foreach (explode(' ', $signatures) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw self::failure('The webhook signature does not match');
    }

    private static function failure(string $message): WebhookVerificationException
    {
        return new WebhookVerificationException($message, 'WEBHOOK_VERIFICATION_FAILED');
    }
}
