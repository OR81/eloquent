<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Jalali;
use Or81\Eloquent\NDB;
NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table orders (id integer primary key autoincrement, created_at text)');
NDB::unprepared('create table invoices (id integer primary key autoincrement, issued_at text, paid_at text, logged_at integer)');

NDB::table('orders')->insert([
    ['created_at' => '2024-08-16 14:30:00'],
    ['created_at' => '2024-08-17 09:00:00'],
    ['created_at' => Jalali::today()->toGregorianDateString() . ' 10:00:00'],
]);

NDB::table('invoices')->insert([
    ['issued_at' => '1403/05/26', 'paid_at' => '1403-05-27', 'logged_at' => Jalali::create(1403, 5, 26)->toTimestamp()],
    ['issued_at' => '1403/06/02', 'paid_at' => '1403-06-03', 'logged_at' => Jalali::create(1403, 6, 2)->toTimestamp()],
]);

echo "== README: opening examples ==\n";

check('whereJalaliDate', count(NDB::table('orders')->whereJalaliDate('created_at', '1403/05/26')->get()), 1);
check('whereJalaliBetween', count(NDB::table('orders')->whereJalaliBetween('created_at', ['1403/01/01', '1403/06/31'])->get()), 2);
check('whereJalaliThisMonth', NDB::table('orders')->whereJalaliThisMonth('created_at')->count(), 1);

check('the documented SQL is exactly what is produced',
    NDB::table('orders')->whereJalaliDate('created_at', '1403/05/26')->toRawSql(),
    'select * from "orders" where ("created_at" >= \'2024-08-16\' and "created_at" < \'2024-08-17\')');

echo "\n== README: declaring storage ==\n";

check('per-query dateStorage chain',
    count(NDB::table('invoices')
        ->dateStorage('issued_at', Jalali::JALALI)
        ->dateStorage('paid_at', Jalali::JALALI, 'Y-m-d')
        ->dateStorage('logged_at', Jalali::UNIX)
        ->whereJalaliMonth('issued_at', 1403, 5)
        ->get()),
    1);

check('the three modes agree on the same day', [
    NDB::table('invoices')->dateStorage('issued_at', Jalali::JALALI)->whereJalaliDate('issued_at', '1403/05/26')->count(),
    NDB::table('invoices')->dateStorage('paid_at', Jalali::JALALI, 'Y-m-d')->whereJalaliDate('paid_at', '1403/05/27')->count(),
    NDB::table('invoices')->dateStorage('logged_at', Jalali::UNIX)->whereJalaliDate('logged_at', '1403/05/26')->count(),
], [1, 1, 1]);

class Invoice extends NDB
{
    protected static array $dateStorage = [
        'issued_at' => [Jalali::JALALI, 'Y/m/d'],
        'paid_at' => [Jalali::JALALI, 'Y-m-d'],
        'logged_at' => Jalali::UNIX,
    ];
}

check('the model example from the README',
    Invoice::query()->whereJalaliYear('issued_at', 1403)->count(), 2);

check('detectDateStorage example',
    count(NDB::table('invoices')->detectDateStorage('issued_at')->whereJalaliDate('issued_at', '1403/05/26')->get()), 1);

echo "\n== README: every documented clause runs ==\n";

$clauses = [
    "whereJalali day" => fn () => NDB::table('orders')->whereJalali('created_at', '1403/05/26'),
    "whereJalali >=" => fn () => NDB::table('orders')->whereJalali('created_at', '>=', '1403/05/26'),
    "whereJalaliDate" => fn () => NDB::table('orders')->whereJalaliDate('created_at', '1403/05/26'),
    "whereJalaliBetween" => fn () => NDB::table('orders')->whereJalaliBetween('created_at', ['1403/01/01', '1403/06/31']),
    "whereJalaliNotBetween" => fn () => NDB::table('orders')->whereJalaliNotBetween('created_at', ['1403/01/01', '1403/06/31']),
    "whereJalaliYear" => fn () => NDB::table('orders')->whereJalaliYear('created_at', 1403),
    "whereJalaliMonth" => fn () => NDB::table('orders')->whereJalaliMonth('created_at', 1403, 5),
    "whereJalaliWeek" => fn () => NDB::table('orders')->whereJalaliWeek('created_at', '1403/05/24'),
    "whereJalaliToday" => fn () => NDB::table('orders')->whereJalaliToday('created_at'),
    "whereJalaliYesterday" => fn () => NDB::table('orders')->whereJalaliYesterday('created_at'),
    "whereJalaliTomorrow" => fn () => NDB::table('orders')->whereJalaliTomorrow('created_at'),
    "whereJalaliThisWeek" => fn () => NDB::table('orders')->whereJalaliThisWeek('created_at'),
    "whereJalaliThisMonth" => fn () => NDB::table('orders')->whereJalaliThisMonth('created_at'),
    "whereJalaliLastMonth" => fn () => NDB::table('orders')->whereJalaliLastMonth('created_at'),
    "whereJalaliThisYear" => fn () => NDB::table('orders')->whereJalaliThisYear('created_at'),
    "whereJalaliLastDays" => fn () => NDB::table('orders')->whereJalaliLastDays('created_at', 7),
    "orWhereJalali" => fn () => NDB::table('orders')->where('id', 1)->orWhereJalali('created_at', '1403/05/26'),
    "orWhereJalaliDate" => fn () => NDB::table('orders')->where('id', 1)->orWhereJalaliDate('created_at', '1403/05/26'),
    "orWhereJalaliBetween" => fn () => NDB::table('orders')->where('id', 1)->orWhereJalaliBetween('created_at', ['1403/01/01', '1403/12/29']),
    "orWhereJalaliYear" => fn () => NDB::table('orders')->where('id', 1)->orWhereJalaliYear('created_at', 1403),
    "orWhereJalaliMonth" => fn () => NDB::table('orders')->where('id', 1)->orWhereJalaliMonth('created_at', 1403, 5),
];

