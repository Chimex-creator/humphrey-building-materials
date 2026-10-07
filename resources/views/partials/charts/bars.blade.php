{{--
    Hand-rolled horizontal bar list (Phase 11) — pure CSS, no chart library.

    Expects:
      $rows         array of ['label' => string, 'value' => float,
                               'display' => string?, 'class' => string?]
      $emptyMessage string  (optional) shown when there are no rows
--}}
@php
    $rows = $rows ?? [];
    $emptyMessage = $emptyMessage ?? 'Nothing recorded during this period.';

    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) $row['value']);
    }
@endphp

@if (count($rows) === 0)
    <p class="empty-inline">{{ $emptyMessage }}</p>
@else
    <div class="bars">
        @foreach ($rows as $row)
            @php
                $value = (float) $row['value'];
                // A visible stub for a non-zero value keeps "0.5 of 10" readable.
                $pct = $max > 0 ? max($value > 0 ? 2 : 0, ($value / $max) * 100) : 0;
                $display = $row['display'] ?? number_format($value);
            @endphp
            <div class="bar-row">
                <span class="bar-label" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                <span class="bar-track">
                    <span class="bar-fill {{ $row['class'] ?? '' }}" style="width: {{ round($pct, 1) }}%"></span>
                </span>
                <span class="bar-value">{{ $display }}</span>
            </div>
        @endforeach
    </div>
@endif
