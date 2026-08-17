<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Expression;
use Or81\Eloquent\NDB;

/**
 * A raw SQL fragment carrying its own bindings, and the fill-in-the-blanks
 * update it exists for.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

section('an Expression can carry bindings');

$expression = new Expression('coalesce(?, name)', ['fallback']);

check('getValue', $expression->getValue(), 'coalesce(?, name)');
check('getBindings', $expression->getBindings(), ['fallback']);
check('hasBindings', $expression->hasBindings(), true);
check('a bare fragment has none', (new Expression('now()'))->hasBindings(), false);
check('the keys are dropped so the order is the order',
    (new Expression('?', [3 => 'a', 1 => 'b']))->getBindings(), ['a', 'b']);
check('NDB::raw takes them too', NDB::raw('f(?)', [7])->getBindings(), [7]);

section('where they are collected');

NDB::unprepared('create table t (id integer primary key autoincrement, a text, b text, c text)');

check('in a select column',
    NDB::table('t')->select(NDB::raw('? as label', ['hello']))->getBindings(), ['hello']);

check('in a where value',
    NDB::table('t')->where('a', '>', NDB::raw('length(?)', ['abc']))->getBindings(), ['abc']);

check('and the sql keeps the fragment',
    NDB::table('t')->where('a', '>', NDB::raw('length(?)', ['abc']))->toSql(),
    'select * from "t" where "a" > length(?)');

check('in a having value',
    NDB::table('t')->groupBy('a')->having('a', '>', NDB::raw('length(?)', ['ab']))->getBindings(), ['ab']);

check('in an insert value',
    (function () {
        NDB::table('t')->insert(['a' => NDB::raw('upper(?)', ['shout']), 'b' => 'plain']);

        return NDB::table('t')->first()->a;
    })(),
    'SHOUT');

check('in an update value',
    (function () {
        NDB::table('t')->where('b', 'plain')->update(['c' => NDB::raw('upper(?)', ['loud'])]);

        return NDB::table('t')->first()->c;
    })(),
    'LOUD');

check('update binding order is set values then wheres',
    NDB::connection()->getGrammar()->prepareBindingsForUpdate(
        NDB::table('t')->where('id', 'WHERE'),
        ['a' => NDB::raw('coalesce(?, a)', ['SET']), 'b' => 'PLAIN']
    ),
    ['SET', 'PLAIN', 'WHERE']);

check('a fragment with no bindings still contributes none',
    NDB::table('t')->where('id', 1)->getBindings(), [1]);

section('the fill-in-the-blanks update');

NDB::unprepared('create table vadana (
    id integer primary key autoincrement,
    national_code text,
    city text,
    code text,
    lessons text
)');

NDB::table('vadana')->insert([
    ['national_code' => '001', 'city' => '',        'code' => null,   'lessons' => '[]'],
    ['national_code' => '002', 'city' => 'تهران',   'code' => 'A-1',  'lessons' => '["ریاضی"]'],
    ['national_code' => '003', 'city' => '   ',     'code' => '',     'lessons' => 'null'],
    ['national_code' => '004', 'city' => 'اصفهان',  'code' => null,   'lessons' => null],
]);

/**
 * Exactly the statement being replaced, expressed with the builder.
 */
function fillBlanks(string $studentId, ?string $city, ?string $code, ?string $lessonsJson): int
{
    $blank = fn ($value) => $value === null || trim((string) $value) === '';

    return NDB::table('vadana')
        ->where('national_code', $studentId)
        ->update([
            'city' => NDB::raw(
                "coalesce(nullif(trim(city), ''), ?, city)",
                [$blank($city) ? null : $city]
            ),
            'code' => NDB::raw(
                "coalesce(nullif(trim(code), ''), ?, code)",
                [$blank($code) ? null : $code]
            ),
            'lessons' => NDB::raw(
                "coalesce(nullif(nullif(nullif(trim(lessons), ''), '[]'), 'null'), ?, lessons)",
                [$blank($lessonsJson) ? null : $lessonsJson]
            ),
        ]);
}

check('it reports the affected row', fillBlanks('001', 'شیراز', 'B-2', '["فیزیک"]'), 1);

$row = NDB::table('vadana')->firstWhere('national_code', '001');

check('an empty string is filled', $row->city, 'شیراز');
check('a null is filled', $row->code, 'B-2');
check('an empty json array is filled', $row->lessons, '["فیزیک"]');

fillBlanks('002', 'شیراز', 'B-2', '["فیزیک"]');

$row = NDB::table('vadana')->firstWhere('national_code', '002');

check('a value already there is kept', $row->city, 'تهران');
check('so is a code', $row->code, 'A-1');
check('and a non-empty lessons array', $row->lessons, '["ریاضی"]');

fillBlanks('003', 'یزد', 'C-3', '["شیمی"]');

$row = NDB::table('vadana')->firstWhere('national_code', '003');

check('whitespace counts as blank', $row->city, 'یزد');
check('an empty string code counts as blank', $row->code, 'C-3');
check('the literal string null counts as blank', $row->lessons, '["شیمی"]');

check('nothing incoming leaves everything alone',
    (function () {
        fillBlanks('004', null, null, null);

        $row = NDB::table('vadana')->firstWhere('national_code', '004');

        return [$row->city, $row->code, $row->lessons];
    })(),
    ['اصفهان', null, null]);

check('a blank column with nothing incoming stays blank',
    (function () {
        NDB::table('vadana')->insert(['national_code' => '005', 'city' => '', 'code' => null, 'lessons' => '[]']);

        fillBlanks('005', '', '   ', null);

        $row = NDB::table('vadana')->firstWhere('national_code', '005');

        return [$row->city, $row->code, $row->lessons];
    })(),
    ['', null, '[]']);

check('the compiled sql binds every value',
    NDB::table('vadana')->where('national_code', '001')->toSql() !== '', true);

check('a student who does not exist affects nothing',
    fillBlanks('999', 'قم', 'D-4', '["زیست"]'), 0);

section('the same thing, one column at a time');

/**
 * The alternative when you would rather not write SQL: three targeted
 * updates, each guarded by a where.
 */
function fillColumn(string $studentId, string $column, ?string $value, array $alsoBlank = []): int
{
    if ($value === null || trim($value) === '') {
        return 0;
    }

    return NDB::table('vadana')
        ->where('national_code', $studentId)
        ->where(function ($query) use ($column, $alsoBlank) {
            $query->whereNull($column)->orWhereRaw("trim({$column}) = ''");

            foreach ($alsoBlank as $blank) {
                $query->orWhereRaw("trim({$column}) = ?", [$blank]);
            }
        })
        ->update([$column => $value]);
}

NDB::table('vadana')->insert(['national_code' => '006', 'city' => '', 'code' => 'E-5', 'lessons' => 'null']);

check('it fills a blank column', fillColumn('006', 'city', 'کرج'), 1);
check('it leaves a filled one alone', fillColumn('006', 'code', 'Z-9'), 0);
check('and it understands the extra blanks', fillColumn('006', 'lessons', '["ادبیات"]', ['[]', 'null']), 1);

$row = NDB::table('vadana')->firstWhere('national_code', '006');

check('the result matches', [$row->city, $row->code, $row->lessons], ['کرج', 'E-5', '["ادبیات"]']);

summary();
