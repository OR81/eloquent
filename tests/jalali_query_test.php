<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\Jalali;
use Or81\Eloquent\NDB;
NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

/*
 | One row per day, written into four differently-shaped columns:
 |   g_date      date       gregorian  2024-08-16
 |   g_datetime  datetime   gregorian  2024-08-16 14:30:00
 |   j_text      varchar    jalali     1403/05/26
 |   ts          integer    unix       1723800000
 */
NDB::unprepared('create table orders (
    id integer primary key autoincrement,
    title text,
    g_date text,
    g_datetime text,
    j_text text,
    j_dash text,
    ts integer
)');

$days = [];
$cursor = Jalali::create(1403, 5, 20);

for ($i = 0; $i < 60; $i++) {
    $date = $cursor->addDays($i);
    $withTime = Jalali::create($date->year(), $date->month(), $date->day(), 14, 30, 0);

    $days[] = [
        'title' => $date->toDateString(),
        'g_date' => $date->toGregorianDateString(),
        'g_datetime' => $withTime->toGregorianDateTimeString(),
        'j_text' => $date->format('Y/m/d'),
        'j_dash' => $date->format('Y-m-d'),
        'ts' => $withTime->toTimestamp(),
    ];
}

NDB::table('orders')->insert($days);

// A second block a year later, to exercise the year clauses.
NDB::table('orders')->insert([[
    'title' => '1404/05/26',
    'g_date' => Jalali::create(1404, 5, 26)->toGregorianDateString(),
    'g_datetime' => Jalali::create(1404, 5, 26, 8, 0, 0)->toGregorianDateTimeString(),
    'j_text' => '1404/05/26',
    'j_dash' => '1404-05-26',
    'ts' => Jalali::create(1404, 5, 26, 8, 0, 0)->toTimestamp(),
]]);

check('fixture size', NDB::table('orders')->count(), 61);

/**
 * Runs the same assertion against every storage shape.
 */
function everyStorage(string $label, callable $apply, $expected): void
{
    $columns = [
        'g_date' => [Jalali::GREGORIAN, 'Y-m-d'],
        'g_datetime' => [Jalali::GREGORIAN, 'Y-m-d'],
        'j_text' => [Jalali::JALALI, 'Y/m/d'],
        'j_dash' => [Jalali::JALALI, 'Y-m-d'],
        'ts' => [Jalali::UNIX, null],
    ];

    foreach ($columns as $column => [$mode, $format]) {
        $query = NDB::table('orders')->dateStorage($column, $mode, $format);

        check("{$label} [{$column}]", $apply($query, $column), $expected);
    }
}

section('a single day');

everyStorage('one day', fn (Builder $q, string $c) => $q->whereJalaliDate($c, '1403/05/26')->pluck('title'), ['1403/05/26']);

everyStorage('one day, other direction',
    fn (Builder $q, string $c) => $q->whereJalali($c, '1403/06/01')->pluck('title'), ['1403/06/01']);

everyStorage('a day with no rows',
    fn (Builder $q, string $c) => $q->whereJalaliDate($c, '1390/01/01')->pluck('title'), []);

section('comparisons');

// The 60-day block runs 1403/05/20 .. 1403/07/17, plus the single 1404/05/26 row.
everyStorage('>= includes the day itself',
    fn (Builder $q, string $c) => $q->whereJalali($c, '>=', '1403/07/15')->pluck('title'),
    ['1403/07/15', '1403/07/16', '1403/07/17', '1404/05/26']);

everyStorage('> excludes the day itself',
    fn (Builder $q, string $c) => $q->whereJalali($c, '>', '1403/07/15')->pluck('title'),
    ['1403/07/16', '1403/07/17', '1404/05/26']);

everyStorage('< excludes the day itself',
    fn (Builder $q, string $c) => $q->whereJalali($c, '<', '1403/05/22')->pluck('title'),
    ['1403/05/20', '1403/05/21']);

everyStorage('<= includes the day itself',
    fn (Builder $q, string $c) => $q->whereJalali($c, '<=', '1403/05/22')->pluck('title'),
    ['1403/05/20', '1403/05/21', '1403/05/22']);

everyStorage('!= excludes just that day',
    fn (Builder $q, string $c) => $q->whereJalali($c, '!=', '1403/05/26')->count(), 60);

section('ranges');

everyStorage('between is inclusive at both ends',
    fn (Builder $q, string $c) => $q->whereJalaliBetween($c, ['1403/05/25', '1403/05/28'])->pluck('title'),
    ['1403/05/25', '1403/05/26', '1403/05/27', '1403/05/28']);

everyStorage('a reversed range is normalised',
    fn (Builder $q, string $c) => $q->whereJalaliBetween($c, ['1403/05/28', '1403/05/25'])->count(), 4);

everyStorage('not between',
    fn (Builder $q, string $c) => $q->whereJalaliNotBetween($c, ['1403/05/21', '1403/07/17'])->pluck('title'),
    ['1403/05/20', '1404/05/26']);

