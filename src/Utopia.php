<?php

declare(strict_types=1);

namespace Utopia;

use Utopia\Exception\ApiConnectionException;
use Utopia\Exception\UtopiaException;
use Utopia\Resource\Account;
use Utopia\Resource\CheckoutSessions;
use Utopia\Resource\Customers;
use Utopia\Resource\Events;
use Utopia\Resource\Payments;
use Utopia\Resource\Products;
use Utopia\Resource\Subscriptions;
use Utopia\Resource\WebhookEndpoints;

/**
 * Client for the Utopia Payments API.
 *
 *     $utopia = new \Utopia\Utopia(getenv('UTOPIA_API_KEY'));
 *     $session = $utopia->checkoutSessions->create([
 *         'product_cart' => [['product_id' => 'pdt_…']],
 *         'return_url' => 'https://your-store.com/thank-you',
 *     ]);
 *     header('Location: ' . $session['checkout_url']);
 *
 * Responses are associative arrays shaped exactly like the API's JSON.
 */
final class Utopia
{
    public const VERSION = '0.1.0';
    private const DEFAULT_BASE_URL = 'https://utopia-payments.com/api/v1';

    public readonly Account $account;
    public readonly CheckoutSessions $checkoutSessions;
    public readonly Customers $customers;
    public readonly Events $events;
    public readonly Payments $payments;
    public readonly Products $products;
    public readonly Subscriptions $subscriptions;
    public readonly WebhookEndpoints $webhookEndpoints;

    private readonly string $baseUrl;
    private readonly int $timeout;
    private readonly int $maxRetries;

    /**
     * @param string $apiKey Secret key: sk_live_… or sk_test_…
     * @param array{base_url?: string, timeout?: int, max_retries?: int} $options
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if (!preg_match('/^sk_(live|test)_/', $apiKey)) {
            throw new UtopiaException('Pass your secret API key (sk_live_… or sk_test_…).', 'MISSING_API_KEY');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $options['timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 2;

        $this->account = new Account($this);
        $this->checkoutSessions = new CheckoutSessions($this);
        $this->customers = new Customers($this);
        $this->events = new Events($this);
        $this->payments = new Payments($this);
        $this->products = new Products($this);
        $this->subscriptions = new Subscriptions($this);
        $this->webhookEndpoints = new WebhookEndpoints($this);
    }

    /** True for live keys, false for test keys. */
    public function isLivemode(): bool
    {
        return str_starts_with($this->apiKey, 'sk_live_');
    }

    /**
     * Sends a request and returns the decoded JSON body.
     *
     * Network errors, 429 and 5xx responses are retried with backoff. Every
     * POST carries an Idempotency-Key, so a retry can never create a duplicate.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn ($value): bool => $value !== null);
        if ($query !== []) {
            $url .= '?' . http_build_query(array_map(
                static fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                $query,
            ));
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: utopia-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . ($options['idempotency_key'] ?? self::uuid());
        }

        for ($attempt = 0; ; $attempt++) {
            $responseHeaders = [];
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            unset($curl);

            if ($raw === false) {
                if ($attempt < $this->maxRetries) {
                    self::pause($attempt);
                    continue;
                }
                throw new ApiConnectionException('Could not reach the Utopia API: ' . $error, 'CONNECTION_ERROR');
            }

            $data = json_decode((string) $raw, true);
            $data = is_array($data) ? $data : [];
            if ($status >= 200 && $status < 300) {
                return $data;
            }
            $retryable = $status === 429 || $status >= 500 || ($data['code'] ?? null) === 'IDEMPOTENCY_KEY_IN_USE';
            if ($retryable && $attempt < $this->maxRetries) {
                $retryAfter = (int) ($responseHeaders['retry-after'] ?? 0);
                $retryAfter > 0 ? usleep(min($retryAfter, 20) * 1_000_000) : self::pause($attempt);
                continue;
            }
            throw UtopiaException::fromResponse($status, $data);
        }
    }

    /**
     * Walks every page of a list endpoint, one item at a time.
     *
     * @param array<string, scalar|null> $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): \Generator
    {
        do {
            $page = $this->request('GET', $path, $query);
            foreach ($page['items'] ?? [] as $item) {
                yield $item;
            }
            $query['cursor'] = !empty($page['has_more']) ? ($page['next_cursor'] ?? null) : null;
        } while ($query['cursor'] !== null);
    }

    private static function pause(int $attempt): void
    {
        $milliseconds = min(8000, 500 * 2 ** $attempt) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
        usleep((int) ($milliseconds * 1000));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
