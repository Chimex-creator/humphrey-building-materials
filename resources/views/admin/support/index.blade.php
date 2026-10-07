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
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
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
