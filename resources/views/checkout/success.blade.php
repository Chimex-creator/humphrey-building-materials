@extends('layouts.app')

@section('title', 'Order Confirmed — ' . $order->order_number)

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Order Confirmed 🎉</h1>
            <p>Thank you — we have received your order.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            @include('partials.checkout-progress', ['currentStep' => 5])

            <div class="success-card">
                <div class="success-icon">✓</div>
                <h2>Order {{ $order->order_number }}</h2>
                <p class="success-sub">
                    Status: <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                    &nbsp;·&nbsp;
                    Payment:
                    <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
                </p>
                <p>{{ $note }}</p>

                {{-- What happens next with the money --}}
                <div class="pay-state
                    @if ($order->payment_status === 'paid') pay-state-success
                    @elseif ($order->delivery_fee_status === 'pending_confirmation') pay-state-wait
                    @else pay-state-info @endif">
                    @if ($order->payment_status === 'paid')
                        <h4>✓ Payment received</h4>
                        <p>Your payment of <strong>₦{{ number_format((float) $order->total, 0) }}</strong> is confirmed. We are preparing your order.</p>
                    @elseif ($order->delivery_fee_status === 'pending_confirmation')
                        <h4>Delivery fee to be confirmed</h4>
                        <p>
                            Our team will confirm the delivery fee for
                            <strong>{{ $order->delivery_address }}</strong> and notify you.
                            You can pay the final total after that.
                        </p>
                    @else
                        <h4>Complete your payment</h4>
                        <p>
                            Amount to pay: <strong>₦{{ number_format((float) $order->total, 0) }}</strong>.
                            Pay securely online with Paystack to confirm your order.
                        </p>
                    @endif
                </div>

                <div class="success-actions">
                    @if (! $order->isPaid() && $order->status !== 'cancelled')
                        <a href="{{ route('orders.payment', $order) }}" class="btn btn-primary">
                            💳 Go to Payment
                        </a>
                    @endif
                    @if (auth()->check())
                        <a href="{{ route('orders.show', $order) }}" class="btn btn-navy">View Order</a>
                    @endif
                    <a href="{{ route('products.index') }}" class="btn btn-outline-dark">Continue Shopping</a>
                </div>

                <div class="success-details">
                    <div>
                        <h4>Customer</h4>
                        <p>
                            {{ $order->customer_name }}<br>
                            {{ $order->customer_email }}<br>
                            {{ $order->customer_phone }}
                        </p>
                    </div>
                    <div>
                        <h4>{{ $order->isDelivery() ? 'Delivery Address' : 'Pickup' }}</h4>
                        <p>{{ $order->delivery_address }}</p>
                        @if ($order->isDelivery() && $order->preferred_delivery_date)
                            <p><small class="empty-inline">Preferred date: {{ $order->preferred_delivery_date->format('d M Y') }}</small></p>
                        @endif
                    </div>
                    @if ($order->notes)
                        <div>
                            <h4>Notes</h4>
                            <p>{{ $order->notes }}</p>
                        </div>
                    @endif
                    <div>
                        <h4>Placed</h4>
                        <p>{{ $order->created_at->format('d M Y, h:i A') }}</p>
                    </div>
                    <div>
                        <h4>Payment Option</h4>
                        <p>
                            {{ $order->paymentOptionLabel() }}<br>
                            <small class="empty-inline">Delivery: {{ $order->feeStatusLabel() }}</small>
                        </p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Unit Price</th>
                                <th>Qty</th>
                                <th>Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr>
                                    <td>{{ $item->product_name }}@if ($item->unit) <small>/ {{ $item->unit }}</small>@endif</td>
                                    <td>₦{{ number_format((float) $item->unit_price, 0) }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td>₦{{ number_format((float) $item->line_total, 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3"><strong>Subtotal</strong></td>
                                <td><strong>₦{{ number_format((float) $order->subtotal, 0) }}</strong></td>
                            </tr>
                            <tr>
                                <td colspan="3">Delivery ({{ $order->feeStatusLabel() }})</td>
                                <td>
                                    @if ((float) $order->delivery_fee > 0)
                                        ₦{{ number_format((float) $order->delivery_fee, 0) }}
                                    @else
                                        Free
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3"><strong>Total</strong></td>
                                <td><strong>₦{{ number_format((float) $order->total, 0) }}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="success-actions">
                    <a href="{{ route('home') }}" class="btn btn-outline-dark">Back to Home</a>
                </div>
            </div>

        </div>
    </section>

@endsection
