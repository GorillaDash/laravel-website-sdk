<?php

declare(strict_types=1);

namespace GorillaDash\WebsiteSdk\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Defers a callback until after the HTTP response has been sent to the browser,
 * so a background cache refresh never blocks the rendered page.
 *
 * Uses the application's `terminating` callbacks, which Laravel runs in
 * `Kernel::terminate()` after the response is flushed (and after Artisan
 * commands finish). No queue worker is required.
 */
class AfterResponseRefresher
{
    /** @var array<int, callable> */
    private array $pending = [];

    private bool $registered = false;

    public function __construct(private readonly Application $app) {}

    public function defer(callable $callback): void
    {
        $this->pending[] = $callback;
        $this->register();
    }

    private function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->app->terminating(fn () => $this->flush());
    }

    /**
     * Run every deferred callback. Exceptions are swallowed per-callback so a
     * failed background refresh can never surface to the (already sent) request;
     * stale data simply stays cached until the next attempt.
     */
    public function flush(): void
    {
        $callbacks = $this->pending;
        $this->pending = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable) {
                // Background refresh failed — keep serving stale, try again next request.
            }
        }
    }
}
