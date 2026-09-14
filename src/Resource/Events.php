<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Events extends Resource
{
    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/events', $id));
    }

    /**
     * @param array{limit?: int, cursor?: string, type?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/events', $params);
    }

    /**
     * @param array<string, scalar> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        return $this->client->paginate('/events', $params);
    }
}
