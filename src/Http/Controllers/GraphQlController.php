<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Http\Controllers;

use GorillaDash\WebsiteSdk\Facades\GorillaDash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Default GraphQL endpoint. Proxies a POST { query, variables } body through the
 * stale-while-revalidate cache and returns the full envelope (data + cache
 * metadata: status, age, cached_at).
 */
class GraphQlController
{
    public function __invoke(Request $request): JsonResponse
    {
        $envelope = GorillaDash::graphqlWithMeta(
            (string) $request->input('query'),
            (array) $request->input('variables', []),
        );

        return response()->json($envelope);
    }
}
