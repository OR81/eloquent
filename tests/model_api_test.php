<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Jalali;
use Or81\Eloquent\Model;
use Or81\Eloquent\ModelBuilder;
use Or81\Eloquent\ModelNotFoundException;
use Or81\Eloquent\NDB;

/**
 * Every model snippet printed in the README, run exactly as written.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table users (id integer primary key autoincrement, name text, email text,
    is_active integer default 1, created_at text, updated_at text)');
NDB::unprepared('create table customers (id integer primary key autoincrement, name text, city text,
    is_active integer default 1, created_at text, updated_at text)');
NDB::unprepared('create table orders (id integer primary key autoincrement, customer_id integer,
    code text, status text, total integer, meta text, is_paid integer default 0, issued_at text,
    created_at text, updated_at text, deleted_at text)');

class User extends Model
{
    protected array $fillable = ['name', 'email'];
    protected array $casts = ['is_active' => 'bool'];
}

class Customer extends Model
{
    protected array $fillable = ['name', 'city', 'is_active'];

    public function orders(): ModelBuilder
    {
        return $this->hasMany(Order::class);            // orders.customer_id
    }

    public function scopeActive(ModelBuilder $query): void
    {
        $query->where('is_active', 1);
    }

    public function scopeInCity(ModelBuilder $query, string $city): void
    {
        $query->where('city', $city);
    }
}

class Order extends Model
{
    protected array $fillable = ['customer_id', 'code', 'status', 'total', 'meta', 'issued_at'];

    protected array $casts = [
        'total' => 'int',
        'meta' => 'array',
        'is_paid' => 'bool',
        'issued_at' => 'jalali',
    ];

    protected array $dateStorage = [
        'issued_at' => [Jalali::JALALI, 'Y/m/d'],
    ];

    protected bool $softDeletes = true;

    protected array $appends = ['summary'];

    public function customer(): ModelBuilder
    {
        return $this->belongsTo(Customer::class);       // orders.customer_id
    }

    public function scopePaid(ModelBuilder $query): void
    {
        $query->where('status', 'paid');
    }

    public function getSummaryAttribute(): string
    {
        return $this->code . ' (' . $this->status . ')';
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper((string) $value);
    }
}

section('README: getting a builder');

NDB::table('users')->insert([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'is_active' => 1],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'is_active' => 0],
]);

check('a table returns arrays',
    NDB::table('users')->where('is_active', 1)->get()[0]['name'], 'Alice');
check('a model returns models',
    User::where('is_active', 1)->get()[0] instanceof User, true);
check('and its casts are applied', User::find(1)->is_active, true);

section('README: the Order walkthrough');

Customer::create(['name' => 'علی رضایی', 'city' => 'تهران']);

$order = Order::create([
    'customer_id' => 1,
    'code'        => 'ORD-1001',
    'status'      => 'paid',
    'total'       => '250000',
    'meta'        => ['gift' => true],
    'issued_at'   => Jalali::today()->toDateString(),
]);

check('total is an int', $order->total, 250000);
check('meta is decoded', $order->meta['gift'], true);
check('issued_at is a Jalali', $order->issued_at instanceof Jalali, true);
check('and formats in Persian',
    (bool) preg_match('/^[^\x00-\x7F]+ \d{1,2} [^\x00-\x7F]+$/u', $order->issued_at->format('l j F')), true);

check('the scope combined with a jalali clause',
    Order::paid()->whereJalaliThisMonth('issued_at')->count(), 1);

section('README: casts table');

class CastShowcase extends Model
{
    protected string $table = 'cast_showcase';
    public bool $timestamps = false;
    protected array $guarded = [];

    protected array $casts = [
        'total' => 'int',
        'rate' => 'float',
        'price' => 'decimal:2',
        'code' => 'string',
        'is_paid' => 'bool',
        'meta' => 'array',
        'payload' => 'json',
        'settings' => 'object',
        'seen_at' => 'timestamp',
        'birthday' => 'date',
        'opened_at' => 'datetime',
        'issued_at' => 'jalali',
    ];
}

$showcase = new CastShowcase([
    'total' => '42',
    'rate' => '3.5',
    'price' => '9.5',
    'code' => 1001,
    'is_paid' => 1,
    'meta' => ['a' => 1],
    'payload' => ['b' => 2],
    'settings' => ['c' => 3],
    'seen_at' => '1723800000',
    'birthday' => '1990-05-06 14:00:00',
    'opened_at' => '2024-08-16 14:30:00',
    'issued_at' => '1403/05/26',
]);

check('int', $showcase->total, 42);
check('float', $showcase->rate, 3.5);
check('decimal:2', $showcase->price, '9.50');
check('string', $showcase->code, '1001');
check('bool', $showcase->is_paid, true);
check('array', $showcase->meta, ['a' => 1]);
check('json', $showcase->payload, ['b' => 2]);
check('object', $showcase->settings->c, 3);
check('timestamp', $showcase->seen_at, 1723800000);
check('date drops the time', $showcase->birthday->format('Y-m-d H:i:s'), '1990-05-06 00:00:00');
check('datetime keeps it', $showcase->opened_at->format('Y-m-d H:i:s'), '2024-08-16 14:30:00');
check('jalali', $showcase->issued_at->toDateString(), '1403/05/26');

check('a jalali cast accepts a DateTimeInterface',
    (new CastShowcase(['issued_at' => new DateTimeImmutable('2024-08-16')]))->issued_at->toDateString(),
    '1403/05/26');
check('and an array', (new CastShowcase(['issued_at' => [1403, 5, 26]]))->issued_at->toDateString(), '1403/05/26');
check('and a Jalali', (new CastShowcase(['issued_at' => Jalali::create(1403, 5, 26)]))->issued_at->toDateString(), '1403/05/26');

section('README: accessors and mutators');

check('the accessor', $order->summary, 'ORD-1001 (paid)');
check('the mutator upper-cases on assignment',
    (new Order(['code' => 'ord-2002']))->code, 'ORD-2002');

section('README: scopes');

Customer::create(['name' => 'مریم احمدی', 'city' => 'اصفهان']);
Customer::create(['name' => 'حسن کریمی', 'city' => 'تهران', 'is_active' => 0]);

check('active()->inCity()->orderBy()',
    Customer::active()->inCity('تهران')->orderBy('name')->pluck('name'), ['علی رضایی']);

section('README: reading and writing');

check('all()', count(Customer::all()), 3);
check('find()', Customer::find(1)->name, 'علی رضایی');
check('findOrFail()', Customer::findOrFail(1)->name, 'علی رضایی');
check('where()->first()', Customer::where('city', 'اصفهان')->first()->name, 'مریم احمدی');
check('where()->firstOrFail()', Customer::where('city', 'اصفهان')->firstOrFail()->name, 'مریم احمدی');
check('count()', Customer::count(), 3);
check('paginate() data holds models', Customer::query()->paginate(2, 1)['data'][0] instanceof Customer, true);
check('cursor() yields models',
    (function () {
        foreach (Customer::query()->cursor() as $row) {
            return $row instanceof Customer;
        }
    })(),
    true);
check('toBase() gives arrays', is_array(Customer::query()->toBase()[0]), true);

check('forceCreate ignores $fillable',
    Customer::query()->forceCreate(['id' => 77, 'name' => 'اجباری'])->id, 77);
check('firstOrNew is not saved', Customer::query()->firstOrNew(['name' => 'موقت'])->exists, false);
check('firstOrCreate is', Customer::query()->firstOrCreate(['name' => 'ماندگار'], ['city' => 'قم'])->exists, true);
check('updateOrCreate', Customer::query()->updateOrCreate(['name' => 'ماندگار'], ['city' => 'یزد'])->city, 'یزد');

$subject = Customer::find(1);
$subject->city = 'شیراز';

check('isDirty', $subject->isDirty(), true);
check('isDirty on a column', $subject->isDirty('city'), true);
check('getDirty', $subject->getDirty(), ['city' => 'شیراز']);
check('getOriginal', $subject->getOriginal('city'), 'تهران');
check('save writes only the change', $subject->save(), true);
check('and it stuck', Customer::find(1)->city, 'شیراز');
check('getAttributes is raw where getAttribute is cast', [
    is_string(Order::find(1)->getAttributes()['meta']),
    is_array(Order::find(1)->meta),
], [true, true]);

check('update()', (function () {
    Customer::find(2)->update(['city' => 'رشت']);
    return Customer::find(2)->city;
})(), 'رشت');

check('refresh()', (function () {
    $stale = Customer::find(2);
    Customer::find(2)->update(['city' => 'اهواز']);
    return $stale->refresh()->city;
})(), 'اهواز');

check('fresh()', (function () {
    $stale = Customer::find(2);
    Customer::find(2)->update(['city' => 'کرمان']);
    return [$stale->fresh()->city, $stale->city];
})(), ['کرمان', 'اهواز']);

section('README: soft deletes');

$order->delete();

check('trashed()', $order->trashed(), true);
check('find() skips it', Order::find(1), null);
check('withTrashed()', Order::withTrashed()->find(1)->code, 'ORD-1001');
check('onlyTrashed()', Order::onlyTrashed()->count(), 1);
check('restore()', (function () use ($order) {
    $order->restore();
    return Order::find(1) !== null;
})(), true);

check('a query-level restore', (function () {
    $doomed = Order::create(['customer_id' => 1, 'code' => 'ORD-9', 'status' => 'cancelled', 'total' => 0]);
    $doomed->delete();
    Order::onlyTrashed()->where('status', 'cancelled')->restore();
    return Order::where('code', 'ORD-9')->count();
})(), 1);

check('a query-level forceDelete', (function () {
    Order::where('code', 'ORD-9')->forceDelete();
    return NDB::table('orders')->where('code', 'ORD-9')->count();
})(), 0);

check('forceDelete on the instance', (function () {
    $doomed = Order::create(['customer_id' => 1, 'code' => 'ORD-10', 'status' => 'pending', 'total' => 0]);
    $doomed->forceDelete();
    return NDB::table('orders')->where('code', 'ORD-10')->count();
})(), 0);

section('README: relations');

check('hasMany', Customer::find(1)->orders()->count(), 1);
check('hasMany with a clause', Customer::find(1)->orders()->where('status', 'paid')->sum('total'), 250000);
check('belongsTo', Order::find(1)->customer()->first()->name, 'علی رضایی');
check('hasOne', Customer::find(1)->hasOne(Order::class)->first()->code, 'ORD-1001');
check('explicit keys', Customer::find(1)->hasMany(Order::class, 'customer_id', 'id')->count(), 1);

section('README: output');

$array = Order::find(1)->toArray();

check('toArray applies the casts', $array['total'], 250000);
check('and adds the appends', $array['summary'], 'ORD-1001 (paid)');
check('a Jalali becomes a string', is_string($array['issued_at']), true);
check('toJson keeps unicode readable', strpos(Order::find(1)->toJson(), '\\u') === false, true);
check('json_encode works', json_decode(json_encode(Order::find(1)), true)['code'], 'ORD-1001');
check('array access works', Order::find(1)['code'], 'ORD-1001');

section('README: mass assignment guard');

class GuardedByDefault extends Model
{
    protected string $table = 'customers';
}

throws('the message names $fillable',
    fn () => new GuardedByDefault(['name' => 'x']),
    LogicException::class,
    'Add the columns to its $fillable list');

throws('ModelNotFoundException from findOrFail',
    fn () => Customer::findOrFail(9999),
    ModelNotFoundException::class);

section('README: extending NDB instead');

NDB::unprepared('create table blog_posts (id integer primary key autoincrement, published integer)');
NDB::table('blog_posts')->insert([['published' => 1], ['published' => 0]]);

class BlogPost extends NDB {}

check('the plain builder returns Row objects, read as properties',
    BlogPost::query()->where('published', 1)->first()->published, 1);
check('and toBase() still gives arrays',
    BlogPost::query()->where('published', 1)->toBase(), [['id' => 1, 'published' => 1]]);

summary();