$broken = [];
foreach ($clauses as $label => $build) {
    try {
        $build()->get();
    } catch (Throwable $e) {
        $broken[$label] = $e->getMessage();
    }
}
check('all documented clauses execute', $broken, []);

echo "\n== README: accepted value shapes ==\n";

check('string, array, Jalali and DateTimeInterface are interchangeable', [
    NDB::table('orders')->whereJalaliDate('created_at', '1403/05/26')->count(),
    NDB::table('orders')->whereJalaliDate('created_at', '1403-5-26')->count(),
    NDB::table('orders')->whereJalaliDate('created_at', '۱۴۰۳/۰۵/۲۶')->count(),
    NDB::table('orders')->whereJalaliDate('created_at', [1403, 5, 26])->count(),
    NDB::table('orders')->whereJalaliDate('created_at', Jalali::create(1403, 5, 26))->count(),
    NDB::table('orders')->whereJalaliDate('created_at', new DateTimeImmutable('2024-08-16'))->count(),
], [1, 1, 1, 1, 1, 1]);

echo "\n== README: ordering needs nothing special ==\n";

check('orderBy on a gregorian column',
    NDB::table('orders')->orderBy('created_at')->limit(2)->pluck('created_at'),
    ['2024-08-16 14:30:00', '2024-08-17 09:00:00']);

check('orderBy on a jalali text column',
    NDB::table('invoices')->orderByDesc('issued_at')->pluck('issued_at'),
    ['1403/06/02', '1403/05/26']);

echo "\n== README: castJalali ==\n";

check('the documented cast output',
    NDB::table('orders')->castJalali('created_at', 'l j F Y ساعت H:i')->whereJalaliDate('created_at', '1403/05/26')->value('created_at'),
    'جمعه 26 مرداد 1403 ساعت 14:30');

echo "\n== README: the Jalali class walkthrough ==\n";

$date = Jalali::parse('1403/05/26');

check('accessors', [$date->year(), $date->month(), $date->day()], [1403, 5, 26]);
check('dayOfWeek', $date->dayOfWeek(), 6);
check('dayOfYear', $date->dayOfYear(), 150);
check('daysInMonth', $date->daysInMonth(), 31);
check('isLeapYear', $date->isLeapYear(), true);
check('monthName', $date->monthName(), 'مرداد');
check('dayName', $date->dayName(), 'جمعه');
check('toDateString', $date->toDateString(), '1403/05/26');
check('toGregorianDateString', $date->toGregorianDateString(), '2024-08-16');
check('toGregorian', $date->toGregorian()->format('Y-m-d'), '2024-08-16');
check('format', $date->format('l j F Y'), 'جمعه 26 مرداد 1403');
check('comparisons', [
    $date->lessThan(Jalali::parse('1403/05/27')),
    $date->greaterThan(Jalali::parse('1403/05/25')),
    $date->equalTo(Jalali::parse('1403/05/26')),
], [true, true, true]);
check('digits round trip', Jalali::toEnglishDigits(Jalali::toPersianDigits('1403/05/26')), '1403/05/26');
check('immutability: the original is untouched',
    [$date->addDays(10)->toDateString(), $date->toDateString()],
    ['1403/06/05', '1403/05/26']);

echo "\n== README: verta interop ==\n";

class FakeVerta
{
    public function datetime(): DateTimeImmutable
    {
        return new DateTimeImmutable('2024-08-16 14:30:00');
    }
}

check('an object exposing datetime() is accepted',
    Jalali::parse(new FakeVerta())->toDateTimeString(), '1403/05/26 14:30:00');

check('and can be handed straight to a clause',
    NDB::table('orders')->whereJalaliDate('created_at', new FakeVerta())->count(), 1);

summary();