section('month and year');

everyStorage('a whole jalali month',
    fn (Builder $q, string $c) => $q->whereJalaliMonth($c, 1403, 6)->count(), 31);

everyStorage('mordad 1403 is only the tail of the block',
    fn (Builder $q, string $c) => $q->whereJalaliMonth($c, 1403, 5)->pluck('title'),
    ['1403/05/20', '1403/05/21', '1403/05/22', '1403/05/23', '1403/05/24', '1403/05/25',
     '1403/05/26', '1403/05/27', '1403/05/28', '1403/05/29', '1403/05/30', '1403/05/31']);

everyStorage('a whole jalali year',
    fn (Builder $q, string $c) => $q->whereJalaliYear($c, 1403)->count(), 60);

everyStorage('the next jalali year',
    fn (Builder $q, string $c) => $q->whereJalaliYear($c, 1404)->pluck('title'), ['1404/05/26']);

section('the persian week');

// 1403/05/20 is a Saturday, so that week runs 05/20 .. 05/26
everyStorage('the week of a given day, saturday to friday',
    fn (Builder $q, string $c) => $q->whereJalaliWeek($c, '1403/05/24')->pluck('title'),
    ['1403/05/20', '1403/05/21', '1403/05/22', '1403/05/23', '1403/05/24', '1403/05/25', '1403/05/26']);

everyStorage('a week starting on its own saturday',
    fn (Builder $q, string $c) => $q->whereJalaliWeek($c, '1403/05/20')->count(), 7);

everyStorage('a week asked for from its friday',
    fn (Builder $q, string $c) => $q->whereJalaliWeek($c, '1403/05/26')->count(), 7);

section('boolean chaining');

check('orWhereJalaliDate keeps the groups separate',
    NDB::table('orders')
        ->where('title', '1403/05/20')
        ->orWhereJalaliDate('g_date', '1403/05/26')
        ->pluck('title'),
    ['1403/05/20', '1403/05/26']);

check('a jalali clause ANDs correctly with a normal one',
    NDB::table('orders')
        ->where('title', 'like', '1403/06/%')
        ->whereJalaliBetween('g_date', ['1403/06/01', '1403/06/03'])
        ->pluck('title'),
    ['1403/06/01', '1403/06/02', '1403/06/03']);

check('two jalali clauses intersect',
    NDB::table('orders')
        ->whereJalali('g_date', '>=', '1403/06/01')
        ->whereJalali('g_date', '<=', '1403/06/03')
        ->pluck('title'),
    ['1403/06/01', '1403/06/02', '1403/06/03']);

check('orWhereJalaliYear',
    NDB::table('orders')
        ->whereJalaliDate('g_date', '1403/05/20')
        ->orWhereJalaliYear('g_date', 1404)
        ->pluck('title'),
    ['1403/05/20', '1404/05/26']);

section('generated sql');

check('a jalali day against a gregorian column',
    NDB::table('orders')->whereJalaliDate('g_date', '1403/05/26')->toRawSql(),
    'select * from "orders" where ("g_date" >= \'2024-08-16\' and "g_date" < \'2024-08-17\')');

check('a jalali day against a jalali column',
    NDB::table('orders')->dateStorage('j_text', Jalali::JALALI)->whereJalaliDate('j_text', '1403/05/26')->toRawSql(),
    'select * from "orders" where ("j_text" >= \'1403/05/26\' and "j_text" < \'1403/05/27\')');

check('a jalali day against a unix column',
    NDB::table('orders')->dateStorage('ts', Jalali::UNIX)->whereJalaliDate('ts', '1403/05/26')->getBindings(),
    [Jalali::create(1403, 5, 26)->toTimestamp(), Jalali::create(1403, 5, 27)->toTimestamp()]);

check('the column is never wrapped in a function',
    strpos(NDB::table('orders')->whereJalaliYear('g_datetime', 1403)->toSql(), '(') !== false
        && strpos(NDB::table('orders')->whereJalaliYear('g_datetime', 1403)->toSql(), 'strftime') === false,
    true);

section('storage detection');

check('detects a gregorian date column',
    (NDB::table('orders')->detectDateStorage('g_date')->whereJalaliDate('g_date', '1403/05/26'))->pluck('title'),
    ['1403/05/26']);

