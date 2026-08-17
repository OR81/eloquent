<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\Connection;
use Or81\Eloquent\Expression;
use Or81\Eloquent\Grammar;
use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;

/**
 * The grammar's public surface, driver by driver, plus Expression and
 * QueryException.
 */

$sqlite = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
$mysql = new Connection(['driver' => 'mysql', 'database' => 'test']);

$sqliteGrammar = $sqlite->getGrammar();
$mysqlGrammar = $mysql->getGrammar();

function sqliteQuery(Connection $connection, string $table = 'users'): Builder
{
    return (new Builder($connection))->from($table);
}

section('the grammar knows its driver');

check('sqlite', $sqliteGrammar->getDriver(), 'sqlite');
check('mysql', $mysqlGrammar->getDriver(), 'mysql');
check('a grammar can be built on its own', (new Grammar('mysql'))->getDriver(), 'mysql');

section('wrapping identifiers');

check('a bare column', $sqliteGrammar->wrap('name'), '"name"');
check('a qualified column', $sqliteGrammar->wrap('users.name'), '"users"."name"');
check('an aliased column', $sqliteGrammar->wrap('users.name as author'), '"users"."name" as "author"');
check('a star is left alone', $sqliteGrammar->wrap('*'), '*');
check('a qualified star', $sqliteGrammar->wrap('users.*'), '"users".*');
check('mysql uses backticks', $mysqlGrammar->wrap('users.name'), '`users`.`name`');
check('an embedded quote is escaped', $sqliteGrammar->wrap('we"ird'), '"we""ird"');
check('an embedded backtick is escaped', $mysqlGrammar->wrap('we`ird'), '`we``ird`');
check('an Expression passes through untouched',
    $sqliteGrammar->wrap(new Expression('count(*)')), 'count(*)');
check('as does an aliased one, case-insensitively',
    $sqliteGrammar->wrap('total AS Sum'), '"total" as "Sum"');

check('wrapTable', $sqliteGrammar->wrapTable('users'), '"users"');
check('wrapTable with an alias', $sqliteGrammar->wrapTable('users as u'), '"users" as "u"');
check('wrapTable with an Expression',
    $sqliteGrammar->wrapTable(new Expression('(select 1) as t')), '(select 1) as t');

check('columnize', $sqliteGrammar->columnize(['id', 'users.name']), '"id", "users"."name"');
check('columnize with a raw member',
    $sqliteGrammar->columnize(['id', new Expression('count(*) as c')]), '"id", count(*) as c');

section('parameters');

check('a value becomes a placeholder', $sqliteGrammar->parameter('anything'), '?');
check('an Expression becomes itself', $sqliteGrammar->parameter(new Expression('now()')), 'now()');
check('parameterize', $sqliteGrammar->parameterize([1, 2, 3]), '?, ?, ?');
check('parameterize keeps raw members inline',
    $sqliteGrammar->parameterize([1, new Expression('default')]), '?, default');

section('compileSelect');

check('the simplest query',
    $sqliteGrammar->compileSelect(sqliteQuery($sqlite)), 'select * from "users"');

check('a fully loaded query',
    $sqliteGrammar->compileSelect(
        sqliteQuery($sqlite)
            ->select('users.role')
            ->selectRaw('count(*) as total')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->where('users.active', 1)
            ->groupBy('users.role')
            ->having('total', '>', 1)
            ->orderByDesc('total')
            ->limit(10)
            ->offset(20)
    ),
    'select "users"."role", count(*) as total from "users" '
    . 'inner join "posts" on "users"."id" = "posts"."user_id" '
    . 'where "users"."active" = ? group by "users"."role" having "total" > ? '
    . 'order by "total" desc limit 10 offset 20');

check('compileExists wraps the whole select',
    $sqliteGrammar->compileExists(sqliteQuery($sqlite)->where('id', 1)),
    'select exists(select * from "users" where "id" = ?) as "exists"');

check('compileRandom is driver specific',
    [$sqliteGrammar->compileRandom(), $mysqlGrammar->compileRandom()],
    ['RANDOM()', 'RAND()']);

