@extends('layouts.admin')

@section('title', 'Order ' . $order->order_number)

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <a href="{{ route('admin.orders.index') }}" class="empty-inline">&larr; Back to orders</a>
            <h1>{{ $order->order_number }}</h1>
            <p>
                Placed {{ $order->created_at->format('d M Y, h:i A') }}
                &nbsp;·&nbsp;
                <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                &nbsp;·&nbsp;
                <span class="badge {{ $order->paymentStatusBadgeClass() }}">{{ $order->paymentStatusLabel() }}</span>
            </p>
        </div>
    </div>

    <div class="order-detail-grid">
        {{-- Left: customer + delivery --}}
        <div>
            <div class="admin-card">
                <h2>Customer</h2>
                <p class="order-block">
                    <strong>{{ $order->customer_name }}</strong><br>
                    {{ $order->customer_email }}<br>
                    {{ $order->customer_phone }}
                    @if ($order->user)
                        <br><small class="empty-inline">Account: {{ $order->user->email }}</small>
                        @if (! $order->user->email_verified_at)
                            <br><span class="badge badge-amber">Email not verified</span>
                        @endif
                    @else
                        <br><small class="empty-inline">Guest checkout (no account)</small>
                    @endif
                </p>

                <h2>{{ $order->isDelivery() ? 'Delivery' : 'Pickup' }}</h2>
                <p class="order-block order-address">
                    {{ $order->delivery_address }}
                    @if ($order->isDelivery() && $order->preferred_delivery_date)
                        <br><small class="empty-inline">
                            Preferred date: {{ $order->preferred_delivery_date->format('d M Y') }}
                        </small>
                    @endif
                </p>

                @if ($order->notes)
                    <h2>Customer Notes</h2>
                    <p class="order-block">{{ $order->notes }}</p>
                @endif
            </div>

            {{-- PHASE 8/18 — Delivery fee confirmation (delivery orders only) --}}
            <div class="admin-card">
                <h2>Delivery Fee</h2>

                @if (! $order->isDelivery())
                    <p class="empty-inline">Pickup order — no delivery fee applies.</p>
                @else
                    @if ($order->deliveryLocationLabel())
                        <p class="order-block">
                            <strong>Delivering to:</strong> {{ $order->deliveryLocationLabel() }}
                            <br><small class="empty-inline">Source: {{ $order->deliveryFeeSourceLabel() }}
                            @if ($order->delivery_fee_note)
                                · "{{ $order->delivery_fee_note }}"
                            @endif
                            </small>
                        </p>
                    @endif

                    @if ($order->feeConfirmed())
                        <p class="order-block">
                            <span class="badge badge-green">Confirmed</span>
                            <strong>₦{{ number_format((float) $order->delivery_fee, 0) }}</strong>
                            <br><small class="empty-inline">
                                Confirmed by {{ $order->confirmedBy?->name ?? '—' }}
                                on {{ $order->confirmed_at?->format('d M Y, h:i A') ?? '—' }}
                            </small>
                        </p>

                        {{-- PHASE 18 — deliberate override, reason + audit required --}}
                        @if ($order->paidAmount() <= 0)
                            <div class="pay-state pay-state-wait">
                                <p>
                                    <strong>Override the fee?</strong> Changing a confirmed fee needs a
                                    written reason and is recorded in the activity log. The customer is
                                    notified with the new total.
                                </p>
                            </div>
                            <form action="{{ route('admin.orders.delivery-fee', $order) }}" method="POST" class="status-form">
                                @csrf
                                <div class="filter-row">
                                    <input type="number" name="delivery_fee" class="filter-input"
                                           min="0" step="1" required
                                           value="{{ old('delivery_fee', $order->delivery_fee) }}"
                                           placeholder="New delivery fee (₦)">
                                    <button type="submit" class="btn btn-navy"
                                            onclick="return confirm('Override the delivery fee on {{ $order->order_number }}? The customer will be notified.');">
                                        Override Fee
                                    </button>
                                </div>
                                <div class="form-group">
                                    <textarea name="override_reason" rows="2" maxlength="500" required
                                              placeholder="Reason for the override (required) — e.g. agreed special rate, distance change..."
                                              class="{{ $errors->has('override_reason') ? 'is-invalid' : '' }}">{{ old('override_reason') }}</textarea>
                                    @error('override_reason')<span class="field-error">{{ $message }}</span>@enderror
                                    @error('delivery_fee')<span class="field-error">{{ $message }}</span>@enderror
                                </div>
                            </form>
                        @endif
                    @elseif ($order->paidAmount() > 0)
                        <p class="empty-inline">
                            Money already received — the fee can no longer be changed.
                        </p>
                    @else
                        <div class="pay-state pay-state-wait">
                            <p>
                                <strong>To be confirmed.</strong>
                                Priced automatically from the delivery zone at checkout for covered
                                locations — confirm by hand only if this order was placed before
                                zones existed or the location was agreed by phone.
                            </p>
                        </div>
                        <form action="{{ route('admin.orders.delivery-fee', $order) }}" method="POST" class="status-form">
                            @csrf
                            <div class="filter-row">
                                <input type="number" name="delivery_fee" class="filter-input"
                                       min="0" step="1" required
                                       value="{{ old('delivery_fee', '') }}"
                                       placeholder="Delivery fee (₦)">
                                <button type="submit" class="btn btn-navy"
                                        onclick="return confirm('Confirm delivery fee for {{ $order->order_number }}? The customer will be notified.');">
                                    Confirm Fee
                                </button>
                            </div>
                            @error('delivery_fee')<span class="field-error">{{ $message }}</span>@enderror
                            <p class="empty-inline status-hint">
                                New total = subtotal ₦{{ number_format((float) $order->subtotal, 0) }} + fee.
                            </p>
                        </form>
                    @endif
                @endif
            </div>

            {{-- Status update --}}
            <div class="admin-card">
                <h2>Update Status</h2>
                @if ($order->isTerminal())
                    <p class="empty-inline">
                        This order is <strong>{{ $order->statusLabel() }}</strong> — no further changes.
                    </p>
                @else
                    <form action="{{ route('admin.orders.status', $order) }}" method="POST" class="status-form">
                        @csrf
                        @method('PATCH')
                        <div class="filter-row">
                            <select name="status" class="filter-select" required>
                                @foreach ($order->allowedNextStatuses() as $next)
                                    <option value="{{ $next }}">
                                        {{ \App\Models\Order::STATUSES[$next] }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-navy"
                                    onclick="return confirm('Update order {{ $order->order_number }} status?');">
                                Update Status
                            </button>
                        </div>
                    </form>
                    <p class="empty-inline status-hint">
                        Allowed from <strong>{{ $order->statusLabel() }}</strong>:
                        @foreach ($order->allowedNextStatuses() as $i => $next)
                            {{ \App\Models\Order::STATUSES[$next] }}{{ $i < count($order->allowedNextStatuses()) - 1 ? ',' : '' }}
                        @endforeach
                    </p>
                    @if ($order->status === 'delivered')
                        <p class="empty-inline status-hint">
                            <strong>Closing out</strong> ends the order for good. It stays available to
                            view but can no longer change status.
                        </p>
                    @endif
                @endif
            </div>

            {{-- Status history --}}
            <div class="admin-card">
                <h2>Status History</h2>
                @if ($order->statusHistory->isEmpty())
                    <p class="empty-inline">No history yet.</p>
                @else
                    <ul class="status-history">
                        @foreach ($order->statusHistory as $entry)
                            <li>
                                <strong>{{ $entry->fromLabel() }}</strong> →
                                <strong>{{ $entry->toLabel() }}</strong>
                                <br><small class="empty-inline">
                                    {{ $entry->created_at->format('d M Y, h:i A') }}
                                    @if ($entry->changedBy)
                                        · by {{ $entry->changedBy->name }}
                                    @endif
                                    @if ($entry->notes)
                                        · {{ $entry->notes }}
                                    @endif
                                </small>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Returns (Phase 10) --}}
            <div class="admin-card">
                <h2>Returns</h2>
                @if ($order->returns->isEmpty())
                    <p class="empty-inline">No returns on this order.</p>
                @else
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Review</th>
                                    <th>Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->returns as $return)
                                    <tr>
                                        <td>
                                            {{ $return->product->name ?? '—' }}
                                            <br><small class="empty-inline">{{ $return->sourceLabel() }}</small>
                                        </td>
                                        <td>{{ $return->quantity }}</td>
                                        <td><span class="badge {{ $return->statusBadgeClass() }}">{{ $return->statusLabel() }}</span></td>
                                        <td>
                                            @if ($return->isRestocked())
                                                <span class="badge badge-green">Restocked</span>
                                            @elseif ($return->isRejected())
                                                <span class="empty-inline">—</span>
                                            @else
                                                <span class="badge badge-warn">Not yet</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="empty-inline" style="margin-top:10px;">
                        Review, inspection and restocking are handled from
                        <a href="{{ route('admin.returns.index') }}">Returns</a>.
                    </p>
                @endif

                @if ($order->hasPendingReturns())
                    <div class="pay-state pay-state-wait" style="margin-top:12px;">
                        <p><strong>⚠ {{ $order->pendingReturns()->count() }} return request(s) awaiting review</strong>
                        — the order cannot be closed until these are approved or rejected.</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Right: items + money --}}
        <div>
            <div class="admin-card">
                <h2>Items</h2>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Product</th>
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
                                            <small class="empty-inline">/ {{ $item->unit }}</small>
                                        @endif
                                    </td>
                                    <td>₦{{ number_format((float) $item->unit_price, 0) }}</td>
                                    <td>{{ $item->quantity }}</td>
                                    <td><strong>₦{{ number_format((float) $item->line_total, 0) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Subtotal</td>
                                <td>₦{{ number_format((float) $order->subtotal, 0) }}</td>
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
            </div>

            {{-- PHASE 8 — Payments + record money by hand --}}
            <div class="admin-card">
                <h2>Payments</h2>

                @if ($order->payments->isEmpty())
                    <p class="empty-inline">No payment records yet for this order.</p>
                @else
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->payments->sortByDesc('id') as $payment)
                                    <tr>
                                        <td><small>{{ $payment->reference }}</small>
                                            @if ($payment->channel)
                                                <br><small class="empty-inline">{{ $payment->channel }}</small>
                                            @endif
                                        </td>
                                        <td>{{ $payment->methodLabel() }}</td>
                                        <td>₦{{ number_format((float) $payment->amount, 0) }}</td>
                                        <td>
                                            <span class="badge {{ $payment->statusBadgeClass() }}">
                                                {{ $payment->statusLabel() }}
                                            </span>
                                        </td>
                                        <td>
                                            {{ $payment->paid_at?->format('d M Y, h:i A') ?? $payment->created_at->format('d M Y, h:i A') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($order->payment_status === 'paid')
                    <div class="pay-state pay-state-success" style="margin-top:12px;">
                        <p><strong>✓ This order is fully paid.</strong></p>
                    </div>
                @elseif ($order->status !== 'cancelled' && $order->payment_status !== 'failed')
                    <div class="pay-state pay-state-wait" style="margin-top:12px;">
                        <p>Payment for this order is made by the customer online through Paystack —
                        there is nothing to record by hand. Use <strong>Verify with Paystack</strong>
                        on the payment row if a payment looks stuck.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
