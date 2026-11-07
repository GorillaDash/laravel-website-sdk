<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\Facades\GorillaDash;
use GorillaDash\WebsiteSdk\TokenManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

/** Point base_uri somewhere else, as a site's config would. */
function useBaseUri(string $baseUri): void
{
    config()->set('website-sdk.base_uri', $baseUri);
    app()->forgetInstance(Connection::class);
}

dataset('refusals', [
    '401 from another host' => [401, 'https://gd.test'],
    '401 from graphql.gorilladash.com' => [401, 'https://graphql.gorilladash.com'],
    '404 from graphql.gorilladash.com' => [404, 'https://graphql.gorilladash.com'],
]);

it('forgets a refused full token, mints a new one and retries the exchange once', function (int $status, string $baseUri) {
    useBaseUri($baseUri);
    Http::fake([
        '*/oauth/token' => fullTokenSequence(2),
        '*/oauth/website-browser-token' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], $status)
            ->push(browserTokenBody('browser-1'), 201),
    ]);

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-1');

    $exchanges = recordedRequestsTo('/oauth/website-browser-token');

    expect($exchanges)->toHaveCount(2)
        ->and($exchanges[0]->url())->toBe($baseUri.'/oauth/website-browser-token')
        ->and($exchanges[0]->header('Authorization'))->toBe(['Bearer full-1'])
        ->and($exchanges[1]->header('Authorization'))->toBe(['Bearer full-2'])
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(2)
        ->and(app(TokenManager::class)->getToken())->toBe('full-2');
})->with('refusals');

it('gives up after one retry when the new full token is refused too', function (int $status, string $baseUri) {
    useBaseUri($baseUri);
    Http::fake([
        '*/oauth/token' => fullTokenSequence(3),
        '*/oauth/website-browser-token' => Http::response(['message' => 'Unauthenticated.'], $status),
    ]);

    expect(fn () => app(TokenManager::class)->getBrowserToken())->toThrow(GdRequestException::class, "HTTP {$status}")
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(2);
})->with('refusals');

it('does not mint a new full token for a 404 from a host that does not serve the exchange', function () {
    useBaseUri('https://api.gorilladash.com');
    Http::fake([
        '*/oauth/token' => fullTokenSequence(2),
        '*/oauth/website-browser-token' => Http::response('', 404),
    ]);

    expect(fn () => app(TokenManager::class)->getBrowserToken())->toThrow(
        GdRequestException::class,
        'base_uri is https://api.gorilladash.com; in production only graphql.gorilladash.com serves the exchange.',
    )
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1)
        ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(1);
});

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

/** The key TokenManager stores this site's browser token under. */
function browserTokenKey(): string
{
    return 'gd_website:browser-token:'.app(Connection::class)->fingerprint();
}

/** Take the exchange lock as another request would. */
function holdExchangeLock(): Lock
{
    $lock = Cache::lock(browserTokenKey().':exchange', 40);
    $lock->get();

    return $lock;
}

it('waits for the request holding the exchange lock when it has no token at all', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-from-this-request'),
    ]);

    $holder = holdExchangeLock();

    // While this request waits, the holder finishes its exchange and lets go.
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($holder): void {
        $now = now()->timestamp;
        Cache::put(browserTokenKey(), [
            'access_token' => 'browser-from-holder',
            'expires_at' => $now + 3600,
            'refresh_at' => $now + 3000,
        ], 3600);
        $holder->release();
    });

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-from-holder');

    Http::assertNothingSent();
});

it('exchanges on its own when the wait for the lock times out', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => browserTokenResponse('browser-1'),
    ]);

    holdExchangeLock();
    Sleep::fake(syncWithCarbon: true);

    expect(app(TokenManager::class)->getBrowserToken())->toBe('browser-1')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);
});

it('returns the previous token at once while another request holds the exchange lock', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(browserTokenBody('browser-1'), 201)
            ->push(browserTokenBody('browser-2'), 201),
    ]);

    $tokens = app(TokenManager::class);
    $tokens->getBrowserToken();

    $this->travel(3000)->seconds();
    holdExchangeLock();
    Sleep::fake(syncWithCarbon: true);

    expect($tokens->getBrowserTokenWithExpiry())->toBe(['access_token' => 'browser-1', 'expires_in' => 600])
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);

    Sleep::assertNeverSlept();
});