section('compileWheres on its own');

check('with the keyword',
    $sqliteGrammar->compileWheres(sqliteQuery($sqlite)->where('a', 1)->where('b', 2)),
    'where "a" = ? and "b" = ?');

check('without the keyword, as a join uses it',
    $sqliteGrammar->compileWheres(sqliteQuery($sqlite)->where('a', 1)->orWhere('b', 2), false),
    '"a" = ? or "b" = ?');

check('no wheres compiles to nothing',
    $sqliteGrammar->compileWheres(sqliteQuery($sqlite)), '');

section('compileInsert');

check('one row',
    $sqliteGrammar->compileInsert(sqliteQuery($sqlite), [['name' => 'A', 'email' => 'a@x']]),
    'insert into "users" ("name", "email") values (?, ?)');

check('several rows',
    $sqliteGrammar->compileInsert(sqliteQuery($sqlite), [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]),
    'insert into "users" ("name") values (?), (?), (?)');

check('an empty row falls back to the defaults, sqlite style',
    $sqliteGrammar->compileInsert(sqliteQuery($sqlite), []),
    'insert into "users" default values');

check('and mysql style',
    $mysqlGrammar->compileInsert(sqliteQuery($mysql), []),
    'insert into `users` () values ()');

check('the ignore modifier, sqlite',
    $sqliteGrammar->compileInsert(sqliteQuery($sqlite), [['name' => 'A']], 'ignore'),
    'insert or ignore into "users" ("name") values (?)');

check('the ignore modifier, mysql',
    $mysqlGrammar->compileInsert(sqliteQuery($mysql), [['name' => 'A']], 'ignore'),
    'insert ignore into `users` (`name`) values (?)');

section('compileInsertUsing');

check('with a column list',
    $sqliteGrammar->compileInsertUsing(sqliteQuery($sqlite), ['name', 'votes'], 'select "name", "votes" from "old_users"'),
    'insert into "users" ("name", "votes") select "name", "votes" from "old_users"');

check('without one',
    $sqliteGrammar->compileInsertUsing(sqliteQuery($sqlite), [], 'select * from "old_users"'),
    'insert into "users" select * from "old_users"');

check('mysql wraps its identifiers the same way',
    $mysqlGrammar->compileInsertUsing(sqliteQuery($mysql), ['name'], 'select `name` from `old_users`'),
    'insert into `users` (`name`) select `name` from `old_users`');

section('compileUpsert');

check('sqlite uses on conflict / excluded',
    $sqliteGrammar->compileUpsert(
        sqliteQuery($sqlite),
        [['email' => 'a@x', 'votes' => 1]],
        ['email'],
        ['votes']
    ),
    'insert into "users" ("email", "votes") values (?, ?) '
    . 'on conflict ("email") do update set "votes" = "excluded"."votes"');

check('mysql uses on duplicate key update',
    $mysqlGrammar->compileUpsert(
        sqliteQuery($mysql),
        [['email' => 'a@x', 'votes' => 1]],
        ['email'],
        ['votes']
    ),
    'insert into `users` (`email`, `votes`) values (?, ?) '
    . 'on duplicate key update `votes` = values(`votes`)');

check('a keyed update sets a literal instead of the inserted value',
    $sqliteGrammar->compileUpsert(
        sqliteQuery($sqlite),
        [['email' => 'a@x']],
        ['email'],
        ['seen_at' => 'now']
    ),
    'insert into "users" ("email") values (?) on conflict ("email") do update set "seen_at" = ?');

section('compileUpdate and compileDelete');

check('update',
    $sqliteGrammar->compileUpdate(sqliteQuery($sqlite)->where('id', 1), ['name' => 'X']),
    'update "users" set "name" = ? where "id" = ?');

check('update with a raw value',
    $sqliteGrammar->compileUpdate(sqliteQuery($sqlite), ['votes' => new Expression('votes + 1')]),
    'update "users" set "votes" = votes + 1');

