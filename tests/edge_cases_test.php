<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\NDB;
NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

echo "== operators ==\n";

check('whereNot as the first clause keeps the NOT',
    NDB::table('users')->whereNot('role', 'guest')->toSql(),
    'select * from "users" where not ("role" = ?)');

check('orWhereNot as the first clause keeps the NOT',
    NDB::table('users')->orWhereNot('role', 'guest')->toSql(),
    'select * from "users" where not ("role" = ?)');

check('operators are case insensitive',
    NDB::table('users')->where('name', 'LIKE', '%a%')->toSql(),
    'select * from "users" where "name" LIKE ?');

$message = null;
try {
    NDB::table('users')->where('age', '=>', 5);
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
}
check('a typo\'d operator throws instead of building broken SQL',
    $message,
    'Unsupported operator [=>]. Use whereRaw() for anything the builder does not know.');

check('two args still treat the second as a value',
    NDB::table('users')->where('name', 'anything at all')->toSql(),
    'select * from "users" where "name" = ?');

$threw = false;
try {
    NDB::table('users')->where('votes', '>', null);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a null value with a relational operator throws', $threw, true);

echo "\n== state isolation ==\n";

$base = NDB::table('users')->where('active', 1);

check('pluck does not mutate the builder',
    (function () use ($base) {
        $before = $base->toSql();
        $base->getConnection();
        return $before;
    })(),
    'select * from "users" where "active" = ?');

check('reusing a builder after toSql is stable',
    [$base->toSql(), $base->toSql()],
    ['select * from "users" where "active" = ?', 'select * from "users" where "active" = ?']);

$q = NDB::table('users')->select('id', 'name')->where('a', 1);
$sqlBefore = $q->toSql();
$grammar = NDB::connection()->getGrammar();
$grammar->compileUpdate($q, ['name' => 'x']);
check('compiling an update leaves the select intact', $q->toSql(), $sqlBefore);

echo "\n== bindings order ==\n";

$q = NDB::table('users')
    ->selectSub(NDB::table('posts')->selectRaw('count(*)')->where('published', 'S'), 'c')
    ->join('posts', function ($join) {
        $join->on('users.id', '=', 'posts.user_id')->where('posts.flag', 'J');
    })
    ->where('users.name', 'W')
    ->groupBy('users.id')
    ->having('c', '>', 'H')
    ->orderByRaw('length(name) + ?', ['O']);

check('bindings follow the SQL left to right',
    $q->getBindings(),
    ['S', 'J', 'W', 'H', 'O']);

check('update binding order is join, set, where',
    $grammar->prepareBindingsForUpdate(
        NDB::table('users')->where('id', 'W'),
        ['name' => 'V']
    ),
    ['V', 'W']);

echo "\n== misc ==\n";

check('empty insert is a no-op', NDB::table('users')->insert([]), true);
check('empty update is a no-op', NDB::table('users')->where('id', 1)->update([]), 0);

check('limit(0) is kept',
    NDB::table('users')->limit(0)->toSql(),
    'select * from "users" limit 0');

check('negative limit is clamped',
    NDB::table('users')->limit(-5)->toSql(),
    'select * from "users" limit 0');

check('limit(null) clears the limit',
    NDB::table('users')->limit(10)->limit(null)->toSql(),
    'select * from "users"');

$threw = false;
try {
    NDB::table('users')->orderBy('name', 'sideways');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a bad sort direction throws', $threw, true);

$threw = false;
try {
    NDB::table('users')->chunk(0, fn () => null);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('chunk(0) throws instead of looping forever', $threw, true);

check('Expression is not bound',
    NDB::table('users')->where('created_at', '<', NDB::raw('now()'))->getBindings(),
    []);

check('Expression lands in the SQL verbatim',
    NDB::table('users')->where('created_at', '<', NDB::raw('now()'))->toSql(),
    'select * from "users" where "created_at" < now()');

summary();
