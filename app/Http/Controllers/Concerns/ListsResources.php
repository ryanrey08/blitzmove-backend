<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ListsResources
{
    /** Case-insensitive "contains" search across the given columns (dot notation for relations). */
    protected function applySearch(Builder $query, Request $request, array $columns): Builder
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($columns, $term) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);
                    $q->orWhereHas($relation, fn (Builder $r) => $r->where($field, 'like', "%{$term}%"));
                } else {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            }
        });
    }

    /** Equality filters for whitelisted query parameters. */
    protected function applyFilters(Builder $query, Request $request, array $filters): Builder
    {
        foreach ($filters as $param => $column) {
            $value = $request->query(is_int($param) ? $column : $param);
            if ($value !== null && $value !== '') {
                $query->whereIn($column, explode(',', (string) $value));
            }
        }

        return $query;
    }

    /** ?sort=column or ?sort=-column, limited to the whitelist. */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default = '-created_at'): Builder
    {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    protected function perPage(Request $request, int $default = 10): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }
}
