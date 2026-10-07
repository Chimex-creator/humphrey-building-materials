@extends('layouts.app')

@section('title', 'Pay for Order ' . $order->order_number)

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Payment</h1>
            <p>
                <a href="{{ route('orders.index') }}" class="breadcrumb-link">My Orders</a>
                &nbsp;/&nbsp;
                {{ $order->order_number }}
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            <div class="pay-page-head">
                <div>
                    <h2>{{ $order->order_number }}</h2>
                    <p class="empty-inline">
                        Placed {{ $order->created_at->format('d M Y, h:i A') }}
                    </p>
                </div>
                <div class="pay-badges">
                    <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                    <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
                </div>
            </div>

            <div class="pay-layout">

                {{-- Left: payment actions --}}
                <div class="admin-card">
                    <h2>Complete Your Payment</h2>

                    {{-- State 1: order cancelled --}}
                    @if ($order->status === 'cancelled')
                        <div class="pay-state pay-state-error">
                            <h4>Order cancelled</h4>
                            <p>This order was cancelled, so there is nothing to pay.</p>
                            <a href="{{ route('orders.index') }}" class="btn btn-navy">Back to My Orders</a>
                        </div>

                    {{-- State 2: fully paid --}}
                    @elseif ($order->isPaid())
                        <div class="pay-state pay-state-success">
                            <h4>✓ Fully Paid</h4>
                            <p>
                                We have received
                                <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                                for this order. Thank you!
                            </p>
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-navy">View Order</a>
                        </div>

                    {{-- State 3: waiting for staff to confirm the delivery fee --}}
                    @elseif ($order->delivery_fee_status === 'pending_confirmation')
                        <div class="pay-state pay-state-wait">
                            <h4>Delivery fee being confirmed</h4>
                            <p>
                                Our team is confirming the delivery fee for
                                <strong>{{ $order->delivery_address }}</strong>.
                                You will get a notification (bell + email) with the final total,
                                and you can pay straight after that.
                            </p>
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-dark">View Order</a>
                        </div>

                    {{-- State 4: payable --}}
                    @else
                        <div class="pay-figures">
                            <div class="pay-figure">
                                <span>Product total</span>
                                <strong>₦{{ number_format((float) $order->subtotal, 0) }}</strong>
                            </div>
                            <div class="pay-figure">
                                <span>Delivery fee</span>
                                <strong>
                                    @if (! $order->isDelivery())
                                        —
                                    @else
                                        ₦{{ number_format((float) $order->delivery_fee, 0) }}
                                    @endif
                                </strong>
                            </div>
                            <div class="pay-figure pay-figure-due">
                                <span>Amount to pay</span>
                                <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                            </div>
                        </div>

                        {{-- Pay the order total online through Paystack --}}
                        <p class="pay-choice">
                            Payment method: <strong>{{ $order->paymentOptionLabel() }}</strong>
                            <br><small class="empty-inline">
                                You will be taken to Paystack's secure checkout to pay
                                <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                                (card, bank transfer or USSD). Your order is confirmed automatically
                                once Paystack confirms the payment.
                            </small>
                        </p>

                        <form action="{{ route('orders.payment.paystack', $order) }}" method="POST" class="pay-action">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-block">
                                💳 Pay ₦{{ number_format((float) $order->total, 0) }} Online (Paystack)
                            </button>
                            <small class="field-help">
                                The full amount — product total
                                @if ($order->isDelivery()) + delivery fee @endif
                                — is paid in one go on Paystack's secure checkout
                                (card, bank transfer or USSD). Test mode — no real money leaves your account.
                            </small>
                        </form>

                        {{-- History of attempts --}}
                        @if ($order->payments->count() > 0)
                            <div class="table-wrap" style="margin-top:18px;">
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
                    @endif
                </div>

                {{-- Right: order summary --}}
                <div class="cart-summary checkout-summary">
                    <h2>Order Summary</h2>

                    <ul class="checkout-items">
                        @foreach ($order->items as $item)
                            <li>
                                <span class="ci-qty">{{ $item->quantity }}×</span>
                                <span class="ci-name">{{ $item->product_name }}</span>
                                <span class="ci-price">₦{{ number_format((float) $item->line_total, 0) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <ul class="summary-rows">
                        <li>
                            <span>Subtotal</span>
                            <strong>₦{{ number_format((float) $order->subtotal, 0) }}</strong>
                        </li>
                        <li>
                            <span>{{ $order->isDelivery() ? 'Delivery' : 'Pickup' }}</span>
                            <strong>
                                @if (! $order->isDelivery())
                                    Free
                                @elseif ($order->feeConfirmed())
                                    ₦{{ number_format((float) $order->delivery_fee, 0) }}
                                @else
                                    To be confirmed
                                @endif
                            </strong>
                        </li>
                        <li class="summary-total">
                            <span>Total</span>
                            <strong>₦{{ number_format((float) $order->total, 0) }}</strong>
                        </li>
                        <li>
                            <span>Payment status</span>
                            <strong>
                                <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
                            </strong>
                        </li>
                    </ul>

                    <div class="pay-order-info">
                        <p>
                            <strong>{{ $order->isDelivery() ? 'Delivery address' : 'Pickup' }}</strong><br>
                            {{ $order->delivery_address }}
                        </p>
                        @if ($order->isDelivery() && $order->preferred_delivery_date)
                            <p>
                                <strong>Preferred date</strong><br>
                                {{ $order->preferred_delivery_date->format('d M Y') }}
                            </p>
                        @endif
                        <p>
                            <strong>Delivery fee status</strong><br>
                            {{ $order->feeStatusLabel() }}
                        </p>
                    </div>

                    <p class="summary-note">
                        Stock for this order is already reserved — paying does not change it.
                    </p>
                </div>

            </div>

        </div>
    </section>

@endsection
