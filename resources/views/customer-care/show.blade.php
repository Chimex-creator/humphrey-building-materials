@extends('layouts.app')

@section('title', 'Request ' . $ticket->reference)

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>{{ $ticket->subject }}</h1>
            <p>
                Reference {{ $ticket->reference }} · {{ $ticket->categoryLabel() }} ·
                Filed {{ $ticket->created_at->format('d M Y, H:i') }}
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container auth-wrap">
            <div class="auth-card auth-card-wide">

                <div class="row-between" style="flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                    <span class="badge {{ $ticket->badgeClass() }}">{{ $ticket->statusLabel() }}</span>
                    @if ($ticket->resolved_at)
                        <small class="empty-inline">Resolved on {{ $ticket->resolved_at->format('d M Y, H:i') }}</small>
                    @endif
                </div>

                <h2>What you told us</h2>
                <p style="white-space: pre-wrap;">{{ $ticket->description }}</p>

                <div style="margin-top: 14px;">
                    @if ($ticket->order)
                        <p>
                            📋 Related order:
                            <a href="{{ route('orders.show', $ticket->order) }}">{{ $ticket->order->order_number }}</a>
                        </p>
                    @endif
                    @if ($ticket->product)
                        <p>📦 Relevant product: {{ $ticket->product->name }}</p>
                    @endif
                    @if ($ticket->customer_phone)
                        <p>📞 Best number: {{ $ticket->customer_phone }}</p>
                    @endif
                </div>

                <hr style="margin: 18px 0; border: 0; border-top: 1px solid #e5e7eb;">

                <h2>Our response</h2>
                @if ($ticket->staff_response)
                    <p style="white-space: pre-wrap;">{{ $ticket->staff_response }}</p>
                    <small class="field-help">
                        @if ($ticket->responder)
                            By {{ $ticket->responder->name }} ·
                        @endif
                        {{ $ticket->responded_at?->format('d M Y, H:i') }}
                    </small>
                @else
                    <p>
                        @if ($ticket->isOpen())
                            We are still working on this — a member of staff will reply here.
                            You will get an email the moment we do.
                        @else
                            No written reply was recorded for this request.
                        @endif
                    </p>
                @endif

                <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="{{ route('support.index') }}" class="btn btn-navy">My Requests</a>
                    <a href="{{ route('support.index') }}#top" class="btn btn-outline-dark">Report Something Else</a>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', \App\Models\Setting::get('business_phone', '+2348153667923')) }}"
                       class="btn btn-clear">📞 Call Us Instead</a>
                </div>

            </div>
        </div>
    </section>

@endsection
