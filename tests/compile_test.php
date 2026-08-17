<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\JoinClause;
use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;
/* ====================================================================
 | 1. SQL compilation (no database needed)
 | ==================================================================== */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
NDB::configure(['driver' => 'mysql', 'database' => 'test'], 'mysql');

function sqliteQuery(string $table = 'users'): Builder
{
    return NDB::table($table);
}

function mysqlQuery(string $table = 'users'): Builder
{
    $connection = NDB::connection('mysql');
    return (new Builder($connection))->from($table);
}

section('compile: select');

check('basic select',
    sqliteQuery()->toSql(),
    'select * from "users"');

check('column list',
    sqliteQuery()->select('id', 'name')->toSql(),
    'select "id", "name" from "users"');

check('distinct + alias',
    sqliteQuery()->distinct()->select('u.name as author')->from('users', 'u')->toSql(),
    'select distinct "u"."name" as "author" from "users" as "u"');

check('mysql backticks',
    mysqlQuery()->select('id')->toSql(),
    'select `id` from `users`');

check('identifier with a backtick is escaped',
    mysqlQuery('we`ird')->toSql(),
    'select * from `we``ird`');

section('compile: where');

$q = sqliteQuery()->where('votes', '>', 100)->where('name', 'John');
check('where sql', $q->toSql(), 'select * from "users" where "votes" > ? and "name" = ?');
check('where bindings', $q->getBindings(), [100, 'John']);

$q = sqliteQuery()->where('a', 1)->orWhere('b', 2)->whereNot('c', 3);
check('or / not sql',
    $q->toSql(),
    'select * from "users" where "a" = ? or "b" = ? and not ("c" = ?)');

$q = sqliteQuery()->where(function (Builder $sub) {
    $sub->where('a', 1)->orWhere('b', 2);
})->where('c', 3);
check('nested closure sql',
    $q->toSql(),
    'select * from "users" where ("a" = ? or "b" = ?) and "c" = ?');
check('nested closure bindings', $q->getBindings(), [1, 2, 3]);

check('array of wheres',
    sqliteQuery()->where(['a' => 1, 'b' => 2])->toSql(),
    'select * from "users" where ("a" = ? and "b" = ?)');

check('list of wheres',
    sqliteQuery()->where([['votes', '>', 5], ['name', 'Jane']])->toSql(),
    'select * from "users" where ("votes" > ? and "name" = ?)');

check('where null shorthand',
    sqliteQuery()->where('deleted_at', null)->toSql(),
    'select * from "users" where "deleted_at" is null');

check('where not null shorthand',
    sqliteQuery()->where('deleted_at', '!=', null)->toSql(),
    'select * from "users" where "deleted_at" is not null');

$q = sqliteQuery()->whereIn('id', [1, 2, 3])->whereNotIn('role', ['guest']);
check('in / not in sql',
    $q->toSql(),
    'select * from "users" where "id" in (?, ?, ?) and "role" not in (?)');
check('in bindings', $q->getBindings(), [1, 2, 3, 'guest']);

check('empty in is always false',
    sqliteQuery()->whereIn('id', [])->toSql(),
    'select * from "users" where 0 = 1');

check('empty not-in is always true',
    sqliteQuery()->whereNotIn('id', [])->toSql(),
    'select * from "users" where 1 = 1');

$q = sqliteQuery()->whereBetween('age', [18, 30]);
check('between sql', $q->toSql(), 'select * from "users" where "age" between ? and ?');
check('between bindings', $q->getBindings(), [18, 30]);

check('whereColumn',
    sqliteQuery()->whereColumn('updated_at', '>', 'created_at')->toSql(),
    'select * from "users" where "updated_at" > "created_at"');

check('whereRaw',
    sqliteQuery()->whereRaw('length(name) > ?', [5])->toSql(),
    'select * from "users" where length(name) > ?');

check('whereLike',
    sqliteQuery()->whereLike('name', '%jo%')->toSql(),
    'select * from "users" where "name" like ?');

check('sqlite date where',
    sqliteQuery()->whereYear('created_at', 2024)->toSql(),
    'select * from "users" where strftime(\'%Y\', "created_at") = cast(? as text)');

check('mysql date where',
    mysqlQuery()->whereMonth('created_at', 3)->toSql(),
    'select * from `users` where month(`created_at`) = ?');

check('mysql month value is padded',
    mysqlQuery()->whereMonth('created_at', 3)->getBindings(),
    ['03']);