it('refreshes without a lock on a store that has none', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(browserTokenBody('browser-1'), 201)
            ->push(browserTokenBody('browser-2'), 201),
    ]);

    $store = new class implements Store
    {
        private ArrayStore $items;

        public function __construct()
        {
            $this->items = new ArrayStore;
        }

        public function get($key)
        {
            return $this->items->get($key);
        }

        public function many(array $keys)
        {
            return $this->items->many($keys);
        }

        public function put($key, $value, $seconds)
        {
            return $this->items->put($key, $value, $seconds);
        }

        public function putMany(array $values, $seconds)
        {
            return $this->items->putMany($values, $seconds);
        }

        public function increment($key, $value = 1)
        {
            return $this->items->increment($key, $value);
        }

        public function decrement($key, $value = 1)
        {
            return $this->items->decrement($key, $value);
        }

        public function forever($key, $value)
        {
            return $this->items->forever($key, $value);
        }

        public function forget($key)
        {
            return $this->items->forget($key);
        }

        public function flush()
        {
            return $this->items->flush();
        }

        public function getPrefix()
        {
            return '';
        }
    };

    expect($store)->not->toBeInstanceOf(LockProvider::class);

    $tokens = new TokenManager(app(Connection::class), new Repository($store), app(HttpFactory::class));

    expect($tokens->getBrowserToken())->toBe('browser-1')
        ->and($tokens->getBrowserToken())->toBe('browser-1');

    $this->travel(3000)->seconds();

    expect($tokens->getBrowserToken())->toBe('browser-2')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);
});

it('exchanges with short timeouts, whatever http_timeout says', function () {
    config()->set('website-sdk.http_timeout', 30);
    app()->forgetInstance(Connection::class);

    $options = [];
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options[parse_url($request->url(), PHP_URL_PATH)] = [$requestOptions['timeout'], $requestOptions['connect_timeout']];

        return str_ends_with($request->url(), '/oauth/token')
            ? Http::response(['access_token' => 'full-1', 'expires_in' => 1296000])
            : browserTokenResponse('browser-1');
    });

    app(TokenManager::class)->getBrowserToken();

    expect($options)->toBe([
        '/oauth/token' => [5, 2],
        '/oauth/website-browser-token' => [3, 2],
    ]);
});

describe('after a failed exchange', function () {
    beforeEach(function () {
        Exceptions::fake();
    });

    it('returns the previous token while it is still valid, and reports the failure once', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
            'gd.test/oauth/website-browser-token' => Http::sequence()
                ->push(browserTokenBody('browser-1'), 201)
                ->push(['message' => 'Server Error'], 500),
        ]);

        $tokens = app(TokenManager::class);
        $tokens->getBrowserToken();

        $this->travel(3100)->seconds();

        expect($tokens->getBrowserTokenWithExpiry())->toBe(['access_token' => 'browser-1', 'expires_in' => 500])
            ->and($tokens->getBrowserToken())->toBe('browser-1')
            ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (GdRequestException $exception): bool => str_contains($exception->getMessage(), 'HTTP 500'));
    });

    it('throws when no previous token is valid', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
            'gd.test/oauth/website-browser-token' => Http::response(['message' => 'Server Error'], 500),
        ]);

        expect(fn () => app(TokenManager::class)->getBrowserToken())->toThrow(GdRequestException::class, 'HTTP 500');

        Exceptions::assertReportedCount(1);
    });

    it('makes no request for 30 seconds, forced or not, then tries again', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
            'gd.test/oauth/website-browser-token' => Http::sequence()
                ->push(['message' => 'Server Error'], 500)
                ->push(browserTokenBody('browser-1'), 201),
        ]);

        $tokens = app(TokenManager::class);

        expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class, 'HTTP 500');

        $this->travel(29)->seconds();

        expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class, 'last exchange failed')
            ->and(fn () => $tokens->getBrowserToken(forceRefresh: true))->toThrow(GdRequestException::class, 'last exchange failed')
            ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);

        Exceptions::assertReportedCount(1);

        $this->travel(1)->seconds();

        expect($tokens->getBrowserToken())->toBe('browser-1')
            ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);
    });

    it('costs one request and no new full token per 30 seconds when the host never serves the exchange', function () {
        useBaseUri('https://api.gorilladash.com');
        Http::fake([
            '*/oauth/token' => fullTokenSequence(3),
            '*/oauth/website-browser-token' => Http::response('', 404),
        ]);

        $tokens = app(TokenManager::class);

        foreach (range(1, 3) as $attempt) {
            expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class);
            $this->travel(10)->seconds();
        }

        $this->travel(1)->seconds();
        expect(fn () => $tokens->getBrowserToken())->toThrow(GdRequestException::class);

        expect(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2)
            ->and(recordedRequestsTo('/oauth/token'))->toHaveCount(1);
    });
});

it('refreshes a short-lived token after a minute, not on every request', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'full-1', 'expires_in' => 1296000]),
        'gd.test/oauth/website-browser-token' => Http::sequence()
            ->push(browserTokenBody('browser-1', 300), 201)
            ->push(browserTokenBody('browser-2'), 201),
    ]);

    $tokens = app(TokenManager::class);

    expect($tokens->getBrowserToken())->toBe('browser-1');

    $this->travel(59)->seconds();
    expect($tokens->getBrowserToken())->toBe('browser-1')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(1);

    $this->travel(1)->seconds();
    expect($tokens->getBrowserToken())->toBe('browser-2')
        ->and(recordedRequestsTo('/oauth/website-browser-token'))->toHaveCount(2);
});
