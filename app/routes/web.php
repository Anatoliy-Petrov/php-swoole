<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Benchmark workload: one DB query + one Redis read per request
Route::get('/ping', function () {
    $db = DB::select('SELECT 1 as alive');

    $cached = Cache::remember('ping:counter', 60, fn () => 0);
    Cache::increment('ping:counter');

    return response()->json([
        'db'      => $db[0]->alive === 1,
        'cache'   => $cached,
        'worker'  => getmypid(),
    ]);
});
