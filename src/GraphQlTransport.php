<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Executes GraphQL queries against {base_uri}/graphql using a bearer token from
 * the {@see TokenManager}. Retries once on a 401 with a freshly exchanged token.
 */
class GraphQlTransport
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenManager $tokenManager,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The decoded GraphQL `data` payload.
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->send($query, $variables, $this->tokenManager->getToken());

        // A rejected token: drop it and retry once with a fresh one.
        if ($response->status() === 401) {
            $this->tokenManager->forget();
            $response = $this->send($query, $variables, $this->tokenManager->getToken(forceRefresh: true));
        }

        if ($response->failed()) {
            throw new GdRequestException("GorillaDash GraphQL request failed with HTTP {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new GdRequestException('GorillaDash returned a non-JSON GraphQL response.');
        }

        if (! empty($body['errors'])) {
            throw GdRequestException::fromGraphqlErrors($body['errors']);
        }

        return $body['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function send(string $query, array $variables, string $token): Response
    {
        try {
            return $this->http
                ->timeout($this->connection->httpTimeout)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post($this->connection->baseUri.'/graphql', [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);
        } catch (Throwable $exception) {
            throw new GdRequestException('Failed to reach the GorillaDash GraphQL endpoint.', previous: $exception);
        }
    }
}
