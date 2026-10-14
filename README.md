# fiber-interop/pg

Cooperative `pg_query()`, `pg_query_params()`, `pg_prepare()`, `pg_execute()` and `pg_connect()` for
[fiber-interop/api](https://github.com/fiber-interop/api): inside `async\run()` a query suspends only
the calling task, on whichever adapter is installed.

```
composer require fiber-interop/pg
```

```php
use function async\{run, go, await_all};

$rows = run(fn() => await_all(array_map(
    fn($id) => go(function () use ($id) {
        $c = async\pg\connect('host=db user=app dbname=app', PGSQL_CONNECT_FORCE_NEW);
        $r = async\pg\query_params($c, 'SELECT * FROM users WHERE id = $1', [$id]);
        return $r === false ? pg_last_error($c) : pg_fetch_assoc($r);   // same contract as pg_query_params()
    }),
    [1, 2, 3],
)));
```

## API

- `async\pg\query(\PgSql\Connection $c, string $sql): \PgSql\Result|false` and
  `async\pg\query_params(\PgSql\Connection $c, string $sql, array $params): \PgSql\Result|false` —
  the contracts of `pg_query()` / `pg_query_params()`: the (last) result, or `false` with
  `pg_last_error($c)` set; the connection stays usable after a failed query. Outside `async\run()`
  they are the plain blocking functions. Inside, a failure raises no warning (the original emits
  "Query failed").
- `async\pg\prepare(\PgSql\Connection $c, string $name, string $sql): \PgSql\Result|false` and
  `async\pg\execute(\PgSql\Connection $c, string $name, array $params): \PgSql\Result|false` —
  the same for `pg_prepare()` / `pg_execute()`, sent with `pg_send_prepare()` / `pg_send_execute()`.
- `async\pg\start(\PgSql\Connection $c, string $sql, ?array $params = null): object` — sends the query
  now and returns an awaitable (repeatable `async\await()`) resolving to what `query()` (or
  `query_params()` when `$params` is given) returns. `query()` is `await(start())`.
- `async\pg\connect(string $conninfo, int $flags = 0): \PgSql\Connection|false` — `pg_connect()`;
  inside `async\run()` it connects with `PGSQL_CONNECT_ASYNC` and `pg_connect_poll()`, waiting on
  the socket between steps. A failure returns `false` without a warning. As with `pg_connect()`,
  pass `PGSQL_CONNECT_FORCE_NEW` to get a separate connection per task.
- `async\pg\Driver` — one method, `start(\PgSql\Connection, string, ?array): object`.
- `async\pg\setup(Driver)`, `async\pg\register(callable $factory, string $package)`,
  `async\pg\driver()` — same semantics as fiber-interop/curl's registry: `setup()` wins; a single
  registered candidate is instantiated lazily; two candidates without `setup()` throw
  `async\NotConfiguredException`; with none, the default `SocketDriver` is used. Adapters write
  `async\pg\Registry::$candidates[...]` directly from their `register.php`, guarded by
  `interface_exists(\async\pg\Driver::class)` and their own runtime check.

## How it waits: on a real file descriptor

`SocketDriver` sends with `pg_send_query()` / `pg_send_query_params()` and then waits with
`async\readable(pg_socket($c))`, calling `pg_consume_input()` until `pg_connection_busy()` is false,
and collects the results with `pg_get_result()` (keeping the last, as `pg_query()` does). libpq hands
out its socket, so the event loop watches it directly: no polling, no timers, no threads. Contrast
fiber-interop/curl, where PHP exposes no descriptor for `curl_multi` and the driver has to poll.

libpq allows one query in flight per connection. A query started on a connection that is already
running one is queued and sent when that one completes, so two tasks may share a connection and
get their results in order; for parallelism, use one connection per task.

## Native drivers

| Runtime | Driver |
|---|---|
| Revolt, AMPHP, ReactPHP, phasync (with or without the extension), Swoole | `SocketDriver` |

Swoole's `SWOOLE_HOOK_ALL` covers `pdo_pgsql` (`SWOOLE_HOOK_PDO_PGSQL`), not ext-pgsql, so a plain
`pg_query()` blocks the worker there; `SocketDriver` is cooperative on it because `pg_socket()` is
an ordinary descriptor Swoole's `readable()` can wait on. The phasync extension does make plain
`pg_query()` cooperative (it redirects libpq's `poll()`), so a phasync native driver
(`go(fn() => pg_query(...))`) is possible; `SocketDriver` works there too and is what is tested.

## Limits

- A timed-out or cancelled `await()` does not abort the query; it runs to completion (the
  connection is busy until then, and later queries queue behind it) and its result is discarded
  unless awaited again.
- Inside `connect()`, host name resolution happens inside libpq and blocks; use an IP address or a
  local resolver cache if that matters. `pg_send_query()` flushes large queries with a short
  blocking loop inside the pgsql extension.
- Do not mix `async\pg` with plain `pg_query()` on the same connection while an `async\pg` query is
  in flight.

## Tests

The suite needs a PostgreSQL server; `PG_DSN` is its libpq conninfo, defaulting to
`host=127.0.0.1 port=15432 user=fi password=fi dbname=fi`, which matches:

```
docker run -d --name fi-pg -p 127.0.0.1:15432:5432 -e POSTGRES_USER=fi -e POSTGRES_PASSWORD=fi -e POSTGRES_DB=fi postgres:16
```

`FI_RUNTIME=revolt|amp|react|phasync|swoole` picks the adapter:

```
FI_RUNTIME=amp vendor/bin/phpunit
FI_RUNTIME=phasync php -d extension=phasync.so vendor/bin/phpunit
```
