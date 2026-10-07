@extends('layouts.admin')

@section('title', 'Orders')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Orders</h1>
            <p>Track customer orders from pending through delivery.</p>
        </div>
    </div>

    <div class="admin-card">
        {{-- Filters --}}
        <form action="{{ route('admin.orders.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Search order #, name, email or phone..."
                   class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                @foreach (\App\Models\Order::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>
                        {{ $label }} ({{ $statusCounts[$key] ?? 0 }})
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $statusFilter)
                <a href="{{ route('admin.orders.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($orders->isEmpty())
            <div class="empty-state">
                <h3>No orders found</h3>
                <p>Orders appear here after customers check out.</p>
                <a href="{{ route('products.index') }}" class="btn btn-navy">View Shop</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><strong>{{ $order->order_number }}</strong></td>
                                <td>
                                    {{ $order->customer_name }}<br>
                                    <small class="empty-inline">{{ $order->customer_phone }}</small>
                                </td>
                                <td>{{ $order->created_at->format('d M Y') }}<br>
                                    <small class="empty-inline">{{ $order->created_at->format('h:i A') }}</small>
                                </td>
                                <td>{{ $order->items_count ?? $order->items()->count() }}</td>
                                <td><strong>₦{{ number_format((float) $order->total, 0) }}</strong></td>
                                <td>
                                    <span class="badge {{ $order->statusBadgeClass() }}">
                                        {{ $order->statusLabel() }}
                                    </span>
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
@endsection
