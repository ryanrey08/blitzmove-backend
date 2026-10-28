<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Generates human-readable sequential reference numbers such as ORD-2026-0001.
 * Callers run inside a transaction; the unique index on the column is the
 * final guard against collisions.
 */
class NumberGenerator
{
    /** @param class-string<Model> $model */
    public function next(string $model, string $column, string $prefix, bool $yearly = true): string
    {
        $base = $yearly ? sprintf('%s-%s-', $prefix, now()->format('Y')) : $prefix.'-';

        $query = $model::query()->where($column, 'like', $base.'%')->lockForUpdate();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $last = $query->orderByDesc($column)->value($column);
        $sequence = $last ? ((int) substr($last, strlen($base))) + 1 : 1;

        return $base.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
