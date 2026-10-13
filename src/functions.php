<?php

/**
 * fiber-interop/pg: cooperative pg_query(), pg_query_params() and pg_connect() on any
 * fiber-interop adapter.
 */

namespace async\pg;

if (\function_exists('async\pg\query')) {
    return;
}

/**
 * Same contract as pg_query($c, $sql): a result, or false with pg_last_error($c) set (no
 * warning is raised inside run()). Inside async\run() only the calling task waits; outside
 * run() this is a plain blocking pg_query().
 */
function query(\PgSql\Connection $c, string $sql): \PgSql\Result|false
{
    if (\async\Registry::$active === []) {
        return \pg_query($c, $sql);
    }
    return \async\await(driver()->start($c, $sql));
}

/** Same contract as pg_query_params($c, $sql, $params); see query(). */
function query_params(\PgSql\Connection $c, string $sql, array $params): \PgSql\Result|false
{
    if (\async\Registry::$active === []) {
        return \pg_query_params($c, $sql, $params);
    }
    return \async\await(driver()->start($c, $sql, $params));
}

/**
 * Send the query now (or queue it behind the connection's running query) and return an
 * awaitable that resolves to what query() / query_params() would return. Pass $params to use
 * pg_query_params() semantics. Outside async\run() the query completes before start() returns.
 * A timed-out or cancelled await leaves the query running to completion; the connection is
 * usable again once it has.
 */
function start(\PgSql\Connection $c, string $sql, ?array $params = null): object
{
    if (\async\Registry::$active === []) {
        $q = new Query();
        $q->resolve($params === null ? \pg_query($c, $sql) : \pg_query_params($c, $sql, $params));
        return $q;
    }
    return driver()->start($c, $sql, $params);
}

/**
 * Same contract as pg_connect($conninfo, $flags), except that a failure inside run() returns
 * false without a warning. Inside async\run() the connection is made with PGSQL_CONNECT_ASYNC
 * and pg_connect_poll(), the task waiting on pg_socket() between steps (DNS resolution inside
 * libpq still blocks); outside run() this is a plain pg_connect().
 */
function connect(string $conninfo, int $flags = 0): \PgSql\Connection|false
{
    if (\async\Registry::$active === []) {
        return \pg_connect($conninfo, $flags);
    }
    $c = \pg_connect($conninfo, $flags | \PGSQL_CONNECT_ASYNC);
    if ($c === false) {
        return false;
    }
    while (true) {
        $s = \pg_connect_poll($c);
        if ($s === \PGSQL_POLLING_OK) {
            return $c;
        }
        if ($s === \PGSQL_POLLING_FAILED) {
            return false;
        }
        $sock = \pg_socket($c);
        $s === \PGSQL_POLLING_READING ? \async\readable($sock) : \async\writable($sock);
    }
}

/**
 * Install the driver. Same semantics as async\setup(): repeating it with a driver of the same
 * class is a no-op, a different class throws \LogicException.
 */
function setup(Driver $driver): void
{
    $current = Registry::$driver;
    if ($current === null) {
        Registry::$driver = $driver;
    } elseif (\get_class($current) !== \get_class($driver)) {
        throw new \LogicException(\sprintf(
            'async\pg driver %s is already set up; refusing %s.', \get_class($current), \get_class($driver)));
    }
}

/**
 * Offer a driver candidate: a thin wrapper over Registry::$candidates. Nothing is instantiated
 * here. Adapter packages do not call this from their Composer `files` autoload, because the order
 * in which packages' files load is not guaranteed; they write Registry::$candidates directly,
 * guarded by interface_exists(\async\pg\Driver::class) (autoload on). Applications and
 * packages loaded later may call register().
 *
 * @param callable(): Driver $factory
 */
function register(callable $factory, string $package): void
{
    Registry::$candidates[$package] = $factory;
}

/**
 * The driver in use: setup() wins; else the single registered candidate; else SocketDriver.
 * Two or more candidates without setup() throw \async\NotConfiguredException.
 */
function driver(): Driver
{
    if (Registry::$driver !== null) {
        return Registry::$driver;
    }
    $c = Registry::$candidates;
    if (\count($c) > 1) {
        throw new \async\NotConfiguredException('Several async\pg drivers are registered ('
            . \implode(', ', \array_keys($c)) . '); call async\pg\setup() with the one you want.');
    }
    return Registry::$driver = $c === [] ? new SocketDriver() : \current($c)();
}
