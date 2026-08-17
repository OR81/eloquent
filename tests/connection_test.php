<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Connection;
use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;

/**
 * Connection management, raw statements, transactions and the query log,
 * both through the Connection object and through the NDB facade.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

$connection = NDB::connection();
$connection->unprepared('create table users (id integer primary key autoincrement, name text, votes integer default 0)');

section('configuration');

check('getDriver', $connection->getDriver(), 'sqlite');
check('getConfig by key', $connection->getConfig('database'), ':memory:');
check('getConfig fills in the defaults', $connection->getConfig('charset'), 'utf8mb4');
check('an unknown key is null', $connection->getConfig('nope'), null);
check('getConfig with no key returns everything', is_array($connection->getConfig()), true);
check('the grammar matches the driver', $connection->getGrammar()->getDriver(), 'sqlite');

throws('an unsupported driver is refused',
    fn () => new Connection(['driver' => 'oracle']),
    InvalidArgumentException::class,
    'Unsupported driver');

throws('sqlite without a database path is refused',
    fn () => (new Connection(['driver' => 'sqlite']))->getPdo(),
    InvalidArgumentException::class,
    "'database' config value is required");

section('statements');

check('statement', $connection->statement('insert into users (name, votes) values (?, ?)', ['Alice', 10]), true);
check('insert', $connection->insert('insert into users (name, votes) values (?, ?)', ['Bob', 20]), true);
check('affectingStatement returns the row count',
    $connection->affectingStatement('update users set votes = votes + 1 where votes > ?', [5]), 2);
check('update', $connection->update('update users set votes = ? where name = ?', [99, 'Alice']), 1);
check('select', $connection->select('select name from users order by id'), [['name' => 'Alice'], ['name' => 'Bob']]);
check('selectOne', $connection->selectOne('select name from users where votes = ?', [99]), ['name' => 'Alice']);
check('selectOne with no match is null', $connection->selectOne('select name from users where id = ?', [999]), null);
check('unprepared', $connection->unprepared('create table scratch (id integer)'), true);
check('lastInsertId', (int) $connection->lastInsertId() > 0, true);
check('delete', $connection->delete('delete from users where name = ?', ['Bob']), 1);

check('run returns the PDOStatement',
    $connection->run('select count(*) as c from users')->fetch(PDO::FETCH_ASSOC), ['c' => 1]);

check('cursor streams',
    iterator_to_array($connection->cursor('select name from users')), [['name' => 'Alice']]);

section('errors');

throws('a broken statement throws QueryException',
    fn () => $connection->select('select * from nope'),
    QueryException::class,
    'no such table');

try {
    $connection->select('select * from nope where id = ?', [5]);
} catch (QueryException $e) {
    check('the exception carries the sql', $e->getSql(), 'select * from nope where id = ?');
    check('the exception carries the bindings', $e->getBindings(), [5]);
    check('the driver exception is kept as the cause', $e->getPrevious() instanceof PDOException, true);
    check('the message shows both', strpos($e->getMessage(), 'bindings: [5]') !== false, true);
}

throws('unprepared errors are wrapped too',
    fn () => $connection->unprepared('this is not sql'),
    QueryException::class);

section('transactions');

check('the level starts at zero', $connection->transactionLevel(), 0);

$connection->beginTransaction();
check('the level rises', $connection->transactionLevel(), 1);
$connection->insert('insert into users (name) values (?)', ['Temp']);
$connection->rollBack();
check('the level falls back', $connection->transactionLevel(), 0);
check('a manual rollback undoes the write',
    $connection->selectOne('select count(*) as c from users')['c'], 1);

$connection->beginTransaction();
$connection->insert('insert into users (name) values (?)', ['Kept']);
$connection->commit();
check('a manual commit keeps the write',
    $connection->selectOne('select count(*) as c from users')['c'], 2);

check('transaction returns the callback value',
    $connection->transaction(fn () => 'result'), 'result');

check('rollBack outside a transaction is a no-op',
    (function () use ($connection) {
        $connection->rollBack();
        return $connection->transactionLevel();
    })(),
    0);

$attempts = 0;
throws('a transaction gives up after the last attempt',
    function () use ($connection, &$attempts) {
        $connection->transaction(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('nope');
        }, 3);
    },
    RuntimeException::class);
check('and it really retried', $attempts, 3);

check('a retried transaction can succeed on a later attempt',
    (function () use ($connection) {
        $tries = 0;
        return $connection->transaction(function () use (&$tries) {
            $tries++;
            if ($tries < 2) {
                throw new RuntimeException('flaky');
            }
            return $tries;
        }, 3);
    })(),
    2);

section('nested transactions use savepoints');

$connection->transaction(function () use ($connection) {
    $connection->insert('insert into users (name) values (?)', ['Outer']);

    check('the inner level is 2',
        $connection->transaction(fn () => $connection->transactionLevel()), 2);

    try {
        $connection->transaction(function () use ($connection) {
            $connection->insert('insert into users (name) values (?)', ['Inner']);
            throw new RuntimeException('inner failed');
        });
    } catch (RuntimeException $e) {
        // expected
    }
});

check('the outer write survived',
    $connection->selectOne('select count(*) as c from users where name = ?', ['Outer'])['c'], 1);
check('the inner write was rolled back',
    $connection->selectOne('select count(*) as c from users where name = ?', ['Inner'])['c'], 0);

section('query log');

$connection->flushQueryLog();
$connection->disableQueryLog();
$connection->select('select 1');
check('nothing is logged while disabled', $connection->getQueryLog(), []);

$connection->enableQueryLog();
$connection->select('select * from users where id = ?', [1]);
$log = $connection->getQueryLog();

check('one entry per query', count($log), 1);
check('the entry keeps the sql', $log[0]['query'], 'select * from users where id = ?');
check('and the bindings', $log[0]['bindings'], [1]);
check('and a duration', is_float($log[0]['time']), true);

$connection->flushQueryLog();
check('flushQueryLog empties it', $connection->getQueryLog(), []);

section('an injected PDO');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('create table t (id integer primary key, label text)');
$pdo->exec("insert into t (label) values ('from the injected handle')");

$injected = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
$injected->setPdo($pdo);

check('setPdo is used instead of connecting',
    $injected->selectOne('select label from t'), ['label' => 'from the injected handle']);
check('getPdo hands back the same instance', $injected->getPdo() === $pdo, true);

$injected->disconnect();
check('disconnect resets the transaction level', $injected->transactionLevel(), 0);

section('the NDB facade');

check('NDB::select', NDB::select('select name from users where name = ?', ['Alice']), [['name' => 'Alice']]);
check('NDB::selectOne', NDB::selectOne('select name from users where name = ?', ['Alice']), ['name' => 'Alice']);
check('NDB::insert', NDB::insert('insert into users (name) values (?)', ['Facade']), true);
check('NDB::update', NDB::update('update users set votes = ? where name = ?', [5, 'Facade']), 1);
check('NDB::delete', NDB::delete('delete from users where name = ?', ['Facade']), 1);
check('NDB::statement', NDB::statement('create table facade_scratch (id integer)'), true);
check('NDB::unprepared', NDB::unprepared('drop table facade_scratch'), true);
check('NDB::getPdo', NDB::getPdo() instanceof PDO, true);
check('NDB::raw builds an Expression',
    NDB::raw('count(*)')->getValue(), 'count(*)');

check('NDB::transaction', NDB::transaction(fn () => 'ok'), 'ok');

NDB::beginTransaction();
NDB::insert('insert into users (name) values (?)', ['ViaFacade']);
NDB::rollBack();
check('NDB::beginTransaction + rollBack', NDB::table('users')->where('name', 'ViaFacade')->exists(), false);

NDB::beginTransaction();
NDB::insert('insert into users (name) values (?)', ['ViaFacade']);
NDB::commit();
check('NDB::beginTransaction + commit', NDB::table('users')->where('name', 'ViaFacade')->exists(), true);

NDB::flushQueryLog();
NDB::enableQueryLog();
NDB::table('users')->count();
check('NDB::enableQueryLog + getQueryLog', count(NDB::getQueryLog()), 1);
NDB::disableQueryLog();
NDB::table('users')->count();
check('NDB::disableQueryLog stops it', count(NDB::getQueryLog()), 1);
NDB::flushQueryLog();
check('NDB::flushQueryLog', NDB::getQueryLog(), []);

section('named connections');

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:'], 'reporting');
NDB::connection('reporting')->unprepared('create table events (id integer primary key, label text)');
NDB::connection('reporting')->insert("insert into events (label) values ('report only')");

check('a second connection is separate',
    NDB::connection('reporting')->selectOne('select label from events'), ['label' => 'report only']);
check('the default connection does not see it',
    NDB::connection()->selectOne("select name from sqlite_master where name = 'events'"), null);

check('connection() caches the instance',
    NDB::connection('reporting') === NDB::connection('reporting'), true);

$custom = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
NDB::setConnection($custom, 'injected');
check('setConnection registers an instance', NDB::connection('injected') === $custom, true);

check('getDefaultConnection', NDB::getDefaultConnection(), 'default');
NDB::setDefaultConnection('reporting');
check('setDefaultConnection switches the target',
    NDB::connection()->selectOne('select label from events'), ['label' => 'report only']);
NDB::setDefaultConnection('default');

$before = NDB::connection('reporting');
NDB::purge('reporting');
check('purge drops the cached instance', NDB::connection('reporting') === $before, false);

NDB::purge();
throws('purge with no name drops them all, including injected instances',
    fn () => NDB::connection('injected'),
    InvalidArgumentException::class,
    'is not configured');

throws('an unconfigured name is refused',
    fn () => NDB::connection('missing'),
    InvalidArgumentException::class,
    'is not configured');

section('newBuilder');

$builder = NDB::newBuilder();
check('newBuilder has no table', $builder->toSql(), 'select *');
check('newBuilder can be pointed anywhere',
    $builder->from('users')->where('name', 'Alice')->toSql(),
    'select * from "users" where "name" = ?');

summary();
