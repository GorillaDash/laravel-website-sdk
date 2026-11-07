<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\Facades\GorillaDash;
use GorillaDash\WebsiteSdk\TokenManager;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

/**
 * @return Collection<int, Request>
 */
function recordedRequestsTo(string $path): Collection
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === $path)
        ->values();
}

function fullTokenSequence(int $count): ResponseSequence
{
    $sequence = Http::sequence();

    for ($index = 1; $index <= $count; $index++) {
        $sequence->push(['access_token' => "full-{$index}", 'expires_in' => 1296000]);
    }

    return $sequence;
}

it('exchanges the full token for a browser token on base_uri, never the write host', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-1'),
    ]);

    $token = GorillaDash::connection(['write_base_uri' => 'https://write.test'])->browserToken();

    expect($token)->toBe('browser-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gd.test/oauth/website-browser-token'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer full-1'));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://write.test'));
});

it('never returns a token answered outside the responder envelope', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::response(['access_token' => 'full-2', 'expires_in' => 3600], 201),
    ]);

    app(TokenManager::class)->getBrowserToken();
})->throws(GdRequestException::class, 'did not return data.access_token and data.expires_in');

it('never returns a token without the read-only scope', function (array $data) {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::response(['status' => 201, 'success' => true, 'data' => $data], 201),
    ]);

    app(TokenManager::class)->getBrowserToken();
})->with([
    'a full scope' => [['token_type' => 'Bearer', 'scope' => '*', 'access_token' => 'full-2', 'expires_in' => 3600]],
    'no scope' => [['token_type' => 'Bearer', 'access_token' => 'browser-1', 'expires_in' => 3600]],
])->throws(GdRequestException::class, 'without the website:read scope');

it('reuses the cached browser token without a second exchange', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-1'),
    ]);

    $tokens = app(TokenManager::class);

    expect($tokens->getBrowserToken())->toBe('browser-1')
        ->and($tokens->getBrowserToken())->toBe('browser-1')
        ->and(GorillaDash::browserToken())->toBe('browser-1')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(1);
});

it('reports the seconds the cached browser token has left', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-1'),
    ]);

    $tokens = app(TokenManager::class);
    $tokens->getBrowserToken();

    $this->travel(100)->seconds();

    expect($tokens->getBrowserTokenWithExpiry())->toBe(['access_token' => 'browser-1', 'expires_in' => 3500]);
});

it('caches the browser token by expires_in until it has ten minutes left', function (int $expiresIn, int $cachedFor) {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(browserTokenBody('browser-1', $expiresIn), 201)
            ->push(browserTokenBody('browser-2'), 201),
    ]);

    $tokens = app(TokenManager::class);
    expect($tokens->getBrowserToken())->toBe('browser-1');

    $this->travel($cachedFor - 1)->seconds();
    expect($tokens->getBrowserToken())->toBe('browser-1')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);

    $this->travel(1)->seconds();
    expect($tokens->getBrowserToken())->toBe('browser-2')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);
})->with([
    'a new token, an hour left' => [3600, 3000],
    'a reused token, fifteen minutes left' => [900, 300],
]);

it('forgets a refused full token, mints a new one and retries the exchange once', function (int $status) {
    Http::fake([
        'gd.test/oauth/token' => fullTokenSequence(2),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], $status)
            ->push(browserTokenBody('browser-1'), 201),
    ]);

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-1');

    $exchanges = recordedRequestsTo('/oauth/website-browser-token');

    expect($exchanges)->toHaveCount(2)
        ->and($exchanges[0]->header('Authorization'))->toBe(['Bearer full-1'])
        ->and($exchanges[1]->header('Authorization'))->toBe(['Bearer full-2'])
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(2)
        ->and(app(TokenManager::class)->getToken())->toBe('full-2');
})->with([401, 404]);

it('gives up after one retry when the new full token is refused too', function (int $status) {
    Http::fake([
        'gd.test/oauth/token' => fullTokenSequence(3),
        'gd.test/oauth/website-browser-token' => Http::response(['message' => 'Unauthenticated.'], $status),
    ]);

    $tokens = app(TokenManager::class);

    expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class, "HTTP {$status}")
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(2);

    // Within the minute a refusal does not mint again, so a host that never
    // accepts the exchange cannot mint a full token per request.
    expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class)
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(3)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(2);
})->with([401, 404]);

it('names the host when the exchange is not found', function () {
    Http::fake([
        'gd.test/oauth/token' => fullTokenSequence(2),
        'gd.test/oauth/website-browser-token' => Http::response('', 404),
    ]);

    app(TokenManager::class)->getBrowserToken();
})->throws(GdRequestException::class, 'base_uri is https://gd.test; in production only graphql.gorilladash.com serves the exchange.');

it('throws on any other failure without minting a new full token', function () {
    Http::fake([
        'gd.test/oauth/token' => fullTokenSequence(2),
        'gd.test/oauth/website-browser-token' => Http::response(['message' => 'Too Many Attempts.'], 429),
    ]);

    expect(fn () => app(TokenManager::class)->getBrowserToken())->toThrow(GdRequestException::class, 'HTTP 429')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(1);
});

it('throws when the exchange answers without a token or a lifetime', function (array $data) {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::response(['status' => 201, 'success' => true, 'data' => $data], 201),
    ]);

    app(TokenManager::class)->getBrowserToken();
})->with([
    'no token' => [['scope' => 'website:read', 'expires_in' => 3600]],
    'no lifetime' => [['scope' => 'website:read', 'access_token' => 'browser-1']],
])->throws(GdRequestException::class, 'did not return data.access_token and data.expires_in');

it('honours a forced refresh at most once a minute', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(browserTokenBody('browser-1'), 201)
            ->push(browserTokenBody('browser-2'), 201)
            ->push(browserTokenBody('browser-3'), 201),
    ]);

    $tokens = app(TokenManager::class);

    expect($tokens->getBrowserToken())->toBe('browser-1')
        ->and($tokens->getBrowserToken(forceRefresh: true))->toBe('browser-2')
        ->and($tokens->getBrowserToken(forceRefresh: true))->toBe('browser-2')
        ->and($tokens->getBrowserToken(forceRefresh: true))->toBe('browser-2')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);

    $this->travel(61)->seconds();

    expect($tokens->getBrowserToken(forceRefresh: true))->toBe('browser-3')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(3);
});

it('uses the token cached by the request that held the exchange lock', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-from-this-request'),
    ]);

    $key = 'gd_website:browser-token:'.app(Connection::class)->fingerprint();
    $holder = Cache::lock($key.':exchange', 40);
    $holder->get();

    // While this request waits, the holder finishes its exchange and lets go.
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($key, $holder): void {
        Cache::put($key, ['access_token' => 'browser-from-holder', 'expires_at' => now()->timestamp + 3600], 3000);
        $holder->release();
    });

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-from-holder');

    Http::assertNothingSent();
});

it('exchanges anyway when the lock wait times out', function () {
    config()->set('website-sdk.http_timeout', 1);
    app()->forgetInstance(Connection::class);

    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-1'),
    ]);

    $key = 'gd_website:browser-token:'.app(Connection::class)->fingerprint();
    Cache::lock($key.':exchange', 40)->get();
    Sleep::fake(syncWithCarbon: true);

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-1')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);
});
