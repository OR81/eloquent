<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Jalali;
echo "== known anchors (jalali -> gregorian) ==\n";

$anchors = [
    ['1403/01/01', '2024-03-20'],
    ['1403/05/26', '2024-08-16'],
    ['1403/12/30', '2025-03-20'],   // 1403 is a leap year
    ['1404/01/01', '2025-03-21'],
    ['1402/01/01', '2023-03-21'],
    ['1400/01/01', '2021-03-21'],
    ['1399/01/01', '2020-03-20'],
    ['1399/12/30', '2021-03-20'],   // 1399 is a leap year
    ['1379/01/01', '2000-03-20'],
    ['1357/11/22', '1979-02-11'],
    ['1300/01/01', '1921-03-21'],
    ['1450/01/01', '2071-03-21'],
];

foreach ($anchors as [$jalali, $gregorian]) {
    check("{$jalali} -> {$gregorian}", Jalali::parse($jalali)->toGregorianDateString(), $gregorian);
    check("{$gregorian} -> {$jalali}", Jalali::fromGregorian($gregorian)->toDateString(), $jalali);
}

echo "\n== round trip over 120 years ==\n";

$broken = [];
$start = new DateTimeImmutable('1950-01-01');
for ($i = 0; $i < 120 * 365; $i += 7) {
    $date = $start->modify("+{$i} days");
    $jalali = Jalali::fromGregorian($date);
    if ($jalali->toGregorianDateString() !== $date->format('Y-m-d')) {
        $broken[] = $date->format('Y-m-d');
    }
}
check('every sampled day round trips', $broken, []);

echo "\n== day counts ==\n";

$lengths = [];
foreach ([1399, 1400, 1401, 1402, 1403, 1404] as $year) {
    $lengths[$year] = Jalali::create($year, 1, 1)->toGregorian()->diff(
        Jalali::create($year + 1, 1, 1)->toGregorian()
    )->days;
}
check('year lengths are 365 or 366', array_values(array_unique($lengths)), [366, 365]);

check('leap years match the 366-day years',
    array_keys(array_filter($lengths, fn ($d) => $d === 366)),
    array_keys(array_filter($lengths, fn ($d, $y) => Jalali::isLeapJalaliYear($y), ARRAY_FILTER_USE_BOTH)));

check('days in month', [
    Jalali::daysInJalaliMonth(1403, 1),
    Jalali::daysInJalaliMonth(1403, 7),
    Jalali::daysInJalaliMonth(1403, 12),
    Jalali::daysInJalaliMonth(1404, 12),
], [31, 30, 30, 29]);

echo "\n== parsing ==\n";

check('slash separator', Jalali::parse('1403/5/26')->toDateString(), '1403/05/26');
check('dash separator', Jalali::parse('1403-05-26')->toDateString(), '1403/05/26');
check('with time', Jalali::parse('1403/05/26 14:30:59')->toDateTimeString(), '1403/05/26 14:30:59');
check('persian digits', Jalali::parse('۱۴۰۳/۰۵/۲۶')->toDateString(), '1403/05/26');
check('arabic digits', Jalali::parse('١٤٠٣/٠٥/٢٦')->toDateString(), '1403/05/26');
check('array', Jalali::parse([1403, 5, 26])->toDateString(), '1403/05/26');
check('DateTimeInterface routes to fromGregorian',
    Jalali::parse(new DateTimeImmutable('2024-08-16'))->toDateString(), '1403/05/26');
check('timestamp', Jalali::fromTimestamp(mktime(0, 0, 0, 8, 16, 2024))->toDateString(), '1403/05/26');

