<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Exceptions;

use RuntimeException;

/**
 * Thrown when a request to the GorillaDash API fails — transport error, auth
 * failure, or GraphQL errors in the response body.
 */
class GdRequestException extends RuntimeException
{
    /**
     * @param  array<int, mixed>  $graphqlErrors
     */
    public function __construct(
        string $message,
        public readonly array $graphqlErrors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    public static function fromGraphqlErrors(array $errors): self
    {
        $first = $errors[0]['message'] ?? 'Unknown GraphQL error';

        return new self("GorillaDash GraphQL error: {$first}", $errors);
    }
}
