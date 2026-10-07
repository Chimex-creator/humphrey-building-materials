{{--
    Hand-rolled SVG line/area chart (Phase 11) — no JS, no chart library.

    Expects:
      $series       array of ['label' => string, 'value' => float]
      $chartTitle   string  (optional) heading for screen readers / caption
      $emptyMessage string  (optional) shown when there is nothing to plot
--}}
@php
    $series = $series ?? [];
    $chartTitle = $chartTitle ?? 'Sales over time';
    $emptyMessage = $emptyMessage ?? 'No money was received during this period.';

    $W = 1000;
    $H = 320;
    $padL = 86;
    $padR = 20;
    $padT = 22;
    $padB = 48;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $count = count($series);
    $total = (float) array_sum(array_column($series, 'value'));

    // Round the axis up to a "nice" number so the gridlines read cleanly.
    $rawMax = 0;
    foreach ($series as $point) {
        $rawMax = max($rawMax, (float) $point['value']);
    }
    $max = $rawMax > 0 ? $rawMax : 1;
    $magnitude = pow(10, floor(log10($max)));
    $normalised = $max / $magnitude;
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
        if ($normalised <= $step) {
            $max = $step * $magnitude;
            break;
        }
    }

    $xAt = function (int $i) use ($padL, $plotW, $count) {
        return $count <= 1 ? $padL + ($plotW / 2) : $padL + ($i * $plotW / ($count - 1));
    };
    $yAt = function ($value) use ($padT, $plotH, $max) {
        return $padT + $plotH - (((float) $value) / $max) * $plotH;
    };

    $gridSteps = 4;
    $grids = [];
    for ($s = 0; $s <= $gridSteps; $s++) {
        $value = ($max / $gridSteps) * $s;
        $grids[] = ['y' => round($yAt($value), 2), 'text' => $value];
    }

    $line = [];
    foreach ($series as $i => $point) {
        $line[] = round($xAt($i), 2) . ',' . round($yAt($point['value']), 2);
    }
    $area = $line;
    if ($count > 0) {
        $area[] = round($xAt($count - 1), 2) . ',' . round($padT + $plotH, 2);
        $area[] = round($xAt(0), 2) . ',' . round($padT + $plotH, 2);
    }

    $short = function ($value) {
        $abs = abs($value);
        if ($abs >= 1e9) {
            return '₦' . round($value / 1e9, 1) . 'B';
        }
        if ($abs >= 1e6) {
            return '₦' . round($value / 1e6, 1) . 'M';
        }
        if ($abs >= 1e3) {
            return '₦' . round($value / 1e3, 1) . 'k';
        }
        return '₦' . number_format(round($value), 0);
    };

    $labelStep = max(1, (int) ceil($count / 8));
    $showDots = $count <= 35;
    $baseline = round($padT + $plotH, 2);
@endphp

<figure class="chart-figure">
    <figcaption class="chart-caption">
        <strong>{{ $chartTitle }}</strong>
        @if ($count > 0)
            <span class="chart-caption-total">{{ $short($total) }} total across {{ $count }} {{ $count === 1 ? 'point' : 'points' }}</span>
        @endif
    </figcaption>

    @if ($count === 0)
        <p class="empty-inline">Nothing to plot for this period.</p>
    @else
        <div class="chart-wrap">
            <svg class="chart-svg" viewBox="0 0 {{ $W }} {{ $H }}" role="img"
                 aria-label="{{ $chartTitle }} — {{ $short($total) }} in total">
                {{-- horizontal gridlines + value axis --}}
                @foreach ($grids as $grid)
                    <line class="chart-grid-line" x1="{{ $padL }}" y1="{{ $grid['y'] }}"
                          x2="{{ $W - $padR }}" y2="{{ $grid['y'] }}"></line>
                    <text class="chart-axis-text" x="{{ $padL - 12 }}" y="{{ $grid['y'] }}"
                          text-anchor="end" dominant-baseline="middle">{{ $short($grid['text']) }}</text>
                @endforeach

                {{-- value area + line --}}
                <polygon class="chart-area" points="{{ implode(' ', $area) }}"></polygon>
                <polyline class="chart-line" points="{{ implode(' ', $line) }}"></polyline>

                {{-- one hoverable dot per bucket --}}
                @if ($showDots)
                    @foreach ($series as $i => $point)
                        <circle class="chart-dot" cx="{{ round($xAt($i), 2) }}"
                                cy="{{ round($yAt($point['value']), 2) }}" r="4">
                            <title>{{ $point['label'] }}: {{ $short($point['value']) }}</title>
                        </circle>
                    @endforeach
                @endif

                {{-- baseline + category axis --}}
                <line class="chart-baseline" x1="{{ $padL }}" y1="{{ $baseline }}"
                      x2="{{ $W - $padR }}" y2="{{ $baseline }}"></line>

                @foreach ($series as $i => $point)
                    @if ($i % $labelStep === 0 || $i === $count - 1)
                        <text class="chart-axis-text" x="{{ round($xAt($i), 2) }}"
                              y="{{ $baseline + 26 }}" text-anchor="middle">{{ $point['label'] }}</text>
                    @endif
                @endforeach
            </svg>
        </div>

        @if ($total <= 0)
            <p class="chart-note">{{ $emptyMessage }}</p>
        @endif
    @endif
</figure>