section('compile: sub queries');

$sub = sqliteQuery('orders')->selectRaw('count(*)')->whereColumn('orders.user_id', 'users.id');

$q = sqliteQuery()->select('name')->selectSub($sub, 'orders_count');
check('select sub',
    $q->toSql(),
    'select "name", (select count(*) from "orders" where "orders"."user_id" = "users"."id") as "orders_count" from "users"');

$q = sqliteQuery()->whereExists(function (Builder $sub) {
    $sub->from('orders')->whereColumn('orders.user_id', 'users.id')->where('total', '>', 100);
});
check('where exists',
    $q->toSql(),
    'select * from "users" where exists (select * from "orders" where "orders"."user_id" = "users"."id" and "total" > ?)');
check('where exists bindings', $q->getBindings(), [100]);

$q = sqliteQuery()->whereIn('id', function (Builder $sub) {
    $sub->from('orders')->select('user_id')->where('total', '>', 50);
});
check('where in sub',
    $q->toSql(),
    'select * from "users" where "id" in (select "user_id" from "orders" where "total" > ?)');

section('compile: joins');

check('simple join',
    sqliteQuery()->join('posts', 'users.id', '=', 'posts.user_id')->toSql(),
    'select * from "users" inner join "posts" on "users"."id" = "posts"."user_id"');

check('join with implied operator',
    sqliteQuery()->join('posts', 'users.id', 'posts.user_id')->toSql(),
    'select * from "users" inner join "posts" on "users"."id" = "posts"."user_id"');

check('left join',
    sqliteQuery()->leftJoin('posts', 'users.id', '=', 'posts.user_id')->toSql(),
    'select * from "users" left join "posts" on "users"."id" = "posts"."user_id"');

check('cross join',
    sqliteQuery()->crossJoin('colors')->toSql(),
    'select * from "users" cross join "colors"');

$q = sqliteQuery()->join('posts', function (JoinClause $join) {
    $join->on('users.id', '=', 'posts.user_id')->where('posts.published', 1);
})->where('users.active', 1);
check('join closure sql',
    $q->toSql(),
    'select * from "users" inner join "posts" on "users"."id" = "posts"."user_id" and "posts"."published" = ? where "users"."active" = ?');
check('join bindings come before where bindings', $q->getBindings(), [1, 1]);

section('compile: group / order / limit');

$q = sqliteQuery()->select('role')->selectRaw('count(*) as total')
    ->groupBy('role')->having('total', '>', 2)->orderByDesc('total')->limit(5)->offset(10);
check('group + having + order + limit',
    $q->toSql(),
    'select "role", count(*) as total from "users" group by "role" having "total" > ? order by "total" desc limit 5 offset 10');
check('having bindings', $q->getBindings(), [2]);

check('offset without limit (sqlite)',
    sqliteQuery()->offset(10)->toSql(),
    'select * from "users" limit -1 offset 10');

check('offset without limit (mysql)',
    mysqlQuery()->offset(10)->toSql(),
    'select * from `users` limit 18446744073709551615 offset 10');

check('forPage',
    sqliteQuery()->forPage(3, 20)->toSql(),
    'select * from "users" limit 20 offset 40');

check('inRandomOrder sqlite',
    sqliteQuery()->inRandomOrder()->toSql(),
    'select * from "users" order by RANDOM()');

check('inRandomOrder mysql',
    mysqlQuery()->inRandomOrder()->toSql(),
    'select * from `users` order by RAND()');

check('mysql lock',
    mysqlQuery()->where('id', 1)->lockForUpdate()->toSql(),
    'select * from `users` where `id` = ? for update');

section('compile: unions');

$second = sqliteQuery('admins')->select('name');
check('sqlite union',
    sqliteQuery()->select('name')->union($second)->toSql(),
    'select * from (select "name" from "users") union select * from (select "name" from "admins")');

$second = mysqlQuery('admins')->select('name');
check('mysql union',
    mysqlQuery()->select('name')->union($second)->toSql(),
    '(select `name` from `users`) union (select `name` from `admins`)');

section('compile: conditionals');

check('when true',
    sqliteQuery()->when(true, fn (Builder $q) => $q->where('a', 1))->toSql(),
    'select * from "users" where "a" = ?');

check('when false',
    sqliteQuery()->when(false, fn (Builder $q) => $q->where('a', 1))->toSql(),
    'select * from "users"');

