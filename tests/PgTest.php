<?php

namespace async\pg\Tests;

use PHPUnit\Framework\TestCase;
use function async\{run, go, await, await_all};

final class PgTest extends TestCase
{
    private static function conn(): \PgSql\Connection
    {
        return pg_connect(PG_DSN, PGSQL_CONNECT_FORCE_NEW);
    }

    private static function one(\PgSql\Result|false $r): mixed
    {
        return $r === false ? false : pg_fetch_result($r, 0, 0);
    }

    public function testOutsideRunBehavesLikePgQuery(): void
    {
        $c = self::conn();
        $this->assertSame('2', self::one(\async\pg\query($c, 'SELECT 1 + 1')));
        $this->assertSame('ab', self::one(\async\pg\query_params($c, 'SELECT $1::text || $2', ['a', 'b'])));
        $this->assertSame('3', self::one(run(fn($q) => await($q), \async\pg\start($c, 'SELECT 3'))));
        $this->assertFalse(@\async\pg\query($c, 'SELEC 1'));
        $this->assertStringContainsString('syntax error', pg_last_error($c));
    }

    public function testQueryInsideRun(): void
    {
        $c = self::conn();
        $this->assertSame(['2', 'xy', '1'], run(fn() => [
            self::one(\async\pg\query($c, 'SELECT 1 + 1')),
            self::one(\async\pg\query_params($c, 'SELECT $1::text || $2', ['x', 'y'])),
            self::one(\async\pg\query($c, 'SELECT 0; SELECT 1')),
        ]));
    }

    public function testOverlap(): void
    {
        $conns = array_map(fn() => self::conn(), range(1, 10));
        $t0 = microtime(true);
        $r = run(fn() => await_all(array_map(
            fn($c) => go(fn() => self::one(\async\pg\query($c, 'SELECT pg_sleep(0.2)::text || \'ok\''))), $conns)));
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'ok'), $r);
        $this->assertLessThan(1.0, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $c = self::conn();
        $ticks = 0;
        $seen = run(function () use ($c, &$ticks) {
            $ticker = go(function () use (&$ticks) {
                for ($i = 0; $i < 5; $i++) { \async\sleep(0.02); $ticks++; }
            });
            \async\pg\query($c, 'SELECT pg_sleep(0.3)');
            $seen = $ticks;
            await($ticker);
            return $seen;
        });
        $this->assertSame(5, $seen);
    }

    public function testEagerStartAndRepeatedAwait(): void
    {
        $c = self::conn();
        [$a, $b, $elapsed] = run(function () use ($c) {
            $q = \async\pg\start($c, 'SELECT pg_sleep(0.2)::text || \'done\'');
            \async\sleep(0.2); // the query runs meanwhile
            $t0 = microtime(true);
            $a = await($q);
            return [self::one($a), self::one(await($q)), microtime(true) - $t0];
        });
        $this->assertSame(['done', 'done'], [$a, $b]);
        $this->assertLessThan(0.1, $elapsed);
    }

    public function testSyntaxErrorReportsAndConnectionSurvives(): void
    {
        $c = self::conn();
        [$bad, $err, $good] = run(fn() => [
            \async\pg\query($c, 'SELEC 1'),
            pg_last_error($c),
            self::one(\async\pg\query($c, 'SELECT 5')),
        ]);
        $this->assertFalse($bad);
        $this->assertStringContainsString('syntax error', $err);
        $this->assertSame('5', $good);
        $this->assertSame(PGSQL_CONNECTION_OK, pg_connection_status($c));
    }

    public function testQueriesOnOneConnectionQueue(): void
    {
        $c = self::conn();
        [$r, $elapsed] = run(function () use ($c) {
            $t0 = microtime(true);
            $a = \async\pg\start($c, 'SELECT pg_sleep(0.1)::text || \'a\'');
            $b = \async\pg\start($c, 'SELECT \'b\'');
            $x = \async\pg\start($c, 'SELECT $1::text', ['c']);
            return [[self::one(await($b)), self::one(await($a)), self::one(await($x))], microtime(true) - $t0];
        });
        $this->assertSame(['b', 'a', 'c'], $r);
        $this->assertGreaterThanOrEqual(0.1, $elapsed);
    }

    public function testTimedOutAwaitLeavesQueryRunning(): void
    {
        $c = self::conn();
        [$timedOut, $late, $after] = run(function () use ($c) {
            $q = \async\pg\start($c, 'SELECT pg_sleep(0.2)::text || \'late\'');
            $timedOut = false;
            try {
                await($q, 0.05);
            } catch (\async\TimeoutException) {
                $timedOut = true;
            }
            return [$timedOut, self::one(await($q)), self::one(\async\pg\query($c, 'SELECT 7'))];
        });
        $this->assertSame([true, 'late', '7'], [$timedOut, $late, $after]);
    }

    public function testConnect(): void
    {
        [$v, $elapsed] = run(function () {
            $t0 = microtime(true);
            $cs = await_all(array_map(fn() => go(fn() => \async\pg\connect(PG_DSN, PGSQL_CONNECT_FORCE_NEW)), range(1, 3)));
            return [array_map(fn($c) => self::one(\async\pg\query($c, 'SELECT 1')), $cs), microtime(true) - $t0];
        });
        $this->assertSame(['1', '1', '1'], $v);
        $this->assertFalse(run(fn() => \async\pg\connect('host=127.0.0.1 port=1 connect_timeout=2')));
        $this->assertInstanceOf(\PgSql\Connection::class, \async\pg\connect(PG_DSN));
    }
}
