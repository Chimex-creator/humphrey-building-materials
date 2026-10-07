@extends('layouts.admin')

@section('title', 'Users / Staff')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Users &amp; Staff</h1>
            <p>Manage customer and staff accounts, roles and activation status — tick the boxes to activate or deactivate several accounts at once.</p>
        </div>
        @if (auth()->user()->isAdmin())
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">+ Add Staff Account</a>
        @endif
    </div>

    {{-- Filters --}}
    <div class="admin-card">
        <form action="{{ route('admin.users.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name or email..." class="filter-input">
            <select name="role" class="filter-select">
                <option value="">All roles</option>
                @foreach (\App\Models\User::ROLES as $key => $label)
                    <option value="{{ $key }}" {{ $roleFilter === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $roleFilter)
                <a href="{{ route('admin.users.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($users->isEmpty())
            <div class="empty-state">
                <h3>No users found</h3>
                <p>Try a different search or filter.</p>
            </div>
        @else
            {{-- §39 — bulk activate / deactivate (checkboxes join via the
                 HTML `form` attribute so the per-row Edit links stay untouched) --}}
            <form action="{{ route('admin.users.bulk') }}" method="POST" id="bulk-users-form"
                  class="filter-row" style="margin-bottom: 12px;">
                @csrf
                <span class="empty-inline">Bulk actions — <strong id="bulk-count">0</strong> selected:</span>
                <button type="submit" name="action" value="activate" class="btn btn-sm btn-navy">Activate selected</button>
                <button type="submit" name="action" value="deactivate" class="btn btn-sm btn-danger">Deactivate selected</button>
            </form>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all accounts"></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-row" name="user_ids[]"
                                           value="{{ $user->id }}" form="bulk-users-form"
                                           aria-label="Select {{ $user->name }}">
                                </td>
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
                                <td>{{ $user->created_at?->format('d M Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-navy">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($users->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $users->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
                @if ($users->hasMorePages())
                    <a class="page-btn" href="{{ $users->nextPageUrl() }}">Next &rarr;</a>
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
        var form = document.getElementById('bulk-users-form');

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
                    showToast('Select at least one account first.', 'error');
                    return;
                }
                var action = event.submitter && event.submitter.value;
                var verb = action === 'deactivate' ? 'deactivate' : 'activate';
                if (!confirm('Are you sure you want to ' + verb + ' ' + picked + ' account(s)?')) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
