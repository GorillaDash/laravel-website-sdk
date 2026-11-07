<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('does not register the graphql proxy by default', function () {
    Http::fake();

    $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])->assertNotFound();

    Http::assertNothingSent();
});

describe('when a site turns the proxy on', function () {
    beforeEach(function () {
        $this->withPackageRoutes(['website-sdk.register_graphql_route' => true]);
    });

    it('serves graphql over the POST endpoint with the cache envelope', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
        ]);

        $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])
            ->assertOk()
            ->assertJson([
                'data' => ['websiteInfo' => ['name' => 'Acme']],
                'status' => 'miss',
            ]);
    });

    it('passes variables through to the transport', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['name' => 'About']]]),
        ]);

        $this->postJson('/graphql', [
            'query' => 'query ($slug: String) { websitePage(slug: $slug) { name } }',
            'variables' => ['slug' => 'about-us'],
        ])->assertOk()->assertJson(['data' => ['websitePage' => ['name' => 'About']]]);

        Http::assertSent(fn ($request) => $request->url() === 'https://gd.test/graphql'
            && (array) $request['variables'] === ['slug' => 'about-us']);
    });

    it('runs a query that only mentions mutation in a comment, a string or a field name', function () {
        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'gd.test/graphql' => Http::response(['data' => ['websitePage' => ['name' => 'About']]]),
        ]);

        $query = <<<'GRAPHQL'
            # mutation { submitEnquiry }
            query Page($slug: String = "mutation { x }") {
              websitePage(slug: $slug) { name mutation: name }
            }
            GRAPHQL;

        $this->postJson('/graphql', ['query' => $query])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://gd.test/graphql');
    });

    it('refuses a document that defines a mutation or a subscription', function (string $query) {
        Http::fake();

        $this->postJson('/graphql', ['query' => $query])
            ->assertForbidden()
            ->assertJsonPath('errors.0.message', 'This endpoint only runs GraphQL queries.');

        Http::assertNothingSent();
    })->with([
        'a mutation' => ['mutation { submitEnquiry(email: "a@b.test") { id } }'],
        'a named mutation' => ['mutation Send($email: String!) { submitEnquiry(email: $email) { id } }'],
        'a mutation after a query' => ['query A { websiteInfo { name } } mutation B { submitEnquiry { id } }'],
        'a subscription' => ['subscription { orderUpdated { id } }'],
    ]);

    it('refuses a body that carries no well-formed query', function (mixed $query) {
        Http::fake();

        $this->postJson('/graphql', ['query' => $query])->assertBadRequest();

        Http::assertNothingSent();
    })->with([
        'no query' => [null],
        'an empty query' => [''],
        'a query that is not a string' => [['mutation' => 'x']],
        'unbalanced braces' => ['{ websiteInfo { name }'],
        'only a fragment' => ['fragment F on WebsiteInfo { name }'],
    ]);

    it('rate limits the proxy per IP address', function () {
        $this->withPackageRoutes([
            'website-sdk.register_graphql_route' => true,
            'website-sdk.graphql_rate_limit' => 2,
        ]);

        Http::fake([
            'gd.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'gd.test/graphql' => Http::response(['data' => ['websiteInfo' => ['name' => 'Acme']]]),
        ]);

        $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])->assertOk();
        $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])->assertOk();
        $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'])->assertTooManyRequests();
        $this->postJson('/graphql', ['query' => '{ websiteInfo { name } }'], ['REMOTE_ADDR' => '203.0.113.9'])
            ->assertOk();
    });
});
