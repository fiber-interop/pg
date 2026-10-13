<?php

namespace async\pg;

/**
 * Runs queries for async\pg\start().
 *
 * start() must send the query (or queue it behind the connection's in-flight query) before it
 * returns, and give back something async\await() accepts (repeatable, by several awaiters) that
 * resolves to what pg_query($c, $sql) would return, or pg_query_params($c, $sql, $params) when
 * $params is not null, with pg_last_error($c) set as those would leave it.
 */
interface Driver
{
    public function start(\PgSql\Connection $c, string $sql, ?array $params = null): object;
}
