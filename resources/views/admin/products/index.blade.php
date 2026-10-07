@extends('layouts.admin')

@section('title', 'Products')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Products</h1>
            <p>Manage the product catalogue — pricing, stock, status and images.</p>
        </div>
        <a href="{{ route('admin.products.create') }}" class="btn btn-primary">+ Add Product</a>
    </div>

    <div class="admin-card">
        {{-- Filters --}}
        <form action="{{ route('admin.products.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name or description..."
                   class="filter-input">
            <select name="category" class="filter-select">
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" {{ (string) $categoryFilter === (string) $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                @endforeach
            </select>
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $statusFilter === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $categoryFilter || $statusFilter)
                <a href="{{ route('admin.products.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($products->isEmpty())
            <div class="empty-state">
                <h3>No products found</h3>
                <p>Try different filters, or add a new product.</p>
                <a href="{{ route('admin.products.create') }}" class="btn btn-navy">Add Product</a>
            </div>
        @else
            {{-- Final Spec §39 — bulk activate / deactivate (checkboxes join via the
                 HTML `form` attribute so the per-row delete forms stay untouched) --}}
            <form action="{{ route('admin.products.bulk') }}" method="POST" id="bulk-products-form"
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
                            <th><input type="checkbox" id="bulk-select-all" aria-label="Select all products"></th>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Featured</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>
                                    <input type="checkbox" class="bulk-row" name="product_ids[]"
                                           value="{{ $product->id }}" form="bulk-products-form"
                                           aria-label="Select {{ $product->name }}">
                                </td>
                                <td>
                                    @if ($product->image)
                                        <img src="{{ asset('storage/' . $product->image) }}" alt=""
                                             class="admin-thumb">
                                    @else
                                        <span class="admin-thumb placeholder">{{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $product->name }}</strong><br>
                                    <small class="empty-inline">{{ $product->unit ? '/' . $product->unit : '' }}</small>
                                </td>
                                <td>{{ $product->category->name ?? '—' }}</td>
                                <td>₦{{ number_format($product->price, 0) }}</td>
                                <td>
                                    @if ($product->stock_quantity <= 0)
                                        <span class="badge badge-red">0</span>
                                    @elseif ($product->stock_quantity <= $lowStockThreshold)
                                        <span class="badge badge-warn">{{ $product->stock_quantity }}</span>
                                    @else
                                        <span class="badge badge-green">{{ $product->stock_quantity }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($product->status === 'active')
                                        <span class="badge badge-green">Active</span>
                                    @else
                                        <span class="badge badge-red">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($product->is_featured)
                                        <span class="badge badge-amber">★ Yes</span>
                                    @else
                                        <span class="empty-inline">No</span>
                                    @endif
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-sm btn-navy">Edit</a>
                                    <form action="{{ route('admin.products.destroy', $product) }}" method="POST"
                                          class="inline-delete"
                                          onsubmit="return confirm('Delete product &ldquo;{{ $product->name }}&rdquo;? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($products->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $products->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>
                @if ($products->hasMorePages())
                    <a class="page-btn" href="{{ $products->nextPageUrl() }}">Next &rarr;</a>
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
        var form = document.getElementById('bulk-products-form');

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
                    showToast('Select at least one product first.', 'error');
                    return;
                }
                var action = event.submitter && event.submitter.value;
                var verb = action === 'deactivate' ? 'deactivate' : 'activate';
                if (!confirm('Are you sure you want to ' + verb + ' ' + picked + ' product(s)?')) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>
@endpush
