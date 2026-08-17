<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\JoinClause;
use Or81\Eloquent\NDB;
NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table users (id integer primary key autoincrement, name text, email text unique,
    role text default "user", votes integer default 0, balance integer default 0,
    created_at text, deleted_at text, updated_at text)');
NDB::unprepared('create table posts (id integer primary key autoincrement, user_id integer, title text, published integer default 0)');
NDB::unprepared('create table colors (name text)');

NDB::table('users')->insert([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'votes' => 150, 'balance' => 500, 'created_at' => '2024-03-05 09:30:00'],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user', 'votes' => 60, 'balance' => 300, 'created_at' => '2024-04-06 11:00:00'],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'role' => 'user', 'votes' => 10, 'balance' => 50, 'created_at' => '2025-01-10 08:00:00'],
]);
NDB::table('posts')->insert([
    ['user_id' => 1, 'title' => 'Hello', 'published' => 1],
    ['user_id' => 1, 'title' => 'Draft', 'published' => 0],
    ['user_id' => 2, 'title' => 'Bob post', 'published' => 1],
]);
NDB::table('colors')->insert([['name' => 'red'], ['name' => 'blue']]);

echo "== README: selecting ==\n";

check('table with alias + aliased column',
    NDB::table('users', 'u')->select('u.name as author')->orderBy('u.id')->limit(1)->get(),
    [['author' => 'Alice']]);

check('selectRaw multiple aggregates',
    NDB::table('users')->selectRaw('count(*) as total, max(votes) as top')->first(),
    ['total' => 3, 'top' => 150]);

check('selectSub correlated count',
    NDB::table('users')->select('name')->selectSub(
        NDB::table('orders')->from('posts')->selectRaw('count(*)')->whereColumn('posts.user_id', 'users.id'),
        'posts_count'
    )->orderBy('users.id')->get(),
    [
        ['name' => 'Alice', 'posts_count' => 2],
        ['name' => 'Bob', 'posts_count' => 1],
        ['name' => 'Carol', 'posts_count' => 0],
    ]);

check('fromSub',
    NDB::table('x')->fromSub(NDB::table('users')->where('role', 'user'), 'plain')->count(),
    2);

check('select with a raw expression',
    NDB::table('users')->select(NDB::raw('count(*) as total'))->first(),
    ['total' => 3]);

echo "\n== README: where sub queries ==\n";

check('where with a sub query on the right side',
    NDB::table('users')->where('votes', '>', function (Builder $query) {
        $query->from('users')->selectRaw('avg(votes)');
    })->pluck('name'),
    ['Alice']);

check('whereIn with a closure',
    NDB::table('users')->whereIn('id', function (Builder $query) {
        $query->from('posts')->select('user_id')->where('published', 1);
    })->orderBy('id')->pluck('name'),
    ['Alice', 'Bob']);

echo "\n== README: joins ==\n";

check('crossJoin row count',
    NDB::table('users')->crossJoin('colors')->count(),
    6);

check('rightJoin compiles',
    NDB::table('users')->rightJoin('posts', 'users.id', '=', 'posts.user_id')->toSql(),
    'select * from "users" right join "posts" on "users"."id" = "posts"."user_id"');

check('join closure with an extra condition',
    NDB::table('users')->join('posts', function (JoinClause $join) {
        $join->on('users.id', '=', 'posts.user_id')->where('posts.published', 1);
    })->orderBy('posts.id')->pluck('title'),
    ['Hello', 'Bob post']);

check('joinSub',
    NDB::table('users')->joinSub(
        NDB::table('posts')->select('user_id')->selectRaw('count(*) as total')->groupBy('user_id'),
        'stats',
        'users.id',
        '=',
        'stats.user_id'
    )->orderBy('users.id')->select('users.name', 'stats.total')->get(),
    [
        ['name' => 'Alice', 'total' => 2],
        ['name' => 'Bob', 'total' => 1],
    ]);

echo "\n== README: grouping & ordering ==\n";

check('groupByRaw',
    NDB::table('users')->selectRaw('count(*) as total')->groupByRaw("strftime('%Y', created_at)")
        ->orderByRaw("strftime('%Y', created_at)")->pluck('total'),
    [2, 1]);

