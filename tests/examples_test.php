<?php

require __DIR__ . '/bootstrap.php';

use Or81\Eloquent\Builder;
use Or81\Eloquent\Jalali;
use Or81\Eloquent\JoinClause;
use Or81\Eloquent\NDB;
NDB::configure(['driver' => 'sqlite', 'database' => ':memory:']);

NDB::unprepared('create table customers (
    id integer primary key autoincrement,
    name text, phone text, city text, is_active integer default 1,
    created_at text
)');
NDB::unprepared('create table orders (
    id integer primary key autoincrement,
    customer_id integer, code text, status text, total integer,
    created_at text, archived_at text
)');
NDB::unprepared('create table order_items (
    id integer primary key autoincrement,
    order_id integer, product text, quantity integer, price integer
)');
NDB::unprepared('create table legacy_invoices (
    id integer primary key autoincrement,
    number text, amount integer, issue_date text
)');

$customers = [
    ['name' => 'علی رضایی', 'phone' => '09121110000', 'city' => 'تهران', 'is_active' => 1],
    ['name' => 'مریم احمدی', 'phone' => '09122220000', 'city' => 'اصفهان', 'is_active' => 1],
    ['name' => 'حسن کریمی', 'phone' => '09123330000', 'city' => 'تهران', 'is_active' => 0],
    ['name' => 'زهرا موسوی', 'phone' => '09124440000', 'city' => 'شیراز', 'is_active' => 1],
];

foreach ($customers as $i => $customer) {
    $customer['created_at'] = Jalali::create(1403, 1, $i + 1)->toGregorianDateTimeString();
    NDB::table('customers')->insert($customer);
}

$orderSpecs = [
    [1, 'ORD-1001', 'paid', 250000, [1403, 5, 26]],
    [1, 'ORD-1002', 'paid', 120000, [1403, 5, 28]],
    [1, 'ORD-1003', 'cancelled', 80000, [1403, 6, 2]],
    [2, 'ORD-1004', 'paid', 640000, [1403, 5, 26]],
    [2, 'ORD-1005', 'pending', 95000, [1403, 6, 15]],
    [3, 'ORD-1006', 'paid', 310000, [1402, 11, 3]],
    [4, 'ORD-1007', 'paid', 45000, [1403, 6, 15]],
    [4, 'ORD-1008', 'paid', 780000, [1403, 7, 1]],
];

foreach ($orderSpecs as [$customerId, $code, $status, $total, $date]) {
    NDB::table('orders')->insert([
        'customer_id' => $customerId,
        'code' => $code,
        'status' => $status,
        'total' => $total,
        'created_at' => Jalali::create($date[0], $date[1], $date[2], 12, 0, 0)->toGregorianDateTimeString(),
    ]);
}

NDB::table('order_items')->insert([
    ['order_id' => 1, 'product' => 'کیبورد', 'quantity' => 1, 'price' => 250000],
    ['order_id' => 2, 'product' => 'ماوس', 'quantity' => 2, 'price' => 60000],
    ['order_id' => 4, 'product' => 'مانیتور', 'quantity' => 1, 'price' => 640000],
    ['order_id' => 8, 'product' => 'لپ‌تاپ', 'quantity' => 1, 'price' => 780000],
]);

NDB::table('legacy_invoices')->insert([
    ['number' => 'F-901', 'amount' => 500000, 'issue_date' => '1403/05/26'],
    ['number' => 'F-902', 'amount' => 320000, 'issue_date' => '1403/06/02'],
    ['number' => 'F-903', 'amount' => 145000, 'issue_date' => '1403/06/20'],
]);

/* ==================================================================== */

section('1. a filtered, paginated listing');

function searchOrders(array $filters, int $page = 1): array
{
    return NDB::table('orders')
        ->join('customers', 'orders.customer_id', '=', 'customers.id')
        ->select('orders.code', 'orders.total', 'orders.status', 'customers.name')
        ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where(function (Builder $inner) use ($term) {
            $inner->whereLike('customers.name', "%{$term}%")
                  ->orWhereLike('orders.code', "%{$term}%");
        }))
        ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->whereIn('orders.status', (array) $status))
        ->when($filters['city'] ?? null, fn (Builder $q, $city) => $q->where('customers.city', $city))
        ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereJalali('orders.created_at', '>=', $from))
        ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereJalali('orders.created_at', '<=', $to))
        ->orderByDesc('orders.created_at')
        ->paginate(3, $page);
}

