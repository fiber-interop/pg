<?php

namespace async\pg;

/**
 * Runs queries for async\pg\start().
 *
 * start() must send the query (or queue it behind the connection's in-flight query) before it
 * returns, and give back something async\await() accepts (repeatable, by several awaiters) that
 * resolves to what pg_query($c, $sql) would return, or pg_query_params($c, $sql, $params) when
 * $params is not null, with pg_last_error($c) set as those would leave it. prepare() and
 * execute() are the same for pg_prepare($c, $name, $sql) and pg_execute($c, $name, $params).
 */
interface Driver
{
    public function start(\PgSql\Connection $c, string $sql, ?array $params = null): object;

    public function prepare(\PgSql\Connection $c, string $name, string $sql): object;

    public function execute(\PgSql\Connection $c, string $name, array $params): object;
}
