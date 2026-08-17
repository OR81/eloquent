<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Connection;
use Or81\Eloquent\NDB;
use Or81\Eloquent\Row;

/**
 * Reading a large result set without holding it all at once.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table events (id integer primary key autoincrement, label text, payload text)');

NDB::connection()->transaction(function () {
    for ($batch = 0; $batch < 40; $batch++) {
        $rows = [];

        for ($i = 0; $i < 500; $i++) {
            $n = $batch * 500 + $i;
            $rows[] = ['label' => 'event-' . $n, 'payload' => str_repeat('x', 200)];
        }

        NDB::table('events')->insert($rows);
    }
});

check('the fixture is big enough to notice', NDB::table('events')->count(), 20000);

section('cursor holds one row at a time');

$peak = memory_get_usage();
$seen = 0;

foreach (NDB::table('events')->cursor() as $row) {
    $seen++;
    $peak = max($peak, memory_get_usage());
}

$streamed = $peak - memory_get_usage();

check('every row was visited', $seen, 20000);

$before = memory_get_usage();
$all = NDB::table('events')->get();
$buffered = memory_get_usage() - $before;
unset($all);

check('get() costs far more than cursor()', $buffered > $streamed * 10, true);

section('pluck streams too');

$before = memory_get_usage();
$labels = NDB::table('events')->pluck('label');
$plucked = memory_get_usage() - $before;

check('pluck returns every value', count($labels), 20000);
check('the values are right', [$labels[0], $labels[19999]], ['event-0', 'event-19999']);
check('and it costs less than fetching the rows', $plucked < $buffered, true);

check('pluck keyed by another column',
    count(NDB::table('events')->pluck('label', 'id')), 20000);
check('the keys came through',
    NDB::table('events')->limit(3)->pluck('label', 'id'),
    [1 => 'event-0', 2 => 'event-1', 3 => 'event-2']);

check('pluck respects a where',
    NDB::table('events')->where('label', 'event-7')->pluck('label'), ['event-7']);

check('pluck respects an order',
    NDB::table('events')->orderByDesc('id')->limit(2)->pluck('label'),
    ['event-19999', 'event-19998']);

check('pluck does not disturb the query it was called on',
    (function () {
        $query = NDB::table('events')->where('label', 'event-1');
        $query->pluck('label');

        return $query->toSql();
    })(),
    'select * from "events" where "label" = ?');

check('pluck keeps an existing select',
    NDB::table('events')->selectRaw("'literal' as label")->limit(1)->pluck('label'),
    ['literal']);

section('the buffering switch');

check('sqlite needs no switch, and streams anyway',
    (function () {
        $seen = 0;

        foreach (NDB::table('events')->limit(10)->cursor() as $row) {
            $seen++;
        }

        return $seen;
    })(),
    10);

check('another query can still run right after a cursor',
    (function () {
        foreach (NDB::table('events')->limit(1)->cursor() as $row) {
            // consumed
        }

        return NDB::table('events')->count();
    })(),
    20000);

check('breaking out of a cursor leaves the connection usable',
    (function () {
        foreach (NDB::table('events')->cursor() as $row) {
            break;
        }

        return NDB::table('events')->where('label', 'event-3')->count();
    })(),
    1);

check('an exception thrown inside a cursor does not strand it',
    (function () {
        try {
            foreach (NDB::table('events')->cursor() as $row) {
                throw new RuntimeException('stop');
            }
        } catch (RuntimeException $e) {
            // expected
        }

        return NDB::table('events')->count();
    })(),
    20000);

// The MySQL path cannot be exercised without a server, so check the decision
// itself: only MySQL is told to stop buffering.
$decide = function (Connection $connection): bool {
    $method = new ReflectionMethod($connection, 'unbufferedFetching');
    $method->setAccessible(true);

    return $method->invoke($connection);
};

check('sqlite is left alone', $decide(NDB::connection()), false);
check('mysql is switched, when the driver is loaded',
    $decide(new Connection(['driver' => 'mysql', 'database' => 'test'])),
    defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'));

section('cursor still yields Rows and models');

check('a Row', (function () {
    foreach (NDB::table('events')->limit(1)->cursor() as $row) {
        return $row instanceof Row && $row->label === 'event-0';
    }
})(), true);

check('casts still apply while streaming',
    (function () {
        NDB::unprepared('create table stamps (id integer primary key, at text)');
        NDB::table('stamps')->insert(['id' => 1, 'at' => '2024-08-16 14:30:00']);

        foreach (NDB::table('stamps')->castJalali('at', 'Y/m/d')->cursor() as $row) {
            return $row->at;
        }
    })(),
    '1403/05/26');

summary();
