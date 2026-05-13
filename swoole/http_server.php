<?php

// Raw Swoole HTTP server — no framework
// Demonstrates: worker model, coroutines, concurrent I/O

$server = new Swoole\Http\Server('0.0.0.0', 8000);

$server->set([
    'worker_num'    => swoole_cpu_num() * 2,
    'max_request'   => 1000,   // restart worker after N requests (prevents leaks)
    'log_level'     => SWOOLE_LOG_INFO,
]);

$server->on('start', function (Swoole\Http\Server $server) {
    echo sprintf(
        "Swoole %s HTTP server started on http://0.0.0.0:8000\n",
        swoole_version()
    );
    echo sprintf("Workers: %d\n", swoole_cpu_num() * 2);
});

$server->on('workerStart', function (Swoole\Http\Server $server, int $workerId) {
    echo "Worker #{$workerId} started (PID {$server->worker_pid})\n";
});

$server->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
    $path = $request->server['request_uri'] ?? '/';

    match ($path) {
        '/hello'     => handleHello($response),
        '/coroutine' => handleCoroutine($response),
        '/info'      => handleInfo($response, $request),
        default      => handleNotFound($response, $path),
    };
});

function handleHello(Swoole\Http\Response $response): void
{
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'message' => 'Hello from raw Swoole',
        'worker'  => getmypid(),
    ]));
}

// Two coroutines sleeping in parallel — total wall time ~100ms, not 200ms
// No Coroutine\run() here — the HTTP server already runs requests inside a coroutine context
function handleCoroutine(Swoole\Http\Response $response): void
{
    $results = [];
    $start   = microtime(true);

    $wg = new Swoole\Coroutine\WaitGroup();

    $wg->add();
    Swoole\Coroutine::create(function () use ($wg, &$results) {
        Swoole\Coroutine::sleep(0.1);
        $results['task_a'] = 'done after 100ms';
        $wg->done();
    });

    $wg->add();
    Swoole\Coroutine::create(function () use ($wg, &$results) {
        Swoole\Coroutine::sleep(0.1);
        $results['task_b'] = 'done after 100ms';
        $wg->done();
    });

    $wg->wait();

    $elapsed = round((microtime(true) - $start) * 1000);

    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'results'    => $results,
        'elapsed_ms' => $elapsed,
        'note'       => 'Two 100ms tasks ran concurrently',
    ]));
}

function handleInfo(Swoole\Http\Response $response, Swoole\Http\Request $request): void
{
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'swoole_version' => swoole_version(),
        'php_version'    => PHP_VERSION,
        'worker_pid'     => getmypid(),
        'worker_id'      => $request->server['worker_id'] ?? null,
        'cpu_num'        => swoole_cpu_num(),
    ]));
}

function handleNotFound(Swoole\Http\Response $response, string $path): void
{
    $response->status(404);
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'error'  => 'Not found',
        'path'   => $path,
        'routes' => ['/hello', '/coroutine', '/info'],
    ]));
}

$server->start();