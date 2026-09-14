<?php

declare(strict_types=1);

namespace Utopia\Resource;

final class Account extends Resource
{
    /**
     * The merchant this key belongs to, whether payments are enabled and in
     * which currencies. Handy as a connection test.
     *
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->client->request('GET', '/account');
    }
}
