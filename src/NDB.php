<?php

namespace Or81\Eloquent;

use InvalidArgumentException;
use PDO;

/**
 * The entry point of the library.
 *
 * Configure once, then start queries from it:
 *
 *     NDB::configure(['driver' => 'mysql', 'database' => 'shop', 'username' => 'root', 'password' => '']);
 *     NDB::table('users')->where('active', 1)->get();
 *
 * A subclass gets its table name for free, so `class BlogPost extends NDB {}`
 * gives you `BlogPost::query()->get()` against the `blog_posts` table.
 */
class NDB
{
    /**
     * Override in a subclass to pin its table name. Left empty, the name is
     * derived from the class name.
     */
    protected static string $table = '';

    /**
     * Override in a subclass to pin it to a named connection.
     */
    protected static ?string $connectionName = null;

    /**
     * Override in a subclass to say how its date columns are stored, so the
     * whereJalali*() methods know what to compare against:
     *
     *     protected static array $dateStorage = [
     *         'created_at' => Jalali::GREGORIAN,
     *         'issued_at'  => [Jalali::JALALI, 'Y-m-d'],
     *         'logged_at'  => Jalali::UNIX,
     *     ];
     *
     * @var array<string, string|array{0: string, 1: string|null}>
     */
    protected static array $dateStorage = [];

    /** @var array<string, array> */
    private static array $configs = [];

    /** @var array<string, Connection> */
    private static array $connections = [];

    private static string $defaultConnection = 'default';

    /* ------------------------------------------------------------------
     | Configuration
     | ------------------------------------------------------------------ */

    /**
     * Override what the environment says. Anything left out keeps its .env
     * value, so a single key can be changed on its own:
     *
     *     NDB::configure(['database' => 'shop_test']);
     *
     * MySQL: ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
     *         'database' => '...', 'username' => '...', 'password' => '...']
     * SQLite: ['driver' => 'sqlite', 'database' => '/path/to/db.sqlite']
     */
    public static function configure(array $config, string $name = 'default'): void
    {
        self::$configs[$name] = array_merge(static::envConfig($name) ?? [], $config);

        unset(self::$connections[$name]);
    }

    public static function connection(?string $name = null): Connection
    {
        $name = $name ?: self::$defaultConnection;

        if (! isset(self::$connections[$name])) {
            $config = self::$configs[$name] ?? static::envConfig($name);

            if ($config === null) {
                throw new InvalidArgumentException(
                    "Database connection [{$name}] is not configured. Call NDB::configure([...]), "
                    . 'or set DB_CONNECTION and DB_DATABASE in a .env file.'
                );
            }

            self::$configs[$name] = $config;
            self::$connections[$name] = new Connection($config);
        }

        return self::$connections[$name];
    }

    /**
     * Read a .env file from somewhere other than the auto-discovered location.
     * Call it before the first query: it discards every cached config and
     * connection so everything is read again from the new file.
     */
    public static function useEnv(string $path): void
    {
        Env::use($path);

        self::forget();
    }

    /**
     * The connection config the environment describes, or null when it says
     * nothing about this connection.
     *
     * The default connection reads DB_*; any other name reads {NAME}_DB_*, so
     * a typo in a connection name still fails loudly instead of silently
     * pointing at the main database.
     *
     * Recognised keys: DB_CONNECTION (or DB_DRIVER), DB_HOST, DB_PORT,
     * DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET, DB_COLLATION,
     * DB_SOCKET, DB_DATE_STORAGE, DB_DATE_FORMAT.
     */
    public static function envConfig(?string $name = null): ?array
    {
        $name = $name ?: self::$defaultConnection;

        $prefix = $name === self::$defaultConnection ? '' : strtoupper($name) . '_';

        $driver = Env::get($prefix . 'DB_CONNECTION') ?? Env::get($prefix . 'DB_DRIVER');
        $database = Env::get($prefix . 'DB_DATABASE');

        if ($driver === null && $database === null) {
            return null;
        }

        $config = array_filter([
            'driver' => $driver,
            'host' => Env::get($prefix . 'DB_HOST'),
            'port' => Env::get($prefix . 'DB_PORT'),
            'unix_socket' => Env::get($prefix . 'DB_SOCKET'),
            'database' => $database,
            'username' => Env::get($prefix . 'DB_USERNAME'),
            'password' => Env::get($prefix . 'DB_PASSWORD'),
            'charset' => Env::get($prefix . 'DB_CHARSET'),
            'collation' => Env::get($prefix . 'DB_COLLATION'),
            'date_storage' => Env::get($prefix . 'DB_DATE_STORAGE'),
            'date_format' => Env::get($prefix . 'DB_DATE_FORMAT'),
        ], fn ($value) => $value !== null);

        if (isset($config['port'])) {
            $config['port'] = (int) $config['port'];
        }

        // An empty password is a real setting, so put it back if it was given.
        if (! isset($config['password']) && Env::has($prefix . 'DB_PASSWORD')) {
            $config['password'] = '';
        }

        return $config;
    }

    public static function setConnection(Connection $connection, string $name = 'default'): void
    {
        self::$connections[$name] = $connection;
    }

    public static function setDefaultConnection(string $name): void
    {
        self::$defaultConnection = $name;
    }

    public static function getDefaultConnection(): string
    {
        return self::$defaultConnection;
    }

    /**
     * Drop a cached connection so the next query reconnects. The config it was
     * built from is kept: use forget() to discard that too.
     */
    public static function purge(?string $name = null): void
    {
        if ($name === null) {
            self::$connections = [];

            return;
        }

        unset(self::$connections[$name]);
    }

