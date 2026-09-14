<?php

declare(strict_types=1);

namespace Utopia\Resource;

use Utopia\Utopia;

/** @internal */
abstract class Resource
{
    public function __construct(protected readonly Utopia $client)
    {
    }

    protected static function path(string $base, string $id, string $suffix = ''): string
    {
        return $base . '/' . rawurlencode($id) . $suffix;
    }
}
