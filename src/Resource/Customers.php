<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Customers extends Resource
{
    /**
     * @param array{email: string, name: string, phone_number?: string, metadata?: array<string, string>} $params
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/customers', [], $params, $options);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/customers', $id));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', self::path('/customers', $id), [], $params);
    }

    /**
     * @param array{limit?: int, cursor?: string, email?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/customers', $params);
    }

    /**
     * @param array<string, scalar> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        return $this->client->paginate('/customers', $params);
    }
}
