<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\Connection;
use Or81\Eloquent\NDB;
use Or81\Eloquent\QueryException;

/**
 * Many rows at a time, each judged on its own values.
 *
 * A single-row upsert can bind the incoming value. A batch cannot: one ?
 * would be one value for every row in the statement. The update clause has
 * to name the incoming value instead, which is what incoming() is for.
 */

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);
NDB::configure(['driver' => 'mysql', 'database' => 'test'], 'mysql');

NDB::unprepared('create table vadana (
    id integer primary key autoincrement,
    name text,
    national_code text not null unique,
    student_code text,
    code text,
    city text,
    lessons text
)');

section('incoming() speaks each driver');

check('sqlite', NDB::incoming('city')->getValue(), '"excluded"."city"');
check('mysql',
    (new Builder(NDB::connection('mysql')))->incoming('city')->getValue(),
    'values(`city`)');
check('it is a bare fragment with no bindings', NDB::incoming('city')->hasBindings(), false);
check('it survives being dropped into a string',
    "coalesce(city, " . NDB::incoming('city') . ")",
    'coalesce(city, "excluded"."city")');

section('the batch writer');

/**
 * The nullif chain that decides what counts as blank for a column.
 */
function blankChain(string $expression, array $alsoBlank = []): string
{
    $sql = "trim({$expression})";

    foreach (array_merge([''], $alsoBlank) as $blank) {
        $sql = "nullif({$sql}, '" . str_replace("'", "''", $blank) . "')";
    }

    return $sql;
}

/**
 * Keep what is stored when it is not blank, otherwise take what came in,
 * otherwise leave the row alone. Per row, so it batches.
 */
function fillBlank(string $column, array $alsoBlank = [])
{
    return NDB::raw(sprintf(
        'coalesce(%s, %s, %s)',
        blankChain($column, $alsoBlank),
        blankChain((string) NDB::incoming($column), $alsoBlank),
        $column
    ));
}

function saveStudents(array $students, int $size = 500): int
{
    $written = 0;

    foreach (array_chunk($students, $size) as $batch) {
        $written += NDB::table('vadana')->upsert($batch, ['national_code'], [
            'city' => fillBlank('city'),
            'code' => fillBlank('code'),
            'lessons' => fillBlank('lessons', ['[]', 'null']),
        ]);
    }

    return $written;
}

function student(string $code, ?string $name, ?string $city, ?string $classCode, ?string $lessons): array
{
    return [
        'name' => $name,
        'national_code' => $code,
        'student_code' => 'S-' . $code,
        'code' => $classCode,
        'city' => $city,
        'lessons' => $lessons,
    ];
}

saveStudents([
    student('001', 'علی رضایی', null, '', '[]'),
    student('002', 'مریم احمدی', 'اصفهان', 'B-2', '["شیمی"]'),
    student('003', 'حسن کریمی', '   ', null, 'null'),
]);

check('three rows landed', NDB::table('vadana')->count(), 3);
check('the blanks stayed blank',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '001');
        return [$row->city, $row->code, $row->lessons];
    })(),
    [null, '', '[]']);
check('the complete row is complete',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '002');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['اصفهان', 'B-2', '["شیمی"]']);

section('a second pass fills each row on its own terms');

saveStudents([
    student('001', 'نام دیگر', 'تهران', 'A-1', '["ریاضی"]'),   // every column blank, so all three fill
    student('002', 'نام دیگر', 'شیراز', 'Z-9', '["فیزیک"]'),   // nothing blank, so nothing changes
    student('003', 'نام دیگر', 'یزد', 'C-3', '["ادبیات"]'),    // whitespace and "null" count as blank
    student('004', 'زهرا موسوی', 'قم', 'D-4', '["زیست"]'),     // new, so a plain insert
]);

check('a fourth row was inserted', NDB::table('vadana')->count(), 4);

check('the blank row was filled',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '001');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['تهران', 'A-1', '["ریاضی"]']);

check('the full row was left alone',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '002');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['اصفهان', 'B-2', '["شیمی"]']);

check('whitespace and the literal null counted as blank',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '003');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['یزد', 'C-3', '["ادبیات"]']);

