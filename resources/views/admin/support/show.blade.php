@extends('layouts.admin')

@section('title', 'Customer Care — ' . $ticket->reference)

@section('content')
    <div class="admin-page-head row-between">
        <div>
            <h1>{{ $ticket->subject }}</h1>
            <p>
                {{ $ticket->reference }} · {{ $ticket->categoryLabel() }} ·
                Filed {{ $ticket->created_at->format('d M Y, H:i') }}
            </p>
        </div>
        <a href="{{ route('admin.support.index') }}" class="btn btn-clear">← All Requests</a>
    </div>

    <div class="admin-card" style="margin-bottom: 18px;">
        <div class="row-between" style="flex-wrap: wrap; gap: 12px;">
            <div>
                <span class="badge {{ $ticket->badgeClass() }}">{{ $ticket->statusLabel() }}</span>
                @if ($ticket->responded_at)
                    <span class="badge badge-navy">Replied {{ $ticket->responded_at->format('d M Y, H:i') }}</span>
                @endif
                @if ($ticket->resolved_at)
                    <span class="badge badge-green">Resolved {{ $ticket->resolved_at->format('d M Y, H:i') }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="admin-card" style="margin-bottom: 18px;">
        <h3>From the customer</h3>
        <p>
            <strong>{{ $ticket->customer_name }}</strong>
            · <a href="mailto:{{ $ticket->customer_email }}">{{ $ticket->customer_email }}</a>
            @if ($ticket->customer_phone)
                · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $ticket->customer_phone) }}">{{ $ticket->customer_phone }}</a>
            @endif
            @if ($ticket->customer)
                <br><small class="empty-inline">
                    Customer account ·
                    <a href="{{ route('admin.customers.show', $ticket->customer) }}">view profile</a>
                </small>
            @endif
        </p>

        @if ($ticket->order)
            <p>
                📋 Linked order:
                <a href="{{ route('admin.orders.show', $ticket->order) }}">{{ $ticket->order->order_number }}</a>
                · ₦{{ number_format((float) $ticket->order->total, 0) }}
            </p>
        @endif
        @if ($ticket->product)
            <p>📦 Relevant product: {{ $ticket->product->name }}</p>
        @endif

        <p style="white-space: pre-wrap; margin-top: 10px;">{{ $ticket->description }}</p>
    </div>

    <div class="admin-card" style="margin-bottom: 18px;">
        <h3>Response so far</h3>
        @if ($ticket->staff_response)
            <p style="white-space: pre-wrap;">{{ $ticket->staff_response }}</p>
            <small class="empty-inline">
                {{ $ticket->responder?->name ?? 'Staff' }} · {{ $ticket->responded_at?->format('d M Y, H:i') }}
            </small>
        @else
            <p class="empty-inline">No response has been sent yet.</p>
        @endif
    </div>

    <div class="admin-card">
        <h3>Respond / change status</h3>
        <form action="{{ route('admin.support.update', $ticket) }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="form-group">
                <label for="staff_response">Reply to the customer</label>
                <textarea id="staff_response" name="staff_response" rows="5" maxlength="5000"
                          placeholder="What did we do or agree with the customer?"
                          class="{{ $errors->has('staff_response') ? 'is-invalid' : '' }}">{{ old('staff_response') }}</textarea>
                @error('staff_response')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">
                    Sent to the customer by email and in-app when you save — the status above changes only
                    because you say so here, never automatically.
                </small>
            </div>

            <div class="form-group">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status" required
                        class="{{ $errors->has('status') ? 'is-invalid' : '' }}">
                    @foreach (\App\Models\SupportTicket::STATUSES as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $ticket->status) === $key ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('status')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
@endsection
