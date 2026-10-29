<?php

namespace App\Models\Concerns;

use LogicException;

/** Rows can be inserted but never changed or removed through the application. */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }
}
