<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 11 — sales reports and charts.
 *
 * Everything here is read-only: no money is moved and no state changes.
 * The report period is resolved once (preset buttons or explicit from/to)
 * and every figure on the page is computed against the same window, so the
 * chart, the KPI cards and the CSV export can never disagree.
 */
class ReportController extends Controller
{
    /** One-click ranges offered on the report page. */
    public const PRESETS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        'all' => 'All time',
    ];

    /** Ranges up to this many days are bucketed per day; longer ones per month. */
    private const DAILY_LIMIT_DAYS = 31;

    /** Safety net so a silly "all time" window can never spin forever. */
    private const MAX_BUCKETS = 600;

    public function index(Request $request): View
    {
        [$from, $to, $preset] = $this->resolveRange($request);

        return view('admin.reports.index', [
            'presets' => self::PRESETS,
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'kpis' => $this->kpis($from, $to),
            'series' => $this->salesSeries($from, $to),
            'statusBreakdown' => $this->statusBreakdown($from, $to),
            'methodMix' => $this->methodMix($from, $to),
            'topProducts' => $this->topProducts($from, $to),
        ]);
    }

    /** One row per order in the selected window, as a CSV download. */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request);

        $orders = Order::with('items')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $filename = 'sales-report_'.$from->format('Y-m-d').'_to_'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Order Number',
                'Placed At',
                'Customer',
                'Email',
                'Phone',
                'Delivery',
                'Status',
                'Payment Option',
                'Payment Status',
                'Subtotal',
                'Delivery Fee',
                'Total',
                'Paid',
                'Units',
            ]);

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->order_number,
                    $order->created_at->format('Y-m-d H:i'),
                    $order->customer_name,
                    $order->customer_email,
                    $order->customer_phone,
                    $order->deliveryOptionLabel(),
                    $order->statusLabel(),
                    $order->paymentOptionLabel(),
                    $order->paymentStatusLabel(),
                    number_format((float) $order->subtotal, 2, '.', ''),
                    number_format((float) $order->delivery_fee, 2, '.', ''),
                    number_format((float) $order->total, 2, '.', ''),
                    number_format($order->paidAmount(), 2, '.', ''),
                    (int) $order->items->sum('quantity'),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------
     | Period resolution
     * ------------------------------------------------------------ */

    /**
     * Turn the query string into one [from, to, preset] window.
     * Explicit from/to wins over the preset buttons; a preset alone
     * produces a named range; nothing at all defaults to 30 days.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string|null}
     */
    private function resolveRange(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::PRESETS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $hasFrom = ! empty($data['from']);
        $hasTo = ! empty($data['to']);

        if ($hasFrom || $hasTo) {
            $to = $hasTo
                ? Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()
                : Carbon::now()->endOfDay();
            $from = $hasFrom
                ? Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                : $to->copy()->subDays(29)->startOfDay();

            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages([
                    'from' => 'The start date must be on or before the end date.',
                ]);
            }

            return [$from, $to, null];
        }

        $preset = $data['preset'] ?? '30d';

        [$from, $to] = match ($preset) {
            'today' => [Carbon::today()->startOfDay(), Carbon::now()->endOfDay()],
            '7d' => [Carbon::today()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay()],
            'last_month' => [
                Carbon::now()->subMonthNoOverflow()->startOfMonth(),
                Carbon::now()->subMonthNoOverflow()->endOfMonth(),
            ],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfDay()],
            'all' => $this->allTimeRange(),
            default => [Carbon::today()->subDays(29)->startOfDay(), Carbon::now()->endOfDay()],
        };

        return [$from, $to, $preset];
    }

    /** "All time" starts at the very first order ever placed (or 1 year back). */
    private function allTimeRange(): array
    {
        $first = Order::query()->orderBy('created_at')->value('created_at');

        $from = $first ? Carbon::parse($first)->startOfDay() : Carbon::today()->subYear();

        return [$from, Carbon::now()->endOfDay()];
    }

    /* ------------------------------------------------------------
     | Figures
     * ------------------------------------------------------------ */

    private function kpis(Carbon $from, Carbon $to): array
    {
        $orderQuery = Order::whereBetween('created_at', [$from, $to]);
        $ordersCount = (int) (clone $orderQuery)->count();
        $ordersGross = (float) (clone $orderQuery)->sum('total');

        return [
            'revenue' => $this->revenue($from, $to),
            'orders' => $ordersCount,
            'avg_order' => $ordersCount > 0 ? $ordersGross / $ordersCount : 0.0,
            'units' => $this->unitsSold($from, $to),
            'returns_completed' => ProductReturn::whereBetween('created_at', [$from, $to])
                ->where('status', 'completed')
                ->count(),
            'new_customers' => User::where('role', 'customer')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            // Count only — Master Scope §5 removed outstanding balances.
            'unpaid_orders' => Order::whereBetween('created_at', [$from, $to])
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
                ->count(),
        ];
    }

    /** Money actually received in the window (not "orders that exist"). */
    private function revenue(Carbon $from, Carbon $to): float
    {
        return (float) (clone $this->paidPayments($from, $to))->sum('amount');
    }

    /**
     * Paid payments whose receipt falls inside the window.
     * paid_at is the honest timestamp; if a row somehow missed it we fall
     * back to created_at rather than silently dropping the money.
     *
     * @return Builder
     */
    private function paidPayments(Carbon $from, Carbon $to)
    {
        return Payment::query()
            ->where('status', 'paid')
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($sub) use ($from, $to) {
                        $sub->whereNull('paid_at')->whereBetween('created_at', [$from, $to]);
                    });
            });
    }

    private function unitsSold(Carbon $from, Carbon $to): int
    {
        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->sum('order_items.quantity');
    }

    /**
     * Revenue per day (short ranges) or per month (long ones),
     * keyed by the period so gaps come back as genuine zeros.
     *
     * @return array<int, array{label: string, value: float}>
     */
    private function salesSeries(Carbon $from, Carbon $to): array
    {
        $daily = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) < self::DAILY_LIMIT_DAYS;

        $series = [];
        $cursor = $daily ? $from->copy()->startOfDay() : $from->copy()->startOfMonth();
        $end = $daily ? $to->copy()->startOfDay() : $to->copy()->startOfMonth();
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($end) && $guard++ < self::MAX_BUCKETS) {
            $key = $daily ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
            $series[$key] = [
                'label' => $daily ? $cursor->format('d M') : $cursor->format('M Y'),
                'value' => 0.0,
            ];
            if ($daily) {
                $cursor->addDay();
            } else {
                $cursor->addMonthNoOverflow();
            }
        }

        foreach ((clone $this->paidPayments($from, $to))->get(['paid_at', 'created_at', 'amount']) as $payment) {
            $at = $payment->paid_at ?? $payment->created_at;
            $key = $daily ? $at->format('Y-m-d') : $at->format('Y-m');
            if (isset($series[$key])) {
                $series[$key]['value'] += (float) $payment->amount;
            }
        }

        return array_values($series);
    }

    /** How many orders sit in each fulfilment state (only non-empty ones). */
    private function statusBreakdown(Carbon $from, Carbon $to): array
    {
        $counts = [];
        $rows = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }

        $out = [];
        foreach (Order::STATUSES as $status => $label) {
            $value = (int) ($counts[$status] ?? 0);
            if ($value > 0) {
                $out[] = ['key' => $status, 'label' => $label, 'value' => $value];
            }
        }

        return $out;
    }

    /** Where the money in this window actually came from. */
    private function methodMix(Carbon $from, Carbon $to): array
    {
        $rows = (clone $this->paidPayments($from, $to))
            ->selectRaw('method, sum(amount) as aggregate')
            ->groupBy('method')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label' => Payment::METHODS[$row->method] ?? ucfirst(str_replace('_', ' ', $row->method)),
                'value' => (float) $row->aggregate,
            ];
        }

        usort($out, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return $out;
    }

    /** Best sellers in the window, ranked by revenue, top 10. */
    private function topProducts(Carbon $from, Carbon $to): array
    {
        $items = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->select('order_items.product_id', 'order_items.product_name', 'order_items.quantity', 'order_items.line_total')
            ->get();

        $grouped = [];
        foreach ($items as $item) {
            $key = $item->product_id ?? 'name:'.$item->product_name;
            $grouped[$key] ??= [
                'name' => $item->product_name,
                'qty' => 0,
                'revenue' => 0.0,
            ];
            $grouped[$key]['qty'] += (int) $item->quantity;
            $grouped[$key]['revenue'] += (float) $item->line_total;
        }

        usort($grouped, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);
        $grouped = array_slice($grouped, 0, 10);

        $total = array_sum(array_column($grouped, 'revenue'));
        foreach ($grouped as &$row) {
            $row['share'] = $total > 0 ? ($row['revenue'] / $total) * 100 : 0.0;
        }
        unset($row);

        return $grouped;
    }
}
