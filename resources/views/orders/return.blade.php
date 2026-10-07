@extends('layouts.app')

@section('title', 'Request a Return — Order ' . $order->order_number)

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Request a Return</h1>
            <p>Order {{ $order->order_number }} · Delivered {{ $order->created_at->format('d M Y') }}</p>
        </div>
    </section>

    <section class="section">
        <div class="container auth-wrap">
            <div class="auth-card auth-card-wide">

                <p style="margin-bottom: 18px;">
                    Tell us what is coming back and why. Our team reviews every request —
                    nothing is charged or restocked until it is approved.
                </p>

                <form action="{{ route('orders.return.store', $order) }}" method="POST" novalidate id="returnForm">
                    @csrf

                    <div class="form-group">
                        <label for="product_id">Item to return <span class="req">*</span></label>
                        <select id="product_id" name="product_id" required
                                class="{{ $errors->has('product_id') ? 'is-invalid' : '' }}">
                            <option value="">Select an item...</option>
                            @foreach ($lines as $line)
                                <option value="{{ $line['product_id'] }}"
                                        data-remaining="{{ $line['remaining'] }}"
                                        data-name="{{ $line['name'] }}"
                                        data-price="{{ $line['unit_price'] }}"
                                        {{ (string) old('product_id') === (string) $line['product_id'] ? 'selected' : '' }}>
                                    {{ $line['name'] }}
                                    — {{ $line['returned'] }} of {{ $line['ordered'] }} already requested
                                    · {{ $line['remaining'] }} returnable
                                </option>
                            @endforeach
                        </select>
                        @error('product_id')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help" id="lineHint">
                            Ordered quantities already requested for a return are hidden.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity <span class="req">*</span></label>
                        <input type="number" id="quantity" name="quantity" required min="1" step="1"
                               value="{{ old('quantity') }}"
                               class="{{ $errors->has('quantity') ? 'is-invalid' : '' }}">
                        @error('quantity')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help" id="qtyHint"></small>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason <span class="req">*</span></label>
                        <textarea id="reason" name="reason" rows="4" maxlength="500" required
                                  placeholder="e.g. Two bags arrived torn, wrong size delivered..."
                                  class="{{ $errors->has('reason') ? 'is-invalid' : '' }}">{{ old('reason') }}</textarea>
                        @error('reason')<span class="field-error">{{ $message }}</span>@enderror
                        <small class="field-help">A reason is required — at least 3 characters.</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Send Return Request</button>
                </form>

                <div style="margin-top: 16px; text-align: center;">
                    <a href="{{ route('orders.show', $order) }}" class="btn btn-clear">Back to Order</a>
                </div>

            </div>
        </div>
    </section>

@endsection

@push('scripts')
<script>
    (function () {
        var select = document.getElementById('product_id');
        var qty = document.getElementById('quantity');
        var hint = document.getElementById('qtyHint');
        var lineHint = document.getElementById('lineHint');

        function sync() {
            var opt = select.options[select.selectedIndex];
            var remaining = opt ? parseInt(opt.getAttribute('data-remaining') || '0', 10) : 0;
            var price = opt ? parseFloat(opt.getAttribute('data-price') || '0') : 0;

            if (!opt || !opt.value) {
                qty.removeAttribute('max');
                hint.textContent = '';
                lineHint.textContent = 'Ordered quantities already requested for a return are hidden.';
                return;
            }

            qty.max = remaining;
            if (parseInt(qty.value || '0', 10) > remaining) {
                qty.value = remaining;
            }

            hint.textContent = 'Up to ' + remaining + ' available to return. '
                + 'Value at the price you paid: ₦' + (remaining * price).toLocaleString('en-NG', { maximumFractionDigits: 0 }) + '.';
            lineHint.textContent = opt.getAttribute('data-name') + ' — ' + opt.getAttribute('data-remaining') + ' still returnable.';
        }

        select.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
