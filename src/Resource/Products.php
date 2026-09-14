<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Products extends Resource
{
    /**
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/products', [], $params, $options);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/products', $id));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', self::path('/products', $id), [], $params);
    }

    /**
     * @param array{limit?: int, cursor?: string, status?: string, billing?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/products', $params);
    }

    /**
     * @param array<string, scalar> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        return $this->client->paginate('/products', $params);
    }
}
