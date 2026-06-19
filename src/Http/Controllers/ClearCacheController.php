<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Http\Controllers;

use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cache-clear webhook. GorillaDash calls
 * {site}/{clear_cache_path}?key={public_key} when content changes; a matching
 * public key flushes this site's cached content.
 */
class ClearCacheController
{
    public function __construct(private readonly WebsiteClient $client)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $configured = (string) config('website-sdk.public_key');
        $provided = (string) $request->query('key');

        abort_if($configured === '' || ! hash_equals($configured, $provided), 404);

        $this->client->flush();

        return response()->json(['cleared' => true]);
    }
}
