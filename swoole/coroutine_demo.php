<?php

// Phase 4 — coroutine-native I/O demo (Swoole 5+/6+)
// SWOOLE_HOOK_ALL makes PDO, file, sleep, etc. yield to the scheduler on I/O,
// turning standard blocking calls into coroutine-compatible ones.
//
// Run: docker exec -it swoole_app php /var/www/swoole/coroutine_demo.php

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$dsn  = 'mysql:host=mysql;port=3306;dbname=swoole_app;charset=utf8mb4';
$user = 'swoole';
$pass = 'secret';

function hr(string $title): void
{
    echo "\n" . str_repeat('─', 50) . "\n{$title}\n" . str_repeat('─', 50) . "\n";
}

Swoole\Coroutine\run(function () use ($dsn, $user, $pass) {

    // ── 1. Sequential PDO queries ────────────────────────────────
    hr('1. Sequential PDO — 3 × SLEEP(0.1)');
    $start = microtime(true);

    for ($i = 1; $i <= 3; $i++) {
        $pdo  = new PDO($dsn, $user, $pass);
        $pdo->query('SELECT SLEEP(0.1)')->fetch();
        echo "  query {$i} done\n";
    }

    printf("  Total: %dms  (expected ~300ms)\n", (microtime(true) - $start) * 1000);

    // ── 2. Concurrent PDO queries ────────────────────────────────
    hr('2. Concurrent PDO — 3 × SLEEP(0.1) in parallel');
    $start = microtime(true);
    $wg    = new Swoole\Coroutine\WaitGroup();

    for ($i = 1; $i <= 3; $i++) {
        $wg->add();
        Swoole\Coroutine::create(function () use ($wg, $dsn, $user, $pass, $i) {
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->query('SELECT SLEEP(0.1)')->fetch();
            echo "  coroutine {$i} done\n";
            $wg->done();
        });
    }

    $wg->wait();
    printf("  Total: %dms  (expected ~100ms)\n", (microtime(true) - $start) * 1000);

    // ── 3. Connection pool ───────────────────────────────────────
    hr('3. Pool — 5 connections shared across 10 concurrent requests');

    $pool = new Swoole\Coroutine\Channel(5);
    for ($i = 0; $i < 5; $i++) {
        $pool->push(new PDO($dsn, $user, $pass));
    }

    $start = microtime(true);
    $wg    = new Swoole\Coroutine\WaitGroup();

    for ($i = 1; $i <= 10; $i++) {
        $wg->add();
        Swoole\Coroutine::create(function () use ($wg, $pool, $i) {
            $pdo = $pool->pop(3.0);   // blocks coroutine (not thread) until a connection is free
            $pdo->query('SELECT SLEEP(0.05)')->fetch();
            $pool->push($pdo);        // return connection
            echo "  request {$i} done\n";
            $wg->done();
        });
    }

    $wg->wait();
    // 10 requests × 50ms, but only 5 connections → two batches → ~100ms
    printf("  Total: %dms  (expected ~100ms with pool vs ~500ms sequential)\n",
        (microtime(true) - $start) * 1000);

    // ── 4. Concurrent HTTP fan-out ───────────────────────────────
    hr('4. HTTP fan-out — 3 concurrent requests via Coroutine\Http\Client');
    $start   = microtime(true);
    $results = [];
    $wg      = new Swoole\Coroutine\WaitGroup();

    // Calls our own raw Swoole server — make sure http_server.php is running on 8000
    $endpoints = ['/hello', '/info', '/hello'];

    foreach ($endpoints as $idx => $path) {
        $wg->add();
        Swoole\Coroutine::create(function () use ($wg, $path, $idx, &$results) {
            $client = new Swoole\Coroutine\Http\Client('127.0.0.1', 8000);
            $client->set(['timeout' => 2.0]);
            $client->get($path);
            $results[$idx] = [
                'path'   => $path,
                'status' => $client->statusCode,
                'body'   => json_decode($client->body, true),
            ];
            $client->close();
            $wg->done();
        });
    }

    $wg->wait();
    printf("  Total: %dms\n", (microtime(true) - $start) * 1000);
    foreach ($results as $r) {
        printf("  %-10s → HTTP %d  worker=%s\n",
            $r['path'], $r['status'], $r['body']['worker'] ?? '?');
    }
});