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
            {{-- PHASE 13 — bulk status updates ("failed" needs the individual
                 page's reason field; "delivered" needs one received-by name). --}}
            <form action="{{ route('admin.deliveries.bulk') }}" method="POST" id="bulk-deliveries-form"
                  class="filter-row" style="margin-bottom: 12px;">
                @csrf
                @method('PATCH')
                <span class="empty-inline">Bulk — <strong id="bulk-count">0</strong> selected:</span>
                <select name="status" class="filter-select" required>
                    <option value="">Move selected to…</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed / Assigned</option>
                    <option value="out_for_delivery">Out for Delivery</option>
                    <option value="delivered">Delivered</option>
                </select>
                <input type="text" name="received_by" maxlength="100" class="filter-input"
                       placeholder="Received by (needed for Delivered)" style="min-width: 200px;">
                <button type="submit" class="btn btn-sm btn-navy">Apply to selected</button>
            </form>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all deliveries"></th>
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
                                <td>
                                    <input type="checkbox" class="bulk-row" name="delivery_ids[]"
                                           value="{{ $delivery->id }}" form="bulk-deliveries-form"
                                           aria-label="Select delivery {{ $order?->order_number ?? $delivery->id }}">
                                </td>
                                <td><strong>{{ $order?->order_number ?? '—' }}</strong></td>
                                <td>
                                    {{ $order?->customer_name }}<br>
                                    <small class="empty-inline">{{ $order?->customer_phone }}</small>
                                </td>
                                <td>{{ $order?->delivery_address }}</td>
                                <td>{{ $order?->preferred_delivery_date?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $delivery->confirmed_date?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $delivery->assignedPersonLabel() ?? '—' }}</td>
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

@push('scripts')
<script>
    (function () {
        var selectAll = document.getElementById('bulk-select-all');
        if (!selectAll) return;

        var rows = Array.prototype.slice.call(document.querySelectorAll('.bulk-row'));
        var counter = document.getElementById('bulk-count');
        var form = document.getElementById('bulk-deliveries-form');

        function refresh() {
            var count = rows.filter(function (r) { return r.checked; }).length;
            if (counter) counter.textContent = String(count);
        }

        selectAll.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = selectAll.checked; });
            refresh();
        });
        rows.forEach(function (r) { r.addEventListener('change', refresh); });

        if (form) {
            form.addEventListener('submit', function (event) {
                var picked = rows.filter(function (r) { return r.checked; }).length;
                if (picked === 0) {
                    event.preventDefault();
                    showToast('Select at least one delivery first.', 'error');
                    return;
                }
                var statusSelect = form.querySelector('select[name="status"]');
                var value = statusSelect ? statusSelect.value : '';
                var receivedBy = form.querySelector('input[name="received_by"]');
                if (value === 'delivered' && receivedBy && !receivedBy.value.trim()) {
                    event.preventDefault();
                    showToast('Enter who received the batch before marking Delivered.', 'error');
                    return;
                }
                var label = statusSelect && statusSelect.options[statusSelect.selectedIndex]
                    ? statusSelect.options[statusSelect.selectedIndex].text : '';
                if (!confirm('Update ' + picked + ' delivery record(s) to "' + label + '"?')) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
