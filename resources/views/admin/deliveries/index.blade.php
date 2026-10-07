@extends('layouts.admin')

@section('title', 'Deliveries')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Deliveries</h1>
            <p>Who is taking what, on which day. Pickup orders never appear here.</p>
        </div>
        <a href="{{ route('admin.orders.index', ['status' => 'confirmed']) }}" class="btn btn-navy">View Orders</a>
    </div>

    <div class="stats-grid">
        @foreach (\App\Models\Delivery::STATUSES as $key => $label)
            <div class="stat-card{{ $key === 'failed' ? ' warn' : '' }}">
                <span class="stat-icon">🚚</span>
                <div>
                    <strong>{{ $statusCounts[$key] ?? 0 }}</strong>
                    <span>{{ $label }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.deliveries.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Search order #, name or phone..."
                   class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                @foreach (\App\Models\Delivery::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>
                        {{ $label }} ({{ $statusCounts[$key] ?? 0 }})
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $status)
                <a href="{{ route('admin.deliveries.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($errors->has('search') || $errors->has('status'))
            <p class="field-error">
                {{ $errors->first('search') ?: $errors->first('status') }}
            </p>
        @endif

        @if ($deliveries->isEmpty())
            <div class="empty-state">
                <h3>No deliveries found</h3>
                @if ($search || $status)
                    <p>Try a different search or filter.</p>
                    <a href="{{ route('admin.deliveries.index') }}" class="btn btn-navy">Clear filters</a>
                @else
                    <p>Deliveries appear here as soon as a customer chooses delivery at checkout.</p>
                    <a href="{{ route('admin.orders.index') }}" class="btn btn-navy">View Orders</a>
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Delivery Address</th>
                            <th>Requested</th>
                            <th>Confirmed</th>
                            <th>Taking It</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            @php($order = $delivery->order)
                            <tr>
                                <td><strong>{{ $order?->order_number ?? '—' }}</strong></td>
                                <td>
                                    {{ $order?->customer_name }}<br>
                                    <small class="empty-inline">{{ $order?->customer_phone }}</small>
                                </td>
                                <td>{{ $order?->delivery_address }}</td>
                                <td>{{ $order?->preferred_delivery_date?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $delivery->confirmed_date?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $delivery->assignee?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $delivery->statusBadgeClass() }}">{{ $delivery->statusLabel() }}</span>
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.deliveries.show', $delivery) }}"
                                       class="btn btn-sm btn-navy">Manage</a>
                                    <a href="{{ route('admin.orders.show', $order) }}"
                                       class="btn btn-sm btn-outline">Order</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($deliveries->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $deliveries->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $deliveries->currentPage() }} of {{ $deliveries->lastPage() }}</span>
                @if ($deliveries->hasMorePages())
                    <a class="page-btn" href="{{ $deliveries->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
