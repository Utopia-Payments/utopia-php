<?php

declare(strict_types=1);

namespace Utopia\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use Utopia\Exception\AuthenticationException;
use Utopia\Exception\ConflictException;
use Utopia\Exception\InvalidRequestException;
use Utopia\Exception\NotFoundException;
use Utopia\Exception\WebhookVerificationException;
use Utopia\Utopia;
use Utopia\Webhook;

/**
 * The library against the real API server (sdks/sandbox/server.mjs). Skipped
 * unless UTOPIA_SANDBOX points at the sandbox.json the server writes.
 */
final class SandboxTest extends TestCase
{
    /** @var array{origin: string, base_url: string, api_key: string} */
    private static array $sandbox;
    private static Utopia $utopia;

    public static function setUpBeforeClass(): void
    {
        $file = (string) getenv('UTOPIA_SANDBOX');
        if ($file === '' || !is_file($file)) {
            self::markTestSkipped('Set UTOPIA_SANDBOX to run against the local sandbox API.');
        }
        self::$sandbox = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::$utopia = new Utopia(self::$sandbox['api_key'], ['base_url' => self::$sandbox['base_url'], 'max_retries' => 0]);
    }

    /**
     * Plays a part that normally happens outside the API, like a customer paying.
     *
     * @return array<string, mixed>
     */
    private static function sandbox(string $method, string $path): array
    {
        $curl = curl_init(self::$sandbox['origin'] . '/__sandbox' . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method !== 'GET') {
            // Like a browser, a POST names its origin, or the server refuses it.
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Origin: ' . self::$sandbox['origin']]);
            curl_setopt($curl, CURLOPT_POSTFIELDS, '{}');
        }
        $raw = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertSame(200, $status, "Sandbox {$path}: {$raw}");
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testDescribesTheAccount(): void
    {
        $account = self::$utopia->account->retrieve();

        self::assertSame('account', $account['object']);
        self::assertTrue($account['livemode']);
        self::assertTrue($account['payments_enabled']);
        self::assertContains('AED', $account['currencies']);
    }

    public function testTakesAOneTimePaymentThroughHostedCheckout(): void
    {
        $product = self::$utopia->products->create(['name' => 'PHP library test', 'price' => 12550, 'currency' => 'AED']);
        self::assertMatchesRegularExpression('/^pdt_[0-9a-f]{32}$/', $product['product_id']);

        $params = [
            'product_cart' => [['product_id' => $product['product_id'], 'quantity' => 2]],
            'customer' => ['email' => 'php-sdk@example.com', 'name' => 'PHP Library'],
            'return_url' => 'https://example.com/thank-you',
            'metadata' => ['order_id' => 'php-1001'],
        ];
        $key = 'php-sdk-' . bin2hex(random_bytes(8));
        $session = self::$utopia->checkoutSessions->create($params, ['idempotency_key' => $key]);
        $replayed = self::$utopia->checkoutSessions->create($params, ['idempotency_key' => $key]);
        self::assertSame($session['session_id'], $replayed['session_id'], 'The same idempotency key never creates a second checkout');
        self::assertStringContainsString('/checkout/cks_', $session['checkout_url']);

        $paid = self::sandbox('POST', '/checkout_sessions/' . $session['session_id'] . '/pay');
        $payment = self::$utopia->payments->retrieve($paid['payment_id']);

        self::assertSame('succeeded', $payment['status']);
        self::assertSame(25100, $payment['total_amount']);
        self::assertSame('AED', $payment['currency']);
        self::assertSame('php-1001', $payment['metadata']['order_id']);

        $found = false;
        foreach (self::$utopia->payments->all(['limit' => 1]) as $listed) {
            $found = $found || $listed['payment_id'] === $paid['payment_id'];
        }
        self::assertTrue($found, 'Pagination reaches the payment');
    }

    public function testRaisesTypedErrors(): void
    {
        try {
            self::$utopia->payments->retrieve('pay_' . str_repeat('0', 32));
            self::fail('Expected a NotFoundException');
        } catch (NotFoundException $error) {
            self::assertSame(404, $error->getHttpStatus());
        }

        try {
            self::$utopia->products->create(['name' => '', 'price' => -1, 'currency' => 'AED']);
            self::fail('Expected an InvalidRequestException');
        } catch (InvalidRequestException $error) {
            self::assertSame(400, $error->getHttpStatus());
        }

        $this->expectException(AuthenticationException::class);
        (new Utopia('sk_live_not_a_real_key', ['base_url' => self::$sandbox['base_url'], 'max_retries' => 0]))->account->retrieve();
    }

    public function testRunsASubscriptionFromCheckoutToCancellation(): void
    {
        $plan = self::$utopia->products->create([
            'name' => 'PHP library plan',
            'price' => 9900,
            'currency' => 'AED',
            'billing' => 'recurring',
            'billing_interval' => 'month',
        ]);
        $session = self::$utopia->checkoutSessions->create([
            'product_cart' => [['product_id' => $plan['product_id']]],
            'customer' => ['email' => 'php-member@example.com'],
        ]);
        self::assertSame('subscription', $session['kind']);

        self::sandbox('POST', '/checkout_sessions/' . $session['session_id'] . '/pay');
        $subscriptionId = self::$utopia->checkoutSessions->retrieve($session['session_id'])['subscription_id'];
        $subscription = self::$utopia->subscriptions->retrieve($subscriptionId);
        self::assertSame('active', $subscription['status']);
        self::assertSame('4242', $subscription['payment_method']['last4']);
        self::assertSame(9900, $subscription['recurring_amount']);

        self::sandbox('POST', '/subscriptions/' . $subscriptionId . '/renew');
        $renewals = array_filter(
            self::$utopia->payments->list(['limit' => 100])['items'],
            static fn (array $p): bool => ($p['subscription_id'] ?? null) === $subscriptionId && $p['billing_reason'] === 'subscription_cycle',
        );
        self::assertCount(1, $renewals, 'The renewal charged the saved card');

        self::assertTrue(self::$utopia->subscriptions->cancel($subscriptionId, atPeriodEnd: true)['cancel_at_period_end']);
        self::assertSame('cancelled', self::$utopia->subscriptions->cancel($subscriptionId)['status']);
        try {
            self::$utopia->subscriptions->cancel($subscriptionId);
            self::fail('Expected a ConflictException');
        } catch (ConflictException $error) {
            self::assertSame('SUBSCRIPTION_CANCELLED', $error->getErrorCode());
        }

        $cancelled = array_column(iterator_to_array(self::$utopia->subscriptions->all(['status' => 'cancelled']), false), 'subscription_id');
        self::assertContains($subscriptionId, $cancelled);
    }

    public function testVerifiesWebhooksSignedByTheServer(): void
    {
        $endpoint = self::$utopia->webhookEndpoints->create([
            'url' => 'https://example.com/webhooks/utopia',
            'event_types' => ['payment.succeeded'],
            'disabled' => true,
        ]);
        self::assertStringStartsWith('whsec_', $endpoint['secret']);
        self::assertSame($endpoint['secret'], self::$utopia->webhookEndpoints->secret($endpoint['webhook_id'])['secret']);

        $delivery = self::sandbox('GET', '/signed-event?secret=' . rawurlencode($endpoint['secret']));
        $event = Webhook::verify($delivery['body'], $delivery['headers'], $endpoint['secret']);
        self::assertSame('payment.succeeded', $event['type']);

        $ids = array_column(self::$utopia->webhookEndpoints->list()['items'], 'webhook_id');
        self::assertContains($endpoint['webhook_id'], $ids);
        self::$utopia->webhookEndpoints->delete($endpoint['webhook_id']);
        self::assertNotContains($endpoint['webhook_id'], array_column(self::$utopia->webhookEndpoints->list()['items'], 'webhook_id'));

        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($delivery['body'] . ' ', $delivery['headers'], $endpoint['secret']);
    }

    public function testManagesCustomersAndReadsEvents(): void
    {
        $email = 'php-customer-' . bin2hex(random_bytes(4)) . '@example.com';
        $customer = self::$utopia->customers->create(['email' => $email, 'name' => 'Before']);
        self::assertSame('After', self::$utopia->customers->update($customer['customer_id'], ['name' => 'After'])['name']);
        self::assertSame($email, self::$utopia->customers->retrieve($customer['customer_id'])['email']);
        self::assertSame([$customer['customer_id']], array_column(self::$utopia->customers->list(['email' => $email])['items'], 'customer_id'));

        $events = self::$utopia->events->list(['limit' => 5])['items'];
        self::assertNotEmpty($events);
        self::assertSame($events[0]['type'], self::$utopia->events->retrieve($events[0]['event_id'])['type']);
    }
}
