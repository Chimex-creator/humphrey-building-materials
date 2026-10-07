@extends('layouts.app')

@section('title', 'Your Cart — Humphrey Building Materials')

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Your Shopping Cart</h1>
            <p>
                <a href="{{ route('home') }}" class="breadcrumb-link">Home</a>
                &nbsp;/&nbsp;
                <a href="{{ route('products.index') }}" class="breadcrumb-link">Products</a>
                &nbsp;/&nbsp;
                Cart
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            @include('partials.checkout-progress', ['currentStep' => 1])

            @if ($items->isEmpty())
                <div class="empty-state">
                    <h3>Your cart is empty</h3>
                    <p>Browse our catalogue and add some materials to get started.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-navy">Browse Products</a>
                </div>
            @else
                <div class="cart-layout">

                    {{-- Line items --}}
                    <div class="cart-lines">
                        <div class="cart-line-head">
                            <span>Product</span>
                            <span>Price</span>
                            <span>Qty</span>
                            <span>Total</span>
                            <span></span>
                        </div>

                        @foreach ($items as $line)
                            @php $p = $line->product; @endphp
                            <div class="cart-line">
                                <div class="cart-line-product">
                                    <a href="{{ route('products.show', $p->slug) }}" class="cart-thumb">
                                        @if ($p->image)
                                            <img src="{{ asset('storage/' . $p->image) }}" alt="{{ $p->name }}">
                                        @else
                                            <span>{{ mb_strtoupper(mb_substr($p->name, 0, 2)) }}</span>
                                        @endif
                                    </a>
                                    <div>
                                        <a href="{{ route('products.show', $p->slug) }}" class="cart-line-name">
                                            {{ $p->name }}
                                        </a>
                                        <small>{{ $p->category->name }} · {{ $p->unit ?: 'unit' }}</small>
                                        @if ($p->stock_quantity < 5 && $p->stock_quantity > 0)
                                            <small class="low-stock">Only {{ $p->stock_quantity }} left</small>
                                        @endif
                                    </div>
                                </div>

                                <div class="cart-line-price">₦{{ number_format((float) $p->price, 0) }}</div>

                                <div class="cart-line-qty">
                                    <form action="{{ route('cart.update', $p->id) }}" method="POST" class="qty-form">
                                        @csrf
                                        @method('PATCH')
                                        <input
                                            type="number"
                                            name="qty"
                                            value="{{ $line->qty }}"
                                            min="0"
                                            step="{{ $p->step() }}"
                                            max="{{ $p->stock_quantity }}"
                                            aria-label="Quantity for {{ $p->name }}"
                                        >
                                        <button type="submit" class="btn btn-sm btn-navy qty-btn">Update</button>
                                        @if ($p->minOrder() > 1 || $p->step() > 1)
                                            <small class="field-help">
                                                Min {{ $p->minOrder() }}@if ($p->step() > 1), multiples of {{ $p->step() }}@endif · enter 0 to remove
                                            </small>
                                        @else
                                            <small class="field-help">Enter 0 to remove</small>
                                        @endif
                                    </form>
                                </div>

                                <div class="cart-line-total">₦{{ number_format($line->line_total, 0) }}</div>

                                <div class="cart-line-remove">
                                    <form action="{{ route('cart.destroy', $p->id) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-remove" title="Remove item" aria-label="Remove {{ $p->name }}">✕</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach

                        <div class="cart-actions">
                            <a href="{{ route('products.index') }}" class="btn btn-outline-dark btn-sm">&larr; Continue Shopping</a>
                            <form action="{{ route('cart.clear') }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Clear Cart</button>
                            </form>
                        </div>
                    </div>

                    {{-- Summary --}}
                    <aside class="cart-summary">
                        <h2>Order Summary</h2>
                        <ul class="summary-rows">
                            <li>
                                <span>Subtotal ({{ $items->sum('qty') }} item(s))</span>
                                <strong>₦{{ number_format($subtotal, 0) }}</strong>
                            </li>
                            <li>
                                <span>Delivery</span>
                                <strong>Confirmed by our team</strong>
                            </li>
                            <li class="summary-total">
                                <span>Total before delivery</span>
                                <strong>₦{{ number_format($subtotal, 0) }}</strong>
                            </li>
                        </ul>

                        <a href="{{ route('checkout.show') }}" class="btn btn-primary btn-block">
                            Proceed to Checkout →
                        </a>

                        <p class="summary-note">
                            Pickup orders have no delivery fee. For delivery orders our team
                            confirms the fee before payment. To pay online,
                            <a href="{{ route('login') }}">log in</a> first.
                        </p>
                    </aside>

                </div>
            @endif

        </div>
    </section>

@endsection
