@extends('layouts.admin')

@section('title', 'Activity Log')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Activity Log</h1>
            <p>Permanent audit trail: who changed stock, prices, payments, orders, staff and settings (Master §47).</p>
        </div>
    </div>

    <div class="admin-card">
        <div class="pay-figures">
            <div class="pay-figure">
                <span>Total entries</span>
                <strong>{{ $summary['total'] }}</strong>
            </div>
            <div class="pay-figure">
                <span>Recorded today</span>
                <strong>{{ $summary['today'] }}</strong>
            </div>
            <div class="pay-figure">
                <span>People involved</span>
                <strong>{{ $summary['actors'] }}</strong>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.activity-logs.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Search what, which record, or who..."
                   class="filter-input">
            <select name="action" class="filter-select">
                <option value="">All actions</option>
                @foreach (\App\Models\ActivityLog::ACTIONS as $key => $label)
                    <option value="{{ $key }}" {{ $actionFilter === $key ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $actionFilter)
                <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($logs->isEmpty())
            <div class="empty-state">
                <h3>No activity recorded yet</h3>
                <p>Stock adjustments, price changes, payments, cancellations, staff changes and settings edits all land here.</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Who</th>
                            <th>Action</th>
                            <th>Record</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td>
                                    {{ $log->created_at->format('d M Y') }}
                                    <br><small class="empty-inline">{{ $log->created_at->format('h:i A') }}</small>
                                </td>
                                <td>
                                    @if ($log->user)
                                        {{ $log->user->name }}
                                        <br><small class="empty-inline">{{ \App\Models\User::ROLES[$log->user->role] ?? $log->user->role }}</small>
                                    @else
                                        <span class="empty-inline">System</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $log->actionBadgeClass() }}">{{ $log->actionLabel() }}</span>
                                </td>
                                <td>
                                    @if ($log->subject_label)
                                        <strong>{{ $log->subject_label }}</strong>
                                    @else
                                        —
                                    @endif
                                    @if ($log->ip_address)
                                        <br><small class="empty-inline">from {{ $log->ip_address }}</small>
                                    @endif
                                </td>
                                <td>
                                    {{ $log->description }}
                                    @if (! empty($log->details))
                                        <br><small class="empty-inline">
                                            @foreach ($log->details as $key => $value)
                                                {{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}{{ $loop->last ? '' : ' · ' }}
                                            @endforeach
                                        </small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($logs->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $logs->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
                @if ($logs->hasMorePages())
                    <a class="page-btn" href="{{ $logs->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