check('unless',
    sqliteQuery()->unless(false, fn (Builder $q) => $q->where('a', 1))->toSql(),
    'select * from "users" where "a" = ?');

section('compile: write statements');

$grammar = NDB::connection()->getGrammar();

check('compileInsert',
    $grammar->compileInsert(sqliteQuery(), [['name' => 'A', 'email' => 'a@x.com']]),
    'insert into "users" ("name", "email") values (?, ?)');

check('compileInsert multi row',
    $grammar->compileInsert(sqliteQuery(), [['name' => 'A'], ['name' => 'B']]),
    'insert into "users" ("name") values (?), (?)');

check('compileUpdate',
    $grammar->compileUpdate(sqliteQuery()->where('id', 1), ['name' => 'B']),
    'update "users" set "name" = ? where "id" = ?');

check('compileDelete',
    $grammar->compileDelete(sqliteQuery()->where('id', 1)),
    'delete from "users" where "id" = ?');

$mysqlGrammar = NDB::connection('mysql')->getGrammar();

check('mysql update with join',
    $mysqlGrammar->compileUpdate(
        mysqlQuery()->join('posts', 'users.id', '=', 'posts.user_id')->where('posts.spam', 1),
        ['users.active' => 0]
    ),
    'update `users` inner join `posts` on `users`.`id` = `posts`.`user_id` set `users`.`active` = ? where `posts`.`spam` = ?');

check('mysql delete with join',
    $mysqlGrammar->compileDelete(
        mysqlQuery()->join('posts', 'users.id', '=', 'posts.user_id')->where('posts.spam', 1)
    ),
    'delete `users` from `users` inner join `posts` on `users`.`id` = `posts`.`user_id` where `posts`.`spam` = ?');

$threw = false;
try {
    $grammar->compileDelete(sqliteQuery()->where('a', 1)->limit(3));
} catch (RuntimeException $e) {
    $threw = true;
}
check('sqlite delete with limit throws instead of silently ignoring', $threw, true);

section('toRawSql');

check('interpolated debug sql',
    sqliteQuery()->where('name', "O'Brien")->where('age', 30)->toRawSql(),
    'select * from "users" where "name" = \'O\'\'Brien\' and "age" = 30');

/* ====================================================================
 | 2. Against a real SQLite database
 | ==================================================================== */

section('runtime: schema + insert');

$connection = NDB::connection();
$connection->enableQueryLog();