    /**
     * Drop both the connection and the config behind it, so the next query
     * reads the environment again from scratch.
     */
    public static function forget(?string $name = null): void
    {
        if ($name === null) {
            self::$configs = [];
            self::$connections = [];

            return;
        }

        unset(self::$configs[$name], self::$connections[$name]);
    }

    public static function getPdo(?string $name = null): PDO
    {
        return static::connection($name)->getPdo();
    }

    /* ------------------------------------------------------------------
     | Starting a query
     | ------------------------------------------------------------------ */

    /**
     * @param Expression|string|null $table defaults to the subclass table name
     */
    public static function table($table = null, ?string $as = null): Builder
    {
        $table = $table ?? static::tableName();

        if ($table === null) {
            throw new InvalidArgumentException(
                'No table given. Pass one to NDB::table(), or extend NDB to infer it from the class name.'
            );
        }

        $builder = static::newBuilder()->from($table, $as);

        foreach (static::$dateStorage as $column => $storage) {
            $builder->dateStorage($column, ...array_values((array) $storage));
        }

        return $builder;
    }

    /**
     * A builder already pointed at this class's table.
     */
    public static function query(): Builder
    {
        return static::table();
    }

    /**
     * A builder with no table set.
     */
    public static function newBuilder(?string $connection = null): Builder
    {
        $connection = static::connection($connection ?: static::$connectionName);

        return new Builder($connection, $connection->getGrammar());
    }

    /**
     * A raw SQL fragment. Values still belong in bindings: put a ? where each
     * one goes and pass them in the same order.
     *
     *     NDB::raw("COALESCE(NULLIF(TRIM(city), ''), ?, city)", [$city])
     */
    public static function raw(string $value, array $bindings = []): Expression
    {
        return new Expression($value, $bindings);
    }

    /* ------------------------------------------------------------------
     | Raw statements
     | ------------------------------------------------------------------ */

    public static function select(string $query, array $bindings = []): array
    {
        return static::connection(static::$connectionName)->select($query, $bindings);
    }

    public static function selectOne(string $query, array $bindings = []): ?array
    {
        return static::connection(static::$connectionName)->selectOne($query, $bindings);
    }

    public static function insert(string $query, array $bindings = []): bool
    {
        return static::connection(static::$connectionName)->insert($query, $bindings);
    }

    public static function update(string $query, array $bindings = []): int
    {
        return static::connection(static::$connectionName)->update($query, $bindings);
    }

    public static function delete(string $query, array $bindings = []): int
    {
        return static::connection(static::$connectionName)->delete($query, $bindings);
    }

    public static function statement(string $query, array $bindings = []): bool
    {
        return static::connection(static::$connectionName)->statement($query, $bindings);
    }

    public static function unprepared(string $query): bool
    {
        return static::connection(static::$connectionName)->unprepared($query);
    }

    /* ------------------------------------------------------------------
     | Transactions
     | ------------------------------------------------------------------ */

    public static function transaction(callable $callback, int $attempts = 1)
    {
        return static::connection(static::$connectionName)->transaction($callback, $attempts);
    }

    public static function beginTransaction(): void
    {
        static::connection(static::$connectionName)->beginTransaction();
    }

    public static function commit(): void
    {
        static::connection(static::$connectionName)->commit();
    }

    public static function rollBack(): void
    {
        static::connection(static::$connectionName)->rollBack();
    }

    /* ------------------------------------------------------------------
     | Query log
     | ------------------------------------------------------------------ */

    public static function enableQueryLog(?string $name = null): void
    {
        static::connection($name)->enableQueryLog();
    }

    public static function disableQueryLog(?string $name = null): void
    {
        static::connection($name)->disableQueryLog();
    }

    public static function getQueryLog(?string $name = null): array
    {
        return static::connection($name)->getQueryLog();
    }

    public static function flushQueryLog(?string $name = null): void
    {
        static::connection($name)->flushQueryLog();
    }

    /* ------------------------------------------------------------------
     | Table name inference
     | ------------------------------------------------------------------ */

    /**
     * @return string|null null when called on NDB itself
     */
    public static function tableName(): ?string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        if (static::class === self::class) {
            return null;
        }

        $short = substr(strrchr('\\' . static::class, '\\'), 1);
        $short = preg_replace('/_?model$/i', '', $short);

        return static::pluralize(static::snake($short));
    }

    protected static function snake(string $value): string
    {
        $value = preg_replace('/\s+/u', '', ucwords($value));

        return strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $value));
    }

    protected static function pluralize(string $value): string
    {
        $irregular = [
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'child' => 'children',
            'tooth' => 'teeth',
            'foot' => 'feet',
            'mouse' => 'mice',
            'goose' => 'geese',
        ];

        $uncountable = [
            'equipment', 'information', 'money', 'news', 'data',
            'series', 'species', 'sheep', 'fish', 'staff', 'media',
        ];

        $lower = strtolower($value);

        if (in_array($lower, $uncountable, true)) {
            return $value;
        }

        foreach ($irregular as $singular => $plural) {
            if ($lower === $singular || substr($lower, -strlen($singular) - 1) === '_' . $singular) {
                return substr($value, 0, strlen($value) - strlen($singular)) . $plural;
            }
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return $value . 'es';
        }

        if (preg_match('/[^aeiou]y$/i', $value)) {
            return substr($value, 0, -1) . 'ies';
        }

        return $value . 's';
    }
}
