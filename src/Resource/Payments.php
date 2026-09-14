<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Payments extends Resource
{
    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/payments', $id));
    }

    /**
     * One page: ['items' => [...], 'has_more' => bool, 'next_cursor' => ?string].
     *
     * @param array{limit?: int, cursor?: string, status?: string, customer_id?: string, checkout_session_id?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/payments', $params);
    }

    /**
     * Every payment across all pages.
     *
     * @param array<string, scalar> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        return $this->client->paginate('/payments', $params);
    }
}
