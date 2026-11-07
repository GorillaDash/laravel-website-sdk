<?php

declare(strict_types=1);

use GorillaDash\WebsiteSdk\Support\GraphQlOperations;

it('lists the type of every operation in a document', function (string $document, array $types) {
    expect(GraphQlOperations::types($document))->toBe($types);
})->with([
    'a shorthand query' => ['{ websiteInfo { name } }', ['query']],
    'a named query' => ['query Info { websiteInfo { name } }', ['query']],
    'a keyword touching its selection' => ['query{websiteInfo{name}}', ['query']],
    'a query with variables and a default object' => [
        'query ($filter: Filter = { name: "x" }, $page: Int = 1) { products(filter: $filter, page: $page) { id } }',
        ['query'],
    ],
    'a query with a fragment' => [
        'query { websiteInfo { ...Info } } fragment Info on WebsiteInfo { name }',
        ['query'],
    ],
    'a mutation' => ['mutation { submitEnquiry { id } }', ['mutation']],
    'a subscription' => ['subscription { orderUpdated { id } }', ['subscription']],
    'a query and a mutation' => [
        "query A { websiteInfo { name } }\nmutation B { submitEnquiry { id } }",
        ['query', 'mutation'],
    ],
    'mutation in a comment' => ["# mutation { x }\n{ websiteInfo { name } }", ['query']],
    'mutation in a string' => ['{ websitePage(slug: "} mutation { x") { name } }', ['query']],
    'mutation in a block string' => ['{ websitePage(slug: """ \""" } mutation { x """) { name } }', ['query']],
    'a field named mutation' => ['{ mutation: websiteInfo { mutation } }', ['query']],
    'a byte order mark and commas' => ["\xEF\xBB\xBF,{ websiteInfo { name } },", ['query']],
    'nothing but whitespace' => ["  \n# only a comment\n", []],
]);

it('rejects a document it cannot read', function (string $document) {
    expect(GraphQlOperations::types($document))->toBeNull();
})->with([
    'an unclosed selection set' => ['{ websiteInfo { name }'],
    'a stray closing brace' => ['{ websiteInfo } }'],
    'an unclosed string' => ['{ websitePage(slug: "about) { name } }'],
    'a string broken by a newline' => ["{ websitePage(slug: \"ab\nout\") { name } }"],
    'an unclosed block string' => ['{ websitePage(slug: """about) { name } }'],
    'an operation with no selection set' => ['query Info'],
    'a schema definition' => ['type Query { websiteInfo: WebsiteInfo }'],
    'a variable at the top level' => ['$slug { websiteInfo { name } }'],
    'unbalanced parentheses' => ['query ($slug: String { websiteInfo { name } }'],
]);
