<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in(__DIR__);

/**
 * The responder envelope the browser-token endpoint answers with.
 *
 * @return array<string, mixed>
 */
function browserTokenBody(string $token, int $expiresIn = 3600): array
{
    return [
        'status' => 201,
        'success' => true,
        'data' => [
            'token_type' => 'Bearer',
            'scope' => 'website:read',
            'access_token' => $token,
            'expires_in' => $expiresIn,
        ],
    ];
}

function browserTokenResponse(string $token, int $expiresIn = 3600): PromiseInterface
{
    return Http::response(browserTokenBody($token, $expiresIn), 201);
}
