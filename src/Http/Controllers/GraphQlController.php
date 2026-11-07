<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Http\Controllers;

use GorillaDash\WebsiteSdk\Facades\GorillaDash;
use GorillaDash\WebsiteSdk\Support\GraphQlOperations;
use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional GraphQL endpoint. Proxies a POST { query, variables } body through
 * the stale-while-revalidate cache and returns the full envelope (data + cache
 * metadata: status, age, cached_at).
 *
 * It answers anyone who can reach the site, so it runs queries only, with the
 * read-only browser token: a document that defines a mutation or a
 * subscription is refused before anything is sent, and Gorilla Dash limits
 * the rest to what the public can see. Its answers are cached apart from the
 * site's server reads. Mutations go through {@see WebsiteClient::mutate()} from the
 * site's own server code.
 */
class GraphQlController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->input('query');
        $types = is_string($query) ? GraphQlOperations::types($query) : null;

        if ($types === null || $types === []) {
            return $this->refuse('The request body must carry a GraphQL query.', 400);
        }

        if (array_diff($types, ['query']) !== []) {
            return $this->refuse('This endpoint only runs GraphQL queries.', 403);
        }

        $envelope = GorillaDash::browserGraphqlWithMeta(
            $query,
            (array) $request->input('variables', []),
        );

        return response()->json($envelope);
    }

    /** A refusal in the GraphQL error shape, so a GraphQL client shows its message. */
    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['errors' => [['message' => $message]]], $status);
    }
}
