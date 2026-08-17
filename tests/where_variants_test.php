<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\JoinClause;
use Or81\Eloquent\NDB;

/**
 * Every "or" and "not" variant of the where and having clauses, plus the
 * corners of the builder that the other suites reach only indirectly.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
NDB::configure(['driver' => 'mysql', 'database' => 'test'], 'mysql');

NDB::unprepared('create table users (
    id integer primary key autoincrement,
    name text, email text, role text, votes integer default 0,
    manager_id integer, deleted_at text, created_at text
)');
NDB::unprepared('create table posts (id integer primary key autoincrement, user_id integer, title text, published integer default 0)');

NDB::table('users')->insert([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'votes' => 150, 'manager_id' => null, 'deleted_at' => null, 'created_at' => '2024-03-05 09:30:00'],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user', 'votes' => 60, 'manager_id' => 1, 'deleted_at' => null, 'created_at' => '2024-04-06 11:15:00'],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'role' => 'user', 'votes' => 10, 'manager_id' => 1, 'deleted_at' => '2025-01-01', 'created_at' => '2025-01-10 08:00:00'],
    ['name' => 'Dave', 'email' => 'dave@example.com', 'role' => 'editor', 'votes' => 60, 'manager_id' => 2, 'deleted_at' => null, 'created_at' => '2025-02-11 22:45:00'],
]);

NDB::table('posts')->insert([
    ['user_id' => 1, 'title' => 'Hello', 'published' => 1],
    ['user_id' => 2, 'title' => 'Draft', 'published' => 0],
]);

function users(): Builder
{
    return NDB::table('users');
}

function mysqlUsers(): Builder
{
    $connection = NDB::connection('mysql');

    return (new Builder($connection))->from('users');
}

section('or variants of the where clauses');

check('orWhereRaw',
    users()->where('role', 'admin')->orWhereRaw('votes < ?', [20])->orderBy('id')->pluck('name'),
    ['Alice', 'Carol']);

check('orWhereIn',
    users()->where('role', 'admin')->orWhereIn('votes', [60])->orderBy('id')->pluck('name'),
    ['Alice', 'Bob', 'Dave']);

check('orWhereNotIn',
    users()->where('name', 'Alice')->orWhereNotIn('role', ['user', 'admin'])->orderBy('id')->pluck('name'),
    ['Alice', 'Dave']);

check('orWhereNull',
    users()->where('name', 'Bob')->orWhereNull('manager_id')->orderBy('id')->pluck('name'),
    ['Alice', 'Bob']);

check('orWhereNotNull',
    users()->where('name', 'Alice')->orWhereNotNull('deleted_at')->orderBy('id')->pluck('name'),
    ['Alice', 'Carol']);

check('orWhereBetween',
    users()->where('role', 'admin')->orWhereBetween('votes', [55, 65])->orderBy('id')->pluck('name'),
    ['Alice', 'Bob', 'Dave']);

check('orWhereColumn',
    users()->where('name', 'Alice')->orWhereColumn('manager_id', '=', 'id')->orderBy('id')->pluck('name'),
    ['Alice']);

check('orWhereExists',
    users()->where('name', 'Carol')->orWhereExists(function (Builder $query) {
        $query->from('posts')->whereColumn('posts.user_id', 'users.id')->where('published', 1);
    })->orderBy('id')->pluck('name'),
    ['Alice', 'Carol']);

section('not variants');

check('whereNotBetween',
    users()->whereNotBetween('votes', [50, 100])->orderBy('id')->pluck('name'),
    ['Alice', 'Carol']);

check('orWhereNotBetween',
    users()->where('name', 'Bob')->orWhereNotBetween('votes', [50, 200])->orderBy('id')->pluck('name'),
    ['Bob', 'Carol']);

check('whereNotLike',
    users()->whereNotLike('name', 'A%')->orderBy('id')->pluck('name'),
    ['Bob', 'Carol', 'Dave']);

check('whereNotExists',
    users()->whereNotExists(function (Builder $query) {
        $query->from('posts')->whereColumn('posts.user_id', 'users.id');
    })->orderBy('id')->pluck('name'),
    ['Carol', 'Dave']);

check('orWhereNotExists',
    users()->where('name', 'Alice')->orWhereNotExists(function (Builder $query) {
        $query->from('posts')->whereColumn('posts.user_id', 'users.id');
    })->orderBy('id')->pluck('name'),
    ['Alice', 'Carol', 'Dave']);

section('whereNested built by hand');

$query = users()->whereNested(function (Builder $inner) {
    $inner->where('role', 'user')->orWhere('role', 'editor');
})->where('votes', '>', 50);

check('whereNested sql',
    $query->toSql(),
    'select * from "users" where ("role" = ? or "role" = ?) and "votes" > ?');
check('whereNested results', $query->orderBy('id')->pluck('name'), ['Bob', 'Dave']);

check('an empty nested closure adds nothing',
    users()->whereNested(function (Builder $inner) {})->toSql(),
    'select * from "users"');

section('date part clauses');

check('whereDay', users()->whereDay('created_at', 6)->pluck('name'), ['Bob']);
check('whereTime', users()->whereTime('created_at', '>', '20:00:00')->pluck('name'), ['Dave']);
check('whereDay on mysql compiles to day()',
    mysqlUsers()->whereDay('created_at', 6)->toSql(),
    'select * from `users` where day(`created_at`) = ?');
check('whereTime on sqlite compiles to strftime',
    users()->whereTime('created_at', '09:30:00')->toSql(),
    'select * from "users" where strftime(\'%H:%M:%S\', "created_at") = cast(? as text)');

section('having variants');

check('orHaving',
    users()->select('role')->selectRaw('count(*) as total')->groupBy('role')
        ->having('total', '>', 1)->orHaving('role', 'admin')->orderBy('role')->pluck('role'),
    ['admin', 'user']);

check('havingNull',
    users()->select('manager_id')->groupBy('manager_id')->havingNull('manager_id')->get(),
    [['manager_id' => null]]);

check('havingNull compiles',
    users()->select('manager_id')->groupBy('manager_id')->havingNull('manager_id')->toSql(),
    'select "manager_id" from "users" group by "manager_id" having "manager_id" is null');

section('select and join extras');

check('addSelect appends without clearing',
    users()->select('id')->addSelect('name')->orderBy('id')->first(),
    ['id' => 1, 'name' => 'Alice']);

check('addSelect accepts a list',
    users()->select('id')->addSelect(['name', 'role'])->orderBy('id')->first(),
    ['id' => 1, 'name' => 'Alice', 'role' => 'admin']);

check('leftJoinSub',
    users()->leftJoinSub(
        NDB::table('posts')->select('user_id')->selectRaw('count(*) as total')->groupBy('user_id'),
        'stats',
        'users.id',
        '=',
        'stats.user_id'
    )->orderBy('users.id')->select('users.name', 'stats.total')->get(),
    [
        ['name' => 'Alice', 'total' => 1],
        ['name' => 'Bob', 'total' => 1],
        ['name' => 'Carol', 'total' => null],
        ['name' => 'Dave', 'total' => null],
    ]);

check('orOn inside a join closure',
    users()->join('posts', function (JoinClause $join) {
        $join->on('users.id', '=', 'posts.user_id')->orOn('users.manager_id', '=', 'posts.user_id');
    })->toSql(),
    'select * from "users" inner join "posts" on "users"."id" = "posts"."user_id" or "users"."manager_id" = "posts"."user_id"');

section('unions and locks');

check('unionAll keeps duplicates',
    count(users()->select('role')->where('role', 'user')
        ->unionAll(users()->select('role')->where('role', 'user'))->get()),
    4);

check('union collapses duplicates',
    count(users()->select('role')->where('role', 'user')
        ->union(users()->select('role')->where('role', 'user'))->get()),
    1);

check('sharedLock compiles on mysql',
    mysqlUsers()->where('id', 1)->sharedLock()->toSql(),
    'select * from `users` where `id` = ? lock in share mode');

check('locks are ignored on sqlite',
    users()->where('id', 1)->sharedLock()->toSql(),
    'select * from "users" where "id" = ?');

section('aggregates');

check('aggregate() called directly', (int) users()->aggregate('sum', ['votes']), 280);
check('average is an alias of avg', users()->average('votes'), users()->avg('votes'));
check('aggregate returns null when there are no rows', users()->where('id', 999)->aggregate('max', ['votes']), null);

section('bindings and cloning');

$query = users()->where('role', 'admin');

check('getRawBindings is grouped', $query->getRawBindings()['where'], ['admin']);
check('other groups start empty', $query->getRawBindings()['having'], []);

$query->addBinding(7, 'having');
check('addBinding lands in the right group', $query->getRawBindings()['having'], [7]);
check('addBinding accepts a list',
    users()->addBinding([1, 2], 'order')->getRawBindings()['order'], [1, 2]);

throws('addBinding rejects an unknown group',
    fn () => users()->addBinding(1, 'nope'),
    InvalidArgumentException::class,
    'Invalid binding type');

throws('a multi-row insert with mismatched columns is refused',
    fn () => users()->insert([['name' => 'X', 'role' => 'user'], ['name' => 'Y']]),
    InvalidArgumentException::class,
    'must have the same columns');

$fresh = $query->newQuery();
check('newQuery starts clean', [$fresh->toSql(), $fresh->getBindings()], ['select *', []]);
check('newQuery keeps the connection', $fresh->getConnection() === $query->getConnection(), true);
check('getGrammar is exposed', $fresh->getGrammar() === NDB::connection()->getGrammar(), true);

section('debug output');

ob_start();
users()->where('votes', '>', 100)->dump();
$dumped = ob_get_clean();

check('dump prints the sql', strpos($dumped, 'select * from "users" where "votes" > ?') !== false, true);
check('dump prints the bindings', strpos($dumped, '100') !== false, true);

$script = sys_get_temp_dir() . '/ndb_dd_probe.php';
file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
    . 'Or81\Eloquent\NDB::configure(["driver" => "sqlite", "database" => ":memory:"]);'
    . 'Or81\Eloquent\NDB::table("users")->where("id", 7)->dd();');

$output = [];
$status = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
unlink($script);

$printed = implode("\n", $output);

check('dd prints the query', strpos($printed, 'select * from "users" where "id" = ?') !== false, true);
check('dd stops the script', $status, 1);

summary();