check('delete',
    $sqliteGrammar->compileDelete(sqliteQuery($sqlite)->where('id', 1)),
    'delete from "users" where "id" = ?');

check('delete with no wheres',
    $sqliteGrammar->compileDelete(sqliteQuery($sqlite)), 'delete from "users"');

throws('sqlite refuses a joined update',
    fn () => $sqliteGrammar->compileUpdate(
        sqliteQuery($sqlite)->join('posts', 'users.id', '=', 'posts.user_id'),
        ['name' => 'X']
    ),
    RuntimeException::class,
    'does not support joins');

throws('sqlite refuses a limited delete',
    fn () => $sqliteGrammar->compileDelete(sqliteQuery($sqlite)->limit(5)),
    RuntimeException::class,
    'order by / limit');

check('mysql allows an ordered, limited delete',
    $mysqlGrammar->compileDelete(sqliteQuery($mysql)->where('votes', 0)->orderBy('id')->limit(100)),
    'delete from `users` where `votes` = ? order by `id` asc limit 100');

throws('but not alongside a join',
    fn () => $mysqlGrammar->compileDelete(
        sqliteQuery($mysql)->join('posts', 'users.id', '=', 'posts.user_id')->limit(5)
    ),
    RuntimeException::class,
    'multi-table');

section('compileTruncate');

check('mysql truncates',
    $mysqlGrammar->compileTruncate(sqliteQuery($mysql)),
    ['truncate table `users`' => []]);

check('sqlite deletes and resets the sequence',
    $sqliteGrammar->compileTruncate(sqliteQuery($sqlite)),
    [
        'delete from "users"' => [],
        'delete from sqlite_sequence where name = ?' => ['users'],
    ]);

section('binding order helpers');

$query = sqliteQuery($sqlite)
    ->join('posts', function ($join) {
        $join->on('users.id', '=', 'posts.user_id')->where('posts.flag', 'JOIN');
    })
    ->where('users.name', 'WHERE');

check('prepareBindingsForUpdate puts joins, then values, then wheres',
    $sqliteGrammar->prepareBindingsForUpdate($query, ['name' => 'VALUE']),
    ['JOIN', 'VALUE', 'WHERE']);

check('prepareBindingsForUpdate skips raw values',
    $sqliteGrammar->prepareBindingsForUpdate(
        sqliteQuery($sqlite)->where('id', 1),
        ['votes' => new Expression('votes + 1'), 'name' => 'X']
    ),
    ['X', 1]);

check('prepareBindingsForDelete keeps joins before wheres',
    $sqliteGrammar->prepareBindingsForDelete($query),
    ['JOIN', 'WHERE']);

section('Expression');

$expression = new Expression('count(*) as total');

check('getValue', $expression->getValue(), 'count(*) as total');
check('it casts to a string', (string) $expression, 'count(*) as total');
check('it is Stringable', $expression instanceof Stringable, true);
check('NDB::raw builds one', NDB::raw('now()')->getValue(), 'now()');

section('QueryException');

$previous = new PDOException('SQLSTATE[HY000]: boom');
$exception = new QueryException('select * from t where id = ?', [42], $previous);

check('getSql', $exception->getSql(), 'select * from t where id = ?');
check('getBindings', $exception->getBindings(), [42]);
check('getPrevious', $exception->getPrevious(), $previous);
check('the message keeps the driver text',
    strpos($exception->getMessage(), 'SQLSTATE[HY000]: boom') !== false, true);
check('the message shows the sql',
    strpos($exception->getMessage(), 'select * from t where id = ?') !== false, true);
check('and the bindings',
    strpos($exception->getMessage(), 'bindings: [42]') !== false, true);

check('bindings are rendered readably',
    (new QueryException('x', [null, true, false, 1.5, 'text'], $previous))->getMessage(),
    'SQLSTATE[HY000]: boom (SQL: x - bindings: [null, true, false, 1.5, text])');

check('no bindings means no bindings clause',
    (new QueryException('select 1', [], $previous))->getMessage(),
    'SQLSTATE[HY000]: boom (SQL: select 1)');

summary();
