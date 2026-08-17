<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Jalali;
use Or81\Eloquent\Model;
use Or81\Eloquent\ModelBuilder;
use Or81\Eloquent\ModelNotFoundException;
use Or81\Eloquent\NDB;
use Or81\Eloquent\Str;

/**
 * The Eloquent-style model layer: table and key resolution, mass assignment,
 * casts, accessors, timestamps, soft deletes, scopes, relations and output.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table customers (
    id integer primary key autoincrement,
    name text, city text, is_active integer default 1,
    created_at text, updated_at text
)');

NDB::unprepared('create table orders (
    id integer primary key autoincrement,
    customer_id integer, code text, status text, total integer,
    meta text, is_paid integer default 0,
    issued_at text, logged_at integer,
    created_at text, updated_at text, deleted_at text
)');

NDB::unprepared('create table api_tokens (
    token text primary key,
    label text,
    created_at text, updated_at text
)');

NDB::unprepared('create table blog_posts (id integer primary key autoincrement, title text)');
NDB::unprepared('create table people (id integer primary key autoincrement, name text)');
NDB::unprepared('create table legacy_rows (id integer primary key autoincrement, note text)');

class Customer extends Model
{
    protected array $fillable = ['name', 'city', 'is_active'];

    protected array $casts = ['is_active' => 'bool'];

    public function orders(): ModelBuilder
    {
        return $this->hasMany(Order::class);
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
    protected array $fillable = ['customer_id', 'code', 'status', 'total', 'meta', 'is_paid', 'issued_at', 'logged_at'];

    protected array $casts = [
        'total' => 'int',
        'meta' => 'array',
        'is_paid' => 'bool',
        'issued_at' => 'jalali',
        'logged_at' => 'jalali',
    ];

    protected array $dateStorage = [
        'issued_at' => [Jalali::JALALI, 'Y/m/d'],
        'logged_at' => Jalali::UNIX,
    ];

    protected bool $softDeletes = true;

    protected array $hidden = ['deleted_at'];

    protected array $appends = ['summary'];

    public function getSummaryAttribute(): string
    {
        return $this->code . ' (' . $this->status . ')';
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper((string) $value);
    }

    public function customer(): ModelBuilder
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopePaid(ModelBuilder $query): void
    {
        $query->where('status', 'paid');
    }
}

class ApiToken extends Model
{
    protected string $primaryKey = 'token';
    protected string $keyType = 'string';
    protected bool $incrementing = false;

    protected array $fillable = ['token', 'label'];
}

class BlogPost extends Model
{
    protected array $guarded = [];
}

class Person extends Model
{
    protected array $fillable = ['name'];
    public bool $timestamps = false;
}

class LegacyRow extends Model
{
    protected string $table = 'legacy_rows';
    protected array $fillable = ['note'];
    public bool $timestamps = false;
}

class ApiTokenWithNoFillable extends Model
{
    protected string $table = 'api_tokens';
}

class BadCast extends Model
{
    protected string $table = 'legacy_rows';
    protected array $fillable = ['value'];
    protected array $casts = ['value' => 'geometry'];
}

class VisibleOnly extends Model
{
    protected string $table = 'legacy_rows';
    protected array $visible = ['a', 'c'];
}

section('table and key resolution');

check('the table comes from the class name', (new Customer())->getTable(), 'customers');
check('a compound class name is snaked and pluralised', (new BlogPost())->getTable(), 'blog_posts');
check('an irregular plural', (new Person())->getTable(), 'people');
check('an explicit table wins', (new LegacyRow())->getTable(), 'legacy_rows');
check('the default key', (new Customer())->getKeyName(), 'id');
check('a custom key', (new ApiToken())->getKeyName(), 'token');
check('the qualified key', (new Customer())->getQualifiedKeyName(), 'customers.id');
check('key type and incrementing',
    [(new ApiToken())->getKeyType(), (new ApiToken())->getIncrementing()], ['string', false]);
check('the connection is the default one',
    (new Customer())->getConnection() === NDB::connection(), true);

section('mass assignment');

$customer = new Customer(['name' => 'علی رضایی', 'city' => 'تهران', 'id' => 99]);

check('fillable columns are taken', $customer->name, 'علی رضایی');
check('anything else is dropped', $customer->getAttributes()['id'] ?? null, null);
check('isFillable reports the rule',
    [$customer->isFillable('name'), $customer->isFillable('id')], [true, false]);

check('an empty $guarded lets everything through',
    (new BlogPost(['title' => 'anything']))->title, 'anything');

throws('a model that guards everything says so instead of saving a blank row',
    fn () => new ApiTokenWithNoFillable(['token' => 'x']),
    LogicException::class,
    'may be mass assigned');

check('forceFill bypasses the check',
    (new Customer())->forceFill(['id' => 7, 'name' => 'X'])->getAttributes(),
    ['id' => 7, 'name' => 'X']);

check('unguarded() lifts it for one call',
    Model::unguarded(fn () => (new Customer(['id' => 7, 'name' => 'X']))->getAttributes()),
    ['id' => 7, 'name' => 'X']);

check('and puts it back afterwards',
    (new Customer(['id' => 7, 'name' => 'X']))->getAttributes(), ['name' => 'X']);

section('creating and reading');

$alice = Customer::create(['name' => 'علی رضایی', 'city' => 'تهران']);

check('create returns a saved model', [$alice->exists, is_int($alice->id)], [true, true]);
check('the key came back from the database', $alice->id, 1);
check('created_at was stamped', $alice->created_at !== null, true);
check('updated_at too', $alice->updated_at !== null, true);

Customer::create(['name' => 'مریم احمدی', 'city' => 'اصفهان']);
Customer::create(['name' => 'حسن کریمی', 'city' => 'تهران', 'is_active' => false]);

check('all() returns models', array_map(fn (Model $c) => $c->name, Customer::all()),
    ['علی رضایی', 'مریم احمدی', 'حسن کریمی']);

check('get() returns models', Customer::query()->get()[0] instanceof Customer, true);
check('find()', Customer::find(2)->name, 'مریم احمدی');
check('find() with no match', Customer::find(999), null);
check('first()', Customer::query()->orderByDesc('id')->first()->name, 'حسن کریمی');
check('where() straight off the class', Customer::where('city', 'اصفهان')->first()->name, 'مریم احمدی');
check('count() stays an int', Customer::count(), 3);
check('exists()', Customer::where('city', 'شیراز')->exists(), false);
check('pluck() stays raw', Customer::query()->pluck('name', 'id'),
    [1 => 'علی رضایی', 2 => 'مریم احمدی', 3 => 'حسن کریمی']);
check('value() stays raw', Customer::where('id', 1)->value('city'), 'تهران');

throws('findOrFail explains what was missing',
    fn () => Customer::findOrFail(999),
    ModelNotFoundException::class,
    'with key [999]');

throws('firstOrFail too',
    fn () => Customer::where('city', 'شیراز')->firstOrFail(),
    ModelNotFoundException::class,
    'No query results for model');

try {
    Customer::findOrFail(999);
} catch (ModelNotFoundException $e) {
    check('the exception names the model', $e->getModel(), Customer::class);
    check('and the key', $e->getId(), 999);
}

section('casts');

$order = Order::create([
    'customer_id' => 1,
    'code' => 'ord-1001',
    'status' => 'paid',
    'total' => '250000',
    'meta' => ['gift' => true, 'note' => 'تحویل سریع'],
    'is_paid' => 1,
    'issued_at' => '1403/05/26',
    'logged_at' => '1403/05/26',
]);

check('int cast', $order->total, 250000);
check('bool cast', $order->is_paid, true);
check('array cast on the way out', $order->meta, ['gift' => true, 'note' => 'تحویل سریع']);
check('array cast on the way in is json',
    json_decode($order->getAttributes()['meta'], true), ['gift' => true, 'note' => 'تحویل سریع']);
check('the mutator upper-cased the code', $order->code, 'ORD-1001');
check('the accessor is computed', $order->summary, 'ORD-1001 (paid)');

check('the jalali cast returns a Jalali', $order->issued_at instanceof Jalali, true);
check('and it holds the right day', $order->issued_at->toDateString(), '1403/05/26');
check('a jalali column stores the declared format', $order->getAttributes()['issued_at'], '1403/05/26');
check('a unix column stores an integer',
    $order->getAttributes()['logged_at'], Jalali::create(1403, 5, 26)->toTimestamp());
check('and reads back as the same day', $order->logged_at->toDateString(), '1403/05/26');

$reloaded = Order::find($order->id);
check('the casts survive a round trip', [
    $reloaded->total,
    $reloaded->is_paid,
    $reloaded->meta['gift'],
    $reloaded->issued_at->toDateString(),
    $reloaded->logged_at->toDateString(),
], [250000, true, true, '1403/05/26', '1403/05/26']);

check('a Jalali object can be assigned and saved',
    (function () {
        $fresh = Order::find(1);
        $fresh->issued_at = Jalali::create(1403, 6, 2);
        $fresh->save();

        return Order::find(1)->getAttributes()['issued_at'];
    })(),
    '1403/06/02');

throws('an unknown cast is refused',
    fn () => (new BadCast(['value' => 1]))->value,
    InvalidArgumentException::class,
    'Unknown cast');

section('dirty tracking and save');

$customer = Customer::find(1);

check('a freshly loaded model is clean', $customer->isDirty(), false);
check('and getDirty is empty', $customer->getDirty(), []);

$customer->city = 'کرج';

check('a changed column is dirty', $customer->isDirty('city'), true);
check('an untouched one is not', $customer->isDirty('name'), false);
check('getDirty lists only the change', array_keys($customer->getDirty()), ['city']);
check('getOriginal remembers', $customer->getOriginal('city'), 'تهران');

$customer->save();

check('the change was written', Customer::find(1)->city, 'کرج');
check('and the model is clean again', $customer->isDirty(), false);
check('isClean is the inverse', $customer->isClean(), true);

check('saving with nothing dirty is a no-op that still succeeds', $customer->save(), true);

check('update() fills and saves',
    (function () {
        $model = Customer::find(2);
        $model->update(['city' => 'یزد']);
        return Customer::find(2)->city;
    })(),
    'یزد');

check('update() on an unsaved model does nothing', (new Customer(['name' => 'X']))->update(['city' => 'Y']), false);

check('updated_at moved but created_at did not',
    (function () {
        $model = Customer::find(1);
        $createdAt = $model->created_at;
        $model->forceFill(['updated_at' => '2000-01-01 00:00:00'])->save();
        $model->city = 'شیراز';
        $model->save();
        return [$model->created_at === $createdAt, $model->updated_at !== '2000-01-01 00:00:00'];
    })(),
    [true, true]);

check('a model with timestamps off writes neither',
    (function () {
        $person = Person::create(['name' => 'بدون تایم‌استمپ']);
        return array_key_exists('created_at', $person->getAttributes());
    })(),
    false);

section('refresh and fresh');

$one = Customer::find(3);
$two = Customer::find(3);

$two->update(['city' => 'مشهد']);

check('the stale instance still holds the old value', $one->city, 'تهران');
check('fresh() returns a new instance', $one->fresh()->city, 'مشهد');
check('and leaves the original alone', $one->city, 'تهران');
check('refresh() updates in place', $one->refresh()->city, 'مشهد');
check('fresh() on an unsaved model is null', (new Customer())->fresh(), null);

section('a non-incrementing string key');

$token = ApiToken::create(['token' => 'tok_abc123', 'label' => 'CI']);

check('the key was kept as given', $token->token, 'tok_abc123');
check('the row is there', ApiToken::find('tok_abc123')->label, 'CI');

throws('saving without the key is refused',
    fn () => ApiToken::create(['label' => 'no key']),
    RuntimeException::class,
    'must be set before saving');

section('scopes');

check('a bare scope', array_map(fn (Model $c) => $c->name, Customer::active()->get()),
    ['علی رضایی', 'مریم احمدی']);

check('a scope taking an argument',
    Customer::inCity('یزد')->pluck('name'), ['مریم احمدی']);

check('scopes chain with everything else',
    Customer::active()->where('city', 'شیراز')->count(), 1);

check('a scope on another model', Order::paid()->count(), 1);

throws('an unknown method points at scopes',
    fn () => Customer::query()->nonsense(),
    RuntimeException::class,
    'scopeNonsense');

section('soft deletes');

$order = Order::find(1);

check('trashed() is false to begin with', $order->trashed(), false);
check('delete() stamps instead of removing', $order->delete(), true);
check('the model knows it is trashed', $order->trashed(), true);
check('the row is still there', NDB::table('orders')->where('id', 1)->count(), 1);
check('but the model no longer finds it', Order::find(1), null);
check('nor counts it', Order::count(), 0);
check('withTrashed brings it back', Order::withTrashed()->find(1)->code, 'ORD-1001');
check('onlyTrashed finds just the deleted ones', Order::onlyTrashed()->count(), 1);

check('restore() clears the stamp', $order->restore(), true);
check('and it is visible again', Order::find(1)->code, 'ORD-1001');
check('trashed() is false once more', Order::find(1)->trashed(), false);

check('forceDelete removes the row for good',
    (function () {
        $target = Order::create(['customer_id' => 1, 'code' => 'ord-doomed', 'status' => 'pending', 'total' => 1]);
        $id = $target->id;
        $target->forceDelete();
        return NDB::table('orders')->where('id', $id)->count();
    })(),
    0);

check('a query-level restore works too',
    (function () {
        $target = Order::create(['customer_id' => 1, 'code' => 'ord-back', 'status' => 'pending', 'total' => 1]);
        $target->delete();
        Order::onlyTrashed()->where('code', 'ORD-BACK')->restore();
        return Order::where('code', 'ORD-BACK')->count();
    })(),
    1);

throws('restore() on a model without soft deletes is refused',
    fn () => Customer::find(1)->restore(),
    LogicException::class,
    'does not use soft deletes');

section('firstOrCreate, updateOrCreate, firstOrNew');

check('firstOrCreate creates when missing',
    Customer::query()->firstOrCreate(['name' => 'زهرا موسوی'], ['city' => 'شیراز'])->city, 'شیراز');
check('and finds when present',
    Customer::query()->firstOrCreate(['name' => 'زهرا موسوی'], ['city' => 'اهواز'])->city, 'شیراز');
check('only one row was made', Customer::where('name', 'زهرا موسوی')->count(), 1);

check('updateOrCreate updates the existing row',
    Customer::query()->updateOrCreate(['name' => 'زهرا موسوی'], ['city' => 'اهواز'])->city, 'اهواز');
check('still one row', Customer::where('name', 'زهرا موسوی')->count(), 1);

check('updateOrCreate creates when missing',
    Customer::query()->updateOrCreate(['name' => 'کاربر تازه'], ['city' => 'قم'])->exists, true);

$new = Customer::query()->firstOrNew(['name' => 'هرگز ذخیره نشده'], ['city' => 'تبریز']);
check('firstOrNew does not save', $new->exists, false);
check('but it is filled', $new->city, 'تبریز');

check('forceCreate skips the fillable check',
    Customer::query()->forceCreate(['id' => 500, 'name' => 'اجباری'])->id, 500);

section('relations');

check('hasMany returns a query', Customer::find(1)->orders() instanceof ModelBuilder, true);
check('and it is scoped to the parent',
    array_map(fn (Model $o) => $o->code, Customer::find(1)->orders()->get()),
    ['ORD-1001', 'ORD-BACK']);
check('the foreign key was guessed', Customer::find(2)->orders()->count(), 0);
check('belongsTo walks back', Order::find(1)->customer()->first()->name, 'علی رضایی');
check('a relation query still takes clauses',
    Customer::find(1)->orders()->where('status', 'paid')->count(), 1);

throws('a relation must point at a Model',
    fn () => Customer::find(1)->hasMany(stdClass::class),
    InvalidArgumentException::class,
    'is not a');

section('jalali clauses through the model');

check('the model passes its date storage to the query',
    Order::withTrashed()->whereJalaliDate('issued_at', '1403/06/02')->count(), 1);

check('a unix column declared on the model',
    Order::withTrashed()->whereJalaliYear('logged_at', 1403)->count(), 1);

check('and a month range',
    Order::withTrashed()->whereJalaliMonth('issued_at', 1403, 6)->pluck('code'), ['ORD-1001']);

section('array and json output');

$order = Order::find(1);
$array = $order->toArray();

check('toArray applies the casts', [$array['total'], $array['is_paid'], $array['meta']],
    [250000, true, ['gift' => true, 'note' => 'تحویل سریع']]);
check('a Jalali becomes a string', $array['issued_at'], '1403/06/02 00:00:00');
check('appends are added', $array['summary'], 'ORD-1001 (paid)');
check('hidden columns are removed', array_key_exists('deleted_at', $array), false);

check('toJson keeps unicode readable',
    strpos($order->toJson(), 'تحویل سریع') !== false, true);
check('json_encode uses jsonSerialize',
    json_decode(json_encode($order), true)['summary'], 'ORD-1001 (paid)');
check('__toString is the json', (string) $order, $order->toJson());

check('$visible narrows the output',
    array_keys((new VisibleOnly())->forceFill(['a' => 1, 'b' => 2, 'c' => 3])->toArray()),
    ['a', 'c']);

section('array access and magic');

$customer = Customer::find(1);

check('offsetGet', $customer['name'], 'علی رضایی');
check('offsetExists', isset($customer['name']), true);
check('offsetExists on a missing key', isset($customer['nope']), false);

$customer['city'] = 'رشت';
check('offsetSet', $customer->city, 'رشت');

unset($customer['city']);
check('offsetUnset', $customer->city, null);

check('an unknown attribute is null', $customer->does_not_exist, null);

section('paginate and chunk return models');

$page = Customer::query()->orderBy('id')->paginate(2, 1);

check('paginate data holds models', $page['data'][0] instanceof Customer, true);
check('paginate counts every row', $page['total'], Customer::count());

$seen = [];
Customer::query()->orderBy('id')->chunk(2, function (array $rows) use (&$seen) {
    foreach ($rows as $row) {
        $seen[] = $row instanceof Customer;
    }
});
check('chunk yields models', array_unique($seen), [true]);

$streamed = null;
foreach (Customer::query()->limit(1)->cursor() as $row) {
    $streamed = $row instanceof Customer;
}
check('cursor yields models', $streamed, true);

check('toBase drops back to plain arrays',
    is_array(Customer::query()->limit(1)->toBase()[0]), true);

section('the string helpers behind all of this');

check('snake', [Str::snake('BlogPost'), Str::snake('User'), Str::snake('already_snake')],
    ['blog_post', 'user', 'already_snake']);
check('studly', [Str::studly('blog_post'), Str::studly('is_active')], ['BlogPost', 'IsActive']);
check('camel', Str::camel('created_at'), 'createdAt');
check('plural', [Str::plural('user'), Str::plural('category'), Str::plural('person'), Str::plural('news')],
    ['users', 'categories', 'people', 'news']);
check('classBasename', Str::classBasename('App\\Models\\BlogPost'), 'BlogPost');

section('the rest of the model surface, called directly');

$model = new Customer();

check('fill() returns the model', $model->fill(['name' => 'مستقیم']) === $model, true);
check('setAttribute / getAttribute',
    (function () use ($model) {
        $model->setAttribute('city', 'بندرعباس');
        return $model->getAttribute('city');
    })(),
    'بندرعباس');
check('getAttribute on an empty key', $model->getAttribute(''), null);

check('hasCast', [$model->hasCast('is_active'), $model->hasCast('name')], [true, false]);
check('getCasts', $model->getCasts(), ['is_active' => 'bool']);
check('getDateStorage', (new Order())->getDateStorage(), [
    'issued_at' => [Jalali::JALALI, 'Y/m/d'],
    'logged_at' => Jalali::UNIX,
]);

check('setTable overrides for this instance',
    (new Customer())->setTable('other_customers')->newQuery()->toSql(),
    'select * from "other_customers"');

check('getKey reads the primary key', Customer::find(1)->getKey(), 1);
check('getKey on an unsaved model', (new Customer())->getKey(), null);
check('getConnectionName is null by default', (new Customer())->getConnectionName(), null);

check('usesTimestamps', [(new Customer())->usesTimestamps(), (new Person())->usesTimestamps()], [true, false]);
check('usesSoftDeletes', [(new Order())->usesSoftDeletes(), (new Customer())->usesSoftDeletes()], [true, false]);

check('the timestamp column names', [
    (new Customer())->getCreatedAtColumn(),
    (new Customer())->getUpdatedAtColumn(),
    (new Order())->getDeletedAtColumn(),
    (new Order())->getQualifiedDeletedAtColumn(),
], ['created_at', 'updated_at', 'deleted_at', 'orders.deleted_at']);

check('freshTimestampFor a plain column looks like a datetime',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (new Customer())->freshTimestampFor('updated_at')),
    true);
check('freshTimestampFor a declared jalali column is jalali',
    (bool) preg_match('/^1[34]\d{2}\/\d{2}\/\d{2}$/', (new Order())->freshTimestampFor('issued_at')),
    true);

check('newQueryWithoutScopes drops the soft-delete filter',
    (new Order())->newQueryWithoutScopes()->toSql(), 'select * from "orders"');
check('newQuery adds it back',
    (new Order())->newQuery()->toSql(), 'select * from "orders" where "orders"."deleted_at" is null');

check('newInstance builds an unsaved model',
    (function () {
        $instance = (new Customer())->newInstance(['name' => 'تازه']);
        return [$instance instanceof Customer, $instance->exists, $instance->name];
    })(),
    [true, false, 'تازه']);

check('newFromBuilder builds a saved one',
    (function () {
        $instance = (new Customer())->newFromBuilder(['id' => 42, 'name' => 'از دیتابیس']);
        return [$instance->exists, $instance->id, $instance->isDirty()];
    })(),
    [true, 42, false]);

check('syncOriginal marks the current state as clean',
    (function () {
        $instance = Customer::find(1);
        $instance->city = 'گرگان';
        $instance->syncOriginal();
        return $instance->isDirty();
    })(),
    false);

check('hasOne is a limited hasMany',
    (function () {
        $query = Customer::find(1)->hasOne(Order::class);
        return [$query instanceof ModelBuilder, $query->limit];
    })(),
    [true, 1]);

check('jsonSerialize called directly',
    (new Customer())->forceFill(['name' => 'سریال'])->jsonSerialize(), ['name' => 'سریال']);

$access = Customer::find(1);

check('offsetExists directly', $access->offsetExists('name'), true);
check('offsetGet directly', $access->offsetGet('name'), 'علی رضایی');
check('offsetSet directly',
    (function () use ($access) {
        $access->offsetSet('city', 'زاهدان');
        return $access->city;
    })(),
    'زاهدان');
check('offsetUnset directly',
    (function () use ($access) {
        $access->offsetUnset('city');
        return $access->city;
    })(),
    null);

check('setModel returns the query',
    (function () {
        $query = (new Customer())->newQuery();
        return $query->setModel(new Customer()) === $query;
    })(),
    true);
check('getModel hands the model back',
    Customer::query()->getModel() instanceof Customer, true);

summary();
