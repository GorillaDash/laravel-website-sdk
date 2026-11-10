<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk;

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Executes GraphQL queries against {base_uri}/graphql using a bearer token from
 * the {@see TokenManager}: the full website token, or with $browserToken the
 * read-only browser token. Retries once on a 401 with a freshly exchanged token,
 * and, with the website token, once on a 404, which is how GorillaDash refuses
 * a revoked or expired one (see {@see TokenManager::replaceAfterNotFound()}).
 * A refused token stops the request before anything runs, so the retry cannot
 * file a mutation twice.
 */
class GraphQlTransport
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenManager $tokenManager,
        private readonly HttpFactory $http,
        private readonly bool $browserToken = false,
    ) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The decoded GraphQL `data` payload.
     */
    public function query(string $query, array $variables = []): array
    {
        $token = $this->token();
        $response = $this->send($query, $variables, $token);

        // A rejected token: drop it and retry once with a fresh one.
        if ($response->status() === 401) {
            $response = $this->send($query, $variables, $this->token(refused: true));
        } elseif ($response->status() === 404 && ! $this->browserToken) {
            $replacement = $this->tokenManager->replaceAfterNotFound($token);

            if ($replacement !== null) {
                $response = $this->send($query, $variables, $replacement);

                if ($response->status() === 404) {
                    $this->tokenManager->holdMintAfterNotFound();
                }
            }
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

    private function token(bool $refused = false): string
    {
        if ($this->browserToken) {
            return $this->tokenManager->getBrowserToken(forceRefresh: $refused);
        }

        if ($refused) {
            $this->tokenManager->forget();
        }

        return $this->tokenManager->getToken(forceRefresh: $refused);
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
