<?php
/**
 * FI_RUNTIME=revolt|amp|react|phasync|swoole selects the adapter (default revolt).
 * FI_DRIVER=generic forces SocketDriver; FI_DRIVER=native requires the adapter's native driver.
 * PG_DSN is the libpq conninfo of a test server (default matches the README's docker command).
 */
require __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) {
    require_once __DIR__ . '/../vendor/fiber-interop/api/tests/Support/ScopeStub.php';
}

$runtime = getenv('FI_RUNTIME') ?: 'revolt';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
async\setup(new $class());

$driver = getenv('FI_DRIVER') ?: 'generic';
if ($driver === 'generic') {
    async\pg\setup(new async\pg\SocketDriver());
} elseif (async\pg\Registry::$candidates === []) {
    fwrite(STDERR, "No native async\\pg driver registered for $runtime\n");
    exit(1);
}
fwrite(STDERR, "runtime=$runtime driver=" . get_class(async\pg\driver()) . "\n");

define('PG_DSN', getenv('PG_DSN') ?: 'host=127.0.0.1 port=15432 user=fi password=fi dbname=fi');
