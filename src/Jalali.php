<?php

namespace Or81\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * An immutable Jalali (Shamsi) date, modelled on the verta package but with
 * no dependencies. Every mutator returns a new instance.
 *
 *     Jalali::parse('1403/05/26')->addDays(10)->format('l j F Y');
 *     Jalali::fromGregorian('2024-08-16')->toDateString();   // 1403/05/26
 *
 * It also describes how a date column is stored, which is what the query
 * builder needs in order to compare against it:
 *
 *   GREGORIAN  a Gregorian value: date, datetime, MySQL timestamp, or a
 *              varchar holding something like '2024-08-16'
 *   JALALI     a varchar (or date) holding a Jalali value like '1403/05/26'
 *   UNIX       an integer column holding seconds since the epoch
 */
class Jalali
{
    public const GREGORIAN = 'gregorian';
    public const JALALI = 'jalali';
    public const UNIX = 'unix';

    public const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /** Indexed from Saturday, the first day of the Persian week. */
    public const DAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

    public const SHORT_DAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    protected int $year;
    protected int $month;
    protected int $day;
    protected int $hour;
    protected int $minute;
    protected int $second;

    public function __construct(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0)
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Invalid Jalali month [{$month}].");
        }

        $daysInMonth = self::daysInJalaliMonth($year, $month);

        if ($day < 1 || $day > $daysInMonth) {
            throw new InvalidArgumentException("Invalid Jalali day [{$day}] for {$year}/{$month}.");
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new InvalidArgumentException('Invalid time component.');
        }

        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
        $this->hour = $hour;
        $this->minute = $minute;
        $this->second = $second;
    }

    /* ------------------------------------------------------------------
     | Constructors
     | ------------------------------------------------------------------ */

    public static function create(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0): self
    {
        return new self($year, $month, $day, $hour, $minute, $second);
    }

    public static function now(?DateTimeZone $timezone = null): self
    {
        return self::fromGregorian(new DateTimeImmutable('now', $timezone));
    }

    public static function today(?DateTimeZone $timezone = null): self
    {
        return self::now($timezone)->startOfDay();
    }

    /**
     * Read a Jalali value: '1403/05/26', '1403-5-26 14:30:00', [1403, 5, 26],
     * another Jalali, or any DateTimeInterface (which is converted).
     *
     * Persian and Arabic digits are accepted.
     *
     * @param Jalali|DateTimeInterface|array|string $value
     */
    public static function parse($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return self::fromGregorian($value);
        }

        // Interop with verta and similar wrappers.
        if (is_object($value) && method_exists($value, 'datetime')) {
            return self::fromGregorian($value->datetime());
        }

        if (is_array($value)) {
            $value = array_values($value) + [0, 1, 1, 0, 0, 0];

            return new self((int) $value[0], (int) $value[1], (int) $value[2], (int) $value[3], (int) $value[4], (int) $value[5]);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A Jalali date must be a string, an array, a Jalali or a DateTimeInterface.');
        }

        $normalized = trim(self::toEnglishDigits($value));

        $pattern = '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?:[\sT]+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/';

        if (! preg_match($pattern, $normalized, $matches)) {
            throw new InvalidArgumentException("Unrecognised Jalali date [{$value}].");
        }

        return new self(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            (int) ($matches[4] ?? 0),
            (int) ($matches[5] ?? 0),
            (int) ($matches[6] ?? 0)
        );
    }

    /**
     * @param DateTimeInterface|string|int $value a date, an ISO string, or a Unix timestamp
     */
    public static function fromGregorian($value): self
    {
        if (is_int($value)) {
            return self::fromTimestamp($value);
        }

        if (is_string($value)) {
            $value = new DateTimeImmutable(self::toEnglishDigits(trim($value)));
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('A Gregorian date must be a DateTimeInterface, a string or a timestamp.');
        }

        [$year, $month, $day] = self::gregorianToJalali(
            (int) $value->format('Y'),
            (int) $value->format('n'),
            (int) $value->format('j')
        );

        return new self($year, $month, $day, (int) $value->format('G'), (int) $value->format('i'), (int) $value->format('s'));
    }

    public static function fromTimestamp(int $timestamp): self
    {
        return self::fromGregorian((new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get())));
    }

    /* ------------------------------------------------------------------
     | Accessors
     | ------------------------------------------------------------------ */

    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function day(): int
    {
        return $this->day;
    }

    public function hour(): int
    {
        return $this->hour;
    }

    public function minute(): int
    {
        return $this->minute;
    }

    public function second(): int
    {
        return $this->second;
    }

    /**
     * @return int 0 for شنبه through 6 for جمعه
     */
    public function dayOfWeek(): int
    {
        return ((int) $this->toGregorian()->format('w') + 1) % 7;
    }

    /**
     * @return int 1 for the first day of Farvardin
     */
    public function dayOfYear(): int
    {
        $days = $this->day;

        for ($month = 1; $month < $this->month; $month++) {
            $days += self::daysInJalaliMonth($this->year, $month);
        }

        return $days;
    }

    public function daysInMonth(): int
    {
        return self::daysInJalaliMonth($this->year, $this->month);
    }

    public function isLeapYear(): bool
    {
        return self::isLeapJalaliYear($this->year);
    }

    public function monthName(): string
    {
        return self::MONTHS[$this->month - 1];
    }

    public function dayName(): string
    {
        return self::DAYS[$this->dayOfWeek()];
    }

    /* ------------------------------------------------------------------
     | Output
     | ------------------------------------------------------------------ */

    public function toGregorian(): DateTimeImmutable
    {
        [$year, $month, $day] = self::jalaliToGregorian($this->year, $this->month, $this->day);

        return new DateTimeImmutable(sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year, $month, $day, $this->hour, $this->minute, $this->second
        ));
    }

    public function toTimestamp(): int
    {
        return $this->toGregorian()->getTimestamp();
    }

    public function toGregorianDateString(): string
    {
        return $this->toGregorian()->format('Y-m-d');
    }

    public function toGregorianDateTimeString(): string
    {
        return $this->toGregorian()->format('Y-m-d H:i:s');
    }

    public function toDateString(): string
    {
        return $this->format('Y/m/d');
    }

    public function toDateTimeString(): string
    {
        return $this->format('Y/m/d H:i:s');
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function toArray(): array
    {
        return [$this->year, $this->month, $this->day];
    }

    /**
     * Tokens: Y y m n d j H G i s F M l D N w t L z a A U.
     * A backslash escapes the next character. `z` is the 1-based day of year.
     */
    public function format(string $format): string
    {
        $out = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ($char === '\\') {
                $i++;

                if ($i < $length) {
                    $out .= $format[$i];
                }

                continue;
            }

            switch ($char) {
                case 'Y': $out .= sprintf('%04d', $this->year); break;
                case 'y': $out .= sprintf('%02d', $this->year % 100); break;
                case 'm': $out .= sprintf('%02d', $this->month); break;
                case 'n': $out .= (string) $this->month; break;
                case 'd': $out .= sprintf('%02d', $this->day); break;
                case 'j': $out .= (string) $this->day; break;
                case 'H': $out .= sprintf('%02d', $this->hour); break;
                case 'G': $out .= (string) $this->hour; break;
                case 'i': $out .= sprintf('%02d', $this->minute); break;
                case 's': $out .= sprintf('%02d', $this->second); break;
                case 'F':
                case 'M': $out .= $this->monthName(); break;
                case 'l': $out .= $this->dayName(); break;
                case 'D': $out .= self::SHORT_DAYS[$this->dayOfWeek()]; break;
                case 'N': $out .= (string) ($this->dayOfWeek() + 1); break;
                case 'w': $out .= (string) $this->dayOfWeek(); break;
                case 't': $out .= (string) $this->daysInMonth(); break;
                case 'L': $out .= $this->isLeapYear() ? '1' : '0'; break;
                case 'z': $out .= (string) $this->dayOfYear(); break;
                case 'a': $out .= $this->hour < 12 ? 'ق.ظ' : 'ب.ظ'; break;
                case 'A': $out .= $this->hour < 12 ? 'قبل از ظهر' : 'بعد از ظهر'; break;
                case 'U': $out .= (string) $this->toTimestamp(); break;
                default: $out .= $char;
            }
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    /* ------------------------------------------------------------------
     | Arithmetic
     | ------------------------------------------------------------------ */

    public function addDays(int $days): self
    {
        if ($days === 0) {
            return $this;
        }

        $gregorian = $this->toGregorian()->modify(($days > 0 ? '+' : '-') . abs($days) . ' days');

        return self::fromGregorian($gregorian);
    }

    public function subDays(int $days): self
    {
        return $this->addDays(-$days);
    }

    /**
     * The day is clamped when the target month is shorter.
     */
    public function addMonths(int $months): self
    {
        $total = ($this->year * 12) + ($this->month - 1) + $months;

        $year = intdiv($total, 12);
        $month = $total % 12;

        if ($month < 0) {
            $month += 12;
            $year--;
        }

        $month++;

        return new self(
            $year,
            $month,
            min($this->day, self::daysInJalaliMonth($year, $month)),
            $this->hour,
            $this->minute,
            $this->second
        );
    }

    public function subMonths(int $months): self
    {
        return $this->addMonths(-$months);
    }

    public function addYears(int $years): self
    {
        $year = $this->year + $years;

        return new self(
            $year,
            $this->month,
            min($this->day, self::daysInJalaliMonth($year, $this->month)),
            $this->hour,
            $this->minute,
            $this->second
        );
    }

    public function subYears(int $years): self
    {
        return $this->addYears(-$years);
    }

    public function startOfDay(): self
    {
        return new self($this->year, $this->month, $this->day);
    }

    public function endOfDay(): self
    {
        return new self($this->year, $this->month, $this->day, 23, 59, 59);
    }

    /**
     * The Persian week runs from Saturday to Friday.
     */
    public function startOfWeek(): self
    {
        return $this->startOfDay()->subDays($this->dayOfWeek());
    }

    public function endOfWeek(): self
    {
        return $this->startOfWeek()->addDays(6)->endOfDay();
    }

    public function startOfMonth(): self
    {
        return new self($this->year, $this->month, 1);
    }

    public function endOfMonth(): self
    {
        return new self($this->year, $this->month, $this->daysInMonth(), 23, 59, 59);
    }

    public function startOfYear(): self
    {
        return new self($this->year, 1, 1);
    }

    public function endOfYear(): self
    {
        return new self($this->year, 12, self::daysInJalaliMonth($this->year, 12), 23, 59, 59);
    }

    public function compareTo(self $other): int
    {
        return $this->toTimestamp() <=> $other->toTimestamp();
    }

    public function equalTo(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /* ------------------------------------------------------------------
     | Calendar maths
     | ------------------------------------------------------------------ */

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $monthDays = [31, self::isLeapGregorianYear($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 1;

        foreach ($monthDays as $length) {
            if ($gd <= $length) {
                break;
            }

            $gd -= $length;
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $offsets = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $offsets[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * Esfand has 30 days in a leap year, so the check is whether 12/30 exists.
     */
    public static function isLeapJalaliYear(int $year): bool
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($year, 12, 30);

        return self::gregorianToJalali($gy, $gm, $gd) === [$year, 12, 30];
    }

    public static function daysInJalaliMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Invalid Jalali month [{$month}].");
        }

        if ($month <= 6) {
            return 31;
        }

        if ($month <= 11) {
            return 30;
        }

        return self::isLeapJalaliYear($year) ? 30 : 29;
    }

    public static function isLeapGregorianYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /* ------------------------------------------------------------------
     | Digits
     | ------------------------------------------------------------------ */

    public static function toPersianDigits(string $value): string
    {
        return str_replace(range('0', '9'), ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], $value);
    }

    public static function toEnglishDigits(string $value): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
    }

    /* ------------------------------------------------------------------
     | Column storage
     | ------------------------------------------------------------------ */

    public static function defaultFormatFor(string $mode): ?string
    {
        switch ($mode) {
            case self::GREGORIAN:
                return 'Y-m-d';
            case self::JALALI:
                return 'Y/m/d';
            case self::UNIX:
                return null;
            default:
                throw new InvalidArgumentException(
                    "Unknown date storage [{$mode}]. Use Jalali::GREGORIAN, Jalali::JALALI or Jalali::UNIX."
                );
        }
    }

    /**
     * Work out how a column stores its dates from one sample value.
     *
     * A Jalali year lands in 1000-1699 and a Gregorian one does not, which is
     * what separates '1403/05/26' from '2024-08-16'.
     *
     * @return array{0: string, 1: string|null}|null [mode, format], or null when undecidable
     */
    public static function detectStorage($sample): ?array
    {
        if ($sample === null || $sample === '' || is_bool($sample)) {
            return null;
        }

        if (is_int($sample) || is_float($sample) || (is_string($sample) && preg_match('/^\d{9,11}$/', $sample))) {
            return [self::UNIX, null];
        }

        if (! is_string($sample)) {
            return null;
        }

        $value = trim(self::toEnglishDigits($sample));

        if (! preg_match('/^(\d{4})([\/\-.])(\d{1,2})\2(\d{1,2})/', $value, $matches)) {
            return null;
        }

        $separator = $matches[2];
        $padded = strlen($matches[3]) === 2 && strlen($matches[4]) === 2;

        $format = 'Y' . $separator . ($padded ? 'm' : 'n') . $separator . ($padded ? 'd' : 'j');

        $year = (int) $matches[1];

        return [$year >= 1000 && $year <= 1699 ? self::JALALI : self::GREGORIAN, $format];
    }

    /**
     * Range comparisons rely on the stored text sorting chronologically, which
     * only holds for a zero-padded year-month-day layout.
     */
    public static function isSortableFormat(string $format): bool
    {
        preg_match_all('/[A-Za-z]/', preg_replace('/\\\\./', '', $format), $matches);

        $tokens = implode('', $matches[0]);

        return in_array($tokens, ['Ymd', 'YmdH', 'YmdHi', 'YmdHis'], true);
    }
}
