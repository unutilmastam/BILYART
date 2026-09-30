<?php

namespace App\Support\Http;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §1 pagination: ?page&perPage (max 100) → { data, meta: {page, perPage, total} }. */
trait Paginates
{
    /**
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginate(Builder $query, Request $request, string $resource): array
    {
        $perPage = max(1, min(100, (int) $request->query('perPage', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $resource::collection($paginator->items())->resolve($request),
            'meta' => ['page' => $paginator->currentPage(), 'perPage' => $paginator->perPage(), 'total' => $paginator->total()],
        ];
    }
}
