<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class CheckoutSessions extends Resource
{
    /**
     * Creates a hosted checkout. Redirect the customer to its checkout_url.
     * One recurring product starts a subscription; one-time items take a payment.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string} $options
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/checkout_sessions', [], $params, $options);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', self::path('/checkout_sessions', $id));
    }
}
