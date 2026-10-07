@extends('layouts.app')

@section('title', 'Checkout — Humphrey Building Materials')

@section('content')

    <section class="page-banner">
        <div class="container">
            <h1>Checkout</h1>
            <p>
                <a href="{{ route('cart.index') }}" class="breadcrumb-link">Cart</a>
                &nbsp;/&nbsp;
                Checkout
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            @include('partials.checkout-progress', ['currentStep' => 4])

            <div class="checkout-layout">

                {{-- Delivery details form --}}
                <div class="checkout-form-card">
                    <h2>Delivery Details</h2>
                    @guest
                        <p class="form-hint">
                            Checking out as a guest.
                            <a href="{{ route('login') }}">Log in</a> first to prefill your details —
                            and to pay online.
                        </p>
                    @endguest

                    <form action="{{ route('checkout.store') }}" method="POST" novalidate>
                        @csrf

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="name">Full Name <span class="req">*</span></label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="{{ old('name', $form['name']) }}"
                                    required
                                    maxlength="255"
                                    autocomplete="name"
                                    class="{{ $errors->has('name') ? 'is-invalid' : '' }}"
                                >
                                @error('name')<span class="field-error">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group">
                                <label for="email">Email <span class="req">*</span></label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="{{ old('email', $form['email']) }}"
                                    required
                                    maxlength="255"
                                    autocomplete="email"
                                    class="{{ $errors->has('email') ? 'is-invalid' : '' }}"
                                >
                                @error('email')<span class="field-error">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="phone">Phone Number <span class="req">*</span></label>
                                <input
                                    type="text"
                                    id="phone"
                                    name="phone"
                                    value="{{ old('phone', $form['phone']) }}"
                                    required
                                    maxlength="30"
                                    autocomplete="tel"
                                    placeholder="+234 ..."
                                    class="{{ $errors->has('phone') ? 'is-invalid' : '' }}"
                                >
                                @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group">
                                <label for="notes">Order Notes <span class="optional">(optional)</span></label>
                                <input
                                    type="text"
                                    id="notes"
                                    name="notes"
                                    value="{{ old('notes', $form['notes']) }}"
                                    maxlength="1000"
                                    placeholder="e.g. deliver after 2pm, call on arrival"
                                >
                            </div>
                        </div>

                        {{-- PHASE 8 — pickup or delivery --}}
                        <div class="form-group payment-methods">
                            <label>How would you like to receive your order? <span class="req">*</span></label>
                            @error('delivery_option')<span class="field-error">{{ $message }}</span>@enderror
                            @foreach ($deliveryOptions as $key => $label)
                                <label class="payment-option" for="do_{{ $key }}">
                                    <input
                                        type="radio"
                                        id="do_{{ $key }}"
                                        name="delivery_option"
                                        value="{{ $key }}"
                                        {{ $defaultDelivery === $key ? 'checked' : '' }}
                                        required
                                    >
                                    <span class="payment-option-body">
                                        <strong>{{ $label }}</strong>
                                        @if ($key === 'pickup')
                                            <small>No delivery fee — collect from our shop.</small>
                                        @else
                                            <small>The fee for your area is worked out below and added to your total.</small>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        {{-- Shown only when "Deliver" is selected (toggled by JS) --}}
                        <div id="delivery-fields" class="{{ $defaultDelivery === 'delivery' ? '' : 'is-hidden' }}">

                            {{-- PHASE 16/17 — where to + what it costs (priced on the server) --}}
                            <div class="form-row-2">
                                <div class="form-group">
                                    <label for="delivery_state_id">State <span class="req" data-delivery-req>*</span></label>
                                    <select id="delivery_state_id" name="delivery_state_id"
                                            class="{{ $errors->has('delivery_state_id') ? 'is-invalid' : '' }}">
                                        <option value="">Choose your state...</option>
                                        @foreach ($states as $state)
                                            <option value="{{ $state['id'] }}"
                                                    data-fee="{{ $state['fee'] }}"
                                                    {{ (string) old('delivery_state_id') === (string) $state['id'] ? 'selected' : '' }}>
                                                {{ $state['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('delivery_state_id')<span class="field-error">{{ $message }}</span>@enderror
                                </div>

                                <div class="form-group">
                                    <label for="delivery_area_id">Area <span class="optional">(optional)</span></label>
                                    <select id="delivery_area_id" name="delivery_area_id"
                                            class="{{ $errors->has('delivery_area_id') ? 'is-invalid' : '' }}">
                                        <option value="">Choose an area...</option>
                                        @foreach ($states as $state)
                                            @foreach ($state['areas'] as $area)
                                                <option value="{{ $area['id'] }}"
                                                        data-state="{{ $state['id'] }}"
                                                        data-fee="{{ $area['fee'] }}"
                                                        data-zone="{{ $area['zone'] }}"
                                                        {{ (string) old('delivery_area_id') === (string) $area['id'] ? 'selected' : '' }}>
                                                    {{ $area['name'] }}
                                                </option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                    @error('delivery_area_id')<span class="field-error">{{ $message }}</span>@enderror
                                    <span class="field-help">
                                        Required for FCT and Nasarawa deliveries — pick your town/area for the exact rate (e.g. Mararaba or Nyanya).
                                    </span>
                                </div>
                            </div>

                            <div class="form-group" id="feeEstimateGroup">
                                <strong id="feeEstimate">Choose a state to see the delivery fee.</strong>
                            </div>

                            <div class="form-group">
                                <label for="address">Delivery Address <span class="req" data-delivery-req>*</span></label>
                                <textarea
                                    id="address"
                                    name="address"
                                    rows="3"
                                    maxlength="500"
                                    placeholder="Street, area, city, state..."
                                    class="{{ $errors->has('address') ? 'is-invalid' : '' }}"
                                >{{ old('address', $form['address']) }}</textarea>
                                @error('address')<span class="field-error">{{ $message }}</span>@enderror
                                <span class="field-help">Required for delivery — where should we bring these materials?</span>
                            </div>

                            <div class="form-group">
                                <label for="preferred_delivery_date">Preferred Delivery Date <span class="req" data-delivery-req>*</span></label>
                                <input
                                    type="date"
                                    id="preferred_delivery_date"
                                    name="preferred_delivery_date"
                                    value="{{ old('preferred_delivery_date', $form['preferred_delivery_date']) }}"
                                    min="{{ now()->toDateString() }}"
                                    class="{{ $errors->has('preferred_delivery_date') ? 'is-invalid' : '' }}"
                                >
                                @error('preferred_delivery_date')<span class="field-error">{{ $message }}</span>@enderror
                                <span class="field-help">Pick a day that works for you (today or later).</span>
                            </div>
                        </div>

                        {{-- Final Spec §18 — Paystack only --}}
                        <div class="form-group payment-methods">
                            <label>Payment</label>
                            <div class="payment-option" style="cursor:default;">
                                <span class="payment-option-body">
                                    <strong>💳 Pay Online (Paystack)</strong>
                                    <small>
                                        You will be taken straight to Paystack's secure checkout
                                        (card, bank transfer or USSD — test mode). Your order is
                                        confirmed automatically once the payment is verified.
                                    </small>
                                </span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block" id="placeOrderBtn">
                            Place Order &amp; Pay
                        </button>
                        <p class="summary-note" style="margin-top:10px;">
                            Your items are reserved as soon as you place the order —
                            you will pay securely on the next page.
                        </p>
                    </form>
                </div>

                {{-- Order summary --}}
                <aside class="cart-summary checkout-summary">
                    <h2>Your Items</h2>
                    <ul class="checkout-items">
                        @foreach ($items as $line)
                            <li>
                                <span class="ci-qty">{{ $line->qty }}×</span>
                                <span class="ci-name">{{ $line->product->name }}</span>
                                <span class="ci-price">₦{{ number_format($line->line_total, 0) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <ul class="summary-rows">
                        <li>
                            <span>Subtotal</span>
                            <strong>₦{{ number_format($subtotal, 0) }}</strong>
                        </li>
                        <li>
                            <span>Delivery</span>
                            <strong id="summaryDelivery">Free (pickup)</strong>
                        </li>
                        <li class="summary-total">
                            <span>Total</span>
                            <strong id="summaryTotal">₦{{ number_format($subtotal, 0) }}</strong>
                        </li>
                    </ul>

                    <p class="summary-note" id="summaryNote">
                        Pickup orders have no delivery fee — you pay the subtotal above.
                    </p>
                </aside>

            </div>

        </div>
    </section>

@endsection

@push('scripts')
<script>
    (function () {
        var DELIVERY = 'delivery';
        var subtotal = {{ (float) $subtotal }};
        var optionRadios = document.querySelectorAll('input[name="delivery_option"]');
        var fields = document.getElementById('delivery-fields');
        var address = document.getElementById('address');
        var dateInput = document.getElementById('preferred_delivery_date');
        var stateSelect = document.getElementById('delivery_state_id');
        var areaSelect = document.getElementById('delivery_area_id');
        var feeEstimate = document.getElementById('feeEstimate');
        var deliveryCell = document.getElementById('summaryDelivery');
        var totalCell = document.getElementById('summaryTotal');
        var note = document.getElementById('summaryNote');
        var reqMarks = document.querySelectorAll('[data-delivery-req]');
        var currentFee = 0;

        function money(n) {
            return '₦' + n.toLocaleString('en-NG', { maximumFractionDigits: 0 });
        }

        function stateOption() {
            return stateSelect.options[stateSelect.selectedIndex];
        }

        function refreshAreas() {
            var stateId = stateSelect.value;
            for (var i = 0; i < areaSelect.options.length; i++) {
                var opt = areaSelect.options[i];
                if (!opt.value) continue;
                opt.hidden = stateId !== '' && opt.getAttribute('data-state') !== stateId;
                if (opt.hidden && opt.selected) areaSelect.value = '';
            }
        }

        function refreshFee() {
            var selectedArea = areaSelect.options[areaSelect.selectedIndex];
            var fee = null;
            var where = '';
            var zone = '';

            if (selectedArea && selectedArea.value && !selectedArea.hidden) {
                fee = parseFloat(selectedArea.getAttribute('data-fee'));
                zone = selectedArea.getAttribute('data-zone') || '';
                where = selectedArea.textContent.trim();
            } else {
                var opt = stateOption();
                if (opt && opt.value) {
                    var stateFee = opt.getAttribute('data-fee');
                    if (stateFee !== 'null' && stateFee !== '') {
                        fee = parseFloat(stateFee);
                        where = opt.textContent.trim();
                    }
                }
            }

            if (fee === null || isNaN(fee)) {
                currentFee = 0;
                var chosen = selectedArea && selectedArea.value && !selectedArea.hidden;
                if (!stateSelect.value) {
                    feeEstimate.textContent = 'Choose a state to see the delivery fee.';
                } else {
                    var st = stateOption();
                    var stateFee = st ? st.getAttribute('data-fee') : null;
                    var zoneState = stateFee === null || stateFee === 'null' || stateFee === '';
                    feeEstimate.textContent = (zoneState && !chosen)
                        ? 'Choose your area to see the delivery fee.'
                        : 'Delivery is currently unavailable for this area. Please contact Customer Care.';
                }
                feeEstimate.style.color = '#b45309';
                return;
            }

            currentFee = fee;
            feeEstimate.textContent = 'Delivery fee: ' + money(fee)
                + ' to ' + where
                + (zone ? ' (' + zone + ')' : '')
                + ' — added to your total.';
            feeEstimate.style.color = '';
        }

        function refresh() {
            var selected = document.querySelector('input[name="delivery_option"]:checked');
            var isDelivery = selected && selected.value === DELIVERY;

            fields.classList.toggle('is-hidden', !isDelivery);

            address.required = isDelivery;
            dateInput.required = isDelivery;
            stateSelect.required = isDelivery;
            address.placeholder = isDelivery
                ? 'Street, area, city, state...'
                : 'Optional — only needed if you later switch to delivery';
            reqMarks.forEach(function (el) {
                el.style.display = isDelivery ? '' : 'none';
            });

            refreshAreas();
            refreshFee();

            var fee = isDelivery ? currentFee : 0;
            deliveryCell.textContent = isDelivery ? money(fee) : 'Free (pickup)';
            totalCell.textContent = money(subtotal + fee);
            note.textContent = isDelivery
                ? 'Your delivery fee is included above and confirmed when you place the order — no surprises later.'
                : 'Pickup orders have no delivery fee — you pay the subtotal above.';
        }

        optionRadios.forEach(function (radio) {
            radio.addEventListener('change', refresh);
        });
        stateSelect.addEventListener('change', function () { refreshAreas(); refresh(); });
        areaSelect.addEventListener('change', refresh);

        refresh();
    })();
</script>
@endpush
