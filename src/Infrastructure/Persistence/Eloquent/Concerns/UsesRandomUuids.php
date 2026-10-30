<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * UUID v4 keys. Laravel's HasUuids makes UUID v7, whose first 48 bits are the creation time
 * in milliseconds: on an anonymous response that would give away when it was sent.
 */
trait UsesRandomUuids
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
