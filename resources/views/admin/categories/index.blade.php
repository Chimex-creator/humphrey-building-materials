@extends('layouts.admin')

@section('title', 'Categories')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Product Categories</h1>
            <p>Organise the catalogue into categories customers can browse.</p>
        </div>
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary">+ Add Category</a>
    </div>

    <div class="admin-card">
        @if ($categories->isEmpty())
            <div class="empty-state">
                <h3>No categories yet</h3>
                <p>Create your first category to start organising products.</p>
                <a href="{{ route('admin.categories.create') }}" class="btn btn-navy">Add Category</a>
            </div>
        @else
            {{-- PHASE 11 — bulk delete (categories have no activation state). --}}
            <form action="{{ route('admin.categories.bulk') }}" method="POST" id="bulk-categories-form"
                  class="filter-row" style="margin-bottom: 12px;">
                @csrf
                <span class="empty-inline">Bulk actions — <strong id="bulk-count">0</strong> selected:</span>
                <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger">Delete selected (empty only)</button>
            </form>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all categories"></th>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-row" name="category_ids[]"
                                           value="{{ $category->id }}" form="bulk-categories-form"
                                           aria-label="Select {{ $category->name }}">
                                </td>
                                <td><strong>{{ $category->name }}</strong></td>
                                <td><code>{{ $category->slug }}</code></td>
                                <td>
                                    @if ($category->description)
                                        {{ \Illuminate\Support\Str::limit($category->description, 60) }}
                                    @else
                                        <span class="empty-inline">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $category->products_count > 0 ? 'badge-green' : 'badge-red' }}">
                                        {{ $category->products_count }}
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-navy">Edit</a>
                                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                          class="inline-delete"
                                          onsubmit="return confirm('Delete category &ldquo;{{ $category->name }}&rdquo;?');">
                                        @csrf
                                        @method('DELETE')
                                        {{-- PHASE 11 — deletion is blocked while products still use
                                             this category (the FK would cascade-delete them). --}}
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                @if ($category->products_count > 0)
                                                    disabled
                                                    title="Has {{ $category->products_count }} product(s) — move or delete them first"
                                                @endif>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($categories->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $categories->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $categories->currentPage() }} of {{ $categories->lastPage() }}</span>
                @if ($categories->hasMorePages())
                    <a class="page-btn" href="{{ $categories->nextPageUrl() }}">Next &rarr;</a>
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
        var form = document.getElementById('bulk-categories-form');

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
                    showToast('Select at least one category first.', 'error');
                    return;
                }
                if (!confirm('Permanently delete ' + picked + ' selected empty categor' + (picked === 1 ? 'y?' : 'ies?'))) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
