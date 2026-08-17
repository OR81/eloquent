<?php

namespace Or81\Eloquent;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * A single PDO connection. Connects lazily, on the first statement.
 */
class Connection
{
    protected array $config;
    protected Grammar $grammar;
    protected ?PDO $pdo = null;
    protected int $transactions = 0;
    protected bool $logging = false;
    protected array $queryLog = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'unix_socket' => null,
            'database' => '',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [],
        ], $config);

        if (! in_array($this->config['driver'], ['mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException("Unsupported driver [{$this->config['driver']}]. Use 'mysql' or 'sqlite'.");
        }

        $this->grammar = new Grammar($this->config['driver']);
    }

    public function getDriver(): string
    {
        return $this->config['driver'];
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    public function getConfig(?string $key = null)
    {
        return $key === null ? $this->config : ($this->config[$key] ?? null);
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Use an already-established PDO instance instead of connecting.
     */
    public function setPdo(PDO $pdo): self
    {
        $this->pdo = $pdo;

        return $this;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactions = 0;
    }

    /* ------------------------------------------------------------------
     | Running statements
     | ------------------------------------------------------------------ */

    public function select(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectOne(string $query, array $bindings = []): ?array
    {
        $record = $this->run($query, $bindings)->fetch(PDO::FETCH_ASSOC);

        return $record === false ? null : $record;
    }

    /**
     * Stream rows one at a time instead of buffering the whole result set.
     *
     * PDO buffers by default on MySQL, which means the entire result set is
     * pulled into memory by execute() and fetching one row at a time saves
     * nothing. Buffering is turned off for the duration and restored after.
     *
     * While an unbuffered cursor is open MySQL will not run another query on
     * the same connection, so finish the loop (or break out of it) before
     * querying again, or use a second connection.
     */
    public function cursor(string $query, array $bindings = []): Generator
    {
        $unbuffered = $this->unbufferedFetching();
        $previous = null;

        if ($unbuffered) {
            $previous = $this->getPdo()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

            $this->getPdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $this->run($query, $bindings);

            while (($record = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $record;
            }
        } finally {
            if ($unbuffered) {
                $this->getPdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous ?? true);
            }
        }
    }

    /**
     * Whether this connection has to be told not to buffer. Only MySQL does;
     * SQLite streams either way.
     */
    protected function unbufferedFetching(): bool
    {
        return $this->config['driver'] === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    public function insert(string $query, array $bindings = []): bool
    {
        return $this->statement($query, $bindings);
    }

    public function update(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function delete(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function statement(string $query, array $bindings = []): bool
    {
        $this->run($query, $bindings);

        return true;
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings)->rowCount();
    }

    /**
     * Run SQL with no bindings and no prepared statement (DDL, pragmas, ...).
     */
    public function unprepared(string $query): bool
    {
        try {
            return $this->getPdo()->exec($query) !== false;
        } catch (PDOException $e) {
            throw new QueryException($query, [], $e);
        }
    }

    public function lastInsertId(?string $sequence = null)
    {
        return $this->getPdo()->lastInsertId($sequence);
    }

    public function run(string $query, array $bindings = []): PDOStatement
    {
        $start = microtime(true);

        try {
            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $bindings);

            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException($query, $bindings, $e);
        }

        if ($this->logging) {
            $this->queryLog[] = [
                'query' => $query,
                'bindings' => $bindings,
                'time' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        return $statement;
    }

    protected function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach (array_values($bindings) as $index => $value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
                $type = PDO::PARAM_STR;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif (is_int($value)) {
                $type = PDO::PARAM_INT;
            } else {
                $type = PDO::PARAM_STR;
            }

            $statement->bindValue($index + 1, $value, $type);
        }
    }

    /* ------------------------------------------------------------------
     | Transactions (nested calls use savepoints)
     | ------------------------------------------------------------------ */

    public function transaction(callable $callback, int $attempts = 1)
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);

                $this->commit();

                return $result;
            } catch (Throwable $e) {
                $this->rollBack();

                if ($attempt >= $attempts) {
                    throw $e;
                }
            }
        }

        return null;
    }

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->getPdo()->beginTransaction();
        } else {
            $this->getPdo()->exec('SAVEPOINT trans' . ($this->transactions + 1));
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 1) {
            $this->getPdo()->commit();
        } elseif ($this->transactions > 1) {
            $this->getPdo()->exec('RELEASE SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        if ($this->transactions === 1) {
            $this->getPdo()->rollBack();
        } else {
            $this->getPdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions--;
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /* ------------------------------------------------------------------
     | Query log
     | ------------------------------------------------------------------ */

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    /* ------------------------------------------------------------------
     | Connecting
     | ------------------------------------------------------------------ */

    protected function connect(): PDO
    {
        $options = $this->config['options'] + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $dsn = $this->dsn();

        try {
            $pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
        } catch (PDOException $e) {
            throw new QueryException('[connecting to ' . $dsn . ']', [], $e);
        }

        if ($this->config['driver'] === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    protected function dsn(): string
    {
        if ($this->config['driver'] === 'sqlite') {
            return 'sqlite:' . $this->sqlitePath();
        }

        if (! empty($this->config['unix_socket'])) {
            return "mysql:unix_socket={$this->config['unix_socket']};dbname={$this->config['database']}";
        }

        $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']}";

        if (! empty($this->config['charset'])) {
            $dsn .= ";charset={$this->config['charset']}";
        }

        return $dsn;
    }

    protected function sqlitePath(): string
    {
        $path = (string) $this->config['database'];

        if ($path === '') {
            throw new InvalidArgumentException("The 'database' config value is required for the sqlite driver.");
        }

        if ($path === ':memory:') {
            return $path;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! file_exists($path)) {
            touch($path);
        }

        return $path;
    }
}
