@extends('layouts.app')

@section('title', 'Shop by Category — ' . config('app.name'))

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Shop by Category</h1>
            <p>Pick a category to browse quality building materials.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            @if ($categories->isEmpty())
                <div class="empty-state">
                    <h3>No categories yet</h3>
                    <p>Check back soon — our catalogue is growing.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-navy">View All Products</a>
                </div>
            @else
                <div class="category-grid">
                    @foreach ($categories as $category)
                        <a href="{{ route('products.index', ['category' => $category->slug]) }}" class="category-card">
                            <div class="category-icon">{!! \App\Support\CategoryIcons::for($category->slug) !!}</div>
                            <h3>{{ $category->name }}</h3>
                            <p>{{ $category->description ?: 'Browse products in this category.' }}</p>
                            <span class="category-count">
                                {{ $category->active_products_count }}
                                product{{ $category->active_products_count === 1 ? '' : 's' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="back-row" style="margin-top: 32px;">
                <a href="{{ route('products.index') }}" class="btn btn-outline-dark">&larr; Browse All Products</a>
            </div>

        </div>
    </section>

@endsection
