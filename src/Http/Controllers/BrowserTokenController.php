<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Http\Controllers;

use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional browser-token endpoint, for a site whose browser code needs a new
 * read-only token without a page load. Answers { access_token, expires_in };
 * ?refresh=1 says Gorilla Dash refused the last one, and is honoured at most
 * once a minute per site.
 *
 * The answer is never stored by a browser or a shared cache: a cached copy
 * would hand out a token after it expired.
 */
class BrowserTokenController
{
    public function __construct(private readonly WebsiteClient $client) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $response = response()->json($this->client->browserTokenWithExpiry($request->boolean('refresh')));
        } catch (GdRequestException $exception) {
            report($exception);

            $response = response()->json(['message' => 'A browser token is not available right now.'], 503);
        }

        return $response->header('Cache-Control', 'no-store, private');
    }
}