NDB::unprepared('
    create table users (
        id integer primary key autoincrement,
        name text not null,
        email text unique,
        role text not null default "user",
        votes integer not null default 0,
        created_at text,
        deleted_at text
    )
');
NDB::unprepared('
    create table posts (
        id integer primary key autoincrement,
        user_id integer not null,
        title text not null,
        published integer not null default 0
    )
');

check('insert single', NDB::table('users')->insert([
    'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'votes' => 10, 'created_at' => '2024-03-05 10:00:00',
]), true);

check('insert multiple actually inserts every row',
    NDB::table('users')->insert([
        ['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user', 'votes' => 5, 'created_at' => '2024-03-06 11:00:00'],
        ['name' => 'Carol', 'email' => 'carol@example.com', 'role' => 'user', 'votes' => 20, 'created_at' => '2025-01-10 09:00:00'],
        ['name' => 'Dave', 'email' => 'dave@example.com', 'role' => 'user', 'votes' => 0, 'created_at' => '2025-02-11 09:00:00'],
    ]),
    true);

check('row count after inserts', NDB::table('users')->count(), 4);

$id = NDB::table('users')->insertGetId([
    'name' => 'Eve', 'email' => 'eve@example.com', 'role' => 'user', 'votes' => 1,
]);
check('insertGetId returns an int id', $id, 5);

check('insertOrIgnore skips the duplicate email',
    NDB::table('users')->insertOrIgnore([['name' => 'Eve2', 'email' => 'eve@example.com']]),
    0);

NDB::table('posts')->insert([
    ['user_id' => 1, 'title' => 'Hello', 'published' => 1],
    ['user_id' => 1, 'title' => 'Draft', 'published' => 0],
    ['user_id' => 2, 'title' => 'Bob post', 'published' => 1],
]);

section('runtime: reading');

check('get returns rows', count(NDB::table('users')->get()), 5);

check('first', NDB::table('users')->where('name', 'Alice')->first()['email'], 'alice@example.com');

check('first on no match is null', NDB::table('users')->where('name', 'Nobody')->first(), null);

check('find', NDB::table('users')->find(2)['name'], 'Bob');

check('value', NDB::table('users')->where('id', 3)->value('name'), 'Carol');

check('pluck', NDB::table('users')->orderBy('id')->pluck('name'),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Eve']);

check('pluck keyed', NDB::table('users')->where('id', '<', 3)->orderBy('id')->pluck('name', 'id'),
    [1 => 'Alice', 2 => 'Bob']);

check('exists', NDB::table('users')->where('role', 'admin')->exists(), true);
check('doesntExist', NDB::table('users')->where('role', 'ghost')->doesntExist(), true);

check('count with where', NDB::table('users')->where('role', 'user')->count(), 4);
check('max', (int) NDB::table('users')->max('votes'), 20);
check('min', (int) NDB::table('users')->min('votes'), 0);
check('sum', (int) NDB::table('users')->sum('votes'), 36);
check('avg', round((float) NDB::table('users')->avg('votes'), 1), 7.2);

check('count is not broken by an order by',
    NDB::table('users')->orderBy('name')->count(),
    5);

check('distinct count',
    NDB::table('users')->distinct()->count('role'),
    2);

section('runtime: where variants');

check('whereIn', count(NDB::table('users')->whereIn('id', [1, 3, 99])->get()), 2);
check('whereBetween', count(NDB::table('users')->whereBetween('votes', [1, 10])->get()), 3);
check('whereNull', count(NDB::table('users')->whereNull('deleted_at')->get()), 5);
check('whereNotNull', count(NDB::table('users')->whereNotNull('created_at')->get()), 4);
check('whereLike', count(NDB::table('users')->whereLike('email', '%example.com')->get()), 5);
check('whereYear', count(NDB::table('users')->whereYear('created_at', 2025)->get()), 2);
check('whereMonth', count(NDB::table('users')->whereMonth('created_at', 3)->get()), 2);
check('whereDate', count(NDB::table('users')->whereDate('created_at', '2024-03-05')->get()), 1);

check('nested or group',
    NDB::table('users')->where('role', 'user')->where(function (Builder $q) {
        $q->where('votes', '>', 10)->orWhere('name', 'Bob');
    })->orderBy('id')->pluck('name'),
    ['Bob', 'Carol']);

check('whereExists at runtime',
    NDB::table('users')->whereExists(function (Builder $q) {
        $q->from('posts')->whereColumn('posts.user_id', 'users.id')->where('published', 1);
    })->orderBy('id')->pluck('name'),
    ['Alice', 'Bob']);

section('runtime: joins & grouping');

check('join returns joined columns',
    NDB::table('users')
        ->join('posts', 'users.id', '=', 'posts.user_id')
        ->where('posts.published', 1)
        ->orderBy('posts.id')
        ->select('users.name', 'posts.title')
        ->get(),
    [
        ['name' => 'Alice', 'title' => 'Hello'],
        ['name' => 'Bob', 'title' => 'Bob post'],
    ]);

check('left join keeps unmatched rows',
    count(NDB::table('users')->leftJoin('posts', 'users.id', '=', 'posts.user_id')->get()),
    6);

check('group by + having',
    NDB::table('posts')
        ->select('user_id')
        ->selectRaw('count(*) as total')
        ->groupBy('user_id')
        ->having('total', '>', 1)
        ->get(),
    [['user_id' => 1, 'total' => 2]]);

section('runtime: ordering & pagination');

check('orderByDesc', NDB::table('users')->orderByDesc('votes')->limit(2)->pluck('name'), ['Carol', 'Alice']);

$page = NDB::table('users')->orderBy('id')->paginate(2, 2);
check('paginate data', array_column($page['data'], 'name'), ['Carol', 'Dave']);
check('paginate total', $page['total'], 5);
check('paginate last_page', $page['last_page'], 3);
check('paginate from/to', [$page['from'], $page['to']], [3, 4]);

$chunks = [];
NDB::table('users')->orderBy('id')->chunk(2, function (array $rows) use (&$chunks) {
    $chunks[] = count($rows);
});
check('chunk sizes', $chunks, [2, 2, 1]);

$seen = 0;
NDB::table('users')->orderBy('id')->each(function () use (&$seen) {
    $seen++;
    return $seen < 3;
}, 2);
check('each stops when the callback returns false', $seen, 3);

$streamed = 0;
foreach (NDB::table('users')->cursor() as $row) {
    $streamed++;
}
check('cursor streams every row', $streamed, 5);

section('runtime: writing');

check('update returns the affected row count',
    NDB::table('users')->where('role', 'user')->update(['votes' => 7]),
    4);

check('update actually wrote', (int) NDB::table('users')->where('id', 2)->value('votes'), 7);

check('update with an expression',
    NDB::table('users')->where('id', 2)->update(['votes' => NDB::raw('votes + 3')]),
    1);
check('expression update result', (int) NDB::table('users')->where('id', 2)->value('votes'), 10);

check('increment', NDB::table('users')->where('id', 2)->increment('votes', 5), 1);
check('increment result', (int) NDB::table('users')->where('id', 2)->value('votes'), 15);

check('decrement result',
    (int) NDB::table('users')->where('id', 2)->tap(fn (Builder $q) => $q->decrement('votes', 5))->value('votes'),
    10);

check('updateOrInsert updates the existing row',
    NDB::table('users')->updateOrInsert(['email' => 'alice@example.com'], ['votes' => 99]),
    true);
check('updateOrInsert value', (int) NDB::table('users')->where('id', 1)->value('votes'), 99);

check('updateOrInsert inserts a missing row',
    NDB::table('users')->updateOrInsert(['email' => 'frank@example.com'], ['name' => 'Frank']),
    true);
check('row count after updateOrInsert', NDB::table('users')->count(), 6);

check('upsert',
    NDB::table('users')->upsert(
        [['name' => 'Alice Updated', 'email' => 'alice@example.com', 'votes' => 42]],
        ['email'],
        ['name', 'votes']
    ) > 0,
    true);
check('upsert updated the existing row', NDB::table('users')->where('id', 1)->value('name'), 'Alice Updated');

check('delete returns the affected row count',
    NDB::table('users')->where('email', 'frank@example.com')->delete(),
    1);

check('delete by id', NDB::table('users')->delete(5), 1);
check('row count after deletes', NDB::table('users')->count(), 4);

section('runtime: transactions');

NDB::transaction(function () {
    NDB::table('users')->insert(['name' => 'Temp', 'email' => 'temp@example.com']);
});
check('committed transaction persists', NDB::table('users')->where('name', 'Temp')->exists(), true);

try {
    NDB::transaction(function () {
        NDB::table('users')->where('name', 'Temp')->delete();
        throw new RuntimeException('boom');
    });
} catch (RuntimeException $e) {
    // expected
}
check('failed transaction is rolled back', NDB::table('users')->where('name', 'Temp')->exists(), true);

NDB::transaction(function () {
    NDB::table('users')->insert(['name' => 'Outer', 'email' => 'outer@example.com']);

    try {
        NDB::transaction(function () {
            NDB::table('users')->insert(['name' => 'Inner', 'email' => 'inner@example.com']);
            throw new RuntimeException('inner boom');
        });
    } catch (RuntimeException $e) {
        // expected
    }
});
check('savepoint rollback keeps the outer insert', NDB::table('users')->where('name', 'Outer')->exists(), true);
check('savepoint rollback discards the inner insert', NDB::table('users')->where('name', 'Inner')->exists(), false);
check('transaction level is back to zero', NDB::connection()->transactionLevel(), 0);

section('runtime: unions & truncate');

check('union at runtime',
    count(NDB::table('users')->select('name')->where('role', 'admin')
        ->union(NDB::table('users')->select('name')->where('votes', '>', 5))->get()) > 0,
    true);

NDB::table('posts')->truncate();
check('truncate empties the table', NDB::table('posts')->count(), 0);

section('runtime: errors & injection');

$threw = false;
try {
    NDB::table('users')->where('id', 1)->update(['nope' => 1]);
} catch (QueryException $e) {
    $threw = strpos($e->getSql(), 'update "users"') === 0;
}
check('a bad statement throws QueryException instead of killing the script', $threw, true);

$evil = "1' OR '1'='1";
check('injection attempt in a value matches nothing',
    NDB::table('users')->where('name', $evil)->count(),
    0);

check('injection attempt survives as a literal on insert',
    (function () use ($evil) {
        NDB::table('users')->insert(['name' => $evil, 'email' => 'evil@example.com']);
        return NDB::table('users')->where('email', 'evil@example.com')->value('name');
    })(),
    $evil);

section('table name inference');

check('log recorded queries', count(NDB::getQueryLog()) > 50, true);

echo "\n";
echo "passed: {$pass}, failed: {$fail}\n";

exit($fail === 0 ? 0 : 1);
