# PHP Query Builder

A lightweight, dependency-free SQL query builder for PHP with a Laravel-style fluent API. Supports MySQL and SQLite.

Every value goes through a bound parameter, so the builder is safe against SQL injection by construction. Only identifiers are interpolated, and those are quoted by the grammar.

## Requirements

- PHP >= 8.0
- ext-pdo (`pdo_mysql` and/or `pdo_sqlite`)

## Installation

```bash
composer require or81/eloquent
```

Or clone the repository and register the `Or81\Eloquent\` namespace against `src/` in your own autoloader.

## Configuration

Configure once, at bootstrap:

```php
use Or81\Eloquent\NDB;

// MySQL
NDB::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'shop',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
]);

// SQLite (the file is created if it does not exist; ':memory:' also works)
NDB::configure([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/storage/database.sqlite',
]);
```

The connection is opened lazily, on the first query.

### Multiple connections

```php
NDB::configure([...], 'reporting');

NDB::connection('reporting')->select('select count(*) from events');
```

### An existing PDO instance

```php
$connection = new \Or81\Eloquent\Connection(['driver' => 'mysql']);
$connection->setPdo($yourPdo);

NDB::setConnection($connection);
```

## Getting a builder

```php
NDB::table('users')->get();
```

Or extend `NDB` and let the table name be derived from the class name:

```php
class BlogPost extends NDB {}          // -> blog_posts
class Person extends NDB {}            // -> people
class Invoice extends NDB {
    protected static string $table = 'legacy_invoices';
}

BlogPost::query()->where('published', 1)->get();
```

## Selecting

```php
NDB::table('users')->get();
NDB::table('users')->select('id', 'name')->get();
NDB::table('users')->distinct()->select('role')->get();
NDB::table('users', 'u')->select('u.name as author')->get();

NDB::table('users')->selectRaw('count(*) as total, max(votes) as top')->first();

// Correlated sub-select
NDB::table('users')->select('name')->selectSub(
    NDB::table('orders')->selectRaw('count(*)')->whereColumn('orders.user_id', 'users.id'),
    'orders_count'
)->get();

// Sub-query as the FROM clause
NDB::table('x')->fromSub(NDB::table('users')->where('active', 1), 'active_users')->get();
```

### Single results

```php
NDB::table('users')->first();                    // ?array
NDB::table('users')->firstOrFail();              // throws when nothing matches
NDB::table('users')->find(3);                    // by primary key
NDB::table('users')->where('id', 3)->value('email');
NDB::table('users')->pluck('name');              // ['Alice', 'Bob']
NDB::table('users')->pluck('name', 'id');        // [1 => 'Alice', 2 => 'Bob']
NDB::table('users')->where('role', 'admin')->exists();
NDB::table('users')->where('role', 'ghost')->doesntExist();
```

## Where clauses

```php
->where('votes', '>', 100)
->where('name', 'John')                      // '=' is implied
->where(['name' => 'John', 'active' => 1])   // AND of every pair
->where([['votes', '>', 100], ['name', 'John']])

->orWhere('votes', '>', 50)
->whereNot('role', 'guest')
->orWhereNot('role', 'guest')

->whereIn('id', [1, 2, 3])
->whereNotIn('role', ['guest', 'banned'])
->whereNull('deleted_at')
->whereNotNull('email_verified_at')
->whereBetween('age', [18, 30])
->whereNotBetween('age', [18, 30])
->whereColumn('updated_at', '>', 'created_at')
->whereLike('name', '%jo%')
->whereNotLike('name', '%spam%')
->whereRaw('length(name) > ?', [5])

->whereDate('created_at', '2024-03-05')
->whereYear('created_at', 2024)
->whereMonth('created_at', 3)
->whereDay('created_at', 5)
->whereTime('created_at', '>', '09:00:00')
```

Every one of these has an `orWhere*` counterpart.

An operator the builder does not recognise throws rather than compiling into broken SQL, so `where('age', '=>', 5)` is caught at the call site. Reach for `whereRaw()` when you need something exotic.

### Grouped conditions

```php
NDB::table('users')
    ->where('role', 'user')
    ->where(function (Builder $query) {
        $query->where('votes', '>', 100)->orWhere('name', 'Bob');
    })
    ->get();
