@extends('layouts.admin')

@section('title', 'Inventory')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Inventory</h1>
            <p>Stock on hand and selling price. Items at or below {{ $summary['threshold'] }} are flagged as low stock.</p>
        </div>
        <div class="filter-row" style="border:0; padding:0; background:none;">
            <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-navy">Adjust Stock</a>
            <a href="{{ route('admin.returns.create') }}" class="btn btn-outline-dark">Record Return</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">📦</span>
            <div>
                <strong>{{ $summary['total'] }}</strong>
                <span>Products</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🧮</span>
            <div>
                <strong>{{ number_format($summary['units']) }}</strong>
                <span>Units In Stock</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">⚠️</span>
            <div>
                <strong>{{ $summary['low'] }}</strong>
                <span>Low Stock (≤ {{ $summary['threshold'] }})</span>
            </div>
        </div>
        <div class="stat-card warn">
            <span class="stat-icon">🚫</span>
            <div>
                <strong>{{ $summary['out'] }}</strong>
                <span>Out Of Stock</span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.inventory.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name, brand or description..."
                   class="filter-input">
            <select name="category" class="filter-select">
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" {{ (string) $categoryFilter === (string) $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                @endforeach
            </select>
            <select name="stock" class="filter-select">
                <option value="">All stock levels</option>
                <option value="in" {{ $stockFilter === 'in' ? 'selected' : '' }}>Healthy stock</option>
                <option value="low" {{ $stockFilter === 'low' ? 'selected' : '' }}>Low stock (≤ {{ $summary['threshold'] }})</option>
                <option value="out" {{ $stockFilter === 'out' ? 'selected' : '' }}>Out of stock</option>
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $categoryFilter || $stockFilter)
                <a href="{{ route('admin.inventory.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($products->isEmpty())
            <div class="empty-state">
                <h3>No products found</h3>
                <p>Try different filters, or add a new product to the catalogue.</p>
                <a href="{{ route('admin.products.create') }}" class="btn btn-navy">Add Product</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>In Stock</th>
                            <th>Selling Price</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>
                                    <strong>{{ $product->name }}</strong><br>
                                    <small class="empty-inline">{{ $product->brand ? $product->brand . ' · ' : '' }}{{ $product->unit ? '/' . $product->unit : '' }}</small>
                                </td>
                                <td>{{ $product->category->name ?? '—' }}</td>
                                <td>
                                    @if ($product->stock_quantity <= 0)
                                        <span class="badge badge-red">0</span>
                                    @elseif ($product->isLowStock($summary['threshold']))
                                        <span class="badge badge-warn">{{ $product->stock_quantity }}</span>
                                    @else
                                        <span class="badge badge-green">{{ $product->stock_quantity }}</span>
                                    @endif
                                </td>
                                <td>₦{{ number_format($product->price, 0) }}</td>
                                <td>
                                    @if ($product->stock_quantity <= 0)
                                        <span class="badge badge-red">Out of stock</span>
                                    @elseif ($product->isLowStock($summary['threshold']))
                                        <span class="badge badge-warn">Low stock</span>
                                    @else
                                        <span class="badge badge-green">In stock</span>
                                    @endif
                                </td>
                                <td class="actions-cell">
                                    <a href="{{ route('admin.stock-adjustments.create', ['product_id' => $product->id]) }}"
                                       class="btn btn-sm btn-navy">Adjust</a>
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
