@extends('layouts.app')

@section('title', 'Order ' . $order->order_number)

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Order {{ $order->order_number }}</h1>
            <p>
                Placed {{ $order->created_at->format('d M Y, h:i A') }}
                &nbsp;·&nbsp;
                <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                &nbsp;·&nbsp;
                <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            <div class="success-card">
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
                        {{-- Phase 7 - what the delivery team has promised --}}
                        @if ($order->delivery)
                            <p>
                                <span class="badge {{ $order->delivery->statusBadgeClass() }}">{{ $order->delivery->statusLabel() }}</span>
                            </p>
                            @if ($order->delivery->confirmed_date)
                                <p><small class="empty-inline">Confirmed for {{ $order->delivery->confirmed_date->format('d M Y') }}</small></p>
                            @endif
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
                        <h4>Payment</h4>
                        <p>
                            <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span><br>
                            {{ $order->paymentOptionLabel() }}<br>
                            <small class="empty-inline">Paid: ₦{{ number_format($order->paidAmount(), 0) }}
                            · Total: ₦{{ number_format((float) $order->total, 0) }}</small>
                        </p>
                    </div>
                    <div>
                        <h4>Delivery Fee</h4>
                        <p>
                            {{ $order->feeStatusLabel() }}
                            @if ($order->feeConfirmed() && (float) $order->delivery_fee > 0)
                                <br><small class="empty-inline">₦{{ number_format((float) $order->delivery_fee, 0) }}</small>
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Payment call-to-action --}}
                @if ($order->status !== 'cancelled' && ! $order->isPaid())
                    <div class="pay-state
                        @if ($order->delivery_fee_status === 'pending_confirmation') pay-state-wait
                        @else pay-state-info @endif">
                        @if ($order->delivery_fee_status === 'pending_confirmation')
                            <h4>Delivery fee being confirmed</h4>
                            <p>Our team will notify you (bell + email) as soon as the delivery fee is confirmed — then you can pay the final total.</p>
                        @else
                            <h4>Payment needed</h4>
                            <p>
                                Amount to pay: <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                                — pay securely online with Paystack to confirm your order.
                            </p>
                        @endif
                        <a href="{{ route('orders.payment', $order) }}" class="btn btn-primary">💳 Pay Now</a>
                    </div>
                @elseif ($order->payment_status === 'paid')
                    <div class="pay-state pay-state-success">
                        <h4>✓ Fully Paid</h4>
                        <p>Payment of <strong>₦{{ number_format((float) $order->total, 0) }}</strong> confirmed. Thank you!</p>
                    </div>
                @endif

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
                                    <td>
                                        {{ $item->product_name }}
                                        @if ($item->unit)
                                            <small>/ {{ $item->unit }}</small>
                                        @endif
                                    </td>
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
                            <tr>
                                <td colspan="3">Paid</td>
                                <td>₦{{ number_format($order->paidAmount(), 0) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Payment attempts --}}
                @if ($order->payments->count() > 0)
                    <div class="table-wrap" style="margin-top:16px;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->payments->sortByDesc('id') as $payment)
                                    <tr>
                                        <td><small>{{ $payment->reference }}</small></td>
                                        <td>{{ $payment->methodLabel() }}</td>
                                        <td>₦{{ number_format((float) $payment->amount, 0) }}</td>
                                        <td>
                                            <span class="badge {{ $payment->statusBadgeClass() }}">
                                                {{ $payment->statusLabel() }}
                                            </span>
                                        </td>
                                        <td>{{ $payment->created_at->format('d M Y, h:i A') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Returns (Phase 10) --}}
                @if ($order->status === 'delivered' || $order->returns->count() > 0)
                    <div class="table-wrap" style="margin-top:16px;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($order->returns as $return)
                                    <tr>
                                        <td>{{ $return->product->name ?? '—' }}</td>
                                        <td>{{ $return->quantity }}</td>
                                        <td>{{ $return->reason }}</td>
                                        <td><span class="badge {{ $return->statusBadgeClass() }}">{{ $return->statusLabel() }}</span></td>
                                        <td>
                                            @if ($return->isRestocked())
                                                <span class="badge badge-green">Returned</span>
                                            @elseif ($return->isRejected())
                                                <span class="empty-inline">—</span>
                                            @else
                                                <span class="badge badge-warn">Awaiting</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="empty-inline">No returns on this order.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($order->status === 'delivered')
                        <div class="success-actions" style="margin-top: 14px;">
                            <a href="{{ route('orders.return.create', $order) }}" class="btn btn-outline-dark">↩️ Request a Return</a>
                        </div>
                    @endif
                @endif

                <div class="success-actions">
                    <a href="{{ route('orders.index') }}" class="btn btn-navy">My Orders</a>
                    <a href="{{ route('products.index') }}" class="btn btn-outline-dark">Continue Shopping</a>
                </div>
            </div>

        </div>
    </section>

@endsection
