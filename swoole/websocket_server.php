<?php

// WebSocket server on port 6001
// Demonstrates: Swoole\Table for cross-worker connection registry,
// inter-worker messaging for true broadcast across all workers

// --- Connection registry (shared memory, readable by all workers) ---
$table = new Swoole\Table(1024);
$table->column('fd',        Swoole\Table::TYPE_INT);
$table->column('worker_id', Swoole\Table::TYPE_INT);
$table->column('username',  Swoole\Table::TYPE_STRING, 64);
$table->column('joined_at', Swoole\Table::TYPE_INT);
$table->create();

// --- Server ---
$server = new Swoole\WebSocket\Server('0.0.0.0', 6001);

$server->set([
    'worker_num' => 2,
    'log_level'  => SWOOLE_LOG_INFO,
]);

$server->table = $table;

$server->on('start', function () {
    echo "WebSocket server started on ws://0.0.0.0:6001\n";
});

$server->on('workerStart', function (Swoole\WebSocket\Server $server, int $workerId) {
    echo "Worker #{$workerId} started\n";
});

$server->on('open', function (Swoole\WebSocket\Server $server, Swoole\Http\Request $request) {
    $fd       = $request->fd;
    $username = $request->get['username'] ?? "guest_{$fd}";

    $server->table->set((string) $fd, [
        'fd'        => $fd,
        'worker_id' => $server->worker_id,
        'username'  => $username,
        'joined_at' => time(),
    ]);

    echo "Worker #{$server->worker_id}: client #{$fd} connected as {$username}\n";

    $server->push($fd, json_encode([
        'type'   => 'welcome',
        'user'   => $username,
        'online' => $server->table->count(),
    ]));

    broadcastAll($server, [
        'type'   => 'join',
        'user'   => $username,
        'online' => $server->table->count(),
    ], except: $fd);
});

$server->on('message', function (Swoole\WebSocket\Server $server, Swoole\WebSocket\Frame $frame) {
    $fd   = $frame->fd;
    $row  = $server->table->get((string) $fd);
    $user = $row ? $row['username'] : "guest_{$fd}";

    $data = json_decode($frame->data, true);

    if (!$data || !isset($data['text'])) {
        $server->push($fd, json_encode(['type' => 'error', 'message' => 'Expected {"text":"..."}']));
        return;
    }

    echo "Worker #{$server->worker_id}: message from {$user}: {$data['text']}\n";

    broadcastAll($server, [
        'type' => 'message',
        'user' => $user,
        'text' => $data['text'],
        'ts'   => time(),
    ]);
});

$server->on('close', function (Swoole\WebSocket\Server $server, int $fd) {
    $row      = $server->table->get((string) $fd);
    $username = $row ? $row['username'] : "guest_{$fd}";

    $server->table->del((string) $fd);

    echo "Worker #{$server->worker_id}: client #{$fd} ({$username}) disconnected\n";

    broadcastAll($server, [
        'type'   => 'leave',
        'user'   => $username,
        'online' => $server->table->count(),
    ]);
});

// Receive a relayed payload from another worker and push to local connections
$server->on('pipeMessage', function (Swoole\WebSocket\Server $server, int $srcWorkerId, string $data) {
    $payload = json_decode($data, true);
    $except  = $payload['__except'] ?? -1;
    unset($payload['__except']);

    broadcastLocal($server, $payload, $except);
});

// Push payload to connections owned by this worker, then relay to all other workers
function broadcastAll(Swoole\WebSocket\Server $server, array $payload, int $except = -1): void
{
    broadcastLocal($server, $payload, $except);

    $relay = json_encode($payload + ['__except' => $except]);
    for ($i = 0; $i < $server->setting['worker_num']; $i++) {
        if ($i !== $server->worker_id) {
            $server->sendMessage($relay, $i);
        }
    }
}

// Push payload only to connections on the current worker
function broadcastLocal(Swoole\WebSocket\Server $server, array $payload, int $except = -1): void
{
    $json = json_encode($payload);
    foreach ($server->table as $row) {
        $fd = $row['fd'];
        if ($fd === $except) {
            continue;
        }
        if ($row['worker_id'] === $server->worker_id && $server->isEstablished($fd)) {
            $server->push($fd, $json);
        }
    }
}

$server->start();