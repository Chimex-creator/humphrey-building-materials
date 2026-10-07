@extends('layouts.app')

@section('title', $product->name . ' — Humphrey Building Materials')

@section('page', 'product')

@section('content')

    {{-- Page banner --}}
    <section class="page-banner">
        <div class="container">
            <h1>Product Details</h1>
            <p>
                <a href="{{ route('products.index') }}" class="breadcrumb-link">Products</a>
                &nbsp;/&nbsp;
                <a href="{{ route('products.index', ['category' => $product->category->slug]) }}" class="breadcrumb-link">
                    {{ $product->category->name }}
                </a>
                &nbsp;/&nbsp;
                {{ $product->name }}
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            <div class="product-detail">
                <div class="product-thumb detail-thumb">
                    @if ($product->is_featured)
                        <span class="tag">Featured</span>
                    @endif
                    @if ($product->image)
                        <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}"
                             class="product-thumb-img" loading="lazy">
                    @else
                        {{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}
                    @endif
                </div>

                <div class="product-detail-info">
                    <span class="category-label">{{ $product->category->name }}</span>

                    <h1>{{ $product->name }}</h1>

                    <div class="detail-price">
                        ₦{{ number_format($product->price, 0) }}
                        <small>/ {{ $product->unit ?: 'unit' }}</small>
                    </div>

                    @if ($product->inStock())
                        <span class="stock-badge in-stock">In Stock</span>
                    @else
                        <span class="stock-badge out-of-stock">Out of Stock</span>
                    @endif

                    <p class="detail-desc">{{ $product->description }}</p>

                    @if ($product->specifications)
                        <div class="spec-block">
                            <h3>Specifications</h3>
                            {!! nl2br(e($product->specifications)) !!}
                        </div>
                    @endif

                    <ul class="product-meta">
                        @if ($product->brand)
                            <li><strong>Brand:</strong> {{ $product->brand }}</li>
                        @endif
                        <li><strong>Category:</strong> {{ $product->category->name }}</li>
                        <li><strong>Sold by:</strong> {{ $product->unit ?: 'item' }}</li>
                        <li><strong>Availability:</strong> {{ $product->inStock() ? $product->stock_quantity . ' units available' : 'Currently out of stock' }}</li>
                        @if ($product->minOrder() > 1 || $product->step() > 1)
                            <li>
                                <strong>Ordering:</strong>
                                @if ($product->minOrder() > 1)
                                    minimum {{ $product->minOrder() }}
                                @endif
                                @if ($product->step() > 1)
                                    in multiples of {{ $product->step() }}
                                @endif
                            </li>
                        @endif
                        <li><strong>SKU:</strong> {{ strtoupper($product->slug) }}</li>
                    </ul>

                    <div class="detail-actions">
                        @if ($product->inStock())
                            <form action="{{ route('cart.store') }}" method="POST" class="add-to-cart-form js-add-to-cart">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                <label for="qty" class="qty-label">Qty</label>
                                <input
                                    type="number"
                                    id="qty"
                                    name="qty"
                                    value="{{ $product->minOrder() }}"
                                    min="{{ $product->minOrder() }}"
                                    max="{{ $product->stock_quantity }}"
                                    step="{{ $product->step() }}"
                                    class="qty-input"
                                    aria-label="Quantity"
                                >
                                <button type="submit" class="btn btn-primary">🛒 Add to Cart</button>
                            </form>
                        @else
                            <button type="button" class="btn btn-primary" disabled>Out of Stock</button>
                        @endif

                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="btn btn-outline-dark">Call to Order</a>
                        <a href="mailto:{{ $email }}?subject=Order enquiry: {{ urlencode($product->name) }}" class="btn btn-navy">
                            Email Enquiry
                        </a>
                    </div>

                    <p class="detail-note">
                        🚚 Delivery available — contact us for bulk pricing and site delivery quotes.
                    </p>
                </div>
            </div>

            {{-- Related products (same category) --}}
            @if ($related->isNotEmpty())
                <div class="related-section">
                    <div class="section-head" style="text-align: left; margin-bottom: 20px;">
                        <span class="eyebrow">More like this</span>
                        <h2>Related in {{ $product->category->name }}</h2>
                    </div>

                    <div class="product-grid">
                        @foreach ($related as $item)
                            <article class="product-card">
                                <a href="{{ route('products.show', $item->slug) }}" class="product-thumb">
                                    @if ($item->image)
                                        <img src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->name }}"
                                             class="product-thumb-img" loading="lazy">
                                    @else
                                        {{ mb_strtoupper(mb_substr($item->name, 0, 2)) }}
                                    @endif
                                </a>
                                <div class="product-body">
                                    <span class="category-label">{{ $item->category->name }}</span>
                                    <h3>
                                        <a href="{{ route('products.show', $item->slug) }}">{{ $item->name }}</a>
                                    </h3>
                                    @if ($item->inStock())
                                        <span class="stock-badge in-stock">In Stock</span>
                                    @else
                                        <span class="stock-badge out-of-stock">Out of Stock</span>
                                    @endif
                                    <div class="price">
                                        ₦{{ number_format($item->price, 0) }}
                                        <small>/ {{ $item->unit ?: 'unit' }}</small>
                                    </div>

                                    <div class="card-actions">
                                        @if ($item->inStock())
                                            <form action="{{ route('cart.store') }}" method="POST"
                                                  class="add-to-cart-inline js-add-to-cart">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $item->id }}">
                                                <input type="hidden" name="qty" value="{{ $item->minOrder() }}">
                                                <button type="submit" class="btn btn-primary">Add to Cart</button>
                                            </form>
                                        @else
                                            <button type="button" class="btn btn-primary" disabled>Out of Stock</button>
                                        @endif

                                        <a href="{{ route('products.show', $item->slug) }}" class="btn btn-navy">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="back-row">
                <a href="{{ route('products.index') }}" class="btn btn-outline-dark">&larr; Back to Products</a>
            </div>

        </div>
    </section>

@endsection
