<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

class DashboardController extends Controller
{
    /**
     * Admin/staff dashboard — counts and recent activity.
     * The full numbers live on /admin/reports (Phase 11).
     */
    public function index()
    {
        // Configurable low-stock threshold (§35) — same value the
        // inventory screen uses, so the two screens can never disagree.
        $lowStockThreshold = Setting::int('low_stock_threshold', 10);

        $stats = [
            'products' => Product::count(),
            'categories' => Category::count(),
            'customers' => User::where('role', 'customer')->count(),
            'staff' => User::where('role', '!=', 'customer')->count(),
            'low_stock' => Product::where('stock_quantity', '<=', $lowStockThreshold)->count(),
            'inactive_users' => User::where('is_active', false)->count(),

            // Orders / money — the numbers staff actually need day to day.
            'orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'revenue' => (float) Payment::where('status', 'paid')->sum('amount'),
            // Count only: Master Scope §5 removed outstanding balances.
            'unpaid_orders' => Order::query()
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
                ->count(),
        ];

        $recentUsers = User::latest()->take(5)->get();
        $recentOrders = Order::with('items')->latest()->take(6)->get();

        // Phase 11 — the sales chart only makes sense for the two roles that
        // can also open /admin/reports. Inventory staff never see the link,
        // and they must not see revenue either (Master Scope §30).
        $canSeeSales = auth()->user()->isAdmin() || auth()->user()->isSales();
        $sales = $canSeeSales ? $this->salesSnapshot() : null;

        return view('admin.dashboard', compact(
            'stats',
            'recentUsers',
            'recentOrders',
            'lowStockThreshold',
            'sales',
            'canSeeSales'
        ));
    }

    /** Phase 11 — last 30 days of revenue plus the best sellers for the dashboard. */
    private function salesSnapshot(): array
    {
        $from = now()->subDays(29)->startOfDay();
        $to = now()->endOfDay();

        $daily = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $daily[$cursor->format('Y-m-d')] = ['label' => $cursor->format('d M'), 'value' => 0.0];
            $cursor->addDay();
        }

        Payment::query()
            ->where('status', 'paid')
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($sub) use ($from, $to) {
                        $sub->whereNull('paid_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->get(['paid_at', 'created_at', 'amount'])
            ->each(function (Payment $payment) use (&$daily) {
                $at = $payment->paid_at ?? $payment->created_at;
                $key = $at->format('Y-m-d');
                if (isset($daily[$key])) {
                    $daily[$key]['value'] += (float) $payment->amount;
                }
            });

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
        $topProducts = array_slice($grouped, 0, 5);

        return [
            'from' => $from,
            'to' => $to,
            'series' => array_values($daily),
            'total' => array_sum(array_column($daily, 'value')),
            'top_products' => $topProducts,
        ];
    }
}