check('columns outside the update list never change',
    NDB::table('vadana')->orderBy('national_code')->pluck('name'),
    ['علی رضایی', 'مریم احمدی', 'حسن کریمی', 'زهرا موسوی']);

check('and neither does the student code',
    NDB::table('vadana')->firstWhere('national_code', '001')->student_code, 'S-001');

section('a blank incoming value cannot wipe a blank column');

saveStudents([student('001', 'x', '   ', '', 'null')]);

check('the stored values survive',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '001');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['تهران', 'A-1', '["ریاضی"]']);

saveStudents([student('005', 'کاربر خالی', '  ', '', '[]')]);

check('a new row keeps exactly what was sent',
    (function () {
        $row = NDB::table('vadana')->firstWhere('national_code', '005');
        return [$row->city, $row->code, $row->lessons];
    })(),
    ['  ', '', '[]']);

section('one statement per chunk, not per row');

check('a batch of 250 is a single statement',
    (function () {
        $students = [];

        for ($i = 100; $i < 350; $i++) {
            $students[] = student((string) $i, "دانشجو {$i}", 'مشهد', 'E-5', '["آمار"]');
        }

        NDB::connection()->flushQueryLog();
        NDB::enableQueryLog();

        saveStudents($students, 250);

        $log = NDB::getQueryLog();
        NDB::disableQueryLog();

        return count($log);
    })(),
    1);

check('all 250 arrived', NDB::table('vadana')->where('city', 'مشهد')->count(), 250);

check('a chunk size smaller than the input splits it',
    (function () {
        $students = [];

        for ($i = 400; $i < 410; $i++) {
            $students[] = student((string) $i, "دانشجو {$i}", 'تبریز', 'F-6', '["هندسه"]');
        }

        NDB::connection()->flushQueryLog();
        NDB::enableQueryLog();

        saveStudents($students, 4);

        $log = NDB::getQueryLog();
        NDB::disableQueryLog();

        return count($log);
    })(),
    3);

check('and every row still landed', NDB::table('vadana')->where('city', 'تبریز')->count(), 10);

section('the compiled sql, on both drivers');

$sqlite = NDB::connection()->getGrammar();
$mysql = NDB::connection('mysql')->getGrammar();

$rows = [
    ['national_code' => '001', 'city' => 'الف'],
    ['national_code' => '002', 'city' => 'ب'],
];

check('sqlite names the excluded row',
    $sqlite->compileUpsert(NDB::table('vadana'), $rows, ['national_code'], [
        'city' => NDB::raw('coalesce(nullif(trim(city), \'\'), ' . NDB::incoming('city') . ', city)'),
    ]),
    'insert into "vadana" ("national_code", "city") values (?, ?), (?, ?) '
    . 'on conflict ("national_code") do update set '
    . '"city" = coalesce(nullif(trim(city), \'\'), "excluded"."city", city)');

$mysqlQuery = (new Builder(NDB::connection('mysql')))->from('vadana');

check('mysql names the inserted values',
    $mysql->compileUpsert($mysqlQuery, $rows, ['national_code'], [
        'city' => NDB::raw('coalesce(nullif(trim(city), \'\'), ' . $mysqlQuery->incoming('city') . ', city)'),
    ]),
    'insert into `vadana` (`national_code`, `city`) values (?, ?), (?, ?) '
    . 'on duplicate key update '
    . '`city` = coalesce(nullif(trim(city), \'\'), values(`city`), city)');

check('no bindings are spent on the update clause',
    NDB::table('vadana')->upsert($rows, ['national_code'], ['city' => fillBlank('city')]) >= 0,
    true);

section('failures still surface');

throws('a row the database refuses throws',
    fn () => saveStudents([array_merge(student('006', 'x', 'x', 'x', 'x'), ['national_code' => null])]),
    QueryException::class);

check('and the whole chunk is refused with it',
    NDB::table('vadana')->where('national_code', '006')->count(), 0);

check('and it is recognisable',
    (function () {
        try {
            NDB::table('vadana')->insert(['national_code' => '001']);
        } catch (QueryException $e) {
            return $e->isUniqueViolation();
        }
    })(),
    true);

summary();
