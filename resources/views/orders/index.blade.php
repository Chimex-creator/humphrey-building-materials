@extends('layouts.app')

@section('title', 'My Orders')

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>My Orders</h1>
            <p>Track everything you have ordered from us.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            @if ($orders->isEmpty())
                <div class="empty-state">
                    <h3>No orders yet</h3>
                    <p>When you place an order, it will show up here.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-navy">Start Shopping</a>
                </div>
            @else
                <div class="my-orders-list">
                    @foreach ($orders as $order)
                        <a href="{{ route('orders.show', $order) }}" class="my-order-card">
                            <div class="my-order-top">
                                <strong>{{ $order->order_number }}</strong>
                                <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                                <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
                            </div>
                            <div class="my-order-meta">
                                <span>{{ $order->created_at->format('d M Y, h:i A') }}</span>
                                <span>{{ $order->items_count }} item{{ $order->items_count === 1 ? '' : 's' }}</span>
                                <span><strong>₦{{ number_format((float) $order->total, 0) }}</strong></span>
                            </div>
                            <div class="my-order-address">
                                @if ($order->isDelivery())
                                    📍 {{ \Illuminate\Support\Str::limit($order->delivery_address, 90) }}
                                @else
                                    🏪 Pickup from shop
                                @endif
                            </div>
                            @if ($order->status !== 'cancelled' && ! $order->isPaid())
                                <div class="my-order-pay">
                                    ⚠️ Payment pending — <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                                    — <span class="pay-link">Pay now →</span>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>

                <div class="pagination">
                    @if ($orders->onFirstPage())
                        <span class="page-btn disabled">&larr; Prev</span>
                    @else
                        <a class="page-btn" href="{{ $orders->previousPageUrl() }}">&larr; Prev</a>
                    @endif
                    <span class="page-info">Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>
                    @if ($orders->hasMorePages())
                        <a class="page-btn" href="{{ $orders->nextPageUrl() }}">Next &rarr;</a>
                    @else
                        <span class="page-btn disabled">Next &rarr;</span>
                    @endif
                </div>
            @endif

        </div>
    </section>

@endsection
