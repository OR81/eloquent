<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Connection;
use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;

/**
 * Dropped connections: recognising one, retrying the statement on a fresh
 * connection, and refusing to do so when the retry would hide lost work.
 *
 * The server cannot be unplugged from a test, so a Connection subclass throws
 * the PDOException a dropped connection produces, on demand.
 */

$database = sys_get_temp_dir() . '/ndb_reconnect_' . getmypid() . '.sqlite';
@unlink($database);

/**
 * A PDOException exactly as the mysql driver reports a server that went away.
 */
function lostConnection(string $message = 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', int $code = 2006): PDOException
{
    $e = new PDOException($message);
    $e->errorInfo = ['HY000', $code, $message];

    return $e;
}

/**
 * A connection whose next statements can be made to die, counting how often
 * it connected and how often a statement was attempted.
 */
class FlakyConnection extends Connection
{
    public int $connects = 0;
    public int $attempts = 0;
    public int $drops = 0;
    public ?PDOException $failure = null;

    public function dropNext(int $times = 1, ?PDOException $failure = null): void
    {
        $this->drops = $times;
        $this->failure = $failure;
    }

    protected function connect(): PDO
    {
        $this->connects++;

        return parent::connect();
    }

    protected function execute(string $query, array $bindings): PDOStatement
    {
        $this->attempts++;

        if ($this->drops > 0) {
            $this->drops--;

            throw $this->failure ?? lostConnection();
        }

        return parent::execute($query, $bindings);
    }
}

$connect = fn (array $config = []) => new FlakyConnection(
    array_merge(['driver' => 'sqlite', 'database' => $database], $config)
);

section('recognising a dropped connection');

check('the mysql "gone away" error',
    QueryException::causedByLostConnection(lostConnection()), true);
check('a connection lost mid-query',
    QueryException::causedByLostConnection(lostConnection('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query', 2013)), true);
check('a killed connection',
    QueryException::causedByLostConnection(lostConnection('SQLSTATE[HY000]: General error: 1927 Connection was killed', 1927)), true);
check('a broken pipe',
    QueryException::causedByLostConnection(new PDOException('SQLSTATE[HY000]: General error: SSL: Broken pipe')), true);
check('a duplicate key is not a dropped connection',
    QueryException::causedByLostConnection(lostConnection("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry", 1062)), false);

$connection = $connect();
$connection->unprepared('create table users (id integer primary key autoincrement, name text, votes integer default 0)');
$connection->insert('insert into users (name, votes) values (?, ?)', ['Alice', 10]);

try {
    $connection->select('select * from nope');
} catch (QueryException $e) {
    check('a real query error is not a dropped connection', $e->isLostConnection(), false);
    check('a real query error is still reported', strpos($e->getMessage(), 'no such table') !== false, true);
}

section('retrying on a new connection');

$connection = $connect();
$connection->getPdo();
$connection->dropNext();

check('the statement goes through', $connection->select('select name from users'), [['name' => 'Alice']]);
check('it was attempted twice', $connection->attempts, 2);
check('on a second connection', $connection->connects, 2);

$connection = $connect();
$connection->dropNext();
check('a write is retried too',
    $connection->insert('insert into users (name, votes) values (?, ?)', ['Bob', 20]), true);
check('and lands exactly once',
    (int) $connection->select('select count(*) as c from users')[0]['c'], 2);

$connection = $connect();
$connection->dropNext(2);
throws('a connection that keeps dying gives up after one retry',
    fn () => $connection->select('select name from users'),
    QueryException::class,
    'gone away');
check('so the statement was attempted twice, not more', $connection->attempts, 2);

$connection = $connect();
$connection->dropNext(1, new PDOException('SQLSTATE[42S02]: Base table or view not found'));
throws('an ordinary failure is not retried',
    fn () => $connection->select('select name from users'),
    QueryException::class,
    'Base table');
check('so it was attempted once', $connection->attempts, 1);

section('when reconnecting is refused');

$connection = $connect(['reconnect' => false]);
$connection->dropNext();

try {
    $connection->select('select name from users');
    check('reconnect => false reports the drop', 'nothing thrown', 'a QueryException');
} catch (QueryException $e) {
    check('reconnect => false reports the drop', $e->isLostConnection(), true);
    check('and does not try again', $connection->attempts, 1);
    check('and the sql is still on the exception', $e->getSql(), 'select name from users');
}

$connection = $connect();
$connection->setPdo(new PDO('sqlite:' . $database));
$connection->dropNext();
throws('an injected PDO is never replaced',
    fn () => $connection->select('select name from users'),
    QueryException::class,
    'gone away');
check('so that statement was attempted once', $connection->attempts, 1);

section('a drop inside a transaction');

$connection = $connect();
$connection->beginTransaction();
$connection->insert('insert into users (name, votes) values (?, ?)', ['Carol', 30]);
$connection->dropNext();

throws('the statement is reported rather than retried',
    fn () => $connection->insert('insert into users (name, votes) values (?, ?)', ['Dave', 40]),
    QueryException::class,
    'gone away');

check('the transaction level is reset', $connection->transactionLevel(), 0);

$connection->rollBack();
check('so a rollBack() afterwards is a no-op', $connection->transactionLevel(), 0);

// The double only pretended the server had gone, so the real sqlite handle is
// still sitting on an open write transaction. Drop it, or the rest of the
// suite waits on the lock.
$connection->disconnect();

$connection = $connect();
$connection->dropNext();
throws('transaction() reports it as well',
    fn () => $connection->transaction(fn () => $connection->insert('insert into users (name) values (?)', ['Dave'])),
    QueryException::class,
    'gone away');
check('and its rollBack leaves the level at zero', $connection->transactionLevel(), 0);

$connection->disconnect();

/**
 * A connection whose first beginTransaction() finds the server gone, so the
 * retry has to happen before any work is inside the transaction.
 */
class BeginFlakyConnection extends FlakyConnection
{
    protected function connect(): PDO
    {
        $this->connects++;

        $pdo = new BeginFlakyPdo('sqlite:' . $this->getConfig('database'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->drop = $this->connects === 1;

        return $pdo;
    }
}

class BeginFlakyPdo extends PDO
{
    public bool $drop = false;

    public function beginTransaction(): bool
    {
        if ($this->drop) {
            $this->drop = false;

            throw lostConnection();
        }

        return parent::beginTransaction();
    }
}

$connection = new BeginFlakyConnection(['driver' => 'sqlite', 'database' => $database]);
$done = $connection->transaction(fn () => $connection->insert('insert into users (name) values (?)', ['Eve']));

check('a transaction that opens on a dead connection reconnects', $done, true);
check('on a second connection', $connection->connects, 2);
check('and the row is committed',
    (int) $connection->select("select count(*) as c from users where name = 'Eve'")[0]['c'], 1);

section('ping');

$connection = $connect();
check('a healthy connection pings', $connection->ping(), true);
check('without reconnecting', $connection->connects, 1);

$connection = $connect();
$connection->getPdo();
$connection->disconnect();
check('a disconnected one connects again', $connection->ping(), true);

$connection = $connect(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope']);
check('an unreachable server does not ping', $connection->ping(), false);

$connection = $connect();
$connection->beginTransaction();
check('a ping inside a transaction never reconnects', $connection->ping(), true);
$connection->rollBack();

section('through the facade');

NDB::configure(['driver' => 'sqlite', 'database' => $database]);

check('NDB::ping', NDB::ping(), true);

$before = NDB::getPdo();
NDB::reconnect();
check('NDB::reconnect opens a new PDO', NDB::getPdo() !== $before, true);
check('and keeps the same Connection object', NDB::connection() === NDB::connection(), true);
check('and the data is still there', NDB::table('users')->where('name', 'Alice')->exists(), true);

check('reconnect defaults to on', NDB::connection()->getConfig('reconnect'), true);

@unlink($database);

summary();
