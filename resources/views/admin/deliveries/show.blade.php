@extends('layouts.admin')

@section('title', 'Delivery / ' . ($delivery->order?->order_number ?? $delivery->id))

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <a href="{{ route('admin.deliveries.index') }}" class="btn btn-clear">&larr; All Deliveries</a>
            <h1>Delivery — {{ $delivery->order?->order_number ?? '—' }}</h1>
            <p>{{ $delivery->order?->customer_name }} · {{ $delivery->order?->customer_phone }}</p>
        </div>
        @if ($delivery->order)
            <a href="{{ route('admin.orders.show', $delivery->order) }}" class="btn btn-navy">Open Order</a>
        @endif
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-icon">📍</span>
            <div>
                <strong style="font-size: .95rem">{{ $delivery->order?->delivery_address ?? '—' }}</strong>
                <span>Delivery Address</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">📅</span>
            <div>
                <strong>{{ $delivery->order?->preferred_delivery_date?->format('d M Y') ?? '—' }}</strong>
                <span>Requested Date</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">🗓️</span>
            <div>
                <strong>{{ $delivery->confirmed_date?->format('d M Y') ?? 'Not set' }}</strong>
                <span>Confirmed Date</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">💰</span>
            <div>
                <strong>₦{{ number_format((float) ($delivery->order?->total ?? 0), 0) }}</strong>
                <span>Final Total</span>
            </div>
        </div>
        <div class="stat-card">
            <span class="stat-icon">📋</span>
            <div>
                <strong>{{ $delivery->order?->statusLabel() ?? '—' }}</strong>
                <span>Order Status</span>
            </div>
        </div>
    </div>

    {{-- PHASES 21–24 — the record of what actually happened --}}
    @if ($delivery->status === 'failed' || $delivery->failure_reason)
        <div class="admin-card">
            <h2>Failed Attempt</h2>
            <p>
                <strong>Reason:</strong> {{ $delivery->failureReasonLabel() ?? '—' }}
                @if ($delivery->failure_note)
                    <br><strong>Note:</strong> {{ $delivery->failure_note }}
                @endif
                @if ($delivery->rescheduled_date)
                    <br><strong>Rescheduled to:</strong> {{ $delivery->rescheduled_date->format('d M Y') }}
                @endif
            </p>
        </div>
    @endif

    @if ($delivery->delivered_at)
        <div class="admin-card">
            <h2>Delivery Confirmation</h2>
            <p>
                <strong>Delivered:</strong> {{ $delivery->delivered_at->format('d M Y, H:i') }}
                <br><strong>Received by:</strong> {{ $delivery->received_by ?: '— not recorded —' }}
                @if ($delivery->confirmation_note)
                    <br><strong>Note:</strong> {{ $delivery->confirmation_note }}
                @endif
            </p>
        </div>
    @endif

    <div class="admin-card">
        <div class="admin-page-head">
            <div>
                <h1>Manage Delivery</h1>
                <p>Promise a date and say who is taking it.</p>
            </div>
            <span class="badge {{ $delivery->statusBadgeClass() }}">{{ $delivery->statusLabel() }}</span>
        </div>

        <form action="{{ route('admin.deliveries.update', $delivery) }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="form-row-2">
                <div class="form-group">
                    <label for="status">Delivery Status <span class="req">*</span></label>
                    <select name="status" id="status" required>
                        @foreach (\App\Models\Delivery::STATUSES as $key => $label)
                            <option value="{{ $key }}" {{ old('status', $delivery->status) === $key ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    <small class="field-help">
                        "Failed" asks for a reason, "Delivered" asks who received it.
                    </small>
                </div>

                <div class="form-group">
                    <label for="confirmed_date">Confirmed Delivery Date</label>
                    <input type="date" name="confirmed_date" id="confirmed_date"
                           value="{{ old('confirmed_date', $delivery->confirmed_date?->format('Y-m-d')) }}">
                    <small class="field-help">The date you actually committed to (the customer is notified).</small>
                </div>
            </div>

            {{-- PHASE 21/22 — only when the attempt failed --}}
            <div id="failure-fields" class="{{ old('status', $delivery->status) === 'failed' ? '' : 'is-hidden' }}">
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="failure_reason">Failure Reason <span class="req">*</span></label>
                        <select name="failure_reason" id="failure_reason">
                            <option value="">Why did it fail?</option>
                            @foreach (\App\Models\Delivery::FAILURE_REASONS as $key => $label)
                                <option value="{{ $key }}" {{ old('failure_reason', $delivery->failure_reason) === $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('failure_reason')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label for="rescheduled_date">Rescheduled Date <span class="optional">(optional)</span></label>
                        <input type="date" name="rescheduled_date" id="rescheduled_date"
                               min="{{ now()->toDateString() }}"
                               value="{{ old('rescheduled_date', $delivery->rescheduled_date?->format('Y-m-d')) }}">
                        @error('rescheduled_date')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">The next date you agreed with the customer, if any.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="failure_note">What happened? <span class="optional">(note)</span></label>
                    <textarea name="failure_note" id="failure_note" rows="2" maxlength="500"
                              placeholder="e.g. gate locked, nobody home, truck broke down...">{{ old('failure_note', $delivery->failure_note) }}</textarea>
                    @error('failure_note')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Required when the reason is "Other". The customer is told the reason and the new date.</small>
                </div>
            </div>

            {{-- PHASE 24 — only when it was delivered --}}
            <div id="delivered-fields" class="{{ old('status', $delivery->status) === 'delivered' ? '' : 'is-hidden' }}">
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="received_by">Received By <span class="req">*</span></label>
                        <input type="text" name="received_by" id="received_by" maxlength="100"
                               placeholder="Name of the person who took it"
                               value="{{ old('received_by', $delivery->received_by) }}">
                        @error('received_by')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label for="confirmation_note">Confirmation Note <span class="optional">(optional)</span></label>
                        <input type="text" name="confirmation_note" id="confirmation_note" maxlength="500"
                               placeholder="e.g. left with gateman, signature on delivery sheet"
                               value="{{ old('confirmation_note', $delivery->confirmation_note) }}">
                        @error('confirmation_note')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <small class="field-help" id="deliveredStamp">
                    Stamped {{ $delivery->delivered_at?->format('d M Y, H:i') ?: 'the moment you save' }}.
                </small>
            </div>

            <div class="form-group">
                <label for="assigned_to">Taking This Delivery</label>
                <select name="assigned_to" id="assigned_to">
                    <option value="">— Nobody assigned yet —</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" {{ (int) old('assigned_to', $delivery->assigned_to) === $person->id ? 'selected' : '' }}>
                            {{ $person->name }} ({{ $person->roleLabel() }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="notes">Delivery Notes</label>
                <textarea name="notes" id="notes" rows="3"
                          maxlength="1000" placeholder="Landmark, gate code, what happened on a failed attempt...">{{ old('notes', $delivery->notes) }}</textarea>
            </div>

            @if ($errors->any())
                <p class="field-error">{{ $errors->first() }}</p>
            @endif

            <div class="filter-row">
                <button type="submit" class="btn btn-navy"
                        onclick="return confirm('Save delivery for {{ $delivery->order?->order_number }}?');">
                    Save Delivery
                </button>
                <a href="{{ route('admin.deliveries.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>

        @if ($delivery->order)
            <p class="empty-inline" style="margin-top: 14px">
                Order <strong>{{ $delivery->order->order_number }}</strong> is
                "<strong>{{ $delivery->order->statusLabel() }}</strong>".
                Moving the order itself (confirmed → out for delivery → delivered) is done from the
                <a href="{{ route('admin.orders.show', $delivery->order) }}">order page</a>.
            </p>
        @endif
    </div>

    @if ($delivery->order?->items?->isNotEmpty())
        <div class="admin-card">
            <div class="admin-page-head">
                <div>
                    <h1>What Is On Board</h1>
                    <p>Prices are the ones captured when the order was placed.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Unit</th>
                            <th>Price</th>
                            <th>Qty</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($delivery->order->items as $item)
                            <tr>
                                <td>{{ $item->product_name }}</td>
                                <td>{{ $item->unit ?: '—' }}</td>
                                <td>₦{{ number_format((float) $item->unit_price, 0) }}</td>
                                <td>{{ $item->quantity }}</td>
                                <td><strong>₦{{ number_format((float) $item->line_total, 0) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        var status = document.getElementById('status');
        var failure = document.getElementById('failure-fields');
        var delivered = document.getElementById('delivered-fields');
        if (!status || !failure || !delivered) return;

        function sync() {
            failure.classList.toggle('is-hidden', status.value !== 'failed');
            delivered.classList.toggle('is-hidden', status.value !== 'delivered');
        }

        status.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