// where "role" = ? and ("votes" > ? or "name" = ?)
```

### Sub-query conditions

```php
->whereIn('id', function (Builder $query) {
    $query->from('orders')->select('user_id')->where('total', '>', 100);
})

->whereExists(function (Builder $query) {
    $query->from('orders')->whereColumn('orders.user_id', 'users.id');
})

->where('votes', '>', function (Builder $query) {
    $query->from('users')->selectRaw('avg(votes)');
})
```

## Joins

```php
NDB::table('users')
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->select('users.name', 'posts.title')
    ->get();

NDB::table('users')->join('posts', 'users.id', 'posts.user_id');   // '=' is implied
NDB::table('users')->leftJoin('posts', 'users.id', '=', 'posts.user_id');
NDB::table('users')->rightJoin('posts', 'users.id', '=', 'posts.user_id');
NDB::table('users')->crossJoin('colors');
```

Pass a closure for compound conditions. The closure receives a `JoinClause`, which is a full builder plus `on()` / `orOn()`:

```php
NDB::table('users')->join('posts', function (JoinClause $join) {
    $join->on('users.id', '=', 'posts.user_id')
         ->where('posts.published', 1);
})->get();
```

Sub-query joins:

```php
NDB::table('users')->joinSub(
    NDB::table('posts')->select('user_id')->selectRaw('count(*) as total')->groupBy('user_id'),
    'stats',
    'users.id',
    '=',
    'stats.user_id'
)->get();
```

## Grouping, ordering and limits

```php
->groupBy('role')
->groupByRaw('year(created_at)')
->having('total', '>', 2)
->havingBetween('total', [2, 10])
->havingRaw('count(*) > ?', [2])

->orderBy('name')
->orderByDesc('votes')
->orderByRaw('length(name) desc')
->latest()                   // order by created_at desc
->oldest()
->inRandomOrder()
->reorder()                  // drop every order

->limit(10)->offset(20)      // take() / skip() are aliases
->forPage(3, 20)
```

## Aggregates

```php
NDB::table('users')->count();
NDB::table('users')->count('email');       // count of non-null emails
NDB::table('users')->distinct()->count('role');
NDB::table('users')->max('votes');
NDB::table('users')->min('votes');
NDB::table('users')->sum('votes');
NDB::table('users')->avg('votes');
```

## Pagination and chunking

```php
$page = NDB::table('users')->orderBy('id')->paginate(perPage: 20, page: 3);
// ['data' => [...], 'total' => 57, 'per_page' => 20, 'current_page' => 3,
//  'last_page' => 3, 'from' => 41, 'to' => 57]

NDB::table('users')->orderBy('id')->chunk(200, function (array $rows, int $page) {
    // return false to stop early
});

NDB::table('users')->orderBy('id')->each(function (array $row) {
    // one row at a time, fetched 1000 at a time
});

// Stream without buffering the whole result set
foreach (NDB::table('logs')->cursor() as $row) {
    // ...
}
```

## Conditional clauses

```php
NDB::table('users')
    ->when($request['role'] ?? null, fn (Builder $q, $role) => $q->where('role', $role))
    ->unless($includeTrashed, fn (Builder $q) => $q->whereNull('deleted_at'))
    ->get();
```

## Inserting

```php
NDB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);

