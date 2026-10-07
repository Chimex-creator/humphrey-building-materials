{{--
    Checkout progress indicator (§24).

    Expects: $currentStep — 1..5
      1 Cart  2 Customer Information  3 Pickup/Delivery  4 Payment  5 Confirmation
--}}
@php
    $currentStep = (int) ($currentStep ?? 1);
    $steps = [
        1 => 'Cart',
        2 => 'Customer Information',
        3 => 'Pickup / Delivery',
        4 => 'Payment',
        5 => 'Confirmation',
    ];
@endphp

<ol class="checkout-progress" aria-label="Checkout progress">
    @foreach ($steps as $number => $label)
        <li class="checkout-step
            {{ $number < $currentStep ? 'is-done' : '' }}
            {{ $number === $currentStep ? 'is-current' : '' }}"
            @if ($number === $currentStep) aria-current="step" @endif>
            <span class="checkout-step-dot" aria-hidden="true">
                {{ $number < $currentStep ? '✓' : $number }}
            </span>
            <span class="checkout-step-label">{{ $label }}</span>
        </li>
    @endforeach
</ol>
