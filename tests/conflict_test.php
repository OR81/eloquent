<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;

/**
 * Telling one failure apart from another, and the insert-or-fill-blanks
 * write that removes the need to.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table vadana (
    id integer primary key autoincrement,
    name text,
    national_code text not null unique,
    student_code text,
    code text,
    city text,
    lessons text
)');

NDB::unprepared('create table children (
    id integer primary key autoincrement,
    vadana_id integer not null references vadana(id)
)');

section('a failure says what kind it was');

NDB::table('vadana')->insert(['name' => 'علی', 'national_code' => '001']);

try {
    NDB::table('vadana')->insert(['name' => 'تکراری', 'national_code' => '001']);
    check('the duplicate threw', false, true);
} catch (QueryException $e) {
    check('a duplicate is a unique violation', $e->isUniqueViolation(), true);
    check('and not a foreign key one', $e->isForeignKeyViolation(), false);
    check('the sqlstate is there', $e->getSqlState(), '23000');
    check('so is the driver code', $e->getDriverCode(), 19);
    check('and the full errorInfo', count($e->getErrorInfo()), 3);
    check('the sql came along', strpos($e->getSql(), 'insert into "vadana"') === 0, true);
    check('and the bindings', in_array('001', $e->getBindings(), true), true);
}

try {
    NDB::table('children')->insert(['vadana_id' => 9999]);
    check('the bad key threw', false, true);
} catch (QueryException $e) {
    check('a bad foreign key is reported as one', $e->isForeignKeyViolation(), true);
    check('and not as a duplicate', $e->isUniqueViolation(), false);
}

try {
    NDB::table('vadana')->insert(['nope' => 1]);
} catch (QueryException $e) {
    check('an unrelated failure is neither',
        [$e->isUniqueViolation(), $e->isForeignKeyViolation()], [false, false]);
}

check('it is not a PDOException, so catch the right class',
    (function () {
        try {
            NDB::table('vadana')->insert(['name' => 'x', 'national_code' => '001']);
        } catch (PDOException $e) {
            return 'PDOException';
        } catch (QueryException $e) {
            return 'QueryException';
        }

        return 'nothing';
    })(),
    'QueryException');

check('the driver exception is still reachable underneath',
    (function () {
        try {
            NDB::table('vadana')->insert(['name' => 'x', 'national_code' => '001']);
        } catch (QueryException $e) {
            return $e->getPrevious()->errorInfo[1];
        }
    })(),
    19);

section('insert, or fill in only the blanks, in one statement');

/**
 * What the try/catch was reaching for, as a single atomic write.
 */
function saveStudent(array $student): int
{
    $blank = fn ($value) => $value === null || trim((string) $value) === '';

    return NDB::table('vadana')->upsert(
        [$student],
        ['national_code'],
        [
            'city' => NDB::raw(
                "coalesce(nullif(trim(city), ''), ?, city)",
                [$blank($student['city']) ? null : $student['city']]
            ),
            'code' => NDB::raw(
                "coalesce(nullif(trim(code), ''), ?, code)",
                [$blank($student['code']) ? null : $student['code']]
            ),
            'lessons' => NDB::raw(
                "coalesce(nullif(nullif(nullif(trim(lessons), ''), '[]'), 'null'), ?, lessons)",
                [$blank($student['lessons']) ? null : $student['lessons']]
            ),
        ]
    );
}

NDB::table('vadana')->truncate();

$first = [
    'name' => 'علی رضایی',
    'national_code' => '001',
    'student_code' => 'S-1',
    'code' => '',
    'city' => null,
    'lessons' => '[]',
];

saveStudent($first);

$row = NDB::table('vadana')->firstWhere('national_code', '001');

check('a new student is inserted', $row->name, 'علی رضایی');
check('with the blanks left blank', [$row->city, $row->code, $row->lessons], [null, '', '[]']);

saveStudent([
    'name' => 'نام دیگر',
    'national_code' => '001',
    'student_code' => 'S-9',
    'code' => 'A-1',
    'city' => 'تهران',
    'lessons' => '["ریاضی"]',
]);

