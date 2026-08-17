<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\Model;
use Or81\Eloquent\ModelNotFoundException;
use Or81\Eloquent\NDB;
use Or81\Eloquent\RecordNotFoundException;
use Or81\Eloquent\Row;

/**
 * Results as objects, and the creation helpers on the plain query builder.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table users (
    id integer primary key autoincrement,
    name text, email text unique, phone text, role text, votes integer default 0,
    created_at text
)');

NDB::unprepared('create table archive (id integer primary key autoincrement, name text, votes integer)');

NDB::unprepared('create table settings (
    setting_key text primary key,
    value text
)');

NDB::table('users')->insert([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '0912', 'role' => 'admin', 'votes' => 150, 'created_at' => '2024-03-05'],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'phone' => '0913', 'role' => 'user', 'votes' => 60, 'created_at' => '2024-04-06'],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'phone' => '0914', 'role' => 'user', 'votes' => 10, 'created_at' => '2025-01-10'],
]);

section('results are objects');

$user = NDB::table('users')->where('id', 1)->first();

check('first() returns a Row', $user instanceof Row, true);
check('columns read as properties', $user->name, 'Alice');
check('an integer column keeps its type', $user->votes, 150);
check('a missing column reads as null', $user->nope, null);

$users = NDB::table('users')->orderBy('id')->get();

check('get() returns a list of Rows', $users[0] instanceof Row, true);
check('and they are readable straight away', $users[1]->email, 'bob@example.com');
check('the list is a plain array', array_keys($users), [0, 1, 2]);

check('findOrFail returns a Row', NDB::table('users')->findOrFail(2)->name, 'Bob');
check('find returns one too', NDB::table('users')->find(3)->name, 'Carol');

$streamed = null;
foreach (NDB::table('users')->limit(1)->cursor() as $row) {
    $streamed = $row;
}
check('cursor() yields Rows', $streamed instanceof Row, true);
check('and they are readable', $streamed->name, 'Alice');

check('paginate data holds Rows', NDB::table('users')->paginate(2, 1)['data'][0] instanceof Row, true);
check('chunk gives Rows', (function () {
    $seen = null;
    NDB::table('users')->orderBy('id')->chunk(2, function (array $rows) use (&$seen) {
        $seen = $rows[0];
    });
    return $seen instanceof Row;
})(), true);

section('a Row still behaves like an array');

check('array access', $user['name'], 'Alice');
check('isset through array access', isset($user['name']), true);
check('isset on a missing column', isset($user['nope']), false);
check('isset on a null column', isset($user['does_not_exist']), false);
check('foreach walks the columns',
    (function () use ($user) {
        $keys = [];
        foreach ($user as $column => $value) {
            $keys[] = $column;
        }
        return $keys;
    })(),
    ['id', 'name', 'email', 'phone', 'role', 'votes', 'created_at']);
check('count() counts columns', count($user), 7);
check('getIterator hands back the columns',
    iterator_to_array($user->getIterator())['name'], 'Alice');
check('iterator_to_array round trips the whole row',
    iterator_to_array($user), $user->toArray());

section('the rest of the Row surface');

check('toArray', plain($user)['name'], 'Alice');
check('get with a default', $user->get('nope', 'fallback'), 'fallback');
check('get on a real column', $user->get('role'), 'admin');
check('has tells absent from null', [$user->has('role'), $user->has('nope')], [true, false]);
check('keys', $user->keys(), ['id', 'name', 'email', 'phone', 'role', 'votes', 'created_at']);
check('values start with the id', $user->values()[0], 1);
check('only', $user->only(['name', 'role']), ['name' => 'Alice', 'role' => 'admin']);
check('except drops what it is given', array_key_exists('email', $user->except(['email'])), false);
check('toJson keeps unicode readable',
    strpos((new Row(['name' => 'علی']))->toJson(), 'علی') !== false, true);
check('json_encode uses jsonSerialize',
    json_decode(json_encode(new Row(['a' => 1])), true), ['a' => 1]);

$scratch = new Row(['a' => 1]);
$scratch->b = 2;
$scratch->set('c', 3);
$scratch['d'] = 4;
unset($scratch['a']);
unset($scratch->b);

check('a Row can be written to as well', $scratch->toArray(), ['c' => 3, 'd' => 4]);

section('toBase() drops back to arrays');

check('toBase gives plain rows',
    NDB::table('users')->where('id', 1)->toBase(['name']), [['name' => 'Alice']]);
check('pluck is unchanged', NDB::table('users')->orderBy('id')->pluck('name'), ['Alice', 'Bob', 'Carol']);
check('value is unchanged', NDB::table('users')->where('id', 1)->value('name'), 'Alice');
check('aggregates are unchanged', NDB::table('users')->count(), 3);

section('create()');

$created = NDB::table('users')->create(['name' => 'Dave', 'email' => 'dave@example.com', 'votes' => 5]);

check('create returns the stored row', $created instanceof Row, true);
check('with the generated key filled in', $created->id, 4);
check('and the values that were written', $created->name, 'Dave');
check('and the column defaults the database applied', $created->role, null);
check('the row is really there', NDB::table('users')->where('email', 'dave@example.com')->count(), 1);

check('createMany returns them all',
    array_map(fn (Row $row) => $row->name, NDB::table('users')->createMany([
        ['name' => 'Eve', 'email' => 'eve@example.com'],
        ['name' => 'Frank', 'email' => 'frank@example.com'],
    ])),
    ['Eve', 'Frank']);

check('createMany rolls back as a unit',
    (function () {
        $before = NDB::table('users')->count();

        try {
            NDB::table('users')->createMany([
                ['name' => 'Good', 'email' => 'good@example.com'],
                ['name' => 'Clash', 'email' => 'alice@example.com'],   // unique collision
            ]);
        } catch (Throwable $e) {
            // expected
        }

        return NDB::table('users')->count() - $before;
    })(),
    0);

check('create on a table with a different key',
    NDB::table('settings')->keyName('setting_key')
        ->create(['setting_key' => 'theme', 'value' => 'dark'])->value,
    'dark');

section('firstOrCreate()');

check('it creates when there is no match',
    NDB::table('users')->firstOrCreate(['email' => 'grace@example.com'], ['name' => 'Grace'])->name,
    'Grace');

check('and finds when there is',
    NDB::table('users')->firstOrCreate(['email' => 'grace@example.com'], ['name' => 'Ignored'])->name,
    'Grace');

check('only one row was made', NDB::table('users')->where('email', 'grace@example.com')->count(), 1);

section('updateOrCreate() and createOrUpdate()');

check('it creates when there is no match',
    NDB::table('users')->updateOrCreate(['email' => 'heidi@example.com'], ['name' => 'Heidi', 'votes' => 1])->name,
    'Heidi');

check('and updates when there is',
    NDB::table('users')->updateOrCreate(['email' => 'heidi@example.com'], ['votes' => 99])->votes,
    99);

check('still one row', NDB::table('users')->where('email', 'heidi@example.com')->count(), 1);

check('createOrUpdate is the same method under the other name',
    NDB::table('users')->createOrUpdate(['email' => 'heidi@example.com'], ['votes' => 7])->votes,
    7);

check('updateOrCreate with no values just reads it back',
    NDB::table('users')->updateOrCreate(['email' => 'heidi@example.com'])->name, 'Heidi');

section('findOrFail, firstOrFail, sole');

throws('findOrFail names the table and the key',
    fn () => NDB::table('users')->findOrFail(9999),
    RecordNotFoundException::class,
    'with key [9999]');

throws('firstOrFail names the table',
    fn () => NDB::table('users')->where('role', 'ghost')->firstOrFail(),
    RecordNotFoundException::class,
    'No records found for [users]');

try {
    NDB::table('users')->findOrFail(9999);
} catch (RecordNotFoundException $e) {
    check('the exception carries the table', $e->getSubject(), 'users');
    check('and the key', $e->getId(), 9999);
}

check('sole returns the only match', NDB::table('users')->where('role', 'admin')->sole()->name, 'Alice');

throws('sole complains when nothing matches',
    fn () => NDB::table('users')->where('role', 'ghost')->sole(),
    RecordNotFoundException::class,
    'No records found');

throws('and when more than one does',
    fn () => NDB::table('users')->where('role', 'user')->sole(),
    RuntimeException::class,
    'More than one record matched');

section('firstOr, findOr, firstWhere');

check('firstOr returns the row when there is one',
    NDB::table('users')->where('id', 1)->firstOr(fn () => 'fallback')->name, 'Alice');
check('and the callback result when there is not',
    NDB::table('users')->where('id', 9999)->firstOr(fn () => 'fallback'), 'fallback');
check('findOr', NDB::table('users')->findOr(9999, fn () => 'missing'), 'missing');
check('findOr with a hit', NDB::table('users')->findOr(1, fn () => 'missing')->name, 'Alice');
check('firstWhere', NDB::table('users')->firstWhere('role', 'admin')->name, 'Alice');
check('firstWhere with an operator', NDB::table('users')->firstWhere('votes', '<', 20)->name, 'Carol');

section('whereAny, whereAll, whereNone');

check('whereAny searches across columns',
    NDB::table('users')->whereAny(['name', 'email', 'phone'], 'like', '%0913%')->pluck('name'),
    ['Bob']);

check('it stays in its own group',
    NDB::table('users')->where('role', 'user')
        ->whereAny(['name', 'email'], 'like', '%alice%')->count(),
    0);

check('the generated sql groups it',
    NDB::table('users')->whereAny(['name', 'email'], 'like', '%x%')->toSql(),
    'select * from "users" where ("name" like ? or "email" like ?)');

// Every address contains "example.com", so this comes down to the name.
check('whereAll requires every column',
    NDB::table('users')->whereAll(['name', 'email'], 'like', '%a%')->orderBy('id')->pluck('name'),
    ['Alice', 'Carol', 'Dave', 'Frank', 'Grace']);

check('whereNone excludes every match',
    NDB::table('users')->whereNone(['name', 'email'], 'like', '%bob%')->orderBy('id')->pluck('name'),
    ['Alice', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi']);

check('the two-argument form implies =',
    NDB::table('users')->whereAny(['name', 'email'], 'Alice')->count(), 1);

check('orWhereAny joins on with OR',
    NDB::table('users')->where('name', 'Bob')->orWhereAny(['name', 'email'], 'like', '%carol%')
        ->orderBy('id')->pluck('name'),
    ['Bob', 'Carol']);

check('orWhereAll does too',
    NDB::table('users')->where('name', 'Bob')->orWhereAll(['name', 'email'], 'like', '%eve%')
        ->orderBy('id')->pluck('name'),
    ['Bob', 'Eve']);

check('the or forms are still their own group',
    NDB::table('users')->where('name', 'Bob')->orWhereAny(['name', 'email'], 'like', '%carol%')->toSql(),
    'select * from "users" where "name" = ? or ("name" like ? or "email" like ?)');

section('chunkById');

check('it walks every row in key order',
    (function () {
        $seen = [];
        NDB::table('users')->orderByDesc('name')->chunkById(2, function (array $rows) use (&$seen) {
            foreach ($rows as $row) {
                $seen[] = $row->id;
            }
        });
        return $seen;
    })(),
    NDB::table('users')->orderBy('id')->pluck('id'));

check('it survives the callback deleting rows',
    (function () {
        NDB::table('archive')->insert([
            ['name' => 'a', 'votes' => 1], ['name' => 'b', 'votes' => 2], ['name' => 'c', 'votes' => 3],
            ['name' => 'd', 'votes' => 4], ['name' => 'e', 'votes' => 5],
        ]);

        $seen = 0;
        NDB::table('archive')->chunkById(2, function (array $rows) use (&$seen) {
            foreach ($rows as $row) {
                $seen++;
                NDB::table('archive')->where('id', $row->id)->update(['votes' => 0]);
            }
        });

        return $seen;
    })(),
    5);

check('returning false stops it early',
    (function () {
        $pages = 0;
        NDB::table('users')->chunkById(1, function () use (&$pages) {
            $pages++;
            return false;
        });
        return $pages;
    })(),
    1);

throws('a chunk size below one is refused',
    fn () => NDB::table('users')->chunkById(0, fn () => null),
    InvalidArgumentException::class,
    'at least 1');

section('implode and insertUsing');

check('implode joins one column',
    NDB::table('users')->where('role', 'user')->orderBy('id')->implode('name', ', '),
    'Bob, Carol');

check('insertUsing copies rows in',
    NDB::table('archive')->insertUsing(
        ['name', 'votes'],
        NDB::table('users')->select('name', 'votes')->where('role', 'admin')
    ),
    1);

check('and they arrived', NDB::table('archive')->where('name', 'Alice')->value('votes'), 150);

section('models keep working, and gain the same helpers');

class Person extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'votes', 'role'];
    public bool $timestamps = false;
}

check('a model still returns models, not Rows',
    Person::find(1) instanceof Person, true);
check('and reads as properties', Person::find(1)->name, 'Alice');

check('createMany on a model', array_map(
    fn (Model $m) => $m->name,
    Person::query()->createMany([['name' => 'Ivan', 'email' => 'ivan@example.com'], ['name' => 'Judy', 'email' => 'judy@example.com']])
), ['Ivan', 'Judy']);

check('createOrUpdate on a model',
    Person::query()->createOrUpdate(['email' => 'ivan@example.com'], ['votes' => 42])->votes, 42);

check('sole on a model', Person::where('email', 'ivan@example.com')->sole()->name, 'Ivan');

throws('and it throws a ModelNotFoundException',
    fn () => Person::where('email', 'nobody@example.com')->sole(),
    ModelNotFoundException::class);

check('a ModelNotFoundException is a RecordNotFoundException',
    is_subclass_of(ModelNotFoundException::class, RecordNotFoundException::class), true);

check('firstWhere on a model', Person::query()->firstWhere('role', 'admin')->name, 'Alice');
check('findOr on a model', Person::query()->findOr(9999, fn () => 'missing'), 'missing');
check('firstOr on a model', Person::where('id', 1)->firstOr(fn () => 'missing')->name, 'Alice');

check('chunkById on a model yields models',
    (function () {
        $first = null;
        Person::query()->chunkById(2, function (array $rows) use (&$first) {
            $first = $first ?? $rows[0];
            return false;
        });
        return $first instanceof Person;
    })(),
    true);

section('the extra model helpers');

$person = Person::find(1);
$person->votes = 200;
$person->save();

check('wasChanged', $person->wasChanged(), true);
check('wasChanged on a column', $person->wasChanged('votes'), true);
check('wasChanged on an untouched one', $person->wasChanged('name'), false);
check('getChanges', $person->getChanges(), ['votes' => 200]);
check('a fresh save with nothing dirty changes nothing',
    (function () use ($person) {
        $person->save();
        return $person->wasChanged();
    })(),
    false);

check('only', Person::find(1)->only(['name', 'votes']), ['name' => 'Alice', 'votes' => 200]);
check('except drops what it is given', array_key_exists('email', Person::find(1)->except(['email'])), false);

check('is compares identity', Person::find(1)->is(Person::find(1)), true);
check('is on a different row', Person::find(1)->is(Person::find(2)), false);
check('is on null', Person::find(1)->is(null), false);
check('isNot is the inverse', Person::find(1)->isNot(Person::find(2)), true);

$copy = Person::find(1)->replicate();

check('replicate drops the key', $copy->id, null);
check('replicate is unsaved', $copy->exists, false);
check('replicate keeps the rest', $copy->name, 'Alice');
check('a replica can be saved as a new row',
    (function () use ($copy) {
        $copy->email = 'alice-copy@example.com';
        $copy->save();
        return $copy->id > 1;
    })(),
    true);
check('replicate can skip more columns',
    array_key_exists('votes', Person::find(1)->replicate(['votes'])->toArray()), false);

section('destroy and touch');

check('destroy takes one key', Person::destroy(Person::query()->firstWhere('name', 'Judy')->id), 1);
check('the row is gone', Person::query()->firstWhere('name', 'Judy'), null);

check('destroy takes several',
    (function () {
        $ids = Person::query()->createMany([
            ['name' => 'K1', 'email' => 'k1@example.com'],
            ['name' => 'K2', 'email' => 'k2@example.com'],
        ]);
        return Person::destroy(array_map(fn (Model $m) => $m->id, $ids));
    })(),
    2);

check('destroy with nothing to do', Person::destroy([]), 0);

NDB::unprepared('create table stampeds (id integer primary key autoincrement, name text,
    email text, created_at text, updated_at text)');

class Stamped extends Model
{
    protected array $fillable = ['name', 'email'];
}

check('touch bumps updated_at',
    (function () {
        $model = Stamped::query()->create(['name' => 'Touched', 'email' => 'touched@example.com']);
        $before = $model->updated_at;

        $model->forceFill(['updated_at' => '2000-01-01 00:00:00'])->save();
        $model->touch();

        return $model->updated_at !== '2000-01-01 00:00:00' && $before !== null;
    })(),
    true);

check('touch on an unsaved model does nothing', (new Stamped())->touch(), false);
check('touch on a model without timestamps does nothing', (new Person(['name' => 'x']))->touch(), false);

summary();
