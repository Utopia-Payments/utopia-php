<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Subscriptions extends Resource
{
    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/subscriptions', $id));
    }

    /**
     * @param array{limit?: int, cursor?: string, status?: string, customer_id?: string} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/subscriptions', $params);
    }

    /**
     * @param array<string, scalar> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        return $this->client->paginate('/subscriptions', $params);
    }

    /**
     * @param array{cancel_at_period_end?: bool, status?: 'cancelled', metadata?: array<string, string>} $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', self::path('/subscriptions', $id), [], $params);
    }

    /**
     * Cancels now, or lets the paid period run out when $atPeriodEnd is true.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, bool $atPeriodEnd = false): array
    {
        return $this->update($id, $atPeriodEnd ? ['cancel_at_period_end' => true] : ['status' => 'cancelled']);
    }
}
