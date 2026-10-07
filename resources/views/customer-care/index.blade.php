@extends('layouts.app')

@section('title', 'Customer Care')

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Customer Care</h1>
            <p>Questions, complaints or problems with an order? Tell us — we track every request to a real answer.</p>
        </div>
    </section>

    {{-- Direct contact options — real links, no placeholders --}}
    <section class="section" style="padding-bottom: 0;">
        <div class="container">
            <div class="auth-card auth-card-wide" style="margin-bottom: 0;">
                <div class="row-between" style="flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h2>Talk to a person</h2>
                        <p style="margin: 6px 0 0;">
                            @if ($contacts->isNotEmpty())
                                @foreach ($contacts as $contact)
                                    📞 <strong>{{ $contact->label }}:</strong>
                                    <a href="{{ $contact->telHref() }}">{{ $contact->phone }}</a><br>
                                @endforeach
                            @else
                                📞 <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a><br>
                            @endif
                            ✉️ <a href="mailto:{{ $email }}">{{ $email }}</a><br>
                            📍 {{ $address }}
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contacts->first()?->phone ?? $phone) }}" class="btn btn-navy">📞 Call Us</a>
                        <a href="mailto:{{ $email }}" class="btn btn-outline-dark">✉️ Email Us</a>
                    </div>
                </div>
                <small class="field-help" style="margin-top: 10px;">Opening hours: Mon – Sat, 8:00 AM – 6:00 PM.</small>
            </div>
        </div>
    </section>

    {{-- Complaint / enquiry form --}}
    <section class="section">
        <div class="container auth-wrap">
            <div class="auth-card auth-card-wide">

                <h2>Report a problem or ask a question</h2>
                <p style="margin-bottom: 18px;">
                    Give us the details once — your request gets a reference number and a
                    member of staff is assigned to it. We will update you here and by email.
                </p>

                <form action="{{ route('support.store') }}" method="POST" novalidate>
                    @csrf

                    <div class="form-group">
                        <label for="category">What is this about? <span class="req">*</span></label>
                        <select id="category" name="category" required
                                class="{{ $errors->has('category') ? 'is-invalid' : '' }}">
                            <option value="">Choose a category...</option>
                            @foreach ($categories as $key => $label)
                                <option value="{{ $key }}" {{ old('category') === $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('category')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject <span class="req">*</span></label>
                        <input type="text" id="subject" name="subject" maxlength="120" required
                               placeholder="e.g. Cement order delivered late"
                               value="{{ old('subject') }}"
                               class="{{ $errors->has('subject') ? 'is-invalid' : '' }}">
                        @error('subject')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label for="description">Description <span class="req">*</span></label>
                        <textarea id="description" name="description" rows="6" maxlength="5000" required
                                  placeholder="Tell us what happened, when it happened, and the outcome you expect..."
                                  class="{{ $errors->has('description') ? 'is-invalid' : '' }}">{{ old('description') }}</textarea>
                        @error('description')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">At least 10 characters — the more detail, the faster we can fix it.</small>
                    </div>

                    <div class="form-group">
                        <label for="order_reference">Order number (optional)</label>
                        <input type="text" id="order_reference" name="order_reference" maxlength="30"
                               placeholder="e.g. HBM-2026-000123"
                               value="{{ old('order_reference') }}"
                               class="{{ $errors->has('order_reference') ? 'is-invalid' : '' }}">
                        @error('order_reference')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">Must be one of your own orders. Leave blank for general enquiries.</small>
                    </div>

                    <div class="form-group">
                        <label for="product_id">Relevant product (optional)</label>
                        <select id="product_id" name="product_id"
                                class="{{ $errors->has('product_id') ? 'is-invalid' : '' }}">
                            <option value="">No particular product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" {{ (string) old('product_id') === (string) $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('product_id')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label for="customer_phone">Phone number (optional)</label>
                        <input type="tel" id="customer_phone" name="customer_phone" maxlength="30"
                               value="{{ old('customer_phone', $tickets->first()?->customer_phone ?? auth()->user()->phone) }}"
                               class="{{ $errors->has('customer_phone') ? 'is-invalid' : '' }}">
                        @error('customer_phone')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">So we can call you back quickly.</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Send Message</button>
                </form>

            </div>
        </div>
    </section>

    {{-- The customer's own records --}}
    <section class="section" style="padding-top: 0;">
        <div class="container">
            <div class="section-head">
                <h2>My Requests</h2>
                <p>Everything you have reported, and where it stands right now.</p>
            </div>

            <div class="admin-card">
                @if ($tickets->isEmpty())
                    <div class="empty-state">
                        <h3>No requests yet</h3>
                        <p>Use the form above whenever something needs our attention.</p>
                    </div>
                @else
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Filed</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    <tr>
                                        <td><strong>{{ $ticket->reference }}</strong></td>
                                        <td>{{ $ticket->subject }}</td>
                                        <td>{{ $ticket->categoryLabel() }}</td>
                                        <td>{{ $ticket->created_at->format('d M Y') }}</td>
                                        <td><span class="badge {{ $ticket->badgeClass() }}">{{ $ticket->statusLabel() }}</span></td>
                                        <td>
                                            <a href="{{ route('support.show', $ticket) }}" class="btn btn-sm btn-navy">View</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        @if ($tickets->onFirstPage())
                            <span class="page-btn disabled">&larr; Prev</span>
                        @else
                            <a class="page-btn" href="{{ $tickets->previousPageUrl() }}">&larr; Prev</a>
                        @endif
                        <span class="page-info">Page {{ $tickets->currentPage() }} of {{ $tickets->lastPage() }}</span>
                        @if ($tickets->hasMorePages())
                            <a class="page-btn" href="{{ $tickets->nextPageUrl() }}">Next &rarr;</a>
                        @else
                            <span class="page-btn disabled">Next &rarr;</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>

@endsection
