# PHP Query Builder

A lightweight, dependency-free SQL query builder for PHP with a Laravel-style fluent API. Supports MySQL and SQLite, and queries by [Jalali (Shamsi) date](#jalali-shamsi-dates) out of the box.

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

### From a `.env` file, with no setup

Put the credentials in a `.env` file next to your project and start querying. Nothing else is needed:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shop
DB_USERNAME=root
DB_PASSWORD=secret
```

```php
use Or81\Eloquent\NDB;

NDB::table('users')->get();     // already connected to shop
```

The file is found by looking in the working directory first, then walking up from the package, which reaches the project root when installed under `vendor/`. If [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) is installed it is used to load the file, exactly as an application would:

```php
Dotenv::createImmutable($directory)->safeLoad();
```

Without that package a small built-in reader parses the same file, so nothing has to be installed. Real environment variables (`$_ENV`, `$_SERVER`, `getenv`) always win over the file, which is what you want on a server where the credentials are injected rather than committed.

Recognised keys:

| Key | Meaning |
| --- | --- |
| `DB_CONNECTION` (or `DB_DRIVER`) | `mysql` or `sqlite` |
| `DB_HOST`, `DB_PORT`, `DB_SOCKET` | where the server is |
| `DB_DATABASE` | database name, or the SQLite file path |
| `DB_USERNAME`, `DB_PASSWORD` | credentials |
| `DB_CHARSET`, `DB_COLLATION` | MySQL character set |
| `DB_DATE_STORAGE`, `DB_DATE_FORMAT` | the [Jalali storage](#telling-the-builder-how-a-column-is-stored) default for this connection |

SQLite needs only two:

```ini
DB_CONNECTION=sqlite
DB_DATABASE=storage/database.sqlite
```

Point at a `.env` somewhere else — a directory or the file itself — before the first query:

```php
NDB::useEnv(__DIR__ . '/config');
```

To see what the environment actually produced:

```php
NDB::envConfig();          // the config array, or null when .env says nothing
Or81\Eloquent\Env::loadedFrom();   // which file it came from
```

### Overriding it by hand

`NDB::configure()` sits on top of whatever `.env` gave you, so you can change one key and leave the rest alone:

```php
NDB::configure(['database' => 'shop_test']);      // same host and credentials, different database
```

Or ignore the environment entirely and state everything:

```php
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

A second connection is configured by name. In `.env`, prefix its keys with that name:

```ini
REPORTING_DB_CONNECTION=mysql
REPORTING_DB_DATABASE=analytics
```

```php
NDB::configure([...], 'reporting');    // or leave it to the prefixed .env keys

NDB::connection('reporting')->select('select count(*) from events');
NDB::table('events')->getConnection();
```

Only the default connection falls back to the unprefixed `DB_*` keys, so a typo in a connection name fails loudly instead of quietly pointing at the main database.

### An existing PDO instance

```php
$connection = new \Or81\Eloquent\Connection(['driver' => 'mysql']);
$connection->setPdo($yourPdo);

NDB::setConnection($connection);
```

### Managing connections

```php
NDB::connection();                 // the Connection object
NDB::getPdo();                     // the underlying PDO
NDB::setDefaultConnection('reporting');
NDB::getDefaultConnection();
NDB::purge('reporting');           // drop the connection, keep its config
NDB::forget('reporting');          // drop both, so .env is read again
```

## Getting a builder

There are two ways in, and they return the same query builder. Pick whichever suits the job.

**A table, directly.** Rows come back as `Row` objects, so columns are read as properties.

```php
$user = NDB::table('users')->where('active', 1)->first();

$user->name;
$user->id;
```

**A model.** Everything about the table is declared on a class, and rows come back as objects with casts applied. This is the [`Model`](#models) half of the package.

```php
class User extends Model
{
    protected array $fillable = ['name', 'email'];
    protected array $casts = ['is_active' => 'bool'];
}

User::where('is_active', 1)->get();
// [User, User, ...]
```

Both accept exactly the same clauses, so nothing in the rest of this file changes between them.

## Models

A `Model` describes one table: its name, its key, which columns may be filled, how each column is typed, and how its dates are stored. Extend it and you get the query builder, attribute casting, timestamps, soft deletes and scopes.

```php
use Or81\Eloquent\Model;
use Or81\Eloquent\ModelBuilder;
use Or81\Eloquent\Jalali;

class Order extends Model
{
    protected array $fillable = ['customer_id', 'code', 'status', 'total', 'meta', 'issued_at'];

    protected array $casts = [
        'total'     => 'int',
        'meta'      => 'array',
        'is_paid'   => 'bool',
        'issued_at' => 'jalali',
    ];

    protected array $dateStorage = [
        'issued_at' => [Jalali::JALALI, 'Y/m/d'],
    ];

    protected bool $softDeletes = true;

    public function customer(): ModelBuilder
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopePaid(ModelBuilder $query): void
    {
        $query->where('status', 'paid');
    }
}
```

```php
$order = Order::create([
    'customer_id' => 1,
    'code'        => 'ORD-1001',
    'status'      => 'paid',
    'total'       => '250000',
    'meta'        => ['gift' => true],
    'issued_at'   => '1403/05/26',
]);

$order->total;                       // 250000, an int
$order->meta['gift'];                // true, decoded from JSON
$order->issued_at->format('l j F');  // جمعه 26 مرداد, a Jalali object

Order::paid()->whereJalaliThisMonth('issued_at')->get();
```

### What you declare

| Property | Default | What it does |
| --- | --- | --- |
| `$table` | from the class name | `BlogPost` → `blog_posts`, `Person` → `people` |
| `$connection` | `null` | the named connection this model lives on |
| `$primaryKey` | `'id'` | |
| `$keyType` | `'int'` | `'string'` for a UUID |
| `$incrementing` | `true` | `false` when you supply the key yourself |
| `$fillable` | `[]` | the columns `create()` and `fill()` accept |
| `$guarded` | `['*']` | consulted only when `$fillable` is empty |
| `$casts` | `[]` | column → type, see below |
| `$dateStorage` | `[]` | how date columns are stored, see [Jalali storage](#telling-the-builder-how-a-column-is-stored) |
| `$hidden` | `[]` | columns left out of `toArray()` / `toJson()` |
| `$visible` | `[]` | when set, the only columns kept |
| `$appends` | `[]` | accessor-backed values added to the output |
| `$timestamps` | `true` | maintain `created_at` and `updated_at` |
| `$softDeletes` | `false` | turn `delete()` into a `deleted_at` stamp |

Nothing is mass assignable until you list it in `$fillable`. If every attribute of a `create()` call is rejected the model says so rather than quietly writing a blank row:

```
LogicException: No attribute of [Order] may be mass assigned. Add the columns to
its $fillable list, or use forceFill() to bypass the check.
```

### Casts

```php
protected array $casts = [
    'total'      => 'int',          // also: integer
    'rate'       => 'float',        // also: double, real
    'price'      => 'decimal:2',    // a string with two places
    'code'       => 'string',
    'is_paid'    => 'bool',         // also: boolean
    'meta'       => 'array',        // JSON in the column, an array in PHP
    'payload'    => 'json',         // the same thing
    'settings'   => 'object',
    'seen_at'    => 'timestamp',    // an int
    'birthday'   => 'date',         // DateTimeImmutable at midnight
    'created_at' => 'datetime',     // DateTimeImmutable
    'issued_at'  => 'jalali',       // Jalali
];
```

A cast runs both ways. `array` is encoded to JSON when written and decoded when read; `jalali` accepts a string, an array, a `Jalali` or a `DateTimeInterface` and writes whatever `$dateStorage` says the column holds. An unknown cast name throws rather than being ignored.

### Accessors and mutators

```php
class Order extends Model
{
    protected array $appends = ['summary'];

    public function getSummaryAttribute(): string       // $order->summary
    {
        return $this->code . ' (' . $this->status . ')';
    }

    public function setCodeAttribute($value): void      // $order->code = 'ord-1'
    {
        $this->attributes['code'] = strtoupper((string) $value);
    }
}
```

An accessor for a real column receives the cast value and may reshape it. One with no column behind it is computed on the fly; list it in `$appends` to have it show up in `toArray()`.

### Scopes

A `scopeSomething()` method on the model becomes `Something()` on the query:

```php
public function scopeActive(ModelBuilder $query): void
{
    $query->where('is_active', 1);
}

public function scopeInCity(ModelBuilder $query, string $city): void
{
    $query->where('city', $city);
}
```

```php
Customer::active()->inCity('تهران')->orderBy('name')->get();
```

Calling a method that is neither a builder method nor a scope names the scope you would have to write.

### Reading and writing

```php
Order::all();
Order::find(1);
Order::findOrFail(1);                  // ModelNotFoundException when missing
Order::findOr(1, fn () => $default);
Order::where('status', 'paid')->first();
Order::where('status', 'paid')->firstOrFail();
Order::where('status', 'paid')->firstOr(fn () => $default);
Order::query()->firstWhere('status', 'paid');
Order::where('code', 'ORD-1')->sole();  // exactly one match, or it is an error
Order::count();
Order::query()->paginate(20, $page);   // 'data' holds models
Order::query()->cursor();              // streams models
Order::query()->chunkById(200, fn (array $orders) => ...);
Order::query()->toBase();              // plain arrays instead
```

```php
Order::create([...]);
Order::query()->createMany([[...], [...]]);      // one transaction
Order::query()->forceCreate([...]);              // ignores $fillable
Order::query()->firstOrNew(['code' => 'X']);     // not saved
Order::query()->firstOrCreate(['code' => 'X'], ['status' => 'pending']);
Order::query()->updateOrCreate(['code' => 'X'], ['status' => 'paid']);
Order::query()->createOrUpdate(['code' => 'X'], ['status' => 'paid']);

Order::destroy(1);                     // delete by key, without loading first
Order::destroy(1, 2, 3);
Order::destroy([1, 2, 3]);
```

```php
$order = Order::find(1);

$order->status = 'shipped';
$order->save();                        // writes only what changed

$order->update(['status' => 'shipped']);
$order->delete();
$order->refresh();                     // re-read into this instance
$fresh = $order->fresh();              // re-read into a new one

$order->isDirty();                     // anything changed since it was loaded?
$order->isDirty('status');
$order->getDirty();                    // ['status' => 'shipped']
$order->getOriginal('status');         // what it was when loaded
$order->getAttributes();               // the raw column values

$order->wasChanged();                  // did the last save() write anything?
$order->wasChanged('status');
$order->getChanges();                  // what it wrote

$order->touch();                       // bump updated_at and nothing else
$order->only(['code', 'total']);       // a subset, cast as usual
$order->except(['meta']);
$order->is($other);                    // same row?
$order->isNot($other);
$copy = $order->replicate();           // an unsaved copy, without the key or timestamps
```

`save()` writes only the changed columns, and does nothing at all when nothing changed. `$timestamps` keeps `created_at` and `updated_at` current, and respects `$dateStorage` — a project that stores Jalali text gets Jalali timestamps.

### Soft deletes

```php
protected bool $softDeletes = true;
```

```php
$order->delete();          // sets deleted_at
$order->trashed();         // true
Order::find(1);            // null: soft-deleted rows are filtered out
Order::withTrashed()->find(1);
Order::onlyTrashed()->get();
$order->restore();
$order->forceDelete();     // really gone

Order::onlyTrashed()->where('status', 'cancelled')->restore();
Order::withTrashed()->where('total', 0)->forceDelete();
```

### Relations

Each relation returns a query, so finish it yourself. Eager loading is not included: this is a query builder with a model layer on top, not a full ORM.

```php
class Customer extends Model
{
    public function orders(): ModelBuilder
    {
        return $this->hasMany(Order::class);            // orders.customer_id
    }
}

class Order extends Model
{
    public function customer(): ModelBuilder
    {
        return $this->belongsTo(Customer::class);       // orders.customer_id
    }
}
```

```php
$customer->orders()->get();
$customer->orders()->where('status', 'paid')->sum('total');
$order->customer()->first();
$customer->hasOne(Order::class)->first();
```

Keys are guessed from the class names and can be given explicitly: `hasMany(Order::class, 'buyer_id', 'id')`.

### Output

```php
$order->toArray();     // casts applied, $hidden removed, $appends added
$order->toJson();      // the same, JSON encoded with readable Unicode
json_encode($order);   // works: the model is JsonSerializable
$order['code'];        // works: the model is ArrayAccess
```

`Jalali` and `DateTimeInterface` values become strings on the way out, so the result is always JSON-safe.

### Or skip the model

If you want the table-name inference without any of the above, extend `NDB` instead. You get a plain query builder returning `Row` objects:

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

### Results are objects

`get()` returns a list of `Row` objects and `first()`, `find()`, `findOrFail()` and `sole()` return one, so columns are read as properties:

```php
$user = NDB::table('users')->find(3);

$user->name;
$user->email;

foreach (NDB::table('users')->get() as $user) {
    echo $user->name;
}
```

Array access still works, so nothing written against the older array results has to change:

```php
$user['name'];
foreach ($user as $column => $value) { ... }
count($user);
json_encode($user);
```

A `Row` also has:

```php
$user->toArray();                  // the plain associative array
$user->toJson();
$user->get('nickname', 'none');    // with a default
$user->has('nickname');            // present, even if null
$user->only(['id', 'name']);
$user->except(['password']);
$user->keys();
$user->values();
```

A column that is not in the result reads as `null`; use `has()` to tell an absent column from one that is really null.

When you want plain arrays after all — for `array_column`, a CSV writer, or an assertion — ask for them:

```php
NDB::table('users')->toBase();     // [['id' => 1, 'name' => 'Alice'], ...]
```

`pluck()`, `value()` and the aggregates were never rows, and are unchanged.

### Single results

```php
NDB::table('users')->first();                    // ?Row
NDB::table('users')->firstOrFail();              // throws RecordNotFoundException
NDB::table('users')->firstOr(fn () => $default);
NDB::table('users')->firstWhere('role', 'admin');
NDB::table('users')->sole();                     // exactly one match, or it is an error
NDB::table('users')->find(3);                    // by primary key
NDB::table('users')->findOrFail(3);
NDB::table('users')->findOr(3, fn () => $default);
NDB::table('users')->where('id', 3)->value('email');
NDB::table('users')->pluck('name');              // ['Alice', 'Bob']
NDB::table('users')->pluck('name', 'id');        // [1 => 'Alice', 2 => 'Bob']
NDB::table('users')->implode('name', ', ');      // 'Alice, Bob'
NDB::table('users')->where('role', 'admin')->exists();
NDB::table('users')->where('role', 'ghost')->doesntExist();
```

`find()`, `findOrFail()`, `create()` and `delete($id)` look rows up by `id`. Point them at a different key once:

```php
NDB::table('settings')->keyName('setting_key')->find('theme');
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

### One condition, several columns

The search-box case. Each of these is compiled as a single group, so it cannot leak into the clauses around it:

```php
->whereAny(['name', 'email', 'phone'], 'like', "%{$term}%")   // any column matches
->whereAll(['name', 'email'], 'like', "%{$term}%")            // every column matches
->whereNone(['name', 'email'], 'like', "%{$term}%")           // no column matches
```

```sql
where "role" = ? and ("name" like ? or "email" like ? or "phone" like ?)
```

`orWhereAny()` and `orWhereAll()` join the group on with `or`.

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

## Jalali (Shamsi) dates

Query with Persian dates against columns that hold whatever your schema already holds. Nothing needs to change in the database, and no Jalali support is needed from MySQL or SQLite.

```php
NDB::table('orders')->whereJalaliDate('created_at', '1403/05/26')->get();
NDB::table('orders')->whereJalaliBetween('created_at', ['1403/01/01', '1403/06/31'])->get();
NDB::table('orders')->whereJalaliThisMonth('created_at')->count();
```

Every clause compiles to a half-open range over the plain column, so an index on it is still used:

```sql
where ("created_at" >= '2024-08-16' and "created_at" < '2024-08-17')
```

The column is never wrapped in a function, which is what makes this different from `whereDate()` / `whereYear()`.

### Telling the builder how a column is stored

Two things vary independently: the calendar (Gregorian or Jalali) and the column type (`date`, `datetime`, `varchar`, `timestamp`). What the builder actually needs to know is which value to compare against, so there are three storage modes:

| Mode | Fits | Compared against |
| --- | --- | --- |
| `Jalali::GREGORIAN` | `date`, `datetime`, MySQL `timestamp`, or a `varchar` holding `2024-08-16` | `'2024-08-16'` |
| `Jalali::JALALI` | a `varchar` (or `date`) holding `1403/05/26` | `'1403/05/26'` |
| `Jalali::UNIX` | an integer column holding seconds since the epoch | `1723800000` |

`Jalali::GREGORIAN` is the default. Declare anything else:

```php
NDB::table('invoices')
    ->dateStorage('issued_at', Jalali::JALALI)             // 1403/05/26
    ->dateStorage('paid_at', Jalali::JALALI, 'Y-m-d')      // 1403-05-26
    ->dateStorage('logged_at', Jalali::UNIX)
    ->whereJalaliMonth('issued_at', 1403, 5)
    ->get();
```

Per model, so you only say it once:

```php
class Invoice extends NDB
{
    protected static array $dateStorage = [
        'issued_at' => [Jalali::JALALI, 'Y-m-d'],
        'logged_at' => Jalali::UNIX,
    ];
}

Invoice::query()->whereJalaliThisYear('issued_at')->get();
```

Per connection, when a whole legacy database stores Jalali text:

```php
NDB::configure([...,  'date_storage' => Jalali::JALALI, 'date_format' => 'Y/m/d']);
```

Or let the builder work it out by reading one stored value. A Jalali year lands in 1000–1699 and a Gregorian one does not, which is what separates the two. The result is cached for the rest of the process, so the probe runs once per column:

```php
NDB::table('invoices')->detectDateStorage('issued_at')->whereJalaliToday('issued_at')->get();
```

Range comparison relies on the stored text sorting chronologically, so a `varchar` must hold a zero-padded year-month-day value. A format like `Y/n/j` (`1403/5/6`) or `d/m/Y` is refused with an explanation rather than silently returning wrong rows.

### The clauses

```php
->whereJalali('created_at', '1403/05/26')            // that whole day
->whereJalali('created_at', '>=', '1403/05/26')      // also > < <= != <>
->whereJalaliDate('created_at', '1403/05/26')
->whereJalaliBetween('created_at', ['1403/01/01', '1403/06/31'])   // both ends inclusive
->whereJalaliNotBetween('created_at', ['1403/01/01', '1403/06/31'])
->whereJalaliYear('created_at', 1403)
->whereJalaliMonth('created_at', 1403, 5)            // the year is required: a month alone is not a range
->whereJalaliWeek('created_at', '1403/05/24')        // the Saturday-to-Friday week containing that day

->whereJalaliToday('created_at')
->whereJalaliYesterday('created_at')
->whereJalaliTomorrow('created_at')
->whereJalaliThisWeek('created_at')
->whereJalaliThisMonth('created_at')
->whereJalaliLastMonth('created_at')
->whereJalaliThisYear('created_at')
->whereJalaliLastDays('created_at', 7)               // today and the six days before it
```

`whereJalali`, `whereJalaliDate`, `whereJalaliBetween`, `whereJalaliYear` and `whereJalaliMonth` each have an `orWhereJalali*` counterpart. Values may be a string (`'1403/05/26'`, `'1403-5-26'`, Persian digits), an array (`[1403, 5, 26]`), a `Jalali`, or a `DateTimeInterface`.

Ordering needs nothing special: all three storage modes already sort chronologically, so `orderBy('created_at')` is correct as it stands.

### Jalali output

```php
NDB::table('orders')
    ->castJalali('created_at', 'l j F Y ساعت H:i')
    ->whereJalaliToday('created_at')
    ->get();
// created_at => 'جمعه 26 مرداد 1403 ساعت 14:30'
```

### The `Jalali` class

A self-contained immutable date, modelled on [verta](https://github.com/hekmatinasser/verta)'s API but with no dependency on it. Every mutator returns a new instance.

```php
use Or81\Eloquent\Jalali;

Jalali::now();
Jalali::today();
Jalali::create(1403, 5, 26);
Jalali::parse('1403/05/26 14:30');       // slashes, dashes, Persian or Arabic digits
Jalali::fromGregorian('2024-08-16');     // string, DateTimeInterface or timestamp
Jalali::fromTimestamp(1723800000);

$date = Jalali::parse('1403/05/26');

$date->year();  $date->month();  $date->day();
$date->dayOfWeek();      // 0 = شنبه
$date->dayOfYear();      // 150
$date->daysInMonth();    // 31
$date->isLeapYear();     // true
$date->monthName();      // مرداد
$date->dayName();        // جمعه

$date->toDateString();               // 1403/05/26
$date->toGregorianDateString();      // 2024-08-16
$date->toGregorian();                // DateTimeImmutable
$date->toTimestamp();
$date->format('l j F Y');            // جمعه 26 مرداد 1403

$date->addDays(10);  $date->subDays(10);
$date->addMonths(2); $date->addYears(1);        // the day is clamped to a shorter month
$date->startOfWeek(); $date->endOfWeek();       // Saturday .. Friday
$date->startOfMonth(); $date->endOfMonth();
$date->startOfYear(); $date->endOfYear();

$date->lessThan($other); $date->greaterThan($other); $date->equalTo($other);

Jalali::toPersianDigits('1403/05/26');   // ۱۴۰۳/۰۵/۲۶
Jalali::toEnglishDigits('۱۴۰۳/۰۵/۲۶');   // 1403/05/26
```

`format()` accepts `Y y m n d j H G i s F M l D N w t L z a A U`, and a backslash escapes the next character. `z` is the 1-based day of the year.

A verta instance can be handed to any of these directly — `Jalali::parse($verta)` picks up its `datetime()`.

`Jalali::now()`, `today()` and the `UNIX` storage mode all read PHP's default timezone, so set it once at bootstrap:

```php
date_default_timezone_set('Asia/Tehran');
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

NDB::table('users')->orderBy('id')->each(function (Row $row) {
    // one row at a time, fetched 1000 at a time
});

// Stream without buffering the whole result set
foreach (NDB::table('logs')->cursor() as $row) {
    echo $row->message;
}
```

`chunk()` pages with `limit`/`offset`, so rows the callback deletes or reorders shift the pages still to come. When the callback writes to the same table, page by key instead:

```php
NDB::table('users')->chunkById(200, function (array $rows) {
    foreach ($rows as $row) {
        NDB::table('users')->where('id', $row->id)->update(['migrated' => 1]);
    }
});
```

`chunkById` walks in key order and remembers where it got to, so nothing is skipped or seen twice. Pass a column name as the third argument when the key is not `id`.

## Conditional clauses

```php
NDB::table('users')
    ->when($request['role'] ?? null, fn (Builder $q, $role) => $q->where('role', $role))
    ->unless($includeTrashed, fn (Builder $q) => $q->whereNull('deleted_at'))
    ->get();
```

## Inserting

### Getting the row back

`create()` inserts one row and reads it back, so the generated key and any column defaults the database filled in are there straight away:

```php
$user = NDB::table('users')->create(['name' => 'John', 'email' => 'john@example.com']);

$user->id;          // 4, from the database
$user->role;        // 'user', the column default
```

```php
// Several at once, in one transaction: if any row fails, none of them land
$users = NDB::table('users')->createMany([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);

// Find it or make it
NDB::table('users')->firstOrCreate(
    ['email' => 'john@example.com'],     // what to match on
    ['name' => 'John', 'votes' => 0]     // what to add when creating
);

// Update it or make it. Either way the stored row comes back.
NDB::table('users')->updateOrCreate(
    ['email' => 'john@example.com'],
    ['votes' => 42]
);

NDB::table('users')->createOrUpdate([...], [...]);   // the same method, other name
```

### Plain inserts

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

// Copy rows in from another query, without pulling them through PHP
NDB::table('archived_orders')->insertUsing(
    ['code', 'total'],
    NDB::table('orders')->select('code', 'total')->where('status', 'done')
);
```

Every row of a multi-row `insert()` must carry the same columns. One that does not is reported by position, rather than becoming a `VALUES` list the driver rejects with a vaguer message.

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

## Worked examples

Every snippet below is executed by `tests/examples_test.php`, so they are all known to run.

### A filtered, paginated listing

The classic search page: several optional filters, a joined table, a Jalali date range, and a page of results. `when()` keeps the optional filters from turning into a pile of `if` statements.

```php
function searchOrders(array $filters, int $page = 1): array
{
    return NDB::table('orders')
        ->join('customers', 'orders.customer_id', '=', 'customers.id')
        ->select('orders.code', 'orders.total', 'orders.status', 'customers.name')
        ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where(function (Builder $inner) use ($term) {
            $inner->whereLike('customers.name', "%{$term}%")
                  ->orWhereLike('orders.code', "%{$term}%");
        }))
        ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->whereIn('orders.status', (array) $status))
        ->when($filters['city'] ?? null, fn (Builder $q, $city) => $q->where('customers.city', $city))
        ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereJalali('orders.created_at', '>=', $from))
        ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereJalali('orders.created_at', '<=', $to))
        ->orderByDesc('orders.created_at')
        ->paginate(20, $page);
}

searchOrders([]);                                              // everything, page 1
searchOrders(['status' => 'paid', 'city' => 'تهران']);
searchOrders(['q' => 'مریم']);
searchOrders(['from' => '1403/05/26', 'to' => '1403/06/02', 'status' => ['paid', 'cancelled']]);
```

The `q` filter is wrapped in its own closure on purpose: without it the `orWhereLike` would escape the group and pull in rows the other filters meant to exclude.

### Dashboard counters

```php
$stats = [
    'today' => NDB::table('orders')->whereJalaliToday('created_at')->count(),
    'this_week' => NDB::table('orders')->whereJalaliThisWeek('created_at')->count(),
    'this_month_revenue' => (int) NDB::table('orders')
        ->where('status', 'paid')
        ->whereJalaliThisMonth('created_at')
        ->sum('total'),
    'has_pending' => NDB::table('orders')->where('status', 'pending')->exists(),
];
```

### A sales report grouped into Jalali months

Neither MySQL nor SQLite knows what a Jalali month is, so group by day in SQL — both have a `date()` function — and fold the days into months in PHP. One query, correct boundaries.

```php
$daily = NDB::table('orders')
    ->selectRaw('date(created_at) as day, sum(total) as total, count(*) as orders')
    ->where('status', 'paid')
    ->whereJalaliYear('created_at', 1403)
    ->groupByRaw('date(created_at)')
    ->get();

$byMonth = [];

foreach ($daily as $row) {
    $month = Jalali::fromGregorian($row->day)->format('Y/m');

    $byMonth[$month]['total'] = ($byMonth[$month]['total'] ?? 0) + $row->total;
    $byMonth[$month]['orders'] = ($byMonth[$month]['orders'] ?? 0) + $row->orders;
}

// ['1403/05' => ['total' => 1010000, ...], '1403/06' => [...], '1403/07' => [...]]
```

### Top customers, by a correlated sub-select

```php
$top = NDB::table('customers', 'c')
    ->select('c.name')
    ->selectSub(
        NDB::table('orders')
            ->selectRaw('coalesce(sum(total), 0)')
            ->whereColumn('orders.customer_id', 'c.id')
            ->where('status', 'paid'),
        'revenue'
    )
    ->where('c.is_active', 1)
    ->orderByDesc('revenue')
    ->limit(10)
    ->get();

// [['name' => 'زهرا موسوی', 'revenue' => 825000], ...]
```

### Only customers who ordered this Jalali year

`whereExists` keeps this to one query, with no join and no duplicate rows to clean up.

```php
$active = NDB::table('customers')
    ->whereExists(function (Builder $query) {
        $query->from('orders')
              ->whereColumn('orders.customer_id', 'customers.id')
              ->where('status', 'paid')
              ->whereJalaliYear('created_at', 1403);
    })
    ->orderBy('id')
    ->pluck('name');
```

### A write batch inside a transaction

`insertGetId` gives you the new id for the child rows, and the whole thing rolls back together if anything throws.

```php
function placeOrder(int $customerId, array $items): int
{
    return NDB::transaction(function () use ($customerId, $items) {
        $total = array_sum(array_map(fn ($item) => $item['quantity'] * $item['price'], $items));

        $orderId = NDB::table('orders')->insertGetId([
            'customer_id' => $customerId,
            'code' => 'ORD-' . str_pad((string) (NDB::table('orders')->max('id') + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'pending',
            'total' => $total,
            'created_at' => Jalali::now()->toGregorianDateTimeString(),
        ]);

        NDB::table('order_items')->insert(array_map(
            fn ($item) => $item + ['order_id' => $orderId],
            $items
        ));

        return $orderId;
    });
}

$orderId = placeOrder(2, [
    ['product' => 'هدفون', 'quantity' => 2, 'price' => 150000],
    ['product' => 'کابل', 'quantity' => 3, 'price' => 20000],
]);
```

Throwing anywhere inside the closure rolls the whole batch back:

```php
try {
    NDB::transaction(function () {
        NDB::table('orders')->insert([...]);

        throw new RuntimeException('payment gateway refused');
    });
} catch (RuntimeException $e) {
    // nothing was written
}
```

### A legacy table that stores Jalali text

`issue_date` is a `varchar` holding `1403/05/26`. Let the builder work that out and then query it like any other date column.

```php
$invoices = NDB::table('legacy_invoices')
    ->detectDateStorage('issue_date')
    ->whereJalaliBetween('issue_date', ['1403/05/26', '1403/06/02'])
    ->orderBy('issue_date')
    ->pluck('amount', 'number');

// ['F-901' => 500000, 'F-902' => 320000]
```

Or state it, and skip the probe:

```php
NDB::table('legacy_invoices')
    ->dateStorage('issue_date', Jalali::JALALI)
    ->whereJalaliMonth('issue_date', 1403, 6)
    ->pluck('number');
```

### Streaming an export

`cursor()` fetches one row at a time instead of loading the table into memory, and `castJalali` converts the dates on the way past.

```php
$query = NDB::table('orders')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->select('orders.code', 'orders.total', 'orders.created_at', 'customers.name')
    ->castJalali('created_at', 'Y/m/d')
    ->where('orders.status', 'paid')
    ->orderBy('orders.id');

$handle = fopen('orders.csv', 'w');

foreach ($query->cursor() as $row) {
    fputcsv($handle, [$row->code, $row->name, $row->created_at, $row->total]);
}

fclose($handle);
// ORD-1001,علی رضایی,1403/05/26,250000
```

### Bulk updates

```php
// Cancel everything still pending from before Mehr
NDB::table('orders')
    ->where('status', 'pending')
    ->whereJalali('created_at', '<', '1403/07/01')
    ->update(['status' => 'cancelled', 'archived_at' => Jalali::now()->toGregorianDateTimeString()]);

// Adjust a counter without reading it first
NDB::table('orders')->where('code', 'ORD-1001')->increment('total', 5000);

// Insert a daily rollup, or update it if the day is already there
NDB::table('daily_totals')->upsert(
    [['day' => '1403/05/26', 'total' => 250]],
    ['day'],
    ['total']
);
```

### Walking a large table

```php
NDB::table('orders')->orderBy('id')->chunk(500, function (array $rows, int $page) {
    foreach ($rows as $row) {
        // ...
    }
});
```

## Testing

The package ships its own suite, with no PHPUnit or other dependency to install:

```bash
php tests/run.php
```

```
builder_api_test            35 passed    0 failed
compile_test               126 passed    0 failed
connection_test             74 passed    0 failed
edge_cases_test             20 passed    0 failed
env_test                    40 passed    0 failed
examples_test               28 passed    0 failed
grammar_test                66 passed    0 failed
jalali_api_test             29 passed    0 failed
jalali_calendar_test        97 passed    0 failed
jalali_query_test          122 passed    0 failed
model_api_test              72 passed    0 failed
model_test                 160 passed    0 failed
row_test                   115 passed    0 failed
where_variants_test         47 passed    0 failed
----------------------------------------------------------
total                     1031 passed    0 failed
```

Every public method of every class is exercised.

Run one group, or show every assertion:

```bash
php tests/run.php jalali
```

```bash
php tests/run.php -v
```

A single suite is a plain script, so it can be run on its own:

```bash
php tests/jalali_calendar_test.php
```

Every suite uses an in-memory SQLite database and runs in its own process, so nothing has to be set up and nothing leaks between them. What each one covers:

| Suite | Covers |
| --- | --- |
| `compile_test` | SQL generation for both drivers, then the same queries against a real database |
| `builder_api_test` | the reading, writing and aggregate methods end to end |
| `where_variants_test` | every `or*` / `not*` clause, having, joins, unions, locks, debug output |
| `edge_cases_test` | operator validation, binding order, state isolation, empty and boundary inputs |
| `grammar_test` | identifier wrapping, every compile method, `Expression`, `QueryException` |
| `connection_test` | raw statements, transactions and savepoints, the query log, named connections |
| `env_test` | `.env` parsing, precedence, per-connection prefixes, `configure()` overrides |
| `jalali_calendar_test` | the calendar maths against known dates, formatting, arithmetic, the Persian week |
| `jalali_query_test` | every Jalali clause against all four column shapes |
| `jalali_api_test` | the Jalali examples in this file |
| `model_test` | casts, mass assignment, timestamps, soft deletes, scopes, relations |
| `model_api_test` | the model examples in this file |
| `row_test` | results as objects, and the create/find helpers on both builders |
| `examples_test` | the worked examples in this file |

## Classes

| Class | Role |
| --- | --- |
| `NDB` | Entry point: configuration, connections, raw statements, transactions |
| `Model` | Eloquent-style base model: casts, timestamps, soft deletes, scopes |
| `ModelBuilder` | The query a model hands out; returns models instead of rows |
| `Builder` | The fluent query builder |
| `Row` | One result row, read as properties or as an array |
| `JoinClause` | The `ON` clause of a join; a `Builder` with `on()` / `orOn()` |
| `Jalali` | An immutable Jalali date, plus the calendar maths and storage detection |
| `Grammar` | Compiles a builder into SQL for the active driver |
| `Connection` | PDO wrapper: lazy connect, bindings, transactions, query log |
| `Env` | Reads `.env`, with phpdotenv when it is installed |
| `Str` | The name conversions behind table and accessor resolution |
| `Expression` | A raw SQL fragment |
| `QueryException` | A failed statement, with its SQL and bindings |
| `RecordNotFoundException` | Thrown by the builder's `findOrFail()`, `firstOrFail()` and `sole()` |
| `ModelNotFoundException` | The same thing from a model; extends `RecordNotFoundException` |

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

### Rows became objects

`get()`, `first()`, `find()` and `cursor()` used to hand back associative arrays and now hand back [`Row`](#results-are-objects) objects. `Row` implements `ArrayAccess`, `IteratorAggregate`, `Countable` and `JsonSerializable`, so the things usually done with those arrays keep working unchanged:

```php
$row['name'];                    // still fine
foreach ($row as $col => $val)   // still fine
count($row);                     // still fine
json_encode($row);               // still fine
```

Two things do change:

- A strict comparison against an array (`$rows === [['id' => 1]]`) no longer holds. Call `->toArray()`, or ask the query for `toBase()`.
- Functions that require a real array — `array_column($rows, 'name')`, `array_map` over the columns of one row — need `toBase()` or `toArray()` first.

`pluck()`, `value()`, the aggregates, and `Connection::select()` were never rows and are untouched.

## License

MIT. See [LICENSE](LICENSE).

## Contact

[omidrajabi81@gmail.com](mailto:omidrajabi81@gmail.com)
