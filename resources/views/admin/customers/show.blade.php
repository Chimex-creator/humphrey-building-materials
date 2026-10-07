@extends('layouts.admin')

@section('title', 'Customer / ' . $customer->name)

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <a href="{{ route('admin.customers.index') }}" class="btn btn-clear">&larr; All Customers</a>
            <h1>{{ $customer->name }}</h1>
            <p>
                {{ $customer->email }}
                @if ($customer->phone) · {{ $customer->phone }} @endif
            </p>
        </div>

        <div style="text-align: right">
            <p style="margin-bottom: 10px">
                <span class="role-badge role-{{ $customer->role }}">{{ $customer->roleLabel() }}</span>
                @if ($customer->is_active)
                    <span class="badge badge-green">Active</span>
                @else
                    <span class="badge badge-red">Deactivated</span>
                @endif
                @if ($customer->email_verified_at)
                    <span class="badge badge-green">Email verified</span>
                @else
                    <span class="badge badge-warn">Email not verified</span>
                @endif
            </p>

            {{-- Account on/off is an admin decision - sales only get to look. --}}
            @if (auth()->user()->isAdmin())
                <form action="{{ route('admin.customers.status', $customer) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="active" value="{{ $customer->is_active ? 0 : 1 }}">
                    @if ($customer->is_active)
                        <button type="submit" class="btn btn-danger"
                                onclick="return confirm('Deactivate {{ $customer->name }}? They will not be able to log in.')">
                            Deactivate Account
                        </button>
                    @else
                        <button type="submit" class="btn btn-navy"
                                onclick="return confirm('Reactivate {{ $customer->name }}?')">
                            Reactivate Account
                        </button>
                    @endif
                </form>
            @endif
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">📋</span>
            <div>
                <strong>{{ $stats['orders'] }}</strong>
                <span>Orders</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">💰</span>
            <div>
                <strong>₦{{ number_format($stats['paid'], 0) }}</strong>
                <span>Total Paid</span>
            </div>
        </div>
        <div class="stat-card{{ $stats['unpaid_orders'] > 0 ? ' warn' : '' }}">
            <span class="stat-icon">⏳</span>
            <div>
                <strong>{{ $stats['unpaid_orders'] }}</strong>
                <span>Orders Awaiting Payment</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">↩️</span>
            <div>
                <strong>{{ $stats['returns'] }}</strong>
                <span>Returns</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🗓️</span>
            <div>
                <strong>{{ $customer->created_at->format('d M Y') }}</strong>
                <span>Joined</span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-page-head">
            <div>
                <h1>Order History</h1>
                <p>Everything this customer has checked out.</p>
            </div>
        </div>

        @if ($orders->isEmpty())
            <div class="empty-state">
                <h3>No orders yet</h3>
                <p>This customer has an account but has not ordered anything.</p>
                <a href="{{ route('products.index') }}" class="btn btn-navy">View Shop</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><strong>{{ $order->order_number }}</strong></td>
                                <td>{{ $order->created_at->format('d M Y') }}</td>
                                <td>{{ $order->items_count }}</td>
                                <td><strong>₦{{ number_format((float) $order->total, 0) }}</strong></td>
                                <td>₦{{ number_format((float) ($paidByOrder[$order->id] ?? 0), 0) }}</td>
                                <td>
                                    <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                                </td>
                                <td>
                                    <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.orders.show', $order) }}"
                                       class="btn btn-sm btn-navy">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($orders->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $orders->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
                @if ($orders->hasMorePages())
                    <a class="page-btn" href="{{ $orders->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>

    @if ($returns->isNotEmpty())
        <div class="admin-card">
            <div class="admin-page-head">
                <div>
                    <h1>Returns</h1>
                    <p>Return requests and recorded returns on this customer's orders.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($returns as $return)
                            <tr>
                                <td>{{ $return->created_at->format('d M Y') }}</td>
                                <td>{{ $return->order?->order_number ?: '—' }}</td>
                                <td>{{ $return->product?->name ?: 'Deleted product' }}</td>
                                <td>{{ $return->quantity }}</td>
                                <td>{{ $return->reason }}</td>
                                <td>
                                    <span class="badge {{ $return->statusBadgeClass() }}">{{ $return->statusLabel() }}</span>
                                </td>
                                <td class="actions-cell">
                                    @if ($return->order)
                                        <a href="{{ route('admin.orders.show', $return->order) }}"
                                           class="btn btn-sm btn-navy">Order</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
