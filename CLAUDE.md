# PHP + Swoole + Laravel — Project Context

## Goal
Learn and benchmark Swoole as a coroutine-based async runtime for PHP, integrated with Laravel via Octane.
This is an exploratory/test project — the deliverable is understanding, benchmarks, and working code examples.

## Stack
- PHP 8.3
- Swoole extension (latest stable, installed via pecl)
- Laravel 11
- Laravel Octane (Swoole driver)
- MySQL 8 + Redis 7 (via Docker)
- k6 or wrk for benchmarking

## Project structure
```
.
├── CLAUDE.md           # this file
├── docker-compose.yml
├── app/                # Laravel app
├── swoole/             # raw Swoole experiments (outside Laravel)
│   ├── http_server.php
│   ├── websocket_server.php
│   └── coroutine_demo.php
└── benchmarks/         # k6/wrk scripts and results
```

## The plan (5 phases)

### Phase 1 — Environment + Swoole basics
- Install PHP 8.3, Swoole extension, verify `swoole_version()`
- Write a raw `Swoole\Http\Server` (no framework) in `swoole/http_server.php`
- Understand worker model: master → manager → N workers
- Test coroutines with `Coroutine::create()` and concurrent MySQL queries

### Phase 2 — Laravel Octane + Swoole driver
- Install `laravel/octane`, configure Swoole as the driver
- Understand the persistent-app lifecycle: app boots once, serves many requests
- Identify and fix memory leaks: static state, mutating singletons, container scope
- Run with `php artisan octane:start --server=swoole --workers=4`

### Phase 3 — WebSocket server
- Build a `Swoole\WebSocket\Server` on a second port (e.g. 6001)
- Implement live chat or real-time dashboard
- Use `Swoole\Table` (shared memory) as a cross-worker connection registry
- Integrate with Laravel Broadcasting for clean event handling

### Phase 4 — Coroutine-native DB + HTTP clients
- Replace PDO with `Swoole\Coroutine\MySQL` and coroutine Redis client
- Use `Coroutine\Http\Client` to fan out concurrent external API calls
- Add a connection pool and measure pool vs no-pool latency
- Implement background tasks via Swoole Process or Timer

### Phase 5 — Benchmark vs PHP-FPM
- Run the same Laravel app under PHP-FPM + nginx as a baseline
- Load test with k6 or wrk: RPS, p99 latency, memory over time
- Test failure modes: coroutine panic, OOM, zombie workers
- Document findings: when Swoole wins, when it doesn't

## Key concepts to keep in mind

### Persistent app lifecycle (critical)
Unlike FPM where every request boots a fresh PHP process, Octane + Swoole boots Laravel **once**.
This means:
- Static properties persist between requests — never use them for request state
- Service container bindings that mutate will bleed across requests
- Use `$request` injection, never `request()` helper stored in a property
- Octane provides a `RequestReceived` event to flush state — use it

### Coroutines
- Swoole coroutines are cooperative (not preemptive) — they yield on I/O, not on CPU
- `Coroutine::create(fn)` spawns a coroutine; `Co\run(fn)` creates a coroutine context
- Standard blocking calls (PDO, `file_get_contents`, `sleep`) block the entire worker — always use Swoole-native clients inside coroutines

### Worker model
- Master process: manages the server lifecycle
- Manager process: supervises workers
- Worker processes: each handles requests; coroutines run inside a worker
- `--workers=N` → N workers; a good starting point is CPU count × 2

### Swoole Table
- Shared memory structure accessible by all worker processes
- Fixed schema, fixed size — must be declared before the server starts
- Use for: WebSocket connection registry, simple counters, shared rate-limit state
- Not a replacement for Redis — small, fast, ephemeral

## Commands

```bash
# Start Octane dev server
php artisan octane:start --server=swoole --workers=4 --watch

# Run a raw Swoole script
php swoole/http_server.php

# Reload Octane workers without downtime
php artisan octane:reload

# Run benchmarks
k6 run benchmarks/load_test.js
wrk -t4 -c100 -d30s http://localhost:8000/api/endpoint
```

## Docker services
- `app` — PHP 8.3 + Swoole, Laravel
- `mysql` — MySQL 8, port 3306
- `redis` — Redis 7, port 6379
- `nginx` — for FPM baseline comparison only

## Architectural decisions
- Octane on port 8000, WebSocket server on port 6001
- Use coroutine-native clients (Swoole MySQL/Redis) in Phase 4, not before
- Keep raw Swoole experiments in `swoole/` directory, separate from the Laravel app
- Benchmark workload: one DB query + one Redis read per request (realistic, not hello-world)

## What to watch out for
- Memory leaks in Phase 2 are the main learning — don't skip debugging them
- Octane's `--watch` mode uses inotify; may need `inotify` PHP extension in Docker
- WebSocket + Octane on the same process is possible but complex — separate ports is cleaner
- Connection pools must be sized per-worker, not globally
