@extends('layouts.admin')

@section('title', 'Customers')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Customers</h1>
            <p>Everyone who shops with you - what they spend, what they ordered and whether they can still sign in.</p>
        </div>
        @if (auth()->user()->isAdmin())
            <a href="{{ route('admin.users.index') }}" class="btn btn-navy">Users &amp; Staff</a>
        @endif
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">👥</span>
            <div>
                <strong>{{ $stats['total'] }}</strong>
                <span>Customers</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">✅</span>
            <div>
                <strong>{{ $stats['active'] }}</strong>
                <span>Active</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">⛔</span>
            <div>
                <strong>{{ $stats['inactive'] }}</strong>
                <span>Deactivated</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🆕</span>
            <div>
                <strong>{{ $stats['new_month'] }}</strong>
                <span>New This Month</span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.customers.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Search name, email or phone..."
                   class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All accounts</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Deactivated</option>
            </select>
            <select name="sort" class="filter-select">
                @foreach ($sorts as $key => $label)
                    <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $status || $sort !== 'recent')
                <a href="{{ route('admin.customers.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($customers->isEmpty())
            <div class="empty-state">
                <h3>No customers found</h3>
                @if ($search || $status || $sort !== 'recent')
                    <p>Try a different search or filter.</p>
                    <a href="{{ route('admin.customers.index') }}" class="btn btn-navy">Clear filters</a>
                @else
                    <p>Customers appear here as soon as they create an account.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-navy">View Shop</a>
                @endif
            </div>
        @else
            <div class="table-wrap">
                {{-- Final Spec §39 — bulk activate / deactivate (admins only) --}}
                <form action="{{ route('admin.customers.bulk') }}" method="POST" id="bulk-customers-form">
                    @csrf
                    @if (auth()->user()->isAdmin())
                        <div class="filter-row" style="margin-bottom: 12px;">
                            <span class="empty-inline">Bulk actions — <strong id="bulk-count">0</strong> selected:</span>
                            <button type="submit" name="action" value="activate" class="btn btn-sm btn-navy">Activate selected</button>
                            <button type="submit" name="action" value="deactivate" class="btn btn-sm btn-danger">Deactivate selected</button>
                        </div>
                    @endif

                    <table class="admin-table">
                        <thead>
                            <tr>
                                @if (auth()->user()->isAdmin())
                                    <th><input type="checkbox" id="bulk-select-all" aria-label="Select all customers"></th>
                                @endif
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Joined</th>
                                <th>Orders</th>
                                <th>Lifetime Spend</th>
                                <th>Last Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customers as $person)
                                <tr>
                                    @if (auth()->user()->isAdmin())
                                        <td>
                                            <input type="checkbox" class="bulk-row" name="customer_ids[]"
                                                   value="{{ $person->id }}" aria-label="Select {{ $person->name }}">
                                        </td>
                                    @endif
                                    <td>
                                    <strong>{{ $person->name }}</strong><br>
                                    <small class="empty-inline">{{ $person->email }}</small>
                                </td>
                                <td>{{ $person->phone ?: '—' }}</td>
                                <td>{{ $person->created_at->format('d M Y') }}</td>
                                <td>{{ $person->orders_count }}</td>
                                <td><strong>₦{{ number_format((float) $person->lifetime_paid, 0) }}</strong></td>
                                <td>{{ $person->last_order_at ? \Illuminate\Support\Carbon::parse($person->last_order_at)->format('d M Y') : '—' }}</td>
                                <td>
                                    @if ($person->is_active)
                                        <span class="badge badge-green">Active</span>
                                    @else
                                        <span class="badge badge-red">Deactivated</span>
                                    @endif
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.customers.show', $person) }}"
                                       class="btn btn-sm btn-navy">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    </table>
                </form>
            </div>

            <div class="pagination">
                @if ($customers->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $customers->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $customers->currentPage() }} of {{ $customers->lastPage() }}</span>
                @if ($customers->hasMorePages())
                    <a class="page-btn" href="{{ $customers->nextPageUrl() }}">Next &rarr;</a>
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

        function refresh() {
            var count = rows.filter(function (r) { return r.checked; }).length;
            if (counter) counter.textContent = String(count);
        }

        selectAll.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = selectAll.checked; });
            refresh();
        });
        rows.forEach(function (r) { r.addEventListener('change', refresh); });

        // The bulk form must carry a real selection + confirmation.
        var form = document.getElementById('bulk-customers-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                var picked = rows.filter(function (r) { return r.checked; }).length;
                if (picked === 0) {
                    event.preventDefault();
                    showToast('Select at least one customer first.', 'error');
                    return;
                }
                var action = event.submitter && event.submitter.value;
                var verb = action === 'deactivate' ? 'deactivate' : 'activate';
                if (!confirm('Are you sure you want to ' + verb + ' ' + picked + ' customer account(s)?')) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
