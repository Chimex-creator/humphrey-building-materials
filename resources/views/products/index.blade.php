```blade
@extends('layouts.app')

@section('title', 'Products — Humphrey Building Materials')

{{-- Marks the catalogue so js/app.js can save/restore the scroll position. --}}

@section('page', 'catalogue')

@section('content')

    {{-- Page banner --}}

    <section class="page-banner">

        <div class="container">

            <h1>Our Products</h1>

            <p>Browse quality building materials for residential and commercial projects.</p>

        </div>

    </section>

    <section class="section">

        <div class="container">

            {{-- Search + category filter --}}

            <div class="catalogue-toolbar">

                <form action="{{ route('products.index') }}" method="GET" class="search-form" role="search">

                    @if (request('category'))

                        <input type="hidden" name="category" value="{{ request('category') }}">

                    @endif

                    @if ($sort !== 'newest')

                        <input type="hidden" name="sort" value="{{ $sort }}">

                    @endif

                    @if ($inStockOnly)

                        <input type="hidden" name="stock" value="in">

                    @endif

                    @if ($minPrice !== null)

                        <input type="hidden" name="min_price" value="{{ $minPrice }}">

                    @endif

                    @if ($maxPrice !== null)

                        <input type="hidden" name="max_price" value="{{ $maxPrice }}">

                    @endif

                    <input
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search products (e.g. cement, tiles)..."
                        maxlength="100"
                        aria-label="Search products"
                    >

                    <button type="submit" class="btn btn-navy">Search</button>

                    @if ($search || request('category') || $sort !== 'newest' || $inStockOnly || $minPrice !== null || $maxPrice !== null)

                        <a href="{{ route('products.index') }}" class="btn btn-clear">Clear All</a>

                    @endif

                </form>

                <div class="category-filters" role="navigation" aria-label="Filter by category">

                    <a
                        href="{{ route('products.index', array_filter(['search' => $search, 'sort' => $sort !== 'newest' ? $sort : null, 'stock' => $inStockOnly ? 'in' : null, 'min_price' => $minPrice, 'max_price' => $maxPrice], fn ($v) => $v !== null && $v !== '')) }}"
                        class="filter-pill {{ !request('category') ? 'active' : '' }}"
                    >

                        All

                    </a>

                    @foreach ($categories as $category)

                        <a
                            href="{{ route('products.index', array_filter([
                                'category' => $category->slug,
                                'search' => $search,
                                'sort' => $sort !== 'newest' ? $sort : null,
                                'stock' => $inStockOnly ? 'in' : null,
                                'min_price' => $minPrice,
                                'max_price' => $maxPrice,
                            ], fn ($v) => $v !== null && $v !== '')) }}"
                            class="filter-pill {{ request('category') === $category->slug ? 'active' : '' }}"
                        >

                            {{ $category->name }}

                            <small>({{ $category->active_products_count }})</small>

                        </a>

                    @endforeach

                </div>

                {{-- Price range filter (§18) --}}

                <form action="{{ route('products.index') }}" method="GET" class="price-filter" aria-label="Filter by price">

                    @foreach (request()->query() as $key => $value)

                        @if (!in_array($key, ['min_price', 'max_price', 'page'], true))

                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">

                        @endif

                    @endforeach

                    <label for="min_price">Price (₦)</label>

                    <input type="number" id="min_price" name="min_price" class="filter-input"
                           min="0" step="100" value="{{ $minPrice ?? '' }}" placeholder="Min">

                    <span aria-hidden="true">–</span>

                    <input type="number" name="max_price" class="filter-input"
                           min="0" step="100" value="{{ $maxPrice ?? '' }}" placeholder="Max" aria-label="Maximum price">

                    <button type="submit" class="btn btn-navy">Apply</button>

                </form>

            </div>

            {{-- Sort + stock row --}}

            <div class="sort-bar">

                <form action="{{ route('products.index') }}" method="GET" class="sort-form" id="sortForm">

                    @foreach (request()->query() as $key => $value)

                        @if (!in_array($key, ['sort', 'page'], true))

                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">

                        @endif

                    @endforeach

                    <label for="sort">Sort by</label>

                    <select name="sort" id="sort" class="filter-select" onchange="this.form.submit()">

                        @foreach ($sortOptions as $key => $label)

                            <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>

                        @endforeach

                    </select>

                    <label class="stock-toggle">

                        <input type="checkbox" name="stock" value="in"
                               {{ $inStockOnly ? 'checked' : '' }}
                               onchange="this.form.submit()">

                        <span>In stock only</span>

                    </label>

                </form>

                <p class="results-info">

                    @if ($currentCategory)

                        Showing <strong>{{ $products->total() }}</strong> product(s) in

                        <strong>{{ $currentCategory->name }}</strong>

                    @elseif ($search)

                        Showing <strong>{{ $products->total() }}</strong> result(s) for

                        <strong>&ldquo;{{ $search }}&rdquo;</strong>

                    @else

                        Showing <strong>{{ $products->total() }}</strong> product(s)

                    @endif

                </p>

            </div>

            @if ($products->isEmpty())

                <div class="empty-state">

                    <h3>No products found</h3>

                    <p>Try a different search term, category, or clear the filters.</p>

                    <a href="{{ route('products.index') }}" class="btn btn-navy">View all products</a>

                </div>

            @else

                <div class="product-grid">

                    @foreach ($products as $product)

                        <article class="product-card">

                            <a href="{{ route('products.show', $product->slug) }}" class="product-thumb">

                                @if ($product->is_featured)

                                    <span class="tag">Featured</span>

                                @endif

                                @if ($product->image)

                                    <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}"
                                         class="product-thumb-img" loading="eager" decoding="async">

                                @else

                                    {{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}

                                @endif

                            </a>

                            <div class="product-body">

                                <span class="category-label">{{ $product->category->name }}</span>

                                <h3>

                                    <a href="{{ route('products.show', $product->slug) }}">

                                        {{ $product->name }}

                                    </a>

                                </h3>

                                @if ($product->inStock())

                                    <span class="stock-badge in-stock">In Stock</span>

                                @else

                                    <span class="stock-badge out-of-stock">Out of Stock</span>

                                @endif

                                <div class="price">

                                    ₦{{ number_format($product->price, 0) }}

                                    <small>/ {{ $product->unit ?: 'unit' }}</small>

                                </div>

                                <p class="short-desc">

                                    {{ \Illuminate\Support\Str::limit($product->description, 80) }}

                                </p>

                                <div class="card-actions">

                                    @if ($product->inStock())

                                        <form action="{{ route('cart.store') }}" method="POST"
                                              class="add-to-cart-inline js-add-to-cart">

                                            @csrf

                                            <input type="hidden" name="product_id" value="{{ $product->id }}">

                                            <input type="hidden" name="qty" value="{{ $product->minOrder() }}">

                                            <button type="submit" class="btn btn-primary">Add to Cart</button>

                                        </form>

                                    @else

                                        <button type="button" class="btn btn-primary" disabled>Out of Stock</button>

                                    @endif

                                    <a href="{{ route('products.show', $product->slug) }}" class="btn btn-navy">

                                        View Details

                                    </a>

                                </div>

                            </div>

                        </article>

                    @endforeach

                </div>

                {{-- Simple pagination --}}

                @if ($products->lastPage() > 1)

                    <div class="pagination">

                        @if ($products->onFirstPage())

                            <span class="page-btn disabled">&larr; Prev</span>

                        @else

                            <a class="page-btn" href="{{ $products->previousPageUrl() }}">&larr; Prev</a>

                        @endif

                        <span class="page-info">

                            Page {{ $products->currentPage() }} of {{ $products->lastPage() }}

                        </span>

                        @if ($products->hasMorePages())

                            <a class="page-btn" href="{{ $products->nextPageUrl() }}">Next &rarr;</a>

                        @else

                            <span class="page-btn disabled">Next &rarr;</span>

                        @endif

                    </div>

                @endif

            @endif

        </div>

    </section>

@endsection
```
