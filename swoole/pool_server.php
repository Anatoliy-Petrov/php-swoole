<?php

// Phase 4 — connection pool HTTP server on port 8000
// Endpoints:
//   /nopool  — new PDO connection per request (shows connection overhead)
//   /pool    — PDO from per-worker pool      (shows pool benefit)
//   /fanout  — 3 concurrent HTTP calls via Coroutine\Http\Client
//
// Run: docker exec -it swoole_app php /var/www/swoole/pool_server.php
// Benchmark:
//   wrk -t4 -c50 -d10s http://localhost:8000/nopool
//   wrk -t4 -c50 -d10s http://localhost:8000/pool

// Enable coroutine hooks so PDO yields on I/O instead of blocking the worker
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$dbConfig = [
    'dsn'  => 'mysql:host=mysql;port=3306;dbname=swoole_app;charset=utf8mb4',
    'user' => 'swoole',
    'pass' => 'secret',
];

$server = new Swoole\Http\Server('0.0.0.0', 8000);

// Keep worker_num small: total connections = worker_num × pool_size must stay
// under MySQL's max_connections (default 151). Here: 4 × 10 = 40.
$server->set([
    'worker_num'  => 4,
    'max_request' => 10000,
    'log_level'   => SWOOLE_LOG_WARNING,
]);

// Each worker gets its own pool — pools are never shared across processes
$pool = null;

$server->on('start', function () {
    echo "Pool demo server on http://0.0.0.0:8000\n";
    echo "Endpoints: /nopool  /pool  /fanout\n";
});

$server->on('workerStart', function (Swoole\Http\Server $server, int $workerId) use (&$pool, $dbConfig) {
    $size = 10; // 10 connections per worker
    $ch   = new Swoole\Coroutine\Channel($size);

    for ($i = 0; $i < $size; $i++) {
        $pdo = new PDO($dbConfig['dsn'], $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $ch->push($pdo);
    }

    $pool = $ch;
    echo "Worker #{$workerId}: pool ready ({$size} connections)\n";
});

$server->on('request', function (Swoole\Http\Request $req, Swoole\Http\Response $res) use (&$pool, $dbConfig) {
    $path = $req->server['request_uri'] ?? '/';

    match ($path) {
        '/nopool'  => handleNoPool($res, $dbConfig),
        '/pool'    => handlePool($res, $pool),
        '/fanout'  => handleFanout($res),
        default    => handleIndex($res),
    };
});

// New PDO connection per request — pay connect overhead every time
function handleNoPool(Swoole\Http\Response $res, array $cfg): void
{
    $start = microtime(true);
    $pdo   = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass']);
    $row   = $pdo->query('SELECT 1 AS alive')->fetch(PDO::FETCH_ASSOC);
    $ms    = round((microtime(true) - $start) * 1000, 2);

    $res->header('Content-Type', 'application/json');
    $res->end(json_encode(['mode' => 'no-pool', 'elapsed_ms' => $ms, 'db' => $row, 'worker' => getmypid()]));
}

// Borrow a connection from the pool, use it, return it
function handlePool(Swoole\Http\Response $res, Swoole\Coroutine\Channel $pool): void
{
    $start = microtime(true);
    $pdo   = $pool->pop(3.0);   // yields coroutine until a connection is available

    if ($pdo === false) {
        $res->status(503);
        $res->end(json_encode(['error' => 'pool timeout']));
        return;
    }

    try {
        $row = $pdo->query('SELECT 1 AS alive')->fetch(PDO::FETCH_ASSOC);
    } finally {
        $pool->push($pdo);       // always return, even on exception
    }

    $ms = round((microtime(true) - $start) * 1000, 2);

    $res->header('Content-Type', 'application/json');
    $res->end(json_encode([
        'mode'       => 'pool',
        'elapsed_ms' => $ms,
        'db'         => $row,
        'pool_idle'  => $pool->length(),
        'worker'     => getmypid(),
    ]));
}

// Fan out 3 HTTP calls concurrently — total time ≈ slowest single call
function handleFanout(Swoole\Http\Response $res): void
{
    $start     = microtime(true);
    $results   = [];
    $wg        = new Swoole\Coroutine\WaitGroup();
    $endpoints = [
        ['host' => '127.0.0.1', 'port' => 8000, 'path' => '/pool'],
        ['host' => '127.0.0.1', 'port' => 8000, 'path' => '/pool'],
        ['host' => '127.0.0.1', 'port' => 8000, 'path' => '/pool'],
    ];

    foreach ($endpoints as $i => $ep) {
        $wg->add();
        Swoole\Coroutine::create(function () use ($wg, $ep, $i, &$results) {
            $client = new Swoole\Coroutine\Http\Client($ep['host'], $ep['port']);
            $client->set(['timeout' => 3.0]);
            $client->get($ep['path']);
            $results[$i] = json_decode($client->body, true);
            $client->close();
            $wg->done();
        });
    }

    $wg->wait();
    $ms = round((microtime(true) - $start) * 1000, 2);

    $res->header('Content-Type', 'application/json');
    $res->end(json_encode(['fanout_ms' => $ms, 'calls' => count($results), 'results' => $results]));
}

function handleIndex(Swoole\Http\Response $res): void
{
    $res->header('Content-Type', 'application/json');
    $res->end(json_encode(['routes' => ['/nopool', '/pool', '/fanout']]));
}

$server->start();