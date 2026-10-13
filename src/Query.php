<?php

namespace async\pg;

/**
 * The awaitable returned by SocketDriver::start(): a minimal then-able, which async\await()
 * accepts on every adapter. Settles once; then() after settling calls back immediately.
 */
final class Query
{
    private bool $settled = false;
    private mixed $value = null;
    /** @var list<callable> */
    private array $waiters = [];

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        if ($this->settled) {
            $onFulfilled && $onFulfilled($this->value);
        } elseif ($onFulfilled) {
            $this->waiters[] = $onFulfilled;
        }
    }

    /** @internal */
    public function resolve(mixed $value): void
    {
        $this->settled = true;
        $this->value = $value;
        foreach ($this->waiters as $w) {
            $w($value);
        }
        $this->waiters = [];
    }
}
