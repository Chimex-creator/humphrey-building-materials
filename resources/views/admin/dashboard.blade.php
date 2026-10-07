@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="admin-page-head">
        <h1>Dashboard</h1>
        <p>Welcome back, {{ auth()->user()->name }}. Here is a quick overview of the business.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">📦</span>
            <div>
                <strong>{{ $stats['products'] }}</strong>
                <span>Products</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🗂️</span>
            <div>
                <strong>{{ $stats['categories'] }}</strong>
                <span>Categories</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🛒</span>
            <div>
                <strong>{{ $stats['customers'] }}</strong>
                <span>Customers</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">👥</span>
            <div>
                <strong>{{ $stats['staff'] }}</strong>
                <span>Staff Accounts</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">⚠️</span>
            <div>
                <strong>{{ $stats['low_stock'] }}</strong>
                <span>Low Stock Items (≤ {{ $lowStockThreshold }})</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">💤</span>
            <div>
                <strong>{{ $stats['inactive_users'] }}</strong>
                <span>Inactive Users</span>
            </div>
        </div>
    </div>

    {{-- Orders + money — daily numbers (admin + sales; Master Scope §30) --}}
    @if ($canSeeSales)
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon">🧾</span>
                <div>
                    <strong>{{ $stats['orders'] }}</strong>
                    <span>Total Orders</span>
                </div>
            </div>
            <div class="stat-card warn">
                <span class="stat-icon">⏳</span>
                <div>
                    <strong>{{ $stats['pending_orders'] }}</strong>
                    <span>Pending Orders</span>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">💰</span>
                <div>
                    <strong>₦{{ number_format($stats['revenue'], 0) }}</strong>
                    <span>Payments Received</span>
                </div>
            </div>
            <div class="stat-card warn">
                <span class="stat-icon">🧮</span>
                <div>
                    <strong>{{ $stats['unpaid_orders'] }}</strong>
                    <span>Orders Awaiting Payment</span>
                </div>
            </div>
        </div>

        {{-- Recent orders --}}
        <div class="admin-card" style="margin-bottom: 20px;">
            <div class="admin-card-head" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <h2 style="margin:0;">Recent Orders</h2>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-dark btn-sm">View all orders →</a>
            </div>

        @if (($recentOrders ?? collect())->isEmpty())
            <p class="empty-inline">No orders yet.</p>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentOrders as $order)
                            <tr>
                                <td><a href="{{ route('admin.orders.show', $order) }}"><strong>{{ $order->order_number }}</strong></a></td>
                                <td>{{ $order->customer_name }}</td>
                                <td>{{ $order->deliveryOptionLabel() }}</td>
                                <td>₦{{ number_format((float) $order->total, 0) }}</td>
                                <td><span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span></td>
                                <td><span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span></td>
                                <td><small>{{ $order->created_at->format('d M Y') }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </div>
    @endif

    {{-- Phase 11 — sales charts (admin + sales only; inventory never links here) --}}
    @if ($canSeeSales && $sales)
        <div class="admin-grid-2" style="margin-bottom: 20px;">
            <div class="admin-card">
                <div class="admin-card-head" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
                    <h2 style="margin:0;">Sales — Last 30 Days</h2>
                    <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-dark btn-sm">Open reports →</a>
                </div>
                @include('partials.charts.line', [
                    'series' => $sales['series'],
                    'chartTitle' => 'Revenue received, last 30 days',
                    'emptyMessage' => 'No money has come in during the last 30 days.',
                ])
            </div>

            <div class="admin-card">
                <h2>Best Sellers — 30 Days</h2>
                @include('partials.charts.bars', [
                    'rows' => collect($sales['top_products'])->map(fn (array $product) => [
                        'label' => $product['name'],
                        'value' => $product['revenue'],
                        'display' => '₦' . number_format($product['revenue'], 0) . ' · ' . $product['qty'] . 'u',
                    ])->values()->all(),
                    'emptyMessage' => 'Nothing has sold in the last 30 days yet.',
                ])
            </div>
        </div>
    @endif

    <div class="admin-grid-2">
        @if ($canSeeSales)
        <div class="admin-card">
            <h2>Recent Users</h2>
            @if ($recentUsers->isEmpty())
                <p class="empty-inline">No users yet.</p>
            @else
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentUsers as $user)
                            <tr>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td><span class="role-badge role-{{ $user->role }}">{{ $user->roleLabel() }}</span></td>
                                <td>
                                    @if ($user->is_active)
                                        <span class="badge badge-green">Active</span>
                                    @else
                                        <span class="badge badge-red">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @endif

        <div class="admin-card">
            <h2>Quick Actions</h2>
            <div class="quick-actions">
                @if (auth()->user()->isAdmin() || auth()->user()->isInventory())
                    <a href="{{ route('admin.products.create') }}" class="btn btn-navy">+ Add Product</a>
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-dark">🗂️ Manage Categories</a>
                @endif
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.users.create') }}" class="btn btn-navy">+ Add Staff Account</a>
                    <a href="{{ route('admin.settings') }}" class="btn btn-primary">⚙️ Business Settings</a>
                @endif
                @if (auth()->user()->isAdmin() || auth()->user()->isSales())
                    <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-dark">📈 Sales Reports</a>
                @endif
                <a href="{{ route('products.index') }}" class="btn btn-outline-dark">🛍️ View Shop</a>
                <a href="{{ route('home') }}" class="btn btn-outline-dark">🏠 Homepage</a>
            </div>

            <h2 style="margin-top: 24px;">System Info</h2>
            <ul class="system-info">
                <li><strong>Business:</strong> {{ \App\Models\Setting::get('business_name', config('app.name')) }}</li>
                <li><strong>Phone:</strong> {{ \App\Models\Setting::get('business_phone', '—') }}</li>
                <li><strong>Email:</strong> {{ \App\Models\Setting::get('business_email', '—') }}</li>
                <li><strong>Laravel:</strong> {{ app()->version() }}</li>
            </ul>
        </div>
    </div>
@endsection
