<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PHASE 12 - customer directory (admin + sales).
 *
 * This is the read-mostly counterpart of UserController: that one manages
 * staff accounts, this one exists so the people selling can actually see who
 * buys from them. Only `role = customer` accounts are ever listed here, and
 * only an admin may change whether an account can still log in.
 *
 * Money on this page always means "landed in the till" (payments with
 * status = paid) - the same rule the Phase 11 reports use, so the two pages
 * can never disagree.
 */
class CustomerController extends Controller
{
    /** Whitelisted sort keys for the directory listing. */
    public const SORTS = [
        'recent' => 'Newest customers',
        'name' => 'Name (A - Z)',
        'spent' => 'Highest lifetime spend',
        'orders' => 'Most orders',
    ];

    /** Open order statuses - anything still moving through the shop. */
    private const OPEN_STATUSES = ['pending', 'confirmed', 'ready_for_pickup', 'out_for_delivery'];

    /** Customer directory: search, filter, sort, paginate. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'sort' => ['nullable', 'string', 'in:recent,name,spent,orders'],
        ]);

        $search = trim($data['search'] ?? '');
        $status = $data['status'] ?? '';
        $sort = $data['sort'] ?? 'recent';

        $query = User::where('role', User::ROLE_CUSTOMER);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        // Both aggregates are correlated sub-selects, so the sort runs against
        // the whole filtered set at the database - not just the 10 rows on
        // screen - and the paginator still counts real customers.
        $query->select('users.*')
            ->withCount('orders')
            ->withMax(['orders as last_order_at'], 'created_at')
            ->selectSub($this->lifetimePaidSub(), 'lifetime_paid');

        match ($sort) {
            'name' => $query->orderBy('name'),
            'spent' => $query->orderByDesc('lifetime_paid')->orderBy('name'),
            'orders' => $query->orderByDesc('orders_count')->orderBy('name'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $customers = $query->paginate(10)->withQueryString();

        // Headline numbers ignore the filters on purpose: they are the state
        // of the whole customer base, not of the current search.
        $total = User::where('role', User::ROLE_CUSTOMER)->count();
        $active = User::where('role', User::ROLE_CUSTOMER)->where('is_active', true)->count();
        $newThisMonth = User::where('role', User::ROLE_CUSTOMER)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $stats = [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'new_month' => $newThisMonth,
        ];

        return view('admin.customers.index', [
            'customers' => $customers,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'sorts' => self::SORTS,
        ]);
    }

    /** A single customer's profile: money, orders, returns, account status. */
    public function show(User $user)
    {
        abort_unless($user->isCustomer(), 404);

        $orders = $user->orders()
            ->withCount('items')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $returns = ProductReturn::query()
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->where('orders.user_id', $user->id)
            ->select('returns.*')
            ->with(['product:id,name', 'order:id,order_number'])
            ->latest('returns.created_at')
            ->get();

        // One grouped query instead of calling paidAmount() per order.
        $paidByOrder = Payment::query()
            ->selectRaw('order_id, SUM(amount) AS paid')
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('status', 'paid')
            ->groupBy('order_id')
            ->pluck('paid', 'order_id');

        $stats = [
            'orders' => $user->orders()->count(),
            'paid' => $this->lifetimePaid($user->id),
            'unpaid_orders' => $this->unpaidOrdersFor($user->id),
            'returns' => $returns->count(),
        ];

        return view('admin.customers.show', [
            'customer' => $user,
            'orders' => $orders,
            'paidByOrder' => $paidByOrder,
            'returns' => $returns,
            'stats' => $stats,
        ]);
    }

    /** Activate or deactivate a customer account (admins only). */
    public function updateStatus(Request $request, User $user)
    {
        // Customers only. An admin is never a customer, so locking your own
        // account out is impossible from here - that rule lives in
        // UserController (staff accounts), not in this directory.
        abort_unless($user->isCustomer(), 404);

        $active = $request->boolean('active');

        if ($user->is_active === $active) {
            return back()->with('status', $user->name.' is already '.($active ? 'active' : 'deactivated').'.');
        }

        $user->is_active = $active;
        $user->save();

        return back()->with('status', $active
            ? $user->name.' can log in again.'
            : $user->name.' can no longer log in.');
    }

    /**
     * Final Spec §39 — bulk activate / deactivate customer accounts (admins only).
     *
     * Validation + authorization + confirmation (client-side) + an audit entry
     * per run. Only rows that actually change are counted, so the message is
     * always honest.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $active = $data['action'] === 'activate';

        $ids = User::where('role', User::ROLE_CUSTOMER)
            ->whereIn('id', $data['customer_ids'])
            ->pluck('id');

        $changed = 0;
        foreach ($ids as $id) {
            $user = User::whereKey($id)->first();
            if ($user && (bool) $user->is_active !== $active) {
                $user->is_active = $active;
                $user->save();
                $changed++;
            }
        }

        if ($changed > 0) {
            $logger->log('customers.bulk_'.$data['action'], null, sprintf(
                '%d customer account(s) %s in one bulk action.',
                $changed,
                $active ? 'activated' : 'deactivated'
            ), [
                'count' => $changed,
                'ids' => $ids->all(),
            ]);
        }

        return back()->with('status', $changed > 0
            ? $changed.' customer account(s) '.($active ? 'activated' : 'deactivated').'.'
            : 'Nothing to change — the selected accounts were already in that state.');
    }

    /**
     * Correlated sub-select: money this customer has actually paid.
     *
     * Written once and reused by the listing (as `lifetime_paid`) and the
     * profile, so a customer's spend is always one definition.
     */
    private function lifetimePaidSub()
    {
        return Payment::query()
            ->selectRaw('COALESCE(SUM(payments.amount), 0)')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', 'paid')
            ->whereColumn('orders.user_id', 'users.id');
    }

    private function lifetimePaid(int $userId): float
    {
        return (float) Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', 'paid')
            ->where('orders.user_id', $userId)
            ->sum('payments.amount');
    }

    /**
     * How many of this customer's open orders are still waiting for the
     * money to arrive. A COUNT, never an "amount still owed" balance —
     * Master Scope §5 removed outstanding balances and customer credit.
     */
    private function unpaidOrdersFor(int $userId): int
    {
        return (int) Order::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
            ->count();
    }
}
