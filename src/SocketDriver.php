<?php

namespace async\pg;

/**
 * The default driver: pg_send_query()/pg_send_query_params(), then the task waits with
 * async\readable() on pg_socket() until libpq has the whole reply. A real file descriptor, so
 * no polling. libpq allows one query in flight per connection: a query started while another
 * is running on the same connection is queued and sent when that one completes.
 */
final class SocketDriver implements Driver
{
    /** @var \WeakMap<\PgSql\Connection, Query> the last query queued on each connection */
    private \WeakMap $tail;

    public function __construct()
    {
        $this->tail = new \WeakMap();
    }

    public function start(\PgSql\Connection $c, string $sql, ?array $params = null): object
    {
        $q = new Query();
        $prev = $this->tail[$c] ?? null;
        $this->tail[$c] = $q;
        $sent = $prev === null && self::send($c, $sql, $params);
        \async\go(function () use ($c, $sql, $params, $q, $prev, $sent) {
            if ($prev !== null) {
                \async\await($prev);
                $sent = self::send($c, $sql, $params);
            }
            try {
                $q->resolve($sent ? self::wait($c) : false);
            } finally {
                if (($this->tail[$c] ?? null) === $q) {
                    unset($this->tail[$c]);
                }
            }
        });
        return $q;
    }

    private static function send(\PgSql\Connection $c, string $sql, ?array $params): bool
    {
        return $params === null ? \pg_send_query($c, $sql) : \pg_send_query_params($c, $sql, $params);
    }

    /** Wait for the reply; return the last result as pg_query() would (false on an error status). */
    private static function wait(\PgSql\Connection $c): \PgSql\Result|false
    {
        $sock = \pg_socket($c);
        while (\pg_consume_input($c) && \pg_connection_busy($c)) {
            \async\readable($sock);
        }
        $last = false;
        while (($r = \pg_get_result($c)) !== false) {
            $last = $r;
        }
        if ($last === false) {
            return false;
        }
        return match (\pg_result_status($last)) {
            \PGSQL_EMPTY_QUERY, \PGSQL_BAD_RESPONSE, \PGSQL_NONFATAL_ERROR, \PGSQL_FATAL_ERROR => false,
            default => $last,
        };
    }
}
