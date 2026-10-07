@extends('layouts.admin')

@section('title', 'Payments')

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>Payments</h1>
            <p>Track payment records against customer orders.</p>
        </div>
    </div>

    <div class="admin-card">
        <form action="{{ route('admin.payments.index') }}" method="GET" class="filter-row">
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Search reference, order # or customer..."
                   class="filter-input">
            <select name="status" class="filter-select">
                <option value="">All statuses</option>
                @foreach (\App\Models\Payment::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>
                        {{ $label }} ({{ $statusCounts[$key] ?? 0 }})
                    </option>
                @endforeach
            </select>
            <select name="method" class="filter-select">
                <option value="">All methods</option>
                @foreach (\App\Models\Payment::METHODS as $key => $label)
                    <option value="{{ $key }}" {{ $methodFilter === $key ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-navy">Filter</button>
            @if ($search || $statusFilter || $methodFilter)
                <a href="{{ route('admin.payments.index') }}" class="btn btn-clear">Clear</a>
            @endif
        </form>

        @if ($payments->isEmpty())
            <div class="empty-state">
                <h3>No payments found</h3>
                <p>Payments appear here after customers check out.</p>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-navy">View Orders</a>
            </div>
        @else
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Order Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>
                                    <strong>{{ $payment->reference }}</strong>
                                    @if ($payment->transaction_id)
                                        <br><small class="empty-inline">Paystack tx #{{ $payment->transaction_id }}
                                            @if ($payment->channel) · {{ $payment->channel }} @endif
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if ($payment->order)
                                        <a href="{{ route('admin.orders.show', $payment->order) }}">
                                            {{ $payment->order->order_number }}
                                        </a>
                                        <br><small class="empty-inline">{{ $payment->order->paymentStatusLabel() }}</small>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $payment->order->customer_name ?? '—' }}</td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td><strong>₦{{ number_format((float) $payment->amount, 0) }}</strong></td>
                                <td>
                                    @if ($payment->order)
                                        <strong>₦{{ number_format((float) $payment->order->total, 0) }}</strong>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $payment->statusBadgeClass() }}">
                                        {{ $payment->statusLabel() }}
                                    </span>
                                </td>
                                <td>{{ $payment->created_at->format('d M Y') }}</td>
                                <td class="actions-cell">
                                    @if ($payment->order)
                                        <a href="{{ route('admin.orders.show', $payment->order) }}"
                                           class="btn btn-sm btn-navy">Order</a>
                                    @endif
                                    @if (! $payment->isTerminal())
                                        {{-- A Paystack payment never becomes "paid" by hand —
                                             it must be verified against Paystack first. --}}
                                        @if ($payment->method === 'paystack' && $payment->status === 'pending')
                                            <form action="{{ route('admin.payments.verify', $payment) }}" method="POST"
                                                  class="inline-delete"
                                                  onsubmit="return confirm('Ask Paystack to verify payment {{ $payment->reference }}?');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-primary">Verify with Paystack</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('admin.payments.status', $payment) }}" method="POST"
                                              class="inline-delete"
                                              onsubmit="return confirm('Update payment {{ $payment->reference }}?');">
                                            @csrf
                                            @method('PATCH')
                                            <select name="status" class="filter-select" required style="display:inline-block;width:auto;">
                                                @foreach ($payment->allowedNextStatuses() as $next)
                                                    @if ($next === 'paid' && $payment->method === 'paystack')
                                                        @continue
                                                    @endif
                                                    <option value="{{ $next }}">{{ \App\Models\Payment::STATUSES[$next] }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                @if ($payments->onFirstPage())
                    <span class="page-btn disabled">&larr; Prev</span>
                @else
                    <a class="page-btn" href="{{ $payments->previousPageUrl() }}">&larr; Prev</a>
                @endif
                <span class="page-info">Page {{ $payments->currentPage() }} of {{ $payments->lastPage() }}</span>
                @if ($payments->hasMorePages())
                    <a class="page-btn" href="{{ $payments->nextPageUrl() }}">Next &rarr;</a>
                @else
                    <span class="page-btn disabled">Next &rarr;</span>
                @endif
            </div>
        @endif
    </div>
@endsection
