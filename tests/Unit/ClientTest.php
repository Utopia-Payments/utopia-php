<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Exception\ApiConnectionException;
use Utopia\Exception\ApiException;
use Utopia\Exception\AuthenticationException;
use Utopia\Exception\ConflictException;
use Utopia\Exception\InvalidRequestException;
use Utopia\Exception\NotFoundException;
use Utopia\Exception\PermissionDeniedException;
use Utopia\Exception\RateLimitException;
use Utopia\Exception\UtopiaException;
use Utopia\Utopia;

final class ClientTest extends TestCase
{
    private const KEY = 'sk_test_client_test_key';

    /** @var resource|null */
    private $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        // Only tests that started a mock API have a directory to clean up;
        // without this guard the glob below would walk the filesystem root.
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * Starts a throwaway API that answers with $responses in order.
     *
     * @param list<array{status?: int, json?: mixed, headers?: array<string, string>}> $responses
     */
    private function serve(array $responses, int $maxRetries = 2): Utopia
    {
        $this->dir = sys_get_temp_dir() . '/utopia-mock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/responses.json', json_encode($responses));

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr((string) strrchr($name, ':'), 1);

        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../Fixtures/mock-api.php'],
            [1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
            $pipes,
            null,
            ['UTOPIA_MOCK_DIR' => $this->dir],
        );
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                break;
            }
            usleep(50_000);
        }

        return new Utopia(self::KEY, [
            'base_url' => 'http://127.0.0.1:' . $this->port . '/api/v1',
            'max_retries' => $maxRetries,
        ]);
    }

    /** @return list<array{method: string, uri: string, headers: array<string, string>, body: string}> */
    private function requests(): array
    {
        $files = glob($this->dir . '/request-*.json') ?: [];
        sort($files);
        return array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true), $files);
    }

    public function testRejectsAKeyThatIsNotASecretKey(): void
    {
        $this->expectException(UtopiaException::class);
        new Utopia('pk_live_publishable');
    }

    public function testRejectsABaseUrlWithoutTls(): void
    {
        $this->expectException(UtopiaException::class);
        $this->expectExceptionMessage('https://');
        new Utopia(self::KEY, ['base_url' => 'http://api.example.com/api/v1']);
    }

    public function testAllowsPlainHttpForThisMachineOnly(): void
    {
        foreach (['http://localhost:4010/api/v1', 'http://127.0.0.1:4010/api/v1', 'http://[::1]:4010/api/v1'] as $baseUrl) {
            new Utopia(self::KEY, ['base_url' => $baseUrl]);
        }
        $this->addToAssertionCount(1);
    }

    public function testTellsLiveKeysFromTestKeys(): void
    {
        self::assertTrue((new Utopia('sk_live_abc'))->isLivemode());
        self::assertFalse((new Utopia('sk_test_abc'))->isLivemode());
    }

    /** @return iterable<string, array{int, class-string<UtopiaException>}> */
    public static function errorStatuses(): iterable
    {
        yield 'bad request' => [400, InvalidRequestException::class];
        yield 'unauthorized' => [401, AuthenticationException::class];
        yield 'forbidden' => [403, PermissionDeniedException::class];
        yield 'not found' => [404, NotFoundException::class];
        yield 'conflict' => [409, ConflictException::class];
        yield 'unprocessable' => [422, InvalidRequestException::class];
        yield 'rate limited' => [429, RateLimitException::class];
        yield 'server error' => [500, ApiException::class];
        yield 'unavailable' => [503, ApiException::class];
    }

    /** @param class-string<UtopiaException> $class */
    #[DataProvider('errorStatuses')]
    public function testMapsErrorResponsesToTypedExceptions(int $status, string $class): void
    {
        $error = UtopiaException::fromResponse($status, ['code' => 'SOME_CODE', 'message' => 'Something went wrong']);

        self::assertInstanceOf($class, $error);
        self::assertSame('SOME_CODE', $error->getErrorCode());
        self::assertSame($status, $error->getHttpStatus());
        self::assertSame('Something went wrong', $error->getMessage());
    }

    public function testRetriesRateLimitsAndServerErrorsWithTheSameIdempotencyKey(): void
    {
        $utopia = $this->serve([
            ['status' => 429, 'json' => ['code' => 'RATE_LIMITED', 'message' => 'Slow down'], 'headers' => ['Retry-After' => '1']],
            ['status' => 503, 'json' => ['code' => 'PAYMENTS_UNAVAILABLE', 'message' => 'Try again']],
            ['status' => 200, 'json' => ['session_id' => 'cks_1', 'checkout_url' => 'https://example.com/checkout/cks_1']],
        ]);

        $session = $utopia->checkoutSessions->create([
            'product_cart' => [['product_id' => 'pdt_1', 'quantity' => 2]],
            'metadata' => ['order_id' => '1001'],
        ]);

        self::assertSame('cks_1', $session['session_id']);
        $requests = $this->requests();
        self::assertCount(3, $requests);
        $keys = array_unique(array_map(static fn (array $r): string => $r['headers']['idempotency-key'] ?? '', $requests));
        self::assertCount(1, $keys, 'Every retry reuses one idempotency key');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) reset($keys));
        foreach ($requests as $request) {
            self::assertSame('POST', $request['method']);
            self::assertSame('/api/v1/checkout_sessions', $request['uri']);
            self::assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
            self::assertSame('application/json', $request['headers']['content-type']);
            self::assertStringStartsWith('utopia-php/', $request['headers']['user-agent']);
            self::assertSame('1001', json_decode($request['body'], true)['metadata']['order_id']);
        }
    }

    public function testGivesUpAfterTheLastRetry(): void
    {
        $utopia = $this->serve([['status' => 500, 'json' => ['code' => 'INTERNAL', 'message' => 'Broken']]], maxRetries: 1);

        try {
            $utopia->account->retrieve();
            self::fail('Expected an ApiException');
        } catch (ApiException $error) {
            self::assertSame('INTERNAL', $error->getErrorCode());
        }
        self::assertCount(2, $this->requests());
    }

    public function testDoesNotRetryAClientError(): void
    {
        $utopia = $this->serve([['status' => 404, 'json' => ['code' => 'NOT_FOUND', 'message' => 'No such payment']]]);

        try {
            $utopia->payments->retrieve('pay_missing');
            self::fail('Expected a NotFoundException');
        } catch (NotFoundException $error) {
            self::assertSame('No such payment', $error->getMessage());
        }
        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('/api/v1/payments/pay_missing', $requests[0]['uri']);
        self::assertArrayNotHasKey('idempotency-key', $requests[0]['headers']);
    }

    public function testWalksEveryPageWithTheCursor(): void
    {
        $utopia = $this->serve([
            ['json' => ['items' => [['payment_id' => 'pay_a'], ['payment_id' => 'pay_b']], 'has_more' => true, 'next_cursor' => 'cursor_2']],
            ['json' => ['items' => [['payment_id' => 'pay_c']], 'has_more' => false, 'next_cursor' => null]],
        ]);

        $ids = [];
        foreach ($utopia->payments->all(['limit' => 2, 'status' => 'succeeded']) as $payment) {
            $ids[] = $payment['payment_id'];
        }

        self::assertSame(['pay_a', 'pay_b', 'pay_c'], $ids);
        $requests = $this->requests();
        self::assertSame('/api/v1/payments?limit=2&status=succeeded', $requests[0]['uri']);
        self::assertSame('/api/v1/payments?limit=2&status=succeeded&cursor=cursor_2', $requests[1]['uri']);
    }

    public function testSendsBooleansAsWordsAndCancelsAtPeriodEnd(): void
    {
        $utopia = $this->serve([['json' => ['subscription_id' => 'sub_1', 'cancel_at_period_end' => true]]]);

        $utopia->subscriptions->cancel('sub_1', atPeriodEnd: true);

        $request = $this->requests()[0];
        self::assertSame('PATCH', $request['method']);
        self::assertSame('/api/v1/subscriptions/sub_1', $request['uri']);
        self::assertSame(['cancel_at_period_end' => true], json_decode($request['body'], true));
    }

    public function testReportsAnUnreachableApi(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $utopia = new Utopia(self::KEY, ['base_url' => 'http://' . $name . '/api/v1', 'max_retries' => 0]);

        $this->expectException(ApiConnectionException::class);
        $utopia->account->retrieve();
    }
}