$threw = false;
try {
    Jalali::parse('1403/13/01');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('month 13 is rejected', $threw, true);

$threw = false;
try {
    Jalali::create(1404, 12, 30);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('esfand 30 in a non-leap year is rejected', $threw, true);

echo "\n== formatting ==\n";

$date = Jalali::parse('1403/05/26 09:05:03');

check('Y/m/d', $date->format('Y/m/d'), '1403/05/26');
check('j F Y', $date->format('j F Y'), '26 مرداد 1403');
check('day name', $date->format('l'), 'جمعه');
check('full', $date->format('l j F Y ساعت H:i'), 'جمعه 26 مرداد 1403 ساعت 09:05');
check('escaped token', $date->format('\\Y=Y'), 'Y=1403');
check('day of year', $date->format('z'), '150');
check('leap flag + days in month', $date->format('L t'), '1 31');
check('meridiem', [$date->format('a'), $date->addDays(0)->format('A')], ['ق.ظ', 'قبل از ظهر']);
check('persian digits helper', Jalali::toPersianDigits($date->toDateString()), '۱۴۰۳/۰۵/۲۶');

echo "\n== arithmetic ==\n";

check('addDays crosses a month', Jalali::parse('1403/06/31')->addDays(1)->toDateString(), '1403/07/01');
check('addDays crosses a year', Jalali::parse('1403/12/30')->addDays(1)->toDateString(), '1404/01/01');
check('subDays', Jalali::parse('1404/01/01')->subDays(1)->toDateString(), '1403/12/30');
check('addMonths clamps the day', Jalali::parse('1403/06/31')->addMonths(1)->toDateString(), '1403/07/30');
check('addMonths wraps the year', Jalali::parse('1403/11/15')->addMonths(3)->toDateString(), '1404/02/15');
check('subMonths wraps back', Jalali::parse('1403/01/15')->subMonths(2)->toDateString(), '1402/11/15');
check('addYears clamps esfand 30', Jalali::parse('1403/12/30')->addYears(1)->toDateString(), '1404/12/29');

check('startOfMonth / endOfMonth',
    [Jalali::parse('1403/05/26')->startOfMonth()->toDateString(), Jalali::parse('1403/05/26')->endOfMonth()->toDateString()],
    ['1403/05/01', '1403/05/31']);

check('startOfYear / endOfYear',
    [Jalali::parse('1403/05/26')->startOfYear()->toDateString(), Jalali::parse('1403/05/26')->endOfYear()->toDateString()],
    ['1403/01/01', '1403/12/30']);

echo "\n== persian week (saturday first) ==\n";

// 1403/05/26 = 2024-08-16, a Friday, so its week runs 1403/05/20 (Sat) .. 1403/05/26 (Fri)
check('dayOfWeek of a friday', Jalali::parse('1403/05/26')->dayOfWeek(), 6);
check('startOfWeek is saturday', Jalali::parse('1403/05/26')->startOfWeek()->toDateString(), '1403/05/20');
check('endOfWeek is friday', Jalali::parse('1403/05/26')->endOfWeek()->toDateString(), '1403/05/26');
check('saturday opens the week', Jalali::parse('1403/05/20')->format('l'), 'شنبه');
check('friday closes the week', Jalali::parse('1403/05/26')->format('l'), 'جمعه');
check('a saturday is its own start of week',
    Jalali::parse('1403/05/20')->startOfWeek()->toDateString(), '1403/05/20');
check('every weekday maps to the right name',
    array_map(fn ($i) => Jalali::parse('1403/05/20')->addDays($i)->format('l'), range(0, 6)),
    ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه']);

echo "\n== storage detection ==\n";

check('jalali slash', Jalali::detectStorage('1403/05/26'), [Jalali::JALALI, 'Y/m/d']);
check('jalali dash', Jalali::detectStorage('1403-05-26'), [Jalali::JALALI, 'Y-m-d']);
check('jalali unpadded', Jalali::detectStorage('1403/5/6'), [Jalali::JALALI, 'Y/n/j']);
check('jalali with time', Jalali::detectStorage('1403/05/26 14:30:00'), [Jalali::JALALI, 'Y/m/d']);
check('gregorian date', Jalali::detectStorage('2024-08-16'), [Jalali::GREGORIAN, 'Y-m-d']);
check('gregorian datetime', Jalali::detectStorage('2024-08-16 14:30:00'), [Jalali::GREGORIAN, 'Y-m-d']);
check('gregorian slash varchar', Jalali::detectStorage('2024/08/16'), [Jalali::GREGORIAN, 'Y/m/d']);
check('int timestamp', Jalali::detectStorage(1723800000), [Jalali::UNIX, null]);
check('string timestamp', Jalali::detectStorage('1723800000'), [Jalali::UNIX, null]);
check('null is undecidable', Jalali::detectStorage(null), null);
check('garbage is undecidable', Jalali::detectStorage('not a date'), null);

check('sortable formats',
    [Jalali::isSortableFormat('Y/m/d'), Jalali::isSortableFormat('Y-m-d H:i:s'), Jalali::isSortableFormat('Y/n/j'), Jalali::isSortableFormat('d/m/Y')],
    [true, true, false, false]);

check('the default format of each mode', [
    Jalali::defaultFormatFor(Jalali::GREGORIAN),
    Jalali::defaultFormatFor(Jalali::JALALI),
    Jalali::defaultFormatFor(Jalali::UNIX),
], ['Y-m-d', 'Y/m/d', null]);

throws('an unknown mode is refused',
    fn () => Jalali::defaultFormatFor('hijri'),
    InvalidArgumentException::class,
    'Unknown date storage');

echo "\n== time of day ==\n";

$moment = Jalali::create(1403, 5, 26, 14, 5, 9);

check('hour, minute, second', [$moment->hour(), $moment->minute(), $moment->second()], [14, 5, 9]);
check('toArray drops the time', $moment->toArray(), [1403, 5, 26]);
check('startOfDay zeroes the clock', $moment->startOfDay()->toDateTimeString(), '1403/05/26 00:00:00');
check('endOfDay pins it to the last second', $moment->endOfDay()->toDateTimeString(), '1403/05/26 23:59:59');
check('toGregorianDateTimeString keeps the time', $moment->toGregorianDateTimeString(), '2024-08-16 14:05:09');
check('toTimestamp round trips',
    Jalali::fromTimestamp($moment->toTimestamp())->toDateTimeString(), '1403/05/26 14:05:09');
check('__toString is the full datetime', (string) $moment, '1403/05/26 14:05:09');

throws('an impossible hour is refused',
    fn () => Jalali::create(1403, 5, 26, 24),
    InvalidArgumentException::class,
    'Invalid time component');

echo "\n== comparisons ==\n";

$earlier = Jalali::parse('1403/05/26');
$later = Jalali::parse('1403/05/27');

check('compareTo orders them', [
    $earlier->compareTo($later),
    $later->compareTo($earlier),
    $earlier->compareTo(Jalali::parse('1403/05/26')),
], [-1, 1, 0]);

check('lessThan / greaterThan / equalTo', [
    $earlier->lessThan($later),
    $earlier->greaterThan($later),
    $earlier->equalTo(Jalali::parse('1403/05/26')),
], [true, false, true]);

check('the time of day counts too',
    Jalali::create(1403, 5, 26, 9)->lessThan(Jalali::create(1403, 5, 26, 10)), true);

echo "\n== subYears and the static converters ==\n";

check('subYears', Jalali::parse('1404/05/26')->subYears(1)->toDateString(), '1403/05/26');
check('subYears clamps esfand 30', Jalali::parse('1404/12/29')->subYears(1)->toDateString(), '1403/12/29');

check('jalaliToGregorian returns a triple', Jalali::jalaliToGregorian(1403, 5, 26), [2024, 8, 16]);
check('gregorianToJalali returns a triple', Jalali::gregorianToJalali(2024, 8, 16), [1403, 5, 26]);
check('the two are inverses',
    Jalali::gregorianToJalali(...Jalali::jalaliToGregorian(1399, 12, 30)), [1399, 12, 30]);

check('isLeapGregorianYear', [
    Jalali::isLeapGregorianYear(2024),
    Jalali::isLeapGregorianYear(2023),
    Jalali::isLeapGregorianYear(1900),
    Jalali::isLeapGregorianYear(2000),
], [true, false, false, true]);

throws('daysInJalaliMonth rejects an impossible month',
    fn () => Jalali::daysInJalaliMonth(1403, 13),
    InvalidArgumentException::class,
    'Invalid Jalali month');

throws('parse refuses a value it cannot read',
    fn () => Jalali::parse(1403),
    InvalidArgumentException::class,
    'must be a string');

throws('fromGregorian refuses one too',
    fn () => Jalali::fromGregorian([2024, 8, 16]),
    InvalidArgumentException::class,
    'must be a DateTimeInterface');

summary();
