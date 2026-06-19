<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Support;

/**
 * Builds a GraphQL document from a query name, an explicit field selection, and
 * a set of (string) arguments.
 *
 * Every GorillaDash query requires the caller to state which fields it wants —
 * this helper makes that selection a first-class input and turns arguments into
 * declared GraphQL variables.
 */
final class QueryBuilder
{
    /**
     * @param  array<string, mixed>  $args  Argument name => value. Null/empty values are dropped.
     * @return array{query: string, variables: array<string, mixed>}
     */
    public static function build(string $queryName, string $fields, array $args = []): array
    {
        $variables = array_filter(
            $args,
            static fn ($value) => $value !== null && $value !== '',
        );

        if ($variables === []) {
            return [
                'query' => "{ {$queryName} { {$fields} } }",
                'variables' => [],
            ];
        }

        $names = array_keys($variables);
        $declarations = implode(', ', array_map(static fn (string $name) => "\${$name}: String", $names));
        $arguments = implode(', ', array_map(static fn (string $name) => "{$name}: \${$name}", $names));

        return [
            'query' => "query ({$declarations}) { {$queryName}({$arguments}) { {$fields} } }",
            'variables' => $variables,
        ];
    }
}