check('detects a jalali varchar column',
    NDB::table('orders')->detectDateStorage('j_text')->whereJalaliDate('j_text', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('detects a dash-separated jalali column',
    NDB::table('orders')->detectDateStorage('j_dash')->whereJalaliDate('j_dash', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('detects a unix column',
    NDB::table('orders')->detectDateStorage('ts')->whereJalaliDate('ts', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('detects a gregorian datetime column',
    NDB::table('orders')->detectDateStorage('g_datetime')->whereJalaliDate('g_datetime', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('detection can do every column at once',
    NDB::table('orders')
        ->detectDateStorage('g_date', 'j_text', 'ts')
        ->whereJalaliDate('g_date', '1403/05/26')
        ->whereJalaliDate('j_text', '1403/05/26')
        ->whereJalaliDate('ts', '1403/05/26')
        ->pluck('title'),
    ['1403/05/26']);

section('unsortable storage is refused');

$message = null;
try {
    NDB::table('orders')->dateStorage('j_text', Jalali::JALALI, 'Y/n/j')->whereJalaliToday('j_text')->get();
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
check('an unpadded format is rejected with an explanation',
    $message !== null && strpos($message, 'does not sort chronologically') !== false, true);

section('casting results to jalali');

check('cast a gregorian column on the way out',
    NDB::table('orders')->castJalali('g_datetime', 'Y/m/d H:i')->whereJalaliDate('g_date', '1403/05/26')
        ->value('g_datetime'),
    '1403/05/26 14:30');

check('cast a unix column',
    NDB::table('orders')->dateStorage('ts', Jalali::UNIX)->castJalali('ts', 'l j F Y')
        ->whereJalaliDate('g_date', '1403/05/26')->value('ts'),
    'جمعه 26 مرداد 1403');

check('casting leaves other columns alone',
    plain(NDB::table('orders')->castJalali('g_date', 'Y/m/d')->whereJalaliDate('g_date', '1403/05/26')
        ->first(['title', 'g_date'])),
    ['title' => '1403/05/26', 'g_date' => '1403/05/26']);

check('casts apply to cursor() too',
    (function () {
        foreach (NDB::table('orders')->castJalali('g_date', 'Y/m/d')->whereJalaliDate('g_date', '1403/05/26')->cursor(['g_date']) as $row) {
            return $row['g_date'];
        }
        return null;
    })(),
    '1403/05/26');

section('relative helpers');

$today = Jalali::today();

NDB::table('orders')->insert([
    ['title' => 'today', 'g_date' => $today->toGregorianDateString()],
    ['title' => 'yesterday', 'g_date' => $today->subDays(1)->toGregorianDateString()],
    ['title' => 'tomorrow', 'g_date' => $today->addDays(1)->toGregorianDateString()],
    ['title' => 'six days ago', 'g_date' => $today->subDays(6)->toGregorianDateString()],
    ['title' => 'twenty days ago', 'g_date' => $today->subDays(20)->toGregorianDateString()],
]);

check('whereJalaliToday', NDB::table('orders')->whereJalaliToday('g_date')->pluck('title'), ['today']);
check('whereJalaliYesterday', NDB::table('orders')->whereJalaliYesterday('g_date')->pluck('title'), ['yesterday']);
check('whereJalaliTomorrow', NDB::table('orders')->whereJalaliTomorrow('g_date')->pluck('title'), ['tomorrow']);

check('whereJalaliLastDays(7) covers today back six days',
    NDB::table('orders')->whereJalaliLastDays('g_date', 7)->whereIn('title', ['today', 'yesterday', 'tomorrow', 'six days ago', 'twenty days ago'])->pluck('title'),
    ['today', 'yesterday', 'six days ago']);

check('whereJalaliThisYear finds the rows added today',
    NDB::table('orders')->whereJalaliThisYear('g_date')->where('title', 'today')->count(), 1);

check('whereJalaliThisMonth finds today',
    NDB::table('orders')->whereJalaliThisMonth('g_date')->where('title', 'today')->count(), 1);

check('whereJalaliLastMonth excludes today',
    NDB::table('orders')->whereJalaliLastMonth('g_date')->where('title', 'today')->count(), 0);

section('model-level declaration');

class Order extends NDB
{
    protected static array $dateStorage = [
        'j_text' => [Jalali::JALALI, 'Y/m/d'],
        'ts' => Jalali::UNIX,
    ];
}

check('the model table is inferred', Order::tableName(), 'orders');

check('a declared jalali column works without any per-query setup',
    Order::query()->whereJalaliDate('j_text', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('a declared unix column works too',
    Order::query()->whereJalaliDate('ts', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

check('undeclared columns fall back to gregorian',
    Order::query()->whereJalaliDate('g_date', '1403/05/26')->pluck('title'),
    ['1403/05/26']);

section('connection-level default');

NDB::configure(['driver' => 'sqlite', 'database' => ':memory:', 'date_storage' => Jalali::JALALI], 'legacy');
NDB::connection('legacy')->unprepared('create table logs (id integer primary key, at text)');
NDB::connection('legacy')->getPdo();

$legacy = new Builder(NDB::connection('legacy'));
$legacy->from('logs')->insert([['at' => '1403/05/26'], ['at' => '1403/05/27']]);

$query = new Builder(NDB::connection('legacy'));
check('the whole connection defaults to jalali storage',
    $query->from('logs')->whereJalaliDate('at', '1403/05/26')->pluck('at'),
    ['1403/05/26']);

summary();
