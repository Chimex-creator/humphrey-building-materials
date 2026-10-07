@extends('layouts.app')

@section('title', 'Humphrey Building Materials — Quality Building Materials')

@section('content')

    {{-- Hero --}}
    <section class="hero">
        <div class="container">
            <span class="hero-badge">Trusted Building Materials Supplier</span>
            <h1>Quality Building Materials for <span>Every Project</span></h1>
            <p>
                From cement and steel to timber, sand and tiles — Humphrey Building Materials
                supplies reliable materials at fair prices, delivered to your site on time.
            </p>
            <div class="hero-actions">
                <a href="{{ route('products.index') }}" class="btn btn-primary">Browse Materials</a>
                <a href="#contact" class="btn btn-outline">Request a Quote</a>
            </div>

            <div class="hero-stats">
                <div class="stat">
                    <strong>{{ number_format($stats['products']) }}+</strong>
                    <span>Products in Stock</span>
                </div>
                <div class="stat">
                    <strong>{{ number_format($stats['categories']) }}+</strong>
                    <span>Material Categories</span>
                </div>
                <div class="stat">
                    <strong>{{ number_format($stats['customers']) }}+</strong>
                    <span>Registered Customers</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Categories (from database) --}}
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">What We Sell</span>
                <h2>Shop by Category</h2>
                <p>Browse our wide range of quality building materials for residential and commercial projects.</p>
            </div>

            <div class="category-grid">
                @forelse ($categories as $category)
                    <a href="{{ route('products.index', ['category' => $category->slug]) }}" class="category-card">
                        <div class="category-icon">{!! \App\Support\CategoryIcons::for($category->slug) !!}</div>
                        <h3>{{ $category->name }}</h3>
                        <p>{{ $category->description }}</p>
                        <span class="category-count">
                            {{ $category->active_products_count }}
                            product{{ $category->active_products_count === 1 ? '' : 's' }}
                        </span>
                    </a>
                @empty
                    <p class="results-info">No categories have been published yet.</p>
                @endforelse
            </div>

            @if ($categories->isNotEmpty())
                <div class="back-row" style="margin-top: 24px; text-align: center;">
                    <a href="{{ route('categories.index') }}" class="btn btn-outline-dark">View All Categories</a>
                </div>
            @endif
        </div>
    </section>

    {{-- Featured products --}}
    <section class="section">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">Popular Items</span>
                <h2>Featured Products</h2>
                <p>Hand-picked materials our customers order the most.</p>
            </div>

            <div class="product-grid">
                @forelse ($featuredProducts as $product)
                    <article class="product-card">
                        <a href="{{ route('products.show', $product->slug) }}" class="product-thumb">
                            @if ($product->is_featured)
                                <span class="tag">Featured</span>
                            @endif
                            @if ($product->image)
                                <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}"
                                     class="product-thumb-img" loading="lazy">
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

                                <a href="{{ route('products.show', $product->slug) }}" class="btn btn-navy">View Details</a>
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="results-info">
                        No featured products right now —
                        <a href="{{ route('products.index') }}">browse the full catalogue</a>.
                    </p>
                @endforelse
            </div>

            @if ($featuredProducts->isNotEmpty())
                <div class="back-row" style="margin-top: 24px; text-align: center;">
                    <a href="{{ route('products.index') }}" class="btn btn-navy">View Full Catalogue</a>
                </div>
            @endif
        </div>
    </section>

    {{-- Why choose us --}}
    <section class="section section-alt" id="why-us">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">Why Humphrey</span>
                <h2>Why Choose Us</h2>
                <p>We make buying building materials simple, reliable and affordable.</p>
            </div>

            <div class="features-grid">
                @foreach ($features as $feature)
                    <div class="feature-card">
                        <div class="feature-icon">{!! $feature['icon'] !!}</div>
                        <h3>{{ $feature['title'] }}</h3>
                        <p>{{ $feature['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Call to action (contacts from DB — Final Spec §24/§25/§26) --}}
    <section class="cta-band" id="contact">
        <div class="container">
            <div>
                <h2>Ready to Start Your Project?</h2>
                <p>
                    Contact us today for prices, bulk discounts and fast delivery.
                    Our team is ready to help you choose the right materials.
                </p>
                <p class="cta-address">
                    📍 {{ $address }}
                </p>

                @if ($contacts->isNotEmpty())
                    <p class="cta-address" style="margin-top:6px;">
                        @foreach ($contacts as $contact)
                            📞 <strong>{{ $contact->label }}:</strong>
                            <a href="{{ $contact->telHref() }}" data-action="call">{{ $contact->phone }}</a><br>
                        @endforeach
                    </p>
                @else
                    <p class="cta-address" style="margin-top:6px;">
                        📞 <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" data-action="call">{{ $phone }}</a>
                    </p>
                @endif

                <p class="cta-address" style="margin-top:6px;">
                    ✉️ <strong>Email us at</strong>
                    <a href="mailto:{{ $email }}" data-action="email" id="contact-email">{{ $email }}</a>
                    <button type="button" class="btn btn-sm btn-outline copy-email-btn"
                            data-copy-email="{{ $email }}" aria-label="Copy email address">
                        Copy Email
                    </button>
                </p>
            </div>
            <div class="cta-actions">
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contacts->first()?->phone ?? $phone) }}"
                   class="btn btn-primary" data-action="call">Call Us</a>
                <a href="mailto:{{ $email }}" class="btn btn-outline" data-action="email">Email Us</a>
            </div>
        </div>
    </section>

@endsection