$row = NDB::table('vadana')->firstWhere('national_code', '001');

check('the second call did not duplicate', NDB::table('vadana')->count(), 1);
check('a null city is filled', $row->city, 'تهران');
check('an empty code is filled', $row->code, 'A-1');
check('an empty lessons array is filled', $row->lessons, '["ریاضی"]');

saveStudent([
    'name' => 'باز هم دیگر',
    'national_code' => '001',
    'student_code' => 'S-10',
    'code' => 'B-2',
    'city' => 'شیراز',
    'lessons' => '["فیزیک"]',
]);

$row = NDB::table('vadana')->firstWhere('national_code', '001');

check('values already there are kept', [$row->city, $row->code, $row->lessons],
    ['تهران', 'A-1', '["ریاضی"]']);

check('columns left out of the update list are untouched', $row->name, 'علی رضایی');
check('so is the student code', $row->student_code, 'S-1');

check('nothing incoming leaves the row alone',
    (function () {
        saveStudent([
            'name' => 'x', 'national_code' => '001', 'student_code' => 'x',
            'code' => '   ', 'city' => null, 'lessons' => 'null',
        ]);

        $row = NDB::table('vadana')->firstWhere('national_code', '001');

        return [$row->city, $row->code, $row->lessons];
    })(),
    ['تهران', 'A-1', '["ریاضی"]']);

check('a different student is a plain insert',
    (function () {
        saveStudent([
            'name' => 'مریم', 'national_code' => '002', 'student_code' => 'S-2',
            'code' => 'C-3', 'city' => 'اصفهان', 'lessons' => '["شیمی"]',
        ]);

        return NDB::table('vadana')->count();
    })(),
    2);

section('the upsert carries its raw bindings in the right order');

// insert() sorts the columns, so "city" is bound before "national_code".
check('values first, then the update fragments',
    (function () {
        NDB::connection()->flushQueryLog();
        NDB::enableQueryLog();

        NDB::table('vadana')->upsert(
            [['national_code' => '003', 'city' => 'INSERTED']],
            ['national_code'],
            ['city' => NDB::raw('coalesce(nullif(city, ?), ?, city)', ['EMPTY', 'UPDATED'])]
        );

        $log = NDB::getQueryLog();
        NDB::disableQueryLog();

        return [$log[0]['query'], $log[0]['bindings']];
    })(),
    [
        'insert into "vadana" ("city", "national_code") values (?, ?) '
        . 'on conflict ("national_code") do update set "city" = coalesce(nullif(city, ?), ?, city)',
        ['INSERTED', '003', 'EMPTY', 'UPDATED'],
    ]);

check('and the placeholders line up',
    NDB::table('vadana')->firstWhere('national_code', '003')->city, 'INSERTED');

section('the fallback, when a write really cannot be saved');

$log = sys_get_temp_dir() . '/ndb_failed_inserts_' . getmypid() . '.jsonl';
@unlink($log);

function saveOrLog(array $student, string $log): bool
{
    try {
        saveStudent($student);

        return true;
    } catch (QueryException $e) {
        file_put_contents(
            $log,
            json_encode($student + ['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return false;
    }
}

check('a good row needs no log',
    saveOrLog([
        'name' => 'زهرا', 'national_code' => '004', 'student_code' => 'S-4',
        'code' => 'D-4', 'city' => 'یزد', 'lessons' => '["ادبیات"]',
    ], $log),
    true);

check('nothing was written', file_exists($log), false);

check('a row the database refuses is logged',
    saveOrLog([
        'name' => 'بدون کد ملی', 'national_code' => null, 'student_code' => 'S-5',
        'code' => 'E-5', 'city' => 'قم', 'lessons' => '[]',
    ], $log),
    false);

$logged = json_decode(trim(file_get_contents($log)), true);

check('the row is in the log', $logged['name'], 'بدون کد ملی');
check('with the reason', strpos($logged['error'], 'NOT NULL') !== false, true);
check('and unicode stayed readable', strpos(file_get_contents($log), 'بدون کد ملی') !== false, true);

@unlink($log);

summary();
