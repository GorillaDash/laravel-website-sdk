<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Commands;

use GorillaDash\WebsiteSdk\Connection;
use GorillaDash\WebsiteSdk\WebsiteClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Answer the two questions that go wrong most often on a client site:
 * "which website am I actually talking to?" and "is my cache working?".
 *
 * Both are invisible from the outside — credentials for the wrong website
 * still authenticate and still return well-formed, empty results, and a cache
 * that never persists still serves correct pages, just slowly. This prints
 * both in one shot.
 */
class CheckCommand extends Command
{
    protected $signature = 'gd:check';

    protected $description = 'Check the Gorilla Dash connection: credentials, which website they resolve to, and cache behaviour';

    public function handle(WebsiteClient $client, Connection $connection): int
    {
        $this->line('');
        $this->line('  <options=bold>Configuration</>');
        $this->line(sprintf('    base uri      %s', $connection->baseUri));
        $this->line(sprintf('    client id     %s', $connection->clientId ?: '<fg=red>not set</>'));
        $this->line(sprintf('    client secret %s', $connection->clientSecret ? str_repeat('*', 8) : '<fg=red>not set</>'));
        $this->line(sprintf('    cache store   %s', $connection->cacheStore ?? config('cache.default').' (application default)'));
        $this->line(sprintf('    freshness     %ds', $connection->cacheTtl));
        $this->line('');

        if (blank($connection->clientId) || blank($connection->clientSecret)) {
            $this->error('  Credentials are not configured — set GD_WEBSITE_CLIENT_ID and GD_WEBSITE_CLIENT_SECRET.');

            return self::FAILURE;
        }

        $this->line('  <options=bold>Connection</>');

        $started = microtime(true);

        try {
            $envelope = $client->graphqlWithMeta('{ websiteInfo { id name url } }');
        } catch (Throwable $exception) {
            $this->line(sprintf('    <fg=red>failed</> after %s', $this->ms($started)));
            $this->line('    '.$exception->getMessage());
            $this->line('');

            return self::FAILURE;
        }

        $info = $envelope['data']['websiteInfo'] ?? [];
        $this->line(sprintf('    reachable     yes, in %s', $this->ms($started)));
        $this->line(sprintf('    website       <options=bold>%s</> (id %s)', $info['name'] ?? '?', $info['id'] ?? '?'));
        $this->line(sprintf('    url           %s', $info['url'] ?? '?'));
        $this->line('');
        $this->line('    <fg=yellow>Check the website above is the one this site should serve.</>');
        $this->line('    <fg=yellow>Credentials for another website authenticate happily and return</>');
        $this->line('    <fg=yellow>empty results, which looks identical to having no content yet.</>');
        $this->line('');

        $this->line('  <options=bold>Cache</>');

        $repeat = microtime(true);
        $second = $client->graphqlWithMeta('{ websiteInfo { id name url } }');
        $repeatMs = $this->ms($repeat);

        if (($second['status'] ?? null) === 'miss') {
            $this->line(sprintf('    repeat read   <fg=red>MISS</> in %s', $repeatMs));
            $this->line('');
            $this->line('    <fg=red>The cache is not holding between reads.</> Every request will call');
            $this->line('    <fg=red>the API. Check the store above is one that persists.</>');
            $this->line('');

            return self::FAILURE;
        }

        $this->line(sprintf('    repeat read   %s in %s (age %ds)', strtoupper((string) $second['status']), $repeatMs, $second['age'] ?? 0));
        $this->line('    <fg=green>Cache is working — repeat reads are not hitting the API.</>');
        $this->line('');

        return self::SUCCESS;
    }

    private function ms(float $startedAt): string
    {
        return sprintf('%.0fms', (microtime(true) - $startedAt) * 1000);
    }
}
