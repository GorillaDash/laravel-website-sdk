<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Support;

/**
 * Reads which operations a GraphQL document defines, without a full parser.
 *
 * It walks the document's top level only: each definition starts with `{` (a
 * shorthand query) or one of `query`, `mutation`, `subscription`, `fragment`,
 * and runs to the brace that closes its selection set. Comments and strings
 * are skipped, so `mutation` inside either is never mistaken for an operation,
 * and a field named `mutation` inside a selection set is never at the top level.
 */
final class GraphQlOperations
{
    private const OPERATION_TYPES = ['query', 'mutation', 'subscription'];

    /**
     * The type of every operation in the document, in order.
     *
     * @return list<'query'|'mutation'|'subscription'>|null Null when the document
     *                                                      is not a well-formed
     *                                                      executable document.
     */
    public static function types(string $document): ?array
    {
        $length = strlen($document);
        $types = [];
        $braces = 0;
        $parens = 0;
        $atDefinitionStart = true;
        $position = 0;

        while ($position < $length) {
            $char = $document[$position];

            if ($char === '#') {
                $position = self::endOfComment($document, $position);

                continue;
            }

            if ($char === '"') {
                $position = self::endOfString($document, $position);

                if ($position === null) {
                    return null;
                }

                continue;
            }

            if (str_contains(" \t\n\r,", $char) || substr($document, $position, 3) === "\xEF\xBB\xBF") {
                $position += $char === "\xEF" ? 3 : 1;

                continue;
            }

            if ($char === '{') {
                if ($atDefinitionStart) {
                    $types[] = 'query';
                    $atDefinitionStart = false;
                }
                $braces++;
                $position++;

                continue;
            }

            if ($char === '}') {
                if (--$braces < 0) {
                    return null;
                }
                $atDefinitionStart = $braces === 0 && $parens === 0;
                $position++;

                continue;
            }

            if ($char === '(' || $char === ')') {
                $parens += $char === '(' ? 1 : -1;

                if ($parens < 0 || $atDefinitionStart) {
                    return null;
                }
                $position++;

                continue;
            }

            if (preg_match('/\G[_A-Za-z][_0-9A-Za-z]*/', $document, $match, 0, $position) === 1) {
                if ($atDefinitionStart) {
                    if (in_array($match[0], self::OPERATION_TYPES, true)) {
                        $types[] = $match[0];
                    } elseif ($match[0] !== 'fragment') {
                        return null;
                    }
                    $atDefinitionStart = false;
                }
                $position += strlen($match[0]);

                continue;
            }

            if ($atDefinitionStart) {
                return null;
            }

            $position++;
        }

        return $braces === 0 && $parens === 0 && $atDefinitionStart ? $types : null;
    }

    /** The position just past a `#` comment, which runs to the end of its line. */
    private static function endOfComment(string $document, int $position): int
    {
        $end = strcspn($document, "\n\r", $position);

        return $position + $end;
    }

    /** The position just past a string or block string; null when it never closes. */
    private static function endOfString(string $document, int $position): ?int
    {
        $length = strlen($document);

        if (substr($document, $position, 3) === '"""') {
            $position += 3;

            while (($close = strpos($document, '"""', $position)) !== false) {
                // \""" is an escaped triple quote inside a block string.
                if ($close > 0 && $document[$close - 1] === '\\') {
                    $position = $close + 3;

                    continue;
                }

                return $close + 3;
            }

            return null;
        }

        for ($position++; $position < $length; $position++) {
            $char = $document[$position];

            if ($char === '\\') {
                $position++;
            } elseif ($char === '"') {
                return $position + 1;
            } elseif ($char === "\n" || $char === "\r") {
                return null;
            }
        }

        return null;
    }
}