NDB::table('users')->insert([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);

$id = NDB::table('users')->insertGetId(['name' => 'Carol']);

// Skip rows that collide with a unique index
NDB::table('users')->insertOrIgnore([['email' => 'alice@example.com']]);

// Insert, or update the listed columns on collision
NDB::table('users')->upsert(
    [['email' => 'alice@example.com', 'votes' => 42]],
    ['email'],      // unique columns (required by SQLite, ignored by MySQL)
    ['votes']       // columns to update; defaults to every inserted column
);
```

## Updating and deleting

Both return the number of affected rows.

```php
NDB::table('users')->where('id', 1)->update(['name' => 'John Smith']);
NDB::table('users')->where('id', 1)->update(['votes' => NDB::raw('votes + 1')]);

NDB::table('users')->where('id', 1)->increment('votes');
NDB::table('users')->where('id', 1)->increment('votes', 5, ['updated_at' => date('c')]);
NDB::table('users')->where('id', 1)->decrement('votes');

NDB::table('users')->updateOrInsert(
    ['email' => 'john@example.com'],
    ['name' => 'John', 'votes' => 0]
);

NDB::table('users')->where('votes', '<', 1)->delete();
NDB::table('users')->delete(1);      // by primary key
NDB::table('users')->truncate();
```

`orderBy()` / `limit()` on an `update` or `delete` are MySQL-only. On SQLite the builder throws rather than silently dropping them.

## Transactions

```php
NDB::transaction(function () {
    NDB::table('users')->where('id', 1)->decrement('balance', 100);
    NDB::table('users')->where('id', 2)->increment('balance', 100);
});

// Retry up to 3 times on failure
NDB::transaction($callback, attempts: 3);

// Manual control
NDB::beginTransaction();
NDB::commit();
NDB::rollBack();
```

Nested `transaction()` calls use savepoints, so an inner rollback does not discard the outer work.

## Raw statements

```php
NDB::select('select * from users where votes > ?', [100]);
NDB::selectOne('select * from users where id = ?', [1]);
NDB::insert('insert into users (name) values (?)', ['John']);
NDB::update('update users set votes = ? where id = ?', [10, 1]);
NDB::delete('delete from users where id = ?', [1]);
NDB::statement('alter table users add column age integer');
NDB::unprepared('create table t (id integer)');       // DDL, no bindings
```

Use `NDB::raw()` for a fragment that must not be quoted or bound. Never build one from user input:

```php
NDB::table('users')->select(NDB::raw('count(*) as total'))->first();
```

## Debugging

```php
$query = NDB::table('users')->where('votes', '>', 100);

$query->toSql();        // select * from "users" where "votes" > ?
$query->getBindings();  // [100]
$query->toRawSql();     // bindings interpolated — for reading only
$query->dump();         // print and continue
$query->dd();           // print and exit

NDB::enableQueryLog();
NDB::table('users')->get();
NDB::getQueryLog();     // [['query' => ..., 'bindings' => [...], 'time' => 0.42]]
```

## Error handling

A failed statement throws `Or81\Eloquent\QueryException`, carrying the SQL and bindings that caused it:

```php
try {
    NDB::table('users')->insert(['nope' => 1]);
} catch (\Or81\Eloquent\QueryException $e) {
    $e->getSql();
    $e->getBindings();
    $e->getPrevious();   // the underlying PDOException
}
```

## Classes

| Class | Role |
| --- | --- |
| `NDB` | Entry point: configuration, connections, raw statements, transactions |
| `Builder` | The fluent query builder |
| `JoinClause` | The `ON` clause of a join; a `Builder` with `on()` / `orOn()` |
| `Grammar` | Compiles a builder into SQL for the active driver |
| `Connection` | PDO wrapper: lazy connect, bindings, transactions, query log |
| `Expression` | A raw SQL fragment |
| `QueryException` | A failed statement, with its SQL and bindings |

## Upgrading from the old `DB` class

`src/DB.php` has been removed and replaced by `NDB`. The differences that matter:

- **Values are bound, not interpolated.** The old `where()` built `... '$value'` directly into the SQL.
- **Configuration is external.** Credentials are passed to `NDB::configure()` instead of being hardcoded as private properties.
- **`update()` works.** The old one mixed `?` placeholders with named bindings and never executed.
- **`insertMultiple()` is gone**; `insert()` takes either one row or a list of rows, and writes all of them. The old version bound every row but executed once, so only the last survived.
- **Failures throw** `QueryException` instead of calling `die()`.
- `insert()` returns `bool`; use `insertGetId()` when you need the new id.
- `update()` and `delete()` return the affected row count instead of `void`.
- `get()` returns `[]` rather than `false` when nothing matches; `first()` returns `null`.
- The global `dd()` function is gone. Use `$query->dump()` / `$query->dd()`.

Automatic table naming is still there, and now handles `BlogPost` -> `blog_posts`, `Person` -> `people` and `Category` -> `categories`.

## License

MIT.

## Contact

[omidrajabi81@gmail.com](mailto:omidrajabi81@gmail.com)