$result = searchOrders([]);
check('no filters returns everything, paged', [$result['total'], count($result['data']), $result['last_page']], [8, 3, 3]);

$result = searchOrders(['status' => 'paid', 'city' => 'تهران']);
check('status + city', array_column($result['data'], 'code'), ['ORD-1002', 'ORD-1001', 'ORD-1006']);

$result = searchOrders(['q' => 'مریم']);
check('free-text search on the joined table', array_column($result['data'], 'code'), ['ORD-1005', 'ORD-1004']);

$result = searchOrders(['from' => '1403/05/26', 'to' => '1403/06/02', 'status' => ['paid', 'cancelled']]);
check('a jalali range combined with a status filter',
    array_column($result['data'], 'code'), ['ORD-1003', 'ORD-1002', 'ORD-1001']);

$result = searchOrders([], 3);
check('the last page', [count($result['data']), $result['from'], $result['to']], [2, 7, 8]);

section('2. dashboard counters');

$today = Jalali::today();
NDB::table('orders')->insert([
    'customer_id' => 1, 'code' => 'ORD-TODAY', 'status' => 'paid', 'total' => 99000,
    'created_at' => $today->toGregorianDateString() . ' 08:00:00',
]);

$stats = [
    'today' => NDB::table('orders')->whereJalaliToday('created_at')->count(),
    'this_week' => NDB::table('orders')->whereJalaliThisWeek('created_at')->count(),
    'this_month_revenue' => (int) NDB::table('orders')
        ->where('status', 'paid')->whereJalaliThisMonth('created_at')->sum('total'),
    'has_pending' => NDB::table('orders')->where('status', 'pending')->exists(),
];

check('dashboard counters', $stats, [
    'today' => 1,
    'this_week' => 1,
    'this_month_revenue' => 99000,
    'has_pending' => true,
]);

section('3. a sales report grouped into jalali months');

$daily = NDB::table('orders')
    ->selectRaw('date(created_at) as day, sum(total) as total, count(*) as orders')
    ->where('status', 'paid')
    ->whereJalaliYear('created_at', 1403)
    ->groupByRaw('date(created_at)')
    ->get();

$byMonth = [];
foreach ($daily as $row) {
    $month = Jalali::fromGregorian($row['day'])->format('Y/m');

    $byMonth[$month]['total'] = ($byMonth[$month]['total'] ?? 0) + $row['total'];
    $byMonth[$month]['orders'] = ($byMonth[$month]['orders'] ?? 0) + $row['orders'];
}
ksort($byMonth);

check('revenue per jalali month', array_map(fn ($m) => $m['total'], $byMonth), [
    '1403/05' => 1010000,
    '1403/06' => 45000,
    '1403/07' => 780000,
]);

section('4. top customers with a correlated sub-select');

$top = NDB::table('customers', 'c')
    ->select('c.name')
    ->selectSub(
        NDB::table('orders')->selectRaw('coalesce(sum(total), 0)')
            ->whereColumn('orders.customer_id', 'c.id')->where('status', 'paid'),
        'revenue'
    )
    ->where('c.is_active', 1)
    ->orderByDesc('revenue')
    ->limit(2)
    ->get();

check('top customers by revenue', $top, [
    ['name' => 'زهرا موسوی', 'revenue' => 825000],
    ['name' => 'مریم احمدی', 'revenue' => 640000],
]);

section('5. only customers who ordered this jalali year');

$active = NDB::table('customers')
    ->whereExists(function (Builder $query) {
        $query->from('orders')
              ->whereColumn('orders.customer_id', 'customers.id')
              ->where('status', 'paid')
              ->whereJalaliYear('created_at', 1403);
    })
    ->orderBy('id')
    ->pluck('name');

check('customers with a paid 1403 order', $active, ['علی رضایی', 'مریم احمدی', 'زهرا موسوی']);

section('6. a write batch inside a transaction');

