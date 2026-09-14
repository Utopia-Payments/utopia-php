<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Exception\WebhookVerificationException;
use Utopia\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_c2VjcmV0LWtleS1mb3ItdXRvcGlhLXRlc3RzLTEyMzQ=';
    private const BODY = '{"id":"evt_1","type":"payment.succeeded","data":{"payment_id":"pay_1"}}';

    private static function sign(string $id, int $timestamp, string $body, string $secret = self::SECRET): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')), true);
        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) $key, true));
    }

    /** @return array<string, string> */
    private static function headers(?int $timestamp = null, string $body = self::BODY): array
    {
        $timestamp ??= time();
        return [
            'webhook-id' => 'evt_1',
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::sign('evt_1', $timestamp, $body),
        ];
    }

    public function testReturnsTheEventForAnAuthenticDelivery(): void
    {
        $event = Webhook::verify(self::BODY, self::headers(), self::SECRET);

        self::assertSame('payment.succeeded', $event['type']);
        self::assertSame('pay_1', $event['data']['payment_id']);
    }

    public function testReadsServerVariablesAndFrameworkHeaderBags(): void
    {
        $headers = self::headers();
        $server = [];
        $bag = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            $bag[ucwords($name, '-')] = [$value];
        }

        self::assertSame('evt_1', Webhook::verify(self::BODY, $server, self::SECRET)['id']);
        self::assertSame('evt_1', Webhook::verify(self::BODY, $bag, self::SECRET)['id']);
    }

    public function testAcceptsAnyMatchingSignatureDuringSecretRotation(): void
    {
        $headers = self::headers();
        $headers['webhook-signature'] = 'v1,' . base64_encode('an-older-secret') . ' ' . $headers['webhook-signature'];

        self::assertSame('evt_1', Webhook::verify(self::BODY, $headers, self::SECRET)['id']);
    }

    public function testRejectsATamperedBody(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(str_replace('pay_1', 'pay_2', self::BODY), self::headers(), self::SECRET);
    }

    public function testRejectsAnotherEndpointsSecret(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::BODY, self::headers(), 'whsec_' . base64_encode('another-endpoint-secret'));
    }

    public function testRejectsAReplayedOldDelivery(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::BODY, self::headers(time() - 301), self::SECRET);
    }

    public function testRejectsMissingSignatureHeaders(): void
    {
        $headers = self::headers();
        unset($headers['webhook-signature']);

        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::BODY, $headers, self::SECRET);
    }

    public function testRejectsASecretThatIsNotBase64(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::BODY, self::headers(), 'whsec_!!!');
    }
}
