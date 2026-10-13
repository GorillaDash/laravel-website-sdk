<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\Exceptions\GdRequestException;
use GorillaDash\WebsiteSdk\WebsiteClient;
use GraphQL\Query;
use GraphQL\RawObject;
use GraphQL\Variable;
use Illuminate\Support\Facades\Http;

it('raises a typed exception on GraphQL errors', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['errors' => [['message' => 'Field not found']]]),
    ]);

    $query = (new Query('websiteInfo'))->setSelectionSet(['id']);

    app(WebsiteClient::class)->graphql($query);
})->throws(GdRequestException::class, 'Field not found');

it('retries once with a fresh token after a 401', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'tok-1', 'expires_in' => 3600])
            ->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::sequence()
            ->push(['message' => 'Unauthenticated'], 401)
            ->push(['data' => ['websiteInfo' => ['id' => '1']]], 200),
    ]);

    $query = (new Query('websiteInfo'))->setSelectionSet(['id']);

    $data = app(WebsiteClient::class)->graphql($query);

    expect($data)->toBe(['websiteInfo' => ['id' => '1']]);

    // Second GraphQL call carried the freshly re-exchanged token.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/graphql')
        && $request->hasHeader('Authorization', 'Bearer tok-2'));
});

it('accepts an mghoneimy query object and forwards its variables', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['slug' => 'home']]]),
    ]);

    $query = (new Query('websitePage'))
        ->setVariables([new Variable('slug', 'String', true)])
        ->setArguments(['slug' => new RawObject('$slug')])
        ->setSelectionSet(['slug', 'name']);

    app(WebsiteClient::class)->graphql($query, ['slug' => 'home']);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/graphql')
            && str_contains($request['query'], 'websitePage(slug: $slug)')
            && (array) $request['variables'] === ['slug' => 'home'];
    });
});

it('mutate() bypasses the cache entirely', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::sequence()
            ->push(['data' => ['submitEnquiry' => ['1']]])
            ->push(['data' => ['submitEnquiry' => ['2']]]),
    ]);

    $client = app(WebsiteClient::class);
    $first = $client->mutate('mutation { submitEnquiry }');
    $second = $client->mutate('mutation { submitEnquiry }');

    // Identical payloads must hit the API twice — a cached mutation would
    // replay the first submission instead of creating a second one.
    expect($first)->toBe(['submitEnquiry' => ['1']])
        ->and($second)->toBe(['submitEnquiry' => ['2']]);
    Http::assertSentCount(3); // 1 token + 2 mutations
});

it('mutate() goes to base_uri when no write host is configured', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['submitEnquiry' => ['1']]]),
    ]);

    app(WebsiteClient::class)->mutate('mutation { submitEnquiry }');

    Http::assertSent(fn ($request) => $request->url() === 'https://gd.test/graphql');
});

it('mutate() goes to write_base_uri, with a token that host issued, while reads stay on base_uri', function () {
    config(['website-sdk.write_base_uri' => 'https://writes.test/']);
    app()->forgetInstance(WebsiteClient::class);

    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'read-token', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['id' => '1']]]),
        'writes.test/oauth/token' => Http::response(['access_token' => 'write-token', 'expires_in' => 3600]),
        'writes.test/graphql' => Http::response(['data' => ['submitEnquiry' => ['1']]]),
    ]);

    $client = app(WebsiteClient::class);
    $client->graphql((new Query('websiteInfo'))->setSelectionSet(['id']));
    $data = $client->mutate('mutation { submitEnquiry }');

    expect($data)->toBe(['submitEnquiry' => ['1']]);

    // Each host is asked for its own token, and each request carries the one its
    // host issued — the write host never sees the read host's token.
    Http::assertSent(fn ($request) => $request->url() === 'https://writes.test/oauth/token');
    Http::assertSent(fn ($request) => $request->url() === 'https://writes.test/graphql'
        && str_contains($request['query'], 'submitEnquiry')
        && $request->hasHeader('Authorization', 'Bearer write-token'));
    Http::assertSent(fn ($request) => $request->url() === 'https://gd.test/graphql'
        && str_contains($request['query'], 'websiteInfo')
        && $request->hasHeader('Authorization', 'Bearer read-token'));
    Http::assertNotSent(fn ($request) => $request->url() === 'https://gd.test/graphql'
        && str_contains($request['query'], 'submitEnquiry'));
});

it('mutate() accepts an mghoneimy Mutation object', function () {
    Http::fake([
        'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'gd.test/graphql' => Http::response(['data' => ['submitEnquiry' => ['1']]]),
    ]);

    $mutation = (new GraphQL\Mutation('submitEnquiry'))
        ->setVariables([new Variable('email', 'String', true)])
        ->setArguments(['email' => new RawObject('$email')]);

    app(WebsiteClient::class)->mutate($mutation, ['email' => 'mary@example.test']);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/graphql')
        && str_contains($request['query'], 'mutation(')
        && str_contains($request['query'], 'submitEnquiry(email: $email)')
        && (array) $request['variables'] === ['email' => 'mary@example.test']);
});

it('reads a blank write host as none, and trims its trailing slash', function () {
    expect(Connection::fromConfig(['base_uri' => 'https://gd.test', 'write_base_uri' => ''])->forWrites()->baseUri)
        ->toBe('https://gd.test')
        ->and(Connection::fromConfig(['base_uri' => 'https://gd.test', 'write_base_uri' => 'https://api.gd.test/'])->forWrites()->baseUri)
        ->toBe('https://api.gd.test')
        ->and(Connection::fromConfig(['base_uri' => 'https://gd.test'])->with(['write_base_uri' => 'https://api.gd.test'])->forWrites()->baseUri)
        ->toBe('https://api.gd.test');
});