function placeOrder(int $customerId, array $items): int
{
    return NDB::transaction(function () use ($customerId, $items) {
        $total = array_sum(array_map(fn ($item) => $item['quantity'] * $item['price'], $items));

        $orderId = NDB::table('orders')->insertGetId([
            'customer_id' => $customerId,
            'code' => 'ORD-' . str_pad((string) (NDB::table('orders')->max('id') + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'pending',
            'total' => $total,
            'created_at' => Jalali::now()->toGregorianDateTimeString(),
        ]);

        NDB::table('order_items')->insert(array_map(
            fn ($item) => $item + ['order_id' => $orderId],
            $items
        ));

        return $orderId;
    });
}

$orderId = placeOrder(2, [
    ['product' => 'هدفون', 'quantity' => 2, 'price' => 150000],
    ['product' => 'کابل', 'quantity' => 3, 'price' => 20000],
]);

check('the order was written', (int) NDB::table('orders')->where('id', $orderId)->value('total'), 360000);
check('the items were written', NDB::table('order_items')->where('order_id', $orderId)->count(), 2);

$before = NDB::table('orders')->count();
try {
    NDB::transaction(function () {
        NDB::table('orders')->insert(['code' => 'ORD-BAD', 'total' => 1]);
        throw new RuntimeException('payment gateway refused');
    });
} catch (RuntimeException $e) {
    // handled
}
check('a failed transaction leaves nothing behind', NDB::table('orders')->count(), $before);

section('7. a legacy table that stores jalali text');

$invoices = NDB::table('legacy_invoices')
    ->detectDateStorage('issue_date')
    ->whereJalaliBetween('issue_date', ['1403/05/26', '1403/06/02'])
    ->orderBy('issue_date')
    ->pluck('amount', 'number');

check('a jalali range over a varchar column', $invoices, ['F-901' => 500000, 'F-902' => 320000]);

check('and a whole jalali month over the same column',
    NDB::table('legacy_invoices')
        ->dateStorage('issue_date', Jalali::JALALI)
        ->whereJalaliMonth('issue_date', 1403, 6)
        ->pluck('number'),
    ['F-902', 'F-903']);

section('8. streaming an export');

$lines = [];
$query = NDB::table('orders')
    ->join('customers', 'orders.customer_id', '=', 'customers.id')
    ->select('orders.code', 'orders.total', 'orders.created_at', 'customers.name')
    ->castJalali('created_at', 'Y/m/d')
    ->where('orders.status', 'paid')
    ->orderBy('orders.id');

foreach ($query->cursor() as $row) {
    $lines[] = "{$row['code']},{$row['name']},{$row['created_at']},{$row['total']}";
}

check('the first exported line', $lines[0], 'ORD-1001,علی رضایی,1403/05/26,250000');
check('every paid order was exported', count($lines), 7);

section('9. bulk updates');

check('cancel every pending order older than the current jalali year',
    NDB::table('orders')->where('status', 'pending')->whereJalali('created_at', '<', '1403/07/01')
        ->update(['status' => 'cancelled', 'archived_at' => Jalali::now()->toGregorianDateTimeString()]),
    1);

check('bump a counter with an expression',
    (function () {
        NDB::table('orders')->where('code', 'ORD-1001')->increment('total', 5000);
        return (int) NDB::table('orders')->where('code', 'ORD-1001')->value('total');
    })(),
    255000);

check('upsert a daily rollup row',
    (function () {
        NDB::unprepared('create table daily_totals (day text primary key, total integer)');

        foreach ([['1403/05/26', 100], ['1403/05/26', 250], ['1403/05/27', 90]] as [$day, $total]) {
            NDB::table('daily_totals')->upsert([['day' => $day, 'total' => $total]], ['day'], ['total']);
        }

        return NDB::table('daily_totals')->orderBy('day')->pluck('total', 'day');
    })(),
    ['1403/05/26' => 250, '1403/05/27' => 90]);

section('10. chunking through a large table');

$seen = 0;
NDB::table('orders')->orderBy('id')->chunk(3, function (array $rows) use (&$seen) {
    $seen += count($rows);
});
check('chunk walked every row', $seen, NDB::table('orders')->count());

summary();