check('havingBetween',
    NDB::table('posts')->select('user_id')->selectRaw('count(*) as total')
        ->groupBy('user_id')->havingBetween('total', [2, 5])->get(),
    [['user_id' => 1, 'total' => 2]]);

check('havingRaw',
    NDB::table('posts')->select('user_id')->groupBy('user_id')->havingRaw('count(*) > ?', [1])->pluck('user_id'),
    [1]);

check('latest / oldest',
    [NDB::table('users')->latest()->value('name'), NDB::table('users')->oldest()->value('name')],
    ['Carol', 'Alice']);

check('reorder drops the previous order',
    NDB::table('users')->orderByDesc('votes')->reorder()->orderBy('name')->pluck('name'),
    ['Alice', 'Bob', 'Carol']);

check('reorder with a new column',
    NDB::table('users')->orderByDesc('votes')->reorder('name', 'desc')->pluck('name'),
    ['Carol', 'Bob', 'Alice']);

check('take / skip aliases',
    NDB::table('users')->orderBy('id')->skip(1)->take(1)->pluck('name'),
    ['Bob']);

echo "\n== README: aggregates ==\n";

check('count of a nullable column',
    NDB::table('users')->count('deleted_at'),
    0);

echo "\n== README: conditionals ==\n";

$role = 'admin';
check('when receives the value',
    NDB::table('users')->when($role, fn (Builder $q, $value) => $q->where('role', $value))->pluck('name'),
    ['Alice']);

$includeTrashed = false;
check('unless',
    NDB::table('users')->unless($includeTrashed, fn (Builder $q) => $q->whereNull('deleted_at'))->count(),
    3);

echo "\n== README: writing ==\n";

check('increment with extra columns',
    NDB::table('users')->where('id', 1)->increment('votes', 5, ['updated_at' => '2025-06-01']),
    1);
check('increment applied',
    NDB::table('users')->where('id', 1)->first(['votes', 'updated_at']),
    ['votes' => 155, 'updated_at' => '2025-06-01']);

check('decrement',
    (function () {
        NDB::table('users')->where('id', 1)->decrement('balance', 100);
        return (int) NDB::table('users')->where('id', 1)->value('balance');
    })(),
    400);

check('firstOrFail returns the row',
    NDB::table('users')->where('id', 1)->firstOrFail()['name'],
    'Alice');

$threw = false;
try {
    NDB::table('users')->where('id', 999)->firstOrFail();
} catch (RuntimeException $e) {
    $threw = true;
}
check('firstOrFail throws when nothing matches', $threw, true);

check('paginate with named arguments',
    NDB::table('users')->orderBy('id')->paginate(perPage: 2, page: 2)['data'][0]['name'],
    'Carol');

echo "\n== README: transactions ==\n";

NDB::transaction(function () {
    NDB::table('users')->where('id', 1)->decrement('balance', 100);
    NDB::table('users')->where('id', 2)->increment('balance', 100);
});
check('transfer committed',
    [(int) NDB::table('users')->where('id', 1)->value('balance'), (int) NDB::table('users')->where('id', 2)->value('balance')],
    [300, 400]);

check('transaction with retries returns the callback value',
    NDB::transaction(fn () => 'done', 3),
    'done');

echo "\n== README: raw statements & debugging ==\n";

check('NDB::select', count(NDB::select('select * from users where votes > ?', [100])), 1);
check('NDB::selectOne', NDB::selectOne('select name from users where id = ?', [2]), ['name' => 'Bob']);
check('NDB::update', NDB::update('update users set votes = ? where id = ?', [11, 3]), 1);
check('NDB::delete', NDB::delete('delete from users where id = ?', [99]), 0);
check('NDB::statement', NDB::statement('alter table users add column age integer'), true);

NDB::flushQueryLog();
NDB::enableQueryLog();
NDB::table('users')->get();
$log = NDB::getQueryLog();
check('query log shape',
    [count($log), $log[0]['query'], $log[0]['bindings'], is_float($log[0]['time'])],
    [1, 'select * from "users"', [], true]);

summary();
