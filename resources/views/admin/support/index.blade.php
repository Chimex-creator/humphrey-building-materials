@extends('layouts.admin')

@section('title', 'Customer Care')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Customer Care</h1>
            <p>
                Complaints and enquiries from customers. Respond and move each one through
                <strong>Open → In Progress → Resolved</strong>. Nothing resolves itself.
            </p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card warn">
            <span class="stat-icon">💬</span>
            <div>
                <strong>{{ $counts['open'] ?? 0 }}</strong>
                <span>Open</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🛠️</span>
            <div>
                <strong>{{ $counts['in_progress'] ?? 0 }}</strong>
                <span>In Progress</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">✅</span>
            <div>
                <strong>{{ $counts['resolved'] ?? 0 }}</strong>
                <span>Resolved</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🗂️</span>
            <div>
                <strong>{{ array_sum($counts->all()) }}</strong>
                <span>Total Requests</span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.support.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $data['search'] ?? '' }}"
                   placeholder="Search reference, subject or customer..." class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                @foreach (\App\Models\SupportTicket::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ ($data['status'] ?? '') === $key ? 'selected' : '' }}>
                        {{ $label }} {{ isset($counts[$key]) ? '('.$counts[$key].')' : '' }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if (!empty($data['search']) || !empty($data['status']))
                <a href="{{ route('admin.support.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($tickets->isEmpty())
            <div class="empty-state">
                <h3>No customer care requests found</h3>
                <p>Nothing matches these filters. New complaints from the website appear here instantly.</p>
            </div>
        @else
            {{-- PHASE 16 — bulk In Progress / Resolve; eligible rows only. --}}
            <form action="{{ route('admin.support.bulk') }}" method="POST" id="bulk-support-form"
                  class="filter-row" style="margin-bottom: 12px;">
                @csrf
                @method('PATCH')
                <span class="empty-inline">Bulk — <strong id="bulk-count">0</strong> selected:</span>
                <button type="submit" name="action" value="start_progress" class="btn btn-sm btn-outline-dark">Mark In Progress</button>
                <button type="submit" name="action" value="resolve" class="btn btn-sm btn-navy">Resolve</button>
            </form>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all tickets"></th>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Filed</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-row" name="ticket_ids[]"
                                           value="{{ $ticket->id }}" form="bulk-support-form"
                                           aria-label="Select {{ $ticket->reference }}">
                                </td>
                                <td><strong>{{ $ticket->reference }}</strong></td>
                                <td>
                                    {{ $ticket->customer_name }}<br>
                                    <small class="empty-inline">{{ $ticket->customer_email }}</small>
                                </td>
                                <td>
                                    {{ $ticket->subject }}
                                    @if ($ticket->order)
                                        <br><small class="empty-inline">
                                            Order <a href="{{ route('admin.orders.show', $ticket->order) }}">{{ $ticket->order->order_number }}</a>
                                        </small>
                                    @endif
                                </td>
                                <td>{{ $ticket->categoryLabel() }}</td>
                                <td>{{ $ticket->created_at->format('d M Y, H:i') }}</td>
                                <td>
                                    <span class="badge {{ $ticket->badgeClass() }}">{{ $ticket->statusLabel() }}</span>
                                    @if ($ticket->responded_at)
                                        <br><small class="empty-inline">Replied {{ $ticket->responded_at->format('d M Y') }}</small>
                                    @endif
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.support.show', $ticket) }}" class="btn btn-sm btn-navy">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($tickets->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $tickets->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $tickets->currentPage() }} of {{ $tickets->lastPage() }}</span>
                @if ($tickets->hasMorePages())
                    <a class="page-btn" href="{{ $tickets->nextPageUrl() }}">Next &rarr;</a>
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
        var form = document.getElementById('bulk-support-form');

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
                    showToast('Select at least one ticket first.', 'error');
                    return;
                }
                var action = event.submitter && event.submitter.value;
                var verb = action === 'resolve' ? 'resolve' : 'mark in progress';
                if (!confirm('Are you sure you want to ' + verb + ' ' + picked + ' selected ticket(s)? Ineligible rows will be skipped.')) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
