<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class WebhookEndpoints extends Resource
{
    /**
     * The response includes 'secret', used to verify deliveries.
     *
     * @param array{url: string, description?: string, event_types?: string[], disabled?: bool} $params
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/webhooks', [], $params, $options);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/webhooks', $id));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', self::path('/webhooks', $id), [], $params);
    }

    /** @return array<string, mixed> */
    public function delete(string $id): array
    {
        return $this->client->request('DELETE', self::path('/webhooks', $id));
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        return $this->client->request('GET', '/webhooks');
    }

    /** @return array{secret: string} */
    public function secret(string $id): array
    {
        return $this->client->request('GET', self::path('/webhooks', $id, '/secret'));
    }
}
